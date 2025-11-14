<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'fee_statement.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$studentId = (int)$student['student_internal_id'];
$feeSummary = get_student_fee_summary($studentId);
$feePayments = get_student_fee_payments($studentId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Statement - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <div class="row g-4">
            <div class="col-12">
                <div class="profile-hero card-shadow">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="display-6 mb-2">Fee Statement</h1>
                            <p class="text-muted mb-0">
                                Student ID: <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                                &middot; <?php echo htmlspecialchars($student['dept_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['level_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <a href="#" class="btn btn-outline-primary btn-sm"><i class="fas fa-file-download me-2"></i>Download Receipt</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-shadow">
                    <div class="card-body text-center">
                        <h5 class="section-title mb-3"><i class="fas fa-wallet text-success me-2"></i>Totals</h5>
                        <div class="mb-3">
                            <div class="h4 mb-1 text-success"><?php echo format_currency($feeSummary['total_paid']); ?></div>
                            <div class="text-muted small">Total Paid</div>
                        </div>
                        <div>
                            <div class="h5 mb-1 text-warning"><?php echo format_currency($feeSummary['total_pending']); ?></div>
                            <div class="text-muted small">Pending Balance</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card card-shadow">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="section-title mb-0"><i class="fas fa-receipt text-primary me-2"></i>Payments History</h5>
                        <span class="badge bg-primary"><?php echo count($feePayments); ?> records</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($feePayments)): ?>
                            <p class="text-muted mb-0">No fee payments have been recorded yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Academic Period</th>
                                            <th>Status</th>
                                            <th>Amount</th>
                                            <th>Payment Date</th>
                                            <th>Scholarship</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($feePayments as $payment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payment['semester_name'] . ' ' . $payment['year_name']); ?></td>
                                            <td>
                                                <?php
                                                $badgeMap = [
                                                    'paid' => 'success',
                                                    'pending' => 'warning',
                                                    'overdue' => 'danger',
                                                ];
                                                $status = strtolower($payment['status']);
                                                $badge = $badgeMap[$status] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $badge; ?> text-uppercase"><?php echo htmlspecialchars($payment['status']); ?></span>
                                            </td>
                                            <td><?php echo format_currency((float)$payment['amount']); ?></td>
                                            <td><?php echo $payment['payment_date'] ? format_date($payment['payment_date']) : '-'; ?></td>
                                            <td><?php echo (float)$payment['scholarship_amount'] > 0 ? format_currency((float)$payment['scholarship_amount']) : '<span class="text-muted">None</span>'; ?></td>
                                            <td><?php echo !empty($payment['notes']) ? htmlspecialchars($payment['notes']) : '<span class="text-muted">-</span>'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
