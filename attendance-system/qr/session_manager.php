<?php
/**
 * ------------------------------------------------------------
 * qr/session_manager.php
 * Attendance sessions follow the class schedule automatically.
 *
 * A session belongs to ONE meeting ("occurrence", from
 * includes/schedule.php): same class, session_date = the occurrence's
 * date, scheduled_start = its start. ensure_session_for_occurrence()
 * opens it the first time anyone (teacher page, admin page, a scan)
 * looks while the meeting is ACTIVE, with a fresh random QR token and
 * session_end (the session's "expires at") = the meeting's end time.
 * Nobody opens attendance by hand any more; a teacher or admin can
 * close it early and reopen it again (same QR token, so a QR code that
 * is already on screen works again) as long as the meeting is still
 * ACTIVE. Once the meeting has ended or been cancelled it stays closed.
 *
 * An open session's geofence radius follows its laboratory's current
 * allowed_radius_meters (synced on every look); a closed session keeps
 * the radius it had, as a record of what applied. A session that has not
 * ended yet also follows its meeting's end time (sync_session_end()), so
 * editing a class's times while attendance is open moves the session's end
 * (and so the moment its absences are marked) with it. If the START moved,
 * reattach_moved_sessions() moves the running session to the meeting (same
 * QR) instead of opening a second one, and re-checks its scans for Late.
 *
 * Automatically created sessions have created_by_user_id = NULL and
 * activated_by = the teacher of that meeting (a substitute if one was
 * set by a schedule exception).
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/qr_helper.php';
require_once __DIR__ . '/../includes/schedule.php';

/**
 * Minutes after the start time before a scan counts as Late: the class's own value
 * (teacher_subjects.late_grace_minutes, Admin > Class Assignments) or, when that is empty,
 * the default from Admin > Settings (settings.late_grace_minutes, 15 if unset).
 * Pass the meeting's 'late_grace_minutes' (from includes/schedule.php) as $classGrace.
 */
function get_late_grace_minutes(PDO $pdo, $classGrace = null): int {
    $minutes = ($classGrace !== null && $classGrace !== '') ? (int) $classGrace : get_setting_int($pdo, 'late_grace_minutes', 15);
    return max(0, min(180, $minutes));
}

/** The session (open or closed) for this meeting, or null. */
function find_session_for_occurrence(PDO $pdo, array $occ): ?array {
    $stmt = $pdo->prepare('
        SELECT * FROM attendance_sessions
        WHERE teacher_subject_id = ? AND session_date = ? AND scheduled_start = ?
        ORDER BY session_id LIMIT 1
    ');
    $stmt->execute([$occ['teacher_subject_id'], $occ['date'], $occ['starts_at']]);
    return $stmt->fetch() ?: null;
}

/**
 * If the meeting is ACTIVE and has no session yet, create one; return the
 * meeting's session (open or closed) or null. Nothing is created when the
 * meeting isn't ACTIVE (upcoming, over or cancelled) or its laboratory has
 * no GPS coordinates (attendance couldn't be geofenced).
 */
function ensure_session_for_occurrence(PDO $pdo, array $occ, $now = null): ?array {
    $found = find_session_for_occurrence($pdo, $occ);
    if (!$found && reattach_moved_sessions($pdo, (int) $occ['teacher_subject_id'], $occ['date'], $now)) {
        $found = find_session_for_occurrence($pdo, $occ);   // the class's start moved: it has its running session back
    }
    if (get_occurrence_status($occ, $now) !== OCCURRENCE_ACTIVE) {
        return $found ? sync_session_end($pdo, $found, $occ, $now) : null;
    }
    $lab = $pdo->prepare('SELECT latitude, longitude, allowed_radius_meters FROM laboratories WHERE lab_id = ?');
    $lab->execute([$occ['lab_id']]);
    $lab = $lab->fetch();

    if ($existing = $found) {
        $existing = sync_session_end($pdo, $existing, $occ, $now);
        // An open session follows its laboratory's current radius and its class's current late grace,
        // so editing the lab or the class applies immediately (a closed session keeps what applied).
        $grace = get_late_grace_minutes($pdo, $occ['late_grace_minutes'] ?? null);
        if ($lab && (int) $existing['is_active'] === 1
            && ((int) $existing['allowed_radius_meters'] !== (int) $lab['allowed_radius_meters'] || (int) $existing['late_threshold_minutes'] !== $grace)) {
            $pdo->prepare('UPDATE attendance_sessions SET allowed_radius_meters = ?, late_threshold_minutes = ? WHERE session_id = ? AND is_active = 1')
                ->execute([(int) $lab['allowed_radius_meters'], $grace, $existing['session_id']]);
            $existing['allowed_radius_meters'] = (int) $lab['allowed_radius_meters'];
            $existing['late_threshold_minutes'] = $grace;
        }
        return $existing;
    }

    if (!$lab || $lab['latitude'] === null || $lab['longitude'] === null) return null;

    // Serialise creation per meeting so two simultaneous requests can't both insert.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['attendance-session:' . $occ['occurrence_key']]);
        $session = find_session_for_occurrence($pdo, $occ);
        $created = false;
        if (!$session) {
            $ins = $pdo->prepare("
                INSERT INTO attendance_sessions
                    (teacher_subject_id, session_date, qr_token, scheduled_start, session_end,
                     late_threshold_minutes, allowed_radius_meters, is_active, activated_by, created_by_role, created_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 'teacher', NULL)
                RETURNING *
            ");
            $ins->execute([
                $occ['teacher_subject_id'], $occ['date'], bin2hex(random_bytes(24)),
                $occ['starts_at'], $occ['ends_at'],
                get_late_grace_minutes($pdo, $occ['late_grace_minutes'] ?? null), (int) $lab['allowed_radius_meters'], $occ['teacher_id'],
            ]);
            $session = $ins->fetch();
            $created = true;
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($created) {
        $students = $pdo->prepare("
            SELECT s.user_id FROM enrollments e JOIN students s ON s.student_id = e.student_id
            WHERE e.teacher_subject_id = ? AND e.status = 'enrolled'
        ");
        $students->execute([$occ['teacher_subject_id']]);
        foreach ($students->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            create_notification($pdo, $uid, 'Attendance Open',
                "Attendance is open for {$occ['subject_code']} in {$occ['lab_name']} until " . (new DateTimeImmutable($occ['ends_at']))->format('g:i A') . ' — scan the QR code to check in.');
        }
    }
    return $session;
}

/**
 * Pure: pairs sessions whose start no longer matches any meeting of that class that day (an admin moved
 * the start while attendance was open) with that day's meetings that have no session.
 * $sessions: the class's sessions that day (session_id, scheduled_start, session_end); $occurrences: its
 * meetings that day (get_occurrences()). Only sessions that have not ended move (an ended one is a record),
 * cancelled meetings get none, and with several candidates the nearest start wins.
 * Returns [session_id => occurrence].
 */
function pair_moved_sessions(array $sessions, array $occurrences, $now = null): array {
    $now = schedule_now($now);
    $norm = fn($t) => date('Y-m-d H:i:s', strtotime($t));
    $meetingStarts = [];
    foreach ($occurrences as $o) $meetingStarts[$norm($o['starts_at'])] = true;

    $taken = [];
    $orphans = [];
    foreach ($sessions as $sess) {
        if ($sess['scheduled_start'] === null) continue;
        $start = $norm($sess['scheduled_start']);
        if (isset($meetingStarts[$start])) { $taken[$start] = true; continue; }
        if ($sess['session_end'] !== null && new DateTimeImmutable($sess['session_end'], schedule_tz()) > $now) $orphans[] = $sess;
    }
    $candidates = [];
    foreach ($orphans as $sess) {
        foreach ($occurrences as $o) {
            if (!empty($o['is_cancelled']) || isset($taken[$norm($o['starts_at'])])) continue;
            $candidates[] = [abs(strtotime($o['starts_at']) - strtotime($sess['scheduled_start'])), (int) $sess['session_id'], $o];
        }
    }
    usort($candidates, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $pairs = [];
    foreach ($candidates as [, $sessionId, $o]) {
        $key = $norm($o['starts_at']);
        if (isset($pairs[$sessionId]) || isset($taken[$key])) continue;
        $pairs[$sessionId] = $o;
        $taken[$key] = true;
    }
    return $pairs;
}

/**
 * Pure: the status a scan made with the app has for this start and grace (re-checked when the start moved).
 * Only Present/Late scans change; Absent and a teacher's manual Present (marked_by_user_id) never do.
 */
function rechecked_scan_status(array $record, string $startsAt, int $graceMinutes): string {
    if (!in_array($record['status'], ['Present', 'Late'], true) || !empty($record['marked_by_user_id'])) return $record['status'];
    $lateAfter = (new DateTimeImmutable($startsAt, schedule_tz()))->modify("+$graceMinutes minutes");
    return new DateTimeImmutable($record['time_in'], schedule_tz()) > $lateAfter ? 'Late' : 'Present';
}

/**
 * When a class's start moved while one of that day's sessions was running, move that session to the
 * meeting (pair_moved_sessions()) instead of opening a second one: same id, same QR token (the code on
 * screen keeps working), same scans. Its end follows the meeting (sync_session_end()) and its scans are
 * re-checked for Late against the new start. Returns how many sessions moved. One small query when
 * nothing is running, since pages call this for every meeting that has no session.
 */
function reattach_moved_sessions(PDO $pdo, int $classId, string $date, $now = null): int {
    $running = $pdo->prepare('SELECT 1 FROM attendance_sessions WHERE teacher_subject_id = ? AND session_date = ? AND session_end > CAST(? AS timestamp) LIMIT 1');
    $running->execute([$classId, $date, schedule_now($now)->format('Y-m-d H:i:s')]);
    if (!$running->fetchColumn()) return 0;

    $occurrences = get_occurrences($pdo, $date, $date, ['teacher_subject_id' => $classId]);
    $list = $pdo->prepare('SELECT session_id, scheduled_start, session_end FROM attendance_sessions WHERE teacher_subject_id = ? AND session_date = ?');
    $list->execute([$classId, $date]);
    $pairs = pair_moved_sessions($list->fetchAll(), $occurrences, $now);
    if (!$pairs) return 0;

    $moved = 0;
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        foreach ($pairs as $sessionId => $occ) {
            // Same lock as session creation, so no page opens a second session for this meeting meanwhile.
            $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['attendance-session:' . $occ['occurrence_key']]);
            if (find_session_for_occurrence($pdo, $occ)) continue;   // another request was first
            $upd = $pdo->prepare('UPDATE attendance_sessions SET scheduled_start = ? WHERE session_id = ? RETURNING *');
            $upd->execute([$occ['starts_at'], $sessionId]);
            if (!$session = $upd->fetch()) continue;
            sync_session_end($pdo, $session, $occ, $now);

            $grace = get_late_grace_minutes($pdo, $occ['late_grace_minutes'] ?? null);
            $recs = $pdo->prepare("SELECT record_id, status, time_in, marked_by_user_id FROM attendance_records WHERE session_id = ? AND status IN ('Present', 'Late')");
            $recs->execute([$sessionId]);
            $set = $pdo->prepare('UPDATE attendance_records SET status = ? WHERE record_id = ?');
            foreach ($recs->fetchAll() as $r) {
                $status = rechecked_scan_status($r, $occ['starts_at'], $grace);
                if ($status !== $r['status']) $set->execute([$status, $r['record_id']]);
            }
            $moved++;
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $moved;
}

/**
 * Pure: how a session that has not ended yet must change when its meeting's end time changed
 * (the class was edited while attendance was open). null = no change, else ['end' => 'Y-m-d H:i:s', 'close' => bool].
 * - meeting now ends later, or earlier but still in the future: the session ends with the meeting;
 * - meeting already over: close it and end it one minute from now, NOT at the meeting's end. Absences
 *   are added for sessions whose end is after the point the absence run already reached
 *   (includes/absences.php); an end in the past would never be marked.
 * A session whose end has passed is a record and stays as it is.
 */
function session_end_change(?string $sessionEnd, string $meetingEnd, $now = null): ?array {
    if ($sessionEnd === null) return null;
    $now = schedule_now($now);
    $current = new DateTimeImmutable($sessionEnd, schedule_tz());
    $target = new DateTimeImmutable($meetingEnd, schedule_tz());
    if ($current <= $now || $current == $target) return null;
    if ($target > $now) return ['end' => $target->format('Y-m-d H:i:s'), 'close' => false];
    return ['end' => $now->modify('+1 minute')->format('Y-m-d H:i:s'), 'close' => true];
}

/** Apply session_end_change() to a session of this meeting (open or closed by hand); returns the session as saved. */
function sync_session_end(PDO $pdo, array $session, array $occ, $now = null): array {
    if (!empty($occ['is_cancelled'])) return $session;
    $change = session_end_change($session['session_end'] ?? null, $occ['ends_at'], $now);
    if (!$change) return $session;
    if ($change['close']) {
        $pdo->prepare('UPDATE attendance_sessions SET session_end = ?, is_active = 0, deactivated_at = COALESCE(deactivated_at, NOW()) WHERE session_id = ?')
            ->execute([$change['end'], $session['session_id']]);
        $session['is_active'] = 0;
        $session['deactivated_at'] = $session['deactivated_at'] ?? schedule_now($now)->format('Y-m-d H:i:s');
    } else {
        $pdo->prepare('UPDATE attendance_sessions SET session_end = ? WHERE session_id = ?')->execute([$change['end'], $session['session_id']]);
    }
    $session['session_end'] = $change['end'];
    return $session;
}

/**
 * Today's meetings (same filters as get_occurrences()), ensuring a session for
 * every ACTIVE one. Returns a list of ['occurrence', 'status', 'session'].
 */
function ensure_sessions_for_today(PDO $pdo, array $filters = [], $now = null): array {
    $now = schedule_now($now);
    $out = [];
    foreach (get_todays_occurrences($pdo, $filters, $now) as $occ) {
        $out[] = [
            'occurrence' => $occ,
            'status'     => get_occurrence_status($occ, $now),
            'session'    => ensure_session_for_occurrence($pdo, $occ, $now),
        ];
    }
    return $out;
}

/** Seconds until the next start or end among these meetings (for auto-refreshing pages), or null. */
function seconds_until_next_change(array $occurrences, $now = null): ?int {
    $now = schedule_now($now);
    $next = null;
    foreach ($occurrences as $occ) {
        foreach (['starts_at', 'ends_at'] as $edge) {
            $t = new DateTimeImmutable($occ[$edge], schedule_tz());
            if ($t > $now && ($next === null || $t < $next)) $next = $t;
        }
    }
    return $next ? $next->getTimestamp() - $now->getTimestamp() : null;
}

/** Close a session early (teacher or admin). It can be reopened while its meeting is still ACTIVE. */
function deactivate_attendance_session_by_id(PDO $pdo, $sessionId) {
    $upd = $pdo->prepare('UPDATE attendance_sessions SET is_active = 0, deactivated_at = NOW() WHERE session_id = ? AND is_active = 1');
    $upd->execute([$sessionId]);
    return $upd->rowCount() > 0;
}

/**
 * The scheduled meeting a session belongs to (null if it is no longer on the schedule). If the class's
 * start moved while the session was running, the session is re-attached first (reattach_moved_sessions()).
 */
function find_occurrence_for_session(PDO $pdo, array $session, $now = null): ?array {
    $find = function (string $start) use ($pdo, $session): ?array {
        foreach (get_occurrences($pdo, $session['session_date'], $session['session_date'], ['teacher_subject_id' => $session['teacher_subject_id']]) as $o) {
            if ($o['starts_at'] === substr($start, 0, 19)) return $o;
        }
        return null;
    };
    if ($occ = $find((string) $session['scheduled_start'])) return $occ;
    if (!reattach_moved_sessions($pdo, (int) $session['teacher_subject_id'], $session['session_date'], $now)) return null;
    $st = $pdo->prepare('SELECT scheduled_start FROM attendance_sessions WHERE session_id = ?');
    $st->execute([$session['session_id']]);
    $start = $st->fetchColumn();
    return $start ? $find((string) $start) : null;
}

/** True when a closed session may be reopened: its meeting is still ACTIVE (not over, not cancelled). */
function can_reopen_session(PDO $pdo, array $session, $now = null): bool {
    if ((int) $session['is_active'] === 1) return false;
    $occ = find_occurrence_for_session($pdo, $session, $now);
    return $occ && get_occurrence_status($occ, $now) === OCCURRENCE_ACTIVE;
}

/**
 * Reopen a closed session (teacher or admin) while its meeting is still ACTIVE.
 * The QR token is kept, so the code already shown to students works again.
 * Throws an Exception with a user-facing reason when it can't be reopened.
 */
function reactivate_attendance_session_by_id(PDO $pdo, $sessionId, $now = null): void {
    $stmt = $pdo->prepare('SELECT * FROM attendance_sessions WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) throw new Exception('Session not found.');
    if ((int) $session['is_active'] === 1) throw new Exception('This session is already open.');

    $occ = find_occurrence_for_session($pdo, $session, $now);
    $status = $occ ? get_occurrence_status($occ, $now) : null;
    if ($status === OCCURRENCE_CANCELLED) throw new Exception('This class meeting was cancelled, so attendance cannot be reopened.');
    if ($status !== OCCURRENCE_ACTIVE) throw new Exception('This class meeting has already ended, so attendance cannot be reopened.');

    $upd = $pdo->prepare('UPDATE attendance_sessions SET is_active = 1, deactivated_at = NULL WHERE session_id = ? AND is_active = 0 AND session_end > NOW()');
    $upd->execute([$sessionId]);
    if ($upd->rowCount() === 0) throw new Exception('This class meeting has already ended, so attendance cannot be reopened.');
}

/** Badge label + CSS class for each occurrence status. */
const OCCURRENCE_BADGES = [
    OCCURRENCE_UPCOMING  => ['Upcoming',  'badge-upcoming'],
    OCCURRENCE_ACTIVE    => ['Active',    'badge-active'],
    OCCURRENCE_EXPIRED   => ['Expired',   'badge-inactive'],
    OCCURRENCE_CANCELLED => ['Cancelled', 'badge-absent'],
];

/** One-line attendance state for an entry from ensure_sessions_for_today(). */
function attendance_state_label(array $m) {
    $o = $m['occurrence'];
    if ($m['status'] === OCCURRENCE_CANCELLED) return 'Cancelled' . ($o['exception_reason'] ? ': ' . $o['exception_reason'] : '');
    if ($m['session'] && (int) $m['session']['is_active'] === 1) return 'Attendance open until ' . date('g:i A', strtotime($m['session']['session_end']));
    if ($m['session']) return 'Attendance closed' . ($m['session']['deactivated_at'] ? ' at ' . date('g:i A', strtotime($m['session']['deactivated_at'])) : '');
    if ($m['status'] === OCCURRENCE_UPCOMING) return 'Opens automatically at ' . date('g:i A', strtotime($o['starts_at']));
    if ($m['status'] === OCCURRENCE_ACTIVE) return 'Cannot open: this laboratory has no GPS coordinates';
    return 'No attendance was taken';
}
