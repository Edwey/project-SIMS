<?php
require_once __DIR__ . '/includes/functions.php';

require_auth(null);
set_security_headers();

$errors = [];
$success = null;

if (!is_logged_in()) {
    redirect('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirmation'] ?? '';

        if ($password === '' || $confirm === '') {
            $errors[] = 'Please enter and confirm your new password.';
        } elseif ($password !== $confirm) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        } else {
            $uid = current_user_id();
            if ($uid) {
                db_execute('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [hash_password($password), $uid]);
                $_SESSION['must_change_password'] = false;
                set_flash_message('success', 'Password updated.');
                $role = get_user_role();
                switch ($role) {
                    case 'admin':
                        redirect('/admin/dashboard.php');
                        break;
                    case 'instructor':
                        redirect('/instructor/dashboard.php');
                        break;
                    case 'student':
                        redirect('/student/dashboard.php');
                        break;
                    default:
                        redirect('/login.php');
                }
            } else {
                $errors[] = 'Unable to determine your account.';
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
    <title>Update Password - University</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h4 class="mb-0">Update Your Password</h4>
                    <p class="mb-0 small">You must set a new password before continuing.</p>
                </div>
                <div class="card-body p-4">
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                    <?php foreach (get_flash_messages('success') as $message): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                    <?php endforeach; ?>

                    <form method="POST" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control form-control-lg" minlength="8" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="password_confirmation" class="form-control form-control-lg" minlength="8" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">Update Password</button>
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
