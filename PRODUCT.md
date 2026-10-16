# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Admin** — MCNP-ISAP staff overseeing laboratory facilities shared across two institutions (Medical Colleges of Northern Philippines and International School of Asia and the Pacific). Manages students, teachers, subjects, laboratories, class assignments and schedules, attendance sessions and monitoring, enrollment requests, reports, and system settings.
- **Teacher** — conducts laboratory classes, needs to see who is present in real time without manually starting or stopping anything. Manages own subjects/classes, enrollment (direct or CSV import), reviews enrollment requests, displays the live QR session, reviews attendance history and late students.
- **Student** — attends laboratory classes and must prove physical presence, not just tap a shared code. Requests enrollment, scans attendance, reviews history, manages profile.

## Product Purpose

Prevents proxy/buddy attendance fraud in laboratory classes — a student checking in on behalf of an absent classmate, whether on paper or with a simple shared QR code — while removing the manual work of starting and stopping attendance per class. Success is an attendance record a teacher or admin can trust without having watched the door themselves.

## Positioning

A competing QR-attendance tool could copy the scan-a-code idea in a day. What it could not truthfully copy without rebuilding its core: sessions that open and close themselves from the recurring class schedule (no one has to remember to "start class"), and a server that re-verifies every rule itself — enrollment, time window, GPS radius inside the laboratory, one device per student per session, signed and versioned QR payloads — trusting nothing the browser reports. The QR code printed and mounted on a lab wall is permanent; the session behind it is not, and it only opens for the class that is actually scheduled there right now.

## Operating Context

- Academic structure: Institution -> Department -> Program -> Year level -> Section (a section is written the same way everywhere, e.g. `3A`; the program is stored separately).
- Regular students may only request classes within their own institution, program, year level and section; irregular students may request any active class across institutions (e.g. an MCNP student taking an ISAP subject). Every request needs teacher or admin approval.
- Class assignments carry a recurring weekly schedule (e.g. Mon + Wed, 3:00-5:00 PM) with optional term start/end dates and per-date exceptions (cancel or reschedule one meeting). Meetings are never stored; their status (`UPCOMING` -> `ACTIVE` -> `EXPIRED`, or `CANCELLED`) is calculated from the schedule at request time.
- Batch onboarding happens through CSV import: admin creates up to 50 student accounts per file; a teacher enrolls up to 100 student numbers into one class per file. Both preview before saving.
- Currently running as a capstone pilot: free-tier infrastructure (Vercel + Supabase) with an explicit plan to hand the project and its accounts over to the school once the pilot proves out.

## Capabilities and Constraints

- Hosting is Vercel serverless (PHP runtime) with a PostgreSQL database on Supabase; Supabase does not update from git pushes, so any schema change needs a SQL file run by hand in the Supabase SQL editor before deploying code that depends on it.
- Vercel functions are short-lived and have no persistent disk: CSV import rows are capped (50 students, 100 enrollments) and uploaded profile photos are stored as small images in the database rather than on disk.
- Attendance status is one of Present, Late, Absent, or Excused (an admin/teacher override that counts as neither attended nor absent). Late is determined by a configurable grace period after the scheduled start (default 15 minutes, overridable per class).
- A scan is accepted only when: the student is logged in, the QR signature is valid, the session is active and unexpired, the student is enrolled in that class, the meeting is today and inside its time window, GPS is available and accurate enough, the student is inside the laboratory's radius (Haversine distance), the device isn't already checked in as a different student this session, and the student hasn't already been recorded.
- Security practices already in place: bcrypt password hashing, prepared statements throughout, CSRF token on every POST, login lockout (5 failures/username, 50/IP, per 15 minutes), HttpOnly+SameSite session cookies, database errors logged server-side and never shown to users, QR payloads signed and versioned per laboratory (regenerating invalidates old printouts).
- Student GPS locations are stored only to verify attendance; how long to retain them and what to tell students about that is an open decision, not yet settled.

## Brand Commitments

- Name: "MCNP-ISAP QR Attendance" (short) / "MCNP-ISAP QR Laboratory Attendance System" (full), serving both MCNP and ISAP under one shared system.
- Existing school emblem asset (`assets/img/school-emblem-96.png` and related sizes) is the established mark; not open for replacement without the schools' sign-off.

## Evidence on Hand

- A deployed production instance (Vercel + Supabase) is live and in pilot use, seeded with real-shaped demo semester data (~30 `DEMO-` prefixed student accounts and their attendance sessions) standing in for the school's actual upcoming semester.
- Public seed/demo accounts exist for every role (admin, two teacher logins, four student logins), all sharing one placeholder password — these are documented as public and meant to be changed or removed before real use, not evidence of a real credential scheme.
- No testimonials, press, case studies, or third-party benchmarks exist; future work must not invent any.

## Product Principles

1. Trust nothing the client reports — every attendance rule is re-checked server-side at scan time, never assumed from what the browser already validated.
2. Sessions manage themselves — attendance windows follow the recurring schedule automatically; staff should never need to remember to open or close one.
3. Free-tier by design — the architecture is deliberately shaped to run within Vercel's and Supabase's free tiers so a budget-constrained school can actually afford to operate it through the pilot.
4. Close the cheap fraud paths first — one device per student per session and signed, versioned QR payloads matter more here than any visual polish.
5. Built to be handed off — this is pilot infrastructure on borrowed/free accounts with a known future step of transferring ownership to the school; decisions should stay easy to migrate, not just easy to demo.
