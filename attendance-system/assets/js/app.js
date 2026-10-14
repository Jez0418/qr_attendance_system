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

    toast.querySelector('.toast-close').addEventListener('click', () => toast.remove());
    setTimeout(() => toast.remove(), 5000);
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
// Close modal when clicking outside the box
document.addEventListener('click', (e) => {
    if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
        e.target.classList.remove('show');
    }
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
            let n = 0;
            const item = (o) => {
                const i = n++;
                const selected = o.index === sel.selectedIndex;
                return '<li role="option" tabindex="-1" class="dd-item' + (selected ? ' is-selected' : '') + (o.disabled ? ' is-disabled' : '')
                    + '" data-index="' + o.index + '" aria-selected="' + selected + '" style="--i:' + Math.min(i, 9) + '"><span>'
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
            const searchable = sel.options.length > 10;
            menu.innerHTML = (searchable ? '<div class="dd-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="text" placeholder="Search..." aria-label="Search options" autocomplete="off"></div>' : '')
                + '<ul class="dd-list" role="listbox">' + list + '</ul><div class="dd-empty" hidden>No matches</div>';
            document.body.appendChild(menu);

            const r = btn.getBoundingClientRect();
            const w = Math.max(r.width, 180);
            menu.style.minWidth = w + 'px';
            menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
            const below = window.innerHeight - r.bottom - 12;
            const up = below < 220 && r.top > below;
            const space = (up ? r.top : below) - 8;
            menu.querySelector('.dd-list').style.maxHeight = Math.max(120, Math.min(280, space - (searchable ? 52 : 0))) + 'px';
            const mh = menu.offsetHeight;
            menu.style.top = (up ? Math.max(8, r.top - mh - 6) : r.bottom + 6) + 'px';
            menu.classList.toggle('is-up', up);
            current = { wrap, btn, menu };
            requestAnimationFrame(() => menu.classList.add('is-open'));
            btn.setAttribute('aria-expanded', 'true');
            wrap.classList.add('is-open');

            const items = () => [...menu.querySelectorAll('.dd-item:not(.is-disabled):not([hidden])')];
            const selItem = menu.querySelector('.dd-item.is-selected');
            const search = menu.querySelector('.dd-search input');
            setTimeout(() => {
                const f = search || selItem || items()[0];
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
            if (search) {
                search.addEventListener('input', () => {
                    const q = search.value.trim().toLowerCase();
                    let shown = 0;
                    menu.querySelectorAll('.dd-item').forEach((li) => {
                        const ok = li.textContent.toLowerCase().includes(q);
                        li.hidden = !ok;
                        if (ok) shown++;
                    });
                    menu.querySelectorAll('.dd-group').forEach((g) => { g.hidden = !!q; });
                    menu.querySelector('.dd-empty').hidden = shown > 0;
                });
            }
            menu.addEventListener('keydown', (e) => {
                const els = items();
                const at = els.indexOf(document.activeElement);
                if (e.key === 'Escape') { e.preventDefault(); closeCurrent(true); }
                else if (e.key === 'Tab') { closeCurrent(false); }
                else if (e.key === 'ArrowDown') { e.preventDefault(); const t = els[at + 1] || els[0]; if (t) t.focus(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); const t = at > 0 ? els[at - 1] : (search || els[els.length - 1]); if (t) t.focus(); }
                else if (e.key === 'Home' && !search) { e.preventDefault(); if (els[0]) els[0].focus(); }
                else if (e.key === 'End' && !search) { e.preventDefault(); if (els.length) els[els.length - 1].focus(); }
                else if ((e.key === 'Enter' || e.key === ' ') && document.activeElement.classList.contains('dd-item')) { e.preventDefault(); choose(document.activeElement); }
                else if (!search && e.key.length === 1 && /\S/.test(e.key)) {
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
