<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$current_page = 'finalize_registration.php';
$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $key = trim($_POST['application_key'] ?? '');
        if ($key === '') {
            $errors[] = 'Application key is required.';
        } else {
            $result = finalize_registration_by_key($key, (int)current_user_id());
            if ($result['success']) {
                set_flash_message('success', $result['message'] ?? 'Registration finalized.');
                redirect('/student/dashboard.php');
            } else {
                $errors[] = $result['message'] ?? 'Unable to finalize registration with the supplied key.';
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
  <title>Finalize Registration</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <main class="container py-5">
    <?php render_flash_messages(); ?>
    <div class="row justify-content-center">
      <div class="col-lg-6">
        <div class="card card-shadow">
          <div class="card-body p-4">
            <h2 class="h4 mb-3">Finalize Student Registration</h2>
            <p class="text-muted">Paste the application key from your Admissions dashboard to complete onboarding.</p>

            <?php foreach ($errors as $message): ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div>
            <?php endforeach; ?>

            <form method="POST" novalidate>
              <?php echo csrf_field(); ?>
              <div class="mb-3">
                <label for="application_key" class="form-label">Application Key</label>
                <input type="text" name="application_key" id="application_key" class="form-control" placeholder="Paste your key here" required>
              </div>
              <div class="d-grid">
                <button type="submit" class="btn btn-success">Finalize Registration</button>
              </div>
            </form>

            <div class="mt-3 text-muted small">
              Need help finding your key? Visit the <a href="<?php echo SITE_URL; ?>/applications/dashboard.php">Applications Dashboard</a>.
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
  <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
