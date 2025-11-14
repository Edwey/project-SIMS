<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'manage_departments.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/admin/manage_departments.php');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $deptCode = strtoupper(trim($_POST['dept_code'] ?? ''));
    $deptName = trim($_POST['dept_name'] ?? '');
    $deptHead = trim($_POST['dept_head'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $result = $id > 0
        ? update_department($id, $deptCode, $deptName, $deptHead ?: null, $description ?: null)
        : create_department($deptCode, $deptName, $deptHead ?: null, $description ?: null);

    if ($result['success']) {
        set_flash_message('success', $result['message'] ?? 'Department saved.');
    } else {
        set_flash_message('error', $result['message'] ?? 'Unable to save department.');
    }

    redirect('/admin/manage_departments.php');
}

if (isset($_GET['delete'])) {
    $deptId = (int)$_GET['delete'];
    $res = delete_department($deptId);
    set_flash_message($res['success'] ? 'success' : 'error', $res['message'] ?? 'Delete attempted.');
    redirect('/admin/manage_departments.php');
}

$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRecord = $editId > 0 ? get_department_by_id($editId) : null;
$departments = get_departments_admin();

$pageTitle = 'Manage Departments';
$bodyAttributes = $editRecord ? 'data-edit-modal="departmentEditModal"' : '';

include __DIR__ . '/header.php';
?>
<div class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div></div>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#departmentAddModal">Add Department</button>
    </div>

    <div class="card card-shadow">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="mb-0">Departments</h5>
        </div>
        <div class="card-body table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Head</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $d): ?>
                    <tr>
                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($d['dept_code']); ?></span></td>
                        <td><?php echo htmlspecialchars($d['dept_name']); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($d['dept_head'] ?? ''); ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_departments.php?id=<?php echo (int)$d['id']; ?>">Edit</a>
                            <form method="GET" class="d-inline" onsubmit="return confirm('Delete this department?');">
                                <input type="hidden" name="delete" value="<?php echo (int)$d['id']; ?>">
                                <button class="btn btn-link text-danger btn-sm" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Department Modal -->
    <div class="modal fade" id="departmentAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Code</label>
                            <input type="text" name="dept_code" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="dept_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Head (optional)</label>
                            <input type="text" name="dept_head" class="form-control">
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

    <!-- Edit Department Modal -->
    <div class="modal fade" id="departmentEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <?php if ($editRecord): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editRecord['id']; ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Code</label>
                            <input type="text" name="dept_code" class="form-control" value="<?php echo htmlspecialchars($editRecord['dept_code'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="dept_name" class="form-control" value="<?php echo htmlspecialchars($editRecord['dept_name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Head (optional)</label>
                            <input type="text" name="dept_head" class="form-control" value="<?php echo htmlspecialchars($editRecord['dept_head'] ?? ''); ?>">
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
