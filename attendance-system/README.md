# MCNP / ISAP QR Laboratory Attendance Management System

A complete, self-contained PHP + MySQL + JavaScript attendance system built
for **Medical Colleges of Northern Philippines (MCNP)** and **International
School of Asia and the Pacific (ISAP)**, built to run directly on **XAMPP**
with no external frameworks (no Composer, no Laravel/CodeIgniter — plain
PHP, prepared-statement MySQL, and vanilla JS/AJAX).

**Core rule the whole system is built around:** a student can record
attendance only if they're authenticated, officially enrolled in the
subject, the class is scheduled for **today** (by explicit meeting date —
never day-of-week alone), the QR session is active and hasn't expired, the
current time is within the attendance window, their location is inside the
laboratory's configured radius, and they haven't already recorded
attendance for that session.

---

## ✨ What's in this version (v5)

- **Institution → Department → Program → Year → Section hierarchy.**
  MCNP and ISAP are seeded with their real program lists (BSN, BSMLS, BSPh,
  BSRT, BSPT, and the three 2-year diplomas for MCNP; BSCRIM, BSCA, BSCPE,
  BSIT, BSA, BSSW, BSPSY, BSHM, BSTM, BSBA for ISAP). Choosing an
  institution in any form filters the program dropdown to only that
  institution's programs — selecting MCNP never shows ISAP programs or
  vice versa, and this is re-verified server-side, not just hidden in the UI.
- **Explicit Meeting Date on every class assignment.** A class is no longer
  "every Monday" in the abstract — it's tied to a real calendar date, and
  that date is the single source of truth for whether it can be activated
  today. A class from yesterday can never activate today; a class scheduled
  for tomorrow can't be activated early either.
- **Regular vs. Irregular students.** Regular students only see/request
  subjects matching their own institution + program + year level + section.
  Irregular students can see subjects from other year levels/sections
  within their own institution + program — clearly labeled as such — but
  every request still requires approval either way.
- **Temporary, per-session QR tokens — no permanent codes.** Every time
  attendance is opened, a brand-new random token is generated. It stops
  working the instant that session closes or expires, and is never reused,
  so an old screenshot can never be replayed for a later class meeting.
- **Full server-side validation order** (student/ajax_scan.php): auth →
  QR signature → session exists/active/not expired → enrollment → meeting
  date matches today → time window → location available → GPS accuracy →
  geofence radius → not already recorded. Every one of these is checked in
  PHP, never trusted from the browser.
- **Synchronized enrollment approvals.** A request is one shared row —
  whichever the teacher *or* an admin approves/rejects first, it vanishes
  from pending lists on both sides immediately (there's only one row to
  update, so there's no way for it to go out of sync). Students can also
  cancel their own still-pending requests.

---

## ✨ Full Feature List

**Login** — Role selector (Admin/Teacher/Student); password = the user's own
ID number; the selected role must match the account's real role.

**Administrator**
- Dashboard with live stats and config alerts (pending requests, labs
  missing GPS setup)
- Student / Teacher / Subject / Laboratory CRUD
- **Institutions & Programs** are seeded with the MCNP/ISAP hierarchy;
  manage departments and programs' active/inactive status from the database
  directly or extend `admin/` with a dedicated settings page as needed
- **Class Assignments** ("New Assignment"): Institution → Department →
  Program → Year Level → Section → Subject → Teacher → Laboratory →
  Meeting Date (Day auto-computed from the date) → Start/End Time → Max
  Students → Status, fully validated server-side (start < end, all
  required fields, program genuinely belongs to the chosen
  institution/department)
- **Attendance Session Management**: activate/deactivate attendance for any
  class (date/radius constrained to that class's own meeting date), view
  live sessions with their temporary QR codes, force-stop any session
- **Settings**: configurable max GPS accuracy and default geofence radius
- **Enrollment Requests**: system-wide view; admin can approve/reject any
  request directly, same as the owning teacher can
- Attendance monitoring with multi-filter search; PDF/Excel report export
- Notification broadcast to teachers/students

**Teacher**
- **My Assigned Subjects** — only subjects the admin assigned to them,
  each clickable into a hub showing subject info, pending requests for that
  class, the enrolled roster, and a direct-enroll search
- **Enrollment Requests** — approve/reject (rejection requires a reason);
  approving enrolls the student immediately
- **Attendance Session** — a toggle switch opens/closes attendance for the
  selected class; the toggle is disabled entirely if the class's meeting
  date isn't today. Shows the session's temporary QR code and a live
  roster (Pending → Present/Late) that refreshes every few seconds
- Attendance history + a dedicated "Late Students" report

**Student**
- **Request Enrollment** — browse subjects filtered by their own
  institution/program (and, if Regular, their year/section too); submit a
  request with optional remarks
- **My Enrollment Requests** — track status, see who reviewed it, cancel
  while still pending
- **My Subjects** — everything officially enrolled in
- **Scan Attendance** — camera-and-location permission flow (works on
  Android, iPhone/iPad, and desktop browsers with camera support via a
  resilient multi-CDN library loader), full result screen with distance
  and status, or a specific rejection reason
- Attendance history, editable profile, notifications

---

## 🗂 Key Files

```
attendance-system/
├── admin/
│   ├── assignments.php / ajax_assignments.php   New Assignment (full hierarchy)
│   ├── students.php / ajax_students.php          Student CRUD (full hierarchy)
│   ├── qr_management.php                          Attendance Session Management
│   ├── ajax_qr_activate.php / ajax_qr_deactivate.php
│   ├── ajax_requests.php                           Admin approve/reject
│   ├── settings.php                                 GPS accuracy / radius config
├── teacher/
│   ├── session.php / ajax_session.php              Open/close attendance + QR
│   ├── class_view.php                                Per-class hub
│   ├── ajax_requests.php                             Teacher approve/reject
├── student/
│   ├── browse_subjects.php / ajax_request_enrollment.php   Eligibility-filtered browsing + request
│   ├── ajax_cancel_request.php                              Cancel a pending request
│   ├── scanner.php / ajax_scan.php                            Core GPS+QR validation engine
├── includes/
│   ├── config.php                DB credentials + app constants (EDIT THIS FIRST)
│   ├── functions.php               compute_class_status(), student_eligible_for_class(), etc.
│   ├── geo.php                       Haversine distance / geofence check
├── qr/
│   ├── qr_helper.php           Signed, temporary per-SESSION QR payloads (no permanent codes)
│   └── session_manager.php       Shared activate/deactivate — enforces meeting_date = today
├── database/
│   ├── schema.sql                                      Base tables (v1)
│   ├── migration_v2_gps_qr_enrollment.sql
│   ├── migration_v3_admin_enrollment_approval.sql
│   ├── migration_v4_static_lab_qrcodes.sql
│   ├── migration_v5_institution_hierarchy.sql            This version
│   └── reset_passwords_to_id.php                           Sets demo passwords to ID numbers
└── README.md
```

---

## 🚀 Setup on XAMPP

1. **Copy the project folder** into `htdocs`, e.g. `C:\xampp\htdocs\attendance-system\`.
2. **Start Apache and MySQL** from the XAMPP Control Panel.
3. **Create the database first, via phpMyAdmin's own UI** — click **"New"**
   in the left sidebar, name it `qr_attendance_system`, set Collation to
   `utf8mb4_unicode_ci`, click **Create**. None of this project's SQL
   files run `DROP DATABASE` or `CREATE DATABASE` themselves — some
   hosting panels and managed phpMyAdmin setups disable those two
   statements even when table-level `CREATE`/`DROP TABLE` still work
   fine, so creating the database through the UI sidesteps that entirely.
4. **Select `qr_attendance_system`**, open its **SQL** tab, and run these
   files **in this exact order** (paste each one's contents → **Go**):
   1. `database/schema.sql` — safe to re-run any time; it drops and
      recreates its own tables (not the database) first, so it won't
      conflict with a previous attempt.
   2. `database/migration_v2_gps_qr_enrollment.sql`
   3. `database/migration_v3_admin_enrollment_approval.sql`
   4. `database/migration_v4_static_lab_qrcodes.sql`
   5. `database/migration_v5_institution_hierarchy.sql`

   This is required **even for a fresh install** — `schema.sql` alone only
   has the original base tables; everything built since then (GPS,
   enrollment requests, the institution hierarchy, meeting dates) lives in
   the migration files. Each migration is idempotent and safe to run
   exactly once.
4. **Set demo passwords to ID numbers**: visit
   `http://localhost/attendance-system/database/reset_passwords_to_id.php` once.
5. **Check `includes/config.php`** (defaults match a stock XAMPP install;
   `Asia/Manila` timezone is already set there).
6. **Set real GPS coordinates** for your laboratories: Admin → Laboratories
   → Edit → "Use My Current Location" while standing in each room. A class
   cannot be activated until its laboratory has coordinates.
7. **Create a class assignment with a real meeting date** (Admin → Class
   Assignments → New Assignment) before trying to activate attendance —
   there's nothing to activate until a class exists for today.

### Demo accounts (password = ID number)
| Role    | Username   | Password   |
|---------|------------|------------|
| Admin   | `admin`    | `ADM-0001` |
| Teacher | `tcruz`    | `EMP-001`  |
| Student | `s2023001` | `2023-0001`|

> 🔒 Delete `database/reset_passwords_to_id.php` once your accounts are set up.

### 🔧 Troubleshooting: "DROP DATABASE statements are disabled"
This means your phpMyAdmin/hosting setup blocks that one specific
statement — common on shared hosting, school/lab servers, and some managed
panels, even when your account can freely create/drop tables. Fix: create
`qr_attendance_system` yourself via phpMyAdmin's **"New"** button (step 3
above) instead of letting a script do it, then run `schema.sql` — it only
drops/recreates its own **tables**, never the database itself, so this
error shouldn't come up again. If you're re-running `schema.sql` after a
previous partial attempt, it's safe to just run it again as-is.

---

## 🧪 Testing Checklist (matches the acceptance criteria this version was built against)

**Assignment test** — Admin → Class Assignments → New Assignment → fill
Institution/Department/Program/Year/Section/Subject/Teacher/Laboratory,
Meeting Date, Start/End Time, Max Students → Save. Confirm it appears in
the list with the correct computed status badge (Upcoming/Active/Expired).

**Yesterday test** — Create (or edit) an assignment with `meeting_date` set
to yesterday. Try to activate it from either Admin → Attendance Session
Management or the Teacher's own toggle: both must refuse, with a message
naming the actual meeting date.

**Today's class test** — Create an assignment with today's date.
- Before `start_time`: status badge shows **Upcoming**, activation is allowed.
- Between `start_time` and `end_time`: status shows **Active**.
- After `end_time`: status shows **Expired**; activation is refused with a
  "time window has already ended" message, and any still-open session for
  it auto-closes on the next page load.

**Enrollment sync test** — Student submits a request (status: pending
everywhere). Teacher approves → student becomes enrolled, request
disappears from both the teacher's and the admin's pending lists in the
same action (same underlying row). Repeat with an admin approving instead,
and with a rejection (reason required, student notified).

**QR / location test** — Activate a class, open **Scan Attendance** as an
enrolled student (grant location, then camera), scan the QR shown on the
teacher's/admin's screen. Confirm: inside the radius → recorded
Present/Late; outside the radius → rejected with the exact "outside the
allowed attendance area" message; same student scanning twice → second
scan rejected as a duplicate; a student not enrolled in that subject →
rejected as not enrolled.

---

## 📸 Camera & Location Permissions

Both the camera (QR scanning) and Geolocation API require a **secure
context**: `http://localhost/...` works on the same computer, but a phone
connecting over Wi-Fi via plain `http://192.168.x.x` will have both
blocked by the browser. To test from a real phone, use an HTTPS tunnel
(e.g. `ngrok http 80`) or, for local Android Chrome testing only, add your
local IP under `chrome://flags/#unsafely-treat-insecure-origin-as-secure`.

QR scanning and generation both load their JS libraries through a
resilient multi-source loader (`assets/js/app.js`) that tries several CDNs
and shows a clear error instead of a blank screen if all of them are
blocked by a restrictive network.

---

## 🔐 Security Notes

- **Nothing from the browser is trusted for authorization decisions.**
  Institution/department/program combinations, GPS coordinates, dates, and
  times are all independently re-verified server-side against the database
  — the client-side cascading dropdowns and status displays are UX
  conveniences only.
- All queries use **PDO prepared statements**. Passwords are hashed with
  **bcrypt**. Session ID is regenerated on login.
- **Role-based access control** guards every page, and **ownership** is
  re-checked server-side on every teacher/admin action — a teacher cannot
  approve another teacher's request or view another teacher's class by
  editing the URL or a hidden form field.
- QR payloads are HMAC-signed (`qr/qr_helper.php` — change `QR_SECRET_KEY`
  before any real deployment) and scoped to one session's random token,
  never reused.
- GPS validation happens entirely server-side via the Haversine formula;
  client-reported coordinates are just input to that calculation.

---

## 🛠 Tech Stack

PHP 8 (PDO, prepared statements, password_hash) · MySQL/MariaDB (InnoDB,
foreign keys) · HTML5/CSS3/vanilla JS (`fetch` AJAX) · Chart.js · QR
generation via qrcode.js, scanning via html5-qrcode (both via a multi-CDN
loader) · browser Geolocation API + server-side Haversine · browser
print-to-PDF and native `.xls` streaming for exports.

No Composer, no Node build step — everything runs as-is once dropped into
`htdocs` and the database is imported.

---

## ⚠️ Known Scope Limitations (honest notes for whoever maintains this next)

- There's no dedicated admin UI yet for creating/editing **Institutions**
  and **Departments** as standalone records — they're seeded correctly in
  the migration, and Programs are manageable implicitly through the schema,
  but a polished CRUD screen for institutions/departments themselves would
  need to be added the same way `admin/laboratories.php` was built.
- Cross-device QR/camera behavior (Android/iPhone/desktop browsers) has
  been built against the standard Geolocation + camera APIs and a
  multi-source library loader for resilience, but hasn't been verified on
  physical devices in this environment — test on real hardware before
  relying on it for an actual class.
- CSRF tokens are not yet implemented on top of the existing
  session-based auth + role checks; add them to form submissions if this
  is deployed somewhere more exposed than a campus-internal XAMPP server.
