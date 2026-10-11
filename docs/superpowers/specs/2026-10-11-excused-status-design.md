# Excused attendance status: design

Date: 2026-10-11 · Status: approved in conversation, awaiting written-spec review

## Goal

A teacher can turn an **Absent** record into **Excused** (e.g. a medical certificate shown in person), so a
legitimate absence no longer counts against the student. Today the only option is Absent -> Present,
which records attendance that did not happen and inflates the rate and the warnings.

## Decisions (from the user)

| Question | Decision |
|---|---|
| Who excuses | The teacher only (own classes), extending the existing override. No student requests, no uploads. |
| Effect on statistics | Not counted at all: not an absence, not a meeting. Rate = (Present+Late) / (Present+Late+Absent). |
| Can it be changed later | No. Absent -> Excused is one-way and final, like Absent -> Present. |
| Representation | Approach A: a fourth value `'Excused'` in `attendance_records.status`. |

## Assumptions (confirmed by not being corrected)

- Only an Absent record can be excused; Present/Late mean the student was there.
- Reason required (3-255 characters), student notified, activity log line: same as the override.
- Not available to admins (the override is not either).
- Excusing a future meeting in advance is out of scope: the Absent record must exist first (after the meeting ends).

## Success criteria

1. A teacher can excuse an Absent record of their own class from `teacher/history.php`, with a reason.
2. Excused shows as its own blue badge wherever statuses are shown, and in charts and exports.
3. An Excused record never counts as a scan/check-in and never counts as an absence.
4. Excusing an absence immediately lowers the student's absence count and changes their standing,
   warnings and the teacher watchlist accordingly.
5. Absent -> Excused cannot be repeated or reversed; Present/Late cannot become Excused.

## Part 1: data and rules

### Database

New file `attendance-system/database/supabase_excused_status.sql` (safe to run twice):

```sql
ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS attendance_records_status_check;
ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_check
    CHECK (status IN ('Present','Late','Absent','Excused'));
```

The live constraint is `attendance_records_status_check` (checked 2026-10-11). `status` is `VARCHAR(10)`, so
`'Excused'` fits. `supabase_schema.sql` gets the same CHECK. No new columns: excusing reuses the override columns
`marked_by_user_id`, `marked_at`, `override_reason` (who, when, why).

### Rules: `includes/attendance_override.php`

- `override_problem(?array $record, int $teacherId, string $reason, string $target = 'Present'): string`
  - `$target` must be `'Present'` or `'Excused'`, else refused.
  - Record not found or another teacher's class: same answer, "Record not found." (no id probing).
  - Record not Absent: refused, which makes Excused -> anything and Present/Late -> Excused impossible.
  - Reason length 3-255 characters (multibyte-aware), unchanged.
- `override_absent(PDO $pdo, int $recordId, string $target, int $teacherId, int $teacherUserId, string $reason): string`
  replaces `override_absent_to_present()` (single caller). Returns the student's name; throws `Exception`
  with a user-facing message.
  - `UPDATE ... SET status = ?, marked_by_user_id = ?, marked_at = NOW(), override_reason = ? WHERE record_id = ? AND status = 'Absent'`;
    `rowCount() !== 1` gives "This record was already changed. Reload the page." (double clicks, two tabs).
  - Notification: "Your attendance for <subject> on <date> was changed to <target> by your teacher. Reason: <reason>".
  - Activity log: "Changed <name> from Absent to <target> for session #<id> - <reason>".
  - All in one transaction, as today.

### What does not change (checked)

- `includes/absences.php` inserts with `ON CONFLICT DO NOTHING`, so an Excused row is never re-marked Absent.
- The re-check-Late step (`qr/session_manager.php`) only touches `Present`/`Late` rows.
- Absence notices count `status = 'Absent'` only, so an excused absence drops out of the count.
- `student/ajax_scan.php`: Excused only exists after the meeting ends, so a scan never meets it.

### Statistics: `includes/attendance_stats.php`

`attendance_standing()` is unchanged. Its queries already count attended = `Present`/`Late` and
absent = `Absent`, so Excused drops out of both. The three queries gain
`COUNT(...) FILTER (WHERE ar.status = 'Excused') AS excused`, returned in the rows for display only.

### "Real scans" cleanup

These use `status <> 'Absent'` to mean "a check-in" and would count Excused rows. Each becomes
`status IN ('Present','Late')` (PHP: `in_array($status, ['Present','Late'], true)`):

| File | Use |
|---|---|
| `admin/dashboard.php:23` | today's total check-ins |
| `admin/dashboard.php:72` | laboratory usage today |
| `admin/qr_management.php:30` | scans per session |
| `student/my_subjects.php:18` | times attended |
| `teacher/class_view.php:67` | attended count |
| `teacher/dashboard.php:41` | today's scans |
| `teacher/ajax_session_status.php:35,42,48` | live list sort, time shown, scanned count |

A test fails if `<> "Absent"`, `<> 'Absent'` or `!== 'Absent'` appears again in app code.

### Times shown: `includes/functions.php`

`format_record_time()`: an Excused row shows `"<date> · excused"` (its `time_in` is the meeting end, not a check-in).

## Part 2: the teacher action

- `teacher/history.php`: an Absent row shows two buttons, **Mark present** (unchanged) and **Excuse** (new).
  Other statuses still show "—".
- One modal with two modes, chosen when opened:
  - Present: current title, text and placeholder, plus "This can't be undone."
  - Excused: title "Excuse absence"; text "This changes the record from Absent to Excused. It won't count as an
    absence or against the attendance rate. The student is notified, and it is saved in the activity log with your
    name and this reason. This can't be undone."; placeholder "e.g. Medical certificate shown"; button "Excuse".
- `teacher/ajax_attendance_override.php` accepts `target` (default `Present`, so the existing call is unchanged);
  validation stays in `override_problem()`. CSRF and own-class checks unchanged. Success message:
  "<name> is now marked <target>."
- The Excused badge's tooltip shows "Marked by teacher: <reason>", like an overridden Present.
- Both buttons' `onclick` data switches from `e(json_encode(...))` to `js_attr_json()` (project rule).
- Not on the class roster or the live session page.

## Part 3: display

### Colour

Blue, from the existing indigo tokens (which have dark-theme values):

- `assets/css/style.css`: `.badge-excused`, `.dot-blue`, `--chart-excused` and `--chart-excused-fill` in `:root`
  and in `:root[data-theme="dark"]`. No hard-coded white (theme test).
- `assets/js/theme.js`: `themeChartDefaults()` returns `excused` and `excusedFill`.

### Pages

| Page | Change |
|---|---|
| History (teacher, student), admin monitoring, reports, dashboard rows | Badges already use `badge-<status>`: picks up `.badge-excused`. |
| `admin/dashboard.php` recent activity | New branch "<name> was excused from <subject> · <date>", blue dot. |
| `admin/dashboard.php` 7-day trend, status doughnut | Excused as a fourth series/slice. Counts arrays get an `'Excused'` key. |
| `admin/reports.php` | Fifth stat card "Excused"; Excused in the doughnut and per-subject bars. |
| `admin/attendance_monitoring.php` | "Excused" in the status filter and its allow-list. |
| `includes/export.php`, `admin/export_pdf.php` | `'Excused'` in the status list; `.status-Excused` colour. |
| `student/dashboard.php` | Stat cards unchanged (rate already excludes Excused); the Absent card shows "N excused" when N > 0; "Attendance by Class" rows show "· N excused" when N > 0. |
| `teacher/class_view.php` | "· N excused" next to each student's counts when N > 0. |
| Teacher watchlist | No change (driven by absences and rate). |

`admin/reports.php` `$totalScans = array_sum($counts)` is labelled "Total Records", so including Excused there is
correct; the variable name stays.

## Testing

- `tests/attendance_override_test.php`:
  - Absent -> Excused allowed; Excused -> Present, Excused -> Excused, Present -> Excused, Late -> Excused refused.
  - Unknown target (e.g. `'Late'`, `''`, `'excused'`) refused.
  - Existing three-argument calls still mean Present.
  - `format_record_time()` shows "· excused" for an Excused row and no check-in time.
- New source check: no `<> "Absent"` / `<> 'Absent'` / `!== 'Absent'` in app code (excluding `tests/`).
- `--db` (live database, rolled back, with the user's OK): insert an Absent record, excuse it through
  `override_absent()`, check the status, the override columns, and that `student_class_standings()` reports
  absent 0 and excused 1 for that class.
- `tests/theme_test.php` and the full suite stay green.
- Manual check of the pages listed above in the browser, light and dark, after deploy.

## Rollout

1. Run `supabase_excused_status.sql` on the live database (with the user's OK). Harmless to the current code:
   nothing writes `'Excused'` until the new code is deployed.
2. Push the branch, open a PR into `main`; merge only with the user's OK.
3. Update `CLAUDE.md` (override section) and the Desktop system notes file.

## Out of scope

Student excuse requests and uploads, excusing in advance, undoing an Excused record, an admin excuse button,
excused-specific settings or reports.
