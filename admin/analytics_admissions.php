<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$programs = get_programs();
$yearId = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$status = isset($_GET['status']) ? trim($_GET['status']) : '';

$where = [];
$params = [];
if ($programId > 0) {
    $where[] = 'a.program_id = ?';
    $params[] = $programId;
}
if ($yearId > 0) {
    $where[] = 'YEAR(a.submitted_at) = ?';
    $params[] = $yearId;
}
if ($status !== '') {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$statusCounts = db_query(
    'SELECT a.status, COUNT(*) AS total
     FROM applications a
     ' . $whereClause . '
     GROUP BY a.status',
    $params
);

$countsByStatus = [
    'applied' => 0,
    'under_review' => 0,
    'offered' => 0,
    'accepted' => 0,
    'rejected' => 0,
];
foreach ($statusCounts as $row) {
    $countsByStatus[$row['status']] = (int)$row['total'];
}

$totalApplications = array_sum($countsByStatus);
$conversions = [
    'review_rate' => $totalApplications > 0 ? round(($countsByStatus['under_review'] / $totalApplications) * 100, 1) : null,
    'offer_rate' => $totalApplications > 0 ? round(($countsByStatus['offered'] / $totalApplications) * 100, 1) : null,
    'accept_rate' => $totalApplications > 0 ? round(($countsByStatus['accepted'] / $totalApplications) * 100, 1) : null,
];

$monthlyTrend = db_query(
    'SELECT DATE_FORMAT(a.submitted_at, "%Y-%m") AS period,
            COUNT(*) AS submitted,
            SUM(CASE WHEN a.status = "accepted" THEN 1 ELSE 0 END) AS accepted
     FROM applications a
     ' . $whereClause . '
     GROUP BY period
     ORDER BY period DESC
     LIMIT 12',
    $params
);

$programBreakdown = db_query(
    'SELECT p.program_name,
            COUNT(*) AS total,
            SUM(CASE WHEN a.status = "accepted" THEN 1 ELSE 0 END) AS accepted
     FROM applications a
     JOIN programs p ON p.id = a.program_id
     ' . $whereClause . '
     GROUP BY p.id, p.program_name
     ORDER BY total DESC',
    $params
);

$export = isset($_GET['export']) ? $_GET['export'] : '';
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="admissions_analytics.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Program', 'Total Applications', 'Accepted']);
    foreach ($programBreakdown as $row) {
        fputcsv($out, [
            $row['program_name'],
            (int)$row['total'],
            (int)$row['accepted'],
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Admissions Analytics';
include __DIR__ . '/header.php';
render_flash_messages();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h4 class="mb-1">Admissions Funnel Analytics</h4>
        <p class="text-muted mb-0">Monitor application volume, conversion, and program demand.</p>
    </div>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-primary">
        <i class="fas fa-download me-1"></i>Export CSV
    </a>
</div>

<form method="GET" class="card card-shadow mb-4">
    <div class="card-body row g-3 align-items-end">
        <div class="col-lg-4">
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
            <label class="form-label">Submitted Year</label>
            <select name="year" class="form-select">
                <option value="0">All Years</option>
                <?php
                $years = db_query('SELECT DISTINCT YEAR(submitted_at) AS yr FROM applications ORDER BY yr DESC');
                foreach ($years as $row):
                ?>
                    <option value="<?php echo (int)$row['yr']; ?>" <?php echo $yearId === (int)$row['yr'] ? 'selected' : ''; ?>>
                        <?php echo (int)$row['yr']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (['applied', 'under_review', 'offered', 'accepted', 'rejected'] as $statusOption): ?>
                    <option value="<?php echo $statusOption; ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>>
                        <?php echo ucwords(str_replace('_', ' ', $statusOption)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 d-grid">
            <button type="submit" class="btn btn-primary">Apply</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Total Applications</div>
                <div class="fs-3 fw-bold"><?php echo $totalApplications; ?></div>
                <div class="text-muted small">Across chosen filters</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Offer Conversion</div>
                <div class="fs-3 fw-bold"><?php echo $conversions['offer_rate'] !== null ? $conversions['offer_rate'] . '%' : 'N/A'; ?></div>
                <div class="text-muted small">Offers / total applications</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card card-shadow h-100">
            <div class="card-body">
                <div class="text-muted small">Acceptance Rate</div>
                <div class="fs-3 fw-bold text-success"><?php echo $conversions['accept_rate'] !== null ? $conversions['accept_rate'] . '%' : 'N/A'; ?></div>
                <div class="text-muted small">Accepted / total applications</div>
            </div>
        </div>
    </div>
</div>

<div class="card card-shadow mb-4">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="section-title mb-0">Status Breakdown</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Status</th>
                        <th class="text-end">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($countsByStatus as $label => $count): ?>
                        <tr>
                            <td><?php echo ucwords(str_replace('_', ' ', $label)); ?></td>
                            <td class="text-end"><?php echo $count; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card card-shadow mb-4">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="section-title mb-0">Monthly Trend (Last 12 Periods)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($monthlyTrend)): ?>
            <p class="text-muted mb-0">No application data for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Period</th>
                            <th class="text-end">Submitted</th>
                            <th class="text-end">Accepted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyTrend as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['period']); ?></td>
                                <td class="text-end"><?php echo (int)$row['submitted']; ?></td>
                                <td class="text-end text-success"><?php echo (int)$row['accepted']; ?></td>
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
        <h5 class="section-title mb-0">Program Demand</h5>
    </div>
    <div class="card-body">
        <?php if (empty($programBreakdown)): ?>
            <p class="text-muted mb-0">No application data for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Program</th>
                            <th class="text-end">Applications</th>
                            <th class="text-end">Accepted</th>
                            <th class="text-end">Acceptance %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programBreakdown as $row): ?>
                            <?php
                            $acceptPercent = (int)$row['total'] > 0 ? round(((int)$row['accepted'] / (int)$row['total']) * 100, 1) : null;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['program_name']); ?></td>
                                <td class="text-end"><?php echo (int)$row['total']; ?></td>
                                <td class="text-end text-success"><?php echo (int)$row['accepted']; ?></td>
                                <td class="text-end"><?php echo $acceptPercent !== null ? $acceptPercent . '%' : 'N/A'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
