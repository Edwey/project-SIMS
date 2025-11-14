<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$inviteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = trim($_GET['token'] ?? '');

// Resolve current student's internal ID
$userId = (int)current_user_id();
$student = db_query_one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
if (!$student) {
    set_flash_message('error', 'No student profile linked to your account.');
    redirect('/student/profile.php');
}
$studentId = (int)$student['id'];

// Load invite and validate ownership
$invite = $inviteId > 0 ? get_enrollment_invite($inviteId) : null;
if (!$invite) {
    set_flash_message('error', 'Invite not found.');
    redirect('/student/dashboard.php');
}
if ((int)$invite['student_id'] !== $studentId) {
    set_flash_message('error', 'This invite does not belong to your account.');
    redirect('/student/dashboard.php');
}

// Enrich invite with section/course/term information
$details = db_query_one(
    'SELECT cs.id AS section_id, cs.section_code, c.course_code, c.course_name, s.semester_name, ay.year_name
     FROM course_sections cs
     JOIN courses c ON c.id = cs.course_id
     JOIN semesters s ON s.id = cs.semester_id
     JOIN academic_years ay ON ay.id = s.academic_year_id
     WHERE cs.id = ? LIMIT 1',
    [(int)$invite['course_section_id']]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/student/enrollment_invite.php?id=' . $inviteId . '&token=' . urlencode($token));
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['accept','decline'], true)) {
        set_flash_message('error', 'Invalid action.');
        redirect('/student/enrollment_invite.php?id=' . $inviteId . '&token=' . urlencode($token));
    }

    $res = respond_enrollment_invite($inviteId, $token, $action, $userId);
    if ($res['success']) {
        set_flash_message('success', $res['message'] ?? 'Success.');
    } else {
        set_flash_message('error', $res['message'] ?? 'Unable to process your response.');
    }
    redirect('/student/enrollment_invite.php?id=' . $inviteId . '&token=' . urlencode($token));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Enrollment Invitation</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
  <?php $current_page = 'enrollment_invite.php'; include __DIR__ . '/navbar.php'; ?>
  <main class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="card card-shadow">
          <div class="card-header bg-white border-0 py-3">
            <h5 class="mb-0">Enrollment Invitation</h5>
          </div>
          <div class="card-body">
            <p class="text-muted">Status: <strong class="text-<?php echo $invite['status']==='pending'?'warning':($invite['status']==='accepted'?'success':'secondary'); ?>"><?php echo htmlspecialchars($invite['status']); ?></strong></p>

            <?php if ($details): ?>
              <dl class="row">
                <dt class="col-sm-3">Course</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($details['course_code'] . ' - ' . $details['course_name']); ?></dd>

                <dt class="col-sm-3">Section</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($details['section_code']); ?></dd>

                <dt class="col-sm-3">Semester</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($details['semester_name'] . ' (' . $details['year_name'] . ')'); ?></dd>
              </dl>
            <?php endif; ?>

            <?php if ($invite['status'] === 'pending'): ?>
              <div class="alert alert-info">You have been invited to enroll in this section. Please choose to accept or decline.</div>
              <form method="post" class="d-inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="accept">
                <button type="submit" class="btn btn-success">Accept Invite</button>
              </form>
              <form method="post" class="d-inline ms-2">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="decline">
                <button type="submit" class="btn btn-outline-secondary">Decline</button>
              </form>
            <?php else: ?>
              <div class="alert alert-secondary mb-0">This invitation has already been responded to.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
  <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
