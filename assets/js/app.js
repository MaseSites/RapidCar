/**
 * RapidCar — zentrale Frontend-Helfer (Vanilla JS, kein Framework).
 * Dropdowns, Modals, Toasts, API-Fetch mit CSRF.
 */

(function () {
    'use strict';

    // ------------------------------------------------------------ Dropdowns
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-dropdown]');
        var openMenus = document.querySelectorAll('.dropdown-menu.open');

        if (toggle) {
            var menu = document.getElementById(toggle.getAttribute('data-dropdown'));
            openMenus.forEach(function (m) { if (m !== menu) m.classList.remove('open'); });
            if (menu) menu.classList.toggle('open');
            event.preventDefault();
            return;
        }
        if (!event.target.closest('.dropdown-menu')) {
            openMenus.forEach(function (m) { m.classList.remove('open'); });
        }
    });

    // ------------------------------------------------------------ Mobile-Nav
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-sidebar-toggle]')) {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-backdrop').classList.toggle('open');
        }
        if (event.target.classList && event.target.classList.contains('sidebar-backdrop')) {
            document.querySelector('.sidebar').classList.remove('open');
            event.target.classList.remove('open');
        }
    });

    // --------------------------------------------------------------- Modals
    window.openModal = function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('open');
    };
    window.closeModal = function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('open');
    };
    document.addEventListener('click', function (event) {
        if (event.target.classList && event.target.classList.contains('modal-backdrop')) {
            event.target.classList.remove('open');
        }
        var closer = event.target.closest('[data-close-modal]');
        if (closer) {
            var backdrop = closer.closest('.modal-backdrop');
            if (backdrop) backdrop.classList.remove('open');
        }
    });

    // --------------------------------------------------------------- Toasts
    window.showToast = function (message, type) {
        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        var toast = document.createElement('div');
        toast.className = 'toast ' + (type || '');
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(function () {
            toast.style.transition = 'opacity .4s';
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 400);
        }, 4500);
    };

    // Bestehende Server-Flashes automatisch ausblenden
    setTimeout(function () {
        document.querySelectorAll('.toast-container .toast[data-server]').forEach(function (toast) {
            toast.style.transition = 'opacity .4s';
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 400);
        });
    }, 5000);

    // ------------------------------------------------------------- API-Fetch
    /**
     * apiFetch('api/vehicles/update.php', {method:'POST', body:{...}})
     * → Promise mit {success, data, error}; CSRF-Token automatisch gesetzt.
     */
    window.apiFetch = function (url, options) {
        options = options || {};
        var meta = document.querySelector('meta[name="csrf-token"]');
        var base = document.querySelector('meta[name="base-url"]');
        var fullUrl = /^https?:/.test(url) ? url : (base ? base.content.replace(/\/$/, '') + '/' + url.replace(/^\//, '') : url);

        var fetchOptions = {
            method: options.method || 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        };
        if (meta) fetchOptions.headers['X-CSRF-Token'] = meta.content;

        if (options.body instanceof FormData) {
            fetchOptions.body = options.body;
        } else if (options.body) {
            fetchOptions.headers['Content-Type'] = 'application/json';
            fetchOptions.body = JSON.stringify(options.body);
        }

        return fetch(fullUrl, fetchOptions).then(function (response) {
            return response.json().catch(function () {
                return { success: false, data: null, error: 'Unerwartete Serverantwort (' + response.status + ').' };
            });
        }).catch(function () {
            return { success: false, data: null, error: 'Netzwerkfehler. Bitte Verbindung prüfen.' };
        });
    };

    // ------------------------------------- Auswahllisten bei KI-Unsicherheit
    // Wählt der Benutzer eine Alternative, wird sie in das Eingabefeld
    // übernommen. "Eigene Eingabe" gibt das Feld wieder frei.
    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!select.classList || !select.classList.contains('field-choice-select')) { return; }

        var wrapper = select.closest('.field-choice');
        var group = select.closest('.form-group');
        if (!wrapper || !group) { return; }

        var input = group.querySelector('input.form-control, textarea.form-control');
        if (!input) { return; }

        if (select.value === '__custom__') {
            input.value = '';
            input.readOnly = false;
            input.focus();
            wrapper.classList.add('is-custom');
            return;
        }
        input.value = select.value;
        wrapper.classList.remove('is-custom');
        // Änderung melden, damit abhängige Anzeigen reagieren
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // Tippt der Benutzer selbst, ist die Auswahl nicht mehr massgebend
    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input.classList || !input.classList.contains('form-control')) { return; }
        var group = input.closest('.form-group');
        if (!group) { return; }
        var select = group.querySelector('.field-choice-select');
        if (!select || select.value === '__custom__') { return; }
        if (input.value !== select.value) {
            select.value = '__custom__';
            var wrapper = select.closest('.field-choice');
            if (wrapper) { wrapper.classList.add('is-custom'); }
        }
    });

    // ------------------------------------------------- Ausstattungsauswahl
    // Fenster mit Suchfeld und Feldern; die Uebersicht darunter zeigt die
    // gewaehlten Merkmale als entfernbare Marken.
    var featureGroups = document.getElementById('featureGroups');
    if (featureGroups) {
        var featureSearch = document.getElementById('featureSearch');
        var featureEmpty = document.getElementById('featureEmpty');
        var featureCount = document.getElementById('featureCount');
        var featureDialog = document.getElementById('featureDialog');
        var featureSummary = document.getElementById('featureSummary');
        var featureOpen = document.getElementById('featureOpen');
        var customBox = document.querySelector('textarea[name="features_custom"]');

        function updateFeatureCount() {
            var picked = featureGroups.querySelectorAll('input[type="checkbox"]:checked').length;
            var own = customBox
                ? customBox.value.split(String.fromCharCode(10)).filter(function (line) { return line.trim() !== ''; }).length
                : 0;
            if (featureCount) { featureCount.textContent = String(picked + own); }
        }

        function rebuildSummary() {
            if (!featureSummary) { return; }
            featureSummary.querySelectorAll('.feature-tag').forEach(function (el) { el.remove(); });
            var frag = document.createDocumentFragment();
            featureGroups.querySelectorAll('input[type="checkbox"]:checked').forEach(function (box) {
                var tag = document.createElement('span');
                tag.className = 'feature-tag';
                tag.dataset.feature = box.value;
                tag.textContent = box.value;
                var x = document.createElement('button');
                x.type = 'button';
                x.textContent = String.fromCharCode(215);
                tag.appendChild(x);
                frag.appendChild(tag);
            });
            if (customBox) {
                customBox.value.split(String.fromCharCode(10)).forEach(function (line) {
                    if (line.trim() === '') { return; }
                    var tag = document.createElement('span');
                    tag.className = 'feature-tag is-custom';
                    tag.textContent = line.trim();
                    frag.appendChild(tag);
                });
            }
            featureSummary.insertBefore(frag, featureOpen);
            updateFeatureCount();
        }

        if (featureOpen && featureDialog) {
            featureOpen.addEventListener('click', function () { featureDialog.showModal(); });
            var featureClose = document.getElementById('featureClose');
            var featureDone = document.getElementById('featureDone');
            [featureClose, featureDone].forEach(function (btn) {
                if (btn) { btn.addEventListener('click', function () { featureDialog.close(); }); }
            });
            featureDialog.addEventListener('close', rebuildSummary);
            // Klick auf den abgedunkelten Hintergrund schliesst das Fenster
            featureDialog.addEventListener('click', function (event) {
                if (event.target === featureDialog) { featureDialog.close(); }
            });
        }

        if (featureSummary) {
            // Das Kreuz an einer Marke waehlt das Merkmal wieder ab
            featureSummary.addEventListener('click', function (event) {
                var button = event.target.closest('.feature-tag button');
                if (!button) { return; }
                var tag = button.closest('.feature-tag');
                var value = tag.dataset.feature || '';
                featureGroups.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                    if (box.value === value) {
                        box.checked = false;
                        box.closest('.feature-chip').classList.remove('is-on');
                    }
                });
                tag.remove();
                updateFeatureCount();
            });
        }

        featureGroups.addEventListener('change', function (event) {
            var box = event.target;
            if (box.type !== 'checkbox') { return; }
            box.closest('.feature-chip').classList.toggle('is-on', box.checked);
            updateFeatureCount();
        });
        if (customBox) { customBox.addEventListener('input', updateFeatureCount); }

        if (featureSearch) {
            featureSearch.addEventListener('input', function () {
                var needle = featureSearch.value.trim().toLowerCase();
                var anyVisible = false;
                featureGroups.querySelectorAll('.feature-group').forEach(function (group) {
                    var groupVisible = false;
                    group.querySelectorAll('.feature-chip').forEach(function (chip) {
                        var hit = needle === '' || chip.textContent.toLowerCase().indexOf(needle) !== -1;
                        chip.style.display = hit ? '' : 'none';
                        if (hit) { groupVisible = true; }
                    });
                    group.style.display = groupVisible ? '' : 'none';
                    if (groupVisible) { anyVisible = true; }
                });
                if (featureEmpty) { featureEmpty.style.display = anyVisible ? 'none' : ''; }
            });
        }
    }

    // ------------------------------------------------- Paket-Kacheln (Guthaben)
    document.addEventListener('change', function (event) {
        var radio = event.target;
        if (radio.type !== 'radio' || (radio.name !== 'package' && radio.name !== 'listing_tone')) { return; }
        var grid = radio.closest('.pkg-grid, .tone-grid');
        if (!grid) { return; }
        grid.querySelectorAll('.pkg-option, .tone-option').forEach(function (el) {
            el.classList.toggle('is-active', el.contains(radio));
        });
    });

    // ------------------------------------------------- Klickbare Tabellenzeilen
    // Zeilen mit data-href fuehren zur Detailseite; Klicks auf Verweise und
    // Knoepfe innerhalb der Zeile bleiben unberuehrt.
    document.addEventListener('click', function (event) {
        var row = event.target.closest('tr[data-href]');
        if (!row) { return; }
        if (event.target.closest('a, button, input, select, label, form')) { return; }
        window.location.href = row.dataset.href;
    });

    // ------------------------------------------------ Bestätigungsfenster
    // Statt des harten Browser-Hinweises ein weiches Fenster im Design der
    // Anwendung: rot fuer Loeschen, gruen fuer bestaetigende Schritte.
    var confirmLabels = {
        cancel: document.body.dataset.labelCancel || 'Abbrechen',
        confirm: document.body.dataset.labelConfirm || 'Bestätigen',
        remove: document.body.dataset.labelDelete || 'Löschen'
    };

    function softConfirm(text, tone, label) {
        tone = tone === 'success' ? 'success' : 'danger';
        return new Promise(function (resolve) {
            var dialog = document.createElement('dialog');
            dialog.className = 'confirm-dialog';
            var body = document.createElement('div');
            body.className = 'confirm-body';
            body.textContent = text;
            var foot = document.createElement('div');
            foot.className = 'confirm-foot';
            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-secondary confirm-cancel';
            cancel.textContent = confirmLabels.cancel;
            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'btn confirm-ok is-' + tone;
            ok.textContent = label || (tone === 'danger' ? confirmLabels.remove : confirmLabels.confirm);
            foot.appendChild(cancel);
            foot.appendChild(ok);
            dialog.appendChild(body);
            dialog.appendChild(foot);
            document.body.appendChild(dialog);

            var done = function (answer) {
                dialog.close();
                dialog.remove();
                resolve(answer);
            };
            cancel.addEventListener('click', function () { done(false); });
            ok.addEventListener('click', function () { done(true); });
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) { done(false); }
            });
            dialog.addEventListener('cancel', function (event) {
                event.preventDefault();
                done(false);
            });
            dialog.showModal();
            ok.focus();
        });
    }
    window.softConfirm = softConfirm;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.hasAttribute('data-confirm') || form.dataset.confirmed === '1') {
            return;
        }
        event.preventDefault();
        softConfirm(
            form.getAttribute('data-confirm'),
            form.getAttribute('data-confirm-tone') || 'danger',
            form.getAttribute('data-confirm-label')
        ).then(function (answer) {
            if (!answer) { return; }
            form.dataset.confirmed = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    });
})();
