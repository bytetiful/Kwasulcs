(function () {
  'use strict';

  var config = window.LCSPWA || {};
  var registration = null;
  var deferredInstallPrompt = null;
  var statusRegion = null;
  var connectivityBanner = null;
  var connectivityTimer = 0;
  var connectivityDelayTimer = 0;
  var backOnlineHideTimer = 0;
  var poorConnectionTimer = 0;
  var poorConnectionCandidate = false;
  var poorConnectionActive = false;
  var freshContentReady = false;
  var reloadForWorkerUpdate = false;
  var isSavedSnapshot = !!document.querySelector('meta[name="lcs-pwa-snapshot"][content="1"]');
  var DB_NAME = 'lcs-pwa-resources';
  var SUCCESS_NOTICE_DELAY_MS = 1000;
  var SUCCESS_NOTICE_DURATION_MS = 3000;
  var POOR_CONNECTION_DELAY_MS = 30000;

  function serviceWorkerSupported() {
    return 'serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost');
  }

  function updateIsSensitive() {
    var path = location.pathname;
    return /online_quiz_take\.php$/.test(path)
      || /assignment_test_submission\.php$/.test(path)
      || !!document.querySelector('form[data-uploading="true"], form[data-submitting="true"]');
  }

  function ensureStatusRegion() {
    if (statusRegion) return statusRegion;
    statusRegion = document.createElement('div');
    statusRegion.className = 'pwa-status-region';
    statusRegion.setAttribute('role', 'status');
    statusRegion.setAttribute('aria-live', 'polite');
    statusRegion.setAttribute('aria-atomic', 'true');
    document.body.appendChild(statusRegion);
    return statusRegion;
  }

  function toast(message, kind, actionLabel, action) {
    var region = ensureStatusRegion();
    var item = document.createElement('div');
    item.className = 'pwa-toast' + (kind ? ' is-' + kind : '');
    var text = document.createElement('span');
    text.textContent = message;
    item.appendChild(text);
    if (actionLabel && action) {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = actionLabel;
      button.addEventListener('click', action);
      item.appendChild(button);
    }
    region.replaceChildren(item);
    if (!actionLabel) window.setTimeout(function () { if (item.isConnected) item.remove(); }, 4200);
  }

  function ensureConnectivityBanner() {
    if (connectivityBanner) return connectivityBanner;
    connectivityBanner = document.createElement('div');
    connectivityBanner.className = 'pwa-connectivity-banner';
    connectivityBanner.setAttribute('role', 'status');
    connectivityBanner.setAttribute('aria-live', 'polite');
    connectivityBanner.setAttribute('aria-atomic', 'true');
    connectivityBanner.hidden = true;
    document.body.appendChild(connectivityBanner);
    return connectivityBanner;
  }

  function setConnectivityState(kind, message, actionLabel, action, autoHide) {
    var banner = ensureConnectivityBanner();
    // A quick ping completion after a refresh can report the neutral state.
    // Do not let that race hide/reset the timed "Back online" notice.
    if (!message && banner.classList.contains('is-online') && backOnlineHideTimer) return;
    if (message && kind !== 'online') {
      clearConnectivityDelay();
      if (backOnlineHideTimer) window.clearTimeout(backOnlineHideTimer);
      backOnlineHideTimer = 0;
    }
    if (connectivityTimer) window.clearTimeout(connectivityTimer);
    connectivityTimer = 0;
    banner.className = 'pwa-connectivity-banner' + (kind ? ' is-' + kind : '');
    banner.replaceChildren();
    if (!message) { banner.hidden = true; return; }
    var icon = document.createElement('i');
    icon.className = 'bi ' + (kind === 'offline' ? 'bi-wifi-off' : kind === 'poor' ? 'bi-reception-2' : 'bi-check-circle-fill');
    icon.setAttribute('aria-hidden', 'true');
    var text = document.createElement('span');
    text.textContent = message;
    banner.append(icon, text);
    if (actionLabel && action) {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = actionLabel;
      button.addEventListener('click', action);
      banner.appendChild(button);
    }
    banner.hidden = false;
    if (autoHide) connectivityTimer = window.setTimeout(function () {
      banner.hidden = true;
      connectivityTimer = 0;
    }, autoHide);
  }

  function clearConnectivityDelay() {
    if (connectivityDelayTimer) window.clearTimeout(connectivityDelayTimer);
    connectivityDelayTimer = 0;
  }

  function clearPoorConnectionDelay() {
    if (poorConnectionTimer) window.clearTimeout(poorConnectionTimer);
    poorConnectionTimer = 0;
    poorConnectionCandidate = false;
  }

  function showBackOnlineNotice() {
    clearConnectivityDelay();
    if (backOnlineHideTimer) window.clearTimeout(backOnlineHideTimer);
    backOnlineHideTimer = 0;
    connectivityDelayTimer = window.setTimeout(function () {
      connectivityDelayTimer = 0;
      setConnectivityState('online', 'Back online.', freshContentReady ? 'Refresh' : '',
        freshContentReady ? function () { location.reload(); } : null);
      backOnlineHideTimer = window.setTimeout(function () {
        backOnlineHideTimer = 0;
        var banner = ensureConnectivityBanner();
        if (banner.classList.contains('is-online') && banner.textContent.includes('Back online.')) {
          banner.hidden = true;
        }
      }, SUCCESS_NOTICE_DURATION_MS);
    }, SUCCESS_NOTICE_DELAY_MS);
  }

  function deferPoorConnectionNotice(message) {
    poorConnectionCandidate = true;
    if (poorConnectionTimer) return;
    poorConnectionTimer = window.setTimeout(function () {
      poorConnectionTimer = 0;
      if (!poorConnectionCandidate || !navigator.onLine) return;
      poorConnectionActive = true;
      setConnectivityState('poor', message);
    }, POOR_CONNECTION_DELAY_MS);
  }

  function applySnapshotMode() {
    if (!isSavedSnapshot) return;
    document.documentElement.classList.add('pwa-snapshot-mode');
    document.body.setAttribute('data-pwa-snapshot', 'true');
    // A snapshot is a read-only presentation. This guard catches JS-driven
    // mutations (AI/chat sends, bookmarks, progress writes, edits) in addition
    // to ordinary form submissions. GET refresh/poll requests remain usable.
    var networkFetch = window.fetch.bind(window);
    window.fetch = function (resource, options) {
      var method = String((options && options.method) || (resource && resource.method) || 'GET').toUpperCase();
      if (method !== 'GET' && method !== 'HEAD') {
        setConnectivityState(navigator.onLine ? 'poor' : 'offline', 'Saved page is read-only. Refresh before making changes.');
        return Promise.reject(new Error('Saved pages are read-only.'));
      }
      return networkFetch(resource, options);
    };
    document.querySelectorAll('input[type="file"]').forEach(function (control) {
      control.disabled = true;
      control.setAttribute('aria-disabled', 'true');
    });
    document.querySelectorAll('[data-embed], a[href*="youtube.com"], a[href*="youtu.be"]').forEach(function (videoLink) {
      if (videoLink.querySelector('.pwa-online-required')) return;
      var note = document.createElement('span');
      note.className = 'pwa-online-required';
      note.textContent = 'Internet required';
      videoLink.appendChild(note);
    });
    document.querySelectorAll('form').forEach(function (form) {
      form.setAttribute('data-pwa-readonly', 'true');
      form.querySelectorAll('button[type="submit"], input[type="submit"], input[type="file"]').forEach(function (control) {
        control.disabled = true;
        control.setAttribute('aria-disabled', 'true');
      });
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        setConnectivityState(navigator.onLine ? 'poor' : 'offline', 'Saved page is read-only until a fresh connection is available.');
      });
    });
    document.addEventListener('click', function (event) {
      var link = event.target.closest && event.target.closest('a[href]');
      if (!link) return;
      var href = new URL(link.href, location.href);
      if (/(?:_action|_save|_delete|_mark|_submit|push_subscribe|logout)\.php$/i.test(href.pathname)) {
        event.preventDefault();
        setConnectivityState(navigator.onLine ? 'poor' : 'offline', 'That action needs a fresh connection.');
      }
    }, true);
    setConnectivityState(navigator.onLine ? '' : 'offline', navigator.onLine
      ? 'Saved content is read-only while LCS checks for updates.'
      : 'Offline — showing saved content.');
  }

  function sendToWorker(message) {
    var worker = navigator.serviceWorker.controller || (registration && registration.active);
    if (worker) worker.postMessage(message);
  }

  function installWaitingWorker(worker) {
    if (!worker) return;
    if (updateIsSensitive()) {
      toast('An LCS update is ready. Apply it after you finish this secure activity.', 'offline');
      return;
    }
    toast('An LCS update is ready.', '', 'Update', function () {
      reloadForWorkerUpdate = true;
      worker.postMessage({ type: 'SKIP_WAITING' });
    });
  }

  function setupRegistration(reg) {
    registration = reg;
    if (config.userKey) sendToWorker({ type: 'SET_USER_CONTEXT', userKey: config.userKey });
    if (reg.waiting) installWaitingWorker(reg.waiting);
    reg.addEventListener('updatefound', function () {
      var worker = reg.installing;
      if (!worker) return;
      worker.addEventListener('statechange', function () {
        if (worker.state === 'installed' && navigator.serviceWorker.controller) installWaitingWorker(worker);
      });
    });
  }

  function registerServiceWorker() {
    if (!serviceWorkerSupported()) return Promise.resolve(null);
    var refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (refreshing || !reloadForWorkerUpdate || updateIsSensitive()) return;
      refreshing = true;
      location.reload();
    });
    navigator.serviceWorker.addEventListener('message', handleWorkerMessage);
    return navigator.serviceWorker.register('sw.js', { scope: './' }).then(function (reg) {
      setupRegistration(reg);
      return reg;
    }).catch(function () { return null; });
  }

  function handleWorkerMessage(event) {
    var data = event.data || {};
    if (data.type === 'PAGE_CACHE_AUTH_CHANGED' && isSavedSnapshot) {
      // A server-confirmed session/account change remains authoritative even
      // when a saved page won the short network race.
      try {
        var changedUrl = new URL(data.url, location.href);
        if (changedUrl.origin === location.origin && changedUrl.pathname.replace(/\.php$/, '') === location.pathname.replace(/\.php$/, '')) location.reload();
      } catch (_) { /* ignore unrelated/malformed messages */ }
      return;
    }
    if (data.type === 'PAGE_CACHE_REFRESHED') {
      freshContentReady = true;
      clearPoorConnectionDelay();
      poorConnectionActive = false;
      showBackOnlineNotice();
      return;
    }
  }

  function verifyConnection(announceRestored) {
    if (!navigator.onLine) {
      clearConnectivityDelay();
      clearPoorConnectionDelay();
      poorConnectionActive = false;
      setConnectivityState('offline', isSavedSnapshot ? 'Offline — showing saved content.' : 'Offline — live information is unavailable.');
      return;
    }
    var startedAt = performance.now();
    var controller = new AbortController();
    var timeout = window.setTimeout(function () { controller.abort(); }, 5000);
    fetch('pwa_ping.php?t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin', signal: controller.signal })
      .then(function (response) {
        if (!response.ok) throw new Error('unreachable');
        // A saved HTML snapshot cannot regain its stripped form tokens in
        // place. Once the server responds, offer an explicit refresh so the
        // user can safely return to the live, interactive page.
        if (isSavedSnapshot) freshContentReady = true;
        var elapsed = performance.now() - startedAt;
        if (elapsed > 2000) {
          deferPoorConnectionNotice(isSavedSnapshot ? 'Poor internet connection — showing saved content.' : 'Poor internet connection. Some updates may take longer.');
        } else {
          var recoveredFromPoorConnection = poorConnectionActive || poorConnectionCandidate;
          clearPoorConnectionDelay();
          poorConnectionActive = false;
          if (announceRestored || recoveredFromPoorConnection) showBackOnlineNotice();
          else if (isSavedSnapshot && freshContentReady) showBackOnlineNotice();
          else if (isSavedSnapshot) setConnectivityState('poor', 'Showing saved content while LCS checks for updates.');
          else setConnectivityState('', '');
        }
      }).catch(function () {
        deferPoorConnectionNotice(isSavedSnapshot ? 'Poor internet connection — showing saved content.' : 'LCS is temporarily unreachable.');
      })
      .finally(function () { clearTimeout(timeout); });
  }

  function base64ToBytes(value) {
    var padding = '='.repeat((4 - value.length % 4) % 4);
    var binary = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(binary, function (char) { return char.charCodeAt(0); });
  }

  function subscribePush(button) {
    if (!registration || !('PushManager' in window) || !('Notification' in window) || !config.vapidPublicKey) {
      toast('Browser notifications are not available on this device.', 'offline');
      return;
    }
    button.disabled = true;
    Notification.requestPermission().then(function (permission) {
      if (permission !== 'granted') throw new Error('Notification permission was not granted.');
      return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64ToBytes(config.vapidPublicKey) });
    }).then(function (subscription) {
      return fetch('push_subscribe.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf || '' },
        body: JSON.stringify({ action: 'subscribe', subscription: subscription.toJSON() })
      });
    }).then(function (response) {
      if (!response.ok) throw new Error('LCS could not save this notification subscription.');
      return response.json();
    }).then(function () {
      button.textContent = 'Notifications enabled';
      toast('Browser notifications are enabled on this device.', 'online');
    }).catch(function (error) {
      toast(error.message || 'Unable to enable notifications.', 'offline');
    }).finally(function () { button.disabled = false; });
  }

  function bindSettingsControls() {
    var installButton = document.querySelector('[data-pwa-install]');
    var pushButton = document.querySelector('[data-pwa-push]');
    var support = document.querySelector('[data-pwa-install-support]');
    if (installButton) {
      installButton.hidden = !deferredInstallPrompt;
      if (!installButton.dataset.pwaBound) {
        installButton.dataset.pwaBound = '1';
        installButton.addEventListener('click', function () {
          if (!deferredInstallPrompt) return;
          deferredInstallPrompt.prompt();
          deferredInstallPrompt.userChoice.finally(function () { deferredInstallPrompt = null; installButton.hidden = true; });
        });
      }
    }
    if (support) {
      var installed = matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
      support.textContent = installed ? 'LCS is running as an installed app.'
        : (/iphone|ipad|ipod/i.test(navigator.userAgent) ? 'On iPhone or iPad, use Share then Add to Home Screen.' : 'Install support depends on your browser.');
    }
    if (pushButton) {
      if (!config.vapidPublicKey || !('Notification' in window) || !('PushManager' in window)) {
        pushButton.disabled = true;
        pushButton.textContent = 'Unavailable on this device';
      } else {
        registration && registration.pushManager.getSubscription().then(function (sub) {
          if (sub) pushButton.textContent = 'Notifications enabled';
        });
        if (!pushButton.dataset.pwaBound) {
          pushButton.dataset.pwaBound = '1';
          pushButton.addEventListener('click', function () { subscribePush(pushButton); });
        }
      }
    }
  }

  function updateBadge() {
    var count = Math.max(0, Number(config.unreadCount || 0));
    try {
      if (count && navigator.setAppBadge) navigator.setAppBadge(count).catch(function () {});
      else if (!count && navigator.clearAppBadge) navigator.clearAppBadge().catch(function () {});
    } catch (_) { /* optional API */ }
  }

  function observeUnreadBadge() {
    var header = document.querySelector('.app-header');
    if (!header || !('MutationObserver' in window)) return;
    var sync = function () {
      var badge = header.querySelector('.app-icon-badge');
      config.unreadCount = badge ? Number((badge.textContent || '').replace(/\D/g, '')) || 0 : 0;
      updateBadge();
    };
    new MutationObserver(sync).observe(header, { childList: true, subtree: true, characterData: true });
  }

  function persistSafeState() {
    if (!config.authenticated) return;
    var safe = safePersistedPath(location.pathname + location.search);
    if (!safe) return;
    localStorage.setItem('lcs-pwa-last-safe-path', safe.slice(0, 500));
    if (config.userKey) sendToWorker({ type: 'SET_USER_CONTEXT', userKey: config.userKey });
    if (config.userKey) localStorage.setItem('lcs-pwa-user-key', config.userKey);
  }

  function safePersistedPath(value) {
    try {
      var url = new URL(value, location.origin);
      var basePath = new URL('dashboard', location.href).pathname.replace(/dashboard$/, '');
      if (url.origin !== location.origin || !url.pathname.startsWith(basePath)) return '';
      var leaf = url.pathname.slice(basePath.length).replace(/\.php$/, '');
      var allowed = /^(?:dashboard|courses|course|calendar|assignment_tests)$/;
      if (!allowed.test(leaf)) return '';
      if (url.searchParams.has('ref') || url.searchParams.has('token') || url.searchParams.has('csrf')) return '';
      return basePath + leaf + url.search;
    } catch (_) { return ''; }
  }

  function restoreStandaloneState() {
    if (!config.authenticated || !config.userKey) return false;
    var standalone = matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var launch = new URL(location.href);
    if (!standalone || !/dashboard(?:\.php)?$/.test(launch.pathname) || launch.searchParams.get('source') !== 'pwa') return false;
    if ((localStorage.getItem('lcs-pwa-user-key') || '') !== config.userKey) return false;
    var destination = safePersistedPath(localStorage.getItem('lcs-pwa-last-safe-path') || '');
    if (!destination || destination === launch.pathname + launch.search || /dashboard(?:$|\?)/.test(destination)) return false;
    location.replace(destination);
    return true;
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredInstallPrompt = event;
    bindSettingsControls();
  });
  window.addEventListener('offline', function () { verifyConnection(false); });
  window.addEventListener('online', function () { verifyConnection(true); });

  // Retire metadata from older versions that offered document offline copies.
  if ('indexedDB' in window) indexedDB.deleteDatabase(DB_NAME);
  applySnapshotMode();
  verifyConnection(false);
  registerServiceWorker().then(function () {
    if (restoreStandaloneState()) return;
    persistSafeState();
    bindSettingsControls();
    updateBadge();
    observeUnreadBadge();
  });

  window.LCSPWAApi = {
    clearPrivateData: function () {
      sendToWorker({ type: 'CLEAR_PRIVATE_DATA' });
      localStorage.removeItem('lcs-pwa-user-key');
      localStorage.removeItem('lcs-pwa-last-safe-path');
      if (!('indexedDB' in window)) return Promise.resolve();
      return new Promise(function (resolve) {
        var request = indexedDB.deleteDatabase(DB_NAME);
        request.onsuccess = request.onerror = request.onblocked = resolve;
      });
    }
  };
}());
