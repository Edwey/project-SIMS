<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'transcript.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$studentId = (int)$student['student_internal_id'];
$transcript = get_student_transcript($studentId);
$gpaSummary = get_student_gpa_summary($studentId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Transcript - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <div class="card card-shadow">
            <div class="card-header bg-white border-0 py-4 d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">Official Academic Transcript</h1>
                    <p class="text-muted mb-0">Student ID: <?php echo htmlspecialchars($student['student_id']); ?> &middot; <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                </div>
                <a href="#" class="btn btn-outline-primary">Download PDF</a>
            </div>
            <div class="card-body">
                <?php if ($gpaSummary): ?>
                    <div class="row g-4 mb-4">
                        <div class="col-md-4">
                            <div class="p-4 bg-light rounded-4 text-center h-100">
                                <div class="display-6 fw-bold text-primary mb-0"><?php echo number_format($gpaSummary['calculated_gpa'], 2); ?></div>
                                <div class="text-muted small">Overall GPA</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-4 bg-light rounded-4 text-center h-100">
                                <div class="h4 mb-0"><?php echo (int)$gpaSummary['total_credits']; ?></div>
                                <div class="text-muted small">Credits Earned</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-4 bg-light rounded-4 text-center h-100">
                                <div class="h4 mb-0"><?php echo (int)$gpaSummary['total_courses']; ?></div>
                                <div class="text-muted small">Courses Completed</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($transcript)): ?>
                    <p class="text-muted">No completed courses yet.</p>
                <?php else: ?>
                    <?php
                    $grouped = [];
                    foreach ($transcript as $entry) {
                        $periodKey = $entry['year_name'] . ' - ' . $entry['semester_name'];
                        $grouped[$periodKey][] = $entry;
                    }
                    ?>
                    <?php foreach ($grouped as $period => $entries): ?>
                        <div class="border rounded-4 p-4 mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><?php echo htmlspecialchars($period); ?></h5>
                                <span class="badge bg-secondary"><?php echo count($entries); ?> Courses</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>Credits</th>
                                            <th>Grade</th>
                                            <th>Grade Points</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($entries as $course): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($course['course_code']); ?></strong><br>
                                                    <span class="text-muted small"><?php echo htmlspecialchars($course['course_name']); ?></span>
                                                </td>
                                                <td><?php echo (float)$course['credits']; ?></td>
                                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($course['final_grade']); ?></span></td>
                                                <td><?php echo number_format((float)$course['grade_points'], 2); ?></td>
                                                <td><?php echo ucfirst($course['status']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
