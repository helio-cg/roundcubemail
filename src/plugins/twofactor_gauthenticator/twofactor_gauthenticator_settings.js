/**
 * Settings page: render the enrollment QR code and keep the code field numeric.
 */
(function () {
    'use strict';

    function renderQr() {
        var el = document.getElementById('twofactor-qr');

        if (!el || typeof QRCode === 'undefined') {
            return;
        }

        var url = el.getAttribute('data-otpauth');

        if (!url) {
            return;
        }

        el.innerHTML = '';

        new QRCode(el, {
            text: url,
            width: 200,
            height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });

        // qrcode.js uses the text as title; remove it to avoid leaking the secret on hover.
        el.removeAttribute('title');

        var image = el.querySelector('img');

        if (image) {
            image.setAttribute('alt', '');
        }
    }

    function bindCodeInputs() {
        document.querySelectorAll('.twofactor-2fa-input').forEach(function (input) {
            input.addEventListener('input', function () {
                var value = input.value.replace(/\D+/g, '').slice(0, 6);

                if (value !== input.value) {
                    input.value = value;
                }
            });
        });
    }

    function init() {
        renderQr();
        bindCodeInputs();
    }

    if (window.rcmail) {
        rcmail.addEventListener('init', init);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
