/**
 * ==============================================================
 * app.js — global JS helpers shared across the whole system
 * (toasts, modal open/close, sidebar toggle, generic AJAX POST)
 * ==============================================================
 */

/* ---------------- TOASTS ---------------- */
function showToast(type, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info' };
    const icon = icons[type] || icons.info;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fa-solid ${icon}"></i><span class="toast-msg">${message}</span><span class="toast-close">&times;</span>`;
    container.appendChild(toast);

    // Leave with a short fade-out (is-leaving, style.css) instead of vanishing.
    const dismiss = () => {
        if (toast.classList.contains('is-leaving')) return;
        toast.classList.add('is-leaving');
        setTimeout(() => toast.remove(), 200);
    };
    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    setTimeout(dismiss, 5000);
}

/* ---------------- SIDEBAR TOGGLE (mobile) ---------------- */
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', () => sidebar.classList.toggle('open'));
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 900 && sidebar.classList.contains('open')
                && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Zooming or resizing across a breakpoint: no slide/margin transitions while the layout switches (they left the
    // sidebar and panel half-way), and a menu opened at phone width does not stay "open" once the desktop layout returns.
    let resizeTimer;
    const root = document.documentElement;
    window.addEventListener('resize', () => {
        root.classList.add('is-resizing');
        if (sidebar && window.innerWidth > 900) sidebar.classList.remove('open');
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => root.classList.remove('is-resizing'), 200);
    });
});

/* ---------------- MODAL HELPERS ---------------- */
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
// Close modal when clicking outside the box. Only when the press also started on the backdrop: selecting text in a
// field and releasing outside the box fires a click on the backdrop, which used to close the form and lose the input.
let pressStart = null;
document.addEventListener('mousedown', (e) => { pressStart = e.target; });
document.addEventListener('click', (e) => {
    if (e.target.classList && e.target.classList.contains('modal-backdrop') && pressStart === e.target) {
        e.target.classList.remove('show');
    }
});
// Escape closes the top open modal (an open dropdown handles its own Escape first and marks it handled).
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || e.defaultPrevented) return;
    const open = [...document.querySelectorAll('.modal-backdrop.show')].pop();
    if (open) { e.preventDefault(); open.classList.remove('show'); }
});

/* ---------------- GENERIC AJAX (fetch wrapper) ---------------- */
// CSRF token from <meta name="csrf-token"> (see includes/header.php); sent with every POST.
const CSRF_TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

async function ajaxPost(url, data) {
    const formData = new FormData();
    for (const key in data) formData.append(key, data[key]);
    formData.append('csrf', CSRF_TOKEN);
    const res = await fetch(url, { method: 'POST', body: formData, headers: { 'X-CSRF-Token': CSRF_TOKEN } });
    let json;
    try {
        json = await res.json();
    } catch (err) {
        throw new Error('Invalid server response');
    }
    return json;
}

async function ajaxGet(url) {
    const res = await fetch(url);
    return await res.json();
}

/* ---------------- DEBOUNCE (for live search boxes) ---------------- */
function debounce(fn, delay = 350) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

/* ---------------- CONFIRM DELETE HELPER ---------------- */
function confirmDelete(message = 'Are you sure you want to delete this record? This cannot be undone.') {
    return confirm(message);
}

/* Delete a disabled class / teacher / subject / laboratory (includes/admin_delete.php). Two steps against the same
 * endpoint: action=delete_preview says why it can't be deleted yet, or what would go with it (shown in the confirm);
 * action=delete then does it. idField is the POST key of the item's id, e.g. 'lab_id'. */
async function deleteDisabled(url, idField, id) {
    try {
        const preview = await ajaxPost(url, { action: 'delete_preview', [idField]: id });
        if (!preview.success) { showToast('error', preview.message); return; }
        if (!confirm(preview.confirm)) return;
        const res = await ajaxPost(url, { action: 'delete', [idField]: id });
        if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); }
        else showToast('error', res.message);
    } catch (err) {
        showToast('error', 'Something went wrong. Please try again.');
    }
}

/* ==============================================================
 * GENERIC MULTI-CDN SCRIPT LOADER — robust against a blocked or
 * unreachable CDN (common on restrictive school/campus networks).
 * Tries each URL in order; resolves as soon as one works, rejects
 * only if ALL of them fail.
 * ============================================================== */
const _scriptLoadPromises = {};

function _loadScript(src, timeoutMs = 8000) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        const timer = setTimeout(() => reject(new Error('timeout')), timeoutMs);
        script.onload = () => { clearTimeout(timer); resolve(); };
        script.onerror = () => { clearTimeout(timer); reject(new Error('failed to load')); };
        document.head.appendChild(script);
    });
}

/**
 * Loads a library from the first working URL in `sources`, checking
 * `checkGlobal` (a function returning true once the library is ready)
 * after each attempt. Cached by `cacheKey` so repeated calls don't
 * re-trigger network requests. Rejects with a clear message only if
 * every source fails.
 */
function ensureLibrary(cacheKey, sources, checkGlobal) {
    if (checkGlobal()) return Promise.resolve();
    if (_scriptLoadPromises[cacheKey]) return _scriptLoadPromises[cacheKey];

    _scriptLoadPromises[cacheKey] = (async () => {
        for (const src of sources) {
            try {
                await _loadScript(src);
                if (checkGlobal()) return;
            } catch (err) { /* try next source */ }
        }
        throw new Error(`Could not load a required library (${cacheKey}). Check your internet connection or firewall settings — it needs access to at least one of: ${sources.map(s => new URL(s).hostname).join(', ')}.`);
    })();
    return _scriptLoadPromises[cacheKey];
}

/* ---------------- QR CODE GENERATOR (qrcodejs) ---------------- */
const QR_LIBRARY_SOURCES = [
    'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
    'https://cdn.jsdelivr.net/npm/davidshimjs-qrcodejs@0.0.2/qrcode.min.js',
    'https://unpkg.com/davidshimjs-qrcodejs@0.0.2/qrcode.min.js',
];
function ensureQrLibrary() {
    return ensureLibrary('qrcodejs', QR_LIBRARY_SOURCES, () => !!window.QRCode);
}

/**
 * Safely render a QR code into a container element, handling the
 * "library never loaded" case with a visible, actionable message
 * instead of a blank box.
 */
async function safeRenderQr(containerEl, text, size = 200) {
    if (!containerEl) return;
    try {
        await ensureQrLibrary();
        containerEl.innerHTML = '';
        new QRCode(containerEl, { text, width: size, height: size });
    } catch (err) {
        containerEl.innerHTML = `
            <div style="max-width:260px;padding:16px;background:#fee2e2;border-radius:8px;color:#991b1b;font-size:12.5px;text-align:left">
                <strong><i class="fa-solid fa-triangle-exclamation"></i> QR code could not load</strong>
                <p style="margin:8px 0 0">${err.message}</p>
            </div>`;
    }
}

/* ---------------- QR CODE SCANNER (html5-qrcode) ---------------- */
const HTML5_QRCODE_SOURCES = [
    'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
    'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js',
];
function ensureHtml5QrcodeLibrary() {
    return ensureLibrary('html5-qrcode', HTML5_QRCODE_SOURCES, () => typeof window.Html5Qrcode !== 'undefined');
}

/* ---------------- LIVE FILTERS ----------------
 * A <form method="GET" data-live-filter> reloads its results as the admin types or changes a filter: it fetches the same
 * page with the new query string and swaps in the header count and everything after the form (table + pagination).
 * No Filter button. If the response is not the page (session expired, error) it falls back to a normal navigation. */
function initLiveFilters() {
    document.querySelectorAll('form[data-live-filter]').forEach((form) => {
        if (form._live) return;
        form._live = true;
        const card = form.closest('.card');
        const body = form.parentElement;
        const reset = form.querySelector('[data-live-reset]');
        let ctrl = null;
        let seq = 0;

        const isText = (t) => t.tagName === 'INPUT' && (t.type === 'text' || t.type === 'search');
        const hasFilter = () => [...form.elements].some((el) => {
            if (!el.name || el.type === 'hidden') return false;
            return el.tagName === 'SELECT' ? el.selectedIndex > 0 : el.value !== '';
        });

        const run = async () => {
            const params = new URLSearchParams(new FormData(form));
            [...params.keys()].forEach((k) => { if (params.get(k) === '') params.delete(k); });
            const qs = params.toString();
            const url = location.pathname + (qs ? '?' + qs : '');
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            const mine = ++seq;
            card.classList.add('is-loading');
            try {
                const res = await fetch(url, { signal: ctrl.signal, credentials: 'same-origin' });
                const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                const nForm = doc.querySelector('form[data-live-filter]');
                if (!res.ok || !nForm) { location.href = url; return; }
                if (mine !== seq) return;
                const h3 = card.querySelector('.card-header h3');
                const nH3 = nForm.closest('.card').querySelector('.card-header h3');
                if (h3 && nH3) h3.textContent = nH3.textContent;
                while (form.nextSibling) form.nextSibling.remove();
                for (let n = nForm.nextSibling; n; n = n.nextSibling) body.appendChild(document.importNode(n, true));
                body.querySelectorAll('.table-wrapper').forEach((w) => w.classList.add('live-fade'));
                // Links that depend on the filters (export buttons) are taken from the new page.
                const fresh = nForm.querySelectorAll('a[data-live-sync]');
                form.querySelectorAll('a[data-live-sync]').forEach((a, i) => { if (fresh[i]) a.href = fresh[i].getAttribute('href'); });
                history.replaceState(null, '', url);
                if (reset) reset.hidden = !hasFilter();
            } catch (err) {
                if (err.name !== 'AbortError') location.href = url;
            } finally {
                if (mine === seq) card.classList.remove('is-loading');
            }
        };

        const later = debounce(run, 280);
        form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
        form.addEventListener('input', (e) => {
            if (reset) reset.hidden = !hasFilter();
            if (isText(e.target)) later();
        });
        form.addEventListener('change', (e) => {
            if (reset) reset.hidden = !hasFilter();
            if (!isText(e.target)) run();
        });
    });
}
document.addEventListener('DOMContentLoaded', initLiveFilters);

/* ---------------- ANIMATED DROPDOWNS ----------------
 * Every <select class="form-control"> is replaced by a button + animated listbox. The real <select> stays in the DOM
 * (hidden), keeps its value/name/id, and still gets 'change' events, so forms, inline onchange and JS that sets .value
 * or fills the options keep working. Opt out with class "no-enhance" or the data-native attribute. */
(function () {
    let current = null;

    function esc(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function closeCurrent(focusBtn) {
        if (!current) return;
        const c = current;
        current = null;
        c.menu.classList.remove('is-open');
        c.btn.setAttribute('aria-expanded', 'false');
        c.wrap.classList.remove('is-open');
        setTimeout(() => { if (!c.menu.classList.contains('is-open')) c.menu.remove(); }, 200);
        if (focusBtn) c.btn.focus();
    }

    function enhance(sel) {
        if (sel._dd || sel.multiple || sel.size > 1 || sel.classList.contains('no-enhance') || sel.hasAttribute('data-native')) return;
        sel._dd = true;

        const wrap = document.createElement('div');
        wrap.className = 'dd';
        ['maxWidth', 'minWidth', 'width', 'flex'].forEach((p) => { if (sel.style[p]) wrap.style[p] = sel.style[p]; });
        sel.parentNode.insertBefore(wrap, sel);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'form-control dd-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        const lbl = sel.getAttribute('aria-label');
        if (lbl) btn.setAttribute('aria-label', lbl);
        btn.innerHTML = '<span class="dd-label"></span><i class="fa-solid fa-chevron-down dd-caret" aria-hidden="true"></i>';
        wrap.appendChild(btn);
        wrap.appendChild(sel);
        sel.classList.add('dd-native');
        sel.tabIndex = -1;
        sel.setAttribute('aria-hidden', 'true');
        const labelEl = btn.querySelector('.dd-label');

        function refresh() {
            const o = sel.options[sel.selectedIndex];
            labelEl.textContent = o ? o.textContent.trim() : '';
            btn.disabled = sel.disabled;
            wrap.classList.toggle('is-disabled', sel.disabled);
            wrap.classList.toggle('is-placeholder', !!o && o.value === '' && sel.options.length > 1);
        }

        // Programmatic changes (sel.value = x) fire no event: mirror them into the button.
        ['value', 'selectedIndex'].forEach((p) => {
            const d = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, p);
            Object.defineProperty(sel, p, {
                configurable: true,
                get() { return d.get.call(sel); },
                set(v) { d.set.call(sel, v); refresh(); },
            });
        });
        new MutationObserver(refresh).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected'] });
        if (sel.form) sel.form.addEventListener('reset', () => setTimeout(refresh, 0));
        sel.addEventListener('focus', () => btn.focus());
        sel.addEventListener('change', refresh);
        refresh();

        function open() {
            if (current && current.btn === btn) return;
            closeCurrent(false);
            if (btn.disabled) return;
            refresh();
            const menu = document.createElement('div');
            menu.className = 'dd-menu';
            let list = '';
            const item = (o) => {
                const selected = o.index === sel.selectedIndex;
                return '<li role="option" tabindex="-1" class="dd-item' + (selected ? ' is-selected' : '') + (o.disabled ? ' is-disabled' : '')
                    + '" data-index="' + o.index + '" aria-selected="' + selected + '"><span>'
                    + esc(o.textContent.trim()) + '</span><i class="fa-solid fa-check dd-check" aria-hidden="true"></i></li>';
            };
            [...sel.children].forEach((ch) => {
                if (ch.tagName === 'OPTGROUP') {
                    list += '<li class="dd-group" role="presentation">' + esc(ch.label) + '</li>';
                    [...ch.children].forEach((o) => { list += item(o); });
                } else if (ch.tagName === 'OPTION') {
                    list += item(ch);
                }
            });
            menu.innerHTML = '<ul class="dd-list" role="listbox">' + list + '</ul>';
            document.body.appendChild(menu);

            const r = btn.getBoundingClientRect();
            const w = Math.max(r.width, 180);
            menu.style.minWidth = w + 'px';
            menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
            const below = window.innerHeight - r.bottom - 12;
            const up = below < 220 && r.top > below;
            const space = (up ? r.top : below) - 8;
            menu.querySelector('.dd-list').style.maxHeight = Math.max(120, Math.min(280, space)) + 'px';
            const mh = menu.offsetHeight;
            menu.style.top = (up ? Math.max(8, r.top - mh - 6) : r.bottom + 6) + 'px';
            menu.classList.toggle('is-up', up);
            current = { wrap, btn, menu };
            requestAnimationFrame(() => menu.classList.add('is-open'));
            btn.setAttribute('aria-expanded', 'true');
            wrap.classList.add('is-open');

            const items = () => [...menu.querySelectorAll('.dd-item:not(.is-disabled)')];
            const selItem = menu.querySelector('.dd-item.is-selected');
            setTimeout(() => {
                const f = selItem || items()[0];
                if (f) f.focus({ preventScroll: true });
                if (selItem) selItem.scrollIntoView({ block: 'nearest' });
            }, 30);

            const choose = (li) => {
                const idx = +li.dataset.index;
                if (idx !== sel.selectedIndex) {
                    sel.selectedIndex = idx;
                    sel.dispatchEvent(new Event('input', { bubbles: true }));
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
                closeCurrent(true);
            };
            menu.addEventListener('click', (e) => {
                const li = e.target.closest('.dd-item');
                if (li && !li.classList.contains('is-disabled')) choose(li);
            });
            menu.addEventListener('keydown', (e) => {
                const els = items();
                const at = els.indexOf(document.activeElement);
                if (e.key === 'Escape') { e.preventDefault(); closeCurrent(true); }
                else if (e.key === 'Tab') { closeCurrent(false); }
                else if (e.key === 'ArrowDown') { e.preventDefault(); const t = els[at + 1] || els[0]; if (t) t.focus(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); const t = at > 0 ? els[at - 1] : els[els.length - 1]; if (t) t.focus(); }
                else if (e.key === 'Home') { e.preventDefault(); if (els[0]) els[0].focus(); }
                else if (e.key === 'End') { e.preventDefault(); if (els.length) els[els.length - 1].focus(); }
                else if ((e.key === 'Enter' || e.key === ' ') && document.activeElement.classList.contains('dd-item')) { e.preventDefault(); choose(document.activeElement); }
                else if (e.key.length === 1 && /\S/.test(e.key)) {
                    const k = e.key.toLowerCase();
                    const hit = els.slice(at + 1).concat(els.slice(0, at + 1)).find((li) => li.textContent.trim().toLowerCase().startsWith(k));
                    if (hit) hit.focus();
                }
            });
        }

        btn.addEventListener('click', () => { if (current && current.btn === btn) closeCurrent(false); else open(); });
        btn.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); }
        });
    }

    document.addEventListener('mousedown', (e) => {
        if (current && !current.menu.contains(e.target) && !current.wrap.contains(e.target)) closeCurrent(false);
    });
    window.addEventListener('resize', () => closeCurrent(false));
    window.addEventListener('scroll', (e) => { if (current && !current.menu.contains(e.target)) closeCurrent(false); }, true);

    function scan(root) {
        root.querySelectorAll('select.form-control').forEach(enhance);
    }
    function start() {
        scan(document);
        new MutationObserver((muts) => {
            for (const m of muts) {
                for (const n of m.addedNodes) {
                    if (n.nodeType !== 1) continue;
                    if (n.matches('select.form-control')) enhance(n);
                    else scan(n);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();


/* ---------------- MOTION ----------------
 * Small cues for everything that changes on a page (styles: "MOTION" at the end of style.css). Modals and toasts are
 * handled in CSS/showToast; this adds: rows appended to a table, content that becomes visible (hidden / display / class
 * toggles), numbers and badges whose text changes, and a count-up for dashboard numbers on load. Everything is
 * additive and skipped when the visitor prefers reduced motion (the CSS then only fades). */
(function () {
    const reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    const once = (el, cls) => {
        el.classList.remove(cls);
        void el.offsetWidth;                       // restart the animation if it is already running
        el.classList.add(cls);
        // animationend never fires when the animation is cancelled (element hidden by a breakpoint after a zoom/resize),
        // which left the class, and its display/transform, stuck on. Clean up on cancel and on a timer too.
        const clear = () => {
            el.classList.remove(cls);
            el.removeEventListener('animationend', clear);
            el.removeEventListener('animationcancel', clear);
            clearTimeout(timer);
        };
        const timer = setTimeout(clear, 1500);
        el.addEventListener('animationend', clear);
        el.addEventListener('animationcancel', clear);
    };
    window.animateIn = (el) => { if (el) once(el, 'swap-in'); };

    // Count a dashboard number up from 0. Only plain numbers ("1,204", "85%", "7"); anything else is left alone.
    const NUM = /^([^\d-]*)(-?\d[\d,]*\.?\d*)([^\d]*)$/;
    function countUp(el) {
        if (reduce.matches) return;
        const m = NUM.exec(el.textContent.trim());
        if (!m) return;
        const target = parseFloat(m[2].replace(/,/g, ''));
        if (!isFinite(target) || target === 0 || Math.abs(target) > 1e6) return;
        const decimals = (m[2].split('.')[1] || '').length;
        const comma = m[2].indexOf(',') !== -1;
        const fmt = (v) => {
            let t = v.toFixed(decimals);
            if (comma) t = Number(t).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
            return m[1] + t + m[3];
        };
        const final = el.textContent;
        const t0 = performance.now();
        const dur = 700;
        el._counting = true;
        (function tick(now) {
            const k = Math.min(1, (now - t0) / dur);
            const eased = 1 - Math.pow(1 - k, 4);
            el.textContent = k < 1 ? fmt(target * eased) : final;
            if (k < 1) requestAnimationFrame(tick); else setTimeout(() => { el._counting = false; }, 0);  // after the observer has seen the last write
        })(t0);
    }

    function start() {
        document.querySelectorAll('.stat-value').forEach(countUp);
        const root = document.querySelector('.page-content');
        if (!root) return;

        const seen = (n) => n.nodeType === 1;
        new MutationObserver((muts) => {
            let rows = 0;
            for (const m of muts) {
                if (m.type === 'childList') {
                    // A row added to a table body (a record appended by script) drops in with a highlight.
                    if (m.target.tagName === 'TBODY') {
                        m.addedNodes.forEach((n) => {
                            if (seen(n) && n.tagName === 'TR' && rows < 12) { n.style.animationDelay = (rows++ * 35) + 'ms'; once(n, 'row-in'); }
                        });
                    } else if (m.target.closest && m.target.closest('.stat-value, .badge')) {
                        const el = m.target.closest('.stat-value, .badge');
                        if (!el._counting) once(el, 'value-changed');
                    }
                } else if (m.type === 'characterData') {
                    const el = m.target.parentElement && m.target.parentElement.closest('.stat-value, .badge');
                    if (el && !el._counting) once(el, 'value-changed');
                } else if (m.type === 'attributes') {
                    const t = m.target;
                    if (!seen(t) || t.closest('.dd-menu, .modal-backdrop, .toast, .sidebar')) continue;
                    const was = m.oldValue || '';
                    let shown = false;
                    if (m.attributeName === 'hidden') shown = !t.hidden && m.oldValue !== null;
                    else if (m.attributeName === 'style') shown = /display:\s*none/.test(was) && t.style.display !== 'none';
                    else if (m.attributeName === 'class') shown = /(^|\s)(hidden|d-none)(\s|$)/.test(was) && !t.classList.contains('hidden') && !t.classList.contains('d-none');
                    if (shown && t.offsetParent !== null) once(t, 'reveal-in');
                }
            }
        }).observe(root, {
            subtree: true, childList: true, characterData: true,
            attributes: true, attributeOldValue: true, attributeFilter: ['hidden', 'style', 'class'],
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
