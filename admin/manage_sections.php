<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Filters
$deptId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$yearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$instructorId = isset($_GET['instructor_id']) ? (int)$_GET['instructor_id'] : 0;
$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$q = trim($_GET['q'] ?? '');

$departments = get_all_departments();
$years = get_academic_years_admin();
$semesters = $yearId ? get_semesters_by_year($yearId) : [];

// Load instructors and courses (optionally filtered)
$instructors = db_query("SELECT i.id, CONCAT(i.first_name,' ',i.last_name) AS name FROM instructors i ORDER BY i.first_name, i.last_name");
$courses = $deptId > 0
    ? db_query('SELECT id, course_code, course_name FROM courses WHERE department_id = ? ORDER BY course_code', [$deptId])
    : db_query('SELECT id, course_code, course_name FROM courses ORDER BY course_code');

// Create/Update/Delete handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/manage_sections.php');
    }

    if (isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        // ensure no enrollments exist before delete
        $hasEnroll = db_query_one('SELECT id FROM enrollments WHERE course_section_id = ? LIMIT 1', [$delId]);
        if ($hasEnroll) {
            set_flash_message('error', 'Cannot delete: section has enrollments.');
        } else {
            db_execute('DELETE FROM course_sections WHERE id = ?', [$delId]);
            set_flash_message('success', 'Section deleted.');
        }
        redirect('/admin/manage_sections.php');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $courseIdPost = (int)($_POST['course_id'] ?? 0);
    $sectionName = trim($_POST['section_name'] ?? '');
    $instructorIdPost = (int)($_POST['instructor_id'] ?? 0);
    $schedule = trim($_POST['schedule'] ?? '');
    $room = trim($_POST['room'] ?? '');
    $capacity = (int)($_POST['capacity'] ?? 0);
    $semesterIdPost = (int)($_POST['semester_id'] ?? 0);
    $yearIdPost = (int)($_POST['academic_year_id'] ?? 0);

    if ($courseIdPost <= 0 || $sectionName === '' || $instructorIdPost <= 0 || $capacity <= 0 || $semesterIdPost <= 0 || $yearIdPost <= 0) {
        set_flash_message('error', 'Course, section name, instructor, capacity, academic year and semester are required.');
        redirect('/admin/manage_sections.php');
    }

    // validate foreign keys
    $courseExists = db_query_one('SELECT id FROM courses WHERE id = ? LIMIT 1', [$courseIdPost]);
    $instrExists = db_query_one('SELECT id FROM instructors WHERE id = ? LIMIT 1', [$instructorIdPost]);
    $semExists = db_query_one('SELECT id FROM semesters WHERE id = ? LIMIT 1', [$semesterIdPost]);
    $yearExists = db_query_one('SELECT id FROM academic_years WHERE id = ? LIMIT 1', [$yearIdPost]);
    if (!$courseExists || !$instrExists || !$semExists || !$yearExists) {
        set_flash_message('error', 'Invalid related record(s).');
        redirect('/admin/manage_sections.php');
    }

    if ($id > 0) {
        db_execute(
            'UPDATE course_sections SET course_id=?, section_name=?, instructor_id=?, schedule=?, room=?, capacity=?, semester_id=?, academic_year_id=? WHERE id=?',
            [$courseIdPost, $sectionName, $instructorIdPost, $schedule ?: null, $room ?: null, $capacity, $semesterIdPost, $yearIdPost, $id]
        );
        set_flash_message('success', 'Section updated.');
    } else {
        db_execute(
            'INSERT INTO course_sections (course_id, section_name, instructor_id, schedule, room, capacity, semester_id, academic_year_id, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())',
            [$courseIdPost, $sectionName, $instructorIdPost, $schedule ?: null, $room ?: null, $capacity, $semesterIdPost, $yearIdPost]
        );
        set_flash_message('success', 'Section created.');
    }

    redirect('/admin/manage_sections.php');
}

// Listing with filters (include enrolled count)
$params = [];
$where = [];
if ($deptId > 0) { $where[] = 'c.department_id = ?'; $params[] = $deptId; }
if ($yearId > 0) { $where[] = 'cs.academic_year_id = ?'; $params[] = $yearId; }
if ($semesterId > 0) { $where[] = 'cs.semester_id = ?'; $params[] = $semesterId; }
if ($instructorId > 0) { $where[] = 'cs.instructor_id = ?'; $params[] = $instructorId; }
if ($courseId > 0) { $where[] = 'cs.course_id = ?'; $params[] = $courseId; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(c.course_code LIKE ? OR c.course_name LIKE ? OR cs.section_name LIKE ? OR i.first_name LIKE ? OR i.last_name LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sections = db_query(
    "SELECT cs.id, cs.section_name, cs.schedule, cs.room, cs.capacity,
            c.course_code, c.course_name, d.dept_name,
            CONCAT(i.first_name,' ',i.last_name) AS instructor_name,
            sem.semester_name, ay.year_name,
            (SELECT COUNT(e.id) FROM enrollments e WHERE e.course_section_id = cs.id) AS enrolled
     FROM course_sections cs
     JOIN courses c ON cs.course_id = c.id
     JOIN departments d ON c.department_id = d.id
     JOIN instructors i ON cs.instructor_id = i.id
     JOIN semesters sem ON cs.semester_id = sem.id
     JOIN academic_years ay ON cs.academic_year_id = ay.id
     $whereSql
     ORDER BY ay.year_name DESC, sem.semester_name, c.course_code, cs.section_name
     LIMIT 500",
    $params
);

// Prefill edit
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRecord = $editId > 0 ? db_query_one('SELECT * FROM course_sections WHERE id = ? LIMIT 1', [$editId]) : null;
if ($editRecord) {
    $yearId = (int)$editRecord['academic_year_id'];
    $semesters = get_semesters_by_year($yearId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Course Sections - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body<?php if ($editId): ?> data-edit-modal="sectionEditModal"<?php endif; ?>>
<?php include __DIR__ . '/header.php'; ?>
<main class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Course Sections</h4>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#sectionAddModal">Add Section</button>
    </div>

    <form method="GET" class="row g-2 align-items-end mb-4">
        <div class="col-md-3">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>" <?php echo $deptId === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Course</label>
            <select name="course_id" class="form-select">
                <option value="0">All</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $courseId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Year</label>
            <select name="year_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int)$y['id']; ?>" <?php echo $yearId === (int)$y['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($y['year_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Semester</label>
            <select name="semester_id" class="form-select">
                <option value="0">All</option>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo $semesterId === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['semester_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Instructor</label>
            <select name="instructor_id" class="form-select">
                <option value="0">All</option>
                <?php foreach ($instructors as $i): ?>
                    <option value="<?php echo (int)$i['id']; ?>" <?php echo $instructorId === (int)$i['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($i['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 mt-2">
            <input type="text" name="q" class="form-control" placeholder="Search code/name/section/instructor" value="<?php echo htmlspecialchars($q); ?>">
        </div>
        <div class="col-12 d-flex gap-2 mt-2">
            <button class="btn btn-primary">Filter</button>
            <a href="/project/admin/manage_sections.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <div class="row g-4">
        <div class="col-lg-auto">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">Sections</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-full-width align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Section</th>
                                <th>Instructor</th>
                                <th>Schedule</th>
                                <th>Room</th>
                                <th>Capacity</th>
                                <th>Enrolled</th>
                                <th>Semester</th>
                                <th>Year</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sections as $s): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary "><?php echo htmlspecialchars($s['course_code']); ?></span>
                                    <div class="small text-muted"><?php echo htmlspecialchars($s['course_name']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($s['section_name']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($s['instructor_name']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($s['schedule'] ?? ''); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($s['room'] ?? ''); ?></td>
                                <td><?php echo (int)$s['capacity']; ?></td>
                                <td><?php echo (int)$s['enrolled']; ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($s['semester_name']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($s['year_name']); ?></td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_sections.php?id=<?php echo (int)$s['id']; ?>">Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this section?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$s['id']; ?>">
                                        <button class="btn btn-link text-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    </div>

    <!-- Add Modal: Section -->
    <div class="modal fade" id="sectionAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" class="row g-3 p-3">
                    <?php echo csrf_field(); ?>
                    <div class="col-12">
                        <label class="form-label">Course</label>
                        <select name="course_id" class="form-select" required>
                            <option value="">Select course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c["id"]; ?>"><?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Section Name</label>
                        <input type="text" name="section_name" class="form-control" placeholder="e.g., A, B, 01" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Instructor</label>
                        <select name="instructor_id" class="form-select" required>
                            <option value="">Select instructor</option>
                            <?php foreach ($instructors as $i): ?>
                                <option value="<?php echo (int)$i['id']; ?>"><?php echo htmlspecialchars($i['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Academic Year</label>
                        <select name="academic_year_id" class="form-select" required>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo ($yearId === (int)$y['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($y['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Semester</label>
                        <select name="semester_id" class="form-select" required>
                            <?php foreach ($semesters as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo ($semesterId === (int)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['semester_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Schedule</label>
                        <input type="text" name="schedule" class="form-control" placeholder="e.g., Mon/Wed 10:00-11:30">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Room</label>
                        <input type="text" name="room" class="form-control" placeholder="e.g., B-201">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Capacity</label>
                        <input type="number" min="1" name="capacity" class="form-control" required>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
                        <button class="btn btn-primary" type="submit">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal: Section -->
    <div class="modal fade" id="sectionEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" class="row g-3 p-3">
                    <?php echo csrf_field(); ?>
                    <?php if ($editRecord): ?>
                        <input type="hidden" name="id" value="<?php echo (int)$editRecord['id']; ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label">Course</label>
                        <select name="course_id" class="form-select" required>
                            <option value="">Select course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo ($editRecord && (int)$editRecord['course_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Section Name</label>
                        <input type="text" name="section_name" class="form-control" value="<?php echo htmlspecialchars($editRecord['section_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Instructor</label>
                        <select name="instructor_id" class="form-select" required>
                            <option value="">Select instructor</option>
                            <?php foreach ($instructors as $i): ?>
                                <option value="<?php echo (int)$i['id']; ?>" <?php echo ($editRecord && (int)$editRecord['instructor_id'] === (int)$i['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($i['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Academic Year</label>
                        <select name="academic_year_id" class="form-select" required>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo ($editRecord && (int)$editRecord['academic_year_id'] === (int)$y['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($y['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Semester</label>
                        <select name="semester_id" class="form-select" required>
                            <?php foreach ($semesters as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo ($editRecord && (int)$editRecord['semester_id'] === (int)$s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['semester_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Schedule</label>
                        <input type="text" name="schedule" class="form-control" value="<?php echo htmlspecialchars($editRecord['schedule'] ?? ''); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Room</label>
                        <input type="text" name="room" class="form-control" value="<?php echo htmlspecialchars($editRecord['room'] ?? ''); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Capacity</label>
                        <input type="number" min="1" name="capacity" class="form-control" value="<?php echo htmlspecialchars($editRecord['capacity'] ?? ''); ?>" required>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
                        <button class="btn btn-primary" type="submit">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</main>
<?php include __DIR__ . '/footer.php'; ?>
