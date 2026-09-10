/* =====================================================================
   WMS - Core front-end
   Vanilla JavaScript only. No framework, no build step, no CDN.
   Exposes a single global: window.WMS
   ===================================================================== */
(function () {
    'use strict';

    var WMS = {
        base: (document.documentElement.getAttribute('data-base') || '/'),
        csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
    };

    /* ---------------------------------------------------------- helpers */

    function $(selector, scope) { return (scope || document).querySelector(selector); }
    function $$(selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); }

    WMS.$ = $;
    WMS.$$ = $$;

    WMS.url = function (path) {
        return WMS.base.replace(/\/$/, '/') + String(path).replace(/^\//, '');
    };

    WMS.escape = function (value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    };

    WMS.formatBytes = function (bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) { return bytes + ' B'; }
        var units = ['KB', 'MB', 'GB', 'TB'], i = -1;
        do { bytes /= 1024; i++; } while (bytes >= 1024 && i < units.length - 1);
        return (bytes >= 100 ? bytes.toFixed(0) : bytes.toFixed(1)) + ' ' + units[i];
    };

    WMS.formatDuration = function (seconds) {
        seconds = Math.max(0, Number(seconds) || 0);
        var d = Math.floor(seconds / 86400),
            h = Math.floor((seconds % 86400) / 3600),
            m = Math.floor((seconds % 3600) / 60),
            s = Math.floor(seconds % 60);
        if (d) { return d + 'd ' + h + 'h'; }
        if (h) { return h + 'h ' + (m < 10 ? '0' : '') + m + 'm'; }
        if (m) { return m + 'm ' + (s < 10 ? '0' : '') + s + 's'; }
        return s + 's';
    };

    /* ------------------------------------------------------------ icons */

    var ICONS = {
        check: '<path d="m4 8.5 3 3 6.5-7"/>',
        alert: '<path d="M8 5v4M8 11.5v.01"/><circle cx="8" cy="8" r="6.5"/>',
        info:  '<circle cx="8" cy="8" r="6.5"/><path d="M8 7.5v4M8 4.5v.01"/>',
        close: '<path d="m4 4 8 8M12 4l-8 8"/>'
    };

    function svg(name, cls) {
        return '<svg class="ico ' + (cls || '') + '" viewBox="0 0 16 16" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
    }

    /* ----------------------------------------------------------- toasts */

    function toastStack() {
        var stack = $('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            stack.setAttribute('role', 'status');
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        return stack;
    }

    /**
     * Shows a transient message.
     * @param {string} message
     * @param {string} type success|error|warning|info
     */
    WMS.toast = function (message, type, timeout) {
        type = type || 'info';
        var icon = type === 'success' ? 'check' : (type === 'error' || type === 'warning' ? 'alert' : 'info');
        var el = document.createElement('div');
        el.className = 'toast toast--' + type;
        el.innerHTML = svg(icon, 'toast__icon') +
            '<div class="toast__body">' + WMS.escape(message) + '</div>' +
            '<button type="button" class="toast__close" aria-label="Dismiss">' + svg('close') + '</button>';

        toastStack().appendChild(el);

        var timer = setTimeout(remove, timeout || 4200);
        el.querySelector('.toast__close').addEventListener('click', function () {
            clearTimeout(timer);
            remove();
        });

        function remove() {
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 170);
        }
        return el;
    };

    /* ----------------------------------------------------------- modals */

    WMS.openModal = function (idOrEl) {
        var modal = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
        if (!modal) { return null; }
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        var focusable = modal.querySelector('input:not([type=hidden]), select, textarea, button');
        if (focusable) { setTimeout(function () { focusable.focus(); }, 40); }
        return modal;
    };

    WMS.closeModal = function (idOrEl) {
        var modal = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
        if (!modal) { return; }
        modal.classList.remove('is-open');
        if (!$('.modal.is-open')) { document.body.style.overflow = ''; }
    };

    /**
     * Confirmation dialog. Returns a promise resolving to true/false.
     * @param {object} options title, message, confirmText, tone
     */
    WMS.confirm = function (options) {
        options = options || {};
        return new Promise(function (resolve) {
            var modal = document.createElement('div');
            modal.className = 'modal is-open';
            modal.innerHTML =
                '<div class="modal__panel" role="dialog" aria-modal="true">' +
                    '<div class="modal__head">' +
                        '<h3 class="modal__title">' + WMS.escape(options.title || 'Are you sure?') + '</h3>' +
                        '<button type="button" class="modal__close" data-act="cancel" aria-label="Close">' + svg('close') + '</button>' +
                    '</div>' +
                    '<div class="modal__body"><p class="mb-0">' + WMS.escape(options.message || 'This action cannot be undone.') + '</p></div>' +
                    '<div class="modal__foot">' +
                        '<button type="button" class="btn" data-act="cancel">' + WMS.escape(options.cancelText || 'Cancel') + '</button>' +
                        '<button type="button" class="btn ' + (options.tone === 'danger' ? 'btn--danger' : 'btn--primary') + '" data-act="ok">' +
                            WMS.escape(options.confirmText || 'Confirm') + '</button>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            var okButton = modal.querySelector('[data-act="ok"]');
            if (okButton) { okButton.focus(); }

            function finish(result) {
                modal.remove();
                if (!$('.modal.is-open')) { document.body.style.overflow = ''; }
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function onKey(e) { if (e.key === 'Escape') { finish(false); } }

            modal.addEventListener('click', function (e) {
                var act = e.target.closest('[data-act]');
                if (act) { finish(act.getAttribute('data-act') === 'ok'); }
                else if (e.target === modal) { finish(false); }
            });
            document.addEventListener('keydown', onKey);
        });
    };

    /* ------------------------------------------------------------ fetch */

    /**
     * JSON request with the CSRF token attached.
     * Always resolves - network failures come back as {success:false}.
     */
    WMS.request = function (url, options) {
        options = options || {};
        var headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': WMS.csrf
        };
        var config = { method: options.method || 'GET', headers: headers, credentials: 'same-origin' };

        if (options.body) {
            if (options.body instanceof FormData) {
                options.body.append('csrf_token', WMS.csrf);
                config.body = options.body;
            } else {
                headers['Content-Type'] = 'application/json';
                config.body = JSON.stringify(Object.assign({ csrf_token: WMS.csrf }, options.body));
            }
        }

        return fetch(WMS.url(url), config)
            .then(function (response) {
                return response.json()
                    .catch(function () {
                        return { success: false, message: 'The server returned an unexpected response.' };
                    })
                    .then(function (data) {
                        if (response.status === 419 || response.status === 401) {
                            data.message = data.message || 'Your session expired. Please refresh the page.';
                        }
                        return data;
                    });
            })
            .catch(function () {
                return { success: false, message: 'Connection problem. Please check your network and try again.' };
            });
    };

    /** Puts a button into its loading state and returns a reset function. */
    /**
     * Puts a button into its loading state and returns the undo function.
     *
     * The button is disabled while the request runs, so a second click cannot
     * start a second request. An optional label replaces the button text for
     * the duration, e.g. "Testing connection…".
     */
    WMS.loading = function (button, label) {
        if (!button) { return function () {}; }

        var original = null;
        if (label) {
            original = button.innerHTML;
            button.textContent = label;
        }
        button.classList.add('is-loading');
        button.disabled = true;

        return function () {
            button.classList.remove('is-loading');
            button.disabled = false;
            if (original !== null) { button.innerHTML = original; }
        };
    };

    /* ------------------------------------------------------------ charts
       Small SVG chart renderer: line, area, bar and donut. Responsive by
       viewBox, themed from the CSS custom properties.
       ------------------------------------------------------------------ */

    var PALETTE = ['#22b8d8', '#6fe6fc', '#0a6e85', '#b4610f', '#12805c', '#55686f'];

    function svgEl(name, attrs) {
        var el = document.createElementNS('http://www.w3.org/2000/svg', name);
        Object.keys(attrs || {}).forEach(function (key) { el.setAttribute(key, attrs[key]); });
        return el;
    }

    function niceMax(value) {
        if (value <= 0) { return 1; }
        var magnitude = Math.pow(10, Math.floor(Math.log10(value)));
        var scaled = value / magnitude;
        var step = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10;
        return step * magnitude;
    }

    /**
     * Renders a chart into a container element.
     * @param {Element|string} target
     * @param {object} config {type, labels, series:[{name,data}], format}
     */
    WMS.chart = function (target, config) {
        var host = typeof target === 'string' ? document.getElementById(target) : target;
        if (!host) { return; }
        config = config || {};
        var type = config.type || 'line';
        var labels = config.labels || [];
        var series = config.series || [];

        host.innerHTML = '';
        host.classList.add('chart');

        if (!labels.length || !series.length) {
            host.innerHTML = '<div class="empty" style="padding:2rem 1rem"><div class="empty__text">No data for this period yet.</div></div>';
            return;
        }

        if (type === 'donut') { return donut(host, config); }

        var W = 640, H = config.height || 220;
        var pad = { top: 12, right: 10, bottom: 26, left: 46 };
        var innerW = W - pad.left - pad.right;
        var innerH = H - pad.top - pad.bottom;

        var max = 0;
        series.forEach(function (s) {
            (s.data || []).forEach(function (v) { max = Math.max(max, Number(v) || 0); });
        });
        max = niceMax(max);

        var root = svgEl('svg', {
            viewBox: '0 0 ' + W + ' ' + H,
            preserveAspectRatio: 'none',
            role: 'img',
            'aria-label': config.title || 'chart'
        });
        root.style.height = H + 'px';
        root.style.maxHeight = '100%';

        var x = function (i) {
            return labels.length === 1 ? pad.left + innerW / 2 : pad.left + (i * innerW) / (labels.length - 1);
        };
        var y = function (v) { return pad.top + innerH - ((Number(v) || 0) / max) * innerH; };

        /* gridlines + y axis labels */
        for (var g = 0; g <= 4; g++) {
            var gy = pad.top + (innerH * g) / 4;
            root.appendChild(svgEl('line', {
                x1: pad.left, y1: gy, x2: W - pad.right, y2: gy,
                stroke: '#dde6ea', 'stroke-width': 1
            }));
            var text = svgEl('text', { x: pad.left - 8, y: gy + 3.5, 'text-anchor': 'end', fill: '#86979e', 'font-size': 10 });
            text.textContent = formatTick(max - (max * g) / 4, config.format);
            root.appendChild(text);
        }

        /* x axis labels - thinned so they never collide */
        var step = Math.max(1, Math.ceil(labels.length / 8));
        labels.forEach(function (labelText, i) {
            if (i % step !== 0 && i !== labels.length - 1) { return; }
            var t = svgEl('text', {
                x: x(i), y: H - 8,
                'text-anchor': i === 0 ? 'start' : (i === labels.length - 1 ? 'end' : 'middle'),
                fill: '#86979e', 'font-size': 10
            });
            t.textContent = labelText;
            root.appendChild(t);
        });

        if (type === 'bar') {
            var groupWidth = innerW / labels.length;
            var barWidth = Math.max(3, Math.min(26, (groupWidth * 0.62) / series.length));
            series.forEach(function (s, si) {
                (s.data || []).forEach(function (v, i) {
                    var barX = pad.left + groupWidth * i + groupWidth / 2 - (barWidth * series.length) / 2 + barWidth * si;
                    var barY = y(v);
                    var rect = svgEl('rect', {
                        x: barX, y: barY, width: barWidth,
                        height: Math.max(1, pad.top + innerH - barY),
                        rx: 2, fill: PALETTE[si % PALETTE.length]
                    });
                    rect.setAttribute('data-tip', (s.name ? s.name + ': ' : '') + formatTick(v, config.format) + ' · ' + labels[i]);
                    root.appendChild(rect);
                });
            });
        } else {
            series.forEach(function (s, si) {
                var colour = PALETTE[si % PALETTE.length];
                var points = (s.data || []).map(function (v, i) { return x(i) + ',' + y(v); });

                if (type === 'area' || (config.fill && si === 0)) {
                    root.appendChild(svgEl('polygon', {
                        points: pad.left + ',' + (pad.top + innerH) + ' ' + points.join(' ') + ' ' + (pad.left + innerW) + ',' + (pad.top + innerH),
                        fill: colour, opacity: .08
                    }));
                }
                root.appendChild(svgEl('polyline', {
                    points: points.join(' '), fill: 'none', stroke: colour,
                    'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round',
                    'vector-effect': 'non-scaling-stroke'
                }));
                (s.data || []).forEach(function (v, i) {
                    var dot = svgEl('circle', { cx: x(i), cy: y(v), r: 3.2, fill: '#fff', stroke: colour, 'stroke-width': 1.8 });
                    dot.setAttribute('data-tip', (s.name ? s.name + ': ' : '') + formatTick(v, config.format) + ' · ' + labels[i]);
                    root.appendChild(dot);
                });
            });
        }

        host.appendChild(root);
        attachTips(host, root);

        if (series.length > 1 || config.legend) {
            var legend = document.createElement('div');
            legend.className = 'chart__legend';
            legend.innerHTML = series.map(function (s, i) {
                return '<span><i class="chart__swatch" style="background:' + PALETTE[i % PALETTE.length] + '"></i>' + WMS.escape(s.name || ('Series ' + (i + 1))) + '</span>';
            }).join('');
            host.appendChild(legend);
        }
    };

    function formatTick(value, format) {
        value = Number(value) || 0;
        if (format === 'bytes') { return WMS.formatBytes(value); }
        if (format === 'money') {
            return value >= 1000000 ? (value / 1000000).toFixed(1) + 'M'
                 : value >= 1000 ? (value / 1000).toFixed(value >= 10000 ? 0 : 1) + 'k'
                 : String(Math.round(value));
        }
        if (value >= 1000) { return (value / 1000).toFixed(1) + 'k'; }
        return String(Math.round(value * 10) / 10);
    }

    function donut(host, config) {
        var data = config.series[0].data || [];
        var labels = config.labels || [];
        var total = data.reduce(function (sum, v) { return sum + (Number(v) || 0); }, 0);
        var size = 150, radius = 60, stroke = 20, cx = size / 2, cy = size / 2;

        var root = svgEl('svg', { viewBox: '0 0 ' + size + ' ' + size, role: 'img' });
        root.style.maxWidth = '190px';
        root.style.margin = '0 auto';

        if (total <= 0) {
            root.appendChild(svgEl('circle', { cx: cx, cy: cy, r: radius, fill: 'none', stroke: '#dde6ea', 'stroke-width': stroke }));
        } else {
            var offset = 0;
            var circumference = 2 * Math.PI * radius;
            data.forEach(function (value, i) {
                var portion = (Number(value) || 0) / total;
                if (portion <= 0) { return; }
                var arc = svgEl('circle', {
                    cx: cx, cy: cy, r: radius, fill: 'none',
                    stroke: PALETTE[i % PALETTE.length],
                    'stroke-width': stroke,
                    'stroke-dasharray': (portion * circumference) + ' ' + circumference,
                    'stroke-dashoffset': -offset,
                    transform: 'rotate(-90 ' + cx + ' ' + cy + ')'
                });
                arc.setAttribute('data-tip', (labels[i] || '') + ': ' + value);
                root.appendChild(arc);
                offset += portion * circumference;
            });
        }

        var centreValue = svgEl('text', { x: cx, y: cy - 2, 'text-anchor': 'middle', 'font-size': 20, 'font-weight': 650, fill: '#10222a' });
        centreValue.textContent = formatTick(config.centre != null ? config.centre : total, config.format);
        root.appendChild(centreValue);

        var centreLabel = svgEl('text', { x: cx, y: cy + 14, 'text-anchor': 'middle', 'font-size': 9, fill: '#86979e' });
        centreLabel.textContent = config.centreLabel || 'Total';
        root.appendChild(centreLabel);

        host.appendChild(root);
        attachTips(host, root);

        var legend = document.createElement('div');
        legend.className = 'chart__legend';
        legend.style.justifyContent = 'center';
        legend.innerHTML = labels.map(function (labelText, i) {
            return '<span><i class="chart__swatch" style="background:' + PALETTE[i % PALETTE.length] + '"></i>' +
                WMS.escape(labelText) + ' <b>' + WMS.escape(String(data[i] || 0)) + '</b></span>';
        }).join('');
        host.appendChild(legend);
    }

    function attachTips(host, root) {
        var tip = document.createElement('div');
        tip.className = 'chart__tip';
        host.appendChild(tip);

        root.addEventListener('mousemove', function (e) {
            var target = e.target.closest('[data-tip]');
            if (!target) { tip.classList.remove('is-visible'); return; }
            var box = host.getBoundingClientRect();
            tip.textContent = target.getAttribute('data-tip');
            tip.style.left = (e.clientX - box.left) + 'px';
            tip.style.top = (e.clientY - box.top) + 'px';
            tip.classList.add('is-visible');
        });
        root.addEventListener('mouseleave', function () { tip.classList.remove('is-visible'); });
    }

    /* ------------------------------------------------- global behaviours */

    /* ------------------------------------------------------------ dropdowns
       Row menus live inside .table-wrap, which scrolls horizontally. An
       ancestor that scrolls clips its descendants, so an absolutely
       positioned menu was being cut off - on a phone it never appeared at
       all. The menu is therefore positioned as a fixed layer against the
       viewport and placed from the trigger's own rectangle, which no
       ancestor can clip.

       Below the sheet breakpoint it becomes a bottom sheet instead: a menu
       pinned under a 30px button is a poor target on a touch screen. */

    var SHEET_WIDTH = 640;

    function isSheet() { return window.innerWidth <= SHEET_WIDTH; }

    /*
     * An open menu is moved to <body> and put back when it closes.
     *
     * position:fixed is only fixed to the viewport while no ancestor has
     * claimed the job of containing block - and a filter, a transform or
     * `contain` all claim it. The admin topbar carries a backdrop-filter,
     * so the account menu was being placed relative to the topbar: shifted
     * right by the width of the sidebar, off the side of the screen, with
     * Sign out on it. The portal's own top bar had grown the same filter
     * and the same problem.
     *
     * Rather than police which ancestors may have a filter, the menu simply
     * leaves them. In <body> it is clipped by nothing and contained by
     * nothing, which is also what fixed the row menus trapped inside the
     * horizontally scrolling table wrapper.
     */
    function portalOut(menu) {
        if (menu._home) { return; }
        menu._home = { parent: menu.parentNode, next: menu.nextSibling };
        document.body.appendChild(menu);
    }

    function portalHome(menu) {
        var home = menu._home;
        if (!home || !home.parent) { return; }
        menu._home = null;
        home.parent.insertBefore(menu, home.next);
    }

    function closeDropdowns(except) {
        $$('.dropdown__menu.is-open').forEach(function (menu) {
            if (menu === except) { return; }
            menu.classList.remove('is-open', 'is-sheet', 'drops-up');
            menu.style.top = menu.style.left = menu.style.minWidth = '';
            portalHome(menu);
        });
        if (!$('.dropdown__menu.is-open.is-sheet')) { document.body.classList.remove('has-sheet'); }
    }

    function placeDropdown(menu, trigger) {
        if (isSheet()) {
            menu.classList.add('is-sheet');
            menu.style.top = menu.style.left = menu.style.minWidth = '';
            document.body.classList.add('has-sheet');
            return;
        }

        menu.classList.remove('is-sheet');
        var rect = trigger.getBoundingClientRect();
        var gap = 4;

        /* Measure while open but not yet placed. */
        var width = Math.max(menu.offsetWidth, 190);
        var height = menu.offsetHeight;

        /* Prefer below; flip above when the room is not there. */
        var below = window.innerHeight - rect.bottom - gap;
        var dropUp = below < height && rect.top > height + gap;
        menu.classList.toggle('drops-up', dropUp);
        menu.style.top = (dropUp ? rect.top - height - gap : rect.bottom + gap) + 'px';

        /* Right-aligned to the trigger, then clamped inside the viewport. */
        var left = rect.right - width;
        left = Math.min(Math.max(8, left), window.innerWidth - width - 8);
        menu.style.left = left + 'px';
    }

    document.addEventListener('click', function (e) {
        /* dropdowns */
        var trigger = e.target.closest('[data-dropdown]');
        if (trigger) {
            e.preventDefault();
            var menu = document.getElementById(trigger.getAttribute('data-dropdown'));
            closeDropdowns(menu);
            if (menu) {
                var opening = !menu.classList.contains('is-open');
                menu.classList.toggle('is-open', opening);
                if (opening) {
                    /* Out of any filtered or scrolling ancestor first, so it
                       is measured and placed in the context it will live in. */
                    portalOut(menu);
                    placeDropdown(menu, trigger);
                } else {
                    menu.classList.remove('is-sheet', 'drops-up');
                    menu.style.top = menu.style.left = '';
                    document.body.classList.remove('has-sheet');
                    portalHome(menu);
                }
            }
            return;
        }
        if (!e.target.closest('.dropdown__menu')) {
            closeDropdowns(null);
        }

        /* modal open / close */
        var opener = e.target.closest('[data-modal-open]');
        if (opener) { e.preventDefault(); WMS.openModal(opener.getAttribute('data-modal-open')); return; }

        var closer = e.target.closest('[data-modal-close]');
        if (closer) {
            e.preventDefault();
            WMS.closeModal(closer.closest('.modal'));
            return;
        }
        if (e.target.classList && e.target.classList.contains('modal')) { WMS.closeModal(e.target); }

        /* dismissible alerts */
        var dismiss = e.target.closest('[data-dismiss]');
        if (dismiss) { dismiss.closest('.alert').remove(); }

        /* copy to clipboard */
        var copy = e.target.closest('[data-copy]');
        if (copy) {
            e.preventDefault();
            var value = copy.getAttribute('data-copy');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(value).then(function () { WMS.toast('Copied: ' + value, 'success', 2000); });
            } else {
                var input = document.createElement('input');
                input.value = value;
                document.body.appendChild(input);
                input.select();
                try { document.execCommand('copy'); WMS.toast('Copied: ' + value, 'success', 2000); } catch (err) { /* ignore */ }
                input.remove();
            }
        }
    });

    /* Escape closes the top-most overlay. */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var open = $('.modal.is-open');
        if (open) { WMS.closeModal(open); return; }
        closeDropdowns(null);
    });

    /* ------------------------------------------------------------- reveal
       Blocks marked [data-reveal] resolve out of a soft blur as they reach
       the viewport.

       Two rules govern this, because the cost of getting it wrong is a page
       nobody can read:

       1. The hiding is switched on from here, never from the stylesheet. If
          this script never runs, nothing is hidden and the page reads as
          plain HTML.
       2. The trigger is a throttled scroll check against the element's own
          rectangle, not an IntersectionObserver. The observer is the tidier
          API, but when it does not deliver - and it can be starved - the
          content it was watching stays invisible for ever. A rectangle
          comparison cannot fail quietly, and a belt-and-braces timer
          reveals anything still waiting a few seconds in. */

    (function () {
        var pending = $$('[data-reveal]');
        if (!pending.length) { return; }
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

        document.documentElement.classList.add('wms-reveal');

        function show(el) { el.classList.add('is-in'); }

        function check() {
            var limit = window.innerHeight * 0.92;
            var batch = 0;
            pending = pending.filter(function (el) {
                if (el.getBoundingClientRect().top >= limit) { return true; }

                /* Neighbours arriving together follow each other in, a beat
                   apart. Timed here rather than with animation-delay: a
                   delayed animation needs a fill-mode to hold the element
                   hidden until it starts, and that fill is what leaves
                   content stranded when the animation never advances. */
                var step = Math.min(batch++, 4) * 70;
                if (step) { setTimeout(function () { show(el); }, step); }
                else { show(el); }
                return false;
            });
            if (!pending.length) { teardown(); }
        }

        var queued = false;
        function onScroll() {
            if (queued) { return; }
            queued = true;
            requestAnimationFrame(function () { queued = false; check(); });
        }

        function teardown() {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        check();

        /* Whatever is left after this has had its chance; show it rather
           than risk hiding content because a scroll never happened. */
        setTimeout(function () {
            $$('[data-reveal]:not(.is-in)').forEach(show);
            pending = [];
            teardown();
        }, 4000);
    })();

    /* A fixed menu is placed from the trigger's rectangle, so anything that
       moves that rectangle - a scroll, a resize - leaves it stranded. The
       bottom sheet is anchored to the viewport, so scrolling leaves it be. */
    window.addEventListener('scroll', function () {
        if (!$('.dropdown__menu.is-open.is-sheet')) { closeDropdowns(null); }
    }, true);
    window.addEventListener('resize', function () { closeDropdowns(null); });

    /* Any form marked data-confirm asks first. */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute('data-confirm') || form.dataset.confirmed === '1') { return; }
        e.preventDefault();
        WMS.confirm({
            title: form.getAttribute('data-confirm-title') || 'Please confirm',
            message: form.getAttribute('data-confirm'),
            confirmText: form.getAttribute('data-confirm-button') || 'Continue',
            tone: form.getAttribute('data-confirm-tone') || 'danger'
        }).then(function (ok) {
            if (ok) { form.dataset.confirmed = '1'; form.submit(); }
        });
    });

    /* Submitting a form puts its primary button into the loading state. */
    document.addEventListener('submit', function (e) {
        var button = e.target.querySelector('[type="submit"]:not([data-no-loading])');
        if (button && !e.defaultPrevented) { setTimeout(function () { WMS.loading(button); }, 0); }
    });

    /* Filter bars submit themselves when a select changes. */
    document.addEventListener('change', function (e) {
        var auto = e.target.closest('[data-auto-submit]');
        if (auto && auto.form) { auto.form.submit(); }
    });

    /* Debounced search box submit. */
    document.addEventListener('input', function (e) {
        var input = e.target.closest('[data-search-submit]');
        if (!input || !input.form) { return; }
        clearTimeout(input._timer);
        input._timer = setTimeout(function () { input.form.submit(); }, 550);
    });

    /* Select-all checkbox in list tables. */
    document.addEventListener('change', function (e) {
        var master = e.target.closest('[data-check-all]');
        if (!master) { return; }
        var scope = document.querySelector(master.getAttribute('data-check-all'));
        if (!scope) { return; }
        $$('input[type="checkbox"][name="ids[]"]', scope).forEach(function (box) { box.checked = master.checked; });
        updateBulkBar();
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('input[type="checkbox"][name="ids[]"]')) { updateBulkBar(); }
    });

    function updateBulkBar() {
        var bar = $('[data-bulk-bar]');
        if (!bar) { return; }
        var count = $$('input[type="checkbox"][name="ids[]"]:checked').length;
        bar.classList.toggle('hidden', count === 0);
        var counter = $('[data-bulk-count]', bar);
        if (counter) { counter.textContent = String(count); }
    }
    WMS.updateBulkBar = updateBulkBar;

    /* Progressive relative timestamps. */
    WMS.refreshTimes = function () {
        $$('[data-since]').forEach(function (el) {
            var seconds = Math.floor((Date.now() - new Date(el.getAttribute('data-since')).getTime()) / 1000);
            el.textContent = WMS.formatDuration(seconds);
        });
    };
    setInterval(WMS.refreshTimes, 30000);

    /* Countdown elements (voucher / package time remaining). */
    WMS.startCountdowns = function () {
        var nodes = $$('[data-countdown]');
        if (!nodes.length) { return; }
        function tick() {
            nodes.forEach(function (el) {
                var left = parseInt(el.getAttribute('data-countdown'), 10) - 1;
                el.setAttribute('data-countdown', String(Math.max(0, left)));
                el.textContent = left <= 0 ? 'Expired' : WMS.formatDuration(left);
                if (left <= 0 && !el.dataset.done) {
                    el.dataset.done = '1';
                    el.classList.add('muted');
                }
            });
        }
        tick();
        setInterval(tick, 1000);
    };

    document.addEventListener('DOMContentLoaded', function () {
        WMS.refreshTimes();
        WMS.startCountdowns();
        $$('[data-chart]').forEach(function (el) {
            try {
                WMS.chart(el, JSON.parse(el.getAttribute('data-chart')));
            } catch (err) {
                el.innerHTML = '<div class="empty"><div class="empty__text">This chart could not be drawn.</div></div>';
            }
        });
    });

    window.WMS = WMS;
})();
