<?php
require_once __DIR__ . '/includes/functions.php';

$current_page = 'register.php';
set_security_headers();

$errors = [];
$success = false;
if (is_logged_in()) {
    redirect('/applications/dashboard.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($username === '' || $email === '' || $password === '' || $confirm === '') {
            $errors[] = 'All fields are required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }
        $dupeU = db_query_one('SELECT id FROM users WHERE username = ? LIMIT 1', [$username]);
        if ($dupeU) { $errors[] = 'Username already taken.'; }
        $dupeE = db_query_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($dupeE) { $errors[] = 'Email already registered.'; }

        if (empty($errors)) {
            db_execute('INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, "student", 1, NOW())', [
                $username, $email, hash_password($password)
            ]);
            // Log the user in
            $userId = (int)db_last_id();
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = 'student';
            $_SESSION['last_activity'] = time();
            set_flash_message('success', 'Registration successful. You can now apply for admission.');
            redirect('/applications/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register - University</title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
  <div class="auth-card" style="max-width: 720px;">
    <header>
      <h1 class="fw-semibold mb-1" align="center">Create Account</h1>
      <p class="mb-0 text-muted" align="center">Register to access the Applications Dashboard</p>
    </header>
    <div class="card-body">
      <?php foreach ($errors as $message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div>
      <?php endforeach; ?>
      <?php foreach (get_flash_messages('success') as $message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
      <?php endforeach; ?>

      <div class="row g-4 align-items-start">
        <div class="col-12 col-lg-6">
          <form method="POST" novalidate>
            <?php echo csrf_field(); ?>
            <div class="mb-3">
              <label for="username" class="form-label">Username</label>
              <input type="text" name="username" id="username" class="form-control form-control-lg" placeholder="Choose a username" required autofocus>
            </div>
            <div class="mb-3">
              <label for="email" class="form-label">Email</label>
              <input type="email" name="email" id="email" class="form-control form-control-lg" placeholder="you@example.com" required>
            </div>
            <div class="mb-3">
              <label for="password" class="form-label">Password</label>
              <input type="password" name="password" id="password" class="form-control form-control-lg" placeholder="At least 8 characters" required>
            </div>
            <div class="mb-3">
              <label for="confirm_password" class="form-label">Confirm Password</label>
              <input type="password" name="confirm_password" id="confirm_password" class="form-control form-control-lg" required>
            </div>
            <div class="d-grid mb-3">
              <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
            </div>
            <div class="text-center text-muted small">
              Already have an account? <a href="/project/login.php">Sign in</a>
            </div>
          </form>
        </div>
        <div class="col-12 col-lg-6">
          <div class="p-3 bg-light rounded h-100">
            <h6 class="fw-semibold">What you get</h6>
            <ul class="mb-0">
              <li>Access to Applications Dashboard</li>
              <li>Apply to programs with WASSCE aggregate</li>
              <li>Track application status and key</li>
              <li>Finalize student onboarding with key when accepted</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
