<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'degree_audit.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$studentId = (int)$student['student_internal_id'];
$programId = (int)($student['program_id'] ?? 0);

$program = null;
$requirements = [];
$progress = [
    'total_courses' => 0,
    'required_courses' => 0,
    'completed_courses' => 0,
    'required_completed' => 0,
    'elective_completed' => 0,
    'remaining_courses' => [],
    'remaining_required' => [],
    'remaining_electives' => [],
];
$requirementRows = [];
$completedCourseIds = [];
$completedLookup = [];
$inProgressLookup = [];
$prereqDetails = [];
$overallPercent = 0;
$requiredPercent = 0;
$totalCredits = 0;
$completedCredits = 0;
$requiredCredits = 0;
$remainingRequired = [];
$remainingElectives = [];

if ($programId > 0) {
    $program = get_program_by_id($programId);

    if ($program) {
        $requirements = get_program_requirements($programId);
        $progress = get_student_program_progress($studentId, $programId);
        $remainingRequired = $progress['remaining_required'] ?? [];
        $remainingElectives = $progress['remaining_electives'] ?? [];

        $completedCourseIds = get_student_course_completions($studentId);
        $completedLookup = array_flip($completedCourseIds);

        $prereqMap = get_program_prerequisite_map($programId);
        $allPrereqIds = [];
        foreach ($prereqMap as $courseId => $ids) {
            foreach ($ids as $pid) {
                $allPrereqIds[(int)$pid] = true;
            }
        }
        $prereqIdList = array_keys($allPrereqIds);
        if (!empty($prereqIdList)) {
            $placeholders = implode(',', array_fill(0, count($prereqIdList), '?'));
            $prereqRows = db_query(
                "SELECT id, course_code, course_name FROM courses WHERE id IN ($placeholders)",
                $prereqIdList
            );
            foreach ($prereqRows as $row) {
                $prereqDetails[(int)$row['id']] = $row;
            }
        }

        $currentEnrollments = db_query(
            'SELECT DISTINCT cs.course_id
             FROM enrollments e
             JOIN course_sections cs ON cs.id = e.course_section_id
             WHERE e.student_id = ? AND (e.final_grade IS NULL OR e.final_grade = ?)',
            [$studentId, '']
        );
        foreach ($currentEnrollments as $row) {
            $cid = (int)($row['course_id'] ?? 0);
            if ($cid > 0) {
                $inProgressLookup[$cid] = true;
            }
        }

        foreach ($requirements as $req) {
            $courseId = (int)$req['course_id'];
            $isRequired = (int)$req['required'] === 1;
            $isCompleted = isset($completedLookup[$courseId]);
            $prereqIds = $prereqMap[$courseId] ?? [];
            $missingPrereqIds = array_values(array_filter($prereqIds, function ($pid) use ($completedLookup) {
                return !isset($completedLookup[$pid]);
            }));

            $prereqBadges = [];
            foreach ($prereqIds as $pid) {
                $details = $prereqDetails[$pid] ?? null;
                $code = $details['course_code'] ?? ('Course ' . $pid);
                $name = $details['course_name'] ?? '';
                $isMissing = in_array($pid, $missingPrereqIds, true);
                $prereqBadges[] = [
                    'code' => $code,
                    'name' => $name,
                    'missing' => $isMissing,
                ];
            }

            $credits = (int)$req['credits'];
            $totalCredits += $credits;
            if ($isRequired) {
                $requiredCredits += $credits;
            }
            if ($isCompleted) {
                $completedCredits += $credits;
            }

            $requirementRows[] = [
                'data' => $req,
                'is_required' => $isRequired,
                'is_completed' => $isCompleted,
                'status' => $isCompleted ? 'Completed' : (isset($inProgressLookup[$courseId]) ? 'In Progress' : 'Not Started'),
                'status_class' => $isCompleted ? 'bg-success' : (isset($inProgressLookup[$courseId]) ? 'bg-warning text-dark' : 'bg-light text-muted'),
                'prereq_badges' => $prereqBadges,
                'missing_prereq_ids' => $missingPrereqIds,
            ];
        }

        $overallPercent = $progress['total_courses'] > 0
            ? (int)round(($progress['completed_courses'] / max($progress['total_courses'], 1)) * 100)
            : 0;
        $requiredPercent = $progress['required_courses'] > 0
            ? (int)round(($progress['required_completed'] / max($progress['required_courses'], 1)) * 100)
            : 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Degree Audit - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <?php if (!$program): ?>
            <div class="card card-shadow">
                <div class="card-body">
                    <h1 class="h4 mb-3">Degree Audit</h1>
                    <p class="text-muted mb-0">A program assignment was not found for your account. Please contact the registrar or system administrator to link your student record to a program.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card card-shadow mb-4">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                        <div>
                            <h1 class="h4 mb-2">Degree Audit</h1>
                            <div class="text-muted">Program</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($program['program_name']); ?> (<?php echo htmlspecialchars($program['program_code']); ?>)</div>
                            <div class="text-muted small">Total Program Credits: <?php echo (int)$program['total_credits']; ?></div>
                        </div>
                        <div class="bg-light rounded-4 px-4 py-3 text-center">
                            <div class="text-muted small mb-1">Overall Completion</div>
                            <div class="display-6 fw-bold text-primary mb-1"><?php echo $overallPercent; ?>%</div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $overallPercent; ?>%;" aria-valuenow="<?php echo $overallPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card card-shadow h-100">
                        <div class="card-body">
                            <div class="text-muted small">Courses Completed</div>
                            <div class="fs-3 fw-bold"><?php echo (int)$progress['completed_courses']; ?> / <?php echo (int)$progress['total_courses']; ?></div>
                            <div class="text-muted small">Across program requirements</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-shadow h-100">
                        <div class="card-body">
                            <div class="text-muted small">Required Courses Complete</div>
                            <div class="fs-3 fw-bold"><?php echo (int)$progress['required_completed']; ?> / <?php echo (int)$progress['required_courses']; ?></div>
                            <div class="progress mt-2" style="height: 6px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $requiredPercent; ?>%;" aria-valuenow="<?php echo $requiredPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-shadow h-100">
                        <div class="card-body">
                            <div class="text-muted small">Credits Completed</div>
                            <div class="fs-3 fw-bold"><?php echo (int)$completedCredits; ?> / <?php echo (int)$totalCredits; ?></div>
                            <div class="text-muted small">Within mapped requirements</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-shadow h-100">
                        <div class="card-body">
                            <div class="text-muted small">Electives Completed</div>
                            <div class="fs-3 fw-bold"><?php echo (int)$progress['elective_completed']; ?></div>
                            <div class="text-muted small">Program electives finished</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card card-shadow h-100">
                        <div class="card-header bg-white border-0 py-3">
                            <h5 class="section-title mb-0">Remaining Required Courses</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($remainingRequired)): ?>
                                <p class="text-muted mb-0">All mandatory program courses have been completed.</p>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($remainingRequired as $req): ?>
                                        <li class="mb-3">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($req['course_code']); ?> &middot; <?php echo htmlspecialchars($req['course_name']); ?></div>
                                            <div class="text-muted small">Credits: <?php echo (int)$req['credits']; ?><?php if (!empty($req['term_number'])): ?> &middot; Suggested Term <?php echo (int)$req['term_number']; ?><?php endif; ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card card-shadow h-100">
                        <div class="card-header bg-white border-0 py-3">
                            <h5 class="section-title mb-0">Remaining Electives</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($remainingElectives)): ?>
                                <p class="text-muted mb-0">No pending electives. Great job staying on track!</p>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($remainingElectives as $req): ?>
                                        <li class="mb-3">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($req['course_code']); ?> &middot; <?php echo htmlspecialchars($req['course_name']); ?></div>
                                            <div class="text-muted small">Credits: <?php echo (int)$req['credits']; ?><?php if (!empty($req['term_number'])): ?> &middot; Suggested Term <?php echo (int)$req['term_number']; ?><?php endif; ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="section-title mb-0">All Program Requirements</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($requirementRows)): ?>
                        <p class="text-muted mb-0">No requirements have been configured for this program yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Course</th>
                                        <th scope="col" class="text-center">Credits</th>
                                        <th scope="col" class="text-center">Type</th>
                                        <th scope="col" class="text-center">Term</th>
                                        <th scope="col" class="text-center">Status</th>
                                        <th scope="col">Prerequisites</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requirementRows as $row): ?>
                                        <?php $req = $row['data']; ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($req['course_code']); ?> &middot; <?php echo htmlspecialchars($req['course_name']); ?></div>
                                                <div class="text-muted small">Department: <?php echo htmlspecialchars($req['department_id']); ?></div>
                                            </td>
                                            <td class="text-center"><?php echo (int)$req['credits']; ?></td>
                                            <td class="text-center">
                                                <span class="badge <?php echo $row['is_required'] ? 'bg-primary' : 'bg-secondary'; ?>"><?php echo $row['is_required'] ? 'Required' : 'Elective'; ?></span>
                                            </td>
                                            <td class="text-center"><?php echo !empty($req['term_number']) ? 'Term ' . (int)$req['term_number'] : '—'; ?></td>
                                            <td class="text-center">
                                                <span class="badge <?php echo $row['status_class']; ?>"><?php echo $row['status']; ?></span>
                                            </td>
                                            <td>
                                                <?php if (empty($row['prereq_badges'])): ?>
                                                    <span class="text-muted small">None</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php foreach ($row['prereq_badges'] as $badge): ?>
                                                            <span class="badge <?php echo $badge['missing'] ? 'bg-danger' : 'bg-secondary'; ?>" title="<?php echo htmlspecialchars($badge['name']); ?>">
                                                                <?php echo htmlspecialchars($badge['code']); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
