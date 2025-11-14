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
$currentEnrollments = get_student_current_enrollments($studentId);
$completedEnrollments = get_student_completed_enrollments($studentId);
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
        <div class="row g-4">
            <div class="col-12">
                <div class="profile-hero card-shadow">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="display-6 mb-2">My Courses</h1>
                            <p class="text-muted mb-0">
                                Student ID: <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                                &middot; <?php echo htmlspecialchars($student['dept_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['level_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <a href="enroll.php" class="btn btn-primary">Enroll in Course</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Current Semester</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($currentEnrollments)): ?>
                            <p class="text-muted mb-0">You are not currently enrolled in any courses.</p>
                        <?php else: ?>
                            <?php foreach ($currentEnrollments as $course): ?>
                                <div class="border rounded-4 p-4 mb-3">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                                        <div>
                                            <h6 class="mb-1">
                                                <span class="badge bg-primary me-2"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                                <?php echo htmlspecialchars($course['course_name']); ?>
                                            </h6>
                                            <p class="text-muted small mb-2">
                                                Instructor: <?php echo htmlspecialchars($course['instructor_name']); ?>
                                                <span class="mx-2">&middot;</span>
                                                <?php echo htmlspecialchars($course['schedule']); ?>
                                                <span class="mx-2">&middot;</span>
                                                Room <?php echo htmlspecialchars($course['room']); ?>
                                            </p>
                                            <div class="d-flex flex-wrap gap-2">
                                                <span class="badge bg-info text-dark"><?php echo (int)$course['credits']; ?> Credits</span>
                                                <span class="badge bg-secondary">Section <?php echo htmlspecialchars($course['section_name']); ?></span>
                                                <span class="badge bg-light text-muted">Status: <?php echo ucfirst($course['status']); ?></span>
                                            </div>
                                        </div>
                                        <div class="text-lg-end">
                                            <a href="grades.php" class="btn btn-outline-primary btn-sm">View Grades</a>
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
                        <h5 class="section-title mb-0"><i class="fas fa-check-circle text-success me-2"></i>Completed Courses</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($completedEnrollments)): ?>
                            <p class="text-muted mb-0">Completed courses will appear after grades are finalized.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach (array_slice($completedEnrollments, 0, 8) as $course): ?>
                                    <li class="border-bottom py-2">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($course['course_code']); ?> - <?php echo htmlspecialchars($course['course_name']); ?></div>
                                        <div class="text-muted small d-flex justify-content-between">
                                            <span><?php echo htmlspecialchars($course['semester_name']); ?> <?php echo htmlspecialchars($course['year_name']); ?></span>
                                            <span><?php echo htmlspecialchars($course['final_grade']); ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="text-end mt-3">
                                <a href="transcript.php" class="btn btn-outline-primary btn-sm">Full Transcript</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
