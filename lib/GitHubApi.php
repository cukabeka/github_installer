<?php

namespace FriendsOfREDAXO\GitHubInstaller;

use rex_config;
use rex_path;
use rex_file;

/**
 * GitHub API Client mit File-Cache
 */
class GitHubApi
{
    private string $token;
    private string $baseUrl = 'https://api.github.com';
    private string $cacheDir;
    private int $cacheLifetime;
    
    public function __construct(?string $token = null)
    {
        $this->token = $token ?: rex_config::get('github_installer', 'github_token', '');
        $this->cacheDir = rex_path::addonCache('github_installer');
        $this->cacheLifetime = rex_config::get('github_installer', 'cache_lifetime', 3600);
    }
    
    /**
     * API Request durchführen
     */
    private function makeRequest(string $endpoint, string $method = 'GET', ?array $data = null, bool $useCache = true): array
    {
        $cacheKey = md5($endpoint . $this->token);
        $cacheFile = $this->cacheDir . $cacheKey . '.json';
        
        // Cache nur bei GET verwenden
        if ($method === 'GET' && $useCache && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheLifetime) {
            $content = rex_file::get($cacheFile);
            if ($content) {
                return json_decode($content, true) ?: [];
            }
        }
        
        // API Request
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: REDAXO-GitHub-Installer/1.0',
        ];
        
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        
        $contextOptions = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => 30,
            ],
        ];
        
        // POST/PUT/PATCH Daten hinzufügen. WICHTIG: PATCH nicht vergessen - das
        // Ref-Update in createOrUpdateFiles() nutzt PATCH; ohne Body fehlt der
        // 'sha' und GitHub antwortet mit HTTP 422, der Commit bleibt verwaist.
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contextOptions['http']['header'] .= "\r\nContent-Type: application/json";
            $contextOptions['http']['content'] = json_encode($data);
        }
        
        $context = stream_context_create($contextOptions);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            // $http_response_header wird von file_get_contents() im aufrufenden Scope gesetzt
            // und liefert HTTP-Statuscode + Rate-Limit-Header für eine genauere Fehlermeldung,
            // statt Rate-Limits/abgelaufene Tokens/Netzwerkfehler alle gleich zu behandeln.
            throw new \Exception($this->describeRequestFailure($http_response_header ?? [], $url));
        }
        
        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new \Exception('Invalid JSON response from GitHub API');
        }
        
        // API-Fehler prüfen. WICHTIG: Nicht bloß auf 'message' testen - erfolgreiche
        // Responses der Git Data API (z.B. POST /git/commits) enthalten ebenfalls ein
        // top-level 'message'-Feld (die Commit-Message). Echte GitHub-Fehlerresponses
        // haben immer zusätzlich ein 'documentation_url'-Feld, Erfolgsresponses nie.
        if (isset($result['message']) && isset($result['documentation_url'])) {
            throw new \Exception('GitHub API Error: ' . $result['message']);
        }
        
        // Cache speichern (nur bei GET)
        if ($method === 'GET' && $response !== false) {
            $this->writeCacheFileAtomically($cacheFile, $response);
        }

        return $result;
    }

    /**
     * Cache-Datei atomar schreiben (write-then-rename), damit ein gleichzeitiger
     * Reader nie eine halb geschriebene Datei sieht.
     */
    private function writeCacheFileAtomically(string $cacheFile, string $content): void
    {
        $tmpFile = $cacheFile . '.' . uniqid('', true) . '.tmp';
        if (!rex_file::put($tmpFile, $content)) {
            return;
        }
        if (!rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
        }
    }
    
    /**
     * Erklärende Fehlermeldung aus den HTTP-Response-Headern eines fehlgeschlagenen
     * file_get_contents()-Requests bauen (Statuscode, Rate-Limit, Auth-Fehler).
     *
     * @param string[] $responseHeaders
     */
    private function describeRequestFailure(array $responseHeaders, string $url): string
    {
        $statusCode = null;
        $rateLimitRemaining = null;

        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $statusCode = (int) $matches[1];
            } elseif (stripos($header, 'X-RateLimit-Remaining:') === 0) {
                $rateLimitRemaining = trim(substr($header, strlen('X-RateLimit-Remaining:')));
            }
        }

        if (403 === $statusCode && '0' === $rateLimitRemaining) {
            return 'GitHub API Rate-Limit erreicht - bitte später erneut versuchen oder ein Token mit höherem Limit hinterlegen.';
        }
        if (401 === $statusCode) {
            return 'GitHub-Token ungültig oder abgelaufen - bitte in den Einstellungen prüfen.';
        }
        if (403 === $statusCode) {
            return "Zugriff verweigert (403): {$url} - Token-Berechtigungen prüfen.";
        }
        if (404 === $statusCode) {
            return "Resource not found (404): {$url}";
        }
        if (422 === $statusCode) {
            return "GitHub API request failed (HTTP 422, Validation fehlgeschlagen): {$url} - haeufig Ursache: Branch hat sich waehrend des Uploads geaendert (z.B. paralleler Upload), bitte erneut versuchen.";
        }
        if (null !== $statusCode) {
            return "GitHub API request failed (HTTP {$statusCode}): {$url}";
        }

        return "GitHub API request failed (keine Antwort erhalten, ggf. Netzwerkproblem): {$url}";
    }

    /**
     * Repository-Inhalte abrufen
     */
    public function getRepositoryContents(string $owner, string $repo, string $path = '', string $branch = 'main'): array
    {
        $endpoint = "repos/{$owner}/{$repo}/contents";
        if ($path) {
            $endpoint .= "/{$path}";
        }
        $endpoint .= "?ref={$branch}";
        
        return $this->makeRequest($endpoint);
    }
    
    /**
     * Datei-Inhalt abrufen
     */
    public function getFileContent(string $owner, string $repo, string $path, string $branch = 'main', bool $withMeta = false): mixed
    {
        $endpoint = "repos/{$owner}/{$repo}/contents/{$path}?ref={$branch}";
        $data = $this->makeRequest($endpoint);
        
        if (!isset($data['content']) || $data['type'] !== 'file') {
            throw new \Exception('File not found or not a file: ' . $path);
        }
        
        if ($withMeta) {
            return $data;
        }
        
        return base64_decode($data['content']);
    }
    
    /**
     * Prüfen ob Datei existiert
     */
    public function fileExists(string $owner, string $repo, string $path, string $branch = 'main'): bool
    {
        try {
            $endpoint = "repos/{$owner}/{$repo}/contents/{$path}?ref={$branch}";
            $this->makeRequest($endpoint);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Repository testen
     */
    public function testRepository(string $owner, string $repo, string $branch = 'main'): bool
    {
        try {
            $this->makeRequest("repos/{$owner}/{$repo}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Cache für spezifisches Repository löschen
     */
    public function clearRepositoryCache(string $owner, string $repo): void
    {
        $pattern = $this->cacheDir . '*' . md5("repos/{$owner}/{$repo}") . '*.json';
        foreach (glob($pattern) as $file) {
            unlink($file);
        }
    }
    
    /**
     * Gesamten Cache löschen
     */
    public function clearAllCache(): void
    {
        $pattern = $this->cacheDir . '*.json';
        foreach (glob($pattern) as $file) {
            unlink($file);
        }
    }
    
    /**
     * Cache-Statistiken
     */
    public function getCacheStats(): array
    {
        $files = glob($this->cacheDir . '*.json');
        $totalSize = 0;
        $fileCount = count($files);
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'file_count' => $fileCount,
            'total_size' => $totalSize,
            'formatted_size' => $this->formatBytes($totalSize),
        ];
    }
    
    /**
     * Bytes formatieren
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Datei auf GitHub erstellen oder aktualisieren
     */
    public function createOrUpdateFile(string $owner, string $repo, string $path, string $content, string $message = '', string $branch = 'main'): array
    {
        // Fallback für Commit-Message
        if (empty($message)) {
            $message = "Datei {$path} aktualisiert";
        }
        
        // Erst prüfen ob Datei bereits existiert
        $sha = null;
        try {
            $fileInfo = $this->getFileContent($owner, $repo, $path, $branch, true);
            $sha = $fileInfo['sha'] ?? null;
        } catch (\Exception $e) {
            // Datei existiert nicht - das ist OK für neue Dateien
        }
        
        $endpoint = "repos/{$owner}/{$repo}/contents/{$path}";
        
        $data = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch' => $branch
        ];
        
        // SHA hinzufügen falls Datei bereits existiert (für Update)
        if ($sha) {
            $data['sha'] = $sha;
        }
        
        return $this->makeRequest($endpoint, 'PUT', $data, false);
    }
    
    /**
     * Alias für createOrUpdateFile (legacy support)
     */
    public function uploadFile(string $owner, string $repo, string $path, string $content, string $message = '', string $branch = 'main'): array
    {
        return $this->createOrUpdateFile($owner, $repo, $path, $content, $message, $branch);
    }

    /**
     * Mehrere Dateien in EINEM Commit erstellen/aktualisieren (Git Data API).
     *
     * Die Contents-API (createOrUpdateFile) kann nur eine Datei pro Request und
     * erzeugt damit pro Datei einen eigenen Commit. Für Bulk-Uploads ist das
     * unpraktisch (Commit-Spam im Repo, langsam, Rate-Limit). Diese Methode nutzt
     * stattdessen Blobs -> Tree -> Commit -> Ref-Update, sodass beliebig viele
     * Dateien in einem einzigen Commit landen.
     *
     * Permissions: Die Git-Data-Endpunkte fallen bei Fine-grained Tokens unter
     * dieselbe Repository-Permission "Contents" (Read and write) wie die
     * Contents-API - es ist kein zusätzlicher Scope/Permission erforderlich.
     *
     * @param array<string, array{content: string}> $files Map Pfad => ['content' => ...]
     * @return array Commit-Info (sha, html_url) des erzeugten Commits
     */
    public function createOrUpdateFiles(string $owner, string $repo, array $files, string $message = '', string $branch = 'main'): array
    {
        if (empty($files)) {
            throw new \Exception('createOrUpdateFiles: keine Dateien übergeben');
        }

        // Retry-Loop: Wenn sich der Branch zwischen Basis-Commit-Auslesen und Ref-Update
        // aendert (z.B. paralleler Upload durch Doppelklick oder zwei Backend-Tabs),
        // antwortet das Ref-Update mit HTTP 422 "Update is not a fast forward". In dem
        // Fall den Basis-Commit neu holen und den Commit erneut erstellen (max. 3 Versuche).
        $lastException = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->createCommitOnBranch($owner, $repo, $files, $message, $branch);
            } catch (\Exception $e) {
                $lastException = $e;
                // Nur bei 422 (Fast-Forward-Konflikt) wiederholen, andere Fehler sofort werfen
                if (false === strpos($e->getMessage(), 'HTTP 422')) {
                    throw $e;
                }
            }
        }
        throw $lastException;
    }

    /**
     * Einmaliger Commit-Versuch: Blobs -> Tree -> Commit -> Ref-Update.
     * (Von createOrUpdateFiles() aus aufgerufen, inkl. Retry bei 422.)
     *
     * @param array<string, array{content: string}> $files Map Pfad => ['content' => ...]
     * @return array Commit-Info (sha, html_url) des erzeugten Commits
     */
    private function createCommitOnBranch(string $owner, string $repo, array $files, string $message, string $branch): array
    {
        // 1. Basis-Commit des Branches ermitteln
        $ref = $this->makeRequest("repos/{$owner}/{$repo}/git/ref/heads/{$branch}", 'GET', null, false);
        $baseCommitSha = $ref['object']['sha'] ?? null;
        if (!$baseCommitSha) {
            throw new \Exception("Branch {$branch} nicht gefunden in {$owner}/{$repo}");
        }

        $baseCommit = $this->makeRequest("repos/{$owner}/{$repo}/git/commits/{$baseCommitSha}", 'GET', null, false);
        $baseTreeSha = $baseCommit['tree']['sha'] ?? null;
        if (!$baseTreeSha) {
            throw new \Exception('Basis-Tree konnte nicht ermittelt werden');
        }

        // 2. Blobs für alle Dateien anlegen
        $treeItems = [];
        foreach ($files as $path => $fileData) {
            $blob = $this->makeRequest("repos/{$owner}/{$repo}/git/blobs", 'POST', [
                'content' => base64_encode($fileData['content']),
                'encoding' => 'base64',
            ], false);

            $treeItems[] = [
                'path' => $path,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $blob['sha'],
            ];
        }

        // 3. Neuen Tree erstellen (Basis-Tree als Ausgangspunkt, nur geänderte Pfade ersetzt)
        $tree = $this->makeRequest("repos/{$owner}/{$repo}/git/trees", 'POST', [
            'base_tree' => $baseTreeSha,
            'tree' => $treeItems,
        ], false);

        // 4. Commit erstellen
        $commit = $this->makeRequest("repos/{$owner}/{$repo}/git/commits", 'POST', [
            'message' => $message ?: 'Update ' . count($treeItems) . ' file(s)',
            'tree' => $tree['sha'],
            'parents' => [$baseCommitSha],
        ], false);

        // 5. Branch-Ref auf neuen Commit setzen (Fast-Forward)
        $this->makeRequest("repos/{$owner}/{$repo}/git/refs/heads/{$branch}", 'PATCH', [
            'sha' => $commit['sha'],
            'force' => false,
        ], false);

        return [
            'sha' => $commit['sha'],
            'html_url' => $commit['html_url'] ?? null,
            'file_count' => count($treeItems),
        ];
    }
}
