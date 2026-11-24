<?php
/**
 * ------------------------------------------------------------
 * includes/class_summary_mailer.php
 * Generates and sends an end-of-class attendance summary email to
 * the teacher once a class meeting ends.
 *
 * Includes:
 *   - Date and time of the class
 *   - Subject code and name, section, laboratory
 *   - Summary counts: total enrolled, present, late, absent, excused
 *   - Full rosters of students present, late, and absent
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/functions.php';

/**
 * Ensures the session_email_summaries table exists.
 * Self-healing in case migrations have not been applied manually.
 */
function ensure_email_summaries_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS session_email_summaries (
                session_id INT PRIMARY KEY REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
                teacher_id INT NOT NULL REFERENCES teachers(teacher_id) ON DELETE CASCADE,
                recipient_email VARCHAR(100) NOT NULL,
                sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                present_count INT NOT NULL DEFAULT 0,
                late_count INT NOT NULL DEFAULT 0,
                absent_count INT NOT NULL DEFAULT 0,
                excused_count INT NOT NULL DEFAULT 0
            )
        ");
        $ensured = true;
    } catch (Throwable $e) {
        error_log('ensure_email_summaries_table: ' . $e->getMessage());
    }
}

/**
 * Pure generator: builds the subject line, HTML body, and plain text content
 * for a class attendance summary email.
 *
 * @param array $session Session, subject, teacher and lab metadata
 * @param array $students Enrolled students with their recorded status and time_in
 * @return array ['subject' => string, 'html' => string, 'text' => string, 'counts' => array]
 */
function build_class_summary_email(array $session, array $students): array {
    $subjectCode = $session['subject_code'] ?? 'Subject';
    $subjectName = $session['subject_name'] ?? '';
    $section     = $session['section'] ?? '';
    $labName     = $session['lab_name'] ?? 'Laboratory';
    $teacherName = $session['teacher_name'] ?? 'Instructor';

    $classDate   = !empty($session['scheduled_start'])
        ? date('F j, Y', strtotime($session['scheduled_start']))
        : (!empty($session['session_date']) ? date('F j, Y', strtotime($session['session_date'])) : date('F j, Y'));

    $dayOfWeek   = !empty($session['scheduled_start'])
        ? date('l', strtotime($session['scheduled_start']))
        : date('l');

    $timeRange   = '';
    if (!empty($session['scheduled_start']) && !empty($session['session_end'])) {
        $timeRange = date('g:i A', strtotime($session['scheduled_start'])) . ' – ' . date('g:i A', strtotime($session['session_end']));
    }

    $presents = [];
    $lates    = [];
    $absents  = [];
    $excuseds = [];

    foreach ($students as $st) {
        $status = $st['status'] ?? 'Absent';
        if ($status === 'Present') {
            $presents[] = $st;
        } elseif ($status === 'Late') {
            $lates[] = $st;
        } elseif ($status === 'Excused') {
            $excuseds[] = $st;
        } else {
            $absents[] = $st;
        }
    }

    $totalEnrolled = count($students);
    $presentCount  = count($presents);
    $lateCount     = count($lates);
    $absentCount   = count($absents);
    $excusedCount  = count($excuseds);

    $counts = [
        'total'   => $totalEnrolled,
        'present' => $presentCount,
        'late'    => $lateCount,
        'absent'  => $absentCount,
        'excused' => $excusedCount,
    ];

    $emailSubject = "[iLABQ] Class Attendance Summary: {$subjectCode}" . ($section ? " ({$section})" : '') . " - {$classDate}";

    // -------------------------------------------------------------
    // Build HTML email
    // -------------------------------------------------------------
    $renderStudentRows = function (array $list, bool $showTime = true): string {
        if (empty($list)) return '';
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:10px 0 16px;font-size:13.5px;">';
        $out .= '<thead><tr style="background:#f1f5f9;color:#475569;text-align:left;">';
        $out .= '<th style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:700;width:38px;">#</th>';
        $out .= '<th style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:700;">Student Name</th>';
        $out .= '<th style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:700;width:120px;">ID Number</th>';
        if ($showTime) {
            $out .= '<th style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:700;width:110px;">Check-in</th>';
        }
        $out .= '</tr></thead><tbody>';

        $idx = 1;
        foreach ($list as $s) {
            $bg = ($idx % 2 === 0) ? '#f8fafc' : '#ffffff';
            $name = htmlspecialchars($s['full_name'] ?? '—', ENT_QUOTES, 'UTF-8');
            $num  = htmlspecialchars($s['student_number'] ?? '—', ENT_QUOTES, 'UTF-8');
            $time = !empty($s['time_in']) ? date('g:i A', strtotime($s['time_in'])) : '—';

            $out .= "<tr style=\"background:{$bg};\">";
            $out .= "<td style=\"padding:8px 12px;border:1px solid #e2e8f0;color:#64748b;\">{$idx}</td>";
            $out .= "<td style=\"padding:8px 12px;border:1px solid #e2e8f0;font-weight:600;color:#0f172a;\">{$name}</td>";
            $out .= "<td style=\"padding:8px 12px;border:1px solid #e2e8f0;color:#475569;font-family:monospace;\">{$num}</td>";
            if ($showTime) {
                $out .= "<td style=\"padding:8px 12px;border:1px solid #e2e8f0;color:#0f172a;\">{$time}</td>";
            }
            $out .= '</tr>';
            $idx++;
        }
        $out .= '</tbody></table>';
        return $out;
    };

    $presentRowsHtml = $renderStudentRows($presents, true);
    $lateRowsHtml    = $renderStudentRows($lates, true);
    $absentRowsHtml  = $renderStudentRows($absents, false);
    $excusedRowsHtml = $renderStudentRows($excuseds, false);

    $hSubject = htmlspecialchars("{$subjectCode} – {$subjectName}", ENT_QUOTES, 'UTF-8');
    $hSection = htmlspecialchars($section, ENT_QUOTES, 'UTF-8');
    $hLab     = htmlspecialchars($labName, ENT_QUOTES, 'UTF-8');
    $hTeacher = htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8');
    $hDate    = htmlspecialchars("{$dayOfWeek}, {$classDate}", ENT_QUOTES, 'UTF-8');
    $hTime    = htmlspecialchars($timeRange, ENT_QUOTES, 'UTF-8');
    $hEmailSubject = htmlspecialchars($emailSubject, ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html>' . "\n"
          . '<html lang="en">' . "\n"
          . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $hEmailSubject . '</title></head>' . "\n"
          . '<body style="margin:0;padding:24px 0;background-color:#f1f5f9;font-family:\'Plus Jakarta Sans\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">' . "\n"
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:0;padding:0;">' . "\n"
          . '<tr><td align="center">' . "\n"
          . '  <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(15,27,60,0.08);margin:0 16px;">' . "\n"
          . '    <tr><td style="background:#1d6bf3;padding:28px 32px;color:#ffffff;">' . "\n"
          . '      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . "\n"
          . '        <tr><td><div style="font-size:22px;font-weight:800;letter-spacing:-0.02em;color:#ffffff;line-height:1.2;">iLABQ</div><div style="font-size:12px;color:rgba(255,255,255,0.85);margin-top:2px;">Interactive Laboratory QR Code-Based Attendance and Monitoring System</div></td>' . "\n"
          . '        <td align="right" style="vertical-align:top;"><span style="display:inline-block;padding:4px 10px;background:rgba(255,255,255,0.18);border-radius:20px;font-size:12px;font-weight:600;color:#ffffff;">Class Ended</span></td></tr>' . "\n"
          . '      </table>' . "\n"
          . '      <div style="margin-top:20px;border-top:1px solid rgba(255,255,255,0.22);padding-top:16px;">' . "\n"
          . '        <div style="font-size:13px;text-transform:uppercase;letter-spacing:0.06em;color:rgba(255,255,255,0.8);font-weight:700;">End of Class Attendance Report</div>' . "\n"
          . '        <div style="font-size:20px;font-weight:800;color:#ffffff;margin-top:4px;line-height:1.3;">' . $hSubject . '</div>' . "\n"
          . '      </div>' . "\n"
          . '    </td></tr>' . "\n"
          . '    <tr><td style="padding:24px 32px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">' . "\n"
          . '      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13.5px;line-height:1.6;">' . "\n"
          . '        <tr><td width="50%" style="vertical-align:top;padding-bottom:8px;"><span style="color:#64748b;font-weight:600;">Date:</span><strong style="color:#0f172a;margin-left:4px;">' . $hDate . '</strong></td>' . "\n"
          . '            <td width="50%" style="vertical-align:top;padding-bottom:8px;"><span style="color:#64748b;font-weight:600;">Section:</span><strong style="color:#0f172a;margin-left:4px;">' . $hSection . '</strong></td></tr>' . "\n"
          . '        <tr><td width="50%" style="vertical-align:top;"><span style="color:#64748b;font-weight:600;">Schedule:</span><strong style="color:#0f172a;margin-left:4px;">' . $hTime . '</strong></td>' . "\n"
          . '            <td width="50%" style="vertical-align:top;"><span style="color:#64748b;font-weight:600;">Laboratory:</span><strong style="color:#0f172a;margin-left:4px;">' . $hLab . '</strong></td></tr>' . "\n"
          . '      </table>' . "\n"
          . '    </td></tr>' . "\n"
          . '    <tr><td style="padding:28px 32px;">' . "\n"
          . '      <p style="margin:0 0 20px;font-size:14px;color:#334155;line-height:1.5;">Hello <strong>' . $hTeacher . '</strong>, this is the final attendance summary for your class meeting. All scans and absences have been finalized for this session.</p>' . "\n"
          . '      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">' . "\n"
          . '        <tr>' . "\n"
          . '          <td width="25%" style="padding:4px;"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 10px;text-align:center;"><div style="font-size:24px;font-weight:800;color:#0f172a;">' . $totalEnrolled . '</div><div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-top:2px;">Enrolled</div></div></td>' . "\n"
          . '          <td width="25%" style="padding:4px;"><div style="background:#dcf6e6;border:1px solid #bbf7d0;border-radius:12px;padding:14px 10px;text-align:center;"><div style="font-size:24px;font-weight:800;color:#15803d;">' . $presentCount . '</div><div style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:0.04em;margin-top:2px;">Present</div></div></td>' . "\n"
          . '          <td width="25%" style="padding:4px;"><div style="background:#ffefd9;border:1px solid #fed7aa;border-radius:12px;padding:14px 10px;text-align:center;"><div style="font-size:24px;font-weight:800;color:#b45309;">' . $lateCount . '</div><div style="font-size:11px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:0.04em;margin-top:2px;">Late</div></div></td>' . "\n"
          . '          <td width="25%" style="padding:4px;"><div style="background:#fde4e4;border:1px solid #fecaca;border-radius:12px;padding:14px 10px;text-align:center;"><div style="font-size:24px;font-weight:800;color:#b91c1c;">' . $absentCount . '</div><div style="font-size:11px;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:0.04em;margin-top:2px;">Absent</div></div></td>' . "\n"
          . '        </tr>' . "\n"
          . '      </table>' . "\n"
          . '      <div style="margin-top:24px;"><h3 style="margin:0 0 6px;font-size:15px;color:#15803d;">Present Students (' . $presentCount . ')</h3>' . ($presentRowsHtml !== '' ? $presentRowsHtml : '<p style="margin:4px 0 16px;color:#64748b;font-size:13px;font-style:italic;">No students checked in on time.</p>') . '</div>' . "\n"
          . '      <div style="margin-top:24px;"><h3 style="margin:0 0 6px;font-size:15px;color:#b45309;">Late Students (' . $lateCount . ')</h3>' . ($lateRowsHtml !== '' ? $lateRowsHtml : '<p style="margin:4px 0 16px;color:#64748b;font-size:13px;font-style:italic;">No late students recorded.</p>') . '</div>' . "\n"
          . '      <div style="margin-top:24px;"><h3 style="margin:0 0 6px;font-size:15px;color:#b91c1c;">Absent Students (' . $absentCount . ')</h3>' . ($absentRowsHtml !== '' ? $absentRowsHtml : '<p style="margin:4px 0 16px;color:#15803d;font-size:13px;font-weight:600;">Perfect attendance — all enrolled students were present or late!</p>') . '</div>' . "\n";

    if ($excusedCount > 0) {
        $html .= '      <div style="margin-top:24px;"><h3 style="margin:0 0 6px;font-size:15px;color:#4338ca;">Excused Students (' . $excusedCount . ')</h3>' . $excusedRowsHtml . '</div>' . "\n";
    }

    $html .= '    </td></tr>' . "\n"
          . '    <tr><td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;line-height:1.5;">' . "\n"
          . '      <p style="margin:0 0 4px;">This automated summary was generated by <strong>iLABQ</strong> upon conclusion of the class session.</p>' . "\n"
          . '      <p style="margin:0;">To correct an absence or review full historical records, please sign in to your iLABQ Teacher Portal.</p>' . "\n"
          . '    </td></tr>' . "\n"
          . '  </table>' . "\n"
          . '</td></tr>' . "\n"
          . '</table>' . "\n"
          . '</body></html>';

    // -------------------------------------------------------------
    // Build Plain Text email
    // -------------------------------------------------------------
    $text = "============================================================\n"
          . "iLABQ - CLASS ATTENDANCE SUMMARY\n"
          . "Interactive Laboratory QR Code-Based Attendance and Monitoring System\n"
          . "============================================================\n\n"
          . "Subject:    {$subjectCode} - {$subjectName} ({$section})\n"
          . "Date:       {$dayOfWeek}, {$classDate}\n"
          . "Schedule:   {$timeRange}\n"
          . "Laboratory: {$labName}\n"
          . "Teacher:    {$teacherName}\n\n"
          . "------------------------------------------------------------\n"
          . "SUMMARY\n"
          . "------------------------------------------------------------\n"
          . "Total Enrolled: {$totalEnrolled}\n"
          . "Present:        {$presentCount}\n"
          . "Late:           {$lateCount}\n"
          . "Absent:         {$absentCount}\n";
    if ($excusedCount > 0) {
        $text .= "Excused:        {$excusedCount}\n";
    }
    $text .= "\n";

    $text .= "------------------------------------------------------------\n";
    $text .= "PRESENT STUDENTS ({$presentCount})\n";
    $text .= "------------------------------------------------------------\n";
    if ($presentCount === 0) {
        $text .= "(None)\n";
    } else {
        $i = 1;
        foreach ($presents as $s) {
            $t = !empty($s['time_in']) ? date('g:i A', strtotime($s['time_in'])) : '—';
            $text .= sprintf("%d. %s (%s) - %s\n", $i++, $s['full_name'], $s['student_number'], $t);
        }
    }
    $text .= "\n";

    $text .= "------------------------------------------------------------\n";
    $text .= "LATE STUDENTS ({$lateCount})\n";
    $text .= "------------------------------------------------------------\n";
    if ($lateCount === 0) {
        $text .= "(None)\n";
    } else {
        $i = 1;
        foreach ($lates as $s) {
            $t = !empty($s['time_in']) ? date('g:i A', strtotime($s['time_in'])) : '—';
            $text .= sprintf("%d. %s (%s) - %s\n", $i++, $s['full_name'], $s['student_number'], $t);
        }
    }
    $text .= "\n";

    $text .= "------------------------------------------------------------\n";
    $text .= "ABSENT STUDENTS ({$absentCount})\n";
    $text .= "------------------------------------------------------------\n";
    if ($absentCount === 0) {
        $text .= "Perfect attendance — no absent students!\n";
    } else {
        $i = 1;
        foreach ($absents as $s) {
            $text .= sprintf("%d. %s (%s)\n", $i++, $s['full_name'], $s['student_number']);
        }
    }
    $text .= "\n";

    if ($excusedCount > 0) {
        $text .= "------------------------------------------------------------\n";
        $text .= "EXCUSED STUDENTS ({$excusedCount})\n";
        $text .= "------------------------------------------------------------\n";
        $i = 1;
        foreach ($excuseds as $s) {
            $text .= sprintf("%d. %s (%s)\n", $i++, $s['full_name'], $s['student_number']);
        }
        $text .= "\n";
    }

    $text .= "---\nGenerated automatically by iLABQ upon the conclusion of class.\n";

    return [
        'subject' => $emailSubject,
        'html'    => $html,
        'text'    => $text,
        'counts'  => $counts,
    ];
}

/**
 * Sends the end-of-class attendance summary email for one specific session.
 * Idempotent: returns true immediately if the email was already sent.
 */
function send_class_summary_email(PDO $pdo, int $sessionId): bool {
    ensure_email_summaries_table($pdo);

    // Check if already sent
    $chk = $pdo->prepare('SELECT 1 FROM session_email_summaries WHERE session_id = ?');
    $chk->execute([$sessionId]);
    if ($chk->fetchColumn()) return true;

    // Fetch session, teacher, subject and lab info
    $stmt = $pdo->prepare("
        SELECT s.session_id, s.teacher_subject_id, s.session_date, s.scheduled_start, s.session_end,
               COALESCE(s.activated_by, ts.teacher_id) AS teacher_id,
               t.user_id AS teacher_user_id,
               t.full_name AS teacher_name,
               u.email AS teacher_email,
               sub.subject_code, sub.subject_name,
               ts.section, ts.program, ts.year_level,
               lab.lab_name
        FROM attendance_sessions s
        JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        LEFT JOIN laboratories lab ON lab.lab_id = ts.lab_id
        JOIN teachers t ON t.teacher_id = COALESCE(s.activated_by, ts.teacher_id)
        JOIN users u ON u.user_id = t.user_id
        WHERE s.session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) return false;

    $teacherEmail = trim((string) ($session['teacher_email'] ?? ''));
    if ($teacherEmail === '' || !filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("send_class_summary_email: session #{$sessionId} teacher has no valid email ({$teacherEmail})");
        return false;
    }

    // Fetch enrolled students and their attendance status for this session
    $enr = $pdo->prepare("
        SELECT 
            st.student_id,
            st.student_number,
            st.full_name,
            COALESCE(ar.status, 'Absent') AS status,
            ar.time_in
        FROM enrollments e
        JOIN students st ON st.student_id = e.student_id
        LEFT JOIN attendance_records ar ON ar.student_id = st.student_id AND ar.session_id = ?
        WHERE e.teacher_subject_id = ?
          AND e.status = 'enrolled'
          AND (e.enrolled_at IS NULL OR e.enrolled_at <= COALESCE(?, NOW()))
        ORDER BY 
            CASE COALESCE(ar.status, 'Absent')
                WHEN 'Present' THEN 1
                WHEN 'Late' THEN 2
                WHEN 'Absent' THEN 3
                WHEN 'Excused' THEN 4
                ELSE 5
            END,
            st.full_name ASC
    ");
    $enr->execute([$sessionId, $session['teacher_subject_id'], $session['session_end']]);
    $students = $enr->fetchAll();

    $emailData = build_class_summary_email($session, $students);
    $counts = $emailData['counts'];

    $sent = send_mail(
        $teacherEmail,
        $session['teacher_name'],
        $emailData['subject'],
        $emailData['html'],
        $emailData['text']
    );

    if ($sent) {
        $ins = $pdo->prepare("
            INSERT INTO session_email_summaries
                (session_id, teacher_id, recipient_email, present_count, late_count, absent_count, excused_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (session_id) DO NOTHING
        ");
        $ins->execute([
            $sessionId,
            $session['teacher_id'],
            $teacherEmail,
            $counts['present'],
            $counts['late'],
            $counts['absent'],
            $counts['excused'],
        ]);

        if (!empty($session['teacher_user_id'])) {
            create_notification(
                $pdo,
                (int) $session['teacher_user_id'],
                'Class Summary Emailed',
                "Attendance summary for {$session['subject_code']} ({$session['section']}) has been sent to your email ({$teacherEmail})."
            );
        }
        return true;
    }

    return false;
}

/**
 * Finds all ended sessions that have not yet sent an attendance summary email,
 * and sends them.
 *
 * Runs automatically at the end of class via mark_absent_for_ended_sessions()
 * and auto_expire_sessions(). Never throws.
 *
 * @param PDO $pdo
 * @return int Number of summary emails successfully dispatched
 */
function send_pending_class_summary_emails(PDO $pdo): int {
    try {
        ensure_email_summaries_table($pdo);

        // Find sessions that ended (session_end <= NOW()) today or yesterday that have no summary record yet.
        // Capped at 10 to keep per-request latency within budget on serverless.
        $stmt = $pdo->prepare("
            SELECT s.session_id
            FROM attendance_sessions s
            LEFT JOIN session_email_summaries ses_sum ON ses_sum.session_id = s.session_id
            WHERE ses_sum.session_id IS NULL
              AND s.session_end IS NOT NULL
              AND s.session_end <= NOW()
              AND s.session_date >= (CURRENT_DATE - CAST('1 day' AS interval))
            ORDER BY s.session_end ASC
            LIMIT 10
        ");
        $stmt->execute();
        $sessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $sentCount = 0;
        foreach ($sessionIds as $sid) {
            if (send_class_summary_email($pdo, (int) $sid)) {
                $sentCount++;
            }
        }
        return $sentCount;
    } catch (Throwable $e) {
        error_log('send_pending_class_summary_emails: ' . $e->getMessage());
        return 0;
    }
}
