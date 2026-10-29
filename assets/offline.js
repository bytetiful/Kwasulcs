(function () {
  'use strict';

  // The fallback is returned at the requested page URL. Retry that read page
  // only; never restore a document download, signed link, or mutation URL from
  // browser history, storage, or an untrusted query-string destination.
  var base = new URL('../', document.currentScript.src);
  var home = new URL('dashboard', base);
  var current = new URL(location.href);
  var leaf = current.pathname.slice(base.pathname.length).replace(/\.php$/, '');
  var readPage = /^(?:dashboard|courses|course|calendar|assignment_tests)$/;
  var retry = current.origin === base.origin && current.pathname.startsWith(base.pathname)
    && readPage.test(leaf) ? current : home;
  if (retry === current) retry.pathname = base.pathname + leaf;
  document.getElementById('offline-retry').href = retry.href;
  document.getElementById('offline-home').href = home.href;
  // No online event navigation or delayed reload: retry is always user-driven.
}());
