/**
 * ==============================================================
 * theme.js — Light / Dark / System colour theme
 * Loaded in <head> of every page (includes/theme.php) so the saved choice is applied before the
 * first paint. The choice ("light" | "dark" | "system", default "system") is kept in localStorage,
 * per browser. <html data-theme="light|dark"> is the RESOLVED theme that style.css reacts to.
 * ==============================================================
 */
(function () {
    var KEY = 'theme';
    var root = document.documentElement;
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function getPref() {
        try {
            var v = localStorage.getItem(KEY);
            if (v === 'light' || v === 'dark' || v === 'system') return v;
        } catch (e) { /* storage blocked: fall through to the default */ }
        return 'system';
    }
    function resolve(pref) {
        if (pref === 'system') return mq && mq.matches ? 'dark' : 'light';
        return pref;
    }
    function apply() {
        var pref = getPref(), theme = resolve(pref);
        var changed = root.getAttribute('data-theme') !== theme;
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-theme-pref', pref);
        syncSwitchers(pref);
        if (changed) window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme, pref: pref } }));
    }
    function setPref(pref) {
        try { localStorage.setItem(KEY, pref); } catch (e) { /* not saved, still applied for this page */ }
        apply();
    }

    // ---- the switcher menu (includes/theme.php prints the markup) ----
    var ICONS = { light: 'fa-sun', dark: 'fa-moon', system: 'fa-circle-half-stroke' };

    function syncSwitchers(pref) {
        var switchers = document.querySelectorAll('.theme-switch');
        for (var i = 0; i < switchers.length; i++) {
            var sw = switchers[i];
            var icon = sw.querySelector('.theme-btn i');
            if (icon) icon.className = 'fa-solid ' + ICONS[pref];
            var items = sw.querySelectorAll('[data-theme-choice]');
            for (var j = 0; j < items.length; j++) {
                var on = items[j].getAttribute('data-theme-choice') === pref;
                items[j].setAttribute('aria-checked', on ? 'true' : 'false');
            }
        }
    }
    function closeAll(except) {
        var open = document.querySelectorAll('.theme-switch.open');
        for (var i = 0; i < open.length; i++) {
            if (open[i] === except) continue;
            open[i].classList.remove('open');
            open[i].querySelector('.theme-btn').setAttribute('aria-expanded', 'false');
        }
    }
    function wire() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.theme-btn') : null;
            if (btn) {
                var sw = btn.parentNode;
                closeAll(sw);
                var open = sw.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                return;
            }
            var item = e.target.closest ? e.target.closest('[data-theme-choice]') : null;
            if (item) {
                setPref(item.getAttribute('data-theme-choice'));
                closeAll(null);
                return;
            }
            closeAll(null);
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });
        syncSwitchers(getPref());
    }

    // "System" follows the device live (e.g. automatic dark mode at night).
    if (mq) {
        var onSystemChange = function () { if (getPref() === 'system') apply(); };
        if (mq.addEventListener) mq.addEventListener('change', onSystemChange);
        else if (mq.addListener) mq.addListener(onSystemChange);
    }
    // Another tab changed the choice.
    window.addEventListener('storage', function (e) { if (e.key === KEY) apply(); });

    apply();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire);
    else wire();

    window.setThemePreference = setPref;

    /**
     * Colours for Chart.js, read from the CSS variables of the active theme. Also sets the Chart.js defaults
     * (text and grid colours) so a chart built right after this call matches the page.
     */
    window.themeChartDefaults = function () {
        var cs = getComputedStyle(root);
        function v(name) { return cs.getPropertyValue(name).trim(); }
        var c = {
            green: v('--chart-green'), greenFill: v('--chart-green-fill'),
            amber: v('--chart-amber'), amberFill: v('--chart-amber-fill'),
            red: v('--chart-red'), redFill: v('--chart-red-fill'),
            accent: v('--chart-accent'), surface: v('--surface'),
            text: v('--chart-text'), grid: v('--chart-grid')
        };
        if (window.Chart) {
            Chart.defaults.color = c.text;
            Chart.defaults.borderColor = c.grid;
        }
        return c;
    };
})();
