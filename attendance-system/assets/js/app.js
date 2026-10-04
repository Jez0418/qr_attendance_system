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
                && !sidebar.contains(e.target) && e.target !== toggleBtn) {
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
async function ajaxPost(url, data) {
    const formData = new FormData();
    for (const key in data) formData.append(key, data[key]);
    const res = await fetch(url, { method: 'POST', body: formData });
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
