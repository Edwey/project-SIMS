<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'manage_students.php';

// Prefetch ref data
$departments = get_all_departments();
$levels = get_all_levels();
$programs = get_all_programs();
// Load instructors for advisor assignment with advisee counts (sorted by lowest count first)
$allInstructors = db_query("SELECT i.id, i.first_name, i.last_name, u.email, i.department_id, d.dept_name,
    COUNT(sa.id) AS advisee_count
    FROM instructors i
    JOIN users u ON u.id = i.user_id
    LEFT JOIN student_advisors sa ON sa.instructor_id = i.id AND sa.is_active = 1
    LEFT JOIN departments d ON i.department_id = d.id
    GROUP BY i.id, i.first_name, i.last_name, u.email, i.department_id, d.dept_name
    ORDER BY advisee_count ASC, i.first_name, i.last_name
    LIMIT 1000");

// Users that can be linked (role=student and not linked yet)
$linkableUsers = db_query("SELECT u.id, u.username, u.email FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.role='student' AND (s.user_id IS NULL) ORDER BY u.username LIMIT 500");

// Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/manage_students.php');
    }

    // Assign advisor handler (single active advisor enforced)
    if (isset($_POST['assign_advisor'])) {
        $studentInternalId = (int)($_POST['student_internal_id'] ?? 0);
        $advisorId = (int)($_POST['instructor_id'] ?? 0);
        if ($studentInternalId <= 0 || $advisorId <= 0) {
            set_flash_message('error', 'Student and advisor are required.');
            redirect('/admin/manage_students.php');
        }
        // Upsert advisor mapping
        // Ensure student exists
        $existsStudent = db_query_one('SELECT id FROM students WHERE id = ? LIMIT 1', [$studentInternalId]);
        $existsInstr = db_query_one('SELECT id FROM instructors WHERE id = ? LIMIT 1', [$advisorId]);
        if (!$existsStudent || !$existsInstr) {
            set_flash_message('error', 'Invalid student or advisor.');
            redirect('/admin/manage_students.php');
        }
        // Deactivate all current advisors for this student (single-active policy)
        db_execute('UPDATE student_advisors SET is_active = 0 WHERE student_id = ?', [$studentInternalId]);
        // Create if missing, else reactivate this one
        db_execute('INSERT INTO student_advisors (student_id, instructor_id, assigned_date, is_active) VALUES (?,?, CURDATE(), 1)
                    ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), assigned_date=VALUES(assigned_date)'
                   , [$studentInternalId, $advisorId]);
        set_flash_message('success', 'Advisor assigned to student.');
        redirect('/admin/manage_students.php');
    }

    // Unassign advisor (deactivate current mapping)
    if (isset($_POST['unassign_advisor'])) {
        $studentInternalId = (int)($_POST['student_internal_id'] ?? 0);
        if ($studentInternalId <= 0) {
            set_flash_message('error', 'Invalid student.');
            redirect('/admin/manage_students.php');
        }
        db_execute('UPDATE student_advisors SET is_active = 0 WHERE student_id = ?', [$studentInternalId]);
        set_flash_message('success', 'Advisor unassigned.');
        redirect('/admin/manage_students.php');
    }

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $hasEnroll = db_query_one('SELECT id FROM enrollments WHERE student_id = ? LIMIT 1', [$id]);
        if ($hasEnroll) {
            set_flash_message('error', 'Cannot delete: student has enrollments.');
        } else {
            db_execute('DELETE FROM students WHERE id = ?', [$id]);
            set_flash_message('success', 'Student deleted.');
        }
        redirect('/admin/manage_students.php');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $studentIdCode = trim($_POST['student_id'] ?? '');
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dob = $_POST['date_of_birth'] ?? null;
    $address = trim($_POST['address'] ?? '');
    $levelId = (int)($_POST['current_level_id'] ?? 0);
    $deptId = (int)($_POST['department_id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);
    $enrollDate = $_POST['enrollment_date'] ?? date('Y-m-d');
    $status = in_array($_POST['status'] ?? 'active', ['active','graduated','withdrawn','suspended'], true) ? $_POST['status'] : 'active';
    $linkUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : null;

    if ($first === '' || $last === '' || $levelId<=0 || $deptId<=0 || $programId<=0) {
        set_flash_message('error', 'Name, level, department and program are required.');
        redirect('/admin/manage_students.php');
    }

    if ($id > 0) {
        db_execute('UPDATE students SET student_id=?, first_name=?, last_name=?, email=?, phone=?, date_of_birth=?, address=?, current_level_id=?, department_id=?, enrollment_date=?, status=? WHERE id=?',
            [$studentIdCode,$first,$last,$email,$phone?:null,$dob?:null,$address?:null,$levelId,$deptId,$enrollDate,$status,$id]
        );
        set_flash_message('success', 'Student updated.');
    } else {
        // Auto-generate student_id if not provided
        if ($studentIdCode === '') {
            $dept = get_department_by_id($deptId);
            $deptCode = $dept['dept_code'] ?? 'GEN';
            $year = date('Y');
            $seqRow = db_query_one('SELECT COUNT(*) AS c FROM students WHERE department_id = ?', [$deptId]);
            $sequence = (int)($seqRow['c'] ?? 0) + 1;
            if (function_exists('generate_student_id')) {
                $studentIdCode = generate_student_id($year, $deptCode, $sequence);
            } else {
                $studentIdCode = strtoupper(substr($deptCode,0,3)) . substr($year, -2) . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
            }
        }
        // Ensure unique student_id
        $dupeSid = db_query_one('SELECT id FROM students WHERE student_id = ? LIMIT 1', [$studentIdCode]);
        if ($dupeSid) { set_flash_message('error','Student ID already exists.'); redirect('/admin/manage_students.php'); }
        // Email uniqueness is enforced at users table level
        db_execute('INSERT INTO students (student_id, first_name, last_name, phone, date_of_birth, address, current_level_id, user_id, department_id, program_id, enrollment_date, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())',
            [$studentIdCode,$first,$last,$phone?:null,$dob?:null,$address?:null,$levelId,$linkUserId,$deptId,$programId,$enrollDate,$status]
        );
        set_flash_message('success', 'Student created.');
    }

    redirect('/admin/manage_students.php');
}

// Filters and listing
$q = trim($_GET['q'] ?? '');
$deptFilter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$levelFilter = isset($_GET['level_id']) ? (int)$_GET['level_id'] : 0;
$advisorFilter = $_GET['advisor_filter'] ?? '';
$params = [];$where=[];
if ($q !== '') { $like='%'.$q.'%'; $where[]='(s.student_id LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ?)'; array_push($params,$like,$like,$like,$like); }
if ($deptFilter>0) { $where[]='s.department_id = ?'; $params[]=$deptFilter; }
if ($levelFilter>0) { $where[]='s.current_level_id = ?'; $params[]=$levelFilter; }
if ($advisorFilter === 'no_advisor') { $where[]='sa.instructor_id IS NULL'; }
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
// Pagination
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Total count
$countRow = db_query_one(
    "SELECT COUNT(DISTINCT s.id) AS total
     FROM students s
     JOIN departments d ON s.department_id=d.id
     JOIN levels l ON s.current_level_id=l.id
     LEFT JOIN student_advisors sa ON sa.student_id = s.id AND sa.is_active = 1
     LEFT JOIN instructors i ON sa.instructor_id = i.id
     $whereSql",
    $params
);
$total = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Listing page
$students = db_query(
    "SELECT s.*, p.program_name, p.program_code, d.dept_name, l.level_name,
            s.graduation_lock_at, u.is_active AS user_active,
            i.first_name AS adv_first, i.last_name AS adv_last, ui.email AS adv_email, sa.instructor_id AS adv_id
     FROM students s
     JOIN users u ON u.id = s.user_id
     JOIN programs p ON p.id = s.program_id
     JOIN departments d ON s.department_id=d.id
     JOIN levels l ON s.current_level_id=l.id
     LEFT JOIN student_advisors sa ON sa.student_id = s.id AND sa.is_active = 1
     LEFT JOIN instructors i ON sa.instructor_id = i.id
     LEFT JOIN users ui ON ui.id = i.user_id
     $whereSql
     ORDER BY s.first_name, s.last_name
     LIMIT ?, ?",
    array_merge($params, [$offset, $perPage])
);

// Prefill
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit = $editId>0 ? db_query_one('SELECT * FROM students WHERE id = ? LIMIT 1', [$editId]) : null;
$gpaSummary = $edit ? get_student_gpa_summary((int)$edit['id']) : null;

// Assign Advisor prefill
$assignAdvisorId = isset($_GET['assign_advisor_id']) ? (int)$_GET['assign_advisor_id'] : 0;
$assignStudent = $assignAdvisorId>0 ? db_query_one('SELECT id, first_name, last_name FROM students WHERE id = ? LIMIT 1', [$assignAdvisorId]) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Students - Admin</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body<?php if ($editId): ?> data-edit-modal="studentEditModal"<?php elseif ($assignAdvisorId): ?> data-edit-modal="assignAdvisorModal"<?php endif; ?>>
<?php include __DIR__ . '/header.php'; ?>
<main class="container py-4">
  <?php render_flash_messages(); ?>

  <form method="GET" class="row g-2 align-items-end mb-4">
    <div class="col-md-4">
      <label class="form-label">Search</label>
      <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($q); ?>" placeholder="ID, name or email">
    </div>
    <div class="col-md-3">
      <label class="form-label">Department</label>
      <select name="department_id" class="form-select" onchange="this.form.submit()">
        <option value="0">All</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?php echo (int)$d['id']; ?>" <?php echo $deptFilter===(int)$d['id']?'selected':''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Level</label>
      <select name="level_id" class="form-select">
        <option value="0">All</option>
        <?php foreach ($levels as $l): ?>
          <option value="<?php echo (int)$l['id']; ?>" <?php echo $levelFilter===(int)$l['id']?'selected':''; ?>><?php echo htmlspecialchars($l['level_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Advisor</label>
      <select name="advisor_filter" class="form-select">
        <option value="">All</option>
        <option value="no_advisor" <?php echo $advisorFilter==='no_advisor'?'selected':''; ?>>No Advisor</option>
      </select>
    </div>
    <div class="col-12 mt-2">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-secondary" href="/project/admin/manage_students.php">Reset</a>
    </div>
  </form>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#studentAddModal" id="addStudentBtn">Add Student</button>
  </div>

  <div class="card card-shadow">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Students</h5>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead><tr><th>ID</th><th>Name</th><th>Program/Level</th><th>Advisor</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($students as $s): ?>
                <tr>
                  <td class="small text-muted"><?php echo htmlspecialchars($s['student_id']); ?></td>
                  <td>
                    <div class="fw-semibold"><?php echo htmlspecialchars($s['first_name'].' '.$s['last_name']); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($s['user_email']); ?><?php if (!empty($s['phone'])): ?> · <?php echo htmlspecialchars($s['phone']); ?><?php endif; ?></div>
                  </td>
                  <td class="small text-muted"><?php echo htmlspecialchars($s['program_name']); ?> · <?php echo htmlspecialchars($s['level_name']); ?></td>
                  <td class="small">
                    <?php if (!empty($s['adv_id'])): ?>
                      <div><?php echo htmlspecialchars(($s['adv_first']??'').' '.($s['adv_last']??'')); ?></div>
                      <div class="text-muted small"><?php echo htmlspecialchars($s['adv_email'] ?? ''); ?></div>
                    <?php else: ?>
                      <span class="text-muted">None</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php echo $s['status']==='active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">'.htmlspecialchars(ucfirst($s['status'])).'</span>'; ?>
                    <?php if (!empty($s['graduation_lock_at'])): ?>
                      <div class="text-muted small mt-1">
                        Access until: <?php echo htmlspecialchars(format_datetime_display($s['graduation_lock_at'], 'M j, Y H:i')); ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-outline-primary btn-sm me-1" href="/project/admin/manage_students.php?assign_advisor_id=<?php echo (int)$s['id']; ?>">Assign Advisor</a>
                    <?php if (!empty($s['adv_id'])): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Unassign current advisor for this student?');">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="student_internal_id" value="<?php echo (int)$s['id']; ?>">
                      <button class="btn btn-link text-danger btn-sm" name="unassign_advisor" value="1">Unassign</button>
                    </form>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary btn-sm" href="/project/admin/manage_students.php?id=<?php echo (int)$s['id']; ?>">Edit</a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this student?');">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="delete_id" value="<?php echo (int)$s['id']; ?>">
                      <button class="btn btn-outline-danger btn-sm">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php
        // Pagination controls
        $from = $total === 0 ? 0 : ($offset + 1);
        $to = min($offset + $perPage, $total);
        $qsBase = $_GET;
        unset($qsBase['page']);
        $base = '/project/admin/manage_students.php?'.http_build_query($qsBase);
      ?>
      <div class="d-flex justify-content-between align-items-center my-3">
        <div class="text-muted small">
          Showing <?php echo (int)$from; ?>–<?php echo (int)$to; ?> of <?php echo (int)$total; ?>
        </div>
        <nav>
          <ul class="pagination mb-0">
            <?php $prev = max(1, $page-1); $next = min($totalPages, $page+1); ?>
            <li class="page-item <?php echo $page<=1?'disabled':''; ?>">
              <a class="page-link" href="<?php echo $base.'&page='.$prev; ?>">Prev</a>
            </li>
            <li class="page-item disabled"><span class="page-link">Page <?php echo (int)$page; ?> / <?php echo (int)$totalPages; ?></span></li>
            <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>">
              <a class="page-link" href="<?php echo $base.'&page='.$next; ?>">Next</a>
            </li>
          </ul>
        </nav>
      </div>

      <?php include __DIR__ . '/footer.php'; ?>

  <!-- Modal: Create Student -->
  <div class="modal fade" id="studentAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Add Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <?php echo csrf_field(); ?>
            <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
            <div class="row g-3">
              <div class="col-6"><label class="form-label">Student ID</label><input type="text" name="student_id" class="form-control" value="<?php echo htmlspecialchars($edit['student_id'] ?? ''); ?>" required></div>
              <div class="col-6"><label class="form-label">User (link)</label>
                <select name="user_id" class="form-select">
                  <option value="">-- None / Existing --</option>
                  <?php foreach ($linkableUsers as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo ($edit && (int)($edit['user_id'] ?? 0)===(int)$u['id'])?'selected':''; ?>><?php echo htmlspecialchars($u['username'] . ' (' . $u['email'] . ')'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($edit['first_name'] ?? ''); ?>" required></div>
              <div class="col-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($edit['last_name'] ?? ''); ?>" required></div>
              <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit['email'] ?? ''); ?>" required></div>
              <div class="col-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($edit['phone'] ?? ''); ?>"></div>
              <div class="col-6"><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($edit['date_of_birth'] ?? ''); ?>"></div>
              <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($edit['address'] ?? ''); ?></textarea></div>
              <div class="col-6"><label class="form-label">Department</label>
                <select name="department_id" class="form-select" required>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>" <?php echo ($edit && (int)$edit['department_id']===(int)$d['id'])?'selected':''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6"><label class="form-label">Level</label>
                <select name="current_level_id" class="form-select" required>
                  <?php foreach ($levels as $l): ?>
                    <option value="<?php echo (int)$l['id']; ?>" <?php echo ($edit && (int)$edit['current_level_id']===(int)$l['id'])?'selected':''; ?>><?php echo htmlspecialchars($l['level_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6"><label class="form-label">Enrollment Date</label><input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
              <div class="col-6"><label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <?php foreach (['active','graduated','withdrawn','suspended'] as $st): ?>
                    <option value="<?php echo $st; ?>"><?php echo ucfirst($st); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
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

  <!-- Edit Modal: Update Student -->
  <div class="modal fade" id="studentEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Edit Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <?php echo csrf_field(); ?>
            <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
            <div class="row g-3">
              <div class="col-6"><label class="form-label">Student ID</label><input type="text" name="student_id" class="form-control" value="<?php echo htmlspecialchars($edit['student_id'] ?? ''); ?>" required></div>
              <div class="col-6"><label class="form-label">User (link)</label>
                <select name="user_id" class="form-select">
                  <option value="">-- None / Existing --</option>
                  <?php foreach ($linkableUsers as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo ($edit && (int)($edit['user_id'] ?? 0)===(int)$u['id'])?'selected':''; ?>><?php echo htmlspecialchars($u['username'] . ' (' . $u['email'] . ')'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($edit['first_name'] ?? ''); ?>" required></div>
              <div class="col-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($edit['last_name'] ?? ''); ?>" required></div>
              <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit['email'] ?? ''); ?>" required></div>
              <div class="col-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($edit['phone'] ?? ''); ?>"></div>
              <div class="col-6"><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($edit['date_of_birth'] ?? ''); ?>"></div>
              <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($edit['address'] ?? ''); ?></textarea></div>
              <div class="col-6"><label class="form-label">Department</label>
                <select name="department_id" class="form-select" required>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?php echo (int)$d['id']; ?>" <?php echo ($edit && (int)$edit['department_id']===(int)$d['id'])?'selected':''; ?>><?php echo htmlspecialchars($d['dept_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6"><label class="form-label">Level</label>
                <select name="current_level_id" class="form-select" required>
                  <?php foreach ($levels as $l): ?>
                    <option value="<?php echo (int)$l['id']; ?>" <?php echo ($edit && (int)$edit['current_level_id']===(int)$l['id'])?'selected':''; ?>><?php echo htmlspecialchars($l['level_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6"><label class="form-label">Enrollment Date</label><input type="date" name="enrollment_date" class="form-control" value="<?php echo htmlspecialchars($edit['enrollment_date'] ?? ''); ?>" required></div>
              <div class="col-6"><label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <?php foreach (['active','graduated','withdrawn','suspended'] as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo ($edit && $edit['status']===$st)?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
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

  <!-- Modal: Assign Advisor -->
  <div class="modal fade" id="assignAdvisorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Assign Advisor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <?php echo csrf_field(); ?>
            <?php if ($assignStudent): ?>
              <input type="hidden" name="student_internal_id" value="<?php echo (int)$assignStudent['id']; ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label class="form-label">Student</label>
              <input type="text" class="form-control" value="<?php echo htmlspecialchars($assignStudent ? ($assignStudent['first_name'].' '.$assignStudent['last_name']) : ''); ?>" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label">Select Advisor</label>
              <select name="instructor_id" class="form-select" required size="10">
                <option value="">-- Select Advisor --</option>
                <?php foreach ($allInstructors as $ins): ?>
                  <option value="<?php echo (int)$ins['id']; ?>">
                    <?php echo htmlspecialchars($ins['first_name'].' '.$ins['last_name']); ?> 
                    (<?php echo htmlspecialchars($ins['dept_name']); ?>) 
                    - <?php echo (int)$ins['advisee_count']; ?> advisee<?php echo (int)$ins['advisee_count']!==1?'s':''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" name="assign_advisor" value="1" class="btn btn-primary">Assign</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<?php include __DIR__ . '/footer.php'; ?>
