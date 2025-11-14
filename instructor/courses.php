<?php
require_once __DIR__ . '/../includes/instructor_auth.php';

$current_page = 'courses.php';
$instructorId = get_current_instructor_id();
if (!$instructorId) {
    set_flash_message('error', 'Your account is not linked to an instructor profile.');
    $courseOverview = [];
} else {

$courseOverview = get_instructor_course_overview($instructorId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <div class="card card-shadow mb-4">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="section-title mb-0"><i class="fas fa-clipboard-list text-primary me-2"></i>Course Overview</h5>
                <span class="badge bg-primary"><?php echo count($courseOverview); ?> sections</span>
            </div>
            <div class="card-body">
                <?php if (empty($courseOverview)): ?>
                    <p class="text-muted mb-0">You are not currently assigned to any course sections.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Course</th>
                                    <th>Section</th>
                                    <th>Schedule</th>
                                    <th>Enrollment</th>
                                    <th>Pending Grades</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($courseOverview as $section): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($section['course_code']); ?></strong><br>
                                        <span class="text-muted small"><?php echo htmlspecialchars($section['course_name']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($section['section_name']); ?></td>
                                    <td>
                                        <div class="text-muted small">
                                            <?php echo htmlspecialchars($section['semester_name'] . ' ' . $section['year_name']); ?>
                                        </div>
                                        <div><?php echo htmlspecialchars($section['schedule']); ?></div>
                                        <div class="text-muted small">Room: <?php echo htmlspecialchars($section['room']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo (int)$section['enrolled_count']; ?></span>
                                        <span class="text-muted small">/ <?php echo (int)$section['capacity']; ?></span>
                                    </td>
                                    <td>
                                        <?php $pending = (int)($section['pending_grades'] ?? 0); ?>
                                        <?php if ($pending > 0): ?>
                                            <span class="badge bg-warning text-dark"><?php echo $pending; ?> pending</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="gradebook.php?section_id=<?php echo (int)$section['id']; ?>" class="btn btn-outline-primary">
                                                Gradebook
                                            </a>
                                            <a href="attendance.php?section_id=<?php echo (int)$section['id']; ?>" class="btn btn-outline-secondary">
                                                Attendance
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
