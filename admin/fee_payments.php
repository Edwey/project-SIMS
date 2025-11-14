<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Filters
$status = isset($_GET['status']) && in_array($_GET['status'], ['paid','pending','overdue','cancelled'], true) ? $_GET['status'] : '';
$yearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$studentQuery = trim($_GET['student_q'] ?? ''); // id/email/name

$years = get_academic_years_admin();
$semesters = $yearId ? get_semesters_by_year($yearId) : [];

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $params = [];
    $where = [];
    if ($status !== '') { $where[] = 'fp.status = ?'; $params[] = $status; }
    if ($yearId > 0) { $where[] = 'fp.academic_year_id = ?'; $params[] = $yearId; }
    if ($semesterId > 0) { $where[] = 'fp.semester_id = ?'; $params[] = $semesterId; }
    if ($studentQuery !== '') {
        $like = '%' . $studentQuery . '%';
        $where[] = '(s.student_id LIKE ? OR s.email LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = db_query(
        "SELECT fp.id, s.student_id, CONCAT(s.first_name,' ',s.last_name) AS student_name, fp.amount, fp.payment_date, fp.status, fp.payment_method, fp.transaction_id, fp.scholarship_amount
         FROM fee_payments fp JOIN students s ON fp.student_id = s.id $whereSql ORDER BY fp.payment_date DESC",
        $params
    );

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fee_payments_export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Student ID','Student Name','Amount','Payment Date','Status','Method','Transaction ID','Scholarship']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['student_id'], $r['student_name'], $r['amount'], $r['payment_date'], $r['status'], $r['payment_method'], $r['transaction_id'], $r['scholarship_amount']
        ]);
    }
    fclose($out);
    exit;
}

// Create/Update/Delete handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/fee_payments.php');
    }

    if (isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        db_execute('DELETE FROM fee_payments WHERE id = ?', [$delId]);
        set_flash_message('success', 'Payment deleted.');
        redirect('/admin/fee_payments.php');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $studentId = (int)($_POST['student_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $pStatus = in_array($_POST['status'] ?? '', ['paid','pending','overdue','cancelled'], true) ? $_POST['status'] : 'pending';
    $method = trim($_POST['payment_method'] ?? '');
    $tx = trim($_POST['transaction_id'] ?? '');
    $semesterIdPost = (int)($_POST['semester_id'] ?? 0);
    $yearIdPost = (int)($_POST['academic_year_id'] ?? 0);
    $scholarship = (float)($_POST['scholarship_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($studentId <= 0 || $amount <= 0 || $semesterIdPost <= 0 || $yearIdPost <= 0) {
        set_flash_message('error', 'Student, amount, academic year and semester are required.');
        redirect('/admin/fee_payments.php');
    }

    // validate existence
    $studentExists = db_query_one('SELECT id FROM students WHERE id = ? LIMIT 1', [$studentId]);
    $semExists = db_query_one('SELECT id FROM semesters WHERE id = ? LIMIT 1', [$semesterIdPost]);
    $yearExists = db_query_one('SELECT id FROM academic_years WHERE id = ? LIMIT 1', [$yearIdPost]);
    if (!$studentExists || !$semExists || !$yearExists) {
        set_flash_message('error', 'Invalid related record(s).');
        redirect('/admin/fee_payments.php');
    }

    if ($id > 0) {
        // Check previous values to determine if status changed
        $prev = db_query_one('SELECT status, amount FROM fee_payments WHERE id = ? LIMIT 1', [$id]);
        db_execute(
            'UPDATE fee_payments SET student_id=?, amount=?, payment_date=?, status=?, payment_method=?, transaction_id=?, semester_id=?, academic_year_id=?, scholarship_amount=?, notes=? WHERE id=?',
            [$studentId,$amount,$paymentDate,$pStatus,$method ?: null,$tx ?: null,$semesterIdPost,$yearIdPost,$scholarship,$notes ?: null,$id]
        );
        set_flash_message('success', 'Payment updated.');
        if ($prev && ($prev['status'] !== $pStatus || (float)$prev['amount'] !== (float)$amount)) {
            notify_fee_status_change($studentId, (float)$amount, $pStatus);
        }
    } else {
        db_execute(
            'INSERT INTO fee_payments (student_id, amount, payment_date, status, payment_method, transaction_id, semester_id, academic_year_id, scholarship_amount, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?, NOW())',
            [$studentId,$amount,$paymentDate,$pStatus,$method ?: null,$tx ?: null,$semesterIdPost,$yearIdPost,$scholarship,$notes ?: null]
        );
        set_flash_message('success', 'Payment recorded.');
        notify_fee_status_change($studentId, (float)$amount, $pStatus);
    }

    redirect('/admin/fee_payments.php');
}

// Load students for dropdown (basic list)
$students = db_query("SELECT id, student_id, first_name, last_name FROM students ORDER BY first_name, last_name LIMIT 500");

// Listing with filters
$params = [];
$where = [];
if ($status !== '') { $where[] = 'fp.status = ?'; $params[] = $status; }
if ($yearId > 0) { $where[] = 'fp.academic_year_id = ?'; $params[] = $yearId; }
if ($semesterId > 0) { $where[] = 'fp.semester_id = ?'; $params[] = $semesterId; }
if ($studentQuery !== '') {
    $like = '%' . $studentQuery . '%';
    $where[] = '(s.student_id LIKE ? OR s.email LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$payments = db_query(
    "SELECT fp.*, s.student_id AS sid, CONCAT(s.first_name,' ',s.last_name) AS sname, sem.semester_name, ay.year_name
     FROM fee_payments fp
     JOIN students s ON fp.student_id = s.id
     JOIN semesters sem ON fp.semester_id = sem.id
     JOIN academic_years ay ON fp.academic_year_id = ay.id
     $whereSql
     ORDER BY fp.payment_date DESC
     LIMIT 500",
    $params
);

// Prefill for edit
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRecord = $editId > 0 ? db_query_one('SELECT * FROM fee_payments WHERE id = ? LIMIT 1', [$editId]) : null;
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
    <title>Fee Payments - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Fee Payments</h4>
        <div>
            <a class="btn btn-outline-secondary btn-sm" href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>">
                Export CSV
            </a>
        </div>
    </div>

    <form method="GET" class="row g-2 align-items-end mb-4">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <?php foreach (['paid','pending','overdue','cancelled'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo $status === $opt ? 'selected' : ''; ?>><?php echo ucfirst($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Academic Year</label>
            <select name="year_id" class="form-select" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int)$y['id']; ?>" <?php echo $yearId === (int)$y['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($y['year_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Semester</label>
            <select name="semester_id" class="form-select">
                <option value="0">All</option>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo $semesterId === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['semester_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Student (ID/Name/Email)</label>
            <input type="text" class="form-control" name="student_q" value="<?php echo htmlspecialchars($studentQuery); ?>">
        </div>
        <div class="col-12 d-flex gap-2 mt-2">
            <button class="btn btn-primary">Filter</button>
            <a href="/project/admin/fee_payments.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><?php echo $editRecord ? 'Edit Fee Payment' : 'Record Fee Payment'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <?php echo csrf_field(); ?>
                        <?php if ($editRecord): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editRecord['id']; ?>">
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Student</label>
                            <select name="student_id" class="form-select" required>
                                <option value="">Select student</option>
                                <?php foreach ($students as $st): ?>
                                    <option value="<?php echo (int)$st['id']; ?>" <?php echo ($editRecord && (int)$editRecord['student_id'] === (int)$st['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name'] . ' (' . $st['student_id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" class="form-select" required>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>" <?php echo ($editRecord && (int)$editRecord['academic_year_id'] === (int)$y['id']) || (!$editRecord && $yearId === (int)$y['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($y['year_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Semester</label>
                            <select name="semester_id" class="form-select" required>
                                <?php foreach ($semesters as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>" <?php echo ($editRecord && (int)$editRecord['semester_id'] === (int)$s['id']) || (!$editRecord && $semesterId === (int)$s['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['semester_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo htmlspecialchars($editRecord['amount'] ?? ''); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?php echo htmlspecialchars($editRecord['payment_date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['paid','pending','overdue','cancelled'] as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo ($editRecord && $editRecord['status'] === $opt) ? 'selected' : ''; ?>><?php echo ucfirst($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Method</label>
                            <input type="text" name="payment_method" class="form-control" value="<?php echo htmlspecialchars($editRecord['payment_method'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Transaction ID</label>
                            <input type="text" name="transaction_id" class="form-control" value="<?php echo htmlspecialchars($editRecord['transaction_id'] ?? ''); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Scholarship Amount</label>
                            <input type="number" step="0.01" name="scholarship_amount" class="form-control" value="<?php echo htmlspecialchars($editRecord['scholarship_amount'] ?? '0.00'); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($editRecord['notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit"><?php echo $editRecord ? 'Update' : 'Record'; ?></button>
                            <?php if ($editRecord): ?>
                                <a class="btn btn-secondary" href="/project/admin/fee_payments.php">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">Payments</h5>
                </div>
                <div class="card-body table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Semester</th>
                                <th>Year</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="text-muted small"><?php echo htmlspecialchars($p['payment_date']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($p['sname']); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($p['sid']); ?></div>
                                </td>
                                <td>$<?php echo number_format((float)$p['amount'], 2); ?><?php if ((float)$p['scholarship_amount'] > 0): ?><div class="text-muted small">Scholarship: $<?php echo number_format((float)$p['scholarship_amount'],2); ?></div><?php endif; ?></td>
                                <td><span class="badge bg-<?php echo $p['status']==='paid'?'success':($p['status']==='pending'?'secondary':($p['status']==='overdue'?'danger':'dark')); ?>"><?php echo ucfirst($p['status']); ?></span></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($p['semester_name']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($p['year_name']); ?></td>
                                <td class="text-end">
                                    <form method="GET" class="d-inline">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button class="btn btn-outline-secondary btn-sm">Edit</button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this payment?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$p['id']; ?>">
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
