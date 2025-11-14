<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Filters
$role = isset($_GET['role']) && in_array($_GET['role'], ['admin','instructor','student'], true) ? $_GET['role'] : '';
$status = isset($_GET['status']) && in_array($_GET['status'], ['active','inactive'], true) ? $_GET['status'] : '';
$q = trim($_GET['q'] ?? '');

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/manage_users.php');
    }

    // Delete user (safe)
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $res = admin_delete_user($id);
        if ($res['success']) {
            set_flash_message('success', $res['message']);
        } else {
            set_flash_message('error', $res['message'] ?? 'Unable to delete user.');
        }
        redirect('/admin/manage_users.php');
    }

    // Force delete user (cascade linked data)
    if (isset($_POST['force_delete_id'])) {
        $id = (int)$_POST['force_delete_id'];
        $repId = isset($_POST['replacement_instructor_id']) && $_POST['replacement_instructor_id'] !== ''
            ? (int)$_POST['replacement_instructor_id'] : null;
        $res = admin_force_delete_user($id, $repId);
        if ($res['success']) {
            set_flash_message('success', $res['message']);
        } else {
            set_flash_message('error', $res['message'] ?? 'Unable to force delete user.');
        }
        redirect('/admin/manage_users.php');
    }

    // Activate/Deactivate
    if (isset($_POST['toggle_active'])) {
        $id = (int)$_POST['user_id'];
        $user = db_query_one('SELECT is_active FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user) {
            $new = (int)!((bool)$user['is_active']);
            db_execute('UPDATE users SET is_active = ? WHERE id = ?', [$new, $id]);
            set_flash_message('success', $new ? 'User activated.' : 'User deactivated.');
        }
        redirect('/admin/manage_users.php');
    }

    // Reset password (generate temp)
    if (isset($_POST['reset_password'])) {
        $id = (int)$_POST['user_id'];
        $temporary = bin2hex(random_bytes(4)) . 'A1!';
        $hash = hash_password($temporary);
        db_execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
        set_flash_message('success', 'Temporary password: ' . $temporary . ' (prompt user to change)');
        redirect('/admin/manage_users.php');
    }

    // Create / Update user
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rolePost = in_array($_POST['role'] ?? '', ['admin','instructor','student'], true) ? $_POST['role'] : '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $rolePost === '') {
        set_flash_message('error', 'Username, valid email, and role are required.');
        redirect('/admin/manage_users.php');
    }

    // Uniqueness
    $dupeUser = db_query_one('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1', [$username, $id]);
    $dupeEmail = db_query_one('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $id]);
    if ($dupeUser) { set_flash_message('error', 'Username already taken.'); redirect('/admin/manage_users.php'); }
    if ($dupeEmail) { set_flash_message('error', 'Email already in use.'); redirect('/admin/manage_users.php'); }

    if ($id > 0) {
        db_execute('UPDATE users SET username=?, email=?, role=?, is_active=? WHERE id=?', [$username,$email,$rolePost,$isActive,$id]);
        set_flash_message('success', 'User updated.');
    } else {
        if ($password === '') { set_flash_message('error', 'Password is required for new users.'); redirect('/admin/manage_users.php'); }
        $hash = hash_password($password);
        db_execute('INSERT INTO users (username,email,password_hash,role,is_active,created_at) VALUES (?,?,?,?,?, NOW())', [$username,$email,$hash,$rolePost,$isActive]);
        set_flash_message('success', 'User created.');
    }

    redirect('/admin/manage_users.php');
}

// Listing
$params = [];
$where = [];
if ($role !== '') {
    $where[] = 'role = ?';
    $params[] = $role;
}
if ($status !== '') {
    $where[] = 'is_active = ?';
    $params[] = $status === 'active' ? 1 : 0;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(username LIKE ? OR email LIKE ?)';
    array_push($params, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$users = db_query("SELECT u.id, u.username, u.email, u.role, u.is_active, u.last_login, i.id AS instructor_id FROM users u LEFT JOIN instructors i ON i.user_id = u.id $whereSql ORDER BY u.created_at DESC LIMIT 500", $params);

// For Option A: list of replacement instructors
$instructorOptions = db_query('SELECT i.id, u.username FROM instructors i JOIN users u ON u.id = i.user_id ORDER BY u.username');

// Prefill
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit = $editId > 0 ? db_query_one('SELECT id, username, email, role, is_active FROM users WHERE id = ? LIMIT 1', [$editId]) : null;

$pageTitle = 'Users';
$bodyAttributes = $editId ? 'data-edit-modal="userEditModal"' : '';
include __DIR__ . '/header.php';
render_flash_messages();
?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Users</h4>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#userAddModal">Add User</button>
  </div>
  <form method="GET" class="row g-2 align-items-end mb-4">
    <div class="col-md-3">
      <label class="form-label">Role</label>
      <select name="role" class="form-select">
        <option value="">All</option>
        <?php foreach (['admin','instructor','student'] as $r): ?>
          <option value="<?php echo $r; ?>" <?php echo $role===$r?'selected':''; ?>><?php echo ucfirst($r); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="">All</option>
        <option value="active" <?php echo $status==='active'?'selected':''; ?>>Active</option>
        <option value="inactive" <?php echo $status==='inactive'?'selected':''; ?>>Inactive</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Search</label>
      <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Username or Email">
    </div>
    <div class="col-12 d-flex gap-2 mt-2">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-secondary" href="/project/admin/manage_users.php">Reset</a>
    </div>
  </form>

  <div class="card card-shadow">
        <div class="card-header bg-white border-0 py-3">
          <h5 class="mb-0">Users</h5>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last Login</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td><?php echo htmlspecialchars($u['username']); ?></td>
                  <td class="small text-muted"><?php echo htmlspecialchars($u['email']); ?></td>
                  <td><span class="badge bg-dark"><?php echo htmlspecialchars(ucfirst($u['role'])); ?></span></td>
                  <td><?php echo ((int)$u['is_active']===1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                  <td class="small text-muted"><?php echo $u['last_login'] ?? '-'; ?></td>
                  <td class="text-end">
                    <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_users.php?id=<?php echo (int)$u['id']; ?>">Edit</a>
                    <form method="POST" class="d-inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                      <button class="btn btn-link btn-sm" name="toggle_active" value="1"><?php echo ((int)$u['is_active']===1)?'Deactivate':'Activate'; ?></button>
                    </form>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Reset password for this user?');">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                      <button class="btn btn-link text-warning btn-sm" name="reset_password" value="1">Reset Password</button>
                    </form>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user? This will fail if there are linked profiles.');">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="delete_id" value="<?php echo (int)$u['id']; ?>">
                      <button class="btn btn-link text-danger btn-sm">Delete</button>
                    </form>
                    <form method="POST" class="d-inline" onsubmit="return confirm('FORCE DELETE: this will remove linked student data and notifications. For instructors, select a replacement to reassign sections/attendance. Continue?');">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="force_delete_id" value="<?php echo (int)$u['id']; ?>">
                      <?php if ($u['role'] === 'instructor'): ?>
                        <select name="replacement_instructor_id" class="form-select form-select-sm d-inline w-auto me-2" aria-label="Replacement instructor" required>
                          <option value="">Select replacement instructor</option>
                          <?php foreach ($instructorOptions as $opt): if ((int)($u['instructor_id'] ?? 0) === (int)$opt['id']) continue; ?>
                            <option value="<?php echo (int)$opt['id']; ?>"><?php echo htmlspecialchars($opt['username']); ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php endif; ?>
                      <button class="btn btn-danger btn-sm">Force Delete</button>
                    </form>
                    <?php if ($u['role'] === 'student'): ?>
                      <a class="btn btn-outline-primary btn-sm" href="/project/admin/manage_students.php?link_user=<?php echo (int)$u['id']; ?>">Link/Create Student</a>
                    <?php elseif ($u['role'] === 'instructor'): ?>
                      <a class="btn btn-outline-primary btn-sm" href="/project/admin/manage_instructors.php?link_user=<?php echo (int)$u['id']; ?>">Link/Create Instructor</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

  <!-- Add Modal: User -->
  <div class="modal fade" id="userAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" class="row g-3 p-3">
          <?php echo csrf_field(); ?>
          <div class="col-12">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label">Role</label>
            <select name="role" class="form-select" required>
              <?php foreach (['admin','instructor','student'] as $r): ?>
                <option value="<?php echo $r; ?>"><?php echo ucfirst($r); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <div class="col-12 form-check">
            <input class="form-check-input" type="checkbox" id="is_active_add" name="is_active" value="1" checked>
            <label class="form-check-label" for="is_active_add">Active</label>
          </div>
          <div class="col-12 d-flex gap-2">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
            <button class="btn btn-primary" type="submit">Create</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit Modal: User -->
  <div class="modal fade" id="userEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" class="row g-3 p-3">
          <?php echo csrf_field(); ?>
          <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
          <div class="col-12">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($edit['username'] ?? ''); ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit['email'] ?? ''); ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">Role</label>
            <select name="role" class="form-select" required>
              <?php foreach (['admin','instructor','student'] as $r): ?>
                <option value="<?php echo $r; ?>" <?php echo ($edit && $edit['role']===$r)?'selected':''; ?>><?php echo ucfirst($r); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 form-check">
            <input class="form-check-input" type="checkbox" id="is_active_edit" name="is_active" value="1" <?php echo ($edit && (int)$edit['is_active']===1) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="is_active_edit">Active</label>
          </div>
          <div class="col-12 d-flex gap-2">
            <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
            <button class="btn btn-primary" type="submit">Update</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<?php include __DIR__ . '/footer.php'; ?>
