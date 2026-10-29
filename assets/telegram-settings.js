(function () {
    'use strict';

    var panel = document.getElementById('telegram-notifications');
    if (!panel) return;

    var waitingKey = 'lcsTelegramConnectionWaiting';
    var waiting = panel.querySelector('[data-telegram-waiting]');
    var live = panel.querySelector('[data-telegram-live]');
    var connectButtons = Array.from(panel.querySelectorAll('[data-telegram-connect]'));
    var checkButtons = Array.from(panel.querySelectorAll('[data-telegram-check]'));
    var initialState = panel.dataset.telegramState || 'not_connected';
    var pollTimer = null;
    var pollCount = 0;

    function announce(message, isError) {
        if (!live) return;
        live.className = 'telegram-live-region alert ' + (isError ? 'error' : 'success');
        live.textContent = message;
    }

    function setWaiting(active) {
        if (!waiting) return;
        waiting.hidden = !active;
        if (active) sessionStorage.setItem(waitingKey, '1');
        else sessionStorage.removeItem(waitingKey);
    }

    function stopPolling() {
        if (pollTimer) window.clearInterval(pollTimer);
        pollTimer = null;
    }

    async function checkConnection(manual) {
        try {
            var response = await fetch(panel.dataset.statusEndpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });
            var payload = await response.json();
            if (!response.ok || payload.status !== 'ok' || !payload.connection) throw new Error('status');
            var nextState = payload.connection.state;
            if (nextState === 'connected' || nextState === 'disabled' || nextState === 'blocked') {
                stopPolling();
                setWaiting(false);
                window.location.reload();
                return;
            }
            if (manual) announce('Telegram is not connected yet. Complete the link in Telegram, then check again.', false);
        } catch (error) {
            if (manual) announce('We could not check the Telegram connection. Please try again.', true);
        }
    }

    function startPolling() {
        if (pollTimer) return;
        pollCount = 0;
        pollTimer = window.setInterval(function () {
            pollCount += 1;
            checkConnection(false);
            if (pollCount >= 30) stopPolling();
        }, 10000);
    }

    connectButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled) return;
            var popup = window.open('about:blank', 'kwasu-lcs-telegram-connect');
            if (popup) popup.opener = null;
            connectButtons.forEach(function (item) { item.disabled = true; });
            button.setAttribute('aria-busy', 'true');
            announce('Preparing a secure Telegram connection…', false);
            try {
                var response = await fetch(panel.dataset.connectEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-Token': panel.dataset.csrf || ''
                    },
                    cache: 'no-store'
                });
                var payload = await response.json();
                if (!response.ok || payload.status !== 'ok' || typeof payload.url !== 'string') {
                    throw new Error(payload.message || 'Unable to start Telegram connection.');
                }
                setWaiting(true);
                announce('Telegram opened. Complete the connection there, then return to this page.', false);
                startPolling();
                if (popup) popup.location.replace(payload.url);
                else window.location.assign(payload.url);
            } catch (error) {
                if (popup) popup.close();
                announce(error && error.message ? error.message : 'Unable to start Telegram connection. Please try again.', true);
            } finally {
                connectButtons.forEach(function (item) { item.disabled = false; });
                button.removeAttribute('aria-busy');
            }
        });
    });

    checkButtons.forEach(function (button) {
        button.addEventListener('click', function () { checkConnection(true); });
    });

    if (sessionStorage.getItem(waitingKey) === '1'
        && (initialState === 'not_connected' || initialState === 'disconnected' || initialState === 'blocked')) {
        setWaiting(true);
        startPolling();
    } else if (initialState === 'connected' || initialState === 'disabled') {
        setWaiting(false);
    }

    var dialog = document.getElementById('telegramDisconnectDialog');
    var dialogTrigger = null;
    panel.querySelectorAll('[data-telegram-disconnect-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!dialog) return;
            dialogTrigger = button;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
            var close = dialog.querySelector('[data-telegram-disconnect-close]');
            if (close) close.focus();
        });
    });
    if (dialog) {
        dialog.querySelectorAll('[data-telegram-disconnect-close]').forEach(function (button) {
            button.addEventListener('click', function () { dialog.close(); });
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', function () {
            if (dialogTrigger) dialogTrigger.focus();
        });
    }
})();
