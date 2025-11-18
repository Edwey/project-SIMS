<?php
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'admissions_apply.php';
$errors = [];
$success = null;
$loggedIn = is_logged_in();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/admissions/apply.php');
    }
    $result = create_application([
        'first_name' => $_POST['first_name'] ?? '',
        'last_name' => $_POST['last_name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'program_id' => $_POST['program_id'] ?? '',
        'wasse_aggregate' => $_POST['wasse_aggregate'] ?? '',
    ]);
    if ($result['success']) {
        set_flash_message('success', $result['message']);
        redirect('/admissions/apply.php');
    } else {
        set_flash_message('error', $result['message']);
    }
}

$programs = get_programs();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Apply for Admission - University Management System</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
  <main class="container py-5">
    <?php render_flash_messages(); ?>

    <div class="card card-shadow">
      <div class="card-header bg-white border-0 py-3">
        <h5 class="section-title mb-0">Apply for Admission</h5>
      </div>
      <div class="card-body">
        <?php if (!$loggedIn): ?>
          <div class="alert alert-info d-flex align-items-center" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <div>
              You can submit this application without signing in. If you already created an account, <a href="<?php echo SITE_URL; ?>/login.php" class="alert-link">sign in</a> to track your submission and receive status updates.
            </div>
          </div>
        <?php endif; ?>
        <form method="POST" class="row g-3">
          <?php echo csrf_field(); ?>
          <div class="col-md-6">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">WASSCE Aggregate</label>
            <input type="number" name="wasse_aggregate" class="form-control" min="6" max="48" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Program</label>
            <select name="program_id" class="form-select" required>
              <option value="">-- Choose a Program --</option>
              <?php foreach ($programs as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['program_name']); ?> (<?php echo htmlspecialchars($p['program_code']); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary">Submit Application</button>
          </div>
        </form>
      </div>
    </div>
  </main>

  <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
