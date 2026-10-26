// k6 load test for the QR attendance system. Run ONLY against the staging preview
// (branch `loadtest`, staging Supabase project with synthetic LOADTEST- accounts):
//   bash tests/load/run.sh dry     one student + one teacher, one pass (checks the flow)
//   bash tests/load/run.sh full    ramp 0-100, hold 100, spike 200, headroom 300 (about 12 min)
// run.sh reads the secrets from attendance-system/.env and passes them with -e.
//
// Student virtual user (lt0001..lt0300): login -> dashboard -> scanner -> scan the lab QR once,
// then keeps browsing dashboard/history with think time. Odd VUs are lab 1 students (lt0001-lt0150),
// even VUs lab 2 students (lt0151-lt0300), so both classes fill up as the load ramps.
// Teacher virtual users (ltteacher1/2) open the session page and poll it every 10 s like the real page.
import http from 'k6/http';
import crypto from 'k6/crypto';
import { check, sleep, fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL;
const BYPASS = __ENV.BYPASS;
const PASSWORD = __ENV.PASSWORD;
const QR_KEY = __ENV.QR_KEY;
const PROFILE = __ENV.PROFILE || 'dry';
if (!BASE || !BYPASS || !PASSWORD || !QR_KEY) fail('BASE_URL, BYPASS, PASSWORD and QR_KEY are required (use run.sh)');
if (!/git-loadtest/.test(BASE)) fail('Refusing to run: BASE_URL is not the loadtest preview');

// Staging labs (database seed): id, GPS centre. Radius 50 m.
const LABS = { 1: [17.6132, 121.7270], 2: [17.6135, 121.7273] };
const UAS = [
    'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 12; Redmi Note 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
];

const scanAccepted = new Counter('scan_accepted');
const scanRejected = new Counter('scan_rejected');
const scanTime = new Trend('scan_duration', true);
const loginTime = new Trend('login_duration', true);

const ABORT = { abortOnFail: true, delayAbortEval: '30s' };
const THRESHOLDS = {
    http_req_failed: [{ threshold: 'rate<0.05', ...ABORT }],
    http_req_duration: [{ threshold: 'p(95)<10000', ...ABORT }],
};

// k6 clears each VU's cookies at the start of every iteration by default, which would log the
// virtual users out after their first pass: keep them, like a phone keeps its session.
const COMMON = { noCookiesReset: true, summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'] };
// One (always-passing) threshold per endpoint so the summary shows each endpoint's timings separately.
for (const name of ['GET /login.php', 'POST /login.php', 'GET /student/dashboard.php', 'GET /student/history.php', 'GET /student/scanner.php',
    'POST /student/ajax_scan.php', 'GET /teacher/session.php', 'GET /teacher/ajax_session_status.php']) {
    THRESHOLDS[`http_req_duration{name:${name}}`] = ['max>=0'];
}

export const options = PROFILE === 'full' ? { ...COMMON,
    scenarios: {
        students: {
            executor: 'ramping-vus', exec: 'student', startVUs: 0, gracefulRampDown: '20s',
            stages: [
                { duration: '3m', target: 100 },   // ramp to the expected peak (2 classes x 50)
                { duration: '5m', target: 100 },   // hold
                { duration: '30s', target: 200 },  // 2x spike
                { duration: '1m30s', target: 200 },
                { duration: '30s', target: 300 },  // headroom
                { duration: '1m30s', target: 300 },
                { duration: '30s', target: 0 },
            ],
        },
        teachers: { executor: 'constant-vus', exec: 'teacher', vus: 2, duration: '12m30s' },
    },
    thresholds: THRESHOLDS,
} : { ...COMMON,
    scenarios: {
        students: { executor: 'per-vu-iterations', exec: 'student', vus: 1, iterations: 2 },
        teachers: { executor: 'per-vu-iterations', exec: 'teacher', vus: 1, iterations: 1 },
    },
    thresholds: THRESHOLDS,
};

function params(name, extra) {
    const h = Object.assign({ 'x-vercel-protection-bypass': BYPASS, 'User-Agent': UAS[(__VU - 1) % UAS.length] }, extra || {});
    return { headers: h, tags: { name }, redirects: 5 };
}
function think(min, max) { sleep(min + Math.random() * (max - min)); }
function csrfFrom(res) {
    const m = res.body && res.body.match(/<meta name="csrf-token" content="([^"]+)"/);
    return m ? m[1] : null;
}
function login(username, role) {
    http.get(`${BASE}/login.php`, params('GET /login.php'));
    const res = http.post(`${BASE}/login.php`, { username, password: PASSWORD, role }, params('POST /login.php'));
    loginTime.add(res.timings.duration);
    return check(res, { 'login lands on dashboard': (r) => r.status === 200 && /\/(student|teacher)\/dashboard\.php/.test(r.url) });
}
function labPayload(labId) {
    const sig = crypto.hmac('sha256', QR_KEY, `lab|${labId}|1`, 'hex');
    return JSON.stringify({ t: 'lab', lab: labId, v: 1, sig });
}

// Per-VU state survives between iterations.
let loggedIn = false, scanned = false;

export function student() {
    const lab1 = __VU % 2 === 1;
    const n = lab1 ? (__VU + 1) / 2 : 150 + __VU / 2;
    // __VU is shared with the teacher scenario, so ids can run past 300: never borrow the other lab's students.
    if ((lab1 && n > 150) || n > 300) { sleep(60); return; }
    const username = 'lt' + String(n).padStart(4, '0');
    const labId = lab1 ? 1 : 2;

    if (!loggedIn) {
        if (!login(username, 'student')) { think(5, 10); return; }
        loggedIn = true;
        think(2, 6);
    }
    if (!scanned) {
        const page = http.get(`${BASE}/student/scanner.php`, params('GET /student/scanner.php'));
        const csrf = csrfFrom(page);
        check(page, { 'scanner page has CSRF token': () => !!csrf && /\/student\/scanner\.php/.test(page.url) });
        think(3, 10);   // point the camera, wait for the GPS fix
        const [lat, lon] = LABS[labId];
        const res = http.post(`${BASE}/student/ajax_scan.php`, {
            qr_payload: labPayload(labId),
            latitude: (lat + (Math.random() - 0.5) * 0.0003).toFixed(7),   // within about 17 m of the centre
            longitude: (lon + (Math.random() - 0.5) * 0.0003).toFixed(7),
            accuracy: (5 + Math.random() * 35).toFixed(1),
            csrf,
        }, params('POST /student/ajax_scan.php', { 'X-Requested-With': 'XMLHttpRequest' }));
        scanTime.add(res.timings.duration);
        let body = {};
        try { body = res.json(); } catch (e) { /* not JSON: counted below */ }
        if (body.success) scanAccepted.add(1); else scanRejected.add(1, { reason: String(body.message || res.status).slice(0, 80) });
        check(res, { 'scan accepted': () => body.success === true });
        scanned = true;
        think(2, 5);
    }
    // After checking in: look around the app like a student would.
    // A logged-out user is redirected to login.php, which is also a 200: check the final URL too.
    const r1 = http.get(`${BASE}/student/dashboard.php`, params('GET /student/dashboard.php'));
    check(r1, { 'dashboard 200 (still logged in)': (r) => r.status === 200 && /\/student\/dashboard\.php/.test(r.url) });
    think(5, 30);
    const r2 = http.get(`${BASE}/student/history.php`, params('GET /student/history.php'));
    check(r2, { 'history 200 (still logged in)': (r) => r.status === 200 && /\/student\/history\.php/.test(r.url) });
    think(5, 30);
}

export function teacher() {
    const username = 'ltteacher' + (((__VU - 1) % 2) + 1);
    if (!loggedIn) {
        if (!login(username, 'teacher')) { think(5, 10); return; }
        loggedIn = true;
    }
    const page = http.get(`${BASE}/teacher/session.php`, params('GET /teacher/session.php'));
    const m = page.body && page.body.match(/const SESSION_ID = (\d+);/);
    check(page, { 'session page shows an open session': () => !!m });
    if (!m) { think(10, 10); return; }
    const polls = PROFILE === 'full' ? 30 : 2;   // 30 x 10 s = 5 min, then reload the page
    for (let i = 0; i < polls; i++) {
        sleep(10);
        const r = http.get(`${BASE}/teacher/ajax_session_status.php?session_id=${m[1]}`, params('GET /teacher/ajax_session_status.php'));
        check(r, { 'status poll ok': (res) => res.status === 200 && /"success":\s*true/.test(res.body) });
    }
}
