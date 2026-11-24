---
target: attendance-system/student/scanner.php
total_score: 21
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 4
target_identity: "file:C:\\xampp82\\htdocs\\qr_attendance_system-main\\attendance-system\\student\\scanner.php"
target_fingerprint: "sha256:8658966554dd4de0a68dba7d4342d1093383a5ceeb1164f9af76c16af9fdf03e"
target_path: "C:\\xampp82\\htdocs\\qr_attendance_system-main\\attendance-system\\student\\scanner.php"
timestamp: 2026-10-28T17-17-25Z
slug: attendance-system-student-scanner-php
---
Method: dual-agent (A: design review · B: detector + browser evidence). A's live tab kept falling back to the new-tab page, so A judged from source; B captured the live DOM state.

## Design Health Score
| # | Heuristic | Score | Key Issue |
|---|---|---|---|
| 1 | Visibility of System Status | 3 | 4-row status panel is good; a network failure leaves "Checking attendance..." stuck, and Location stays "Verified" after an outside-area rejection |
| 2 | Match System / Real World | 2 | "GPS Accuracy: 124 meters" has no meaning for a student; camera errors print the raw browser error |
| 3 | User Control and Freedom | 2 | No stop/switch camera, no torch; "Scan Another" after a success invites a re-scan that ends in red "already recorded" |
| 4 | Consistency and Standards | 2 | Waiting states use the Absent-red dot, Start Scanner is Present-green btn-success, a LATE result sits in a green card; "tap Scan again" vs button "Scan Another" |
| 5 | Error Prevention | 2 | Accuracy is never judged client-side: live page showed 124 m (limit 100) next to "Ready to Scan" |
| 6 | Recognition Rather Than Recall | 3 | Auto-start flow; nothing says what to aim at |
| 7 | Flexibility and Efficiency | 2 | No torch, no way to re-acquire location without re-scanning |
| 8 | Aesthetic and Minimalist Design | 2 | Diagnostics lead the hierarchy; title duplicated (H1 + H3 "Scan Attendance"); 9-row receipt |
| 9 | Error Recovery | 2 | Outside-area and accuracy rejections give no numbers or next step; INVALID_QR also covers "no class running" |
| 10 | Help and Documentation | 1 | No inline why-GPS / what-is-accurate-enough help |
| **Total** | | **21/40** | **Acceptable** |

## Design Specificity Verdict
Category-generic surface over product-specific logic: diagnostics table, button, camera, receipt. The verification story (lab wall code, fresh GPS, one phone per student) is not told on screen, and the page breaks DESIGN.md's status-colour rule. Detector: 0 findings on scanner.php, header.php, footer.php (exit 0). Overlay: blocked by the page's CSP (script-src-elem / connect-src for localhost:8400), no overlay.

## Priority Issues
1. [P0] Check-in outcome not announced; status can go stale. No aria-live / role=status anywhere (confirmed live); toast close is a non-focusable span; catch branch leaves "Checking attendance...". Fix: role=status (alert on rejection) on #scanResult, move focus to result heading, update status text on failure, button for toast close. Command: harden.
2. [P1] Ready-to-scan with a reading the server will reject. Live: 124 m accuracy, limit 100, status "Ready to Scan". Fix: compare to max_gps_accuracy_meters client-side, show "Waiting for a better GPS fix (124 m, needs 100 m)" and keep re-reading. Command: harden.
3. [P1] Rejections give no remedy. Outside-area/accuracy messages lack distance and next step; INVALID_QR hides "no class running now" / "code replaced" / "window ended". Command: clarify.
4. [P1] Hierarchy inverted on phones: status panel and buttons above the camera; duplicated title. Fix: camera first with "Point at the QR code on the lab wall", status collapsed to one line that expands on a problem. Command: layout.
5. [P1] Status colours double-duty: red dots for waiting, green Start Scanner, green card for LATE. Fix: slate/blue pending dots, btn-primary, result tinted by real status. Command: colorize.
6. [P2] Success ends on "Scan Another" (leads to a red rejection); 9-row receipt. Fix: success offers dashboard/history; receipt = subject, status, time. Command: polish.

## Persona Red Flags
Casey: camera below a 4-row panel; full-width toast over the top bar; "Location: Verified" before the real check; no torch; "Scan Another" after success.
Jordan: location prompt on page load with no why; no instruction what to scan; "GPS Accuracy: 52 meters" meaningless; raw NotAllowedError text.
Sam: no live regions; toast close not keyboard-reachable; focus not moved to result; all-caps "SCAN REJECTED".

## Minor Observations
Inline dot styles repeated 5x; res.message/student_name/exception_reason written via innerHTML unescaped (server-sourced, low risk, escape anyway); all-caps headings against weight-over-size; allowLocationBtn hidden after page-open success so later location failure only recovers via "Scan Another".

## Questions to Consider
Should a happy-path student ever see "GPS Accuracy"? Could the page say which class it expects ("Anatomy Lab, in session until 5:00") before the scan? Should an unfixable rejection offer one-tap "Notify my teacher"?
