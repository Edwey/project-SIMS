<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('instructor');

$current_page = 'dashboard.php';
$instructorId = get_current_instructor_id();
if (!$instructorId) {
    set_flash_message('error', 'Your account is not linked to an instructor profile. Please contact admin.');
    $sections = [];
    $courseOverview = [];
    $overviewStats = ['section_count'=>0,'student_count'=>0,'pending_grades'=>0];
    $upcomingClasses = [];
    $advisees = [];
} else {
$currentPeriod = get_current_academic_period();

$sections = get_instructor_sections($instructorId);
$courseOverview = get_instructor_course_overview($instructorId);
}

if ($instructorId) { $overviewStats = db_query_one(
    "SELECT
        COUNT(DISTINCT cs.id) AS section_count,
        COUNT(DISTINCT e.student_id) AS student_count,
        SUM(CASE WHEN e.final_grade IS NULL THEN 1 ELSE 0 END) AS pending_grades
    FROM course_sections cs
    LEFT JOIN enrollments e ON cs.id = e.course_section_id
    WHERE cs.instructor_id = ?",
    [$instructorId]
); } 

if ($instructorId) { $upcomingClasses = db_query(
    "SELECT c.course_code, c.course_name, cs.section_name, cs.schedule
     FROM course_sections cs
     JOIN courses c ON cs.course_id = c.id
     WHERE cs.instructor_id = ?
     ORDER BY cs.schedule ASC
     LIMIT 5",
    [$instructorId]
);
}

if ($instructorId) { $advisees = db_query(
    "SELECT s.student_id, s.first_name, s.last_name, s.email
     FROM student_advisors sa
     JOIN students s ON sa.student_id = s.id
     WHERE sa.instructor_id = ? AND sa.is_active = 1
     ORDER BY s.first_name",
    [$instructorId]
);}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>
        <div class="row g-4">
            <div class="col-12">
                <div class="profile-hero card-shadow">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="display-6 mb-2">Welcome back, <?php echo htmlspecialchars(current_username()); ?>!</h1>
                            <p class="text-muted mb-0">
                                You are managing <strong><?php echo (int)($overviewStats['section_count'] ?? 0); ?></strong> active sections
                                with <strong><?php echo (int)($overviewStats['student_count'] ?? 0); ?></strong> students.
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <div class="d-inline-flex flex-column align-items-end gap-2">
                                <div class="stat-pill">
                                    <i class="fas fa-tasks"></i>
                                    Pending grades: <?php echo (int)($overviewStats['pending_grades'] ?? 0); ?>
                                </div>
                                <?php if ($currentPeriod): ?>
                                    <span class="badge bg-primary">
                                        <?php echo htmlspecialchars($currentPeriod['semester_name']); ?>
                                        <?php echo htmlspecialchars($currentPeriod['year_name']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="section-title mb-0"><i class="fas fa-book-open text-primary me-2"></i>Your Sections</h5>
                        <span class="badge bg-primary"><?php echo count($sections); ?> sections</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($sections)): ?>
                            <p class="text-muted mb-0">You are not assigned to any course sections yet.</p>
                        <?php else: ?>
                            <?php foreach ($sections as $section): ?>
                                <div class="border rounded-4 p-4 mb-3">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                                        <div>
                                            <h6 class="mb-1">
                                                <span class="badge bg-primary me-2"><?php echo htmlspecialchars($section['course_code']); ?></span>
                                                <?php echo htmlspecialchars($section['course_name']); ?>
                                            </h6>
                                            <p class="text-muted small mb-2">
                                                Section <?php echo htmlspecialchars($section['section_name']); ?>
                                                <span class="mx-2">&middot;</span>
                                                <?php echo htmlspecialchars($section['semester_name'] . ' ' . $section['year_name']); ?>
                                                <span class="mx-2">&middot;</span>
                                                Schedule: <?php echo htmlspecialchars($section['schedule']); ?>
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a href="gradebook.php?section_id=<?php echo (int)$section['id']; ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-pencil-alt me-1"></i>Gradebook
                                            </a>
                                            <a href="attendance.php?section_id=<?php echo (int)$section['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                                <i class="fas fa-calendar-check me-1"></i>Attendance
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-shadow mb-4">
                    <div class="card-body">
                        <h5 class="section-title mb-3"><i class="fas fa-clock text-info me-2"></i>Upcoming Classes</h5>
                        <?php if (empty($upcomingClasses)): ?>
                            <p class="text-muted mb-0">No upcoming classes scheduled.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0 small">
                                <?php foreach ($upcomingClasses as $class): ?>
                                    <li class="border-bottom py-2">
                                        <strong><?php echo htmlspecialchars($class['course_code']); ?></strong>
                                        <span class="text-muted">(<?php echo htmlspecialchars($class['section_name']); ?>)</span><br>
                                        <span class="text-muted">Schedule: <?php echo htmlspecialchars($class['schedule']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card card-shadow">
                    <div class="card-body">
                        <h5 class="section-title mb-3"><i class="fas fa-user-friends text-success me-2"></i>Advisees</h5>
                        <?php if (empty($advisees)): ?>
                            <p class="text-muted mb-0">No assigned advisees.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0 small">
                                <?php foreach ($advisees as $advisee): ?>
                                    <li class="border-bottom py-2">
                                        <strong><?php echo htmlspecialchars($advisee['first_name'] . ' ' . $advisee['last_name']); ?></strong><br>
                                        <span class="text-muted">ID: <?php echo htmlspecialchars($advisee['student_id']); ?></span><br>
                                        <span class="text-muted">Email: <?php echo htmlspecialchars($advisee['email']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-shadow">
                    <div class="card-body">
                        <h5 class="section-title mb-3"><i class="fas fa-bolt text-warning me-2"></i>Quick Actions</h5>
                        <ul class="list-unstyled mb-0 small">
                            <?php
                            $pendingSections = array_filter($courseOverview, static function ($section) {
                                return ($section['pending_grades'] ?? 0) > 0;
                            });
                            ?>
                            <?php if (empty($pendingSections)): ?>
                                <li class="text-muted">All grades are up to date.</li>
                            <?php else: ?>
                                <?php foreach (array_slice($pendingSections, 0, 4) as $section): ?>
                                    <li class="border-bottom py-2">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['course_name']); ?></div>
                                        <div class="text-muted">Pending grades: <?php echo (int)$section['pending_grades']; ?></div>
                                        <div class="mt-2">
                                            <a href="gradebook.php?section_id=<?php echo (int)$section['id']; ?>" class="btn btn-outline-primary btn-sm">Open Gradebook</a>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <li class="mt-3">
                                <a href="advising.php" class="btn btn-outline-success btn-sm w-100"><i class="fas fa-paper-plane me-1"></i>Send Notification</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
