<?php require_once __DIR__ . '/../includes/functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admissions</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
  <div class="auth-card" style="max-width: 720px;">
    <header class="mb-3 text-center">
      <h1 class="fw-semibold mb-1">Admissions</h1>
      <p class="mb-0 text-muted">Submit your application as a guest.</p>
    </header>
    <div class="card-body text-center">
      <?php foreach (get_flash_messages('error') as $message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($message); ?></div>
      <?php endforeach; ?>
      <?php foreach (get_flash_messages('success') as $message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
      <?php endforeach; ?>

      <a class="btn btn-success btn-lg" href="<?php echo SITE_URL; ?>/admissions/apply.php">Start Application</a>
    </div>
  </div>
  <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
