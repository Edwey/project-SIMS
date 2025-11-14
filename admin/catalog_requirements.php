<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$programs = get_programs();
$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;

if ($programId <= 0 && !empty($programs)) {
    $programId = (int)$programs[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/catalog_requirements.php?program_id=' . $programId);
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_program_course') {
        $programIdPost = (int)($_POST['program_id'] ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        $termNumber = trim($_POST['term_number'] ?? '');
        $requiredFlag = isset($_POST['required']) ? 1 : 0;

        if ($programIdPost <= 0 || $courseId <= 0) {
            set_flash_message('error', 'Program and course are required.');
            redirect('/admin/catalog_requirements.php?program_id=' . $programId);
        }

        $termValue = null;
        if ($termNumber !== '') {
            if (!ctype_digit($termNumber) || (int)$termNumber <= 0) {
                set_flash_message('error', 'Term number must be a positive integer.');
                redirect('/admin/catalog_requirements.php?program_id=' . $programIdPost);
            }
            $termValue = (int)$termNumber;
        }

        try {
            db_execute(
                'INSERT INTO program_courses (program_id, course_id, term_number, required, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$programIdPost, $courseId, $termValue, $requiredFlag]
            );
            set_flash_message('success', 'Course added to program requirements.');
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), 'UNIQUE') !== false) {
                set_flash_message('error', 'That course is already part of the program requirements.');
            } else {
                set_flash_message('error', 'Failed to add course requirement: ' . $e->getMessage());
            }
        }

        redirect('/admin/catalog_requirements.php?program_id=' . $programIdPost);
    }

    if ($action === 'delete_program_course') {
        $programCourseId = (int)($_POST['program_course_id'] ?? 0);
        $programIdPost = (int)($_POST['program_id'] ?? 0);

        if ($programCourseId <= 0) {
            set_flash_message('error', 'Invalid requirement selected.');
            redirect('/admin/catalog_requirements.php?program_id=' . $programId);
        }

        db_execute('DELETE FROM program_courses WHERE id = ?', [$programCourseId]);
        set_flash_message('success', 'Program requirement removed.');
        redirect('/admin/catalog_requirements.php?program_id=' . max($programIdPost, $programId));
    }

    if ($action === 'add_course_prereq') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $prereqCourseId = (int)($_POST['prereq_course_id'] ?? 0);
        $programIdPost = (int)($_POST['program_id'] ?? 0);

        if ($courseId <= 0 || $prereqCourseId <= 0) {
            set_flash_message('error', 'Course and prerequisite are required.');
            redirect('/admin/catalog_requirements.php?program_id=' . $programId);
        }

        if ($courseId === $prereqCourseId) {
            set_flash_message('error', 'A course cannot be its own prerequisite.');
            redirect('/admin/catalog_requirements.php?program_id=' . $programIdPost);
        }

        try {
            db_execute(
                'INSERT INTO course_prerequisites (course_id, prereq_course_id, created_at) VALUES (?, ?, NOW())',
                [$courseId, $prereqCourseId]
            );
            set_flash_message('success', 'Prerequisite added.');
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), 'UNIQUE') !== false) {
                set_flash_message('error', 'That prerequisite already exists.');
            } else {
                set_flash_message('error', 'Failed to add prerequisite: ' . $e->getMessage());
            }
        }

        redirect('/admin/catalog_requirements.php?program_id=' . max($programIdPost, $programId));
    }

    if ($action === 'delete_course_prereq') {
        $prereqId = (int)($_POST['prereq_id'] ?? 0);
        $programIdPost = (int)($_POST['program_id'] ?? 0);

        if ($prereqId <= 0) {
            set_flash_message('error', 'Invalid prerequisite selected.');
            redirect('/admin/catalog_requirements.php?program_id=' . $programId);
        }

        db_execute('DELETE FROM course_prerequisites WHERE id = ?', [$prereqId]);
        set_flash_message('success', 'Prerequisite removed.');
        redirect('/admin/catalog_requirements.php?program_id=' . max($programIdPost, $programId));
    }
}

$selectedProgram = null;
foreach ($programs as $program) {
    if ((int)$program['id'] === $programId) {
        $selectedProgram = $program;
        break;
    }
}

$programCourseRows = [];
$programCourseIds = [];
if ($programId > 0) {
    $programCourseRows = db_query(
        'SELECT pc.id, pc.term_number, pc.required, pc.course_id, c.course_code, c.course_name, c.credits
         FROM program_courses pc
         JOIN courses c ON pc.course_id = c.id
         WHERE pc.program_id = ?
         ORDER BY COALESCE(pc.term_number, 999), c.course_code',
        [$programId]
    );
    foreach ($programCourseRows as $row) {
        $programCourseIds[(int)$row['course_id']] = true;
    }
}

$allCourses = db_query('SELECT id, course_code, course_name, credits FROM courses ORDER BY course_code');

$programCourseOptions = array_filter($programCourseRows, function ($row) {
    return true; // already data prepared
});

$prerequisiteRows = [];
if ($programId > 0) {
    $prerequisiteRows = db_query(
        'SELECT cp.id, cp.course_id, cp.prereq_course_id,
                c.course_code AS course_code, c.course_name AS course_name,
                prereq.course_code AS prereq_code, prereq.course_name AS prereq_name
         FROM course_prerequisites cp
         JOIN courses c ON cp.course_id = c.id
         JOIN courses prereq ON cp.prereq_course_id = prereq.id
         WHERE cp.course_id IN (SELECT course_id FROM program_courses WHERE program_id = ?)
         ORDER BY c.course_code, prereq.course_code',
        [$programId]
    );
}

$pageTitle = 'Program Requirements';
include __DIR__ . '/header.php';
render_flash_messages();
?>

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <h4 class="mb-0">Program Requirements</h4>
            <?php if ($selectedProgram): ?>
                <div class="text-muted small">Managing: <?php echo htmlspecialchars($selectedProgram['program_name']); ?></div>
            <?php endif; ?>
        </div>
        <form method="GET" class="d-flex gap-2">
            <select name="program_id" class="form-select">
                <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo (int)$prog['id']; ?>" <?php echo $programId === (int)$prog['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($prog['program_code'] . ' — ' . $prog['program_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Load Program</button>
        </form>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">Add Required Course</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($programs)): ?>
                        <p class="text-muted mb-0">No programs available. Create a program first.</p>
                    <?php else: ?>
                        <form method="POST" class="row g-3">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_program_course">
                            <input type="hidden" name="program_id" value="<?php echo (int)$programId; ?>">
                            <div class="col-12">
                                <label class="form-label">Course</label>
                                <select name="course_id" class="form-select" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach ($allCourses as $course): ?>
                                        <?php if (isset($programCourseIds[(int)$course['id']])) { continue; } ?>
                                        <option value="<?php echo (int)$course['id']; ?>">
                                            <?php echo htmlspecialchars($course['course_code'] . ' — ' . $course['course_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Term Number (optional)</label>
                                <input type="number" min="1" class="form-control" name="term_number" placeholder="e.g. 1">
                            </div>
                            <div class="col-md-6 d-flex align-items-center">
                                <div class="form-check mt-3">
                                    <input class="form-check-input" type="checkbox" id="requiredFlag" name="required" value="1" checked>
                                    <label class="form-check-label" for="requiredFlag">Required Course</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Add Course</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card card-shadow mt-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Program Courses</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Credits</th>
                                <th>Term</th>
                                <th>Required</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($programCourseRows)): ?>
                                <tr><td colspan="5" class="text-center text-muted">No courses assigned yet.</td></tr>
                            <?php else: foreach ($programCourseRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['course_code']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($row['course_name']); ?></div>
                                    </td>
                                    <td><?php echo (int)$row['credits']; ?></td>
                                    <td><?php echo $row['term_number'] ? (int)$row['term_number'] : '-'; ?></td>
                                    <td><?php echo ((int)$row['required'] === 1) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this course from the program?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_program_course">
                                            <input type="hidden" name="program_course_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="program_id" value="<?php echo (int)$programId; ?>">
                                            <button class="btn btn-link text-danger btn-sm">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">Configure Prerequisites</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($programCourseRows)): ?>
                        <p class="text-muted mb-0">Add courses to the program before configuring prerequisites.</p>
                    <?php else: ?>
                        <form method="POST" class="row g-3">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_course_prereq">
                            <input type="hidden" name="program_id" value="<?php echo (int)$programId; ?>">
                            <div class="col-12">
                                <label class="form-label">Course</label>
                                <select name="course_id" class="form-select" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach ($programCourseRows as $row): ?>
                                        <option value="<?php echo (int)$row['course_id']; ?>"><?php echo htmlspecialchars($row['course_code'] . ' — ' . $row['course_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Prerequisite Course</label>
                                <select name="prereq_course_id" class="form-select" required>
                                    <option value="">-- Select Prerequisite --</option>
                                    <?php foreach ($allCourses as $course): ?>
                                        <option value="<?php echo (int)$course['id']; ?>"><?php echo htmlspecialchars($course['course_code'] . ' — ' . $course['course_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Add Prerequisite</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card card-shadow mt-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Course Prerequisites</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Prerequisite</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($prerequisiteRows)): ?>
                                <tr><td colspan="3" class="text-center text-muted">No prerequisites defined.</td></tr>
                            <?php else: foreach ($prerequisiteRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['course_code']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($row['course_name']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['prereq_code']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($row['prereq_name']); ?></div>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this prerequisite?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_course_prereq">
                                            <input type="hidden" name="prereq_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="program_id" value="<?php echo (int)$programId; ?>">
                                            <button class="btn btn-link text-danger btn-sm">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/footer.php'; ?>
