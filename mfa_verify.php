<?php
require_once __DIR__ . '/includes/functions.php';

set_security_headers();

// If already logged in, go to role dashboard
if (is_logged_in()) {
    $role = get_user_role();
    if ($role === 'admin') redirect(SITE_URL . '/admin/dashboard.php');
    if ($role === 'instructor') redirect(SITE_URL . '/instructor/dashboard.php');
    if ($role === 'student') redirect(SITE_URL . '/student/dashboard.php');
}

$pendingUserId = (int)($_SESSION['pending_mfa_user_id'] ?? 0);
$startedAt = (int)($_SESSION['pending_mfa_started_at'] ?? 0);
if ($pendingUserId <= 0 || ($startedAt && (time() - $startedAt) > 900)) { // 15 min safety
    set_flash_message('error', 'Your verification session expired. Please sign in again.');
    $_SESSION['pending_mfa_user_id'] = null;
    $_SESSION['pending_mfa_started_at'] = null;
    redirect(SITE_URL . '/login.php');
}

$debugOtp = null;
if (defined('APP_DEBUG') && APP_DEBUG) {
    $debugOtp = $_SESSION['debug_last_otp']['mfa'] ?? null;
}

$errors = [];

// Handle resend request
if (($_GET['action'] ?? '') === 'resend') {
    generate_email_otp($pendingUserId, 'mfa', 600);
    set_flash_message('success', 'A new verification code has been sent to your email.');
    redirect(SITE_URL . '/mfa_verify.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            $errors[] = 'Enter the 6-digit code.';
        } else {
            $check = verify_email_otp($pendingUserId, $code, 'mfa');
            if ($check['success']) {
                // Load user and complete login
                $user = db_query_one('SELECT id, username, email, role, must_change_password FROM users WHERE id = ? LIMIT 1', [$pendingUserId]);
                if (!$user) {
                    set_flash_message('error', 'Account not found. Please sign in again.');
                    redirect(SITE_URL . '/login.php');
                }

                // Mark last_login and clear pending MFA session
                db_execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0) === 1;
                $_SESSION['pending_mfa_user_id'] = null;
                $_SESSION['pending_mfa_started_at'] = null;

                // Optionally trust this device for 30 days
                if (!empty($_POST['remember_device']) && (int)$user['id'] > 0) {
                    set_trusted_device_cookie((int)$user['id'], 30);
                }

                // Log success
                db_execute(
                    'INSERT INTO system_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $user['id'],
                        'login_mfa_success',
                        'user',
                        $user['id'],
                        null,
                        json_encode(['username' => $user['username']]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]
                );

                // Route by role
                $role = $user['role'];
                if ($role === 'admin') redirect(SITE_URL . '/admin/dashboard.php');
                if ($role === 'instructor') redirect(SITE_URL . '/instructor/dashboard.php');
                if ($role === 'student') redirect(SITE_URL . '/student/dashboard.php');
                redirect(SITE_URL . '/');
            } else {
                $errors[] = $check['message'] ?? 'Invalid code.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify Code - MFA</title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
  <div class="auth-card" width="auto">
    <header>
      <h1 class="fw-semibold mb-1" align="center">Multi‑Factor Authentication</h1>
      <p class="mb-0 text-muted" align="center">Enter the 6‑digit code sent to your email</p>
    </header>
    <div class="card-body">
      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
          <?php foreach ($errors as $error): ?>
            <div><?php echo htmlspecialchars($error); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php foreach (get_flash_messages('error') as $message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div>
      <?php endforeach; ?>
      <?php foreach (get_flash_messages('success') as $message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
      <?php endforeach; ?>
      <?php if ($debugOtp): ?>
        <div class="alert alert-info">
          <strong>Debug Mode:</strong> Use code <code><?php echo htmlspecialchars($debugOtp); ?></code>
        </div>
      <?php endif; ?>

      <form method="POST" class="mb-3">
        <?php echo csrf_field(); ?>
        <div class="mb-3">
          <label for="code" class="form-label">Verification Code</label>
          <input type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="code" id="code" class="form-control form-control-lg" placeholder="e.g. 123456" required autofocus autocomplete="one-time-code">
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="remember_device" id="remember_device" value="1">
          <label class="form-check-label" for="remember_device">Remember this device for 30 days</label>
        </div>
        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-primary btn-lg">Verify and Continue</button>
        </div>
      </form>

      <div class="text-center small">
        Didn't get a code? <a href="<?php echo SITE_URL; ?>/mfa_verify.php?action=resend">Resend</a>
      </div>

      <div class="text-center mt-3">
        <a class="small" href="<?php echo SITE_URL; ?>/logout.php">Back to sign in</a>
      </div>
    </div>
  </div>
</body>
</html>
