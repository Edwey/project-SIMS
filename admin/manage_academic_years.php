<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/manage_academic_years.php');
    }

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $res = delete_academic_year($id);
        set_flash_message($res['success'] ? 'success' : 'error', $res['message']);
        redirect('/admin/manage_academic_years.php');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $yearName = trim($_POST['year_name'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $isCurrent = isset($_POST['is_current']) && $_POST['is_current'] === '1';

    if ($id > 0) {
        $res = update_academic_year($id, $yearName, $startDate, $endDate, $isCurrent);
    } else {
        $res = create_academic_year($yearName, $startDate, $endDate, $isCurrent);
    }

    set_flash_message($res['success'] ? 'success' : 'error', $res['message']);
    redirect('/admin/manage_academic_years.php');
}

$years = get_academic_years_admin();
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRecord = $editId > 0 ? db_query_one('SELECT * FROM academic_years WHERE id = ? LIMIT 1', [$editId]) : null;

$pageTitle = 'Manage Academic Years';
$bodyAttributes = $editRecord ? 'data-edit-modal="academicYearForm"' : '';

include __DIR__ . '/header.php';
?>
<div class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><?php echo $editRecord ? 'Edit Academic Year' : 'Create Academic Year'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <?php echo csrf_field(); ?>
                        <?php if ($editRecord): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editRecord['id']; ?>">
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Year Name</label>
                            <input type="text" name="year_name" class="form-control" placeholder="2025/2026" value="<?php echo htmlspecialchars($editRecord['year_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($editRecord['start_date'] ?? ''); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($editRecord['end_date'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12 form-check">
                            <input class="form-check-input" type="checkbox" id="is_current" name="is_current" value="1" <?php echo ($editRecord && (int)$editRecord['is_current'] === 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_current">Set as current academic year</label>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit"><?php echo $editRecord ? 'Update' : 'Create'; ?></button>
                            <?php if ($editRecord): ?>
                                <a class="btn btn-secondary" href="/project/admin/manage_academic_years.php">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">Academic Years</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Current</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($years as $y): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($y['year_name']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($y['start_date']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($y['end_date']); ?></td>
                                <td><?php echo ((int)$y['is_current'] === 1) ? '<span class="badge bg-success">Yes</span>' : ''; ?></td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_academic_years.php?id=<?php echo (int)$y['id']; ?>">Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this academic year?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$y['id']; ?>">
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
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
