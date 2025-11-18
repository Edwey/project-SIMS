<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'attendance.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$studentId = (int)$student['student_internal_id'];
// Filters: course and date (all courses / all days by default)
$availableCourses = get_student_attendance_courses($studentId);

$selectedCourse = isset($_GET['course']) ? trim($_GET['course']) : '';
$selectedDate = isset($_GET['date']) ? trim($_GET['date']) : '';

if ($selectedDate !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $selectedDate)) {
    // Invalid date format, ignore filter
    $selectedDate = '';
}

$summary = get_student_attendance_summary($studentId, $selectedCourse !== '' ? $selectedCourse : null, $selectedDate !== '' ? $selectedDate : null);
$records = get_student_attendance_records($studentId, $selectedCourse !== '' ? $selectedCourse : null, $selectedDate !== '' ? $selectedDate : null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - University Management System</title>
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
                            <h1 class="display-6 mb-2">Attendance Overview</h1>
                            <p class="text-muted mb-0">
                                Student ID: <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                                &middot; <?php echo htmlspecialchars($student['dept_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['level_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <div class="stat-pill">
                                <i class="fas fa-calendar-check"></i>
                                Total Sessions: <?php echo $summary['total_count'] ?? 0; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-shadow">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end mb-4">
                            <div class="col-md-5">
                                <label for="course" class="form-label">Course</label>
                                <select name="course" id="course" class="form-select">
                                    <option value="">All courses</option>
                                    <?php foreach ($availableCourses as $course): ?>
                                        <?php $code = $course['course_code']; ?>
                                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo ($selectedCourse === $code) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($selectedDate); ?>">
                            </div>
                            <div class="col-md-2 d-grid">
                                <button type="submit" class="btn btn-primary">Filter</button>
                            </div>
                            <div class="col-md-2 d-grid">
                                <a href="attendance.php" class="btn btn-outline-secondary">Clear</a>
                            </div>
                        </form>
                        <div class="row g-3 text-center">
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4">
                                    <div class="h4 fw-bold text-success mb-1"><?php echo $summary['present_count'] ?? 0; ?></div>
                                    <div class="text-muted small">Present</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4">
                                    <div class="h4 fw-bold text-danger mb-1"><?php echo $summary['absent_count'] ?? 0; ?></div>
                                    <div class="text-muted small">Absent</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4">
                                    <div class="h4 fw-bold text-warning mb-1"><?php echo $summary['late_count'] ?? 0; ?></div>
                                    <div class="text-muted small">Late</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4">
                                    <div class="h4 fw-bold text-primary mb-1"><?php echo $summary['excused_count'] ?? 0; ?></div>
                                    <div class="text-muted small">Excused</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card card-shadow">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="section-title mb-0"><i class="fas fa-list text-primary me-2"></i>Detailed Records</h5>
                        <span class="badge bg-primary"><?php echo count($records); ?> entries</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($records)): ?>
                            <p class="text-muted mb-0">No attendance records available.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped align-middle">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Course</th>
                                        <th>Section</th>
                                        <th>Status</th>
                                        <th>Instructor</th>
                                        <th>Notes</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($records as $record): ?>
                                        <tr>
                                            <td><?php echo format_date($record['attendance_date']); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($record['course_code']); ?></strong><br>
                                                <span class="text-muted small"><?php echo htmlspecialchars($record['course_name']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['section_name']); ?></td>
                                            <td>
                                                <?php
                                                $statusMap = [
                                                    'present' => 'success',
                                                    'absent' => 'danger',
                                                    'late' => 'warning',
                                                    'excused' => 'primary',
                                                ];
                                                $badgeClass = $statusMap[$record['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $badgeClass; ?>">
                                                    <?php echo ucfirst($record['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['instructor_name']); ?></td>
                                            <td><?php echo $record['notes'] ? htmlspecialchars($record['notes']) : '<span class="text-muted">-</span>'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
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
