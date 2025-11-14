<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('instructor');

$current_page = 'gradebook.php';
$instructorId = get_current_instructor_id();
if (!$instructorId) {
    set_flash_message('error', 'Your account is not linked to an instructor profile.');
    $sections = [];
} else {
$sections = get_instructor_sections($instructorId);
}

$sectionId = null;
if (isset($_GET['section_id'])) {
    $sectionId = (int)$_GET['section_id'];
}
if (isset($_POST['section_id'])) {
    $sectionId = (int)$_POST['section_id'];
}

$selectedSection = null;
if ($sectionId && $instructorId) {
    $selectedSection = get_section_details($sectionId, $instructorId);
    if (!$selectedSection) {
        set_flash_message('error', 'You do not have access to that section.');
        redirect('/instructor/gradebook.php');
    }
}

if ($selectedSection) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            set_flash_message('error', 'Invalid request token. Please try again.');
            redirect('/instructor/gradebook.php?section_id=' . (int)$sectionId);
        }
        // Mass grading handler
        if (isset($_POST['mass_grade'])) {
            $assessmentName = sanitize_input($_POST['assessment_name'] ?? '');
            $assessmentType = sanitize_input($_POST['assessment_type'] ?? 'assignment');
            $maxScore = (float)($_POST['max_score'] ?? 100);
            $weight = (float)($_POST['weight'] ?? 10);
            $gradeDate = !empty($_POST['grade_date']) ? $_POST['grade_date'] : date('Y-m-d');
            $ids = $_POST['enrollment_id'] ?? [];
            $scores = $_POST['score'] ?? [];
            if ($assessmentName === '' || $maxScore <= 0) {
                set_flash_message('error', 'Assessment name and maximum score are required for mass grading.');
            } else {
                $saved = 0;
                foreach ($ids as $idx => $eid) {
                    $eid = (int)$eid;
                    $sc = trim((string)($scores[$idx] ?? ''));
                    if ($eid && $sc !== '') {
                        $score = (float)$sc;
                        save_grade_record(null, $eid, $assessmentName, $assessmentType, $score, $maxScore, $weight, $gradeDate);
                        $saved++;
                    }
                }
                set_flash_message('success', "Mass grading saved for {$saved} student(s).");
            }
            redirect('/instructor/gradebook.php?section_id=' . $sectionId . '#mass-grade');
        }
        if (isset($_POST['save_grade'])) {
            $gradeId = !empty($_POST['grade_id']) ? (int)$_POST['grade_id'] : null;
            $enrollmentId = (int)$_POST['enrollment_id'];
            $assessmentName = sanitize_input($_POST['assessment_name'] ?? '');
            $assessmentType = sanitize_input($_POST['assessment_type'] ?? 'assignment');
            $score = (float)($_POST['score'] ?? 0);
            $maxScore = (float)($_POST['max_score'] ?? 100);
            $weight = (float)($_POST['weight'] ?? 10);
            $gradeDate = !empty($_POST['grade_date']) ? $_POST['grade_date'] : date('Y-m-d');

            if ($assessmentName === '' || $maxScore <= 0) {
                set_flash_message('error', 'Assessment name and maximum score are required.');
            } else {
                save_grade_record($gradeId, $enrollmentId, $assessmentName, $assessmentType, $score, $maxScore, $weight, $gradeDate);
                set_flash_message('success', 'Grade saved successfully.');
            }
            redirect('/instructor/gradebook.php?section_id=' . $sectionId . '#enr-' . $enrollmentId);
        }

        if (isset($_POST['delete_grade'])) {
            $gradeId = (int)$_POST['grade_id'];
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            delete_grade_record($gradeId);
            set_flash_message('success', 'Grade removed.');
            $anchor = $enrollmentId ? ('#enr-' . $enrollmentId) : '';
            redirect('/instructor/gradebook.php?section_id=' . $sectionId . $anchor);
        }

        if (isset($_POST['finalize_enrollment'])) {
            $enrollmentId = (int)$_POST['enrollment_id'];
            $result = calculate_enrollment_final_grade($enrollmentId);
            if ($result['success']) {
                set_flash_message('success', 'Enrollment finalized with grade ' . $result['final_grade'] . ' (' . $result['grade_points'] . ' points).');
            } else {
                set_flash_message('error', $result['message']);
            }
            redirect('/instructor/gradebook.php?section_id=' . $sectionId . '#enr-' . $enrollmentId);
        }

        if (isset($_POST['finalize_section'])) {
            $result = finalize_course_section_grades($sectionId);
            set_flash_message('success', "Section finalized: {$result['completed']} updated, {$result['skipped']} skipped.");
            if (!empty($result['messages'])) {
                foreach ($result['messages'] as $msg) {
                    set_flash_message('info', $msg);
                }
            }
            redirect('/instructor/gradebook.php?section_id=' . $sectionId . '#mass-grade');
        }
    }
}

$enrollments = $selectedSection ? get_section_enrollments($sectionId) : [];
$gradebookData = $selectedSection ? get_section_gradebook_data($sectionId) : [];
// Editing context (prefill form if user clicked Edit on a grade)
$editGradeId = $selectedSection && isset($_GET['edit_grade_id']) ? (int)$_GET['edit_grade_id'] : null;
$editEnrollmentId = $selectedSection && isset($_GET['enrollment_id']) ? (int)$_GET['enrollment_id'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gradebook - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <div class="card card-shadow mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="section_id" class="form-label">Select Course Section</label>
                        <select name="section_id" id="section_id" class="form-select" required>
                            <option value="">-- Choose a section --</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?php echo (int)$section['id']; ?>" <?php echo ($sectionId === (int)$section['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['course_name']); ?>
                                    (<?php echo htmlspecialchars($section['section_name']); ?> · <?php echo htmlspecialchars($section['semester_name'] . ' ' . $section['year_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Load</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedSection): ?>
            <div class="card card-shadow mb-4" id="mass-grade">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center sticky-card-header">
                    <div>
                        <h5 class="section-title mb-0"><?php echo htmlspecialchars($selectedSection['course_code'] . ' - ' . $selectedSection['course_name']); ?></h5>
                        <p class="text-muted small mb-0">
                            Section <?php echo htmlspecialchars($selectedSection['section_name']); ?> &middot;
                            <?php echo htmlspecialchars($selectedSection['semester_name'] . ' ' . $selectedSection['year_name']); ?> &middot;
                            Schedule: <?php echo htmlspecialchars($selectedSection['schedule']); ?>
                        </p>
                    </div>
                    <form method="POST" class="d-inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
                        <button type="submit" name="finalize_section" class="btn btn-success btn-sm">Finalize All Grades</button>
                    </form>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3 mb-3">
                        <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
                        <div class="col-md-4">
                            <label class="form-label">Search (name or ID)</label>
                            <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" class="form-control" placeholder="e.g., Jane or S1234">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Has Final</label>
                            <?php $hasFinal = $_GET['has_final'] ?? ''; ?>
                            <select name="has_final" class="form-select">
                                <option value="">All</option>
                                <option value="yes" <?php echo $hasFinal==='yes'?'selected':''; ?>>Yes</option>
                                <option value="no" <?php echo $hasFinal==='no'?'selected':''; ?>>No</option>
                            </select>
                        </div>
                        <div class="col-md-2 align-self-end">
                            <button class="btn btn-outline-primary w-100" type="submit">Apply</button>
                        </div>
                        <div class="col-md-2 align-self-end">
                            <a class="btn btn-outline-secondary w-100" href="/project/instructor/gradebook.php?section_id=<?php echo (int)$sectionId; ?>#mass-grade">Clear</a>
                        </div>
                    </form>

                    <?php
                        // Apply simple filters to $enrollments
                        $q = trim($_GET['q'] ?? '');
                        $hasFinal = $_GET['has_final'] ?? '';
                        $filteredEnrollments = $enrollments;
                        if ($q !== '') {
                            $filteredEnrollments = array_filter($filteredEnrollments, function($e) use ($q){
                                $needle = mb_strtolower($q);
                                $name = mb_strtolower(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
                                $sid = mb_strtolower((string)($e['student_id'] ?? ''));
                                return strpos($name, $needle) !== false || strpos($sid, $needle) !== false;
                            });
                        }
                        if ($hasFinal === 'yes') {
                            $filteredEnrollments = array_filter($filteredEnrollments, function($e){ return !empty($e['final_grade']); });
                        } elseif ($hasFinal === 'no') {
                            $filteredEnrollments = array_filter($filteredEnrollments, function($e){ return empty($e['final_grade']); });
                        }
                    ?>

                    <form method="POST" class="border rounded-4 p-3 mb-4">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Assessment Name</label>
                                <input type="text" name="assessment_name" class="form-control" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Type</label>
                                <select name="assessment_type" class="form-select">
                                    <option value="assignment">Assignment</option>
                                    <option value="quiz">Quiz</option>
                                    <option value="midterm">Midterm</option>
                                    <option value="final">Final</option>
                                    <option value="project">Project</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Max Score</label>
                                <input type="number" step="0.01" name="max_score" class="form-control" value="100" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Weight %</label>
                                <input type="number" step="0.01" name="weight" class="form-control" value="10" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date</label>
                                <input type="date" name="grade_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="table-responsive auto mt-3">
                            <table class="table align-middle table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student</th>
                                        <th class="text-end" style="width:160px;">Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredEnrollments as $enr): ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars(($enr['first_name'] ?? '') . ' ' . ($enr['last_name'] ?? '')); ?>
                                                <span class="text-muted small">(<?php echo htmlspecialchars($enr['student_id'] ?? ''); ?>)</span>
                                            </td>
                                            <td class="text-end">
                                                <input type="hidden" name="enrollment_id[]" value="<?php echo (int)$enr['enrollment_id']; ?>">
                                                <input type="number" step="0.01" name="score[]" class="form-control form-control-sm d-inline-block" style="width:140px;">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-end">
                            <button type="submit" name="mass_grade" class="btn btn-primary btn-sm">Save Mass Grades</button>
                        </div>
                    </form>

                    <?php if (empty($enrollments)): ?>
                        <p class="text-muted mb-0">No students enrolled in this section.</p>
                    <?php else: ?>
                        <?php foreach ($filteredEnrollments as $enrollment): ?>
                            <?php $grades = $gradebookData[$enrollment['enrollment_id']] ?? []; ?>
                            <?php $weighted = calculate_enrollment_weighted_percentage($grades); ?>
                            <div class="border rounded-4 p-4 mb-4 anchor-offset" id="enr-<?php echo (int)$enrollment['enrollment_id']; ?>">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-start">
                                    <div>
                                        <h6 class="mb-1">
                                            <?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?>
                                            <span class="text-muted">(<?php echo htmlspecialchars($enrollment['student_id']); ?>)</span>
                                        </h6>
                                        <p class="text-muted small mb-2">
                                            Final Grade: <strong><?php echo $enrollment['final_grade'] ? htmlspecialchars($enrollment['final_grade']) : 'Pending'; ?></strong>
                                            &middot; Grade Points: <strong><?php echo $enrollment['grade_points'] !== null ? number_format((float)$enrollment['grade_points'], 2) : '-'; ?></strong>
                                            &middot; Weighted Score: <strong><?php echo number_format($weighted, 2); ?>%</strong>
                                        </p>
                                    </div>
                                    <div class="text-lg-end">
                                        <form method="POST" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
                                            <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['enrollment_id']; ?>">
                                            <button type="submit" name="finalize_enrollment" class="btn btn-outline-success btn-sm">Finalize Enrollment</button>
                                        </form>
                                    </div>
                                </div>

                                <div class="table-responsive auto">
                                    <table class="table align-middle table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Assessment</th>
                                                <th>Type</th>
                                                <th>Score</th>
                                                <th>Weight</th>
                                                <th>Date</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($grades)): ?>
                                            <tr>
                                                <td colspan="6" class="text-muted text-center">No assessments recorded yet.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($grades as $grade): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($grade['assessment_name']); ?></td>
                                                    <td><?php echo htmlspecialchars(ucfirst($grade['assessment_type'])); ?></td>
                                                    <td><?php echo (float)$grade['score']; ?> / <?php echo (float)$grade['max_score']; ?></td>
                                                    <td><?php echo (float)$grade['weight']; ?>%</td>
                                                    <td><?php echo format_date($grade['grade_date']); ?></td>
                                                    <td class="text-end">
                                                        <a href="/project/instructor/gradebook.php?section_id=<?php echo (int)$sectionId; ?>&enrollment_id=<?php echo (int)$enrollment['enrollment_id']; ?>&edit_grade_id=<?php echo (int)$grade['grade_id']; ?>#enr-<?php echo (int)$enrollment['enrollment_id']; ?>" class="btn btn-link btn-sm">Edit</a>
                                                        <form method="POST" class="d-inline">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
                                                            <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['enrollment_id']; ?>">
                                                            <input type="hidden" name="grade_id" value="<?php echo (int)$grade['grade_id']; ?>">
                                                            <button type="submit" name="delete_grade" class="btn btn-link text-danger btn-sm" onclick="return confirm('Delete this grade record?');" aria-label="Delete grade">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php
                                    $editing = $editEnrollmentId === (int)$enrollment['enrollment_id'] && $editGradeId;
                                    $editGrade = null;
                                    if ($editing) {
                                        foreach ($grades as $g) {
                                            if ((int)$g['grade_id'] === $editGradeId) { $editGrade = $g; break; }
                                        }
                                        if (!$editGrade) { $editing = false; }
                                    }
                                ?>
                                <form method="POST" class="row g-3 mt-3">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
                                    <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['enrollment_id']; ?>">
                                    <input type="hidden" name="grade_id" value="<?php echo $editing ? (int)$editGrade['grade_id'] : ''; ?>">
                                    <div class="col-12">
                                        <?php if ($editing): ?>
                                            <span class="badge bg-warning text-dark">Editing grade #<?php echo (int)$editGrade['grade_id']; ?></span>
                                            <a class="ms-2 small" href="/project/instructor/gradebook.php?section_id=<?php echo (int)$sectionId; ?>#enr-<?php echo (int)$enrollment['enrollment_id']; ?>">Cancel edit</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Assessment Name</label>
                                        <input type="text" name="assessment_name" class="form-control" value="<?php echo $editing ? htmlspecialchars($editGrade['assessment_name']) : ''; ?>" <?php echo $editing ? 'autofocus' : ''; ?> required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Type</label>
                                        <?php $atype = $editing ? strtolower($editGrade['assessment_type']) : 'assignment'; ?>
                                        <select name="assessment_type" class="form-select">
                                            <option value="assignment" <?php echo $atype==='assignment'?'selected':''; ?>>Assignment</option>
                                            <option value="quiz" <?php echo $atype==='quiz'?'selected':''; ?>>Quiz</option>
                                            <option value="midterm" <?php echo $atype==='midterm'?'selected':''; ?>>Midterm</option>
                                            <option value="final" <?php echo $atype==='final'?'selected':''; ?>>Final</option>
                                            <option value="project" <?php echo $atype==='project'?'selected':''; ?>>Project</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Score</label>
                                        <input type="number" step="0.01" name="score" class="form-control" value="<?php echo $editing ? (float)$editGrade['score'] : ''; ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Max Score</label>
                                        <input type="number" step="0.01" name="max_score" class="form-control" value="<?php echo $editing ? (float)$editGrade['max_score'] : 100; ?>" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Weight %</label>
                                        <input type="number" step="0.01" name="weight" class="form-control" value="<?php echo $editing ? (float)$editGrade['weight'] : 10; ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date</label>
                                        <input type="date" name="grade_date" class="form-control" value="<?php echo $editing ? htmlspecialchars($editGrade['grade_date']) : date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-md-3 align-self-end">
                                        <button type="submit" name="save_grade" class="btn btn-primary"><?php echo $editing ? 'Update Grade' : 'Add Grade'; ?></button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">Select a course section to manage grades.</div>
        <?php endif; ?>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/gradebook.js"></script>
</body>
</html>
