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
 * the radius it had, as a record of what applied.
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
    if (get_occurrence_status($occ, $now) !== OCCURRENCE_ACTIVE) {
        return find_session_for_occurrence($pdo, $occ);
    }
    $lab = $pdo->prepare('SELECT latitude, longitude, allowed_radius_meters FROM laboratories WHERE lab_id = ?');
    $lab->execute([$occ['lab_id']]);
    $lab = $lab->fetch();

    if ($existing = find_session_for_occurrence($pdo, $occ)) {
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

/** The scheduled meeting a session belongs to (null if it is no longer on the schedule). */
function find_occurrence_for_session(PDO $pdo, array $session): ?array {
    foreach (get_occurrences($pdo, $session['session_date'], $session['session_date'], ['teacher_subject_id' => $session['teacher_subject_id']]) as $o) {
        if ($o['starts_at'] === substr($session['scheduled_start'], 0, 19)) return $o;
    }
    return null;
}

/** True when a closed session may be reopened: its meeting is still ACTIVE (not over, not cancelled). */
function can_reopen_session(PDO $pdo, array $session, $now = null): bool {
    if ((int) $session['is_active'] === 1) return false;
    $occ = find_occurrence_for_session($pdo, $session);
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

    $occ = find_occurrence_for_session($pdo, $session);
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
