/* =====================================================================
   WMS - Captive portal behaviours
   Voucher entry, package selection and payment polling. Kept small: the
   portal often loads over a slow, unpaid connection.
   ===================================================================== */
(function () {
    'use strict';

    var $ = WMS.$, $$ = WMS.$$;

    /* ------------------------------------------------- voucher entry box */

    var voucherInput = $('[data-voucher-input]');
    if (voucherInput) {
        voucherInput.addEventListener('input', function () {
            var caret = this.selectionStart;
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
            this.setSelectionRange(caret, caret);
            this.classList.remove('is-invalid');
        });
        // Paste from an SMS often carries spaces.
        voucherInput.addEventListener('paste', function () {
            var field = this;
            setTimeout(function () { field.value = field.value.toUpperCase().replace(/\s+/g, ''); }, 10);
        });
        setTimeout(function () { voucherInput.focus(); }, 250);
    }

    /* --------------------------------------------------- package picker */

    $$('[data-package-card]').forEach(function (card) {
        card.addEventListener('click', function () {
            $$('[data-package-card]').forEach(function (other) { other.classList.remove('is-selected'); });
            card.classList.add('is-selected');
            var radio = card.querySelector('input[type="radio"]');
            if (radio) { radio.checked = true; }
            var submit = $('[data-package-submit]');
            if (submit) { submit.disabled = false; }
        });
    });

    /* ------------------------------------------------- payment polling */

    var poller = $('[data-payment-poll]');
    if (poller) {
        var paymentId = poller.getAttribute('data-payment-poll');
        var attempts = 0;
        var maxAttempts = 60;          // ~3 minutes at 3s
        var interval = 3000;

        var stop = function () { clearInterval(timer); };

        var check = function () {
            attempts++;
            if (attempts > maxAttempts) {
                stop();
                showResult('warning', 'Still waiting',
                    'We have not had confirmation yet. If you approved the payment on your phone, it may take another minute. You can also check your status page.');
                return;
            }

            WMS.request('api/payments/status.php?id=' + encodeURIComponent(paymentId)).then(function (response) {
                if (!response.success) { return; }
                var status = response.data && response.data.status;

                if (status === 'successful') {
                    stop();
                    window.location.href = WMS.url('customer/status.php?payment=' + encodeURIComponent(paymentId));
                } else if (status === 'failed' || status === 'cancelled') {
                    stop();
                    showResult('error', status === 'cancelled' ? 'Payment cancelled' : 'Payment failed',
                        (response.data && response.data.message) || 'The payment was not completed. You can try again.');
                }
            });
        };

        /* The outcome replaces the spinner in place. Both exits stay on the
           screen - a customer whose payment failed still needs a way home,
           not just a way to spend money again. */
        function showResult(tone, title, message) {
            var box = $('[data-payment-result]');
            if (!box) { return; }
            box.innerHTML =
                '<div class="alert alert--' + (tone === 'error' ? 'danger' : tone) + '">' +
                    '<div class="alert__body"><div class="alert__title">' + WMS.escape(title) + '</div>' +
                    WMS.escape(message) + '</div>' +
                '</div>' +
                '<div class="flex gap-1 mt-2">' +
                    '<a class="btn btn--primary flex-1" href="' + WMS.url('customer/packages.php') + '">Try again</a>' +
                    '<a class="btn flex-1" href="' + WMS.url('customer/index.php') + '">Back to start</a>' +
                '</div>';
            var wait = $('.pay-wait__ring');
            if (wait) { wait.style.display = 'none'; }
        }

        var timer = setInterval(check, interval);
        setTimeout(check, 1500);
    }

    /* ------------------------------------------------------ sticky chrome */

    /* The portal bar earns its hairline only once it is actually lifted off
       the top of the page - a permanent rule under it reads as clutter on a
       screen this small. The classic sentinel trick: watch the bar at its
       resting position and flip a class when it stops being fully visible. */
    var portalTop = $('.portal__top');
    if (portalTop && 'IntersectionObserver' in window) {
        var sentinel = document.createElement('div');
        sentinel.setAttribute('aria-hidden', 'true');
        sentinel.style.cssText = 'position:absolute;top:0;height:1px;width:1px';
        portalTop.parentNode.insertBefore(sentinel, portalTop);

        new IntersectionObserver(function (entries) {
            portalTop.classList.toggle('is-stuck', !entries[0].isIntersecting);
        }).observe(sentinel);
    }

    /* ------------------------------------------------- status countdown */

    var statusPage = $('[data-status-refresh]');
    if (statusPage) {
        setInterval(function () { window.location.reload(); }, 60000);
    }
})();
