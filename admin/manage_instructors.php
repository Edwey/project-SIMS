<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'manage_instructors.php';

$departments = get_all_departments();
// Linkable users (role=instructor and not linked yet)
$linkableUsers = db_query("SELECT u.id, u.username, u.email FROM users u LEFT JOIN instructors i ON i.user_id = u.id WHERE u.role='instructor' AND (i.user_id IS NULL) ORDER BY u.username LIMIT 500");

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/manage_instructors.php');
    }

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $hasSections = db_query_one('SELECT id FROM course_sections WHERE instructor_id = ? LIMIT 1', [$id]);
        if ($hasSections) {
            set_flash_message('error', 'Cannot delete: instructor is assigned to sections.');
        } else {
            db_execute('DELETE FROM instructors WHERE id = ?', [$id]);
            set_flash_message('success', 'Instructor deleted.');
        }
        redirect('/admin/manage_instructors.php');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $deptId = (int)($_POST['department_id'] ?? 0);
    $hireDate = $_POST['hire_date'] ?? date('Y-m-d');
    $linkUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : null;

    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $deptId <= 0) {
        set_flash_message('error', 'First/last name, valid email, and department are required.');
        redirect('/admin/manage_instructors.php');
    }

    if ($id > 0) {
        db_execute('UPDATE instructors SET first_name=?, last_name=?, email=?, phone=?, department_id=?, hire_date=? WHERE id=?',
            [$first,$last,$email,$phone?:null,$deptId,$hireDate,$id]
        );
        set_flash_message('success', 'Instructor updated.');
    } else {
        db_execute('INSERT INTO instructors (first_name,last_name,email,phone,department_id,user_id,hire_date,created_at) VALUES (?,?,?,?,?,?,?, NOW())',
            [$first,$last,$email,$phone?:null,$deptId,$linkUserId,$hireDate]
        );
        set_flash_message('success', 'Instructor created.');
    }

    redirect('/admin/manage_instructors.php');
}

// Filters/listing
$q = trim($_GET['q'] ?? '');
$deptFilter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$params = [];$where=[];
if ($q !== '') { $like='%'.$q.'%'; $where[]='(i.first_name LIKE ? OR i.last_name LIKE ? OR i.email LIKE ?)'; array_push($params,$like,$like,$like); }
if ($deptFilter>0) { $where[]='i.department_id = ?'; $params[]=$deptFilter; }
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
$instructors = db_query(
    "SELECT i.*, d.dept_name, COUNT(sa.id) AS advisee_count
     FROM instructors i 
     JOIN departments d ON i.department_id=d.id
     LEFT JOIN student_advisors sa ON sa.instructor_id = i.id AND sa.is_active = 1
     $whereSql
     GROUP BY i.id, i.first_name, i.last_name, i.email, i.phone, i.department_id, i.user_id, i.hire_date, i.created_at, d.dept_name
     ORDER BY i.first_name, i.last_name LIMIT 500",
    $params
);

// Get unassigned students count
$unassignedCount = db_query_one('SELECT COUNT(DISTINCT s.id) AS cnt FROM students s LEFT JOIN student_advisors sa ON sa.student_id = s.id AND sa.is_active = 1 WHERE sa.instructor_id IS NULL');
$unassignedStudents = db_query('SELECT s.id, s.student_id, s.first_name, s.last_name, s.email, d.dept_name, l.level_name FROM students s JOIN departments d ON s.department_id=d.id JOIN levels l ON s.current_level_id=l.id LEFT JOIN student_advisors sa ON sa.student_id = s.id AND sa.is_active = 1 WHERE sa.instructor_id IS NULL ORDER BY s.first_name, s.last_name LIMIT 500');

// Prefill
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit = $editId>0 ? db_query_one('SELECT * FROM instructors WHERE id = ? LIMIT 1', [$editId]) : null;

$pageTitle = 'Manage Instructors';
$bodyAttributes = '';
if ($editId) {
    $bodyAttributes = 'data-edit-modal="instructorEditModal"';
} elseif (isset($_GET['show_unassigned'])) {
    $bodyAttributes = 'data-edit-modal="unassignedStudentsModal"';
}

include __DIR__ . '/header.php';
?>
<div class="container py-4">
  <?php render_flash_messages(); ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name or email">
      </div>
      <div class="col-md-3">
        <label class="form-label">Department</label>
        <select name="department_id" class="form-select">
          <option value="0">All</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?php echo (int)$d['id']; ?>" <?php echo $deptFilter===(int)$d['id']?'selected':''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 mt-2 mt-md-0">
        <button class="btn btn-primary">Filter</button>
      </div>
    </form>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#instructorAddModal">Add Instructor</button>
  </div>

  <div class="card card-shadow">
    <div class="card-header bg-white border-0 py-3"><h5 class="mb-0">Instructors</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Name</th><th>Dept</th><th>Contact</th><th>Advisees</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($instructors as $i): ?>
          <tr>
            <td class="fw-semibold"><?php echo htmlspecialchars($i['first_name'].' '.$i['last_name']); ?></td>
            <td class="small text-muted"><?php echo htmlspecialchars($i['dept_name']); ?></td>
            <td class="small text-muted"><?php echo htmlspecialchars($i['email']); ?><?php if (!empty($i['phone'])): ?> · <?php echo htmlspecialchars($i['phone']); ?><?php endif; ?></td>
            <td><span class="badge bg-info"><?php echo (int)$i['advisee_count']; ?></span></td>
            <td class="text-end">
              <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_instructors.php?id=<?php echo (int)$i['id']; ?>">Edit</a>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this instructor?');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="delete_id" value="<?php echo (int)$i['id']; ?>">
                <button class="btn btn-outline-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php include __DIR__ . '/footer.php'; ?>

  <!-- Add Modal: Instructor -->
  <div class="modal fade" id="instructorAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add Instructor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <?php echo csrf_field(); ?>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">User (link)</label>
                <select name="user_id" class="form-select">
                  <option value="">-- None / Existing --</option>
                  <?php foreach ($linkableUsers as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username'] . ' (' . $u['email'] . ')'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select" required>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['dept_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
              <div class="col-md-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
              <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
              <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
              <div class="col-md-6"><label class="form-label">Hire Date</label><input type="date" name="hire_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Create</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit Modal: Instructor -->
  <div class="modal fade" id="instructorEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Instructor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <?php echo csrf_field(); ?>
            <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">User (link)</label>
                <select name="user_id" class="form-select">
                  <option value="">-- None / Existing --</option>
                  <?php foreach ($linkableUsers as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo ($edit && (int)($edit['user_id'] ?? 0)===(int)$u['id'])?'selected':''; ?>><?php echo htmlspecialchars($u['username'] . ' (' . $u['email'] . ')'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select" required>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>" <?php echo ($edit && (int)$edit['department_id']===(int)$d['id'])?'selected':''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($edit['first_name'] ?? ''); ?>" required></div>
              <div class="col-md-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($edit['last_name'] ?? ''); ?>" required></div>
              <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit['email'] ?? ''); ?>" required></div>
              <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($edit['phone'] ?? ''); ?>"></div>
              <div class="col-md-6"><label class="form-label">Hire Date</label><input type="date" name="hire_date" class="form-control" value="<?php echo htmlspecialchars($edit['hire_date'] ?? date('Y-m-d')); ?>" required></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary">Update</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal: Unassigned Students -->
  <div class="modal fade" id="unassignedStudentsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Unassigned Students (<?php echo (int)($unassignedCount['cnt'] ?? 0); ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($unassignedStudents)): ?>
            <p class="text-muted">All students have advisors assigned!</p>
          <?php else: ?>
            <table class="table table-sm align-middle">
              <thead><tr><th>Student ID</th><th>Name</th><th>Email</th><th>Dept/Level</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($unassignedStudents as $us): ?>
                  <tr>
                    <td class="small"><?php echo htmlspecialchars($us['student_id']); ?></td>
                    <td><?php echo htmlspecialchars($us['first_name'].' '.$us['last_name']); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars($us['email']); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars($us['dept_name'].' · '.$us['level_name']); ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="/project/admin/manage_students.php?id=<?php echo (int)$us['id']; ?>">Assign Advisor</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>


</div>
<?php
include __DIR__ . '/footer.php';
