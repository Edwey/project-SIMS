<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/manage_levels.php');
    }

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $res = delete_level($id);
        set_flash_message($res['success'] ? 'success' : 'error', $res['message']);
        redirect('/admin/manage_levels.php');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $levelName = trim($_POST['level_name'] ?? '');
    $levelOrder = (int)($_POST['level_order'] ?? 0);

    if ($id > 0) {
        $res = update_level($id, $levelName, $levelOrder);
    } else {
        $res = create_level($levelName, $levelOrder);
    }

    set_flash_message($res['success'] ? 'success' : 'error', $res['message']);
    redirect('/admin/manage_levels.php');
}

$levels = db_query('SELECT id, level_name, level_order FROM levels ORDER BY level_order');
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRecord = $editId > 0 ? get_level_by_id($editId) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Levels - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><?php echo $editRecord ? 'Edit Level' : 'Create Level'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <?php echo csrf_field(); ?>
                        <?php if ($editRecord): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editRecord['id']; ?>">
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Level Name</label>
                            <input type="text" name="level_name" class="form-control" value="<?php echo htmlspecialchars($editRecord['level_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Order</label>
                            <input type="number" name="level_order" class="form-control" value="<?php echo htmlspecialchars($editRecord['level_order'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit"><?php echo $editRecord ? 'Update' : 'Create'; ?></button>
                            <?php if ($editRecord): ?>
                                <a class="btn btn-secondary" href="/project/admin/manage_levels.php">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">Levels</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Name</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($levels as $l): ?>
                            <tr>
                                <td><?php echo (int)$l['level_order']; ?></td>
                                <td><?php echo htmlspecialchars($l['level_name']); ?></td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_levels.php?id=<?php echo (int)$l['id']; ?>">Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this level?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$l['id']; ?>">
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
</main>
<?php include __DIR__ . '/footer.php'; ?>
