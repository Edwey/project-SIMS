<?php
require_once __DIR__ . '/../includes/functions.php';
require_auth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/catalog_programs.php');
    }

    $action = $_POST['action'] ?? 'create';
    $programIdPost = (int)($_POST['program_id'] ?? 0);
    $code = $_POST['program_code'] ?? '';
    $name = $_POST['program_name'] ?? '';
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $totalCredits = (int)($_POST['total_credits'] ?? 0);

    if ($action === 'delete') {
        $result = delete_program($programIdPost);
        set_flash_message($result['success'] ? 'success' : 'error', $result['message']);
        redirect('/admin/catalog_programs.php');
    }

    if ($action === 'update') {
        $result = update_program($programIdPost, $code, $name, $departmentId, $totalCredits);
    } else {
        $result = create_program($code, $name, $departmentId, $totalCredits);
    }

    set_flash_message($result['success'] ? 'success' : 'error', $result['message']);
    redirect('/admin/catalog_programs.php');
}

$departments = get_all_departments();
$programs = get_programs();
$editProgram = null;
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($editId > 0) {
    $editProgram = get_program_by_id($editId);
}
$pageTitle = 'Program Guide';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Program Guide</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>
  <main class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="col-lg-5">
      <a class="btn btn-primary" href="/project/admin/catalog_requirements.php">Program Requirements</a>
    </div>

    <div class="row g-4">
      <div class="col-lg-5">
        <div class="card card-shadow">
          <div class="card-header bg-white border-0 py-3"><h5 class="mb-0"><?php echo $editProgram ? 'Edit Program' : 'Create Program'; ?></h5></div>
          <div class="card-body">
            <form method="POST" class="row g-3">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="<?php echo $editProgram ? 'update' : 'create'; ?>">
              <?php if ($editProgram): ?>
                <input type="hidden" name="program_id" value="<?php echo (int)$editProgram['id']; ?>">
              <?php endif; ?>
              <div class="col-md-6">
                <label class="form-label">Program Code</label>
                <input type="text" name="program_code" class="form-control" placeholder="e.g., BSC-CS" value="<?php echo htmlspecialchars($editProgram['program_code'] ?? ''); ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Total Credits</label>
                <input type="number" name="total_credits" class="form-control" value="<?php echo (int)($editProgram['total_credits'] ?? 120); ?>" min="1" required>
              </div>
              <div class="col-12">
                <label class="form-label">Program Name</label>
                <input type="text" name="program_name" class="form-control" placeholder="e.g., B.Sc. Computer Science" value="<?php echo htmlspecialchars($editProgram['program_name'] ?? ''); ?>" required>
              </div>
              <div class="col-12">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select" required>
                  <option value="">-- Choose Department --</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>" <?php echo ($editProgram && (int)$editProgram['department_id'] === (int)$d['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-primary"><?php echo $editProgram ? 'Update Program' : 'Create Program'; ?></button>
                  <?php if ($editProgram): ?>
                    <a href="/project/admin/catalog_programs.php" class="btn btn-outline-secondary">Cancel</a>
                  <?php endif; ?>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>



      <div class="col-lg-7">
        <div class="card card-shadow">
          <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Program Guide</h5>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                  <tr>
                    <th>#</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Credits</th>
                    <th>Department</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($programs)):
                    ?><tr><td colspan="5" class="text-center text-muted">No programs yet</td></tr><?php
                  else: foreach ($programs as $p): ?>
                    <tr>
                      <td><?php echo (int)$p['id']; ?></td>
                      <td><?php echo htmlspecialchars($p['program_code']); ?></td>
                      <td><?php echo htmlspecialchars($p['program_name']); ?></td>
                      <td><?php echo (int)$p['total_credits']; ?></td>
                      <td><?php echo htmlspecialchars($p['dept_name'] ?? ''); ?></td>
                      <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm" href="/project/admin/catalog_programs.php?id=<?php echo (int)$p['id']; ?>">Edit</a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this program? This requires removing its requirements/applications/students first.');">
                          <?php echo csrf_field(); ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="program_id" value="<?php echo (int)$p['id']; ?>">
                          <button type="submit" class="btn btn-link text-danger btn-sm">Delete</button>
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
  </main>
  
<?php include __DIR__ . '/footer.php'; ?>