<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$programs = get_programs();
$academicYears = db_query('SELECT id, year_name FROM academic_years ORDER BY start_date DESC');

$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$yearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$semesterId = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

$semesters = [];
if ($yearId > 0) {
    $semesters = db_query('SELECT id, semester_name FROM semesters WHERE academic_year_id = ? ORDER BY start_date', [$yearId]);
} else {
    $semesters = db_query('SELECT id, semester_name FROM semesters ORDER BY start_date DESC');
}

$whereParts = [];
$params = [];

if ($programId > 0) {
    $whereParts[] = 's.program_id = ?';
    $params[] = $programId;
}
if ($yearId > 0) {
    $whereParts[] = 'fp.academic_year_id = ?';
    $params[] = $yearId;
}
if ($semesterId > 0) {
    $whereParts[] = 'fp.semester_id = ?';
    $params[] = $semesterId;
}
if ($statusFilter !== '') {
    $whereParts[] = 'fp.status = ?';
    $params[] = $statusFilter;
}
$whereClause = $whereParts ? (' WHERE ' . implode(' AND ', $whereParts)) : '';

$summaryRow = db_query_one(
    'SELECT
        SUM(CASE WHEN fp.status = "paid" THEN fp.amount ELSE 0 END) AS total_paid,
        SUM(CASE WHEN fp.status = "pending" THEN fp.amount ELSE 0 END) AS total_pending,
        SUM(CASE WHEN fp.status = "overdue" THEN fp.amount ELSE 0 END) AS total_overdue,
        SUM(fp.scholarship_amount) AS total_scholarships,
        COUNT(fp.id) AS payment_count
     FROM fee_payments fp
     JOIN students s ON s.id = fp.student_id' . $whereClause,
    $params
);

$summaryRow = $summaryRow ?: [
    'total_paid' => 0,
    'total_pending' => 0,
    'total_overdue' => 0,
    'total_scholarships' => 0,
    'payment_count' => 0,
];

$outstandingAmount = (float)$summaryRow['total_pending'] + (float)$summaryRow['total_overdue'];
$netCollected = (float)$summaryRow['total_paid'] - (float)$summaryRow['total_scholarships'];

$monthlyRows = db_query(
    'SELECT
        DATE_FORMAT(fp.payment_date, "%Y-%m") AS period,
        SUM(fp.amount) AS total_amount,
        SUM(CASE WHEN fp.status = "paid" THEN fp.amount ELSE 0 END) AS paid_amount,
        SUM(CASE WHEN fp.status = "overdue" THEN fp.amount ELSE 0 END) AS overdue_amount,
        COUNT(fp.id) AS payment_count
     FROM fee_payments fp
     JOIN students s ON s.id = fp.student_id' . $whereClause . '
     GROUP BY period
     ORDER BY period DESC
     LIMIT 12',
    $params
);

$studentBalances = db_query(
    'SELECT
        s.id,
        s.student_id,
        s.first_name,
        s.last_name,
        SUM(CASE WHEN fp.status = "paid" THEN fp.amount ELSE 0 END) AS paid_amount,
        SUM(CASE WHEN fp.status IN ("pending", "overdue") THEN fp.amount ELSE 0 END) AS outstanding_amount,
        SUM(fp.scholarship_amount) AS scholarships
     FROM fee_payments fp
     JOIN students s ON s.id = fp.student_id' . $whereClause . '
     GROUP BY s.id, s.student_id, s.first_name, s.last_name
     ORDER BY outstanding_amount DESC',
    $params
);

$export = isset($_GET['export']) ? $_GET['export'] : '';
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="finance_analytics.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Name', 'Paid Amount', 'Outstanding Amount', 'Scholarships']);
    foreach ($studentBalances as $row) {
        fputcsv($out, [
            $row['student_id'],
            $row['first_name'] . ' ' . $row['last_name'],
            number_format((float)$row['paid_amount'], 2),
            number_format((float)$row['outstanding_amount'], 2),
            number_format((float)$row['scholarships'], 2),
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Finance Analytics';
include __DIR__ . '/header.php';
render_flash_messages();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h4 class="mb-1">Finance Health Analytics</h4>
        <p class="text-muted mb-0">Monitor collections, outstanding balances, and payment trends.</p>
    </div>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-primary">
        <i class="fas fa-download me-1"></i>Export CSV
    </a>
</div>

<form method="GET" class="card card-shadow mb-4">
    <div class="card-body row g-3 align-items-end">
        <div class="col-lg-3">
            <label class="form-label">Program</label>
            <select name="program_id" class="form-select">
                <option value="0">All Programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?php echo (int)$program['id']; ?>" <?php echo $programId === (int)$program['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($program['program_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3">
            <label class="form-label">Academic Year</label>
            <select name="academic_year_id" class="form-select">
                <option value="0">All Years</option>
                <?php foreach ($academicYears as $year): ?>
                    <option value="<?php echo (int)$year['id']; ?>" <?php echo $yearId === (int)$year['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($year['year_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3">
            <label class="form-label">Semester</label>
            <select name="semester_id" class="form-select">
                <option value="0">All Semesters</option>
                <?php foreach ($semesters as $semester): ?>
                    <option value="<?php echo (int)$semester['id']; ?>" <?php echo $semesterId === (int)$semester['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($semester['semester_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (['paid', 'pending', 'overdue', 'cancelled'] as $status): ?>
                    <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                        <?php echo ucfirst($status); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 d-grid">
            <button type="submit" class="btn btn-primary">Apply</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-lg-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Total Collected</div>
                <div class="fs-3 fw-bold">$<?php echo number_format((float)$summaryRow['total_paid'], 2); ?></div>
                <div class="text-muted small">Sum of payments marked paid</div>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Outstanding</div>
                <div class="fs-3 fw-bold text-warning">$<?php echo number_format($outstandingAmount, 2); ?></div>
                <div class="text-muted small">Pending + Overdue amounts</div>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Scholarships Applied</div>
                <div class="fs-3 fw-bold text-info">$<?php echo number_format((float)$summaryRow['total_scholarships'], 2); ?></div>
                <div class="text-muted small">Reductions across payments</div>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Net Collected</div>
                <div class="fs-3 fw-bold text-success">$<?php echo number_format($netCollected, 2); ?></div>
                <div class="text-muted small">Total collected minus scholarships</div>
            </div>
        </div>
    </div>
</div>

<div class="card card-shadow mb-4">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="section-title mb-0">Recent Payment Trend (12 months)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($monthlyRows)): ?>
            <p class="text-muted mb-0">No payment records for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Overdue</th>
                            <th class="text-end">Receipts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyRows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['period']); ?></td>
                                <td class="text-end">$<?php echo number_format((float)$row['total_amount'], 2); ?></td>
                                <td class="text-end text-success">$<?php echo number_format((float)$row['paid_amount'], 2); ?></td>
                                <td class="text-end text-danger">$<?php echo number_format((float)$row['overdue_amount'], 2); ?></td>
                                <td class="text-end"><?php echo (int)$row['payment_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card card-shadow">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="section-title mb-0">Student Balance Summary</h5>
    </div>
    <div class="card-body">
        <?php if (empty($studentBalances)): ?>
            <p class="text-muted mb-0">No payment records for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Outstanding</th>
                            <th class="text-end">Scholarships</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentBalances as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['student_id']); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></div>
                                </td>
                                <td class="text-end">$<?php echo number_format((float)$row['paid_amount'], 2); ?></td>
                                <td class="text-end text-warning">$<?php echo number_format((float)$row['outstanding_amount'], 2); ?></td>
                                <td class="text-end text-info">$<?php echo number_format((float)$row['scholarships'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
