<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'grades.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$studentId = (int)$student['student_internal_id'];

// Current semester assessments
$currentGradesRaw = db_query(
    "SELECT
        c.course_code,
        c.course_name,
        c.credits,
        cs.section_name,
        CONCAT(i.first_name, ' ', i.last_name) AS instructor_name,
        g.assessment_type,
        g.assessment_name,
        g.score,
        g.max_score,
        g.weight,
        g.grade_date,
        e.final_grade
    FROM enrollments e
    JOIN course_sections cs ON e.course_section_id = cs.id
    JOIN courses c ON cs.course_id = c.id
    JOIN instructors i ON cs.instructor_id = i.id
    LEFT JOIN grades g ON e.id = g.enrollment_id
    JOIN semesters sem ON e.semester_id = sem.id
    WHERE e.student_id = ? AND sem.is_current = 1
    ORDER BY c.course_code, g.grade_date DESC",
    [$studentId]
);

$gradesByCourse = [];
foreach ($currentGradesRaw as $row) {
    $key = $row['course_code'];
    if (!isset($gradesByCourse[$key])) {
        $gradesByCourse[$key] = [
            'course_name' => $row['course_name'],
            'credits' => $row['credits'],
            'section_name' => $row['section_name'],
            'instructor' => $row['instructor_name'],
            'final_grade' => $row['final_grade'],
            'assessments' => [],
        ];
    }
    if (!empty($row['assessment_name'])) {
        $gradesByCourse[$key]['assessments'][] = $row;
    }
}

$transcript = get_student_transcript($studentId);
$gpaSummary = get_student_gpa_summary($studentId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grades - University Management System</title>
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
                            <h1 class="display-6 mb-2">Academic Performance</h1>
                            <p class="text-muted mb-0">
                                Student ID: <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                                &middot; <?php echo htmlspecialchars($student['dept_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['level_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <?php if ($gpaSummary): ?>
                                <div class="bg-white rounded-4 p-4 d-inline-block text-start">
                                    <div class="h1 fw-bold text-primary mb-0"><?php echo number_format($gpaSummary['calculated_gpa'], 2); ?></div>
                                    <div class="text-muted small">Overall GPA</div>
                                    <div class="small">
                                        Credits: <strong><?php echo (int)$gpaSummary['total_credits']; ?></strong>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">GPA data will appear once grades are recorded.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="section-title mb-0">Current Semester</h5>
                        <span class="badge bg-primary"><?php echo count($gradesByCourse); ?> Courses</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($gradesByCourse)): ?>
                            <p class="text-muted mb-0">No grades have been posted for the current semester.</p>
                        <?php else: ?>
                            <?php foreach ($gradesByCourse as $code => $course): ?>
                                <div class="border rounded-4 p-4 mb-4">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                                        <div>
                                            <h6 class="mb-1">
                                                <span class="badge bg-primary me-2"><?php echo htmlspecialchars($code); ?></span>
                                                <?php echo htmlspecialchars($course['course_name']); ?>
                                            </h6>
                                            <p class="text-muted small mb-2">
                                                Instructor: <?php echo htmlspecialchars($course['instructor']); ?>
                                                <span class="mx-2">&middot;</span>
                                                Section <?php echo htmlspecialchars($course['section_name']); ?>
                                                <span class="mx-2">&middot;</span>
                                                <?php echo (int)$course['credits']; ?> Credits
                                            </p>
                                        </div>
                                        <div class="text-lg-end">
                                            <?php if ($course['final_grade']): ?>
                                                <span class="badge bg-success fs-6 px-3 py-2">Final: <?php echo htmlspecialchars($course['final_grade']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark px-3 py-2">In Progress</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($course['assessments'])): ?>
                                        <div class="mt-3">
                                            <div class="d-flex justify-content-between text-muted small mb-2">
                                                <span>Assessment</span>
                                                <span>Score</span>
                                            </div>
                                            <?php
                                            $weightedTotal = 0;
                                            $weightSum = 0;
                                            foreach ($course['assessments'] as $assessment):
                                                $percentage = ($assessment['max_score'] > 0)
                                                    ? ($assessment['score'] / $assessment['max_score']) * 100
                                                    : 0;
                                                $weightedTotal += $percentage * ($assessment['weight'] / 100);
                                                $weightSum += ($assessment['weight'] / 100);
                                            ?>
                                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($assessment['assessment_name']); ?></div>
                                                    <div class="text-muted small">
                                                        <?php echo ucfirst(htmlspecialchars($assessment['assessment_type'])); ?> &middot; Weight <?php echo (float)$assessment['weight']; ?>%
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <div class="fw-semibold"><?php echo (float)$assessment['score']; ?> / <?php echo (float)$assessment['max_score']; ?></div>
                                                    <div class="text-muted small"><?php echo number_format($percentage, 1); ?>%</div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>

                                            <?php if ($weightSum > 0): ?>
                                                <div class="d-flex justify-content-between align-items-center pt-3">
                                                    <div class="fw-semibold">Weighted Average</div>
                                                    <div class="text-end">
                                                        <div class="fw-bold"><?php echo number_format($weightedTotal, 1); ?>%</div>
                                                        <div class="text-muted small">Letter: <?php echo get_grade_letter($weightedTotal); ?></div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted small mb-0 mt-3">Assessments will appear once the instructor posts grades.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-shadow mb-4">
                    <div class="card-body">
                        <h5 class="section-title mb-3">Grade Legend</h5>
                        <div class="d-flex flex-column gap-2">
                            <span class="badge bg-success">A &nbsp; 90-100%</span>
                            <span class="badge bg-primary">B &nbsp; 80-89%</span>
                            <span class="badge bg-warning text-dark">C &nbsp; 70-79%</span>
                            <span class="badge bg-danger">D &nbsp; 60-69%</span>
                            <span class="badge bg-dark">F &nbsp; &lt; 60%</span>
                        </div>
                    </div>
                </div>

                <div class="card card-shadow">
                    <div class="card-body">
                        <h5 class="section-title mb-3"><i class="fas fa-file-alt text-primary me-2"></i>Transcript Snapshot</h5>
                        <?php if (empty($transcript)): ?>
                            <p class="text-muted mb-0">No completed courses on record yet.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach (array_slice($transcript, 0, 6) as $entry): ?>
                                    <li class="border-bottom py-2">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($entry['course_code']); ?> - <?php echo htmlspecialchars($entry['course_name']); ?></div>
                                        <div class="text-muted small d-flex justify-content-between">
                                            <span><?php echo htmlspecialchars($entry['semester_name']); ?> <?php echo htmlspecialchars($entry['year_name']); ?></span>
                                            <span><?php echo htmlspecialchars($entry['final_grade']); ?> (<?php echo (float)$entry['credits']; ?> cr)</span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="text-end mt-3">
                                <a href="transcript.php" class="btn btn-outline-primary btn-sm">View Full Transcript</a>
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
