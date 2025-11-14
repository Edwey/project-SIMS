<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'notifications.php';

$departments = get_all_departments();
$levels = get_all_levels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/notifications.php');
    }

    // Mark single notification as read
    if (isset($_POST['mark_read_id'])) {
        $nid = (int)$_POST['mark_read_id'];
        mark_notification_read($nid, (int)current_user_id());
        set_flash_message('success', 'Notification marked as read.');
        redirect('/admin/notifications.php');
    }

    // Mark all as read
    if (isset($_POST['mark_all_read'])) {
        db_execute('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [(int)current_user_id()]);
        set_flash_message('success', 'All notifications marked as read.');
        redirect('/admin/notifications.php');
    }

    // Broadcast submit
    $roles = $_POST['roles'] ?? [];
    $deptId = isset($_POST['department_id']) && $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
    $levelId = isset($_POST['level_id']) && $_POST['level_id'] !== '' ? (int)$_POST['level_id'] : null;
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $type = $_POST['type'] ?? 'info';

    if ($title === '' || $message === '' || empty($roles)) {
        set_flash_message('error', 'Title, message and at least one audience role are required.');
        redirect('/admin/notifications.php');
    }

    $userIds = get_user_ids_for_audience($roles, $deptId, $levelId);
    if (empty($userIds)) {
        set_flash_message('warning', 'No users matched the selected audience.');
        redirect('/admin/notifications.php');
    }

    $count = send_notification_to_users($userIds, $title, $message, $type, (int)current_user_id());
    set_flash_message('success', "Notification sent to $count users.");
    redirect('/admin/notifications.php');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notifications - Admin</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main class="container py-4">
  <?php render_flash_messages(); ?>
  
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card card-shadow h-100">
        <div class="card-header bg-white border-0 py-3"><h5 class="mb-0">Broadcast Notification</h5></div>
        <div class="card-body">
          <form method="POST" class="row g-3">
            <?php echo csrf_field(); ?>
            <div class="col-12">
              <label class="form-label">Audience Roles</label>
              <div class="d-flex gap-3 flex-wrap">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="r_admin" name="roles[]" value="admin">
                  <label class="form-check-label" for="r_admin">Admins</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="r_instructor" name="roles[]" value="instructor">
                  <label class="form-check-label" for="r_instructor">Instructors</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="r_student" name="roles[]" value="student">
                  <label class="form-check-label" for="r_student">Students</label>
                </div>
              </div>
              <div class="form-text">Optional filters apply to instructors/students.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Department (optional)</label>
              <select class="form-select" name="department_id">
                <option value="">All</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['dept_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Level (students, optional)</label>
              <select class="form-select" name="level_id">
                <option value="">All</option>
                <?php foreach ($levels as $l): ?>
                  <option value="<?php echo (int)$l['id']; ?>"><?php echo htmlspecialchars($l['level_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Type</label>
              <select class="form-select" name="type">
                <?php foreach (['info','success','warning','error'] as $t): ?>
                  <option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Title</label>
              <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Message</label>
              <textarea name="message" rows="5" class="form-control" required></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary" type="submit">Send</button>
              <button class="btn btn-secondary" type="reset">Clear</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card card-shadow h-100">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Your Notifications</h5>
          <form method="POST" class="mb-0">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="mark_all_read" value="1">
            <button class="btn btn-sm btn-outline-secondary" type="submit">Mark all read</button>
          </form>
        </div>
        <div class="card-body">
          <?php $mine = get_user_notifications((int)current_user_id(), false, 25); ?>
          <?php if (empty($mine)): ?>
            <p class="text-muted mb-0">No notifications yet.</p>
          <?php else: ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($mine as $n): ?>
                <li class="border-bottom py-2">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold">[<?php echo strtoupper($n['type']); ?>] <?php echo htmlspecialchars($n['title']); ?> <?php if ((int)$n['is_read']===0): ?><span class="badge bg-danger">New</span><?php endif; ?></div>
                      <div class="text-muted small">From: <?php echo htmlspecialchars($n['sender_username'] ?? 'System'); ?> · <?php echo htmlspecialchars($n['created_at']); ?></div>
                    </div>
                    <div>
                      <?php if ((int)$n['is_read']===0): ?>
                      <form method="POST" class="mb-0">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="mark_read_id" value="<?php echo (int)$n['id']; ?>">
                        <button class="btn btn-sm btn-link">Mark read</button>
                      </form>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="small"><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>
