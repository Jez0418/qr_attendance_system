# Excused Attendance Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A teacher can change an Absent record of their own class to Excused (reason required, one-way), and Excused is shown everywhere but never counted as a scan or an absence.

**Architecture:** A fourth value `'Excused'` in `attendance_records.status`. The existing Absent -> Present override (`includes/attendance_override.php`) gains a `$target` of `'Present'` or `'Excused'`. Queries that meant "a check-in" with `status <> 'Absent'` switch to `status IN ('Present','Late')`. Display picks up a blue badge, chart colour and export colour.

**Tech Stack:** Plain PHP 8 + PDO (PostgreSQL on Supabase via the MySQL-style shim in `includes/db_connect.php`), vanilla JS, Chart.js 4, CSS custom properties. Tests are plain PHP scripts in `attendance-system/tests/` printing `PASS`/`FAIL`.

**Spec:** `docs/superpowers/specs/2026-10-11-excused-status-design.md`

## Global Constraints

- Status value is exactly `'Excused'` (capital E); allowed set `'Present','Late','Absent','Excused'`. Constraint name `attendance_records_status_check`.
- Only `'Absent'` can change, only to `'Present'` or `'Excused'`, only once, only by the class's own teacher (`ts.teacher_id`).
- Reason 3-255 characters, counted with `mb_strlen`.
- Excused counts as neither attended nor absent: rate = (Present+Late) / (Present+Late+Absent). `attendance_standing()` stays unchanged.
- "A check-in/scan" in SQL is written `status IN ("Present", "Late")` inside a single-quoted PHP string (the file style; the shim rewrites double-quoted literals) or `status IN ('Present', 'Late')` inside a double-quoted one: **the SQL quotes follow the enclosing PHP string**. In PHP: `in_array($status, ['Present', 'Late'], true)`. Never `<> 'Absent'` / `!== 'Absent'` for that purpose.
- Excused colour is blue/indigo from CSS tokens; no hard-coded white surfaces (`tests/theme_test.php`).
- Inline `onclick` data goes through `js_attr_json()`; every POST goes through `ajaxPost()` (CSRF).
- Never return raw exception text for DB errors: `safe_error_message($e)`.
- Live database writes and `--db` test runs need the user's explicit OK first (user rule). SQL runs in Supabase before the code reaches `main`.
- Run commands from `attendance-system/`. Run the whole suite with:
  `for t in tests/*_test.php; do php "$t" >/dev/null 2>&1 || echo "FAILED: $t"; done` (no output = all passed).
- The suite only reads files; it never loads the pages. **Every verify step also runs `php -l` on each PHP file the task touched** (expected: `No syntax errors detected` for each).

## Review Focus

0. **Pages that only break when loaded:** a quoting slip inside a SQL string is invisible to the source-reading tests. Expected: every touched file passes `php -l`. Test: the `php -l` line in every task's verify step.
1. **Double submit / two tabs:** the teacher clicks Excuse twice, or excuses in one tab and Mark present in another. Expected: the second call throws "This record was already changed. Reload the page." and the record keeps the first change. Test: Task 3 `--db` step.
2. **A name with an apostrophe in the button** (D'Souza, Women's Health). Expected: both buttons still open the modal. Test: Task 4 source check that `teacher/history.php` uses `js_attr_json(` and no longer `e(json_encode(`.
3. **A hand-crafted POST** with `target=Late`, `target=excused`, `target=` or `target[]=x`. Expected: refused with a JSON error, never a 500 or a status change. Test: Task 1 unit checks on `override_problem()`, plus the endpoint's non-string guard (Task 1 Step 5).
4. **Excused rows in exports and the time column.** Expected: the PDF/Excel row reads "Oct 07, 2026 · excused", status "Excused", coloured. Test: Task 1 `format_record_time` check; Task 6 source check for `.status-Excused` in both export stylesheets.
5. **The roster for a student with no records yet** (`attendance_standing(0, 0, $limits)` fallback has no `excused` key). Expected: no PHP warning, nothing shown. Test: Task 5 source check that `class_view.php` reads `$st['excused'] ?? 0`.

---

### Task 1: Data and rules (SQL, override target, endpoint, time display)

**Files:**
- Create: `attendance-system/database/supabase_excused_status.sql`
- Modify: `attendance-system/database/supabase_schema.sql` (`attendance_records.status` CHECK, ~line 226)
- Modify: `attendance-system/includes/attendance_override.php` (whole file, 64 lines)
- Modify: `attendance-system/teacher/ajax_attendance_override.php:11-18`
- Modify: `attendance-system/includes/functions.php:167-172` (`format_record_time`)
- Test: `attendance-system/tests/attendance_override_test.php`

**Interfaces:**
- Produces:
  - `const OVERRIDE_TARGETS = ['Present', 'Excused'];`
  - `override_problem(?array $record, int $teacherId, string $reason, string $target = 'Present'): string`, where `''` means allowed.
  - `override_absent(PDO $pdo, int $recordId, string $target, int $teacherId, int $teacherUserId, string $reason): string` returns the student's full name and throws `Exception` with a user-facing message. It replaces `override_absent_to_present()`, which is deleted.
  - POST `teacher/ajax_attendance_override.php` fields `record_id`, `reason`, `target` (optional, default `Present`).
  - `format_record_time(['status' => 'Excused', ...])` returns `"<M d, Y> · excused"`.

- [ ] **Step 1: Add failing checks to `tests/attendance_override_test.php`** (before the final summary lines)

```php
check('Absent -> Excused allowed', override_problem($abs, 7, 'Medical certificate', 'Excused') === '');
check('three-argument call still means Present', override_problem($abs, 7, 'reason') === override_problem($abs, 7, 'reason', 'Present'));
foreach (['Late', 'Absent', 'excused', 'EXCUSED', '', 'Present ', 'Excused;'] as $t) {
    check("target '$t' is refused", override_problem($abs, 7, 'reason', $t) !== '');
}
foreach (['Present', 'Late', 'Excused'] as $s) {
    check("$s record cannot be excused", override_problem(['status' => $s, 'teacher_id' => 7], 7, 'reason', 'Excused') !== '');
}
check('Excused record cannot become Present', override_problem(['status' => 'Excused', 'teacher_id' => 7], 7, 'reason', 'Present') !== '');
check("someone else's class is refused for Excused too", override_problem($abs, 8, 'reason', 'Excused') === override_problem(null, 7, 'reason', 'Excused'));
$exc = ['time_in' => '2026-10-07 11:00:00', 'status' => 'Excused', 'marked_by_user_id' => 5];
check('an Excused row says excused', format_record_time($exc) === 'Oct 07, 2026 · excused');
check('an Excused row shows no check-in time', strpos(format_record_time($exc), '11:00') === false);
check('override_absent_to_present() is gone', !function_exists('override_absent_to_present'));
check('override_absent() exists', function_exists('override_absent'));
check('endpoint refuses a non-string target', strpos(file_get_contents(__DIR__ . '/../teacher/ajax_attendance_override.php'), 'is_string($target)') !== false);
```

- [ ] **Step 2: Run it and confirm the new checks fail**

Run: `php tests/attendance_override_test.php`
Expected: the new target/Excused/format checks print `FAIL`, the exit code is 1, and the old checks still `PASS`.

- [ ] **Step 3: Write the SQL file and the schema CHECK**

`database/supabase_excused_status.sql`: a comment header in the style of `supabase_scan_device.sql` ("Safe to run more than once. Run in the Supabase SQL Editor BEFORE deploying the code that uses it."), then exactly:

```sql
ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS attendance_records_status_check;
ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_check
    CHECK (status IN ('Present','Late','Absent','Excused'));
```

In `supabase_schema.sql`, change the column to `CHECK (status IN ('Present','Late','Absent','Excused'))`.

- [ ] **Step 4: Implement the rules in `includes/attendance_override.php`**

- Update the header comment: Absent -> Present or Excused, one-way.
- `override_problem` keeps today's checks and their order. Add a target check (`!in_array($target, OVERRIDE_TARGETS, true)` gives `'Invalid change.'`) right after the not-found check. The not-Absent message becomes `'Only an Absent record can be changed.'`.
- `override_absent`: today's body, plus:
  - `SET status = ?` (bound to `$target`), still `WHERE record_id = ? AND status = 'Absent'`.
  - The notification text says "changed to $target".
  - The log line says "from Absent to $target".

- [ ] **Step 5: Endpoint and time display**

- `teacher/ajax_attendance_override.php`:
  - `$target = $_POST['target'] ?? 'Present'; if (!is_string($target)) $target = '';`, with no trimming or case change.
  - Call `override_absent($pdo, $recordId, $target, ...)`.
  - Message: `"$name is now marked $target."`.
  - Update the header comment.
- `format_record_time`: add `if (($r['status'] ?? '') === 'Excused') return format_date($r['time_in']) . ' · excused';` right after the Absent line, and update its docblock.

- [ ] **Step 6: Run the test, then the suite**

Run: `php tests/attendance_override_test.php`. Expected: `All passed`.
Then run the suite command from Global Constraints. Expected: no output.

- [ ] **Step 7: Commit**

```bash
git add database/supabase_excused_status.sql database/supabase_schema.sql includes/attendance_override.php teacher/ajax_attendance_override.php includes/functions.php tests/attendance_override_test.php
git commit -m "Let a teacher change an Absent record to Excused"
```

---

### Task 2: "Real scans" mean Present or Late

**Files:**
- Create: `attendance-system/tests/excused_status_test.php` (source checks; Task 3 adds its `--db` part)
- Modify: `admin/dashboard.php:23,72`, `admin/qr_management.php:30`, `student/my_subjects.php:18`, `teacher/class_view.php:67`, `teacher/dashboard.php:41`, `teacher/ajax_session_status.php:35,42,48`

**Interfaces:**
- Produces: `tests/excused_status_test.php` with the same `check()` helper and summary/exit code as `tests/attendance_override_test.php`, and a `$useDb = in_array('--db', $argv, true);` flag that is read but unused until Task 3.

- [ ] **Step 1: Write the failing source check**

Scan every `*.php` under `attendance-system/` except `tests/` and `includes/attendance_override.php` (use `RecursiveDirectoryIterator`).
That file is exempt because its `!== 'Absent'` is the rule "only an Absent record can change", not a check-in count. Say so in a
comment next to the exemption. For each line matching
`/<>\s*["\']Absent["\']|!==?\s*["\']Absent["\']/`, collect `file:line`. Then:

```php
check('no page treats "not Absent" as a check-in (' . implode(', ', $hits) . ')', !$hits);
$live = file_get_contents(__DIR__ . '/../teacher/ajax_session_status.php');
check('live list counts only Present/Late as scanned', strpos($live, "['Pending', 'Absent']") === false);
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php tests/excused_status_test.php`
Expected: `FAIL` listing 7 hits (dashboard ×2, qr_management, my_subjects, class_view, teacher dashboard, ajax_session_status:42), and `FAIL` for the live list.

- [ ] **Step 3: Rewrite each query and check**

Fix **every** hit the scan reports, not only the ones listed above (the list came from a filtered grep).
- SQL: `status <> "Absent"` becomes `status IN ("Present", "Late")` (all inside single-quoted PHP strings; see Global Constraints), keeping the `ar.` prefix where present.
- `ajax_session_status.php`:
  - Line 35: `ORDER BY (ar.time_in IS NULL OR ar.status NOT IN ("Present", "Late"))` (single-quoted PHP string).
  - Line 42: the time is shown only when `in_array($r['status'], ['Present', 'Late'], true)`.
  - Line 48: `$scannedCount` counts `in_array($r['status'], ['Present', 'Late'], true)`.

- [ ] **Step 4: Run the test and the suite**

Run: `php tests/excused_status_test.php` (expected: `All passed`), `php -l` on the 6 touched pages (expected: no syntax errors), then the suite (expected: no output).

- [ ] **Step 5: Commit**

```bash
git add tests/excused_status_test.php admin/dashboard.php admin/qr_management.php student/my_subjects.php teacher/class_view.php teacher/dashboard.php teacher/ajax_session_status.php
git commit -m "Count only Present and Late as check-ins"
```

---

### Task 3: Excused counts in the stats

**Files:**
- Modify: `attendance-system/includes/attendance_stats.php:72-120` (`student_class_standings`, `class_student_standings`) and its header comment (lines 7-9)
- Test: `attendance-system/tests/excused_status_test.php` (`--db` section)

**Interfaces:**
- Consumes: `override_absent()` from Task 1.
- Produces:
  - `student_class_standings()` rows gain `excused` (int).
  - `class_student_standings()` values are `attendance_standing(...) + ['excused' => int]`.
  - `teacher_students_at_risk()` is unchanged (the watchlist shows no excused count).

- [ ] **Step 1: Write the `--db` section** (it runs only with `--db`, inside `$pdo->beginTransaction()` ... `finally { $pdo->rollBack(); }`, like `tests/device_check_test.php`)

- Pick an existing Absent record of an enrolled student (live data had 77 on 2026-10-11):
  `SELECT ar.record_id, ar.student_id, s.teacher_subject_id, ts.teacher_id FROM attendance_records ar JOIN attendance_sessions s ON s.session_id = ar.session_id JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id JOIN enrollments e ON e.student_id = ar.student_id AND e.teacher_subject_id = s.teacher_subject_id AND e.status = 'enrolled' WHERE ar.status = 'Absent' LIMIT 1`
- No row: `check('found an Absent record to excuse', false)` and skip the rest. That is an explicit FAIL, not a crash.
- `$standingRow = fn() => current(array_filter(student_class_standings($pdo, $studentId), fn($r) => (int) $r['teacher_subject_id'] === $classId));`, then `$before = $standingRow();`.
- Get the class teacher's user id: `SELECT user_id FROM teachers WHERE teacher_id = ?` (the class's `teacher_id`).

```php
$name = override_absent($pdo, $recordId, 'Excused', $teacherId, $teacherUserId, 'Medical certificate shown');
check('override_absent returns the student name', $name !== '');
$rec = $pdo->query("SELECT status, marked_by_user_id, override_reason FROM attendance_records WHERE record_id = $recordId")->fetch();
check('record is Excused with who/why', $rec['status'] === 'Excused' && (int) $rec['marked_by_user_id'] === $teacherUserId && $rec['override_reason'] === 'Medical certificate shown');
$after = $standingRow();
check('excusing lowers the absence count by 1', $after['standing']['absent'] === $before['standing']['absent'] - 1);
check('excused count went up by 1', $after['excused'] === $before['excused'] + 1);
check('the excused meeting drops out of the meeting count', $after['standing']['meetings'] === $before['standing']['meetings'] - 1);
$threw = false; try { override_absent($pdo, $recordId, 'Present', $teacherId, $teacherUserId, 'second click'); } catch (Exception $e) { $threw = true; }
check('a second change of the same record is refused', $threw);
check('class roster map carries excused', (class_student_standings($pdo, $classId)[$studentId]['excused'] ?? null) === $after['excused']);
```

- [ ] **Step 2: Implement the counts, and let `override_absent()` join an open transaction**

`override_absent()` opens its own transaction, and PDO refuses a nested `beginTransaction()`. So it should call
`beginTransaction()`/`commit()`/`rollBack()` only when `!$pdo->inTransaction()` was true on entry. When it joins
the caller's transaction, it rethrows and leaves the rollback to the caller. Without this, the rolled-back `--db`
test cannot call it.

Add `COUNT(ar.record_id) FILTER (WHERE ar.status = 'Excused') AS excused` (and the `COUNT(*)` form in `class_student_standings`). Cast to int in PHP. Update the header comment: Excused records count as neither attended nor absent.

- [ ] **Step 3: Verify without the database**

Run: `php tests/excused_status_test.php` (expected: `All passed`, no DB section) and `php tests/attendance_override_test.php` (expected: `All passed`).
The `--db` run happens in Task 7, after the user's OK and after the live SQL.

- [ ] **Step 4: Commit**

```bash
git add includes/attendance_stats.php includes/attendance_override.php tests/excused_status_test.php
git commit -m "Report excused counts beside attendance standings"
```

---

### Task 4: Theme colour and the teacher's Excuse button

**Files:**
- Modify: `attendance-system/assets/css/style.css`. The tokens go in `:root` (~line 63) and `:root[data-theme="dark"]` (~line 843). The badge goes after line 280, and the dot after line 411.
- Modify: `attendance-system/assets/js/theme.js:106-112`
- Modify: `attendance-system/teacher/history.php:108-170`
- Test: `attendance-system/tests/excused_status_test.php` (source checks)

**Interfaces:**
- Produces:
  - CSS classes `.badge-excused` and `.dot-blue`.
  - `themeChartDefaults()` returns `excused` and `excusedFill`.
  - JS `openOverride(recordId, who, target)`, where `target` is `'Present'` or `'Excused'`.

- [ ] **Step 1: Write failing source checks**

```php
$css = file_get_contents(__DIR__ . '/../assets/css/style.css');
check('badge-excused style exists', strpos($css, '.badge-excused{') !== false);
check('dot-blue style exists', strpos($css, '.dot-blue{') !== false);
check('excused tokens defined in light and dark', substr_count($css, '--excused-fg:') === 2 && substr_count($css, '--chart-excused:') === 2);
check('chart helper returns excused colours', strpos(file_get_contents(__DIR__ . '/../assets/js/theme.js'), 'excusedFill') !== false);
$hist = file_get_contents(__DIR__ . '/../teacher/history.php');
check('history offers Excuse', strpos($hist, "'Excused')") !== false);
check('history buttons use js_attr_json', strpos($hist, 'js_attr_json(') !== false && strpos($hist, 'e(json_encode(') === false);
check('history posts the target', strpos($hist, 'target') !== false);
```

- [ ] **Step 2: Run it and confirm the new checks fail**

Run: `php tests/excused_status_test.php`. Expected: 7 `FAIL`.

- [ ] **Step 3: Colours**

| Token | Light | Dark |
|---|---|---|
| `--excused-100` | `#e0e7ff` | `rgba(129,140,248,.18)` |
| `--excused-fg` | `#4338ca` | `#a5b4fc` |
| `--chart-excused` | `#6366f1` | `#818cf8` |
| `--chart-excused-fill` | `rgba(99,102,241,.10)` | `rgba(129,140,248,.12)` |

- `.badge-excused{background:var(--excused-100);color:var(--excused-fg)}`
- `.dot-blue{background:var(--chart-excused)}`
- `theme.js`: `excused: v('--chart-excused'), excusedFill: v('--chart-excused-fill')`.

- [ ] **Step 4: Teacher history button and modal**

- An Absent row shows two buttons:
  - `openOverride(<id>, <?php echo js_attr_json($who); ?>, 'Present')`, labelled "Mark present", icon `fa-check`.
  - `openOverride(..., 'Excused')`, labelled "Excuse", icon `fa-file-medical`.
  - `$who` is the same string as today.
- The modal keeps its ids. `openOverride` sets the title, explanation, placeholder and submit label from `target`, using the spec's exact copy (spec Part 2).
- Present mode gains "This can't be undone." at the end of its text.
- `submitOverride()` sends `{ record_id, reason, target }`.

- [ ] **Step 5: Run the test and the suite** (`php tests/excused_status_test.php`, expected `All passed`; then the suite, including `theme_test.php` and `inline_handler_test.php`, expected no output)

- [ ] **Step 6: Commit**

```bash
git add assets/css/style.css assets/js/theme.js teacher/history.php tests/excused_status_test.php
git commit -m "Add the Excuse button and the Excused colour"
```

---

### Task 5: Student dashboard and teacher roster show excused counts

**Files:**
- Modify: `attendance-system/student/dashboard.php:22` (counts default), `:79` (Absent card), `:101` (class row meta)
- Modify: `attendance-system/teacher/class_view.php:174,181` (roster Absences cell)
- Test: `attendance-system/tests/excused_status_test.php`

**Interfaces:**
- Consumes: `excused` on `student_class_standings()` rows and on `class_student_standings()` values (Task 3).

- [ ] **Step 1: Write failing source checks**

```php
$sd = file_get_contents(__DIR__ . '/../student/dashboard.php');
check("student dashboard defaults an Excused count", strpos($sd, "'Excused' => 0") !== false);
check('student class rows mention excused', strpos($sd, "' excused'") !== false);
check('roster reads excused safely', strpos(file_get_contents(__DIR__ . '/../teacher/class_view.php'), "\$st['excused'] ?? 0") !== false);
```

- [ ] **Step 2: Run it and confirm it fails** (3 `FAIL`)

- [ ] **Step 3: Implement**

- `$counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Excused' => 0];`. `$meetings` and `$overallRate` stay as they are.
- On the Absent card, under the value and only when `$counts['Excused'] > 0`, add `<small class="text-muted">N excused</small>`.
- On the class row meta, after the absence label: `· N excused` when `$c['excused'] > 0`. The literal is `' excused'`.
- On the roster Absences cell: `<?php $exc = (int) ($st['excused'] ?? 0); ?>` and, when `$exc > 0`, append `<small class="text-muted"> · <?php echo $exc; ?> excused</small>`.

- [ ] **Step 4: Run the test and the suite.** Expected: `All passed`, and no output from the suite.

- [ ] **Step 5: Commit**

```bash
git add student/dashboard.php teacher/class_view.php tests/excused_status_test.php
git commit -m "Show excused counts on the student dashboard and class roster"
```

---

### Task 6: Admin pages and exports

**Files:**
- Modify: `admin/dashboard.php`:
  - lines 40-58: the trend query and arrays
  - lines 63-65: `$statusCounts`
  - lines 170-175: the recent-activity branch
  - lines 303-336: the chart data and datasets
- Modify: `admin/reports.php`:
  - line 36: the counts
  - line 43: the per-subject SUM
  - lines 86-91: the stat cards
  - lines 130-158: the chart data and datasets
- Modify: `admin/attendance_monitoring.php:14,79`
- Modify: `includes/export.php:12,35,65-67`, `admin/export_pdf.php:56-58`
- Test: `attendance-system/tests/excused_status_test.php`

**Interfaces:**
- Consumes: `c.excused` and `c.excusedFill` (Task 4), `.dot-blue` (Task 4).

- [ ] **Step 1: Write failing source checks**

```php
$ad = file_get_contents(__DIR__ . '/../admin/dashboard.php');
check('admin recent activity has an excused sentence', strpos($ad, 'was excused from') !== false);
check('admin charts plot Excused', strpos($ad, "label: 'Excused'") !== false && strpos($ad, "'Present','Late','Absent','Excused'") !== false);
$rp = file_get_contents(__DIR__ . '/../admin/reports.php');
check('reports count and plot Excused', strpos($rp, "'Excused' => 0") !== false && strpos($rp, "SUM(ar.status='Excused') AS excused") !== false && strpos($rp, "label:'Excused'") !== false);
check('monitoring filter accepts Excused', strpos(file_get_contents(__DIR__ . '/../admin/attendance_monitoring.php'), "['Present', 'Late', 'Absent', 'Excused']") !== false);
foreach (['includes/export.php', 'admin/export_pdf.php'] as $f) {
    check("$f colours Excused", strpos(file_get_contents(__DIR__ . "/../$f"), '.status-Excused{') !== false);
}
check('export colour list includes Excused', strpos(file_get_contents(__DIR__ . '/../includes/export.php'), "['Present', 'Late', 'Absent', 'Excused']") !== false);
```

- [ ] **Step 2: Run it and confirm it fails** (7 `FAIL`)

- [ ] **Step 3: Implement**

- **Admin dashboard:**
  - Trend query gets `SUM(status = "Excused") AS excused` and a `$trendExcused` array.
  - `$statusCounts` gets an `'Excused' => 0` key.
  - Recent activity: the dot map gets `'Excused' => 'dot-blue'`, and a new `elseif ($r['status'] === 'Excused')` branch reads "<name> was excused from <subject> · <M d>", placed before the generic branch.
  - Trend dataset `{ label: 'Excused', data: trendData.excused, borderColor: c.excused, backgroundColor: c.excusedFill, tension:.35, fill:true }`.
  - Doughnut: labels `['Present','Late','Absent','Excused']`, colours `[c.green, c.amber, c.red, c.excused]`.
- **Reports:**
  - `'Excused' => 0` in the counts and `SUM(ar.status='Excused') AS excused` per subject.
  - A 5th stat card using the existing card markup: `stat-icon blue`, icon `fa-file-medical`, label "Excused".
  - `subjectData.excused`, a bar dataset `{ label:'Excused', data: subjectData.excused, backgroundColor: c.excused }`, and a doughnut with the 4th label and colour.
- **Monitoring:** `'Excused'` in the allow-list and an `<option value="Excused">` after Absent.
- **Exports:**
  - `'Excused'` in the colour list and the comment on line 12.
  - `.status-Excused{color:#3730a3;font-weight:bold}` in both stylesheets.

- [ ] **Step 4: Run the test and the suite** (`All passed`, no output)

- [ ] **Step 5: Commit**

```bash
git add admin/dashboard.php admin/reports.php admin/attendance_monitoring.php includes/export.php admin/export_pdf.php tests/excused_status_test.php
git commit -m "Show Excused on the admin dashboard, reports, monitoring and exports"
```

---

### Task 7: Docs, live rollout and verification

**Files:**
- Modify: `CLAUDE.md`:
  - Rewrite the "Teacher override" bullet (Absent -> Present or Excused; `override_absent()`; one-way).
  - **Rewrite the two lines that prescribe the old pattern**: the Absences bullet ("exclude them (`status <> 'Absent'`) from any count of check-ins") and the Admin dashboard bullet ("counts scans only (`ar.status <> "Absent"`)"). Both become `status IN ("Present", "Late")`, and the Absences bullet says Excused rows are excluded too.
  - Add a bullet for the check-in rule, guarded by `tests/excused_status_test.php`.
  - Add `supabase_excused_status.sql` to the database list.
- Modify (outside the repo, no secrets): `C:\Users\Jezreel\OneDrive\Desktop\QR-Attendance-System-Notes.txt`. Update the override section, statuses, and the "Files already run" list.

- [ ] **Step 1: Update both docs and commit `CLAUDE.md`**

```bash
git add ../CLAUDE.md
git commit -m "Document the Excused status"
```

- [ ] **Step 2: Ask the user for OK, then run the SQL on live Supabase** (project `udzmzsrikfshighwhqdm`, migration name `excused_status`, the contents of `supabase_excused_status.sql`)

Verify: `SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'attendance_records_status_check';` includes `'Excused'`.

- [ ] **Step 3: With the same OK, run the `--db` checks** (export `DB_*` from `.env` first; see the memory note `db-tests-need-env-export`)

Run: `php tests/excused_status_test.php --db`. Expected: `All passed`.
Then verify nothing was left behind: `SELECT count(*) FROM attendance_records WHERE status = 'Excused'` returns 0.

- [ ] **Step 4: Full suite, push, update PR #30**

Run the suite (expected: no output). Then `git push qr feature/excused-status`, and edit the PR #30 body: no longer spec-only; list the SQL as already applied.

- [ ] **Step 5: Browser check after merge/deploy (with the user)**

Check these as a teacher, a student and an admin, in light and dark themes:
- Excuse an Absent record.
- Check the badge and tooltip, the student's dashboard rate/absences, the roster note, the admin recent activity, the charts, the reports card, the monitoring filter, and the PDF/Excel export row.
