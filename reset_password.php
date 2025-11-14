<?php
require_once __DIR__ . '/includes/functions.php';

set_security_headers();

$token = sanitize_input($_GET['token'] ?? ($_POST['token'] ?? ''));
$verification = $token !== '' ? verify_password_reset_token($token) : ['success' => false, 'message' => 'Invalid password reset token.'];

$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['password_confirmation'] ?? '';
        $result = complete_password_reset($token, $newPassword, $confirmPassword);

        if ($result['success']) {
            set_flash_message('success', $result['message']);
            redirect('/login.php');
        } else {
            $errors[] = $result['message'] ?? 'Unable to reset password.';
        }
    }
}

if (!$verification['success']) {
    $errors[] = $verification['message'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - University Management System</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h4 class="mb-0">Reset Password</h4>
                    <p class="mb-0 small">Enter and confirm your new password.</p>
                </div>
                <div class="card-body p-4">
                    <?php foreach (get_flash_messages('success') as $message): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                    <?php endforeach; ?>

                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>

                    <?php if ($verification['success']): ?>
                        <form method="POST" novalidate>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                            <div class="mb-3">
                                <label for="password" class="form-label">New Password</label>
                                <input type="password" name="password" id="password" class="form-control form-control-lg" minlength="8" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label for="password_confirmation" class="form-label">Confirm Password</label>
                                <input type="password" name="password_confirmation" id="password_confirmation" class="form-control form-control-lg" minlength="8" required>
                            </div>
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary btn-lg">Update Password</button>
                            </div>
                            <div class="text-center">
                                <a href="login.php" class="small">Back to login</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="text-center">
                            <a href="forgot_password.php" class="btn btn-outline-primary">Request a new reset link</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
