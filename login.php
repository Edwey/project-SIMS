<?php
require_once __DIR__ . '/includes/functions.php';

set_security_headers();

if (is_logged_in()) {
    $role = get_user_role();
    switch ($role) {
        case 'student':
            redirect(SITE_URL . '/student/dashboard.php');
            break;
        case 'admin':
            redirect(SITE_URL . '/admin/dashboard.php');
            break;
        case 'instructor':
            redirect(SITE_URL . '/instructor/dashboard.php');
            break;
        default:
            redirect(SITE_URL . '/logout.php');
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both username and password.';
    } else {
        $result = login_user($username, $password);
        if ($result['success']) {
            if (!empty($result['require_mfa'])) {
                set_flash_message('success', 'We sent a verification code to your email. Enter it to continue.');
                redirect(SITE_URL . '/mfa_verify.php');
            }
            $role = $result['user']['role'];
            switch ($role) {
                case 'student':
                    redirect(SITE_URL . '/student/dashboard.php');
                    break;
                case 'admin':
                    redirect(SITE_URL . '/admin/dashboard.php');
                    break;
                case 'instructor':
                    redirect(SITE_URL . '/instructor/dashboard.php');
                    break;
                default:
                    redirect(SITE_URL . '/logout.php');
            }
        } else {
            $errors[] = $result['message'];
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
    <title>Login - University Management System</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="auth-card" width="auto">
        <header>
            <h1 class="fw-semibold mb-1" align="center">University</h1>
            <p class="mb-0" style="color: #000000ff;" align="center" >Sign in to continue</p>
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

            <div class="row g-4 align-items-start">
                <div class="col-12 col-lg-6">
                    <form method="POST" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="mb-3" width="auto">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" name="username" id="username" class="form-control form-control-lg" placeholder="Enter your username" required autofocus value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
                        </div>
                        <div class="mb-3" width="auto">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password" class="form-control form-control-lg" placeholder="Enter your password" required>
                        </div>
                        <div class="d-grid mb-3" width="auto">
                            <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
                        </div>
                        <div class="text-center mb-3">
                            <a href="forgot_password.php" class="small">Forgot your password?</a>
                        </div>
                        <div class="text-center text-muted small" width="auto">
                            Use your assigned credentials. Contact the administrator if you need access.
                        </div>
                    </form>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="bg-light rounded-4 p-4 h-100 shadow-sm">
                        <h5 class="fw-semibold mb-3"><i class="fas fa-key me-2 text-primary"></i>Demo Login Credentials</h5>
                        <p class="text-muted small mb-3">Use these accounts for quick testing. Change their passwords after first login in production.</p>
                        <ul class="list-unstyled mb-0 small">
                            <li class="mb-3">
                                <div class="fw-semibold text-primary">Admin</div>
                                <div><code>vt_admin</code> / <code>VoltaAdmin@123</code></div>
                            </li>
                            <li class="mb-3">
                                <div class="fw-semibold text-success">Instructor</div>
                                <div><code>sam_owusu</code> / <code>Instructor@123</code></div>
                            </li>
                            <li>
                                <div class="fw-semibold text-info">Student</div>
                                <div><code>samuel_nk</code> / <code>Student@123</code></div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
