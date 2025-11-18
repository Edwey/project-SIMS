<?php
require_once __DIR__ . '/../includes/instructor_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'notifications.php';
$userId = (int)current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/instructor/notifications.php');
    }
    if (isset($_POST['mark_read']) && isset($_POST['nid'])) {
        mark_notification_read((int)$_POST['nid'], $userId);
        redirect('/instructor/notifications.php');
    }
    if (isset($_POST['mark_all'])) {
        $rows = get_user_notifications($userId, true, 1000);
        foreach ($rows as $n) { mark_notification_read((int)$n['id'], $userId); }
        redirect('/instructor/notifications.php');
    }
}

$onlyUnread = isset($_GET['filter']) && $_GET['filter'] === 'unread';
$notifications = get_user_notifications($userId, $onlyUnread, 200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notifications - Instructor</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="container py-4">
  <?php render_flash_messages(); ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Notifications</h4>
    <div>
      <a class="btn btn-outline-secondary btn-sm" href="<?php echo SITE_URL; ?>/instructor/notifications.php?filter=<?php echo $onlyUnread ? '' : 'unread'; ?>">
        <?php echo $onlyUnread ? 'Show All' : 'Show Unread'; ?>
      </a>
      <form method="POST" class="d-inline">
        <?php echo csrf_field(); ?>
        <button class="btn btn-primary btn-sm" name="mark_all" value="1">Mark All Read</button>
      </form>
    </div>
  </div>

  <div class="card card-shadow">
    <div class="card-body">
      <?php if (empty($notifications)): ?>
        <p class="text-muted mb-0">No notifications.</p>
      <?php else: ?>
          <?php foreach ($notifications as $n): ?>
            <li class="border-bottom py-3">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold">[<?php echo strtoupper($n['type']); ?>] <?php echo htmlspecialchars($n['title']); ?></div>
                  <div class="small text-muted mb-1"><?php echo htmlspecialchars($n['created_at']); ?> <?php if (!(int)$n['is_read']): ?><span class="badge bg-info ms-2">New</span><?php endif; ?></div>
                  <div class="small text-muted mb-2">From: <?php echo htmlspecialchars($n['sender_username'] ?? 'System'); ?></div>
                  <div><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
                </div>
                <?php if (!(int)$n['is_read']): ?>
                <form method="POST">
                  <?php echo csrf_field(); ?>
                  <button class="btn btn-sm btn-outline-primary" name="mark_read" value="1">Mark Read</button>
                </form>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</main>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
