<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$programs = get_programs();
$academicYears = db_query('SELECT id, year_name FROM academic_years ORDER BY start_date DESC');

$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$yearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;

$semesters = [];
if ($yearId > 0) {
    $semesters = db_query('SELECT id, semester_name FROM semesters WHERE academic_year_id = ? ORDER BY start_date', [$yearId]);
} else {
    $semesters = db_query('SELECT id, semester_name FROM semesters ORDER BY start_date DESC');
}

$export = isset($_GET['export']) ? $_GET['export'] : '';

$filters = [];
$params = [];
if ($programId > 0) {
    $filters[] = 's.program_id = ?';
    $params[] = $programId;
}
if ($yearId > 0) {
    $filters[] = 'e.academic_year_id = ?';
    $params[] = $yearId;
}
if ($semesterId > 0) {
    $filters[] = 'e.semester_id = ?';
    $params[] = $semesterId;
}
$whereClause = $filters ? (' AND ' . implode(' AND ', $filters)) : '';

// GPA statistics (overall, per student)
$gpaStats = db_query_one(
    'SELECT
        AVG(g.calculated_gpa) AS avg_gpa,
        SUM(CASE WHEN g.calculated_gpa >= 3.5 THEN 1 ELSE 0 END) AS bucket_35,
        SUM(CASE WHEN g.calculated_gpa BETWEEN 3.0 AND 3.49 THEN 1 ELSE 0 END) AS bucket_30,
        SUM(CASE WHEN g.calculated_gpa BETWEEN 2.5 AND 2.99 THEN 1 ELSE 0 END) AS bucket_25,
        SUM(CASE WHEN g.calculated_gpa BETWEEN 2.0 AND 2.49 THEN 1 ELSE 0 END) AS bucket_20,
        SUM(CASE WHEN g.calculated_gpa < 2.0 THEN 1 ELSE 0 END) AS bucket_low,
        COUNT(*) AS student_count
     FROM student_gpa_view g
     JOIN students s ON s.id = g.id
     WHERE 1=1' . ($programId > 0 ? ' AND s.program_id = ?' : ''),
    ($programId > 0 ? [$programId] : [])
);

if (!$gpaStats) {
    $gpaStats = [
        'avg_gpa' => null,
        'bucket_35' => 0,
        'bucket_30' => 0,
        'bucket_25' => 0,
        'bucket_20' => 0,
        'bucket_low' => 0,
        'student_count' => 0,
    ];
}

$passGrades = [
    'A', 'A+', 'A-',
    'B', 'B+', 'B-',
    'C', 'C+', 'C-',
    'D', 'Pass', 'P', 'Credit', 'Distinction', 'Excellent'
];
$passPlaceholders = implode(',', array_fill(0, count($passGrades), '?'));

$gradeParams = array_merge($params, $passGrades, $passGrades);
$gradeStats = db_query_one(
    'SELECT
        COUNT(*) AS total_attempts,
        SUM(CASE WHEN e.final_grade IS NULL THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN e.final_grade IS NOT NULL AND e.final_grade IN (' . $passPlaceholders . ') THEN 1 ELSE 0 END) AS passed,
        SUM(CASE WHEN e.final_grade IS NOT NULL AND e.final_grade NOT IN (' . $passPlaceholders . ') THEN 1 ELSE 0 END) AS failed,
        AVG(CASE WHEN e.grade_points IS NOT NULL THEN e.grade_points END) AS avg_grade_points
     FROM enrollments e
     JOIN students s ON s.id = e.student_id
     JOIN course_sections cs ON cs.id = e.course_section_id
     WHERE 1=1' . $whereClause,
    $gradeParams
);

if (!$gradeStats) {
    $gradeStats = [
        'total_attempts' => 0,
        'in_progress' => 0,
        'passed' => 0,
        'failed' => 0,
        'avg_grade_points' => null,
    ];
}

$enrolledStudentsRow = db_query_one(
    'SELECT COUNT(DISTINCT e.student_id) AS enrolled_count
     FROM enrollments e
     JOIN students s ON s.id = e.student_id
     WHERE 1=1' . $whereClause,
    $params
);
$enrolledStudents = (int)($enrolledStudentsRow['enrolled_count'] ?? 0);

$totalStudentBaseRow = db_query_one(
    'SELECT COUNT(*) AS total_students FROM students s' . ($programId > 0 ? ' WHERE s.program_id = ?' : ''),
    ($programId > 0 ? [$programId] : [])
);
$totalStudentBase = (int)($totalStudentBaseRow['total_students'] ?? 0);
$retentionPercent = $totalStudentBase > 0 ? round(($enrolledStudents / $totalStudentBase) * 100, 1) : null;

$courseMetrics = db_query(
    'SELECT
        c.id,
        c.course_code,
        c.course_name,
        COUNT(e.id) AS enrollment_count,
        SUM(CASE WHEN e.final_grade IS NOT NULL AND e.final_grade IN (' . $passPlaceholders . ') THEN 1 ELSE 0 END) AS passed_count,
        SUM(CASE WHEN e.final_grade IS NOT NULL AND e.final_grade NOT IN (' . $passPlaceholders . ') THEN 1 ELSE 0 END) AS failed_count,
        AVG(e.grade_points) AS avg_grade_points
     FROM enrollments e
     JOIN course_sections cs ON cs.id = e.course_section_id
     JOIN courses c ON c.id = cs.course_id
     JOIN students s ON s.id = e.student_id
     WHERE 1=1' . $whereClause . '
     GROUP BY c.id, c.course_code, c.course_name
     ORDER BY c.course_code',
    $gradeParams
);

if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="academic_analytics.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Course Code', 'Course Name', 'Enrollments', 'Passed', 'Failed', 'Average Grade Points']);
    foreach ($courseMetrics as $row) {
        fputcsv($out, [
            $row['course_code'],
            $row['course_name'],
            (int)$row['enrollment_count'],
            (int)$row['passed_count'],
            (int)$row['failed_count'],
            $row['avg_grade_points'] !== null ? number_format((float)$row['avg_grade_points'], 2) : 'N/A'
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Academic Analytics';
include __DIR__ . '/header.php';
render_flash_messages();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h4 class="mb-1">Academic Performance Analytics</h4>
        <p class="text-muted mb-0">Track GPA distribution, completion outcomes, and course-level performance.</p>
    </div>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-primary">
        <i class="fas fa-download me-1"></i>Export CSV
    </a>
</div>

<form method="GET" class="card card-shadow mb-4">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Program</label>
            <select name="program_id" class="form-select">
                <option value="0">All Programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?php echo (int)$program['id']; ?>" <?php echo $programId === (int)$program['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($program['program_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Academic Year</label>
            <select name="academic_year_id" class="form-select">
                <option value="0">All Years</option>
                <?php foreach ($academicYears as $year): ?>
                    <option value="<?php echo (int)$year['id']; ?>" <?php echo $yearId === (int)$year['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($year['year_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Semester</label>
            <select name="semester_id" class="form-select">
                <option value="0">All Semesters</option>
                <?php foreach ($semesters as $semester): ?>
                    <option value="<?php echo (int)$semester['id']; ?>" <?php echo $semesterId === (int)$semester['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($semester['semester_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 d-grid">
            <button type="submit" class="btn btn-primary">Apply</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Average GPA</div>
                <div class="fs-3 fw-bold">
                    <?php echo $gpaStats['avg_gpa'] !== null ? number_format((float)$gpaStats['avg_gpa'], 2) : 'N/A'; ?>
                </div>
                <div class="text-muted small">Across <?php echo (int)$gpaStats['student_count']; ?> students</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Pass Rate</div>
                <div class="fs-3 fw-bold">
                    <?php
                    $completed = (int)$gradeStats['passed'] + (int)$gradeStats['failed'];
                    $passRate = $completed > 0 ? round(((int)$gradeStats['passed'] / $completed) * 100, 1) : null;
                    echo $passRate !== null ? $passRate . '%' : 'N/A';
                    ?>
                </div>
                <div class="text-muted small">Based on graded enrollments</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Retention</div>
                <div class="fs-3 fw-bold">
                    <?php echo $retentionPercent !== null ? $retentionPercent . '%' : 'N/A'; ?>
                </div>
                <div class="text-muted small">Enrolled vs total active students</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">In Progress</div>
                <div class="fs-3 fw-bold"><?php echo (int)$gradeStats['in_progress']; ?></div>
                <div class="text-muted small">Enrollments awaiting final grade</div>
            </div>
        </div>
    </div>
</div>

<div class="card card-shadow mb-4">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="section-title mb-0">GPA Distribution</h5>
    </div>
    <div class="card-body">
        <?php if ((int)$gpaStats['student_count'] === 0): ?>
            <p class="text-muted mb-0">No GPA data available for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="text-muted">
                            <th>3.5 - 4.0</th>
                            <th>3.0 - 3.49</th>
                            <th>2.5 - 2.99</th>
                            <th>2.0 - 2.49</th>
                            <th>Below 2.0</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo (int)$gpaStats['bucket_35']; ?></td>
                            <td><?php echo (int)$gpaStats['bucket_30']; ?></td>
                            <td><?php echo (int)$gpaStats['bucket_25']; ?></td>
                            <td><?php echo (int)$gpaStats['bucket_20']; ?></td>
                            <td><?php echo (int)$gpaStats['bucket_low']; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card card-shadow">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
        <h5 class="section-title mb-0">Course Performance</h5>
    </div>
    <div class="card-body">
        <?php if (empty($courseMetrics)): ?>
            <p class="text-muted mb-0">No enrollment data available for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Course</th>
                            <th class="text-center">Enrollments</th>
                            <th class="text-center">Passed</th>
                            <th class="text-center">Failed</th>
                            <th class="text-center">Avg Grade Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courseMetrics as $course): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($course['course_name']); ?></div>
                                </td>
                                <td class="text-center"><?php echo (int)$course['enrollment_count']; ?></td>
                                <td class="text-center text-success"><?php echo (int)$course['passed_count']; ?></td>
                                <td class="text-center text-danger"><?php echo (int)$course['failed_count']; ?></td>
                                <td class="text-center"><?php echo $course['avg_grade_points'] !== null ? number_format((float)$course['avg_grade_points'], 2) : 'N/A'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
