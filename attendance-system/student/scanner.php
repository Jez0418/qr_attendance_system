<?php
/**
 * student/scanner.php
 * "Scan Attendance" — opening the page asks for location right away and,
 * once it is granted, starts the camera so the student can scan the QR
 * code (the buttons are only a retry fallback). Both the QR payload AND the raw GPS reading
 * are sent to the server; the SERVER (not the browser) makes the
 * final decision on whether attendance is recorded. See
 * student/ajax_scan.php for the actual validation logic.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('student');
$pageTitle = 'Scan Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="scanner-wrapper">
    <div class="card">
        <div class="card-header"><h3>Scan Attendance</h3></div>
        <div class="card-body">

            <div class="geo-status-panel">
                <div class="geo-status-row">
                    <span class="geo-status-label">Camera</span>
                    <span class="geo-status-value" id="cameraStatusText"><span class="dot dot-red" style="width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px"></span>Not started</span>
                </div>
                <div class="geo-status-row">
                    <span class="geo-status-label">Location</span>
                    <span class="geo-status-value" id="locationStatusText"><span class="dot dot-red" style="width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px"></span>Waiting...</span>
                </div>
                <div class="geo-status-row">
                    <span class="geo-status-label">GPS Accuracy</span>
                    <span class="geo-status-value" id="accuracyText">—</span>
                </div>
                <div class="geo-status-row">
                    <span class="geo-status-label">Attendance Status</span>
                    <span class="geo-status-value" id="attendanceStatusText">Getting your location...</span>
                </div>
            </div>

            <div class="text-center" style="margin:18px 0">
                <button class="btn btn-primary btn-block" id="allowLocationBtn" onclick="requestLocation()" disabled><span class="spinner"></span> Getting location...</button>
                <button class="btn btn-success btn-block" id="scanBtn" style="margin-top:10px;display:none" onclick="startScanner()"><i class="fa-solid fa-camera"></i> Start Scanner</button>
            </div>

            <div id="qrReader" style="width:100%"></div>
            <div id="scanResult"></div>
            <div class="text-center" style="margin-top:16px">
                <button class="btn btn-outline btn-sm" id="restartBtn" style="display:none" onclick="resetScanner()"><i class="fa-solid fa-rotate"></i> Scan Another</button>
            </div>
        </div>
    </div>
</div>

<style>
.geo-status-panel{background:var(--slate-50);border:1px solid var(--slate-200);border-radius:var(--radius-md);padding:14px 16px}
.geo-status-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:4px 12px;padding:7px 0;font-size:13.5px}
.geo-status-row:not(:last-child){border-bottom:1px solid var(--slate-200)}
.geo-status-label{color:var(--slate-500);font-weight:600}
.geo-status-value{color:var(--slate-900);font-weight:600;text-align:right;margin-left:auto;min-width:0;overflow-wrap:anywhere}
</style>

<script>
let html5QrCode;
let scanning = false;
let currentPosition = null; // { latitude, longitude, accuracy }

function requestLocation() {
    const btn = document.getElementById('allowLocationBtn');
    if (!navigator.geolocation) {
        btn.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Location not supported';
        document.getElementById('attendanceStatusText').textContent = 'Location required';
        showToast('error', 'Geolocation is not supported by this browser.');
        return;
    }
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Getting location...';

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            currentPosition = {
                latitude: pos.coords.latitude,
                longitude: pos.coords.longitude,
                accuracy: pos.coords.accuracy
            };
            document.getElementById('locationStatusText').innerHTML =
                '<span class="dot dot-green" style="width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px"></span>Verified ✓';
            document.getElementById('accuracyText').textContent = Math.round(pos.coords.accuracy) + ' meters';
            document.getElementById('attendanceStatusText').textContent = 'Ready to Scan';
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Location Allowed';
            startScanner();
        },
        (err) => {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Allow Location';
            document.getElementById('attendanceStatusText').textContent = 'Location required';
            showToast('error', err.code === err.PERMISSION_DENIED
                ? 'Location is blocked. Allow location for this site in your browser settings, then tap Allow Location.'
                : 'Could not get your location. Make sure GPS is on, then tap Allow Location.');
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

async function startScanner() {
    if (!currentPosition) {
        showToast('error', 'Location access is required to verify that you are physically present in the laboratory.');
        return;
    }
    document.getElementById('scanBtn').style.display = 'none';
    document.getElementById('allowLocationBtn').style.display = 'none';
    document.getElementById('qrReader').innerHTML = '<p class="text-muted" style="text-align:center;padding:20px"><span class="spinner spinner-dark"></span> Loading scanner...</p>';

    try {
        await ensureHtml5QrcodeLibrary();
    } catch (err) {
        document.getElementById('qrReader').innerHTML =
            '<div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> ' + err.message + '</div>';
        return;
    }

    document.getElementById('qrReader').innerHTML = '';
    try {
        html5QrCode = new Html5Qrcode('qrReader');
        scanning = true;
        await html5QrCode.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: 240 },
            onScanSuccess,
            () => {} // ignore per-frame "not found" errors
        );
        document.getElementById('cameraStatusText').innerHTML =
            '<span class="dot dot-green" style="width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px"></span>Connected';
    } catch (err) {
        scanning = false;
        document.getElementById('qrReader').innerHTML =
            '<div class="alert alert-error">Could not access camera: ' + err + '. Please allow camera permission, then tap Start Scanner.</div>';
        const scanBtn = document.getElementById('scanBtn');
        scanBtn.style.display = 'inline-flex';
        scanBtn.disabled = false;
    }
}

async function onScanSuccess(decodedText) {
    if (!scanning) return;
    scanning = false;
    await html5QrCode.stop();
    document.getElementById('qrReader').innerHTML = '';

    document.getElementById('cameraStatusText').innerHTML =
        '<span class="dot dot-green" style="width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px"></span>QR Code Detected ✓';
    document.getElementById('attendanceStatusText').textContent = 'Checking attendance...';
    const resultDiv = document.getElementById('scanResult');
    resultDiv.innerHTML = '<div class="alert alert-info"><span class="spinner spinner-dark"></span> Checking attendance (location, enrollment, and active class)...</div>';

    try {
        const res = await ajaxPost('ajax_scan.php', {
            qr_payload: decodedText,
            latitude: currentPosition.latitude,
            longitude: currentPosition.longitude,
            accuracy: currentPosition.accuracy
        });

        if (res.success) {
            document.getElementById('attendanceStatusText').textContent = res.status.toUpperCase();
            resultDiv.innerHTML = `
                <div class="scan-result scan-ok">
                    <i class="fa-solid fa-circle-check" style="font-size:32px;color:var(--green-600)"></i>
                    <h3 style="margin:10px 0 14px">ATTENDANCE RECORDED</h3>
                    <div style="text-align:left;font-size:13.5px;line-height:2;max-width:280px;margin:0 auto">
                        <div><strong>Student:</strong> ${res.student_name}</div>
                        <div><strong>Subject:</strong> ${res.subject_name}</div>
                        <div><strong>Teacher:</strong> ${res.teacher_name}</div>
                        <div><strong>Laboratory:</strong> ${res.lab_name}</div>
                        <div><strong>Date:</strong> ${res.date}</div>
                        <div><strong>Time:</strong> ${res.time_in}</div>
                        <div><strong>Location:</strong> Verified ✓</div>
                        <div><strong>Distance:</strong> ${res.distance} meters</div>
                        <div><strong>Status:</strong> <span class="badge badge-${res.status.toLowerCase()}">${res.status.toUpperCase()}</span></div>
                    </div>
                </div>`;
            showToast('success', res.message);
        } else {
            document.getElementById('attendanceStatusText').textContent = 'Scan Rejected';
            resultDiv.innerHTML = `<div class="scan-result scan-bad">
                <i class="fa-solid fa-circle-exclamation" style="font-size:32px;color:var(--red-600)"></i>
                <h3 style="margin:10px 0 4px">SCAN REJECTED</h3><p style="margin:0">${res.message}</p></div>`;
            showToast('error', res.message);
        }
    } catch (err) {
        resultDiv.innerHTML = '<div class="alert alert-error">Something went wrong verifying your scan. Please try again.</div>';
    }
    document.getElementById('restartBtn').style.display = 'inline-flex';
}

function resetScanner() {
    document.getElementById('scanResult').innerHTML = '';
    document.getElementById('restartBtn').style.display = 'none';
    document.getElementById('attendanceStatusText').textContent = 'Ready to Scan';
    startScanner();
}

// Opening "Scan Attendance" starts the flow: location first, then the camera.
document.addEventListener('DOMContentLoaded', requestLocation);
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
