/* =====================================================================
   WMS - Admin behaviours
   Sidebar drawer, live tiles, row actions and the async operations the
   control centre needs (router tests, disconnects, voucher actions).
   ===================================================================== */
(function () {
    'use strict';

    var $ = WMS.$, $$ = WMS.$$;

    /* ---------------------------------------------------- sidebar drawer */

    function closeSidebar() {
        var sidebar = $('.sidebar');
        var backdrop = $('.sidebar__backdrop');
        if (sidebar) { sidebar.classList.remove('is-open'); }
        if (backdrop) { backdrop.classList.remove('is-open'); }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-sidebar-toggle]')) {
            e.preventDefault();
            var sidebar = $('.sidebar');
            var backdrop = $('.sidebar__backdrop');
            if (sidebar) { sidebar.classList.toggle('is-open'); }
            if (backdrop) { backdrop.classList.toggle('is-open'); }
            return;
        }
        if (e.target.closest('.sidebar__backdrop')) { closeSidebar(); }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 1024) { closeSidebar(); }
    });

    /* ------------------------------------------------------- row actions
       Any element with data-action posts to an endpoint and reports back.
       Attributes: data-action (url), data-payload (json), data-confirm,
       data-reload, data-success.
       ------------------------------------------------------------------ */

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-action]');
        if (!trigger) { return; }
        e.preventDefault();

        var run = function () {
            var reset = WMS.loading(trigger);
            var payload = {};
            try { payload = JSON.parse(trigger.getAttribute('data-payload') || '{}'); } catch (err) { payload = {}; }

            WMS.request(trigger.getAttribute('data-action'), { method: 'POST', body: payload })
                .then(function (response) {
                    reset();
                    WMS.toast(response.message || (response.success ? 'Done.' : 'That did not work.'), response.success ? 'success' : 'error');
                    if (response.success && trigger.hasAttribute('data-reload')) {
                        setTimeout(function () { window.location.reload(); }, 700);
                    }
                    if (response.success && trigger.getAttribute('data-redirect')) {
                        setTimeout(function () { window.location.href = WMS.url(trigger.getAttribute('data-redirect')); }, 700);
                    }
                    document.dispatchEvent(new CustomEvent('wms:action', { detail: { trigger: trigger, response: response } }));
                });
        };

        if (trigger.hasAttribute('data-confirm')) {
            WMS.confirm({
                title: trigger.getAttribute('data-confirm-title') || 'Please confirm',
                message: trigger.getAttribute('data-confirm'),
                confirmText: trigger.getAttribute('data-confirm-button') || 'Continue',
                tone: trigger.getAttribute('data-confirm-tone') || 'danger'
            }).then(function (ok) { if (ok) { run(); } });
        } else {
            run();
        }
    });

    /* ------------------------------------------------------------- tabs */

    /*
     * Panels are already in the page; switching a tab only changes which one
     * is visible. Nothing is re-fetched, so a slow network cannot leave a
     * tab blank.
     */
    document.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-tabs] .tab');
        if (!tab) { return; }
        e.preventDefault();

        var set  = tab.closest('[data-tabs]');
        var name = tab.getAttribute('data-tab');

        Array.prototype.forEach.call(set.querySelectorAll('.tab'), function (node) {
            var on = node === tab;
            node.classList.toggle('is-active', on);
            node.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        Array.prototype.forEach.call(set.querySelectorAll('.tab-panel'), function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-panel') === name);
        });
    });

    /* ------------------------------------------------------- router test */

    /**
     * Renders the item-by-item result of a connection test.
     * Every line shown here came back from the router - the endpoint never
     * returns a credential, so there is nothing secret to leak into the DOM.
     */
    function renderTestResult(response) {
        var data    = response.data || {};
        var facts   = data.facts || {};
        var checks  = data.checks || [];
        var causes  = data.causes || [];
        var ok      = !!response.success;
        var html    = '<div class="alert alert--' + (ok ? 'success' : 'danger') + '">' +
            '<div class="alert__body"><div class="alert__title">' +
            (ok ? '\u2713 Connection successful' : '\u2715 Connection failed') + '</div>' +
            WMS.escape(response.message || '');

        if (checks.length) {
            html += '<ul class="check-list">';
            checks.forEach(function (check) {
                html += '<li><span class="check-list__mark check-list__mark--' + (check.ok ? 'ok' : 'fail') + '">' +
                    (check.ok ? '\u2713' : '\u2715') + '</span><span>' +
                    '<span class="check-list__label">' + WMS.escape(check.label) + '</span>' +
                    '<span class="check-list__detail"> — ' + WMS.escape(check.detail || '') + '</span>' +
                    '</span></li>';
            });
            html += '</ul>';
        }

        if (ok) {
            var lines = [];
            if (facts.identity)    { lines.push('Router identity: ' + facts.identity); }
            if (facts.version)     { lines.push('RouterOS version: ' + facts.version); }
            if (facts.board)       { lines.push('Board: ' + facts.board); }
            if (facts.hotspot)     { lines.push('Hotspot: ' + facts.hotspot); }
            if (facts.response_ms !== null && facts.response_ms !== undefined) {
                lines.push('Response time: ' + facts.response_ms + ' ms');
            }
            if (lines.length) {
                html += '<div class="tiny muted" style="margin-top:.5rem">' +
                    lines.map(WMS.escape).join(' · ') + '</div>';
            }
        } else if (causes.length) {
            html += '<div class="tiny" style="margin-top:.5rem"><b>Possible causes:</b><ul style="margin:.25rem 0 0 1rem">';
            causes.forEach(function (cause) { html += '<li>' + WMS.escape(cause) + '</li>'; });
            html += '</ul></div>';
        }

        return html + '</div></div>';
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-router-test]');
        if (!trigger) { return; }
        e.preventDefault();

        // Disabled while it runs, so a second click cannot open a second
        // connection to the same router.
        if (trigger.disabled) { return; }

        var reset  = WMS.loading(trigger, 'Testing connection…');
        var target = document.querySelector(trigger.getAttribute('data-result') || '#router-test-result');

        if (target) {
            target.innerHTML = '<div class="loading-row"><span class="spinner"></span> Testing connection…</div>';
        }

        WMS.request('api/network/router-test.php', {
            method: 'POST',
            body: { router_id: Number(trigger.getAttribute('data-router-test')) }
        }).then(function (response) {
            reset();
            if (target) {
                target.innerHTML = renderTestResult(response);
                target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            WMS.toast(response.message || '', response.success ? 'success' : 'error', 7000);
        });
    });

    /* ------------------------------------------- hotspot server discovery */

    /*
     * Asks the router which hotspot servers it really has and offers those.
     * When it has none, the field is left empty and the page says so - WMS
     * never fills in a server name the router does not have.
     */
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-hotspot-discover]');
        if (!trigger || trigger.disabled) { return; }
        e.preventDefault();

        var reset  = WMS.loading(trigger, 'Reading…');
        var note   = document.getElementById('hotspot-discovery');
        var setNote = function (text, tone) {
            if (note) {
                note.className = 'tiny ' + (tone === 'warn' ? '' : 'muted');
                if (tone === 'warn') { note.style.color = 'var(--wms-warning)'; } else { note.style.color = ''; }
                note.textContent = text;
            }
        };

        setNote('Reading hotspot servers from the router…');

        WMS.request('api/network/hotspot-servers.php', {
            method: 'POST',
            body: { router_id: Number(trigger.getAttribute('data-hotspot-discover')) }
        }).then(function (response) {
            reset();

            var data     = response.data || {};
            var servers  = data.servers || [];
            var profiles = data.profiles || [];

            var serverList = document.getElementById('hotspot-servers');
            if (serverList) {
                serverList.innerHTML = '';
                servers.forEach(function (server) {
                    var option = document.createElement('option');
                    option.value = server.name;
                    option.label = server.interface ? server.name + ' (' + server.interface + ')' : server.name;
                    serverList.appendChild(option);
                });
            }

            var profileList = document.getElementById('hotspot-profiles');
            if (profileList) {
                profileList.innerHTML = '';
                profiles.forEach(function (name) {
                    var option = document.createElement('option');
                    option.value = name;
                    profileList.appendChild(option);
                });
            }

            if (!response.success) {
                setNote(response.message || 'The hotspot servers could not be read.', 'warn');
                return;
            }
            if (!servers.length) {
                setNote('No RouterOS hotspot server detected on this router.', 'warn');
                return;
            }
            setNote('Found: ' + servers.map(function (s) { return s.name; }).join(', ') +
                '. Start typing in the Hotspot server box to pick one.');
        });
    });

    /* --------------------------------------------------- live dashboard */

    var pulse = $('[data-live-pulse]');
    if (pulse) {
        var refresh = function () {
            WMS.request('api/network/status.php').then(function (response) {
                if (!response.success || !response.data) { return; }
                Object.keys(response.data).forEach(function (key) {
                    var node = document.querySelector('[data-live="' + key + '"]');
                    if (node) { node.textContent = response.data[key]; }
                });
                var stamp = $('[data-live-stamp]');
                if (stamp) { stamp.textContent = 'Updated just now'; }
            });
        };
        setInterval(refresh, 30000);
    }

    /* ------------------------------------------------ voucher generator */

    var generator = $('[data-voucher-preview]');
    if (generator) {
        var update = function () {
            var form = generator.closest('form');
            if (!form) { return; }
            var prefix = (form.querySelector('[name="prefix"]') || {}).value || '';
            var length = parseInt((form.querySelector('[name="code_length"]') || {}).value, 10) || 8;
            var quantity = parseInt((form.querySelector('[name="quantity"]') || {}).value, 10) || 1;
            var body = Math.max(4, length - (prefix ? Math.min(prefix.length + 1, length - 4) : 0));
            var sample = '';
            var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            for (var i = 0; i < body; i++) { sample += alphabet[Math.floor(Math.random() * alphabet.length)]; }
            generator.textContent = (prefix ? prefix.toUpperCase() + '-' : '') + sample;
            var counter = $('[data-voucher-count]');
            if (counter) { counter.textContent = quantity.toLocaleString(); }
        };
        ['input', 'change'].forEach(function (evt) {
            var form = generator.closest('form');
            if (form) { form.addEventListener(evt, update); }
        });
        update();
    }

    /* ------------------------------------------------- package form aid */

    var dataToggle = $('[data-unlimited-toggle]');
    if (dataToggle) {
        var sync = function () {
            var field = $('[data-data-limit]');
            if (!field) { return; }
            field.disabled = dataToggle.checked;
            field.closest('.field').style.opacity = dataToggle.checked ? '.5' : '1';
        };
        dataToggle.addEventListener('change', sync);
        sync();
    }

    /* ------------------------------------------------------- print view */

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-print]')) {
            e.preventDefault();
            window.print();
        }
    });

    /* Bulk action form gathers the ticked ids. */
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-bulk-form]');
        if (!form) { return; }
        var checked = $$('input[type="checkbox"][name="ids[]"]:checked');
        if (!checked.length) {
            e.preventDefault();
            WMS.toast('Select at least one row first.', 'warning');
            return;
        }
        // Mirror the ticked ids into the bulk form.
        $$('[data-bulk-input]', form).forEach(function (node) { node.remove(); });
        checked.forEach(function (box) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'ids[]';
            hidden.value = box.value;
            hidden.setAttribute('data-bulk-input', '');
            form.appendChild(hidden);
        });
    });
})();
