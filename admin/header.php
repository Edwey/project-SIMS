<?php
$pageTitle = $pageTitle ?? 'Admin';
$bodyClass = $bodyClass ?? 'bg-light';
$bodyAttributes = isset($bodyAttributes) && $bodyAttributes !== '' ? ' ' . trim($bodyAttributes) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle); ?> - Admin</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>"<?php echo $bodyAttributes; ?>>
  <div class="admin-layout d-flex min-vh-100">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="flex-grow-1 bg-light d-flex flex-column">
      <?php include __DIR__ . '/topbar.php'; ?>
      <main class="container-fluid py-4 flex-grow-1">
