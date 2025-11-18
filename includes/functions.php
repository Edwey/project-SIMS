<?php
/**
 * Shared helper functions for the University Management System
 */

require_once __DIR__ . '/database.php';

// Optional PHPMailer autoload (if available)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// -----------------------------------------------------------------------------
// Enrollment invites (instructor/admin -> student confirms)
// -----------------------------------------------------------------------------

function ensure_enrollment_invites_table(): void
{
    db_execute("CREATE TABLE IF NOT EXISTS enrollment_invites (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        course_section_id INT NOT NULL,
        semester_id INT NOT NULL,
        academic_year_id INT NOT NULL,
        invited_by INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        status ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME NULL,
        UNIQUE KEY uniq_student_section (student_id, course_section_id, semester_id),
        FOREIGN KEY (student_id) REFERENCES students(id),
        FOREIGN KEY (course_section_id) REFERENCES course_sections(id),
        FOREIGN KEY (semester_id) REFERENCES semesters(id),
        FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
        FOREIGN KEY (invited_by) REFERENCES users(id)
    ) ENGINE=InnoDB");
}

function create_enrollment_invite(int $actorUserId, int $studentId, int $sectionId, int $semesterId, int $academicYearId): array
{
    if ($actorUserId <= 0 || $studentId <= 0 || $sectionId <= 0 || $semesterId <= 0 || $academicYearId <= 0) {
        return ['success' => false, 'message' => 'Invalid invite parameters.'];
    }
    ensure_enrollment_invites_table();
    // Upsert-like: if an invite exists and pending, re-use; else create
    $existing = db_query_one('SELECT id, status FROM enrollment_invites WHERE student_id = ? AND course_section_id = ? AND semester_id = ? LIMIT 1', [$studentId, $sectionId, $semesterId]);
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $token);
    if ($existing && $existing['status'] === 'pending') {
        db_execute('UPDATE enrollment_invites SET token_hash = ?, invited_by = ?, created_at = NOW() WHERE id = ?', [$hash, $actorUserId, $existing['id']]);
        $inviteId = (int)$existing['id'];
    } else {
        db_execute('INSERT INTO enrollment_invites (student_id, course_section_id, semester_id, academic_year_id, invited_by, token_hash) VALUES (?, ?, ?, ?, ?, ?)', [
            $studentId, $sectionId, $semesterId, $academicYearId, $actorUserId, $hash
        ]);
        $inviteId = (int)db_last_id();
    }

    // Notify student with accept/decline link
    $stu = db_query_one('SELECT user_id FROM students WHERE id = ? LIMIT 1', [$studentId]);
    if ($stu && !empty($stu['user_id'])) {
        $link = SITE_URL . '/student/enrollment_invite.php?id=' . $inviteId . '&token=' . $token;
        send_notification_to_user((int)$stu['user_id'], 'Enrollment invite', 'You have an enrollment invitation pending. Review and respond: ' . $link, 'info');
    }
    return ['success' => true, 'message' => 'Invite sent.'];
}

function get_enrollment_invite(int $inviteId): ?array
{
    ensure_enrollment_invites_table();
    if ($inviteId <= 0) return null;
    return db_query_one('SELECT * FROM enrollment_invites WHERE id = ? LIMIT 1', [$inviteId]);
}

function respond_enrollment_invite(int $inviteId, string $token, string $decision, int $actorUserId): array
{
    ensure_enrollment_invites_table();
    $inv = get_enrollment_invite($inviteId);
    if (!$inv || $inv['status'] !== 'pending') return ['success' => false, 'message' => 'Invite not found or already responded.'];
    if (hash('sha256', $token) !== $inv['token_hash']) return ['success' => false, 'message' => 'Invalid invite token.'];

    if ($decision === 'accept') {
        // Enroll student using normal path with window bypass but capacity honored
        $res = enroll_student((int)$inv['student_id'], (int)$inv['course_section_id'], (int)$inv['semester_id'], (int)$inv['academic_year_id'], true);
        $status = $res['success'] ? 'accepted' : 'declined';
        db_execute('UPDATE enrollment_invites SET status = ?, responded_at = NOW() WHERE id = ?', [$status, $inviteId]);
        return $res['success'] ? ['success' => true, 'message' => 'Enrollment confirmed.'] : ['success' => false, 'message' => 'Unable to enroll: ' . ($res['message'] ?? 'Unknown error')];
    }

    if ($decision === 'decline') {
        db_execute('UPDATE enrollment_invites SET status = ?, responded_at = NOW() WHERE id = ?', ['declined', $inviteId]);
        return ['success' => true, 'message' => 'Invite declined.'];
    }

    return ['success' => false, 'message' => 'Invalid decision.'];
}

function get_instructor_department_id(int $userId): ?int
{
    $row = db_query_one('SELECT department_id FROM instructors WHERE user_id = ? LIMIT 1', [$userId]);
    if ($row && !empty($row['department_id'])) {
        return (int)$row['department_id'];
    }

    $row = db_query_one(
        'SELECT c.department_id
         FROM course_sections cs
         JOIN courses c ON c.id = cs.course_id
         JOIN instructors i ON i.id = cs.instructor_id
         WHERE i.user_id = ?
         ORDER BY cs.created_at DESC, cs.id DESC
         LIMIT 1',
        [$userId]
    );

    if ($row && !empty($row['department_id'])) {
        return (int)$row['department_id'];
    }

    return null;
}

// -----------------------------------------------------------------------------
// Email OTP helpers (MFA, passwordless, verify_email)
// -----------------------------------------------------------------------------

function generate_email_otp(int $userId, string $purpose = 'mfa', int $ttlSeconds = 600): bool
{
    if ($userId <= 0) return false;
    $user = db_query_one('SELECT email, username FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$user || empty($user['email'])) return false;

    // Invalidate previous unused codes for same purpose
    db_execute('UPDATE otp_codes SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, $purpose]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $code);
    $expires = date('Y-m-d H:i:s', time() + max(60, $ttlSeconds));
    db_execute('INSERT INTO otp_codes (user_id, code_hash, channel, purpose, expires_at, created_at) VALUES (?, ?, "email", ?, ?, NOW())', [$userId, $hash, $purpose, $expires]);

    $subject = 'Your verification code';
    $html = '<p>Your verification code is:</p><p style="font-size:22px;font-weight:bold;letter-spacing:2px">' . htmlspecialchars($code) . '</p><p>This code expires in ' . ceil($ttlSeconds/60) . ' minutes.</p>';
    $text = "Your verification code is: $code\nIt expires in " . ceil($ttlSeconds/60) . " minutes.";
    send_email($user['email'], $subject, $html, $text);
    return true;
}

function verify_email_otp(int $userId, string $code, string $purpose = 'mfa'): array
{
    if ($userId <= 0 || $code === '') return ['success' => false, 'message' => 'Invalid code.'];
    $hash = hash('sha256', trim($code));
    $row = db_query_one('SELECT id, expires_at, used_at FROM otp_codes WHERE user_id = ? AND purpose = ? AND code_hash = ? LIMIT 1', [$userId, $purpose, $hash]);
    if (!$row) return ['success' => false, 'message' => 'Incorrect code.'];
    if (!empty($row['used_at'])) return ['success' => false, 'message' => 'Code already used.'];
    if (strtotime($row['expires_at']) < time()) return ['success' => false, 'message' => 'Code expired.'];
    db_execute('UPDATE otp_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);
    return ['success' => true];
}

// -----------------------------------------------------------------------------
// Email helpers
// -----------------------------------------------------------------------------

function send_email(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
{
    $fromAddress = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@example.com';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'University Management System';

    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $mailer->SMTPDebug = 2; // verbose server messages
                $mailer->Debugoutput = 'error_log';
            }
            $mailer->Host = defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : 'localhost';
            $mailer->Port = defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : 25;
            $mailer->SMTPAuth = defined('MAIL_SMTP_USER') && defined('MAIL_SMTP_PASS');
            if ($mailer->SMTPAuth) {
                $mailer->Username = MAIL_SMTP_USER;
                $mailer->Password = MAIL_SMTP_PASS;
            }
            if (defined('MAIL_SMTP_SECURE')) {
                $mailer->SMTPSecure = MAIL_SMTP_SECURE;
            }
            $mailer->SMTPAutoTLS = true;

            // Determine CA bundle path
            $caFromIni = ini_get('openssl.cafile');
            $defaultXamppCA = 'C:\\xampp\\php\\extras\\ssl\\cacert.pem';
            $cafile = is_string($caFromIni) && $caFromIni !== '' ? $caFromIni : $defaultXamppCA;
            $hasCA = is_string($cafile) && file_exists($cafile);

            $sslOpts = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => 'smtp-relay.brevo.com',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ];
            if ($hasCA) { $sslOpts['cafile'] = $cafile; }
            // As a last resort for local dev only: if no CA is available, relax verification in APP_DEBUG
            if (!$hasCA && defined('APP_DEBUG') && APP_DEBUG) {
                $sslOpts['verify_peer'] = false;
                $sslOpts['verify_peer_name'] = false;
            }
            $mailer->SMTPOptions = ['ssl' => $sslOpts];

            $mailer->setFrom($fromAddress, $fromName);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $textBody ?? strip_tags($htmlBody);
            try {
                $mailer->send();
                return true;
            } catch (Throwable $e1) {
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    error_log('Email send attempt 1 failed: ' . $e1->getMessage());
                }
                $GLOBALS['last_mail_error'] = $e1->getMessage();
                $mailer->smtpClose();
                $mailer->Port = 465;
                $mailer->SMTPSecure = 'ssl';
                $sslOpts2 = [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'peer_name' => 'smtp-relay.brevo.com',
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                ];
                if ($hasCA) { $sslOpts2['cafile'] = $cafile; }
                if (!$hasCA && defined('APP_DEBUG') && APP_DEBUG) {
                    $sslOpts2['verify_peer'] = false;
                    $sslOpts2['verify_peer_name'] = false;
                }
                $mailer->SMTPOptions = ['ssl' => $sslOpts2];
                try {
                    $mailer->send();
                    return true;
                } catch (Throwable $e2) {
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        error_log('Email send attempt 2 failed: ' . $e2->getMessage());
                    }
                    $GLOBALS['last_mail_error'] = $e2->getMessage();
                    return false;
                }
            }

        } catch (Throwable $e) {
            if (APP_DEBUG) {
                error_log('Email send failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    $headers = [
        'From: ' . $fromName . ' <' . $fromAddress . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
    ];

    $success = mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    if (!$success && APP_DEBUG) {
        error_log('Email send failed via mail() to ' . $to);
    }

    return $success;
}

function send_password_reset_email(string $email, string $resetLink, string $expiresAt): bool
{
    $subject = 'Password reset instructions';
    $htmlBody = '<p>Hello,</p>'
        . '<p>We received a request to reset your password. Click the link below to choose a new password:</p>'
        . '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>This link will expire on ' . date('M j, Y g:i A', strtotime($expiresAt)) . '.</p>'
        . '<p>If you did not request a reset, you can safely ignore this email.</p>'
        . '<p>Regards,<br>University Management System</p>';

    $textBody = "Hello,\n\n" .
        "We received a request to reset your password. Use the link below to choose a new password:\n" .
        $resetLink . "\n\n" .
        'This link will expire on ' . date('M j, Y g:i A', strtotime($expiresAt)) . ".\n\n" .
        "If you did not request a reset, you can safely ignore this email.\n\n" .
        "Regards,\nUniversity Management System";

    return send_email($email, $subject, $htmlBody, $textBody);
}

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    if ($secure) { ini_set('session.cookie_secure', '1'); }
    session_start();
}

function format_date_display(?string $date, string $format = 'd/m/Y', string $fallback = '-') : string
{
    if (empty($date) || $date === '0000-00-00') {
        return $fallback;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTime ? $dt->format($format) : $date;
}

function format_datetime_display(?string $datetime, string $format = 'd/m/Y H:i', string $fallback = '-') : string
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return $fallback;
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    return $dt instanceof DateTime ? $dt->format($format) : $datetime;
}


function get_student_completed_course_codes(int $studentId): array
{
    $rows = db_query("SELECT DISTINCT UPPER(c.course_code) AS code
        FROM enrollments e
        JOIN course_sections cs ON e.course_section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        WHERE e.student_id = ? AND e.final_grade IS NOT NULL", [$studentId]);
    $codes = [];
    foreach ($rows as $r) { $codes[] = $r['code']; }
    return $codes;
}

// Ensure waitlists table exists
function ensure_waitlists_table(): void
{
    db_execute("CREATE TABLE IF NOT EXISTS waitlists (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        course_section_id INT NOT NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_section (student_id, course_section_id),
        FOREIGN KEY (student_id) REFERENCES students(id),
        FOREIGN KEY (course_section_id) REFERENCES course_sections(id)
    ) ENGINE=InnoDB");
}

function get_course_by_id(int $courseId): ?array
{
    if ($courseId <= 0) {
        return null;
    }
    return db_query_one(
        'SELECT c.id, c.course_code, c.course_name, c.department_id, c.level_id, c.credits, c.prerequisites, c.description
         FROM courses c WHERE c.id = ? LIMIT 1',
        [$courseId]
    );
}

// -----------------------------------------------------------------------------
// Academic Years & Semesters (Admin)
// -----------------------------------------------------------------------------

function get_academic_years_admin(): array
{
    return db_query('SELECT id, year_name, start_date, end_date, is_current, created_at FROM academic_years ORDER BY start_date DESC');
}

function create_academic_year(string $yearName, string $startDate, string $endDate, bool $isCurrent): array
{
    $yearName = trim($yearName);
    if ($yearName === '' || $startDate === '' || $endDate === '') {
        return ['success' => false, 'message' => 'Year name, start date, and end date are required.'];
    }
    if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) >= strtotime($endDate)) {
        return ['success' => false, 'message' => 'Invalid date range.'];
    }
    $dupe = db_query_one('SELECT id FROM academic_years WHERE year_name = ? LIMIT 1', [$yearName]);
    if ($dupe) {
        return ['success' => false, 'message' => 'An academic year with that name already exists.'];
    }
    db_execute('INSERT INTO academic_years (year_name, start_date, end_date, is_current, created_at) VALUES (?, ?, ?, 0, NOW())', [$yearName, $startDate, $endDate]);
    $row = db_query_one('SELECT id FROM academic_years WHERE year_name = ? LIMIT 1', [$yearName]);
    if ($isCurrent && $row) {
        set_current_academic_year((int)$row['id']);
    }
    return ['success' => true, 'message' => 'Academic year created successfully.'];
}

function update_academic_year(int $id, string $yearName, string $startDate, string $endDate, bool $isCurrent): array
{
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid academic year selected.'];
    $yearName = trim($yearName);
    if ($yearName === '' || $startDate === '' || $endDate === '') {
        return ['success' => false, 'message' => 'Year name, start date, and end date are required.'];
    }
    if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) >= strtotime($endDate)) {
        return ['success' => false, 'message' => 'Invalid date range.'];
    }
    $exists = db_query_one('SELECT id FROM academic_years WHERE id = ? LIMIT 1', [$id]);
    if (!$exists) return ['success' => false, 'message' => 'Academic year not found.'];
    $dupe = db_query_one('SELECT id FROM academic_years WHERE year_name = ? AND id <> ? LIMIT 1', [$yearName, $id]);
    if ($dupe) return ['success' => false, 'message' => 'Another academic year already uses that name.'];
    db_execute('UPDATE academic_years SET year_name = ?, start_date = ?, end_date = ?, updated_at = NOW() WHERE id = ?', [$yearName, $startDate, $endDate, $id]);
    if ($isCurrent) {
        set_current_academic_year($id);
    }
    return ['success' => true, 'message' => 'Academic year updated successfully.'];
}

function delete_academic_year(int $id): array
{
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid academic year selected.'];
    $exists = db_query_one('SELECT id, is_current FROM academic_years WHERE id = ? LIMIT 1', [$id]);
    if (!$exists) return ['success' => false, 'message' => 'Academic year not found.'];
    if ((int)$exists['is_current'] === 1) {
        return ['success' => false, 'message' => 'Cannot delete the current academic year.'];
    }
    $hasSemesters = db_query_one('SELECT id FROM semesters WHERE academic_year_id = ? LIMIT 1', [$id]);
    if ($hasSemesters) {
        return ['success' => false, 'message' => 'Cannot delete: year has linked semesters.'];
    }
    $hasEnrollments = db_query_one('SELECT id FROM enrollments WHERE academic_year_id = ? LIMIT 1', [$id]);
    if ($hasEnrollments) {
        return ['success' => false, 'message' => 'Cannot delete: year is linked to enrollments.'];
    }
    db_execute('DELETE FROM academic_years WHERE id = ?', [$id]);
    return ['success' => true, 'message' => 'Academic year deleted successfully.'];
}

function set_current_academic_year(int $id): void
{
    db_execute('UPDATE academic_years SET is_current = 0');
    db_execute('UPDATE academic_years SET is_current = 1 WHERE id = ?', [$id]);
}

function get_semesters_by_year(int $academicYearId): array
{
    return db_query('SELECT id, semester_name, start_date, end_date, is_current, registration_deadline, exam_period_start, exam_period_end, notes, created_at FROM semesters WHERE academic_year_id = ? ORDER BY start_date', [$academicYearId]);
}

function create_semester(
    int $academicYearId,
    string $semesterName,
    string $startDate,
    string $endDate,
    bool $isCurrent,
    ?string $registrationDeadline = null,
    ?string $examPeriodStart = null,
    ?string $examPeriodEnd = null,
    ?string $notes = null
): array
{
    if ($academicYearId <= 0) return ['success' => false, 'message' => 'Invalid academic year.'];
    $semesterName = trim($semesterName);
    if (!in_array($semesterName, ['First Semester','Second Semester'], true)) {
        return ['success' => false, 'message' => 'Invalid semester name.'];
    }
    if ($startDate === '' || $endDate === '' || strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) >= strtotime($endDate)) {
        return ['success' => false, 'message' => 'Invalid date range.'];
    }
    $existsAY = db_query_one('SELECT id, start_date, end_date FROM academic_years WHERE id = ? LIMIT 1', [$academicYearId]);
    if (!$existsAY) return ['success' => false, 'message' => 'Academic year not found.'];
    if (strtotime($startDate) < strtotime($existsAY['start_date']) || strtotime($endDate) > strtotime($existsAY['end_date'])) {
        return ['success' => false, 'message' => 'Semester dates must fall within the academic year range.'];
    }
    $registrationDeadline = $registrationDeadline !== null ? trim($registrationDeadline) : null;
    if ($registrationDeadline === '') { $registrationDeadline = null; }
    if ($registrationDeadline !== null) {
        if (strtotime($registrationDeadline) === false) {
            return ['success' => false, 'message' => 'Registration deadline is not a valid date.'];
        }
        if (strtotime($registrationDeadline) > strtotime($startDate)) {
            return ['success' => false, 'message' => 'Registration deadline must be on or before the semester start date.'];
        }
    }
    $examPeriodStart = $examPeriodStart !== null ? trim($examPeriodStart) : null;
    if ($examPeriodStart === '') { $examPeriodStart = null; }
    if ($examPeriodStart !== null && strtotime($examPeriodStart) === false) {
        return ['success' => false, 'message' => 'Exam period start is not a valid date.'];
    }
    $examPeriodEnd = $examPeriodEnd !== null ? trim($examPeriodEnd) : null;
    if ($examPeriodEnd === '') { $examPeriodEnd = null; }
    if ($examPeriodEnd !== null && strtotime($examPeriodEnd) === false) {
        return ['success' => false, 'message' => 'Exam period end is not a valid date.'];
    }
    if ($examPeriodStart !== null && $examPeriodEnd !== null) {
        if (strtotime($examPeriodStart) > strtotime($examPeriodEnd)) {
            return ['success' => false, 'message' => 'Exam period start must be on or before exam period end.'];
        }
    }
    if ($examPeriodStart !== null && (strtotime($examPeriodStart) < strtotime($startDate) || strtotime($examPeriodStart) > strtotime($endDate))) {
        return ['success' => false, 'message' => 'Exam period start must fall within the semester.'];
    }
    if ($examPeriodEnd !== null && (strtotime($examPeriodEnd) < strtotime($startDate) || strtotime($examPeriodEnd) > strtotime($endDate))) {
        return ['success' => false, 'message' => 'Exam period end must fall within the semester.'];
    }
    $notes = $notes !== null ? trim($notes) : null;
    if ($notes === '') { $notes = null; }
    $dupe = db_query_one('SELECT id FROM semesters WHERE academic_year_id = ? AND semester_name = ? LIMIT 1', [$academicYearId, $semesterName]);
    if ($dupe) return ['success' => false, 'message' => 'This semester already exists for the selected year.'];
    $overlap = db_query_one(
        'SELECT id FROM semesters WHERE academic_year_id = ? AND (? <= end_date AND ? >= start_date) LIMIT 1',
        [$academicYearId, $endDate, $startDate]
    );
    if ($overlap) {
        return ['success' => false, 'message' => 'Semester dates overlap an existing semester in this academic year.'];
    }
    db_execute(
        'INSERT INTO semesters (semester_name, academic_year_id, start_date, end_date, is_current, registration_deadline, exam_period_start, exam_period_end, notes, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, NOW())',
        [$semesterName, $academicYearId, $startDate, $endDate, $registrationDeadline, $examPeriodStart, $examPeriodEnd, $notes]
    );
    $row = db_query_one('SELECT id FROM semesters WHERE academic_year_id = ? AND semester_name = ? LIMIT 1', [$academicYearId, $semesterName]);
    if ($isCurrent && $row) {
        set_current_semester($academicYearId, (int)$row['id']);
    }
    return ['success' => true, 'message' => 'Semester created successfully.'];
}

function update_semester(
    int $id,
    int $academicYearId,
    string $semesterName,
    string $startDate,
    string $endDate,
    bool $isCurrent,
    ?string $registrationDeadline = null,
    ?string $examPeriodStart = null,
    ?string $examPeriodEnd = null,
    ?string $notes = null
): array
{
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid semester selected.'];
    if (!in_array($semesterName, ['First Semester','Second Semester'], true)) {
        return ['success' => false, 'message' => 'Invalid semester name.'];
    }
    if ($startDate === '' || $endDate === '' || strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) >= strtotime($endDate)) {
        return ['success' => false, 'message' => 'Invalid date range.'];
    }
    $exists = db_query_one('SELECT id FROM semesters WHERE id = ? LIMIT 1', [$id]);
    if (!$exists) return ['success' => false, 'message' => 'Semester not found.'];
    $dupe = db_query_one('SELECT id FROM semesters WHERE academic_year_id = ? AND semester_name = ? AND id <> ? LIMIT 1', [$academicYearId, $semesterName, $id]);
    if ($dupe) return ['success' => false, 'message' => 'Another semester with the same name already exists in this year.'];
    $yearRow = db_query_one('SELECT start_date, end_date FROM academic_years WHERE id = ? LIMIT 1', [$academicYearId]);
    if (!$yearRow) {
        return ['success' => false, 'message' => 'Academic year not found.'];
    }
    if (strtotime($startDate) < strtotime($yearRow['start_date']) || strtotime($endDate) > strtotime($yearRow['end_date'])) {
        return ['success' => false, 'message' => 'Semester dates must fall within the academic year range.'];
    }
    $registrationDeadline = $registrationDeadline !== null ? trim($registrationDeadline) : null;
    if ($registrationDeadline === '') { $registrationDeadline = null; }
    if ($registrationDeadline !== null) {
        if (strtotime($registrationDeadline) === false) {
            return ['success' => false, 'message' => 'Registration deadline is not a valid date.'];
        }
        if (strtotime($registrationDeadline) > strtotime($startDate)) {
            return ['success' => false, 'message' => 'Registration deadline must be on or before the semester start date.'];
        }
    }
    $examPeriodStart = $examPeriodStart !== null ? trim($examPeriodStart) : null;
    if ($examPeriodStart === '') { $examPeriodStart = null; }
    if ($examPeriodStart !== null && strtotime($examPeriodStart) === false) {
        return ['success' => false, 'message' => 'Exam period start is not a valid date.'];
    }
    $examPeriodEnd = $examPeriodEnd !== null ? trim($examPeriodEnd) : null;
    if ($examPeriodEnd === '') { $examPeriodEnd = null; }
    if ($examPeriodEnd !== null && strtotime($examPeriodEnd) === false) {
        return ['success' => false, 'message' => 'Exam period end is not a valid date.'];
    }
    if ($examPeriodStart !== null && $examPeriodEnd !== null && strtotime($examPeriodStart) > strtotime($examPeriodEnd)) {
        return ['success' => false, 'message' => 'Exam period start must be on or before exam period end.'];
    }
    if ($examPeriodStart !== null && (strtotime($examPeriodStart) < strtotime($startDate) || strtotime($examPeriodStart) > strtotime($endDate))) {
        return ['success' => false, 'message' => 'Exam period start must fall within the semester.'];
    }
    if ($examPeriodEnd !== null && (strtotime($examPeriodEnd) < strtotime($startDate) || strtotime($examPeriodEnd) > strtotime($endDate))) {
        return ['success' => false, 'message' => 'Exam period end must fall within the semester.'];
    }
    $notes = $notes !== null ? trim($notes) : null;
    if ($notes === '') { $notes = null; }
    $overlap = db_query_one(
        'SELECT id FROM semesters WHERE academic_year_id = ? AND id <> ? AND (? <= end_date AND ? >= start_date) LIMIT 1',
        [$academicYearId, $id, $endDate, $startDate]
    );
    if ($overlap) {
        return ['success' => false, 'message' => 'Semester dates overlap an existing semester in this academic year.'];
    }
    db_execute(
        'UPDATE semesters SET semester_name = ?, start_date = ?, end_date = ?, registration_deadline = ?, exam_period_start = ?, exam_period_end = ?, notes = ?, updated_at = NOW() WHERE id = ?',
        [$semesterName, $startDate, $endDate, $registrationDeadline, $examPeriodStart, $examPeriodEnd, $notes, $id]
    );
    if ($isCurrent) {
        set_current_semester($academicYearId, $id);
    }
    return ['success' => true, 'message' => 'Semester updated successfully.'];
}

function delete_semester(int $id): array
{
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid semester selected.'];
    $row = db_query_one('SELECT id, academic_year_id, is_current FROM semesters WHERE id = ? LIMIT 1', [$id]);
    if (!$row) return ['success' => false, 'message' => 'Semester not found.'];
    if ((int)$row['is_current'] === 1) return ['success' => false, 'message' => 'Cannot delete the current semester.'];
    $hasSections = db_query_one('SELECT id FROM course_sections WHERE semester_id = ? LIMIT 1', [$id]);
    if ($hasSections) return ['success' => false, 'message' => 'Cannot delete: semester has linked course sections.'];
    $hasEnrollments = db_query_one('SELECT id FROM enrollments WHERE semester_id = ? LIMIT 1', [$id]);
    if ($hasEnrollments) return ['success' => false, 'message' => 'Cannot delete: semester is linked to enrollments.'];
    db_execute('DELETE FROM semesters WHERE id = ?', [$id]);
    return ['success' => true, 'message' => 'Semester deleted successfully.'];
}

function set_current_semester(int $academicYearId, int $semesterId): void
{
    db_execute('UPDATE semesters SET is_current = 0 WHERE academic_year_id = ?', [$academicYearId]);
    db_execute('UPDATE semesters SET is_current = 1 WHERE id = ? AND academic_year_id = ?', [$semesterId, $academicYearId]);
}

function get_next_semester_start_date(): ?string
{
    $today = date('Y-m-d');
    $row = db_query_one('SELECT start_date FROM semesters WHERE start_date > ? ORDER BY start_date ASC LIMIT 1', [$today]);
    if ($row && !empty($row['start_date'])) {
        return $row['start_date'];
    }
    $row = db_query_one('SELECT start_date FROM semesters ORDER BY start_date DESC LIMIT 1');
    return $row['start_date'] ?? null;
}

function calculate_graduation_lock_deadline(): string
{
    $fallback = date('Y-m-d H:i:s', strtotime('+30 days'));
    $nextStart = get_next_semester_start_date();
    if (!$nextStart) {
        return $fallback;
    }
    $lockTs = strtotime($nextStart . ' -14 days');
    if ($lockTs === false) {
        return $fallback;
    }
    return date('Y-m-d H:i:s', $lockTs);
}

function process_graduation_account_locks(): array
{
    $now = date('Y-m-d H:i:s');
    $eligibleUsers = db_query(
        'SELECT id, username, graduation_lock_at FROM users WHERE role = "student" AND is_active = 1 AND graduation_lock_at IS NOT NULL AND graduation_lock_at <= ? ORDER BY graduation_lock_at ASC',
        [$now]
    );

    if (empty($eligibleUsers)) {
        return ['processed' => 0, 'user_ids' => []];
    }

    $processedIds = [];
    foreach ($eligibleUsers as $userRow) {
        $userId = (int)$userRow['id'];
        db_execute('UPDATE users SET is_active = 0 WHERE id = ?', [$userId]);
        $processedIds[] = $userId;

        db_execute(
            'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $userId,
                'graduation_account_deactivated',
                'user',
                $userId,
                json_encode(['is_active' => 1, 'graduation_lock_at' => $userRow['graduation_lock_at']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode(['is_active' => 0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                null,
                'cli:graduation-locker'
            ]
        );
    }

    return ['processed' => count($processedIds), 'user_ids' => $processedIds];
}

function refresh_current_academic_term(): void
{
    $today = date('Y-m-d');

    $yearRow = db_query_one(
        'SELECT id, start_date, end_date FROM academic_years WHERE ? BETWEEN start_date AND end_date LIMIT 1',
        [$today]
    );

    if (!$yearRow) {
        $yearRow = db_query_one(
            'SELECT id, start_date, end_date FROM academic_years WHERE start_date > ? ORDER BY start_date ASC LIMIT 1',
            [$today]
        );
    }

    if (!$yearRow) {
        $yearRow = db_query_one(
            'SELECT id, start_date, end_date FROM academic_years ORDER BY end_date DESC LIMIT 1'
        );
    }

    if ($yearRow) {
        $currentYearId = (int)$yearRow['id'];
        $currentYearFlag = db_query_one('SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1');
        if (!$currentYearFlag || (int)$currentYearFlag['id'] !== $currentYearId) {
            set_current_academic_year($currentYearId);
        }

        $semesterRow = db_query_one(
            'SELECT id FROM semesters WHERE academic_year_id = ? AND ? BETWEEN start_date AND end_date LIMIT 1',
            [$currentYearId, $today]
        );

        if (!$semesterRow) {
            $semesterRow = db_query_one(
                'SELECT id FROM semesters WHERE academic_year_id = ? AND start_date > ? ORDER BY start_date ASC LIMIT 1',
                [$currentYearId, $today]
            );
        }

        if (!$semesterRow) {
            $semesterRow = db_query_one(
                'SELECT id FROM semesters WHERE academic_year_id = ? ORDER BY end_date DESC LIMIT 1',
                [$currentYearId]
            );
        }

        if ($semesterRow) {
            $semesterId = (int)$semesterRow['id'];
            $currentSemesterFlag = db_query_one(
                'SELECT id FROM semesters WHERE academic_year_id = ? AND is_current = 1 LIMIT 1',
                [$currentYearId]
            );

            if (!$currentSemesterFlag || (int)$currentSemesterFlag['id'] !== $semesterId) {
                set_current_semester($currentYearId, $semesterId);
            }
        }
    }
}

function get_semester_by_id(int $semesterId): ?array
{
    if ($semesterId <= 0) return null;
    return db_query_one('SELECT id, semester_name, academic_year_id, start_date, end_date, is_current, registration_deadline, exam_period_start, exam_period_end, notes FROM semesters WHERE id = ? LIMIT 1', [$semesterId]);
}

date_default_timezone_set('UTC');

// -----------------------------------------------------------------------------
// Utility helpers
// -----------------------------------------------------------------------------

function sanitize_input(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, PASSWORD_HASH_OPTIONS);
}

function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function generate_temporary_password(int $length = 12): string
{
    $length = max(8, $length);
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%';
    $password = '';
    $maxIndex = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $maxIndex)];
    }
    return $password;
}

function set_flash_message(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

function get_flash_messages(string $type): array
{
    $messages = $_SESSION['flash'][$type] ?? [];
    unset($_SESSION['flash'][$type]);
    return $messages;
}

function render_flash_messages(): void
{
    $typeMap = [
        'success' => 'success',
        'error' => 'danger',
        'info' => 'info',
        'warning' => 'warning',
    ];

    foreach ($typeMap as $type => $bootstrapClass) {
        $messages = get_flash_messages($type);
        if (empty($messages)) {
            continue;
        }

        echo '<div class="alert alert-' . $bootstrapClass . ' alert-dismissible fade show" role="alert">';
        foreach ($messages as $message) {
            echo '<div>' . htmlspecialchars($message) . '</div>';
        }
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

// -----------------------------------------------------------------------------
// CSRF helpers
// -----------------------------------------------------------------------------

function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    if (!isset($_SESSION['csrf_token']) || $token === null) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// Backward-compatible wrapper
function validate_csrf_token(?string $token): bool
{
    return verify_csrf_token($token);
}

// -----------------------------------------------------------------------------
// Logging helpers
// -----------------------------------------------------------------------------

function app_log(string $level, string $message, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = sprintf('[%s] %s: %s', $timestamp, strtoupper($level), $message);
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (defined('APP_DEBUG') && APP_DEBUG) {
        error_log($line);
        return;
    }
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/app.log';
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function log_exception(Throwable $e, string $message = ''): void
{
    $msg = $message !== '' ? ($message . ' - ') : '';
    $msg .= get_class($e) . ': ' . $e->getMessage();
    app_log('error', $msg, [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

// -----------------------------------------------------------------------------
// Password reset helpers
// -----------------------------------------------------------------------------

function generate_password_reset_token(string $email): array
{
    $email = trim($email);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $maxRequests = 3; // per hour

    // Per-IP rate limit (system_logs based)
    $ipRow = db_query_one(
        "SELECT COUNT(*) AS c, MIN(created_at) AS first_time FROM system_logs WHERE action = 'password_reset_request' AND ip_address = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)",
        [$ip]
    );
    $recentIpCount = (int)($ipRow['c'] ?? 0);
    if ($recentIpCount >= $maxRequests) {
        // Compute retry-after seconds (1h window)
        $first = isset($ipRow['first_time']) && $ipRow['first_time'] ? strtotime((string)$ipRow['first_time']) : time();
        $retryAfter = max(1, 3600 - (time() - $first));
        // Log attempt and return neutral success with rate-limited hint
        db_execute(
            'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [null, 'password_reset_request', 'user', null, null, json_encode(['email' => $email, 'rate_limited' => true]), $ip, $ua]
        );
        return ['success' => true, 'message' => 'If the email exists, a reset link will be sent.', 'rate_limited' => true, 'retry_after_seconds' => $retryAfter];
    }

    $user = db_query_one('SELECT id, username, email FROM users WHERE email = ? LIMIT 1', [$email]);
    // Always act like success to avoid user enumeration
    if (!$user) {
        db_execute(
            'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [null, 'password_reset_request', 'user', null, null, json_encode(['email' => $email]), $ip, $ua]
        );
        return ['success' => true, 'message' => 'If the email exists, a reset link will be sent.'];
    }

    // Per-email rate limit using password_resets window
    $cntRow = db_query_one(
        'SELECT COUNT(*) AS c, MIN(created_at) AS first_time FROM password_resets WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)',
        [$user['id']]
    );
    $recentUserCount = (int)($cntRow['c'] ?? 0);
    if ($recentUserCount >= $maxRequests) {
        $first = isset($cntRow['first_time']) && $cntRow['first_time'] ? strtotime((string)$cntRow['first_time']) : time();
        $retryAfter = max(1, 3600 - (time() - $first));
        db_execute(
            'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$user['id'], 'password_reset_request', 'user', $user['id'], null, json_encode(['email' => $email, 'rate_limited' => true]), $ip, $ua]
        );
        return ['success' => true, 'message' => 'If the email exists, a reset link will be sent.', 'rate_limited' => true, 'retry_after_seconds' => $retryAfter];
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY);

    // Mark old tokens used for this user to prevent clutter
    db_execute('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);

    db_execute('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())', [
        $user['id'], $hash, $expiresAt
    ]);

    // Log successful token creation request
    db_execute(
        'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$user['id'], 'password_reset_request', 'user', $user['id'], null, json_encode(['email' => $email]), $ip, $ua]
    );

    return [
        'success' => true,
        'token' => $token,
        'user' => $user,
        'expires_at' => $expiresAt,
    ];
}

function verify_password_reset_token(string $token): array
{
    if ($token === '' || strlen($token) < 10) {
        return ['success' => false, 'message' => 'Invalid password reset token.'];
    }
    $hash = hash('sha256', $token);
    $row = db_query_one(
        'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.username, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? LIMIT 1',
        [$hash]
    );
    if (!$row) {
        return ['success' => false, 'message' => 'Invalid password reset token.'];
    }
    if (!empty($row['used_at'])) {
        return ['success' => false, 'message' => 'This password reset link has already been used.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['success' => false, 'message' => 'This password reset link has expired.'];
    }
    return ['success' => true, 'reset' => $row];
}

function complete_password_reset(string $token, string $newPassword, string $confirmPassword): array
{
    if ($newPassword === '' || $confirmPassword === '') {
        return ['success' => false, 'message' => 'Please enter and confirm your new password.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];
    }

    $verification = verify_password_reset_token($token);
    if (!$verification['success']) {
        return ['success' => false, 'message' => $verification['message']];
    }

    $row = $verification['reset'];
    $userId = (int)$row['user_id'];

    // Update password and clear flag
    db_execute('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [hash_password($newPassword), $userId]);
    // Mark token used
    db_execute('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$row['id']]);
    // Invalidate any other active tokens for this user
    db_execute('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);

    return ['success' => true, 'message' => 'Your password has been reset.'];
}

// -----------------------------------------------------------------------------
// Admin helpers
// -----------------------------------------------------------------------------

function get_all_users(): array
{
    return db_query(
        "SELECT
            u.id,
            u.username,
            u.email,
            u.role,
            u.is_active,
            u.last_login,
            u.created_at,
            s.first_name AS student_first_name,
            s.last_name AS student_last_name,
            i.first_name AS instructor_first_name,
            i.last_name AS instructor_last_name
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN instructors i ON i.user_id = u.id
        ORDER BY u.created_at DESC"
    );
}

function set_user_active_status(int $userId, bool $isActive): void
{
    db_execute('UPDATE users SET is_active = ? WHERE id = ?', [$isActive ? 1 : 0, $userId]);
}

function reset_user_password(int $userId, string $newPassword): void
{
    db_execute('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [hash_password($newPassword), $userId]);
}

function create_admin_user(string $username, string $email, string $password): array
{
    $exists = db_query_one('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email]);
    if ($exists) {
        return ['success' => false, 'message' => 'Username or email already exists.'];
    }

    if (strlen($password) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];
    }

    db_execute('INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, "admin", 1, NOW())',
        [$username, $email, hash_password($password)]);

    return ['success' => true, 'message' => 'Admin account created successfully.'];
}

function get_user_by_id(int $userId): ?array
{
    return db_query_one(
        'SELECT
            u.id,
            u.username,
            u.email,
            u.role,
            u.is_active,
            s.first_name AS student_first_name,
            s.last_name AS student_last_name,
            i.first_name AS instructor_first_name,
            i.last_name AS instructor_last_name
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN instructors i ON i.user_id = u.id
        WHERE u.id = ?
        LIMIT 1',
        [$userId]
    );
}

function update_user_account(int $userId, string $username, string $email, string $role, ?string $firstName, ?string $lastName): array
{
    $allowedRoles = ['admin', 'instructor', 'student'];
    if (!in_array($role, $allowedRoles, true)) {
        return ['success' => false, 'message' => 'Invalid role selected.'];
    }

    if ($username === '' || $email === '') {
        return ['success' => false, 'message' => 'Username and email are required.'];
    }

    $firstName = $firstName !== null ? trim($firstName) : null;
    $lastName = $lastName !== null ? trim($lastName) : null;

    $existingUsername = db_query_one('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1', [$username, $userId]);
    if ($existingUsername) {
        return ['success' => false, 'message' => 'Username already in use by another account.'];
    }

    $existingEmail = db_query_one('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $userId]);
    if ($existingEmail) {
        return ['success' => false, 'message' => 'Email already in use by another account.'];
    }

    db_execute('UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?', [$username, $email, $role, $userId]);

    if ($role === 'student') {
        $studentExists = db_query_one('SELECT id, first_name, last_name FROM students WHERE user_id = ? LIMIT 1', [$userId]);
        if ($studentExists && ($firstName !== null || $lastName !== null)) {
            $updatedFirst = $firstName !== null && $firstName !== '' ? $firstName : $studentExists['first_name'];
            $updatedLast = $lastName !== null && $lastName !== '' ? $lastName : $studentExists['last_name'];
            db_execute('UPDATE students SET first_name = ?, last_name = ? WHERE user_id = ?', [$updatedFirst, $updatedLast, $userId]);
        }
    } elseif ($role === 'instructor') {
        $instructorExists = db_query_one('SELECT id, first_name, last_name FROM instructors WHERE user_id = ? LIMIT 1', [$userId]);
        if ($instructorExists && ($firstName !== null || $lastName !== null)) {
            $updatedFirst = $firstName !== null && $firstName !== '' ? $firstName : $instructorExists['first_name'];
            $updatedLast = $lastName !== null && $lastName !== '' ? $lastName : $instructorExists['last_name'];
            db_execute('UPDATE instructors SET first_name = ?, last_name = ? WHERE user_id = ?', [$updatedFirst, $updatedLast, $userId]);
        }
    }

    return ['success' => true, 'message' => 'User updated successfully.'];
}

function set_user_password(int $userId, string $newPassword): array
{
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];
    }

    db_execute('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [hash_password($newPassword), $userId]);
    return ['success' => true, 'message' => 'Password updated successfully.'];
}

function delete_user_account(int $userId): array
{
    $student = db_query_one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
    if ($student) {
        return ['success' => false, 'message' => 'Cannot delete user: linked student record exists.'];
    }

    $instructor = db_query_one('SELECT id FROM instructors WHERE user_id = ? LIMIT 1', [$userId]);
    if ($instructor) {
        return ['success' => false, 'message' => 'Cannot delete user: linked instructor record exists.'];
    }

    db_execute('DELETE FROM users WHERE id = ?', [$userId]);

    return ['success' => true, 'message' => 'User deleted successfully.'];
}

function get_all_courses(): array
{
    return db_query(
        "SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.department_id,
            c.level_id,
            c.credits,
            c.prerequisites,
            c.description,
            c.created_at,
            d.dept_name,
            l.level_name
        FROM courses c
        JOIN departments d ON c.department_id = d.id
        JOIN levels l ON c.level_id = l.id
        ORDER BY c.course_code"
    );
}

function get_all_departments(): array
{
    return db_query('SELECT id, dept_name FROM departments ORDER BY dept_name');
}

function get_all_levels(): array
{
    return db_query('SELECT id, level_name FROM levels ORDER BY level_order');
}

function get_level_by_id(int $levelId): ?array
{
    if ($levelId <= 0) return null;
    return db_query_one('SELECT id, level_name, level_order FROM levels WHERE id = ? LIMIT 1', [$levelId]);
}

function create_level(string $levelName, int $levelOrder): array
{
    $levelName = trim($levelName);
    if ($levelName === '' || $levelOrder <= 0) {
        return ['success' => false, 'message' => 'Level name and positive order are required.'];
    }
    $dupe = db_query_one('SELECT id FROM levels WHERE level_name = ? LIMIT 1', [$levelName]);
    if ($dupe) {
        return ['success' => false, 'message' => 'A level with that name already exists.'];
    }
    db_execute('INSERT INTO levels (level_name, level_order, created_at) VALUES (?, ?, NOW())', [$levelName, $levelOrder]);
    return ['success' => true, 'message' => 'Level created successfully.'];
}

function update_level(int $levelId, string $levelName, int $levelOrder): array
{
    if ($levelId <= 0) return ['success' => false, 'message' => 'Invalid level selected.'];
    $levelName = trim($levelName);
    if ($levelName === '' || $levelOrder <= 0) {
        return ['success' => false, 'message' => 'Level name and positive order are required.'];
    }
    $exists = db_query_one('SELECT id FROM levels WHERE id = ? LIMIT 1', [$levelId]);
    if (!$exists) return ['success' => false, 'message' => 'Level not found.'];
    $dupe = db_query_one('SELECT id FROM levels WHERE level_name = ? AND id <> ? LIMIT 1', [$levelName, $levelId]);
    if ($dupe) return ['success' => false, 'message' => 'Another level already uses that name.'];
    db_execute('UPDATE levels SET level_name = ?, level_order = ? WHERE id = ?', [$levelName, $levelOrder, $levelId]);
    return ['success' => true, 'message' => 'Level updated successfully.'];
}

function delete_level(int $levelId): array
{
    if ($levelId <= 0) return ['success' => false, 'message' => 'Invalid level selected.'];
    $exists = db_query_one('SELECT id FROM levels WHERE id = ? LIMIT 1', [$levelId]);
    if (!$exists) return ['success' => false, 'message' => 'Level not found.'];
    $usedByStudents = db_query_one('SELECT id FROM students WHERE current_level_id = ? LIMIT 1', [$levelId]);
    if ($usedByStudents) return ['success' => false, 'message' => 'Cannot delete: level is linked to students.'];
    $usedByCourses = db_query_one('SELECT id FROM courses WHERE level_id = ? LIMIT 1', [$levelId]);
    if ($usedByCourses) return ['success' => false, 'message' => 'Cannot delete: level is linked to courses.'];
    db_execute('DELETE FROM levels WHERE id = ?', [$levelId]);
    return ['success' => true, 'message' => 'Level deleted successfully.'];
}

/**
 * Departments (Admin)
 */
function get_departments_admin(): array
{
    return db_query(
        'SELECT id, dept_code, dept_name, dept_head, description, created_at FROM departments ORDER BY dept_name'
    );
}

function get_department_by_id(int $deptId): ?array
{
    if ($deptId <= 0) {
        return null;
    }
    return db_query_one('SELECT id, dept_code, dept_name, dept_head, description FROM departments WHERE id = ? LIMIT 1', [$deptId]);
}

function create_department(string $deptCode, string $deptName, ?string $deptHead, ?string $description): array
{
    $deptCode = strtoupper(trim($deptCode));
    $deptName = trim($deptName);
    $deptHead = $deptHead !== null ? trim($deptHead) : null;
    $description = $description !== null ? trim($description) : null;

    if ($deptCode === '' || $deptName === '') {
        return ['success' => false, 'message' => 'Department code and name are required.'];
    }

    if (!preg_match('/^[A-Z0-9]{2,10}$/', $deptCode)) {
        return ['success' => false, 'message' => 'Department code must be 2-10 uppercase letters/numbers.'];
    }

    $dupeCode = db_query_one('SELECT id FROM departments WHERE dept_code = ? LIMIT 1', [$deptCode]);
    if ($dupeCode) {
        return ['success' => false, 'message' => 'A department with that code already exists.'];
    }

    $dupeName = db_query_one('SELECT id FROM departments WHERE dept_name = ? LIMIT 1', [$deptName]);
    if ($dupeName) {
        return ['success' => false, 'message' => 'A department with that name already exists.'];
    }

    db_execute(
        'INSERT INTO departments (dept_code, dept_name, dept_head, description, created_at) VALUES (?, ?, ?, ?, NOW())',
        [$deptCode, $deptName, $deptHead, $description]
    );

    return ['success' => true, 'message' => 'Department created successfully.'];
}

function update_department(int $deptId, string $deptCode, string $deptName, ?string $deptHead, ?string $description): array
{
    $deptCode = strtoupper(trim($deptCode));
    $deptName = trim($deptName);
    $deptHead = $deptHead !== null ? trim($deptHead) : null;
    $description = $description !== null ? trim($description) : null;

    if ($deptId <= 0) {
        return ['success' => false, 'message' => 'Invalid department selected.'];
    }

    if ($deptCode === '' || $deptName === '') {
        return ['success' => false, 'message' => 'Department code and name are required.'];
    }

    if (!preg_match('/^[A-Z0-9]{2,10}$/', $deptCode)) {
        return ['success' => false, 'message' => 'Department code must be 2-10 uppercase letters/numbers.'];
    }

    $exists = db_query_one('SELECT id FROM departments WHERE id = ? LIMIT 1', [$deptId]);
    if (!$exists) {
        return ['success' => false, 'message' => 'Department not found.'];
    }

    $dupeCode = db_query_one('SELECT id FROM departments WHERE dept_code = ? AND id <> ? LIMIT 1', [$deptCode, $deptId]);
    if ($dupeCode) {
        return ['success' => false, 'message' => 'Another department already uses that code.'];
    }

    $dupeName = db_query_one('SELECT id FROM departments WHERE dept_name = ? AND id <> ? LIMIT 1', [$deptName, $deptId]);
    if ($dupeName) {
        return ['success' => false, 'message' => 'Another department already uses that name.'];
    }

    db_execute(
        'UPDATE departments SET dept_code = ?, dept_name = ?, dept_head = ?, description = ?, updated_at = NOW() WHERE id = ?',
        [$deptCode, $deptName, $deptHead, $description, $deptId]
    );

    return ['success' => true, 'message' => 'Department updated successfully.'];
}

function delete_department(int $deptId): array
{
    if ($deptId <= 0) {
        return ['success' => false, 'message' => 'Invalid department selected.'];
    }

    $exists = db_query_one('SELECT id FROM departments WHERE id = ? LIMIT 1', [$deptId]);
    if (!$exists) {
        return ['success' => false, 'message' => 'Department not found.'];
    }

    // Block deletion if referenced
    $hasCourses = db_query_one('SELECT id FROM courses WHERE department_id = ? LIMIT 1', [$deptId]);
    if ($hasCourses) {
        return ['success' => false, 'message' => 'Cannot delete: department is linked to courses.'];
    }
    $hasInstructors = db_query_one('SELECT id FROM instructors WHERE department_id = ? LIMIT 1', [$deptId]);
    if ($hasInstructors) {
        return ['success' => false, 'message' => 'Cannot delete: department is linked to instructors.'];
    }
    $hasStudents = db_query_one('SELECT id FROM students WHERE department_id = ? LIMIT 1', [$deptId]);
    if ($hasStudents) {
        return ['success' => false, 'message' => 'Cannot delete: department is linked to students.'];
    }

    db_execute('DELETE FROM departments WHERE id = ?', [$deptId]);

    return ['success' => true, 'message' => 'Department deleted successfully.'];
}

function create_course(string $courseCode, string $courseName, int $departmentId, int $levelId, int $credits, ?string $prerequisites, ?string $description): array
{
    $courseCode = strtoupper(trim($courseCode));
    $courseName = trim($courseName);
    $prerequisites = $prerequisites !== null ? trim($prerequisites) : null;
    $description = $description !== null ? trim($description) : null;

    if ($courseCode === '' || $courseName === '') {
        return ['success' => false, 'message' => 'Course code and name are required.'];
    }

    if ($credits <= 0) {
        return ['success' => false, 'message' => 'Credits must be a positive number.'];
    }

    $departmentExists = db_query_one('SELECT id FROM departments WHERE id = ? LIMIT 1', [$departmentId]);
    if (!$departmentExists) {
        return ['success' => false, 'message' => 'Selected department does not exist.'];
    }

    $levelExists = db_query_one('SELECT id FROM levels WHERE id = ? LIMIT 1', [$levelId]);
    if (!$levelExists) {
        return ['success' => false, 'message' => 'Selected level does not exist.'];
    }

    $duplicate = db_query_one('SELECT id FROM courses WHERE course_code = ? LIMIT 1', [$courseCode]);
    if ($duplicate) {
        return ['success' => false, 'message' => 'A course with that code already exists.'];
    }

    db_execute(
        'INSERT INTO courses (course_code, course_name, department_id, level_id, credits, prerequisites, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
        [$courseCode, $courseName, $departmentId, $levelId, $credits, $prerequisites, $description]
    );

    return ['success' => true, 'message' => 'Course created successfully.'];
}

function update_course(int $courseId, string $courseCode, string $courseName, int $departmentId, int $levelId, int $credits, ?string $prerequisites, ?string $description): array
{
    $courseCode = strtoupper(trim($courseCode));
    $courseName = trim($courseName);
    $prerequisites = $prerequisites !== null ? trim($prerequisites) : null;
    $description = $description !== null ? trim($description) : null;

    if ($courseId <= 0) {
        return ['success' => false, 'message' => 'Invalid course selected.'];
    }

    if ($courseCode === '' || $courseName === '') {
        return ['success' => false, 'message' => 'Course code and name are required.'];
    }

    if ($credits <= 0) {
        return ['success' => false, 'message' => 'Credits must be a positive number.'];
    }

    $courseExists = db_query_one('SELECT id FROM courses WHERE id = ? LIMIT 1', [$courseId]);
    if (!$courseExists) {
        return ['success' => false, 'message' => 'Course not found.'];
    }

    $departmentExists = db_query_one('SELECT id FROM departments WHERE id = ? LIMIT 1', [$departmentId]);
    if (!$departmentExists) {
        return ['success' => false, 'message' => 'Selected department does not exist.'];
    }

    $levelExists = db_query_one('SELECT id FROM levels WHERE id = ? LIMIT 1', [$levelId]);
    if (!$levelExists) {
        return ['success' => false, 'message' => 'Selected level does not exist.'];
    }

    $duplicate = db_query_one('SELECT id FROM courses WHERE course_code = ? AND id <> ? LIMIT 1', [$courseCode, $courseId]);
    if ($duplicate) {
        return ['success' => false, 'message' => 'Another course already uses that code.'];
    }

    db_execute(
        'UPDATE courses SET course_code = ?, course_name = ?, department_id = ?, level_id = ?, credits = ?, prerequisites = ?, description = ?, updated_at = NOW() WHERE id = ?',
        [$courseCode, $courseName, $departmentId, $levelId, $credits, $prerequisites, $description, $courseId]
    );

    return ['success' => true, 'message' => 'Course updated successfully.'];
}

function delete_course(int $courseId): array
{
    if ($courseId <= 0) {
        return ['success' => false, 'message' => 'Invalid course selected.'];
    }

    $courseExists = db_query_one('SELECT id FROM courses WHERE id = ? LIMIT 1', [$courseId]);
    if (!$courseExists) {
        return ['success' => false, 'message' => 'Course not found.'];
    }

    $sectionExists = db_query_one('SELECT id FROM course_sections WHERE course_id = ? LIMIT 1', [$courseId]);
    if ($sectionExists) {
        return ['success' => false, 'message' => 'Cannot delete course: linked course sections exist.'];
    }

    db_execute('DELETE FROM courses WHERE id = ?', [$courseId]);

    return ['success' => true, 'message' => 'Course deleted successfully.'];
}

function redirect(string $path): void
{
    $url = $path;
    if (!preg_match('#^https?://#i', $path)) {
        if (strpos($path, SITE_URL) === 0) {
            $url = $path;
        } else {
            $url = SITE_URL . $path;
        }
    }
    header('Location: ' . $url);
    exit;
}

// -----------------------------------------------------------------------------
// Admin helpers
// -----------------------------------------------------------------------------

function admin_delete_user(int $userId): array
{
    $user = db_query_one('SELECT id, role FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    if (isset($user['role']) && $user['role'] === 'admin') {
        return ['success' => false, 'message' => 'Cannot delete an admin user.'];
    }

    // If the user is linked to a student or instructor profile, prevent deletion here
    // to avoid cascading data loss. Ask admin to unlink/delete domain profile first.
    $student = db_query_one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
    $instructor = db_query_one('SELECT id FROM instructors WHERE user_id = ? LIMIT 1', [$userId]);
    if ($student || $instructor) {
        return ['success' => false, 'message' => 'Cannot delete user: linked student/instructor profile exists.'];
    }

    $db = Database::getInstance()->getConnection();
    try {
        $db->beginTransaction();

        // Remove dependent rows to satisfy FK constraints
        db_execute('DELETE FROM notifications WHERE user_id = ? OR sender_user_id = ?', [$userId, $userId]);
        db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        db_execute('DELETE FROM otp_codes WHERE user_id = ?', [$userId]);
        db_execute('DELETE FROM email_outbox WHERE to_user_id = ?', [$userId]);
        db_execute('DELETE FROM system_logs WHERE user_id = ?', [$userId]);
        db_execute('UPDATE applications SET user_id = NULL WHERE user_id = ?', [$userId]);
        db_execute('DELETE FROM user_roles WHERE user_id = ?', [$userId]);

        // Finally delete the user
        db_execute('DELETE FROM users WHERE id = ?', [$userId]);

        $db->commit();
        return ['success' => true, 'message' => 'User deleted.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        return ['success' => false, 'message' => 'Delete failed: ' . (APP_DEBUG ? $e->getMessage() : 'constraint conflict')];
    }
}

// -----------------------------------------------------------------------------
// Phase 1: Programs & Admissions helpers
// -----------------------------------------------------------------------------

function admin_force_delete_user(int $userId, ?int $replacementInstructorId = null): array
{
    $user = db_query_one('SELECT id, role FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    if (isset($user['role']) && $user['role'] === 'admin') {
        return ['success' => false, 'message' => 'Cannot delete an admin user.'];
    }

    $db = Database::getInstance()->getConnection();
    try {
        $db->beginTransaction();

        // If user has a linked student profile, delete dependent student data first
        $student = db_query_one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
        if ($student) {
            $sid = (int)$student['id'];
            // Grades -> Attendance -> Enrollments chain
            db_execute('DELETE g FROM grades g INNER JOIN enrollments e ON e.id = g.enrollment_id WHERE e.student_id = ?', [$sid]);
            db_execute('DELETE a FROM attendance a INNER JOIN enrollments e ON e.id = a.enrollment_id WHERE e.student_id = ?', [$sid]);
            db_execute('DELETE FROM enrollments WHERE student_id = ?', [$sid]);

            // Waitlists
            db_execute('DELETE FROM waitlists WHERE student_id = ?', [$sid]);

            // Billing: payments then invoices
            db_execute('DELETE bp FROM billing_payments bp INNER JOIN billing_invoices bi ON bi.id = bp.invoice_id WHERE bi.student_id = ?', [$sid]);
            db_execute('DELETE FROM billing_invoices WHERE student_id = ?', [$sid]);

            // Legacy/other financial records
            db_execute('DELETE FROM fee_payments WHERE student_id = ?', [$sid]);

            // Advising
            db_execute('DELETE FROM student_advisors WHERE student_id = ?', [$sid]);

            // Documents owned by student
            db_execute("DELETE FROM documents WHERE owner_type = 'student' AND owner_id = ?", [$sid]);

            // Finally remove student profile
            db_execute('DELETE FROM students WHERE id = ?', [$sid]);
        }

        // If user has an instructor profile, reassign or block based on Option A
        $instructor = db_query_one('SELECT id FROM instructors WHERE user_id = ? LIMIT 1', [$userId]);
        if ($instructor) {
            $iid = (int)$instructor['id'];
            $section = db_query_one('SELECT id FROM course_sections WHERE instructor_id = ? LIMIT 1', [$iid]);
            $att = db_query_one('SELECT id FROM attendance WHERE marked_by = ? LIMIT 1', [$iid]);

            if ($section || $att) {
                if ($replacementInstructorId === null || $replacementInstructorId <= 0) {
                    throw new Exception('Replacement instructor required to reassign sections/attendance.');
                }
                // Verify replacement instructor exists
                $rep = db_query_one('SELECT id FROM instructors WHERE id = ? LIMIT 1', [$replacementInstructorId]);
                if (!$rep) {
                    throw new Exception('Replacement instructor not found.');
                }
                // Reassign course sections
                db_execute('UPDATE course_sections SET instructor_id = ? WHERE instructor_id = ?', [$replacementInstructorId, $iid]);
                // Reassign attendance marked_by
                db_execute('UPDATE attendance SET marked_by = ? WHERE marked_by = ?', [$replacementInstructorId, $iid]);
                // Reassign advising links to replacement
                db_execute('UPDATE student_advisors SET instructor_id = ? WHERE instructor_id = ?', [$replacementInstructorId, $iid]);
            }

            // Documents owned by instructor: always delete
            db_execute("DELETE FROM documents WHERE owner_type = 'instructor' AND owner_id = ?", [$iid]);

            // Remove instructor profile
            db_execute('DELETE FROM instructors WHERE id = ?', [$iid]);
        }

        // Common dependents tied to user id
        db_execute('DELETE FROM notifications WHERE user_id = ? OR sender_user_id = ?', [$userId, $userId]);
        db_execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        db_execute('DELETE FROM otp_codes WHERE user_id = ?', [$userId]);
        db_execute('DELETE FROM email_outbox WHERE to_user_id = ?', [$userId]);
        db_execute('DELETE FROM system_logs WHERE user_id = ?', [$userId]);
        db_execute('UPDATE applications SET user_id = NULL WHERE user_id = ?', [$userId]);
        db_execute('DELETE FROM user_roles WHERE user_id = ?', [$userId]);

        // Finally delete the user
        db_execute('DELETE FROM users WHERE id = ?', [$userId]);

        $db->commit();
        return ['success' => true, 'message' => 'User and linked data deleted.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        return ['success' => false, 'message' => (APP_DEBUG ? $e->getMessage() : 'Unable to force delete. Resolve linked records first.')];
    }
}

function get_programs(): array
{
    return db_query('SELECT p.id, p.program_code, p.program_name, p.total_credits, p.department_id, d.dept_name
                     FROM programs p
                     JOIN departments d ON d.id = p.department_id
                     ORDER BY p.program_name');
}

function get_program_by_id(int $programId): ?array
{
    if ($programId <= 0) return null;
    return db_query_one('SELECT id, program_code, program_name, total_credits, cutoff_aggregate, department_id FROM programs WHERE id = ? LIMIT 1', [$programId]);
}

function get_program_requirements(int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    return db_query(
        'SELECT pc.id, pc.program_id, pc.course_id, pc.term_number, pc.required,
                c.course_code, c.course_name, c.credits, c.level_id, c.department_id
         FROM program_courses pc
         JOIN courses c ON c.id = pc.course_id
         WHERE pc.program_id = ?
         ORDER BY COALESCE(pc.term_number, 999), c.course_code',
        [$programId]
    );
}

function get_course_prerequisites(int $courseId): array
{
    if ($courseId <= 0) {
        return [];
    }

    return db_query(
        'SELECT cp.id, cp.course_id, cp.prereq_course_id,
                prereq.course_code AS prereq_code,
                prereq.course_name AS prereq_name,
                prereq.credits AS prereq_credits
         FROM course_prerequisites cp
         JOIN courses prereq ON prereq.id = cp.prereq_course_id
         WHERE cp.course_id = ?
         ORDER BY prereq.course_code',
        [$courseId]
    );
}

function get_program_prerequisite_map(int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    $rows = db_query(
        'SELECT cp.course_id, cp.prereq_course_id
         FROM course_prerequisites cp
         JOIN program_courses pc ON pc.course_id = cp.course_id
         WHERE pc.program_id = ?',
        [$programId]
    );

    $map = [];
    foreach ($rows as $row) {
        $courseId = (int)$row['course_id'];
        $map[$courseId][] = (int)$row['prereq_course_id'];
    }

    return $map;
}

function get_student_course_completions(int $studentId): array
{
    if ($studentId <= 0) {
        return [];
    }

    $rows = db_query(
        'SELECT DISTINCT e.course_section_id, cs.course_id, c.course_code
         FROM enrollments e
         JOIN course_sections cs ON cs.id = e.course_section_id
         JOIN courses c ON c.id = cs.course_id
         WHERE e.student_id = ? AND e.status IN ("completed", "passed")',
        [$studentId]
    );

    $courseIds = [];
    foreach ($rows as $row) {
        $courseIds[(int)$row['course_id']] = true;
    }

    return array_keys($courseIds);
}

function get_student_program_progress(int $studentId, int $programId): array
{
    $requirements = get_program_requirements($programId);
    if (empty($requirements)) {
        return [
            'total_courses' => 0,
            'required_courses' => 0,
            'completed_courses' => 0,
            'required_completed' => 0,
            'elective_completed' => 0,
            'remaining_courses' => [],
            'remaining_required' => [],
            'remaining_electives' => [],
        ];
    }

    $completedCourseIds = get_student_course_completions($studentId);
    $completedLookup = array_flip($completedCourseIds);

    $requiredTotal = 0;
    $requiredCompleted = 0;
    $electiveCompleted = 0;
    $remainingRequired = [];
    $remainingElectives = [];

    foreach ($requirements as $req) {
        $isRequired = (int)$req['required'] === 1;
        $courseId = (int)$req['course_id'];
        $isCompleted = isset($completedLookup[$courseId]);

        if ($isRequired) {
            $requiredTotal++;
            if ($isCompleted) {
                $requiredCompleted++;
            } else {
                $remainingRequired[] = $req;
            }
        } else {
            if ($isCompleted) {
                $electiveCompleted++;
            } else {
                $remainingElectives[] = $req;
            }
        }
    }

    $remaining = array_merge($remainingRequired, $remainingElectives);

    return [
        'total_courses' => count($requirements),
        'required_courses' => $requiredTotal,
        'completed_courses' => count($completedCourseIds),
        'required_completed' => $requiredCompleted,
        'elective_completed' => $electiveCompleted,
        'remaining_courses' => $remaining,
        'remaining_required' => $remainingRequired,
        'remaining_electives' => $remainingElectives,
    ];
}

function create_program(string $code, string $name, int $departmentId, int $totalCredits): array
{
    $code = strtoupper(trim($code));
    $name = trim($name);
    $totalCredits = (int)$totalCredits;
    if ($code === '' || $name === '' || $departmentId <= 0 || $totalCredits <= 0) {
        return ['success' => false, 'message' => 'Code, name, department and total credits are required.'];
    }
    $dept = get_department_by_id($departmentId);
    if (!$dept) return ['success' => false, 'message' => 'Department not found.'];
    $dupe = db_query_one('SELECT id FROM programs WHERE program_code = ? LIMIT 1', [$code]);
    if ($dupe) return ['success' => false, 'message' => 'Program code already exists.'];
    db_execute('INSERT INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES (?, ?, ?, ?, NOW())', [$code, $name, $departmentId, $totalCredits]);
    return ['success' => true, 'message' => 'Program created successfully.'];
}

function update_program(int $programId, string $code, string $name, int $departmentId, int $totalCredits): array
{
    $programId = (int)$programId;
    $code = strtoupper(trim($code));
    $name = trim($name);
    $totalCredits = (int)$totalCredits;

    if ($programId <= 0) {
        return ['success' => false, 'message' => 'Invalid program identifier.'];
    }

    $existing = get_program_by_id($programId);
    if (!$existing) {
        return ['success' => false, 'message' => 'Program not found.'];
    }

    if ($code === '' || $name === '' || $departmentId <= 0 || $totalCredits <= 0) {
        return ['success' => false, 'message' => 'Code, name, department and total credits are required.'];
    }

    $dept = get_department_by_id($departmentId);
    if (!$dept) {
        return ['success' => false, 'message' => 'Department not found.'];
    }

    $dupe = db_query_one('SELECT id FROM programs WHERE program_code = ? AND id <> ? LIMIT 1', [$code, $programId]);
    if ($dupe) {
        return ['success' => false, 'message' => 'Program code already exists.'];
    }

    db_execute(
        'UPDATE programs SET program_code = ?, program_name = ?, department_id = ?, total_credits = ?, updated_at = NOW() WHERE id = ?',
        [$code, $name, $departmentId, $totalCredits, $programId]
    );

    return ['success' => true, 'message' => 'Program updated successfully.'];
}

function delete_program(int $programId): array
{
    $programId = (int)$programId;
    if ($programId <= 0) {
        return ['success' => false, 'message' => 'Invalid program identifier.'];
    }

    $program = get_program_by_id($programId);
    if (!$program) {
        return ['success' => false, 'message' => 'Program not found.'];
    }

    $dependencyChecks = [
        ['sql' => 'SELECT COUNT(*) AS cnt FROM students WHERE program_id = ?', 'label' => 'students'],
        ['sql' => 'SELECT COUNT(*) AS cnt FROM program_courses WHERE program_id = ?', 'label' => 'program requirements'],
        ['sql' => 'SELECT COUNT(*) AS cnt FROM applications WHERE program_id = ?', 'label' => 'applications'],
    ];

    foreach ($dependencyChecks as $check) {
        $row = db_query_one($check['sql'], [$programId]);
        $count = (int)($row['cnt'] ?? 0);
        if ($count > 0) {
            return ['success' => false, 'message' => 'Cannot delete program: remove associated ' . $check['label'] . ' first.'];
        }
    }

    db_execute('DELETE FROM programs WHERE id = ?', [$programId]);

    return ['success' => true, 'message' => 'Program deleted successfully.'];
}

function create_application(array $data): array
{
    $first = trim($data['first_name'] ?? '');
    $last = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $programId = (int)($data['program_id'] ?? 0);
    $agg = isset($data['wasse_aggregate']) ? (int)$data['wasse_aggregate'] : null;
    $uid = current_user_id();

    if ($first === '' || $last === '' || $email === '' || $programId <= 0) {
        return ['success' => false, 'message' => 'First name, last name, email and program are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address.'];
    }
    if (!$uid) {
        $linkedUser = db_query_one('SELECT id, role FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($linkedUser) {
            $uid = (int)$linkedUser['id'];
        } else {
            // Create lightweight applicant account
            $baseUsername = strtolower(preg_replace('/[^a-z0-9_]+/','', explode('@', $email)[0]));
            if ($baseUsername === '') { $baseUsername = 'applicant'; }
            $username = $baseUsername;
            $try = 1;
            while (db_query_one('SELECT id FROM users WHERE username = ? LIMIT 1', [$username])) {
                $try++;
                $username = $baseUsername . $try;
            }
            // Random temp password (not used if passwordless/OTP is employed)
            $tmpPass = generate_temporary_password(12);
            db_execute('INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, "applicant", 1, NOW())', [
                $username, $email, hash_password($tmpPass)
            ]);
            $uid = (int)db_last_id();
        }
    }
    $program = get_program_by_id($programId);
    if (!$program) {
        return ['success' => false, 'message' => 'Selected program does not exist.'];
    }

    // Determine initial status based on cutoff
    $status = 'under_review';
    $reason = null;
    if ($agg !== null && isset($program['cutoff_aggregate']) && $program['cutoff_aggregate'] !== null) {
        if ($agg > (int)$program['cutoff_aggregate']) {
            $status = 'rejected';
            $reason = 'Aggregate exceeds program cutoff (' . (int)$program['cutoff_aggregate'] . ').';
        }
    }

    // Generate application key
    $appKey = bin2hex(random_bytes(32));
    db_execute('INSERT INTO applications (prospect_email, first_name, last_name, phone, program_id, wasse_aggregate, user_id, application_key, status, submitted_at, decided_at, decided_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), CASE WHEN ? = "rejected" THEN NOW() ELSE NULL END, ?)',
        [$email, $first, $last, $phone, $programId, $agg, $uid, $appKey, $status, $status, $reason]);

    // Send acknowledgment email
    $progName = $program['program_name'] ?? 'your selected program';
    send_admissions_email($email, $first . ' ' . $last, 'received', 'We have received your application for ' . $progName . '. Your application key is ' . $appKey . '.');

    if ($status === 'rejected') {
        return ['success' => true, 'message' => 'Application submitted. Unfortunately, your aggregate does not meet the program cutoff.'];
    }
    return ['success' => true, 'message' => 'Application submitted successfully. You will be contacted after review.'];
}

function get_applications_admin(?string $status = null): array
{
    $sql = 'SELECT a.*, p.program_name, p.cutoff_aggregate FROM applications a JOIN programs p ON a.program_id = p.id';
    $args = [];
    if ($status && in_array($status, ['applied','under_review','offered','accepted','rejected'], true)) {
        $sql .= ' WHERE a.status = ?';
        $args[] = $status;
    }
    $sql .= ' ORDER BY a.submitted_at DESC';
    return db_query($sql, $args);
}

function set_application_status(int $appId, string $status, ?string $offerNotes = null): array
{
    $allowed = ['under_review','offered','accepted','rejected'];
    if ($appId <= 0 || !in_array($status, $allowed, true)) {
        return ['success' => false, 'message' => 'Invalid status update.'];
    }
    if ($status === 'offered') {
        // Update and notify offer 
        db_execute('UPDATE applications SET status = ?, offer_notes = ?, decided_at = NOW() WHERE id = ?', [$status, $offerNotes, $appId]);
        $appOffer = db_query_one('SELECT * FROM applications WHERE id = ? LIMIT 1', [$appId]);
        if ($appOffer) {
            send_admissions_email($appOffer['prospect_email'], $appOffer['first_name'] . ' ' . $appOffer['last_name'], 'offered', $offerNotes ?: 'Congratulations - Offer of Admission');
        }
    } elseif ($status === 'accepted') {
        // Accept: provision user and student if not exists
        $app = db_query_one('SELECT a.*, p.department_id FROM applications a JOIN programs p ON a.program_id = p.id WHERE a.id = ? LIMIT 1', [$appId]);
        if (!$app) return ['success' => false, 'message' => 'Application not found.'];

        // Create user account if email not in users
        $user = db_query_one('SELECT id, username FROM users WHERE email = ? LIMIT 1', [$app['prospect_email']]);
        $userId = $user['id'] ?? null;
        if (!$userId) {
            $baseUsername = strtolower(preg_replace('/[^a-z0-9_]+/','', explode('@', $app['prospect_email'])[0]));
            if ($baseUsername === '') { $baseUsername = 'student'; }
            $username = $baseUsername;
            $try = 1;
            while (db_query_one('SELECT id FROM users WHERE username = ? LIMIT 1', [$username])) {
                $try++;
                $username = $baseUsername . $try;
            }
            $tempPass = generate_temporary_password(12);
            db_execute('INSERT INTO users (username, email, password_hash, role, is_active, must_change_password, created_at) VALUES (?, ?, ?, "student", 1, 1, NOW())', [$username, $app['prospect_email'], hash_password($tempPass)]);
            $userId = (int)db_last_id();

            // Email outbox
            db_execute('INSERT INTO email_outbox (to_user_id, subject, body, template, created_at) VALUES (?, ?, ?, ?, NOW())', [
                $userId,
                'Offer Accepted - Account Created',
                'Your application has been accepted. Your account has been created. Username: ' . $username . '. Temporary password: ' . $tempPass,
                'admissions_accept',
            ]);
        } else {
            // Existing user: generate a fresh temporary password and enforce change
            $username = $user['username'] ?? null;
            if (!$username) {
                $u = db_query_one('SELECT username FROM users WHERE id = ? LIMIT 1', [$userId]);
                $username = $u['username'] ?? '';
            }
            $tempPass = generate_temporary_password(12);
            db_execute('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [hash_password($tempPass), $userId]);
        }

        // Create student record if missing
        $stu = db_query_one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
        if (!$stu) {
            $level = db_query_one('SELECT id FROM levels ORDER BY level_order LIMIT 1');
            $levelId = $level ? (int)$level['id'] : 1;
            $studentNum = (int)(db_query_one('SELECT COUNT(*) as c FROM students')['c'] ?? 0) + 1;
            $studId = function_exists('generate_student_id') ? generate_student_id(date('Y'), 'GEN', $studentNum) : ('ST' . date('y') . str_pad((string)$studentNum, 4, '0', STR_PAD_LEFT));
            db_execute('INSERT INTO students (student_id, first_name, last_name, email, phone, date_of_birth, address, current_level_id, user_id, department_id, program_id, enrollment_date, status, gpa, created_at, updated_at) VALUES
                (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, CURDATE(), "active", 0.00, NOW(), NOW())', [
                $studId, $app['first_name'], $app['last_name'], $app['prospect_email'], $app['phone'], $levelId, $userId, (int)$app['department_id'], (int)$app['program_id']
            ]);
        }

        // Mark application accepted and send detailed instructions (always includes credentials)
        db_execute('UPDATE applications SET status = ?, decided_at = NOW() WHERE id = ?', ['accepted', $appId]);

        $loginUrl = SITE_URL . '/login.php';
        $forgotUrl = SITE_URL . '/forgot_password.php';

        // Always send credentials and links
        $html = '<p>Hello ' . htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) . ',</p>'
              . '<p>Congratulations! Your application has been <strong>accepted</strong>.</p>'
              . '<p>Your login details:</p>'
              . '<ul>'
              . '<li><strong>Username:</strong> ' . htmlspecialchars($username) . '</li>'
              . '<li><strong>Temporary password:</strong> ' . htmlspecialchars($tempPass) . '</li>'
              . '</ul>'
              . '<p><a href="' . htmlspecialchars($loginUrl) . '">Log in here</a>. You will be prompted to change your password.</p>'
              . '<p>Regards,<br>Admissions Office</p>';
        $text = "Hello " . $app['first_name'] . ' ' . $app['last_name'] . ",\n\n"
              . "Congratulations! Your application has been accepted.\n\n"
              . "Your login details:\n"
              . "- Username: " . $username . "\n"
              . "- Temporary password: " . $tempPass . "\n\n"
              . "Log in: " . $loginUrl . "\n\n"
              . "Regards,\nAdmissions Office";
        send_email($app['prospect_email'], '[Admissions] Application Accepted', $html, $text);
    } else {
        // under_review or rejected
        db_execute('UPDATE applications SET status = ?, decided_at = NOW() WHERE id = ?', [$status, $appId]);
        $app2 = db_query_one('SELECT * FROM applications WHERE id = ? LIMIT 1', [$appId]);
        if ($app2) {
            send_admissions_email($app2['prospect_email'], $app2['first_name'] . ' ' . $app2['last_name'], $status, $status === 'rejected' ? ($app2['decided_reason'] ?? 'We are unable to proceed with your application at this time.') : 'Your application status has been updated.');
        }
    }
    return ['success' => true, 'message' => 'Application updated.'];
}

function send_admissions_email(string $toEmail, string $name, string $status, string $message): void
{
    $subject = '[Admissions] Application ' . ucfirst(str_replace('_',' ',$status));
    $html = '<p>Hello ' . htmlspecialchars($name) . ',</p>'
          . '<p>' . htmlspecialchars($message) . '</p>'
          . '<p>Regards,<br>Admissions Office</p>';
    $text = "Hello $name,\n\n$message\n\nRegards,\nAdmissions Office";
    send_email($toEmail, $subject, $html, $text);
}

// Additional Admissions helpers
function get_application_by_id(int $appId): ?array
{
    if ($appId <= 0) return null;
    return db_query_one(
        'SELECT a.*, p.program_name, p.department_id, p.cutoff_aggregate
         FROM applications a
         JOIN programs p ON a.program_id = p.id
         WHERE a.id = ? LIMIT 1',
        [$appId]
    );
}

function add_application_note(int $appId, int $userId, string $note): void
{
    $note = trim($note);
    if ($appId <= 0 || $userId <= 0 || $note === '') return;
    db_execute(
        'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        [
            $userId,
            'admissions_note',
            'application',
            $appId,
            null,
            json_encode(['note' => $note], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]
    );
}

function get_application_notes(int $appId): array
{
    if ($appId <= 0) return [];
    return db_query(
        'SELECT id, user_id, new_values, created_at
         FROM system_logs
         WHERE action = "admissions_note" AND entity_type = "application" AND entity_id = ?
         ORDER BY created_at DESC',
        [$appId]
    );
}

function search_applications_admin(?string $status, ?string $q, ?int $programId, int $page, int $pageSize): array
{
    $page = max(1, $page);
    $pageSize = max(1, min(100, $pageSize));
    $offset = ($page - 1) * $pageSize;

    $where = [];
    $args = [];
    if ($status && in_array($status, ['applied','under_review','offered','accepted','rejected'], true)) {
        $where[] = 'a.status = ?';
        $args[] = $status;
    }
    if ($q) {
        $q = '%' . trim($q) . '%';
        $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.prospect_email LIKE ? OR p.program_name LIKE ? OR p.program_code LIKE ?)';
        array_push($args, $q, $q, $q, $q, $q);
    }
    if ($programId && $programId > 0) {
        $where[] = 'a.program_id = ?';
        $args[] = $programId;
    }

    $sqlBase = 'FROM applications a JOIN programs p ON a.program_id = p.id';
    if (!empty($where)) {
        $sqlBase .= ' WHERE ' . implode(' AND ', $where);
    }

    $totalRow = db_query_one('SELECT COUNT(*) AS c ' . $sqlBase, $args);
    $total = (int)($totalRow['c'] ?? 0);

    $rows = db_query(
        'SELECT a.*, p.program_name, p.cutoff_aggregate ' . $sqlBase . ' ORDER BY a.submitted_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset,
        $args
    );

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'pages' => (int)ceil($total / $pageSize),
    ];
}

function purge_accepted_applications(int $days = 7): void
{
    $days = max(1, $days);
    $interval = 'INTERVAL ' . (int)$days . ' DAY';
    db_execute('DELETE FROM applications WHERE status = "accepted" AND decided_at IS NOT NULL AND decided_at < DATE_SUB(NOW(), ' . $interval . ')');
}

// -----------------------------------------------------------------------------
// Applications portal helpers
// -----------------------------------------------------------------------------

function get_user_applications(int $userId): array
{
    return db_query('SELECT a.*, p.program_name, p.cutoff_aggregate FROM applications a JOIN programs p ON p.id = a.program_id WHERE a.user_id = ? ORDER BY a.submitted_at DESC', [$userId]);
}

function finalize_registration_by_key(string $key, int $userId): array
{
    $app = db_query_one('SELECT a.*, p.department_id FROM applications a JOIN programs p ON p.id = a.program_id WHERE a.application_key = ? AND a.user_id = ? LIMIT 1', [$key, $userId]);
    if (!$app) return ['success' => false, 'message' => 'Invalid application key.'];
    if ($app['status'] !== 'accepted') return ['success' => false, 'message' => 'Application is not accepted yet.'];

    // Ensure user exists
    $user = db_query_one('SELECT id FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$user) return ['success' => false, 'message' => 'User not found.'];

    // Create student record if missing
    $stu = db_query_one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
    if (!$stu) {
        $level = db_query_one('SELECT id FROM levels ORDER BY level_order LIMIT 1');
        $levelId = $level ? (int)$level['id'] : 1;
        $studentNum = (int)(db_query_one('SELECT COUNT(*) as c FROM students')['c'] ?? 0) + 1;
        $studId = function_exists('generate_student_id') ? generate_student_id(date('Y'), 'GEN', $studentNum) : ('ST' . date('y') . str_pad((string)$studentNum, 4, '0', STR_PAD_LEFT));
        db_execute('INSERT INTO students (student_id, first_name, last_name, email, phone, date_of_birth, address, current_level_id, user_id, department_id, program_id, enrollment_date, status, gpa, created_at, updated_at) VALUES
            (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, CURDATE(), "active", 0.00, NOW(), NOW())', [
            $studId, $app['first_name'], $app['last_name'], $app['prospect_email'], $app['phone'], $levelId, $userId, (int)$app['department_id'], (int)$app['program_id']
        ]);
    }

    return ['success' => true, 'message' => 'Student onboarding complete. You now have access to student features.'];
}

// -----------------------------------------------------------------------------
// GPA helpers
// -----------------------------------------------------------------------------

function get_gpa_by_term(int $studentId): array
{
    if ($studentId <= 0) return [];
    return db_query(
        'SELECT 
            ay.year_name,
            s.semester_name,
            e.academic_year_id,
            e.semester_id,
            ROUND(SUM(e.grade_points) / NULLIF(SUM(c.credits), 0), 2) AS term_gpa,
            SUM(c.credits) AS term_credits
         FROM enrollments e
         JOIN course_sections cs ON e.course_section_id = cs.id
         JOIN courses c ON cs.course_id = c.id
         JOIN academic_years ay ON e.academic_year_id = ay.id
         JOIN semesters s ON e.semester_id = s.id
         WHERE e.student_id = ? AND e.final_grade IS NOT NULL
         GROUP BY e.academic_year_id, e.semester_id, ay.year_name, s.semester_name
         ORDER BY ay.start_date, s.start_date',
        [$studentId]
    );
}

// -----------------------------------------------------------------------------
// Session & authentication helpers
// -----------------------------------------------------------------------------

function refresh_session_activity(): void
{
    $now = time();
    $lastActivity = $_SESSION['last_activity'] ?? $now;

    if (isset($_SESSION['user_id']) && ($now - $lastActivity) > SESSION_TIMEOUT) {
        logout_user();
        set_flash_message('error', 'Your session has expired. Please log in again.');
        redirect('/login.php');
    }

    $_SESSION['last_activity'] = $now;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Resolve the instructors.id for the currently logged-in instructor user.
 * Returns null if the user is not an instructor or no instructor record exists.
 */
function get_current_instructor_id(): ?int
{
    $uid = current_user_id();
    if (!$uid) return null;
    $row = db_query_one('SELECT id FROM instructors WHERE user_id = ? LIMIT 1', [$uid]);
    return $row ? (int)$row['id'] : null;
}

/**
 * Resolve instructors.id for a given users.id
 */
function get_instructor_id_by_user(int $userId): ?int
{
    if ($userId <= 0) return null;
    $row = db_query_one('SELECT id FROM instructors WHERE user_id = ? LIMIT 1', [$userId]);
    return $row ? (int)$row['id'] : null;
}

function current_username(): ?string
{
    return $_SESSION['username'] ?? null;
}

function get_user_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function login_user(string $username, string $password): array
{
    $username = trim($username);
    $user = db_query_one('SELECT * FROM users WHERE username = ? AND is_active = 1', [$username]);

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    if (!empty($user['graduation_lock_at']) && strtotime($user['graduation_lock_at']) <= time()) {
        db_execute('UPDATE users SET is_active = 0 WHERE id = ?', [$user['id']]);
        db_execute(
            'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $user['id'],
                'graduation_login_blocked',
                'user',
                $user['id'],
                json_encode(['is_active' => 1, 'graduation_lock_at' => $user['graduation_lock_at']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode(['attempt' => 'login_after_graduation_lock'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]
        );

        return ['success' => false, 'message' => 'Your student account has been transitioned after graduation. Please contact administration for assistance.'];
    }

    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        return ['success' => false, 'message' => 'Account locked due to multiple failed attempts. Try again later.'];
    }

    if (!verify_password($password, $user['password_hash'])) {
        $attempts = (int)($user['login_attempts'] ?? 0) + 1;
        $lockedUntil = null;

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_TIME);
            $attempts = 0;
        }

        db_execute('UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?', [$attempts, $lockedUntil, $user['id']]);

        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    db_execute('UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?', [$user['id']]);

    // Determine if MFA (email OTP) is required
    $role = $user['role'];
    $mfaEnabled = (int)($user['mfa_email_enabled'] ?? 0) === 1;
    $needsMfa = ($role === 'admin') || (($role === 'instructor' || $role === 'student') && $mfaEnabled);

    // Trusted device skip logic (admins primarily):
    // Skip MFA only if a valid trusted device token exists AND risk is low
    $riskHigh = ((int)($user['login_attempts'] ?? 0) >= 3) || ((int)($user['must_change_password'] ?? 0) === 1);
    if ($needsMfa && !$riskHigh) {
        if (verify_trusted_device_cookie((int)$user['id'])) {
            $needsMfa = false; // trusted device: bypass MFA this time
        }
    }

    if ($needsMfa) {
        // Generate and send OTP, set pending MFA session, but DO NOT complete login yet
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
        $_SESSION['pending_mfa_user_id'] = (int)$user['id'];
        $_SESSION['pending_mfa_started_at'] = time();
        // 10-minute expiry
        generate_email_otp((int)$user['id'], 'mfa', 600);

        // Log pending login (no last_login yet)
        db_execute(
            'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $user['id'],
                'login_mfa_challenge',
                'user',
                $user['id'],
                null,
                json_encode(['username' => $user['username']]),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]
        );

        return ['success' => true, 'require_mfa' => true, 'user' => ['id' => (int)$user['id'], 'role' => $role, 'username' => $user['username']]];
    }

    // Complete normal login
    db_execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

    db_execute(
        'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $user['id'],
            'login_success',
            'user',
            $user['id'],
            null,
            json_encode(['username' => $user['username']]),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]
    );

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0) === 1;

    refresh_current_academic_term();

    return ['success' => true, 'user' => $user];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash_message('error', 'Please log in to access this page.');
        redirect('/login.php');
    }

    refresh_current_academic_term();
    refresh_session_activity();
}

/**
 * Enforce authentication with optional role requirement.
 * @param mixed $role string|array|null
 */
function require_auth($role = null): void
{
    require_login();

    if ($role !== null) {
        require_role($role);
    }

    // Enforce mandatory password change
    $path = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!empty($_SESSION['must_change_password']) && strpos($path, '/force_change_password.php') === false) {
        redirect('/force_change_password.php');
    }

    set_security_headers();
}

/**
 * Enforce role requirement.
 * @param mixed $role string|array
 */
function require_role($role): void
{
    require_login();

    $roles = is_array($role) ? $role : [$role];
    $userRole = get_user_role();

    if (!in_array($userRole, $roles, true)) {
        set_flash_message('error', 'You do not have permission to access that page.');
        redirect('/login.php');
    }
}

// -----------------------------------------------------------------------------
// Security headers
// -----------------------------------------------------------------------------

function set_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');

    $cspParts = [
        "default-src 'self'",
        "img-src 'self' data:",
        "style-src 'self' 'unsafe-inline'",
        "font-src 'self'",
        "script-src 'self'",
    ];

    header('Content-Security-Policy: ' . implode('; ', $cspParts));

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// -----------------------------------------------------------------------------
// Domain helpers
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// Trusted device helpers for MFA skip
// -----------------------------------------------------------------------------

function get_app_secret(): string
{
    // Derive a stable secret from available config; override via config.local.php by defining APP_SECRET
    if (defined('APP_SECRET') && APP_SECRET) { return (string)APP_SECRET; }
    $base = (DB_NAME ?? 'db') . '|' . (DB_USER ?? 'user') . '|' . substr((string)(DB_PASS ?? 'pass'), 0, 16);
    return hash('sha256', $base);
}

function get_client_ip(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
        $parts = explode(',', $ip);
        $ip = trim($parts[0]);
    }
    return $ip;
}

function get_ip_prefix(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return count($parts) >= 2 ? ($parts[0] . '.' . $parts[1]) : $ip;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // Use first 3 hextets as a coarse prefix
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 3));
    }
    return $ip;
}

function get_ua_hash(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return substr(hash('sha256', $ua), 0, 16);
}

function mfa_trusted_cookie_name(int $userId): string
{
    return 'mfa_trust_' . (int)$userId;
}

function set_trusted_device_cookie(int $userId, int $days = 30): void
{
    $expires = time() + ($days * 86400);
    $ipPrefix = get_ip_prefix(get_client_ip());
    $uaHash = get_ua_hash();
    $nonce = bin2hex(random_bytes(8));
    $payload = $userId . '|' . $expires . '|' . $uaHash . '|' . $ipPrefix . '|' . $nonce;
    $sig = hash_hmac('sha256', $payload, get_app_secret());
    $token = base64_encode($payload) . '.' . $sig;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // Register server-side trusted device entry
    ensure_trusted_devices_table();
    $expiresAt = date('Y-m-d H:i:s', $expires);
    $nonceHash = hash('sha256', $nonce);
    db_execute(
        'INSERT INTO trusted_devices (user_id, nonce_hash, ua_hash, ip_prefix, expires_at, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
        [$userId, $nonceHash, $uaHash, $ipPrefix, $expiresAt]
    );

    setcookie(mfa_trusted_cookie_name($userId), $token, [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_trusted_device_cookie(int $userId): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(mfa_trusted_cookie_name($userId), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function verify_trusted_device_cookie(int $userId): bool
{
    $name = mfa_trusted_cookie_name($userId);
    if (empty($_COOKIE[$name])) { return false; }
    $raw = (string)$_COOKIE[$name];
    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) { return false; }
    [$b64, $sig] = $parts;
    $payload = base64_decode($b64, true);
    if ($payload === false) { return false; }
    $expect = hash_hmac('sha256', $payload, get_app_secret());
    if (!hash_equals($expect, $sig)) { return false; }
    $items = explode('|', $payload);
    if (count($items) < 5) { return false; }
    [$uid, $exp, $uaHash, $ipPrefix, $nonce] = [$items[0], $items[1], $items[2], $items[3], $items[4]];
    if ((int)$uid !== $userId) { return false; }
    if ((int)$exp < time()) { return false; }

    // Risk check: require similar UA and coarse IP prefix
    $curUa = get_ua_hash();
    $curIpPrefix = get_ip_prefix(get_client_ip());
    if (!hash_equals($uaHash, $curUa)) { return false; }
    if ($ipPrefix !== $curIpPrefix) { return false; }

    // Verify server-side registry entry exists and not revoked
    ensure_trusted_devices_table();
    $nonceHash = hash('sha256', $nonce);
    $row = db_query_one('SELECT id, expires_at, revoked_at, ua_hash AS db_ua, ip_prefix AS db_ip FROM trusted_devices WHERE user_id = ? AND nonce_hash = ? LIMIT 1', [$userId, $nonceHash]);
    if (!$row) { return false; }
    if (!empty($row['revoked_at'])) { return false; }
    if (strtotime((string)$row['expires_at']) < time()) { return false; }
    // Optional: also ensure stored UA/IP match
    if (!hash_equals((string)$row['db_ua'], $uaHash)) { return false; }
    if ((string)$row['db_ip'] !== $ipPrefix) { return false; }
    return true;
}

function ensure_trusted_devices_table(): void
{
    // Create table if missing
    db_execute('CREATE TABLE IF NOT EXISTS trusted_devices (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        nonce_hash CHAR(64) NOT NULL,
        ua_hash VARCHAR(32) NOT NULL,
        ip_prefix VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        INDEX idx_user (user_id),
        INDEX idx_user_nonce (user_id, nonce_hash),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )');
}

function forget_all_trusted_devices(int $userId): void
{
    ensure_trusted_devices_table();
    db_execute('UPDATE trusted_devices SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
}

function get_trusted_devices(int $userId): array
{
    ensure_trusted_devices_table();
    return db_query('SELECT id, ua_hash, ip_prefix, created_at, expires_at, revoked_at FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC', [$userId]);
}

function current_trusted_nonce_hash(int $userId): ?string
{
    $name = mfa_trusted_cookie_name($userId);
    if (empty($_COOKIE[$name])) { return null; }
    $raw = (string)$_COOKIE[$name];
    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) { return null; }
    $payload = base64_decode($parts[0], true);
    if ($payload === false) { return null; }
    $items = explode('|', $payload);
    if (count($items) < 5) { return null; }
    $nonce = $items[4];
    return hash('sha256', $nonce);
}

function revoke_trusted_device(int $userId, int $deviceId): bool
{
    ensure_trusted_devices_table();
    // If revoking current device, also clear the cookie
    $curHash = current_trusted_nonce_hash($userId);
    if ($curHash) {
        $row = db_query_one('SELECT nonce_hash FROM trusted_devices WHERE id = ? AND user_id = ? LIMIT 1', [$deviceId, $userId]);
        if ($row && hash_equals((string)$row['nonce_hash'], $curHash)) {
            clear_trusted_device_cookie($userId);
        }
    }
    return db_execute('UPDATE trusted_devices SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$deviceId, $userId]);
}

function format_date(?string $date): string
{
    if (!$date) {
        return '-';
    }
    return date('M j, Y', strtotime($date));
}

function format_datetime(?string $datetime): string
{
    if (!$datetime) {
        return '-';
    }
    return date('M j, Y g:i A', strtotime($datetime));
}

function format_currency(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function generate_student_id(string $year, string $deptCode, int $sequence): string
{
    return strtoupper($year . $deptCode . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT));
}

function enroll_student(int $studentId, int $sectionId, int $semesterId, int $academicYearId, bool $ignoreWindow = false): array
{
    // Already enrolled in this section for this semester?
    $exists = db_query_one('SELECT id FROM enrollments WHERE student_id = ? AND course_section_id = ? AND semester_id = ?', [$studentId, $sectionId, $semesterId]);
    if ($exists) {
        return ['success' => false, 'message' => 'You are already enrolled in this section.'];
    }

    // Load section, course, and term dates
    $row = db_query_one(
        'SELECT cs.id AS section_id, cs.capacity, cs.enrolled_count, cs.semester_id, cs.academic_year_id,
                c.id AS course_id, c.course_code, c.level_id, c.prerequisites,
                sem.start_date, sem.end_date, sem.registration_deadline
         FROM course_sections cs
         JOIN courses c ON c.id = cs.course_id
         JOIN semesters sem ON sem.id = cs.semester_id
         WHERE cs.id = ?
         LIMIT 1',
        [$sectionId]
    );
    if (!$row) {
        return ['success' => false, 'message' => 'Selected section was not found.'];
    }
    if ((int)$row['semester_id'] !== $semesterId || (int)$row['academic_year_id'] !== $academicYearId) {
        return ['success' => false, 'message' => 'Section does not belong to the selected term.'];
    }

    $courseId = (int)$row['course_id'];

    // Enrollment window (between semester start and registration_deadline if set; otherwise a configured window from start_date, else end_date)
    if (!$ignoreWindow) {
        $today = date('Y-m-d');
        if (!empty($row['start_date']) && $today < $row['start_date']) {
            return ['success' => false, 'message' => 'Enrollment has not opened yet for this term.'];
        }
        $closeDate = null;
        if (!empty($row['registration_deadline'])) {
            $closeDate = $row['registration_deadline'];
        } elseif (defined('REGISTRATION_WINDOW_DAYS') && REGISTRATION_WINDOW_DAYS > 0 && !empty($row['start_date'])) {
            $closeDate = date('Y-m-d', strtotime($row['start_date'] . ' +' . (int)REGISTRATION_WINDOW_DAYS . ' days'));
        } else {
            $closeDate = $row['end_date'] ?? null;
        }
        if (!empty($closeDate) && $today > $closeDate) {
            return ['success' => false, 'message' => 'Enrollment is closed for this term.'];
        }
    }

    // Level check: student level order must match course level order exactly
    $studentLevel = db_query_one('SELECT s.current_level_id, s.program_id, l.level_order FROM students s JOIN levels l ON s.current_level_id = l.id WHERE s.id = ? LIMIT 1', [$studentId]);
    $courseLevel = get_level_by_id((int)$row['level_id']);
    if ($studentLevel && $courseLevel) {
        $studentOrder = (int)$studentLevel['level_order'];
        $courseOrder = (int)$courseLevel['level_order'];
        if ($studentOrder !== $courseOrder) {
            return ['success' => false, 'message' => 'You can only enroll in courses that match your current level.'];
        }
    }

    // Prerequisites check: comma-separated course codes in courses.prerequisites
    $prereq = trim((string)($row['prerequisites'] ?? ''));
    if ($prereq !== '') {
        $requiredCodes = array_values(array_filter(array_map(function($x){ return strtoupper(trim($x)); }, explode(',', $prereq))));
        if (!empty($requiredCodes)) {
            $completed = get_student_completed_course_codes($studentId);
            $missing = array_values(array_diff($requiredCodes, $completed));
            if (!empty($missing)) {
                return ['success' => false, 'message' => 'Missing prerequisites: ' . implode(', ', $missing) . '.'];
            }
        }
    }

    // Program-specific prerequisite check (course_prerequisites table)
    $programId = $studentLevel ? (int)($studentLevel['program_id'] ?? 0) : 0;
    if ($programId > 0) {
        $coursePrereqs = db_query(
            'SELECT cp.prereq_course_id, prereq.course_code
             FROM course_prerequisites cp
             JOIN program_courses pc ON pc.course_id = cp.course_id
             JOIN courses prereq ON prereq.id = cp.prereq_course_id
             WHERE cp.course_id = ? AND pc.program_id = ?
             ORDER BY prereq.course_code',
            [$courseId, $programId]
        );

        if (!empty($coursePrereqs)) {
            $completedCourseIds = get_student_course_completions($studentId);
            $completedLookup = array_flip($completedCourseIds);

            $missingProgramPrereqs = [];
            foreach ($coursePrereqs as $pr) {
                $prereqId = (int)$pr['prereq_course_id'];
                if (!isset($completedLookup[$prereqId])) {
                    $missingProgramPrereqs[] = $pr['course_code'];
                }
            }

            if (!empty($missingProgramPrereqs)) {
                return ['success' => false, 'message' => 'Missing prerequisite courses for your program: ' . implode(', ', $missingProgramPrereqs) . '.'];
            }
        }
    }

    // Capacity / waitlist
    if ((int)$row['enrolled_count'] >= (int)$row['capacity']) {
        ensure_waitlists_table();
        // Add to waitlist if not already there
        $wl = db_query_one('SELECT id FROM waitlists WHERE student_id = ? AND course_section_id = ? LIMIT 1', [$studentId, $sectionId]);
        if ($wl) {
            // Notify student (already on waitlist)
            $u = db_query_one('SELECT user_id FROM students WHERE id = ? LIMIT 1', [$studentId]);
            if ($u) { send_notification_to_user((int)$u['user_id'], 'Waitlist status', 'You are already on the waitlist for ' . $row['course_code'] . ' - Section ' . $row['section_id'] . '.', 'info'); }
            return ['success' => true, 'message' => 'Section is full. You are already on the waitlist for this section.'];
        }
        db_execute('INSERT INTO waitlists (student_id, course_section_id, requested_at) VALUES (?, ?, NOW())', [$studentId, $sectionId]);
        // Notify student added to waitlist
        $u = db_query_one('SELECT user_id FROM students WHERE id = ? LIMIT 1', [$studentId]);
        if ($u) { send_notification_to_user((int)$u['user_id'], 'Added to waitlist', 'You have been added to the waitlist for ' . $row['course_code'] . ' - Section ' . $row['section_id'] . '.', 'warning'); }
        return ['success' => true, 'message' => 'Section is full. You have been added to the waitlist.'];
    }

    // Create enrollment
    db_execute('INSERT INTO enrollments (student_id, course_section_id, semester_id, academic_year_id, status, enrollment_date)
                VALUES (?, ?, ?, ?, ?, NOW())', [$studentId, $sectionId, $semesterId, $academicYearId, 'enrolled']);
    db_execute('UPDATE course_sections SET enrolled_count = enrolled_count + 1 WHERE id = ?', [$sectionId]);
    // Notify student of successful enrollment
    $u = db_query_one('SELECT user_id FROM students WHERE id = ? LIMIT 1', [$studentId]);
    if ($u) { send_notification_to_user((int)$u['user_id'], 'Enrollment confirmed', 'You are enrolled in ' . $row['course_code'] . ' - Section ' . $row['section_id'] . '.', 'success'); }

    return ['success' => true, 'message' => 'Enrolled successfully.'];
}

// Permission check: can this user override enrollment windows/capacity for a section?
function can_user_enroll_override(int $actorUserId, int $sectionId): bool
{
    if ($actorUserId <= 0 || $sectionId <= 0) return false;
    $u = db_query_one('SELECT id, role FROM users WHERE id = ? LIMIT 1', [$actorUserId]);
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if ($u['role'] !== 'instructor') return false;

    // Check if instructor owns the section
    $instr = db_query_one('SELECT i.id FROM instructors i JOIN course_sections cs ON cs.instructor_id = i.id WHERE i.user_id = ? AND cs.id = ? LIMIT 1', [$actorUserId, $sectionId]);
    if ($instr) return true;

    // Or instructor in same department as the course's department
    $sameDept = db_query_one('SELECT i.id FROM instructors i JOIN course_sections cs ON cs.id = ? JOIN courses c ON c.id = cs.course_id WHERE i.user_id = ? AND i.department_id = c.department_id LIMIT 1', [$sectionId, $actorUserId]);
    return (bool)$sameDept;
}

// Manual enrollment by admin/instructor: can bypass enrollment window; can optionally override capacity
function enroll_student_manual(int $actorUserId, int $studentId, int $sectionId, int $semesterId, int $academicYearId, bool $forceCapacity = false): array
{
    if (!can_user_enroll_override($actorUserId, $sectionId)) {
        return ['success' => false, 'message' => 'You do not have permission to manually enroll in this section.'];
    }

    // First try normal enroll with window bypass
    $res = enroll_student($studentId, $sectionId, $semesterId, $academicYearId, true);
    if ($res['success']) return $res;

    // If capacity is full and force allowed, insert anyway
    if ($forceCapacity) {
        // Reload minimal section info and ensure not already enrolled
        $exists = db_query_one('SELECT id FROM enrollments WHERE student_id = ? AND course_section_id = ? AND semester_id = ?', [$studentId, $sectionId, $semesterId]);
        if ($exists) {
            return ['success' => false, 'message' => 'Student is already enrolled in this section.'];
        }

        db_execute('INSERT INTO enrollments (student_id, course_section_id, semester_id, academic_year_id, status, enrollment_date) VALUES (?, ?, ?, ?, ?, NOW())', [
            $studentId, $sectionId, $semesterId, $academicYearId, 'enrolled'
        ]);
        db_execute('UPDATE course_sections SET enrolled_count = enrolled_count + 1 WHERE id = ?', [$sectionId]);

        $u = db_query_one('SELECT user_id FROM students WHERE id = ? LIMIT 1', [$studentId]);
        if ($u) { send_notification_to_user((int)$u['user_id'], 'Enrollment confirmed (manual)', 'An instructor/admin enrolled you in a section.', 'info'); }
        return ['success' => true, 'message' => 'Manually enrolled successfully (capacity override).'];
    }

    return $res; // return the original failure if not forcing capacity
}

function drop_enrollment(int $studentId, int $sectionId): array
{
    $enrollment = db_query_one('SELECT id FROM enrollments WHERE student_id = ? AND course_section_id = ? AND status = ? LIMIT 1',
        [$studentId, $sectionId, 'enrolled']);
    if (!$enrollment) {
        return ['success' => false, 'message' => 'Active enrollment not found.'];
    }

    db_execute('UPDATE enrollments SET status = ?, updated_at = NOW() WHERE id = ?', ['dropped', $enrollment['id']]);
    db_execute('UPDATE course_sections SET enrolled_count = GREATEST(enrolled_count - 1, 0) WHERE id = ?', [$sectionId]);

    // Try to promote next waitlisted student into this section
    $section = db_query_one('SELECT semester_id, academic_year_id FROM course_sections WHERE id = ? LIMIT 1', [$sectionId]);
    if ($section) {
        promote_next_from_waitlist($sectionId, (int)$section['semester_id'], (int)$section['academic_year_id']);
    }

    return ['success' => true];
}

// Get waitlist entries for a section (ordered by FIFO)
function get_section_waitlist(int $sectionId): array
{
    ensure_waitlists_table();
    return db_query('SELECT w.id, w.student_id, s.first_name, s.last_name, s.student_id AS sid_code, w.requested_at
        FROM waitlists w JOIN students s ON w.student_id = s.id
        WHERE w.course_section_id = ? ORDER BY w.requested_at ASC, w.id ASC', [$sectionId]);
}

// Promote the next waitlisted student if capacity allows
function promote_next_from_waitlist(int $sectionId, int $semesterId, int $academicYearId): bool
{
    ensure_waitlists_table();
    $sec = db_query_one('SELECT capacity, enrolled_count FROM course_sections WHERE id = ? LIMIT 1', [$sectionId]);
    if (!$sec || (int)$sec['enrolled_count'] >= (int)$sec['capacity']) {
        return false;
    }
    $next = db_query_one('SELECT w.id, w.student_id FROM waitlists w WHERE w.course_section_id = ? ORDER BY w.requested_at ASC, w.id ASC LIMIT 1', [$sectionId]);
    if (!$next) return false;
    // Attempt enrollment
    $res = enroll_student((int)$next['student_id'], $sectionId, $semesterId, $academicYearId);
    if ($res['success'] ?? false) {
        db_execute('DELETE FROM waitlists WHERE id = ?', [$next['id']]);
        // Notify the promoted student
        $u = db_query_one('SELECT user_id FROM students WHERE id = ? LIMIT 1', [(int)$next['student_id']]);
        if ($u) { send_notification_to_user((int)$u['user_id'], 'Waitlist promotion', 'A seat opened and you have been auto-enrolled from the waitlist.', 'success'); }
        return true;
    }
    return false;
}

// Get unread notifications count for navbar badges
function get_unread_notification_count(int $userId): int
{
    $row = db_query_one('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
    return (int)($row['c'] ?? 0);
}

// Event: notify fee status change (pending/overdue/paid)
function notify_fee_status_change(int $studentId, float $amount, string $status): void
{
    $status = strtolower($status);
    $valid = ['pending','overdue','paid','cancelled'];
    if (!in_array($status, $valid, true)) return;
    $stu = db_query_one('SELECT user_id FROM students WHERE id = ? LIMIT 1', [$studentId]);
    if (!$stu) return;
    $title = 'Fee update: ' . ucfirst($status);
    $message = 'Your tuition/fee record of ' . format_currency($amount) . ' is now marked as ' . $status . '.';
    send_notification_to_user((int)$stu['user_id'], $title, $message, $status === 'overdue' ? 'warning' : 'info');
}

// Event: notify when a final grade is posted for an enrollment
function notify_grade_posted(int $enrollmentId): void
{
    $row = db_query_one('SELECT e.student_id, e.final_grade, c.course_code, cs.section_name, s.user_id
        FROM enrollments e
        JOIN course_sections cs ON e.course_section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN students s ON e.student_id = s.id
        WHERE e.id = ? LIMIT 1', [$enrollmentId]);
    if (!$row) return;
    if ($row['final_grade'] === null || $row['final_grade'] === '') return;
    $title = 'Grade posted: ' . $row['course_code'];
    $message = 'Your final grade for ' . $row['course_code'] . ' (Section ' . $row['section_name'] . ') is ' . $row['final_grade'] . '.';
    send_notification_to_user((int)$row['user_id'], $title, $message, 'success');
}

function get_current_user_profile(): ?array
{
    $userId = current_user_id();
    if (!$userId) {
        return null;
    }

    $profile = db_query_one("SELECT
            s.id AS student_internal_id,
            s.student_id,
            s.first_name,
            s.last_name,
            s.email,
            s.phone,
            s.address,
            s.date_of_birth,
            s.enrollment_date,
            d.id AS department_id,
            d.dept_code,
            d.dept_name,
            p.id AS program_id,
            p.program_code,
            p.program_name,
            p.total_credits,
            l.level_name,
            l.level_order,
            u.username,
            u.email AS user_email,
            u.graduation_lock_at
        FROM students s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN departments d ON s.department_id = d.id
        LEFT JOIN programs p ON s.program_id = p.id
        LEFT JOIN levels l ON s.current_level_id = l.id
        WHERE u.id = ?", [$userId]);

    return $profile ?: null;
}

function get_student_current_enrollments(int $studentId): array
{
    $sql = "SELECT
                e.id,
                c.course_code,
                c.course_name,
                c.credits,
                cs.section_name,
                cs.schedule,
                cs.room,
                cs.capacity,
                CONCAT(i.first_name, ' ', i.last_name) AS instructor_name,
                e.status,
                e.final_grade,
                sem.semester_name,
                ay.year_name
            FROM enrollments e
            JOIN course_sections cs ON e.course_section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            JOIN instructors i ON cs.instructor_id = i.id
            JOIN semesters sem ON e.semester_id = sem.id
            JOIN academic_years ay ON e.academic_year_id = ay.id
            WHERE e.student_id = ?
              AND sem.is_current = 1
            ORDER BY c.course_code";

    return db_query($sql, [$studentId]);
}

function get_student_completed_enrollments(int $studentId): array
{
    $sql = "SELECT
                e.id,
                c.course_code,
                c.course_name,
                c.credits,
                cs.section_name,
                cs.schedule,
                cs.room,
                CONCAT(i.first_name, ' ', i.last_name) AS instructor_name,
                e.final_grade,
                e.grade_points,
                sem.semester_name,
                ay.year_name
            FROM enrollments e
            JOIN course_sections cs ON e.course_section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            JOIN instructors i ON cs.instructor_id = i.id
            JOIN semesters sem ON e.semester_id = sem.id
            JOIN academic_years ay ON e.academic_year_id = ay.id
            WHERE e.student_id = ?
              AND e.final_grade IS NOT NULL
            ORDER BY ay.year_name DESC, sem.start_date DESC, c.course_code";

    return db_query($sql, [$studentId]);
}

function get_student_attendance_courses(int $studentId): array
{
    $sql = "SELECT DISTINCT
                c.course_code,
                c.course_name
            FROM enrollments e
            JOIN course_sections cs ON e.course_section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            JOIN semesters sem ON e.semester_id = sem.id
            WHERE e.student_id = ?
              AND sem.is_current = 1
            ORDER BY c.course_code";

    return db_query($sql, [$studentId]);
}

function get_student_gpa_summary(int $studentId): ?array
{
    $summary = db_query_one('SELECT calculated_gpa, total_credits, total_courses FROM student_gpa_view WHERE id = ?', [$studentId]);
    if ($summary) {
        return [
            'calculated_gpa' => (float)($summary['calculated_gpa'] ?? 0),
            'total_credits' => (int)($summary['total_credits'] ?? 0),
            'total_courses' => (int)($summary['total_courses'] ?? 0),
        ];
    }

    $fallback = db_query_one('SELECT
            SUM(e.grade_points * c.credits) AS total_points,
            SUM(c.credits) AS total_credits,
            COUNT(e.id) AS total_courses
        FROM enrollments e
        JOIN course_sections cs ON e.course_section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        WHERE e.student_id = ? AND e.final_grade IS NOT NULL', [$studentId]);

    if (!$fallback || !$fallback['total_credits']) {
        return null;
    }

    $gpa = $fallback['total_points'] / $fallback['total_credits'];
    return [
        'calculated_gpa' => round($gpa, 2),
        'total_credits' => (int)$fallback['total_credits'],
        'total_courses' => (int)$fallback['total_courses'],
    ];
}

function get_student_recent_grades(int $studentId, int $limit = 5): array
{
    $sql = "SELECT
                c.course_code,
                c.course_name,
                g.assessment_name,
                g.assessment_type,
                g.score,
                g.max_score,
                g.weight,
                g.grade_date
            FROM grades g
            JOIN enrollments e ON g.enrollment_id = e.id
            JOIN course_sections cs ON e.course_section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            WHERE e.student_id = ?
            ORDER BY g.grade_date DESC
            LIMIT ?";

    return db_query($sql, [$studentId, $limit]);
}

function get_student_attendance_summary(int $studentId, ?string $courseCode = null, ?string $attendanceDate = null): ?array
{
    $sql = "SELECT
                COUNT(a.id) AS total_count,
                COUNT(CASE WHEN a.status = 'present' THEN 1 END) AS present_count,
                COUNT(CASE WHEN a.status = 'absent' THEN 1 END) AS absent_count,
                COUNT(CASE WHEN a.status = 'late' THEN 1 END) AS late_count,
                COUNT(CASE WHEN a.status = 'excused' THEN 1 END) AS excused_count
            FROM attendance a
            JOIN enrollments e ON a.enrollment_id = e.id
            JOIN course_sections cs ON e.course_section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            JOIN semesters sem ON e.semester_id = sem.id
            WHERE e.student_id = ?
              AND sem.is_current = 1";

    $params = [$studentId];

    if ($courseCode !== null && $courseCode !== '') {
        $sql .= " AND c.course_code = ?";
        $params[] = $courseCode;
    }

    if ($attendanceDate !== null && $attendanceDate !== '') {
        $sql .= " AND a.attendance_date = ?";
        $params[] = $attendanceDate;
    }

    return db_query_one($sql, $params);
}

function get_student_attendance_records(int $studentId, ?string $courseCode = null, ?string $attendanceDate = null): array
{
    $sql = "SELECT
                a.attendance_date,
                a.status,
                a.notes,
                c.course_code,
                c.course_name,
                cs.section_name,
                cs.schedule,
                CONCAT(i.first_name, ' ', i.last_name) AS instructor_name
            FROM attendance a
            JOIN enrollments e ON a.enrollment_id = e.id
            JOIN course_sections cs ON e.course_section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            JOIN instructors i ON cs.instructor_id = i.id
            JOIN semesters sem ON e.semester_id = sem.id
            WHERE e.student_id = ?
              AND sem.is_current = 1";

    $params = [$studentId];

    if ($courseCode !== null && $courseCode !== '') {
        $sql .= " AND c.course_code = ?";
        $params[] = $courseCode;
    }

    if ($attendanceDate !== null && $attendanceDate !== '') {
        $sql .= " AND a.attendance_date = ?";
        $params[] = $attendanceDate;
    }

    $sql .= " ORDER BY a.attendance_date DESC, c.course_code, cs.section_name";

    return db_query($sql, $params);
}

function get_student_transcript(int $studentId): array
{
    $sql = "SELECT
                ay.year_name,
                sem.semester_name,
                c.course_code,
                c.course_name,
                c.credits,
                cs.section_name,
                e.final_grade,
                e.grade_points,
                e.status
            FROM enrollments e
            JOIN course_sections cs ON e.course_section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            JOIN semesters sem ON e.semester_id = sem.id
            JOIN academic_years ay ON e.academic_year_id = ay.id
            WHERE e.student_id = ? AND e.final_grade IS NOT NULL
            ORDER BY ay.year_name, sem.semester_name, c.course_code";

    return db_query($sql, [$studentId]);
}

function get_grade_letter(float $percentage): string
{
    if ($percentage >= 90) {
        return 'A';
    }
    if ($percentage >= 80) {
        return 'B';
    }
    if ($percentage >= 70) {
        return 'C';
    }
    if ($percentage >= 60) {
        return 'D';
    }
    return 'F';
}

function get_grade_points_for_letter(string $letter): float
{
    $mapping = [
        'A' => 4.0,
        'B' => 3.0,
        'C' => 2.0,
        'D' => 1.0,
        'F' => 0.0,
    ];

    $key = strtoupper(trim($letter));
    return $mapping[$key] ?? 0.0;
}

function calculate_enrollment_final_grade(int $enrollmentId): array
{
    $enrollmentInfo = db_query_one(
        'SELECT e.student_id, s.user_id, s.status AS student_status, l.level_order, u.graduation_lock_at
         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN levels l ON l.id = s.current_level_id
         WHERE e.id = ?
         LIMIT 1',
        [$enrollmentId]
    );

    $grades = db_query('SELECT score, max_score, weight FROM grades WHERE enrollment_id = ?', [$enrollmentId]);
    if (empty($grades)) {
        return ['success' => false, 'message' => 'No grade records available.'];
    }

    $weightedTotal = 0.0;
    $weightSum = 0.0;

    foreach ($grades as $grade) {
        $score = (float)($grade['score'] ?? 0);
        $maxScore = (float)($grade['max_score'] ?? 0);
        $weight = (float)($grade['weight'] ?? 0);

        if ($maxScore <= 0 || $weight <= 0) {
            continue;
        }

        $percentage = ($score / $maxScore) * 100;
        $weightedTotal += $percentage * $weight;
        $weightSum += $weight;
    }

    if ($weightSum <= 0) {
        return ['success' => false, 'message' => 'Assessment weights not configured.'];
    }

    $finalPercentage = $weightedTotal / $weightSum;
    $finalPercentage = max(0, min(100, $finalPercentage));
    $letter = get_grade_letter($finalPercentage);
    $gradePoints = get_grade_points_for_letter($letter);

    $status = 'enrolled';
    $isGraduating = false;
    if ($enrollmentInfo && (int)($enrollmentInfo['level_order'] ?? 0) >= 4) {
        $status = 'completed';
        $isGraduating = true;
    }

    db_execute('UPDATE enrollments SET final_grade = ?, grade_points = ?, status = ? WHERE id = ?', [$letter, $gradePoints, $status, $enrollmentId]);

    if ($isGraduating) {
        $studentId = (int)($enrollmentInfo['student_id'] ?? 0);
        $userId = (int)($enrollmentInfo['user_id'] ?? 0);

        if ($studentId > 0) {
            db_execute('UPDATE students SET status = ? WHERE id = ?', ['graduated', $studentId]);
        }

        if ($userId > 0) {
            $scheduledLock = calculate_graduation_lock_deadline();
            $existingLock = $enrollmentInfo['graduation_lock_at'] ?? null;
            $applyLock = $scheduledLock;
            if ($existingLock) {
                $existingTs = strtotime($existingLock);
                $scheduledTs = strtotime($scheduledLock);
                if ($existingTs !== false && $scheduledTs !== false && $existingTs < $scheduledTs) {
                    $applyLock = $existingLock;
                }
            }
            db_execute('UPDATE users SET graduation_lock_at = ? WHERE id = ?', [$applyLock, $userId]);
        }
    }

    // Notify student that a final grade was posted
    notify_grade_posted($enrollmentId);

    return [
        'success' => true,
        'final_percentage' => round($finalPercentage, 2),
        'final_grade' => $letter,
        'grade_points' => $gradePoints,
    ];
}

function finalize_course_section_grades(int $sectionId): array
{
    $enrollments = db_query('SELECT id FROM enrollments WHERE course_section_id = ?', [$sectionId]);
    if (empty($enrollments)) {
        return ['success' => false, 'message' => 'No enrollments found for this section.', 'completed' => 0, 'skipped' => 0];
    }

    $completed = 0;
    $skipped = 0;
    $messages = [];

    foreach ($enrollments as $enrollment) {
        $result = calculate_enrollment_final_grade((int)$enrollment['id']);
        if ($result['success']) {
            $completed++;
        } else {
            $skipped++;
            $messages[] = 'Enrollment ' . $enrollment['id'] . ': ' . $result['message'];
        }
    }

    return [
        'success' => true,
        'completed' => $completed,
        'skipped' => $skipped,
        'messages' => $messages,
    ];
}

function get_instructor_sections(int $instructorId): array
{
    $sql = "SELECT
                cs.id,
                cs.section_name,
                cs.schedule,
                cs.room,
                cs.capacity,
                cs.enrolled_count,
                c.course_code,
                c.course_name,
                sem.semester_name,
                ay.year_name
            FROM course_sections cs
            JOIN courses c ON cs.course_id = c.id
            JOIN semesters sem ON cs.semester_id = sem.id
            JOIN academic_years ay ON cs.academic_year_id = ay.id
            WHERE cs.instructor_id = ?
            ORDER BY ay.year_name DESC, sem.start_date DESC, c.course_code";

    return db_query($sql, [$instructorId]);
}

function get_section_details(int $sectionId, ?int $instructorId = null): ?array
{
    $params = [$sectionId];
    $instructorFilter = '';
    if ($instructorId !== null) {
        $instructorFilter = 'AND cs.instructor_id = ?';
        $params[] = $instructorId;
    }

    $sql = "SELECT
                cs.id,
                cs.section_name,
                cs.schedule,
                cs.room,
                cs.capacity,
                cs.semester_id,
                cs.academic_year_id,
                c.course_code,
                c.course_name,
                c.credits,
                CONCAT(i.first_name, ' ', i.last_name) AS instructor_name,
                sem.semester_name,
                ay.year_name
            FROM course_sections cs
            JOIN courses c ON cs.course_id = c.id
            JOIN instructors i ON cs.instructor_id = i.id
            JOIN semesters sem ON cs.semester_id = sem.id
            JOIN academic_years ay ON cs.academic_year_id = ay.id
            WHERE cs.id = ? $instructorFilter
            LIMIT 1";

    return db_query_one($sql, $params) ?: null;
}

function get_section_enrollments(int $sectionId): array
{
    $sql = "SELECT
                e.id AS enrollment_id,
                e.status,
                e.final_grade,
                e.grade_points,
                s.id AS student_internal_id,
                s.student_id,
                s.first_name,
                s.last_name,
                s.email
            FROM enrollments e
            JOIN students s ON e.student_id = s.id
            WHERE e.course_section_id = ?
            ORDER BY s.last_name, s.first_name";

    return db_query($sql, [$sectionId]);
}

function get_section_gradebook_data(int $sectionId): array
{
    $sql = "SELECT
                e.id AS enrollment_id,
                g.id AS grade_id,
                g.assessment_name,
                g.assessment_type,
                g.score,
                g.max_score,
                g.weight,
                g.grade_date
            FROM enrollments e
            LEFT JOIN grades g ON g.enrollment_id = e.id
            WHERE e.course_section_id = ?
            ORDER BY g.grade_date DESC, g.id ASC";

    $rows = db_query($sql, [$sectionId]);
    $grouped = [];
    foreach ($rows as $row) {
        $enrollmentId = (int)$row['enrollment_id'];
        if (!isset($grouped[$enrollmentId])) {
            $grouped[$enrollmentId] = [];
        }
        if (!empty($row['grade_id'])) {
            $grouped[$enrollmentId][] = $row;
        }
    }
    return $grouped;
}

function save_grade_record(?int $gradeId, int $enrollmentId, string $assessmentName, string $assessmentType, float $score, float $maxScore, float $weight, ?string $gradeDate = null): void
{
    $gradeDate = $gradeDate ?: date('Y-m-d');
    if ($gradeId) {
        db_execute('UPDATE grades SET assessment_name = ?, assessment_type = ?, score = ?, max_score = ?, weight = ?, grade_date = ? WHERE id = ?',
            [$assessmentName, $assessmentType, $score, $maxScore, $weight, $gradeDate, $gradeId]);
    } else {
        db_execute('INSERT INTO grades (enrollment_id, assessment_name, assessment_type, score, max_score, weight, grade_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$enrollmentId, $assessmentName, $assessmentType, $score, $maxScore, $weight, $gradeDate]);
    }
}

function delete_grade_record(int $gradeId): void
{
    db_execute('DELETE FROM grades WHERE id = ?', [$gradeId]);
}

function calculate_enrollment_weighted_percentage(array $grades): float
{
    $total = 0.0;
    $weights = 0.0;
    foreach ($grades as $grade) {
        $score = (float)($grade['score'] ?? 0);
        $max = (float)($grade['max_score'] ?? 0);
        $weight = (float)($grade['weight'] ?? 0);
        if ($max <= 0 || $weight <= 0) {
            continue;
        }
        $total += ($score / $max) * 100 * $weight;
        $weights += $weight;
    }
    if ($weights <= 0) {
        return 0.0;
    }
    return round($total / $weights, 2);
}

function get_section_attendance_records(int $sectionId, ?string $attendanceDate = null): array
{
    $params = [];
    $dateFilter = '';
    if ($attendanceDate) {
        $dateFilter = 'AND a.attendance_date = ?';
        $params[] = $attendanceDate;
    }
    $params[] = $sectionId;

    $sql = "SELECT
                e.id AS enrollment_id,
                s.student_id,
                s.first_name,
                s.last_name,
                a.attendance_date,
                a.status,
                a.notes
            FROM enrollments e
            JOIN students s ON e.student_id = s.id
            LEFT JOIN attendance a ON a.enrollment_id = e.id $dateFilter
            WHERE e.course_section_id = ?
            ORDER BY s.last_name, s.first_name";

    return db_query($sql, $params);
}

function upsert_attendance_record(int $enrollmentId, string $attendanceDate, string $status, ?string $notes, int $instructorId): void
{
    db_execute('INSERT INTO attendance (enrollment_id, attendance_date, status, notes, marked_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes), marked_by = VALUES(marked_by)',
        [$enrollmentId, $attendanceDate, $status, $notes, $instructorId]);
}

function get_instructor_advisees(int $instructorId): array
{
    $sql = "SELECT
                s.id AS student_internal_id,
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.phone,
                sa.assigned_date,
                sa.is_active
            FROM student_advisors sa
            JOIN students s ON sa.student_id = s.id
            WHERE sa.instructor_id = ?
            ORDER BY s.last_name, s.first_name";

    return db_query($sql, [$instructorId]);
}

function create_notification(int $userId, string $title, string $message, string $type = 'info'): void
{
    db_execute('INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())',
        [$userId, $title, $message, $type]);
}

function get_instructor_profile(int $userId): ?array
{
    $sql = "SELECT
                i.id AS instructor_id,
                i.first_name,
                i.last_name,
                i.email,
                i.phone,
                i.hire_date,
                i.department_id,
                d.dept_name,
                u.username,
                u.email AS user_email
            FROM instructors i
            JOIN users u ON i.user_id = u.id
            LEFT JOIN departments d ON i.department_id = d.id
            WHERE i.user_id = ?
            LIMIT 1";

    return db_query_one($sql, [$userId]) ?: null;
}

function update_instructor_contact(int $userId, string $email, ?string $phone): void
{
    db_execute('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
    db_execute('UPDATE instructors SET email = ?, phone = ? WHERE user_id = ?', [$email, $phone, $userId]);
}

function update_instructor_password(int $userId, string $currentPassword, string $newPassword): array
{
    $user = db_query_one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    if (!$user || !verify_password($currentPassword, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }

    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];
    }

    db_execute('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($newPassword), $userId]);
    return ['success' => true];
}

function get_instructor_course_overview(int $instructorId): array
{
    $sql = "SELECT
                cs.id,
                cs.section_name,
                cs.schedule,
                cs.room,
                cs.capacity,
                cs.enrolled_count,
                c.course_code,
                c.course_name,
                sem.semester_name,
                ay.year_name,
                COALESCE(SUM(CASE WHEN e.id IS NOT NULL AND e.final_grade IS NULL THEN 1 ELSE 0 END), 0) AS pending_grades
            FROM course_sections cs
            JOIN courses c ON cs.course_id = c.id
            JOIN semesters sem ON cs.semester_id = sem.id
            JOIN academic_years ay ON cs.academic_year_id = ay.id
            LEFT JOIN enrollments e ON cs.id = e.course_section_id
            WHERE cs.instructor_id = ?
            GROUP BY cs.id, cs.section_name, cs.schedule, cs.room, cs.capacity, cs.enrolled_count,
                     c.course_code, c.course_name, sem.semester_name, ay.year_name
            ORDER BY ay.year_name DESC, sem.start_date DESC, c.course_code";

    return db_query($sql, [$instructorId]);
}

function get_current_academic_period(): ?array
{
    return db_query_one("SELECT
            sem.id AS semester_id,
            ay.id AS academic_year_id,
            sem.semester_name,
            ay.year_name
        FROM semesters sem
        JOIN academic_years ay ON sem.academic_year_id = ay.id
        WHERE sem.is_current = 1
        LIMIT 1");
}

function get_department_courses(int $departmentId): array
{
    return db_query("SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.credits,
            c.description,
            l.level_name
        FROM courses c
        LEFT JOIN levels l ON c.level_id = l.id
        WHERE c.department_id = ?
        ORDER BY c.course_code", [$departmentId]);
}

function get_department_sections(int $departmentId, int $semesterId, int $academicYearId): array
{
    $sql = "SELECT
                cs.id,
                cs.section_name,
                cs.schedule,
                cs.room,
                cs.capacity,
                cs.semester_id,
                cs.academic_year_id,
                c.course_code,
                c.course_name,
                c.credits,
                CONCAT(i.first_name, ' ', i.last_name) AS instructor_name
            FROM course_sections cs
            JOIN courses c ON cs.course_id = c.id
            JOIN instructors i ON cs.instructor_id = i.id
            WHERE c.department_id = ?
              AND cs.semester_id = ?
              AND cs.academic_year_id = ?
            ORDER BY c.course_code, cs.section_name";

    return db_query($sql, [$departmentId, $semesterId, $academicYearId]);
}

function get_student_fee_payments(int $studentId): array
{
    $sql = "SELECT
                fp.id,
                fp.amount,
                fp.payment_date,
                fp.status,
                fp.scholarship_amount,
                fp.notes,
                sem.semester_name,
                ay.year_name
            FROM fee_payments fp
            JOIN semesters sem ON fp.semester_id = sem.id
            JOIN academic_years ay ON fp.academic_year_id = ay.id
            WHERE fp.student_id = ?
            ORDER BY ay.year_name DESC, sem.start_date DESC";

    return db_query($sql, [$studentId]);
}

function get_student_fee_summary(int $studentId): array
{
    $totalPaid = db_query_one('SELECT COALESCE(SUM(amount), 0) AS total_paid FROM fee_payments WHERE student_id = ? AND status = "paid"', [$studentId]);
    $pending = db_query_one('SELECT COALESCE(SUM(amount), 0) AS total_pending FROM fee_payments WHERE student_id = ? AND status = "pending"', [$studentId]);

    return [
        'total_paid' => (float)($totalPaid['total_paid'] ?? 0),
        'total_pending' => (float)($pending['total_pending'] ?? 0),
    ];
}

// -----------------------------------------------------------------------------
// Notifications helpers
// -----------------------------------------------------------------------------

function ensure_notifications_sender_column(): void
{
    // Add sender_user_id column if it doesn't exist (MySQL 8+ supports IF NOT EXISTS)
    try {
        db_execute('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS sender_user_id INT NULL, ADD FOREIGN KEY (sender_user_id) REFERENCES users(id)');
    } catch (Throwable $e) {
        // Ignore if not supported or already exists
    }
}

function send_notification_to_user(int $userId, string $title, string $message, string $type = 'info', ?int $senderUserId = null): void
{
    $title = trim($title);
    $message = trim($message);
    $type = in_array($type, ['info','warning','success','error'], true) ? $type : 'info';
    if ($title === '' || $message === '') return;
    ensure_notifications_sender_column();
    // Try insert with sender_user_id; fallback to without if column not present
    try {
        db_execute('INSERT INTO notifications (user_id, title, message, type, is_read, created_at, sender_user_id) VALUES (?, ?, ?, ?, 0, NOW(), ?)',
            [$userId, $title, $message, $type, $senderUserId]);
    } catch (Throwable $e) {
        db_execute('INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())',
            [$userId, $title, $message, $type]);
    }
}

function send_notification_to_users(array $userIds, string $title, string $message, string $type = 'info', ?int $senderUserId = null): int
{
    $count = 0;
    foreach ($userIds as $uid) {
        send_notification_to_user((int)$uid, $title, $message, $type, $senderUserId);
        $count++;
    }
    return $count;
}

function get_user_notifications(int $userId, bool $onlyUnread = false, int $limit = 100): array
{
    ensure_notifications_sender_column();
    $sql = 'SELECT n.id, n.title, n.message, n.type, n.is_read, n.created_at, n.sender_user_id, su.username AS sender_username
            FROM notifications n
            LEFT JOIN users su ON su.id = n.sender_user_id
            WHERE n.user_id = ?';
    $params = [$userId];
    if ($onlyUnread) { $sql .= ' AND is_read = 0'; }
    $sql .= ' ORDER BY n.created_at DESC LIMIT ' . (int)max(1,$limit);
    return db_query($sql, $params);
}

function mark_notification_read(int $notifId, int $userId): void
{
    db_execute('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$notifId, $userId]);
}

// Broadcast to audience: roles [admin,instructor,student], and optional filters for cohorts
function get_user_ids_for_audience(array $roles, ?int $departmentId = null, ?int $levelId = null): array
{
    $roles = array_values(array_intersect($roles, ['admin','instructor','student']));
    if (empty($roles)) return [];

    $ids = [];
    // Admins
    if (in_array('admin', $roles, true)) {
        $rows = db_query("SELECT id FROM users WHERE role='admin' AND is_active=1");
        foreach ($rows as $r) { $ids[] = (int)$r['id']; }
    }
    // Instructors with optional department filter
    if (in_array('instructor', $roles, true)) {
        if ($departmentId) {
            $rows = db_query('SELECT u.id FROM users u JOIN instructors i ON i.user_id = u.id WHERE u.role = "instructor" AND u.is_active=1 AND i.department_id = ?', [$departmentId]);
        } else {
            $rows = db_query("SELECT id FROM users WHERE role='instructor' AND is_active=1");
        }
        foreach ($rows as $r) { $ids[] = (int)$r['id']; }
    }
    // Students with optional department and level filters
    if (in_array('student', $roles, true)) {
        $cond = 'WHERE u.role = "student" AND u.is_active=1';
        $params = [];
        if ($departmentId) { $cond .= ' AND s.department_id = ?'; $params[] = $departmentId; }
        if ($levelId) { $cond .= ' AND s.current_level_id = ?'; $params[] = $levelId; }
        $rows = db_query("SELECT u.id FROM users u JOIN students s ON s.user_id = u.id $cond", $params);
        foreach ($rows as $r) { $ids[] = (int)$r['id']; }
    }
    return array_values(array_unique($ids));
}
