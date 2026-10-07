/**
 * assets/js/pwa.js - installable app (see includes/pwa.php).
 * Registers the service worker (sw.php) and shows the install card (#installCard) when the browser
 * can install the app, or on iPhone/iPad where Safari needs Share > Add to Home Screen.
 * "Not now" is remembered per browser in localStorage; nothing about this reaches the server.
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var base = (script && script.dataset.base) || '/';
    var DISMISS_KEY = 'pwa-install-dismissed';
    var deferredPrompt = null;

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(base + 'sw.php', { scope: base }).catch(function () {});
        });
    }

    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    function dismissed() {
        try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
    }
    function card() { return document.getElementById('installCard'); }
    function show(mode) {
        var c = card();
        if (!c || standalone || dismissed()) return;
        c.setAttribute('data-mode', mode);
        c.hidden = false;
    }
    function hide() {
        var c = card();
        if (c) c.hidden = true;
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();          // keep the browser's mini-bar away; the card asks instead
        deferredPrompt = e;
        show('prompt');
    });
    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        hide();
    });

    document.addEventListener('DOMContentLoaded', function () {
        var c = card();
        if (!c) return;
        if (isIos) show('ios');
        var installBtn = c.querySelector('[data-install]');
        var dismissBtn = c.querySelector('[data-dismiss]');
        if (installBtn) installBtn.addEventListener('click', function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                deferredPrompt = null;
                if (choice.outcome === 'accepted') hide();
            });
        });
        if (dismissBtn) dismissBtn.addEventListener('click', function () {
            hide();
            try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) { /* private mode: just hide for now */ }
        });
    });
})();
