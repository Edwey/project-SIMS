<?php
require_once __DIR__ . '/includes/functions.php';

set_security_headers();

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Invalid request token. Please try again.';
    } else {
        $email = sanitize_input($_POST['email'] ?? '');
        $result = generate_password_reset_token($email);

    if ($result['success']) {
        if (!empty($result['token']) && !empty($result['user']['email'])) {
            $resetLink = rtrim(SITE_URL, '/') . '/reset_password.php?token=' . urlencode($result['token']);

            if (APP_DEBUG) {
                set_flash_message('info', 'DEBUG reset link: ' . $resetLink);
            }

            $mailSent = send_password_reset_email($result['user']['email'], $resetLink, $result['expires_at']);
            if (!$mailSent) {
                $errorMessage = 'Unable to send reset instructions. Please contact support.';
            }
        }

        // If rate limited, show a friendly countdown in addition to neutral message
        if (($result['rate_limited'] ?? false) && empty($errorMessage)) {
            $retry = (int)($result['retry_after_seconds'] ?? 0);
            $mins = max(1, (int)ceil($retry / 60));
            $successMessage = 'If the email exists in our records, you will receive reset instructions shortly. You\'ve reached the limit. Please try again in about ' . $mins . ' minute(s).';
        } elseif (!$errorMessage) {
            $successMessage = 'If the email exists in our records, you will receive reset instructions shortly.';
        }
    } else {
        $errorMessage = $result['message'] ?? 'Unable to process your request.';
    }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - University Management System</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h4 class="mb-0">Forgot Password</h4>
                    <p class="mb-0 small">Enter your email address to receive a reset link.</p>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($errorMessage); ?>
                        </div>
                        <?php if (defined('APP_DEBUG') && APP_DEBUG && !empty($GLOBALS['last_mail_error'])): ?>
                            <div class="alert alert-warning small">
                                Mailer: <?php echo htmlspecialchars($GLOBALS['last_mail_error']); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($successMessage)): ?>
                        <div class="alert alert-success">
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach (get_flash_messages('info') as $message): ?>
                        <div class="alert alert-info"> <?php echo htmlspecialchars($message); ?> </div>
                    <?php endforeach; ?>

                    <form method="POST" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control form-control-lg" placeholder="Enter your registered email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary btn-lg">Send Reset Link</button>
                        </div>
                        <div class="text-center">
                            <a href="login.php" class="small">Back to login</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
