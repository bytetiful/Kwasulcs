(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, {once: true});
        else callback();
    }

    ready(function () {
        var modal = document.getElementById('privacyAcknowledgementModal');
        if (!modal) return;

        var dialog = modal.querySelector('.privacy-ack-dialog');
        var readLink = modal.querySelector('a[href]');
        var submit = document.getElementById('privacyAcknowledgementSubmit');
        var submitLabel = submit.querySelector('span');
        var error = document.getElementById('privacyAcknowledgementError');
        var inerted = [];

        Array.prototype.forEach.call(document.body.children, function (element) {
            if (element === modal || element.tagName === 'SCRIPT') return;
            inerted.push({element: element, inert: element.inert, ariaHidden: element.getAttribute('aria-hidden')});
            element.inert = true;
            element.setAttribute('aria-hidden', 'true');
        });

        document.body.classList.add('privacy-ack-open');
        readLink.focus();

        function focusableElements() {
            return Array.prototype.filter.call(
                modal.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'),
                function (element) { return !element.hidden; }
            );
        }

        function keepFocusInside(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            if (event.key !== 'Tab') return;
            var focusable = focusableElements();
            if (!focusable.length) {
                event.preventDefault();
                dialog.focus();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        function releasePage() {
            document.removeEventListener('keydown', keepFocusInside, true);
            inerted.forEach(function (item) {
                item.element.inert = item.inert;
                if (item.ariaHidden === null) item.element.removeAttribute('aria-hidden');
                else item.element.setAttribute('aria-hidden', item.ariaHidden);
            });
            document.body.classList.remove('privacy-ack-open');
            modal.hidden = true;
            var destination = document.querySelector('main, .page, .app-header a, .app-header button');
            if (destination) {
                var hadTabindex = destination.hasAttribute('tabindex');
                if (!hadTabindex) destination.setAttribute('tabindex', '-1');
                destination.focus({preventScroll: true});
                if (!hadTabindex) destination.addEventListener('blur', function () { destination.removeAttribute('tabindex'); }, {once: true});
            }
        }

        document.addEventListener('keydown', keepFocusInside, true);
        modal.addEventListener('mousedown', function (event) {
            if (event.target === modal) {
                event.preventDefault();
                readLink.focus();
            }
        });

        submit.addEventListener('click', function () {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            submitLabel.textContent = 'Saving…';
            error.hidden = true;

            fetch(modal.dataset.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': modal.dataset.csrf
                },
                body: '{}'
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok || payload.status !== 'ok') throw new Error(payload.message || 'Unable to save your acknowledgement. Please try again.');
                    return payload;
                });
            }).then(releasePage).catch(function (requestError) {
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
                submitLabel.textContent = 'I Have Read and Understand';
                error.textContent = requestError.message || 'Unable to save your acknowledgement. Please try again.';
                error.hidden = false;
                error.focus();
            });
        });
    });
})();
