<?php
require_once __DIR__ . '/../includes/instructor_auth.php';

$current_page = 'advising.php';
$instructorId = get_current_instructor_id();
if (!$instructorId) {
    set_flash_message('error', 'Your account is not linked to an instructor profile.');
    $advisees = [];
} else {
$advisees = get_instructor_advisees($instructorId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/instructor/advising.php');
    }
    $studentId = (int)($_POST['student_id'] ?? 0);
    $title = sanitize_input($_POST['title'] ?? '');
    $message = sanitize_input($_POST['message'] ?? '');
    $type = sanitize_input($_POST['type'] ?? 'info');

    if (!$studentId || $title === '' || $message === '') {
        set_flash_message('error', 'Student, title, and message are required.');
    } else {
        $user = db_query_one('SELECT user_id FROM students WHERE id = ?', [$studentId]);
        if ($user && $user['user_id']) {
            send_notification_to_user((int)$user['user_id'], $title, $message, $type, (int)current_user_id());
            set_flash_message('success', 'Notification sent to advisee.');
        } else {
            set_flash_message('error', 'Unable to find advisee user account.');
        }
    }

    redirect('/instructor/advising.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advising & Notifications - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0"><i class="fas fa-user-graduate text-primary me-2"></i>Your Advisees</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($advisees)): ?>
                            <p class="text-muted mb-0">No advisees assigned.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Contact</th>
                                            <th>Status</th>
                                            <th class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($advisees as $advisee): ?>
                                        <?php $sid = (int)($advisee['student_internal_id'] ?? 0); ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($advisee['first_name'] . ' ' . $advisee['last_name']); ?></strong><br>
                                                <span class="text-muted small">ID: <?php echo htmlspecialchars($advisee['student_id']); ?></span>
                                            </td>
                                            <td>
                                                <span class="text-muted small d-block">Email: <?php echo htmlspecialchars($advisee['email']); ?></span>
                                                <span class="text-muted small">Phone: <?php echo htmlspecialchars($advisee['phone']); ?></span>
                                            </td>
                                            <td>
                                                <?php echo $advisee['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?>
                                            </td>
                                            <td class="text-end">
                                                <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#grades-<?php echo $sid; ?>" aria-expanded="false" aria-controls="grades-<?php echo $sid; ?>">Grades</button>
                                            </td>
                                        </tr>
                                        <tr class="collapse" id="grades-<?php echo $sid; ?>">
                                            <td colspan="4">
                                                <?php
                                                $grades = db_query(
                                                    "SELECT g.assessment_type, g.assessment_name, g.score, g.max_score, g.grade_date,
                                                            c.course_code, c.course_name, cs.section_name
                                                     FROM grades g
                                                     JOIN enrollments e ON g.enrollment_id = e.id
                                                     JOIN course_sections cs ON e.course_section_id = cs.id
                                                     JOIN courses c ON cs.course_id = c.id
                                                     WHERE e.student_id = ?
                                                     ORDER BY g.grade_date DESC
                                                     LIMIT 20",
                                                     [$sid]
                                                );
                                                ?>
                                                <?php if (empty($grades)): ?>
                                                    <div class="text-muted small">No grades found for this student.</div>
                                                <?php else: ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm mb-0">
                                                            <thead>
                                                                <tr class="small">
                                                                    <th>Course</th>
                                                                    <th>Assessment</th>
                                                                    <th>Score</th>
                                                                    <th>Date</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                            <?php foreach ($grades as $g): ?>
                                                                <tr class="small">
                                                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($g['course_code']); ?></span> <?php echo htmlspecialchars($g['course_name']); ?> <span class="text-muted">(<?php echo htmlspecialchars($g['section_name']); ?>)</span></td>
                                                                    <td><?php echo htmlspecialchars(ucfirst($g['assessment_type'])); ?>: <?php echo htmlspecialchars($g['assessment_name']); ?></td>
                                                                    <td><?php echo htmlspecialchars($g['score']); ?> / <?php echo htmlspecialchars($g['max_score']); ?></td>
                                                                    <td class="text-muted"><?php echo htmlspecialchars($g['grade_date']); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card card-shadow">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0"><i class="fas fa-paper-plane text-success me-2"></i>Send Notification</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <?php echo csrf_field(); ?>
                            <div class="col-12">
                                <label class="form-label">Advisee</label>
                                <select name="student_id" class="form-select" required>
                                    <option value="">-- Select advisee --</option>
                                    <?php foreach ($advisees as $advisee): ?>
                                        <option value="<?php echo (int)$advisee['student_internal_id']; ?>"><?php echo htmlspecialchars($advisee['first_name'] . ' ' . $advisee['last_name']); ?> (<?php echo htmlspecialchars($advisee['student_id']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Title</label>
                                <input type="text" name="title" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message</label>
                                <textarea name="message" class="form-control" rows="4" required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type</label>
                                <select name="type" class="form-select">
                                    <option value="info">Information</option>
                                    <option value="warning">Warning</option>
                                    <option value="success">Success</option>
                                    <option value="error">Alert</option>
                                </select>
                            </div>
                            <div class="col-md-6 align-self-end">
                                <button type="submit" class="btn btn-primary w-100">Send Notification</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
