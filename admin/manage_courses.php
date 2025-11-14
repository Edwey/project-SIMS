<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$departments = get_all_departments();
$levels = get_all_levels();

// Handle create/update/delete submissions (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/admin/manage_courses.php');
    }
    // Delete
    if (isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        // Guard: cannot delete if referenced by sections or enrollments
        $ref = db_query_one('SELECT 
                (SELECT COUNT(*) FROM course_sections WHERE course_id = ?) AS sec_count
            ', [$delId]);
        if ($ref && (int)$ref['sec_count'] > 0) {
            set_flash_message('error', 'Cannot delete: course has sections. Remove sections first.');
        } else {
            db_execute('DELETE FROM courses WHERE id = ?', [$delId]);
            set_flash_message('success', 'Course deleted.');
        }
        redirect('/admin/manage_courses.php');
    }

    // Create/Update
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $course_code = sanitize_input($_POST['course_code'] ?? '');
    $course_name = sanitize_input($_POST['course_name'] ?? '');
    $department_id = (int)($_POST['department_id'] ?? 0);
    $level_id = (int)($_POST['level_id'] ?? 0);
    $credits = (int)($_POST['credits'] ?? 0);
    $prerequisites = sanitize_input($_POST['prerequisites'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    if ($id > 0) {
        $res = update_course($id, $course_code, $course_name, $department_id, $level_id, $credits, $prerequisites ?: null, $description ?: null);
    } else {
        $res = create_course($course_code, $course_name, $department_id, $level_id, $credits, $prerequisites ?: null, $description ?: null);
    }

    if (!empty($res['success'])) {
        set_flash_message('success', $res['message'] ?? 'Saved.');
    } else {
        set_flash_message('error', $res['message'] ?? 'Unable to save course.');
    }
    redirect('/admin/manage_courses.php');
}



// Prefill edit
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRecord = $editId > 0 ? get_course_by_id($editId) : null;
$courses = get_all_courses();

$pageTitle = 'Manage Courses';
$bodyAttributes = $editId ? 'data-edit-modal="courseEditModal"' : '';

include __DIR__ . '/header.php';
?>
<div class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div></div>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#courseAddModal">Add Course</button>
    </div>

    <div class="card card-shadow">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Courses</h5>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-full-width align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Level</th>
                        <th>Credits</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($courses as $c): ?>
                    <tr>
                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($c['course_code']); ?></span></td>
                        <td><?php echo htmlspecialchars($c['course_name']); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($c['dept_name']); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($c['level_name']); ?></td>
                        <td><?php echo (int)$c['credits']; ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_courses.php?id=<?php echo (int)$c['id']; ?>">Edit</a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this course?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo (int)$c['id']; ?>">
                                <button class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Modal: Course -->
    <div class="modal fade" id="courseAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Course Code</label>
                            <input type="text" name="course_code" class="form-control" value="" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Course Name</label>
                            <input type="text" name="course_name" class="form-control" value="" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select" required>
                                <option value="">Select department</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['dept_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Level</label>
                            <select name="level_id" class="form-select" required>
                                <option value="">Select level</option>
                                <?php foreach ($levels as $l): ?>
                                    <option value="<?php echo (int)$l['id']; ?>"><?php echo htmlspecialchars($l['level_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Credits</label>
                            <input type="number" min="1" name="credits" class="form-control" value="" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Prerequisites (optional)</label>
                            <textarea name="prerequisites" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description (optional)</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button class="btn btn-primary" type="submit">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal: Course -->
    <div class="modal fade" id="courseEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <?php if ($editRecord): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editRecord['id']; ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Course Code</label>
                            <input type="text" name="course_code" class="form-control" value="<?php echo htmlspecialchars($editRecord['course_code'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Course Name</label>
                            <input type="text" name="course_name" class="form-control" value="<?php echo htmlspecialchars($editRecord['course_name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select" required>
                                <option value="">Select department</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo (int)$d['id']; ?>" <?php echo ($editRecord && (int)$editRecord['department_id'] === (int)$d['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($d['dept_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Level</label>
                            <select name="level_id" class="form-select" required>
                                <option value="">Select level</option>
                                <?php foreach ($levels as $l): ?>
                                    <option value="<?php echo (int)$l['id']; ?>" <?php echo ($editRecord && (int)$editRecord['level_id'] === (int)$l['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($l['level_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Credits</label>
                            <input type="number" min="1" name="credits" class="form-control" value="<?php echo htmlspecialchars($editRecord['credits'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Prerequisites (optional)</label>
                            <textarea name="prerequisites" class="form-control" rows="2"><?php echo htmlspecialchars($editRecord['prerequisites'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description (optional)</label>
                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($editRecord['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button class="btn btn-primary" type="submit">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
