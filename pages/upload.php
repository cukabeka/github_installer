<?php

use FriendsOfREDAXO\GitHubInstaller\GitHubApi;
use FriendsOfREDAXO\GitHubInstaller\RepositoryManager;
use FriendsOfREDAXO\GitHubInstaller\ClassManager;

// Helper Functions
// WICHTIG: Diese Funktionen MUESSEN vor den Handlern weiter unten definiert werden.
// Bedingte Funktionsdeklarationen (innerhalb von if-Blocks) werden von PHP NICHT
// gehoisted - sie existieren erst, wenn die if-Anweisung ausgefuehrt wurde. Standen
// sie am Dateiende, fuehrte das zu "Call to undefined function uploadModule()".
if (!function_exists('uploadModule')) {

function uploadModule($moduleId, $github, $owner, $repo, $branch, $author, $description, $version) {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT * FROM rex_module WHERE id = ?', [$moduleId]);

    if ($sql->getRows() === 0) {
        throw new Exception(rex_addon::get('github_installer')->i18n('github_installer_item_not_found'));
    }

    // $moduleName wird als GitHub-Ordnername und als "key:" im Manifest verwendet.
    // Hat das Modul in REDAXO noch keinen eigenen Key (rex_module.key ist leer),
    // ist "module_<ID>" NUR ein Platzhalter aus der lokalen Modul-ID dieser Instanz -
    // kein stabiler Bezeichner. Sobald im Modul ein sprechender Key gesetzt und erneut
    // hochgeladen wird, ersetzt dieser den Platzhalter.
    $moduleName = $sql->getValue('key') ?: 'module_' . $moduleId;
    $moduleNameTitle = $sql->getValue('name') ?: 'Unnamed Module';
    $moduleInput = $sql->getValue('input') ?: "<?php\n// Modul Input\n";
    $moduleOutput = $sql->getValue('output') ?: "<?php\n// Modul Output\n";
    $basePath = "modules/{$moduleName}";

    // Upload-Historie: bestehende config.yml aus dem Repo lesen und last_upload
    // mit den Werten des aktuellen Uploads aktualisieren (siehe buildLastUploadYaml).
    $existingConfig = readExistingConfig($github, $owner, $repo, "{$basePath}/config.yml", $branch);
    $config = generateModuleConfig($moduleNameTitle, $moduleName, $author, $description, $version, $existingConfig);

    // Dateien nur SAMMELN - der Commit passiert zentral (einzelner Upload = 1 Commit,
    // Bulk-Upload = 1 Commit fuer alle Items) via GitHubApi::createOrUpdateFiles().
    $files = [];
    $files["{$basePath}/config.yml"] = ['content' => $config];
    $files["{$basePath}/input.php"] = ['content' => $moduleInput];
    $files["{$basePath}/output.php"] = ['content' => $moduleOutput];
    $files["{$basePath}/README.md"] = ['content' => generateModuleReadme($moduleNameTitle, $description)];

    return ['name' => $moduleName, 'files' => $files];
}

function uploadTemplate($templateId, $github, $owner, $repo, $branch, $author, $description, $version) {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT * FROM rex_template WHERE id = ?', [$templateId]);

    if ($sql->getRows() === 0) {
        throw new Exception(rex_addon::get('github_installer')->i18n('github_installer_item_not_found'));
    }

    // Gleicher Platzhalter-Mechanismus wie bei uploadModule(): "template_<ID>"
    // ist nur ein Fallback, solange das Template keinen eigenen Key hat.
    $templateName = $sql->getValue('key') ?: 'template_' . $templateId;
    $templateNameTitle = $sql->getValue('name') ?: 'Unnamed Template';
    $templateContent = $sql->getValue('content') ?: "<?php\n// Template Content\n";
    $basePath = "templates/{$templateName}";

    // Upload-Historie: siehe uploadModule().
    $existingConfig = readExistingConfig($github, $owner, $repo, "{$basePath}/config.yml", $branch);
    $config = generateTemplateConfig($templateNameTitle, $templateName, $author, $description, $version, $existingConfig);

    // Dateien nur SAMMELN - Commit zentral via createOrUpdateFiles() (siehe uploadModule).
    $files = [];
    $files["{$basePath}/config.yml"] = ['content' => $config];
    $files["{$basePath}/template.php"] = ['content' => $templateContent];
    $files["{$basePath}/README.md"] = ['content' => generateTemplateReadme($templateNameTitle, $description)];

    return ['name' => $templateName, 'files' => $files];
}

/**
 * Bestehende config.yml aus dem Repo lesen (fuer Upload-Historie).
 * Gibt null zurueck, wenn die Datei nicht existiert (erster Upload) oder
 * der API-Call fehlschlaegt - die Historie ist optional, ein Fehler hier
 * darf den Upload nicht blockieren.
 */
function readExistingConfig($github, $owner, $repo, $path, $branch) {
    if (!$github || !$owner || !$repo) {
        return null;
    }
    try {
        return $github->getFileContent($owner, $repo, $path, $branch);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Aktuelle Domain ermitteln (fuer die Upload-Historie).
 * YRewrite wenn verfuegbar, sonst HTTP-Host, sonst Fallback.
 */
function getCurrentUploadDomain(): string {
    try {
        if (rex_addon::get('yrewrite')->isAvailable() && rex_yrewrite::getCurrentDomain()) {
            return (string) rex_yrewrite::getCurrentDomain()->getName();
        }
    } catch (Exception $e) {
        // YRewrite nicht aktiv oder nicht initialisiert - Fallback unten
    }
    return $_SERVER['HTTP_HOST'] ?? 'unknown';
}

/**
 * last_upload-Block als YAML erzeugen.
 *
 * Der Block ist bewusst als "x-github-installer:" benannt (OpenAPI-Convention fuer
 * herstellerspezifische Erweiterungen), damit er eindeutig dem GitHub-Installer-Addon
 * zugeordnet ist und nicht mit Feldern anderer Addons/Installer kollidiert.
 * Abwaertskompatibel: aeltere config.yml ohne diesen Block funktionieren unverändert,
 * und der Installer selbst liest nur die bekannten Felder (title, key, version, ...).
 */
function buildLastUploadYaml(?string $existingConfig): string {
    $lines = [];
    $lines[] = 'x-github-installer:';
    $lines[] = '  last_upload:';
    $lines[] = '    date: "' . date('c') . '"'; // ISO 8601
    $lines[] = '    domain: "' . getCurrentUploadDomain() . '"';
    $lines[] = '    redaxo_version: "' . rex::getVersion() . '"';
    return implode("\n", $lines);
}

/**
 * Letzten Upload aus einer bestehenden config.yml extrahieren (fuer Dialog-Anzeige).
 * Einfaches Zeilen-Parsing statt voller YAML-Parser, um Abhaengigkeiten zu vermeiden.
 * @return array{date: string, domain: string, redaxo_version: string}|null
 */
function parseLastUpload(?string $configContent): ?array {
    if (!$configContent) {
        return null;
    }
    $inBlock = false;
    $result = ['date' => '', 'domain' => '', 'redaxo_version' => ''];
    foreach (preg_split('/\R/', $configContent) as $line) {
        if (preg_match('/^x-github-installer:\s*$/', $line)) {
            $inBlock = true;
            continue;
        }
        if ($inBlock) {
            // Block endet bei naechstem Top-Level-Key (keine Einrueckung)
            if (preg_match('/^\S/', $line)) {
                break;
            }
            if (preg_match('/^\s+date:\s*"([^"]*)"/', $line, $m)) {
                $result['date'] = $m[1];
            } elseif (preg_match('/^\s+domain:\s*"([^"]*)"/', $line, $m)) {
                $result['domain'] = $m[1];
            } elseif (preg_match('/^\s+redaxo_version:\s*"([^"]*)"/', $line, $m)) {
                $result['redaxo_version'] = $m[1];
            }
        }
    }
    if (!$result['date'] && !$result['domain']) {
        return null;
    }
    return $result;
}

function generateModuleConfig($moduleName, $moduleKey, $author, $description, $version, ?string $existingConfig = null) {
    $yaml = [];
    $yaml[] = 'title: "' . $moduleName . '"';
    $yaml[] = 'description: "' . ($description ?: 'Keine Beschreibung') . '"';
    $yaml[] = 'author: "' . $author . '"';
    $yaml[] = 'version: "' . $version . '"';
    $yaml[] = 'key: "' . $moduleKey . '"';
    $yaml[] = 'redaxo_version: "5.13+"';
    $yaml[] = '';
    $yaml[] = buildLastUploadYaml($existingConfig);

    return implode("\n", $yaml);
}

function generateTemplateConfig($templateName, $templateKey, $author, $description, $version, ?string $existingConfig = null) {
    $yaml = [];
    $yaml[] = 'title: "' . $templateName . '"';
    $yaml[] = 'description: "' . ($description ?: 'Keine Beschreibung') . '"';
    $yaml[] = 'author: "' . $author . '"';
    $yaml[] = 'version: "' . $version . '"';
    $yaml[] = 'key: "' . $templateKey . '"';
    $yaml[] = 'redaxo_version: "5.13+"';
    $yaml[] = '';
    $yaml[] = buildLastUploadYaml($existingConfig);

    return implode("\n", $yaml);
}

function generateModuleReadme($moduleName, $description) {
    $readme = [];
    $readme[] = "# " . $moduleName . " - REDAXO Modul";
    $readme[] = "";
    $readme[] = $description ?: 'Keine Beschreibung verfügbar';
    $readme[] = "";
    $readme[] = "## Installation";
    $readme[] = "";
    $readme[] = "1. Repository zum GitHub Installer hinzufügen";
    $readme[] = "2. Modul installieren";
    $readme[] = "";

    return implode("\n", $readme);
}

function generateTemplateReadme($templateName, $description) {
    $readme = [];
    $readme[] = "# " . $templateName . " - REDAXO Template";
    $readme[] = "";
    $readme[] = $description ?: 'Keine Beschreibung verfügbar';
    $readme[] = "";
    $readme[] = "## Installation";
    $readme[] = "";
    $readme[] = "1. Repository zum GitHub Installer hinzufügen";
    $readme[] = "2. Template installieren";
    $readme[] = "";

    return implode("\n", $readme);
}

} // function_exists('uploadModule')

$func = rex_request('func', 'string');
$type = rex_request('type', 'string', 'module');
// $type landet u.a. als Tabellenname in einem rex_list-Query (siehe unten) - daher
// hart auf die drei erlaubten Werte begrenzen, statt Request-Input frei durchzureichen.
if (!in_array($type, ['module', 'template', 'class'], true)) {
    $type = 'module';
}
$addon = rex_addon::get('github_installer');
$csrfToken = rex_csrf_token::factory('github_installer_upload');

// Repository-Manager für Repository-Auswahl
$repoManager = new RepositoryManager();
$repositories = $repoManager->getRepositories();

if (empty($repositories)) {
    echo rex_view::warning($addon->i18n('github_installer_upload_no_repos') . ' <a href="' . rex_url::currentBackendPage(['page' => 'github_installer/repositories']) . '">' . $addon->i18n('github_installer_repositories') . '</a>');
    return;
}

// Upload durchführen
if ($func === 'upload' && rex_post('upload', 'bool') && !$csrfToken->isValid()) {
    echo rex_view::error($addon->i18n('github_installer_csrf_invalid'));
} elseif ($func === 'upload' && rex_post('upload', 'bool')) {
    if ($type === 'class') {
        // Klassen-Upload
        $className = rex_post('class_name', 'string');
        $targetRepo = rex_post('target_repo', 'string');
        $commitMessage = rex_post('commit_message', 'string') ?: "Upload class {$className}";
        
        if (!$className || !$targetRepo) {
            echo rex_view::error($addon->i18n('github_installer_upload_missing_data'));
        } else {
            try {
                if (!isset($repositories[$targetRepo])) {
                    throw new Exception($addon->i18n('github_installer_repo_not_found'));
                }
                
                $classManager = new ClassManager();
                $result = $classManager->uploadClass($targetRepo, $className, $commitMessage);
                
                if ($result) {
                    $repo = $repositories[$targetRepo];
                    echo rex_view::success($addon->i18n('github_installer_upload_success', $className, $repo['owner'] . '/' . $repo['repo']));
                }
                
            } catch (Exception $e) {
                echo rex_view::error($addon->i18n('github_installer_upload_error') . ': ' . rex_escape($e->getMessage()));
            }
        }
    } else {
        // Module/Template Upload
        $itemId = (int) rex_post('item_id', 'int');
        $targetRepo = rex_post('target_repo', 'string');
        $description = rex_post('description', 'string', '');
        $version = rex_post('version', 'string', '1.0.0');
        
        if (!$itemId || !$targetRepo) {
            echo rex_view::error($addon->i18n('github_installer_upload_missing_data'));
        } else {
            try {
                if (!isset($repositories[$targetRepo])) {
                    throw new Exception($addon->i18n('github_installer_repo_not_found'));
                }
                
                $repo = $repositories[$targetRepo];
                $github = new GitHubApi();
                
                // Repository testen
                if (!$github->testRepository($repo['owner'], $repo['repo'], $repo['branch'])) {
                    throw new Exception($addon->i18n('github_installer_repo_unreachable', $repo['owner'] . '/' . $repo['repo']));
                }
                
                if ($type === 'module') {
                    $result = uploadModule($itemId, $github, $repo['owner'], $repo['repo'], $repo['branch'], $addon->getConfig('upload_author', ''), $description, $version);
                } else {
                    $result = uploadTemplate($itemId, $github, $repo['owner'], $repo['repo'], $repo['branch'], $addon->getConfig('upload_author', ''), $description, $version);
                }

                // Alle Dateien des Items in EINEM Commit pushen
                $github->createOrUpdateFiles($repo['owner'], $repo['repo'], $result['files'], "Upload {$type} {$result['name']}", $repo['branch']);

                echo rex_view::success($addon->i18n('github_installer_upload_success', $result['name'], $repo['owner'] . '/' . $repo['repo']));

                // "module_45" / "template_45" ist kein echter Key, sondern der ID-Platzhalter
                // aus uploadModule()/uploadTemplate() - im Backend explizit als solchen kennzeichnen,
                // damit er nicht mit einem regulären REDAXO-Key verwechselt wird.
                if (preg_match('/^(module|template)_\d+$/', (string) $result['name'])) {
                    echo rex_view::warning($addon->i18n('github_installer_upload_placeholder_key_hint', $result['name']));
                }

            } catch (Exception $e) {
                echo rex_view::error($addon->i18n('github_installer_upload_error') . ': ' . rex_escape($e->getMessage()));
            }
        }
    }
}

// Bulk-Upload durchführen (ausgewählte Checkboxen ODER "alle hochladen")
$bulkUploadAll = rex_post('bulk_upload_all', 'bool');
if ($func === 'upload' && (rex_post('bulk_upload', 'bool') || $bulkUploadAll)) {
    if (!$csrfToken->isValid()) {
        echo rex_view::error($addon->i18n('github_installer_csrf_invalid'));
    } else {
        $bulkIds = rex_post('bulk_ids', 'array', []);
        $bulkTargetRepo = rex_post('bulk_target_repo', 'string');
        $bulkCommitPrefix = rex_post('bulk_commit_prefix', 'string', '');
        $bulkDescription = rex_post('bulk_description', 'string', '');
        $bulkVersion = rex_post('bulk_version', 'string', '1.0.0') ?: '1.0.0';

        // "Alle hochladen": alle Items des aktuellen Typs aus der DB laden
        if ($bulkUploadAll) {
            if ($type === 'class') {
                try {
                    $bulkIds = array_keys((new ClassManager())->getLocalClasses());
                } catch (Exception $e) {
                    $bulkIds = [];
                }
            } else {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT id FROM ' . rex::getTable($type) . ' ORDER BY name');
                $bulkIds = [];
                for ($i = 0; $i < $sql->getRows(); $i++) {
                    $bulkIds[] = (int) $sql->getValue('id');
                    $sql->next();
                }
            }
        } elseif (rex_post('bulk_select_all_pages', 'string') === '1') {
            // "Alle auswählen (alle Seiten)": Checkboxen der aktuellen Seite + alle weiteren
            if ($type === 'class') {
                try {
                    $bulkIds = array_keys((new ClassManager())->getLocalClasses());
                } catch (Exception $e) {
                    $bulkIds = [];
                }
            } else {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT id FROM ' . rex::getTable($type) . ' ORDER BY name');
                $bulkIds = [];
                for ($i = 0; $i < $sql->getRows(); $i++) {
                    $bulkIds[] = (int) $sql->getValue('id');
                    $sql->next();
                }
            }
        } else {
            $bulkIds = array_map('intval', $bulkIds);
            $bulkIds = array_filter($bulkIds);
        }

        if (empty($bulkIds) || !$bulkTargetRepo) {
            echo rex_view::error($addon->i18n('github_installer_bulk_no_items_selected'));
        } elseif (!isset($repositories[$bulkTargetRepo])) {
            echo rex_view::error($addon->i18n('github_installer_repo_not_found'));
        } else {
            $bulkRepo = $repositories[$bulkTargetRepo];
            $github = new GitHubApi();
            $bulkSuccess = [];
            $bulkErrors = [];

            if ($type === 'class') {
                // Klassen-Bulk: $bulkIds sind hier Klassennamen (Strings)
                $bulkClassNames = $bulkIds;
                $classManager = new ClassManager();
                foreach ($bulkClassNames as $className) {
                    $className = (string) $className;
                    try {
                        $commitMessage = trim($bulkCommitPrefix . ' ' . $className);
                        if ($classManager->uploadClass($bulkTargetRepo, $className, $commitMessage)) {
                            $bulkSuccess[] = $className;
                        } else {
                            $bulkErrors[$className] = 'uploadClass returned false';
                        }
                    } catch (Exception $e) {
                        $bulkErrors[$className] = $e->getMessage();
                    }
                }
            } else {
                // Repository einmalig testen
                if (!$github->testRepository($bulkRepo['owner'], $bulkRepo['repo'], $bulkRepo['branch'])) {
                    $bulkErrors['-'] = $addon->i18n('github_installer_repo_unreachable', $bulkRepo['owner'] . '/' . $bulkRepo['repo']);
                } else {
                    // Alle Dateien aller Items sammeln und in EINEM Commit pushen
                    // (statt pro Datei einen Commit - siehe GitHubApi::createOrUpdateFiles)
                    $bulkFiles = [];
                    $bulkNames = [];
                    foreach ($bulkIds as $itemId) {
                        try {
                            if ($type === 'module') {
                                $result = uploadModule($itemId, $github, $bulkRepo['owner'], $bulkRepo['repo'], $bulkRepo['branch'], $addon->getConfig('upload_author', ''), $bulkDescription, $bulkVersion);
                            } else {
                                $result = uploadTemplate($itemId, $github, $bulkRepo['owner'], $bulkRepo['repo'], $bulkRepo['branch'], $addon->getConfig('upload_author', ''), $bulkDescription, $bulkVersion);
                            }
                            $bulkFiles += $result['files'];
                            $bulkNames[] = $result['name'];
                        } catch (Exception $e) {
                            $bulkErrors['#' . $itemId] = $e->getMessage();
                        }
                    }

                    if (!empty($bulkFiles)) {
                        try {
                            $github->createOrUpdateFiles($bulkRepo['owner'], $bulkRepo['repo'], $bulkFiles, trim($bulkCommitPrefix . ' ' . count($bulkNames) . ' ' . $type . '(s): ' . implode(', ', $bulkNames)), $bulkRepo['branch']);
                            $bulkSuccess = $bulkNames;
                        } catch (Exception $e) {
                            $bulkErrors['-'] = $e->getMessage();
                        }
                    }
                }
            }

            // Sammel-Feedback
            $resultHtml = '';
            foreach ($bulkSuccess as $name) {
                $resultHtml .= '<li class="text-success"><i class="rex-icon fa-check"></i> ' . $addon->i18n('github_installer_bulk_success_item', rex_escape($name)) . '</li>';
            }
            foreach ($bulkErrors as $name => $msg) {
                $resultHtml .= '<li class="text-danger"><i class="rex-icon fa-times"></i> ' . $addon->i18n('github_installer_bulk_error_item', rex_escape($name), rex_escape($msg)) . '</li>';
            }
            if ($resultHtml) {
                $panelClass = $bulkErrors ? 'warning' : 'success';
                echo rex_view::title($addon->i18n('github_installer_bulk_results_title'));
                echo '<div class="panel panel-' . $panelClass . '"><div class="panel-body"><ul class="list-unstyled">' . $resultHtml . '</ul></div></div>';
            }
        }
    }
}

// Upload-Formular anzeigen
// WICHTIG: Nach einem Bulk-Upload NICHT nochmal das Einzel-Upload-Formular rendern.
// rex_post('upload') ist bei Bulk false, der Block lief sonst mit item_id=0 durch
// (Query mit Parameter 0 -> "item not found"). Zudem kann die DB-Verbindung während
// der langen GitHub-API-Calls vom Server geschlossen worden sein ("MySQL server has
// gone away") - daher vorher die Verbindung schließen; rex_sql verbindet lazy neu.
$bulkWasPerformed = rex_post('bulk_upload', 'bool') || $bulkUploadAll;
if ($bulkWasPerformed) {
    rex_sql::closeConnection();
}
if ($func === 'upload' && !rex_post('upload', 'bool') && !$bulkWasPerformed) {
    // Standard-Repository bestimmen
    $defaultRepo = '';
    $uploadRepo = $addon->getConfig('upload_owner', '') . '/' . $addon->getConfig('upload_repo', '');
    foreach ($repositories as $repoKey => $repoData) {
        if ($repoKey === $uploadRepo) {
            $defaultRepo = $repoKey;
            break;
        }
    }
    if (!$defaultRepo && !empty($repositories)) {
        $defaultRepo = array_key_first($repositories);
    }

    // Default-Repo-Daten fuer die Upload-Historie-Anzeige (letzter Upload aus config.yml)
    $defaultRepoOwner = '';
    $defaultRepoRepo = '';
    $defaultRepoBranch = 'main';
    if ($defaultRepo && isset($repositories[$defaultRepo])) {
        $defaultRepoOwner = $repositories[$defaultRepo]['owner'];
        $defaultRepoRepo = $repositories[$defaultRepo]['repo'];
        $defaultRepoBranch = $repositories[$defaultRepo]['branch'] ?? 'main';
    }
    
    if ($type === 'class') {
        // Klassen-Upload Formular
        $className = rex_request('class_name', 'string');
        $itemLabel = $addon->i18n('github_installer_class_label');
        try {
            $classManager = new ClassManager();
            $localClasses = $classManager->getLocalClasses();
        } catch (Exception $e) {
            echo rex_view::error(rex_escape($e->getMessage()));
            return;
        }

        if (!isset($localClasses[$className])) {
            echo rex_view::error($addon->i18n('github_installer_item_not_found'));
            return;
        }

        $classData = $localClasses[$className];

        $content = '<fieldset>';
        $content .= '<legend>' . $itemLabel . ' hochladen: ' . rex_escape($classData['title'] ?? $className) . '</legend>';
        
        $formElements = [];
        
        // Repository-Auswahl
        $n = [];
        $n['label'] = '<label for="target_repo">' . $addon->i18n('github_installer_upload_select_repo') . '</label>';
        $select = '<select name="target_repo" id="target_repo" class="form-control" required>';
        $select .= '<option value="">' . $addon->i18n('github_installer_upload_choose_repo') . '</option>';
        foreach ($repositories as $repoKey => $repoData) {
            $selected = ($repoKey === $defaultRepo) ? ' selected="selected"' : '';
            $select .= '<option value="' . rex_escape($repoKey) . '"' . $selected . '>' . rex_escape($repoData['display_name']) . ' (' . rex_escape($repoKey) . ')</option>';
        }
        $select .= '</select>';
        $n['field'] = $select;
        $formElements[] = $n;
        
        // Commit-Message
        $n = [];
        $n['label'] = '<label for="commit_message">' . $addon->i18n('github_installer_upload_commit_message') . '</label>';
        $n['field'] = '<input class="form-control" type="text" id="commit_message" name="commit_message" value="Update class ' . rex_escape($className) . '" />';
        $formElements[] = $n;
        
        // Hidden Class Name
        $content .= '<input type="hidden" name="class_name" value="' . rex_escape($className) . '" />';
        
    } else {
        // Module/Template Upload Formular
        $itemId = (int) rex_request('item_id', 'int');
        
        if ($type === 'module') {
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT id AS id, name AS name, `key` AS `key` FROM rex_module WHERE id = ?', [$itemId]);
            $itemLabel = $addon->i18n('github_installer_module_label');
        } else {
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT id AS id, name AS name, `key` AS `key` FROM rex_template WHERE id = ?', [$itemId]);
            $itemLabel = $addon->i18n('github_installer_template_label');
        }
        
        if ($sql->getRows() === 0) {
            echo rex_view::error($addon->i18n('github_installer_item_not_found'));
            return;
        }
        
        // Werte direkt über rex_sql Methoden abrufen
        $itemName = $sql->getValue('name');
        $itemKey = $sql->getValue('key');
        
        $content = '<fieldset>';
        $content .= '<legend>' . $itemLabel . ' hochladen: ' . rex_escape($itemName ?: $addon->i18n('github_installer_unknown')) . '</legend>';

        // Upload-Historie aus der im Repo liegenden config.yml anzeigen
        // (Quelle der Wahrheit ist das Repo, nicht eine lokale DB)
        $itemKeyForHistory = $itemKey ?: ($type === 'module' ? 'module_' . $itemId : 'template_' . $itemId);
        $githubForHistory = null;
        if ($defaultRepoOwner && $defaultRepoRepo) {
            try {
                $githubForHistory = new GitHubApi();
            } catch (\Throwable $e) {
                $githubForHistory = null;
            }
        }
        $historyConfig = readExistingConfig($githubForHistory, $defaultRepoOwner, $defaultRepoRepo, ($type === 'module' ? 'modules/' : 'templates/') . $itemKeyForHistory . '/config.yml', $defaultRepoBranch);
        $lastUpload = parseLastUpload($historyConfig);
        if ($lastUpload) {
            $historyDate = $lastUpload['date'];
            $ts = strtotime($historyDate);
            if ($ts) {
                $historyDate = rex_formatter::intlDateTime(date('Y-m-d H:i:s', $ts), [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT]);
            }
            $historyHtml = '<div class="alert alert-info" style="margin-bottom:15px"><strong>' . $addon->i18n('github_installer_upload_history_title') . '</strong><br />';
            $historyHtml .= $addon->i18n('github_installer_upload_history_info', rex_escape($historyDate), rex_escape($lastUpload['domain']), rex_escape($lastUpload['redaxo_version'] ?: '?'));
            $historyHtml .= '</div>';
            $content .= $historyHtml;
        }

        $formElements = [];
        
        // Repository-Auswahl
        $n = [];
        $n['label'] = '<label for="target_repo">' . $addon->i18n('github_installer_upload_select_repo') . '</label>';
        $select = '<select name="target_repo" id="target_repo" class="form-control" required>';
        $select .= '<option value="">' . $addon->i18n('github_installer_upload_choose_repo') . '</option>';
        foreach ($repositories as $repoKey => $repoData) {
            $selected = ($repoKey === $defaultRepo) ? ' selected="selected"' : '';
            $select .= '<option value="' . rex_escape($repoKey) . '"' . $selected . '>' . rex_escape($repoData['display_name']) . ' (' . rex_escape($repoKey) . ')</option>';
        }
        $select .= '</select>';
        $n['field'] = $select;
        $formElements[] = $n;
        
        $n = [];
        $n['label'] = '<label for="description">Beschreibung</label>';
        $n['field'] = '<textarea class="form-control" id="description" name="description" rows="3" placeholder="Kurze Beschreibung des ' . $itemLabel . 's...">' . rex_escape($itemName ?: $addon->i18n('github_installer_unknown')) . '</textarea>';
        $formElements[] = $n;
        
        $n = [];
        $n['label'] = '<label for="version">Version</label>';
        $n['field'] = '<input class="form-control" type="text" id="version" name="version" value="1.0.0" />';
        $formElements[] = $n;
        
        // Hidden Item ID
        $content .= '<input type="hidden" name="item_id" value="' . $itemId . '" />';
    }
    
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $content .= $fragment->parse('core/form/form.php');
    
    $content .= '</fieldset>';
    
    $formElements = [];
    $n = [];
    $n['field'] = '<button class="btn btn-primary" type="submit" name="upload" value="1">' . $addon->i18n('github_installer_upload') . '</button>';
    $n['field'] .= ' <a class="btn btn-secondary" href="' . rex_url::currentBackendPage(['type' => $type]) . '">' . $addon->i18n('github_installer_cancel') . '</a>';
    $formElements[] = $n;
    
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $buttons = $fragment->parse('core/form/submit.php');
    
    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $itemLabel . ' hochladen', false);
    $fragment->setVar('body', $content, false);
    $fragment->setVar('buttons', $buttons, false);
    $output = $fragment->parse('core/page/section.php');
    
    echo '<form action="' . rex_url::currentBackendPage(['func' => 'upload', 'type' => $type]) . '" method="post">';
    echo $csrfToken->getHiddenField();
    echo $output;
    echo '</form>';
    
    return;
}

// Tab-Navigation
$tabs = [
    'module' => $addon->i18n('github_installer_modules'),
    'template' => $addon->i18n('github_installer_templates'),
    'class' => $addon->i18n('github_installer_classes_title')
];

$tabContent = '';
foreach ($tabs as $tabType => $tabLabel) {
    $active = $type === $tabType ? ' class="active"' : '';
    $tabContent .= '<li' . $active . '><a href="' . rex_url::currentBackendPage(['type' => $tabType]) . '">' . $tabLabel . '</a></li>';
}

echo '<ul class="nav nav-tabs">' . $tabContent . '</ul>';

// JS-Asset für Bulk-Upload (Select-all, Zähler, Rows-per-Page Auto-Submit)
rex_view::addJsFile(rex_url::addonAssets('github_installer', 'bulk_upload.js'));

// Zeilen pro Seite: aus Config lesen, per Request änderbar, persistieren
$rowsPerPage = (int) $addon->getConfig('upload_rows_per_page', 30);
$rowsRequest = (int) rex_request('rows_per_page', 'int', 0);
if (in_array($rowsRequest, [30, 50, 100, 250], true)) {
    $rowsPerPage = $rowsRequest;
    $addon->setConfig('upload_rows_per_page', $rowsPerPage);
}

// Standard-Repository für Bulk-Formular bestimmen (wie im Single-Upload)
$defaultRepo = '';
$uploadRepo = $addon->getConfig('upload_owner', '') . '/' . $addon->getConfig('upload_repo', '');
foreach ($repositories as $repoKey => $repoData) {
    if ($repoKey === $uploadRepo) {
        $defaultRepo = $repoKey;
        break;
    }
}
if (!$defaultRepo && !empty($repositories)) {
    $defaultRepo = array_key_first($repositories);
}

// Bulk-Aktionsleiste (oberhalb der Liste, Teil des Bulk-Formulars)
$bulkRepoSelect = '<select name="bulk_target_repo" id="bulk_target_repo" class="form-control" required>';
$bulkRepoSelect .= '<option value="">' . $addon->i18n('github_installer_upload_choose_repo') . '</option>';
foreach ($repositories as $repoKey => $repoData) {
    $selected = ($repoKey === $defaultRepo) ? ' selected="selected"' : '';
    $bulkRepoSelect .= '<option value="' . rex_escape($repoKey) . '"' . $selected . '>' . rex_escape($repoData['display_name']) . ' (' . rex_escape($repoKey) . ')</option>';
}
$bulkRepoSelect .= '</select>';

$rowsSelect = '<select name="rows_per_page" id="bulk_rows_per_page" class="form-control input-sm" style="width:auto;display:inline-block">';
foreach ([30, 50, 100, 250] as $option) {
    $selected = ($rowsPerPage === $option) ? ' selected="selected"' : '';
    $rowsSelect .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
}
$rowsSelect .= '</select>';

$bulkPanel = '<div class="panel panel-default" style="margin-top:10px"><div class="panel-body">';
$bulkPanel .= '<div class="row">';
$bulkPanel .= '<div class="col-md-4"><div class="form-group"><label for="bulk_target_repo">' . $addon->i18n('github_installer_upload_select_repo') . '</label>' . $bulkRepoSelect . '</div></div>';
if ($type !== 'class') {
    $bulkPanel .= '<div class="col-md-3"><div class="form-group"><label for="bulk_version">' . $addon->i18n('github_installer_bulk_version') . '</label><input class="form-control" type="text" id="bulk_version" name="bulk_version" value="1.0.0" /></div></div>';
}
$bulkPanel .= '<div class="col-md-3"><div class="form-group"><label for="bulk_commit_prefix">' . $addon->i18n('github_installer_bulk_commit_prefix') . '</label><input class="form-control" type="text" id="bulk_commit_prefix" name="bulk_commit_prefix" value="Bulk upload" /></div></div>';
$bulkPanel .= '<div class="col-md-2"><div class="form-group"><label>' . $addon->i18n('github_installer_bulk_rows_per_page') . '</label>' . $rowsSelect . '</div></div>';
$bulkPanel .= '</div>';
if ($type !== 'class') {
    $bulkPanel .= '<div class="form-group"><label for="bulk_description">' . $addon->i18n('github_installer_bulk_description') . '</label><textarea class="form-control" id="bulk_description" name="bulk_description" rows="2"></textarea></div>';
}
$bulkPanel .= '<p class="text-muted"><small>' . $addon->i18n('github_installer_bulk_warning_commits') . '</small></p>';
$bulkPanel .= '<button class="btn btn-primary" type="submit" name="bulk_upload" value="1"><i class="rex-icon fa-upload"></i> ' . $addon->i18n('github_installer_bulk_execute') . '</button> ';
$bulkPanel .= '<button class="btn btn-warning" type="submit" name="bulk_upload_all" value="1" onclick="return confirm(\'' . $addon->i18n('github_installer_bulk_upload_all_confirm') . '\')"><i class="rex-icon fa-cloud-upload"></i> ' . $addon->i18n('github_installer_bulk_upload_all') . '</button> ';
$bulkPanel .= '<span class="label label-default" id="bulk-selected-count" data-template="' . rex_escape($addon->i18n('github_installer_bulk_selected_count', '{0}')) . '" data-template-all="' . rex_escape($addon->i18n('github_installer_bulk_selected_all')) . '">0</span>';
$bulkPanel .= ' <a href="#" id="bulk-select-all-pages">' . $addon->i18n('github_installer_bulk_select_all_pages') . '</a>';
$bulkPanel .= '<input type="hidden" name="bulk_select_all_pages" id="bulk_select_all_pages_flag" value="0" />';
$bulkPanel .= '</div></div>';

// Item-Liste anzeigen
if ($type === 'class') {
    // Klassen-Liste
    try {
        $classManager = new ClassManager();
        $localClasses = $classManager->getLocalClasses();
    } catch (Exception $e) {
        echo rex_view::error(rex_escape($e->getMessage()));
        return;
    }

    if (empty($localClasses)) {
        echo rex_view::info($addon->i18n('github_installer_no_local_classes'));
    } else {
        $tableContent = '<div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><input type="checkbox" data-bulk-select-all title="' . rex_escape($addon->i18n('github_installer_bulk_select_all')) . '" /></th>
                        <th>' . $addon->i18n('github_installer_class_name') . '</th>
                        <th>' . $addon->i18n('github_installer_class_title') . '</th>
                        <th>' . $addon->i18n('github_installer_class_version') . '</th>
                        <th>' . $addon->i18n('github_installer_class_author') . '</th>
                        <th>' . $addon->i18n('github_installer_class_installed') . '</th>
                        <th>' . $addon->i18n('github_installer_upload') . '</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($localClasses as $className => $classData) {
            $uploadUrl = rex_url::currentBackendPage(['func' => 'upload', 'class_name' => $className, 'type' => 'class']);
            $tableContent .= '<tr>
                <td><input type="checkbox" name="bulk_ids[]" value="' . rex_escape($className) . '" /></td>
                <td><strong>' . rex_escape($className) . '</strong></td>
                <td>' . rex_escape($classData['title'] ?? 'Unnamed Class') . '</td>
                <td>' . rex_escape($classData['version'] ?? '1.0.0') . '</td>
                <td>' . rex_escape($classData['author'] ?? $addon->i18n('github_installer_unknown')) . '</td>
                <td>' . rex_escape($classData['installed_at'] ?? $addon->i18n('github_installer_unknown')) . '</td>
                <td><a class="btn btn-primary btn-xs" href="' . $uploadUrl . '">' . $addon->i18n('github_installer_upload') . '</a></td>
            </tr>';
        }

        $tableContent .= '</tbody></table></div>';

        // Klassen-Tabelle in das Bulk-Formular einbetten
        echo '<form action="' . rex_url::currentBackendPage(['type' => 'class']) . '" method="post" id="github-installer-bulk-form">';
        echo $csrfToken->getHiddenField();
        echo '<input type="hidden" name="func" value="upload" />';
        echo $bulkPanel;
        echo $tableContent;
        echo '</form>';
    }
} else {
    // Module/Template Liste
    // $type ist oben bereits auf ['module','template','class'] geprüft, hier zusätzlich
    // über rex::getTable() statt roher String-Interpolation aufgelöst.
    // Rows-per-Page als zweiter Parameter von factory() (rex_list hat keinen Setter dafür)
    $list = rex_list::factory('SELECT id, name, `key`, createdate, updatedate FROM ' . rex::getTable($type) . ' ORDER BY name', $rowsPerPage);
    $list->addTableAttribute('class', 'table-striped');

    $list->setColumnLabel('name', $addon->i18n('github_installer_name'));
    $list->setColumnLabel('key', 'Key');
    $list->setColumnLabel('createdate', $addon->i18n('github_installer_created'));
    $list->setColumnLabel('updatedate', $addon->i18n('github_installer_updated'));

    $list->setColumnFormat('createdate', 'custom', function ($params) {
        $value = $params['list']->getValue('createdate');
        if (!$value || $value === '0000-00-00 00:00:00') {
            return '-';
        }
        return rex_formatter::intlDateTime($value, [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT]);
    });
    $list->setColumnFormat('updatedate', 'custom', function ($params) {
        $value = $params['list']->getValue('updatedate');
        if (!$value || $value === '0000-00-00 00:00:00') {
            return '-';
        }
        return rex_formatter::intlDateTime($value, [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT]);
    });

    // Upload-Button hinzufügen
    $list->addColumn('upload', $addon->i18n('github_installer_upload'), -1, ['<th>###VALUE###</th>', '<td>###VALUE###</td>']);
    $list->setColumnFormat('upload', 'custom', function ($params) use ($type) {
        $itemId = $params['list']->getValue('id');
        $url = rex_url::currentBackendPage(['func' => 'upload', 'item_id' => $itemId, 'type' => $type]);
        return '<a class="btn btn-primary btn-xs" href="' . $url . '">' . 
               rex_i18n::msg('github_installer_upload') . '</a>';
    });

    // Bulk-Checkbox-Spalte (erste Spalte) + Select-all im Header
    $list->addColumn('bulk_checkbox', '', 0, [
        '<th><input type="checkbox" data-bulk-select-all title="' . rex_escape($addon->i18n('github_installer_bulk_select_all')) . '" /></th>',
        '<td><input type="checkbox" name="bulk_ids[]" value="###id###" /></td>',
    ]);

    // rex_list in das Bulk-Formular einbetten
    echo '<form action="' . rex_url::currentBackendPage(['type' => $type]) . '" method="post" id="github-installer-bulk-form">';
    echo $csrfToken->getHiddenField();
    echo '<input type="hidden" name="func" value="upload" />';
    echo $bulkPanel;
    echo $list->get();
    echo '</form>';
}
