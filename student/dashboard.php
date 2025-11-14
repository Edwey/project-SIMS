<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'dashboard.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$graduationLockAt = $student['graduation_lock_at'] ?? null;

$studentId = (int)$student['student_internal_id'];
$currentPeriod = get_current_academic_period();
$enrollments = get_student_current_enrollments($studentId);
$gpaSummary = get_student_gpa_summary($studentId);
$gpaByTerm = get_gpa_by_term($studentId);
$recentGrades = get_student_recent_grades($studentId, 5);
$attendanceSummary = get_student_attendance_summary($studentId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>
        <?php if (!empty($graduationLockAt)): ?>
            <div class="alert alert-warning d-flex align-items-center" role="alert">
                <div class="me-3">
                    <i class="bi bi-clock-history fs-4"></i>
                </div>
                <div>
                    <strong>Graduation access window.</strong> Your student portal access remains available until
                    <span class="fw-semibold"><?php echo htmlspecialchars(format_datetime_display($graduationLockAt, 'M j, Y H:i')); ?></span>.
                    Please download transcripts or documents before this time.
                </div>
            </div>
        <?php endif; ?>
        <div class="row g-4">
            <div class="col-12">
                <div class="profile-hero card-shadow">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="display-6 mb-2">Welcome back, <?php echo htmlspecialchars($student['first_name']); ?>!</h1>
                            <p class="text-muted mb-3">
                                Student ID: <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                                &middot; <?php echo htmlspecialchars($student['dept_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['level_name']); ?>
                            </p>
                            <div class="stat-pill">Enrolled since <?php echo format_date($student['enrollment_date']); ?></div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="d-inline-flex flex-column align-items-end gap-2">
                                <div class="avatar-initials" aria-label="Avatar">
                                    <?php echo htmlspecialchars(strtoupper($student['first_name'][0] . $student['last_name'][0])); ?>
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
                <div class="card card-shadow mt-4">
                    <div class="card-body">
                        <h5 class="section-title mb-3">Quick Actions</h5>
                        <div class="d-flex flex-column gap-2">
                            <a href="enroll.php" class="btn btn-outline-primary btn-sm">Enroll in Courses</a>
                            <a href="my_courses.php" class="btn btn-outline-secondary btn-sm">View My Courses</a>
                            <a href="fee_statement.php" class="btn btn-outline-success btn-sm">View Fee Statement</a>
                            <a href="profile.php" class="btn btn-outline-dark btn-sm">Update Profile</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="section-title mb-0">Current Courses</h5>
                        <a href="my_courses.php" class="btn btn-outline-primary btn-sm">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($enrollments)): ?>
                            <div class="text-center py-5 text-muted">
                                <p class="mb-1">You are not enrolled in any courses for the current semester.</p>
                                <p class="mb-0">Visit the enrollment page to add courses.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($enrollments as $course): ?>
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
                                                Time: <?php echo htmlspecialchars($course['schedule']); ?>
                                                <span class="mx-2">&middot;</span>
                                                Room: <?php echo htmlspecialchars($course['room']); ?>
                                            </p>
                                            <div class="d-flex flex-wrap gap-2">
                                                <span class="badge bg-info text-dark"><?php echo (int)$course['credits']; ?> Credits</span>
                                                <span class="badge bg-secondary">Section <?php echo htmlspecialchars($course['section_name']); ?></span>
                                                <span class="badge bg-light text-muted">Status: <?php echo ucfirst($course['status']); ?></span>
                                            </div>
                                        </div>
                                        <div class="text-lg-end">
                                            <?php if (!empty($course['final_grade'])): ?>
                                                <span class="badge bg-success fs-5 px-3 py-2">Final Grade: <?php echo htmlspecialchars($course['final_grade']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark px-3 py-2">In Progress</span>
                                            <?php endif; ?>
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
                        <h5 class="section-title mb-3">Academic Snapshot</h5>
                        <?php if ($gpaSummary): ?>
                            <div class="bg-light rounded-4 p-4 text-center mb-3">
                                <div class="display-6 fw-bold text-primary mb-0"><?php echo number_format($gpaSummary['calculated_gpa'], 2); ?></div>
                                <div class="text-muted small">Current GPA</div>
                            </div>
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="fw-semibold"><?php echo (int)$gpaSummary['total_credits']; ?></div>
                                    <div class="text-muted small">Credits Earned</div>
                                </div>
                                <div class="col-6">
                                    <div class="fw-semibold"><?php echo (int)$gpaSummary['total_courses']; ?></div>
                                    <div class="text-muted small">Courses Completed</div>
                                </div>
                            </div>
                            <hr class="my-4">
                            <h6 class="fw-semibold mb-3">GPA by Term</h6>
                            <?php if (!empty($gpaByTerm)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless align-middle mb-0">
                                        <thead>
                                            <tr class="text-muted small">
                                                <th>Academic Year</th>
                                                <th>Semester</th>
                                                <th class="text-center">Term GPA</th>
                                                <th class="text-center">Credits</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($gpaByTerm as $term): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($term['year_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($term['semester_name']); ?></td>
                                                    <td class="text-center"><span class="badge bg-primary-subtle text-primary fw-semibold"><?php echo number_format((float)$term['term_gpa'], 2); ?></span></td>
                                                    <td class="text-center"><?php echo (int)$term['term_credits']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3 text-end">
                                    <a href="degree_audit.php" class="small text-decoration-none">View full degree audit →</a>
                                </div>
                            <?php else: ?>
                                <p class="text-muted small mb-0">Term GPA will appear after final grades are posted.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">GPA information will appear once grades are recorded.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card card-shadow mb-4">
                    <div class="card-body">
                        <h5 class="section-title mb-3">Attendance (30 days)</h5>
                        <?php if ($attendanceSummary && $attendanceSummary['total_count'] > 0): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted small">Sessions attended</span>
                                <span class="badge bg-success"><?php echo $attendanceSummary['present_count']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted small">Absences</span>
                                <span class="badge bg-danger"><?php echo $attendanceSummary['absent_count']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Late / Excused</span>
                                <span class="badge bg-warning text-dark"><?php echo ($attendanceSummary['late_count'] + $attendanceSummary['excused_count']); ?></span>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No attendance records for the past 30 days.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card card-shadow">
                    <div class="card-body">
                        <h5 class="section-title mb-3">Recent Grades</h5>
                        <?php if (empty($recentGrades)): ?>
                            <p class="text-muted mb-0">No recent grade entries.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($recentGrades as $grade): ?>
                                    <li class="border-bottom py-2">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($grade['course_code']); ?> - <?php echo htmlspecialchars($grade['assessment_name']); ?></div>
                                        <div class="text-muted small d-flex justify-content-between">
                                            <span><?php echo ucfirst(htmlspecialchars($grade['assessment_type'])); ?></span>
                                            <span><?php echo (float)$grade['score']; ?> / <?php echo (float)$grade['max_score']; ?></span>
                                        </div>
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
