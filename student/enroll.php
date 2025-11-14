<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'my_courses.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$studentId = (int)$student['student_internal_id'];
$departmentId = (int)$student['department_id'];
$currentPeriod = get_current_academic_period();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['section_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/student/enroll.php');
    }
    $sectionId = (int)$_POST['section_id'];

    if (!$currentPeriod) {
        set_flash_message('error', 'Enrollment is currently closed.');
        redirect('/student/enroll.php');
    }

    $result = enroll_student($studentId, $sectionId, (int)$currentPeriod['semester_id'], (int)$currentPeriod['academic_year_id']);

    if ($result['success'] ?? false) {
        set_flash_message('success', 'Enrollment request submitted successfully.');
    } else {
        $message = $result['message'] ?? 'Unable to enroll in the selected section.';
        set_flash_message('error', $message);
    }

    redirect('/student/enroll.php');
}

$currentEnrollments = get_student_current_enrollments($studentId);
$sections = [];

if ($currentPeriod && $departmentId) {
    $sections = get_department_sections($departmentId, (int)$currentPeriod['semester_id'], (int)$currentPeriod['academic_year_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Enrollment - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <div class="row g-4">
            <div class="col-12">
                <div class="profile-hero card-shadow mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="display-6 mb-2">Course Enrollment</h1>
                            <p class="text-muted mb-0">
                                Student ID: <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                                &middot; <?php echo htmlspecialchars($student['dept_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['level_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <?php if ($currentPeriod): ?>
                                <span class="badge bg-primary p-3">
                                    <?php echo htmlspecialchars($currentPeriod['semester_name']); ?> <?php echo htmlspecialchars($currentPeriod['year_name']); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary p-3">Enrollment Closed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-shadow">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0"><i class="fas fa-clipboard-check text-primary me-2"></i>Available Sections</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$currentPeriod): ?>
                            <p class="text-muted">Enrollment is not available at this time.</p>
                        <?php elseif (empty($sections)): ?>
                            <p class="text-muted">No sections available for your department in the current semester.</p>
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
                                                <i class="fas fa-chalkboard-teacher me-1"></i> <?php echo htmlspecialchars($section['instructor_name']); ?>
                                                <span class="mx-2">&middot;</span>
                                                <i class="fas fa-clock me-1"></i> <?php echo htmlspecialchars($section['schedule']); ?>
                                                <span class="mx-2">&middot;</span>
                                                <i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($section['room']); ?>
                                            </p>
                                            <div class="d-flex flex-wrap gap-2">
                                                <span class="badge bg-info text-dark">Section <?php echo htmlspecialchars($section['section_name']); ?></span>
                                                <span class="badge bg-secondary">Credits: <?php echo (int)$section['credits']; ?></span>
                                            </div>
                                        </div>
                                        <div class="text-lg-end">
                                            <form method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="section_id" value="<?php echo (int)$section['id']; ?>">
                                                <button type="submit" class="btn btn-primary btn-sm">Enroll</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-shadow">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0"><i class="fas fa-book-open text-success me-2"></i>Current Enrollments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($currentEnrollments)): ?>
                            <p class="text-muted mb-0">You are not enrolled in any courses this semester.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($currentEnrollments as $course): ?>
                                    <li class="border-bottom py-2">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($course['course_code']); ?> - <?php echo htmlspecialchars($course['course_name']); ?></div>
                                        <div class="text-muted small">Section <?php echo htmlspecialchars($course['section_name']); ?> &middot; Credits <?php echo (int)$course['credits']; ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
