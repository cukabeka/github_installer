/**
 * GitHub Installer - Bulk Upload
 * Vanilla JS, keine Abhängigkeiten. Wird nur auf der Upload-Page geladen.
 *
 * Funktionen:
 *  - Select-all-Checkbox toggelt alle Item-Checkboxen der aktuellen Seite
 *  - "Alle auswählen (alle Seiten)"-Link: setzt ein Hidden-Flag, das der
 *    Server beim Bulk-Upload in "alle Items des Typs" auflöst
 *  - Auswahl-Zähler im Bulk-Panel aktualisiert sich live
 *  - Zeilen-pro-Seite-Wechsel submitted das Formular automatisch
 */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('github-installer-bulk-form');
        if (!form) {
            return;
        }

        var counter = document.getElementById('bulk-selected-count');
        var selectAll = form.querySelector('input[data-bulk-select-all]');
        var selectAllPages = document.getElementById('bulk-select-all-pages');
        var selectAllPagesFlag = document.getElementById('bulk_select_all_pages_flag');
        var checkboxes = Array.prototype.slice.call(form.querySelectorAll('input[name="bulk_ids[]"]'));

        function selectedCount() {
            return checkboxes.filter(function (cb) { return cb.checked; }).length;
        }

        function updateCounter() {
            if (!counter) {
                return;
            }
            var allPages = selectAllPagesFlag && selectAllPagesFlag.value === '1';
            if (allPages) {
                counter.textContent = counter.getAttribute('data-template-all') || counter.getAttribute('data-template');
            } else {
                counter.textContent = counter.getAttribute('data-template').replace('{0}', selectedCount());
            }
            counter.classList.toggle('label-success', allPages || selectedCount() > 0);
            counter.classList.toggle('label-default', !allPages && selectedCount() === 0);
        }

        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                // Manuelle Auswahl hebt das "alle Seiten"-Flag auf
                if (selectAllPagesFlag) {
                    selectAllPagesFlag.value = '0';
                }
                if (selectAllPages) {
                    selectAllPages.style.display = '';
                }
                updateCounter();
                if (selectAll) {
                    selectAll.checked = selectedCount() === checkboxes.length;
                }
            });
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checked = selectAll.checked;
                checkboxes.forEach(function (cb) { cb.checked = checked; });
                if (!checked && selectAllPagesFlag) {
                    selectAllPagesFlag.value = '0';
                    if (selectAllPages) {
                        selectAllPages.style.display = '';
                    }
                }
                updateCounter();
            });
        }

        if (selectAllPages) {
            selectAllPages.addEventListener('click', function (e) {
                e.preventDefault();
                checkboxes.forEach(function (cb) { cb.checked = true; });
                if (selectAllPagesFlag) {
                    selectAllPagesFlag.value = '1';
                }
                if (selectAll) {
                    selectAll.checked = true;
                }
                // Link ausblenden, solange "alle Seiten" aktiv ist
                selectAllPages.style.display = 'none';
                updateCounter();
            });
        }

        // Zeilen-pro-Seite: Formular mit neuem Wert abschicken (ohne Bulk-Aktion)
        var rowsSelect = document.getElementById('bulk_rows_per_page');
        if (rowsSelect) {
            rowsSelect.addEventListener('change', function () {
                // Bulk-Submit-Kennzeichnung entfernen, damit nur neu geladen wird
                var submitBtn = form.querySelector('button[name="bulk_upload"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                form.submit();
            });
        }

        updateCounter();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
