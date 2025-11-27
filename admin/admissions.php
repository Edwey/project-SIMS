<?php
require_once __DIR__ . '/../includes/functions.php';
require_auth('admin');

$purgeDays = defined('ADMISSIONS_PURGE_DAYS') ? (int)ADMISSIONS_PURGE_DAYS : 7;
purge_accepted_applications($purgeDays);

$status = $_GET['status'] ?? '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize = 10;
$credentialsToDisplay = null;

// Fetch programs for filter dropdown
$programs = get_programs();

// Search + pagination
$search = search_applications_admin($status ?: null, $q !== '' ? $q : null, ($programId && $programId > 0) ? $programId : null, $page, $pageSize);
$apps = $search['rows'];
$total = $search['total'];
$pages = $search['pages'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/admissions.php');
    }
    $appId = (int)($_POST['app_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $offerNotes = $_POST['offer_notes'] ?? null;
    $map = [
        'under_review' => 'under_review',
        'offer' => 'offered',
        'accept' => 'accepted',
        'reject' => 'rejected',
    ];

    // Bulk actions
    if (isset($_POST['bulk_action']) && isset($map[$_POST['bulk_action']])) {
        $ids = array_map('intval', $_POST['selected_ids'] ?? []);
        $ids = array_values(array_filter($ids, fn($v) => $v > 0));
        if (empty($ids)) {
            set_flash_message('error', 'Select at least one application.');
        } else {
            $act = $_POST['bulk_action'];
            $ok = 0; $fail = 0;
            foreach ($ids as $id) {
                $res = set_application_status($id, $map[$act], $offerNotes);
                if (!empty($res['success'])) { $ok++; } else { $fail++; }
            }
            set_flash_message('success', "Bulk action complete: {$ok} updated, {$fail} failed.");
        }
        $redir = '/admin/admissions.php?status=' . urlencode($status);
        if ($q !== '') { $redir .= '&q=' . urlencode($q); }
        if ($programId) { $redir .= '&program_id=' . (int)$programId; }
        $redir .= '&page=' . (int)$page;
        redirect($redir);
    }

    // Single row actions
    if ($appId > 0 && isset($map[$action])) {
        $res = set_application_status($appId, $map[$action], $offerNotes);
        if ($action === 'accept' && !empty($res['username']) && !empty($res['password'])) {
            $credentialsToDisplay = $res;
            set_flash_message('success', $res['message'] . ' <strong>Username:</strong> ' . htmlspecialchars($res['username']) . ' <strong>Password:</strong> ' . htmlspecialchars($res['password']));
        } else {
            set_flash_message($res['success'] ? 'success' : 'error', $res['message']);
        }
        if (!$credentialsToDisplay) {
            $redir = '/admin/admissions.php?status=' . urlencode($status);
            if ($q !== '') { $redir .= '&q=' . urlencode($q); }
            if ($programId) { $redir .= '&program_id=' . (int)$programId; }
            $redir .= '&page=' . (int)$page;
            redirect($redir);
        }
    }
}
$pageTitle = 'Admissions';
include __DIR__ . '/header.php';
render_flash_messages();
?>

<div class="card card-shadow mb-3">
  <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Admissions</h5>
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <?php foreach (['applied','under_review','offered','accepted','rejected'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $status===$s?'selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$s)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="program_id" class="form-select form-select-sm">
          <option value="">All Programs</option>
          <?php foreach ($programs as $p): ?>
            <option value="<?php echo (int)$p['id']; ?>" <?php echo ($programId && (int)$p['id']===$programId)?'selected':''; ?>><?php echo htmlspecialchars($p['program_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <input type="text" class="form-control form-control-sm" name="q" placeholder="Search name/email/program" value="<?php echo htmlspecialchars($q); ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-primary" type="submit">Apply</button>
      </div>
    </form>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <form method="POST">
      <?php echo csrf_field(); ?>
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th><input type="checkbox" id="chk_all" onclick="document.querySelectorAll('.chk-app').forEach(cb=>cb.checked=this.checked);"></th>
            <th>#</th>
            <th>Applicant</th>
            <th>Email</th>
            <th>Program</th>
            <th>Aggregate</th>
            <th>Cutoff</th>
            <th>Status</th>
            <th>Reason</th>
            <th>Submitted</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($apps)): ?>
            <tr><td colspan="11" class="text-center text-muted">No applications</td></tr>
          <?php else: foreach ($apps as $a): ?>
          <tr>
            <td><input type="checkbox" class="form-check-input chk-app" name="selected_ids[]" value="<?php echo (int)$a['id']; ?>"></td>
            <td><?php echo (int)$a['id']; ?></td>
            <td><a href="/project/admin/admission_detail.php?id=<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?></a></td>
            <td><?php echo htmlspecialchars($a['prospect_email']); ?></td>
            <td><?php echo htmlspecialchars($a['program_name']); ?></td>
            <td><?php echo htmlspecialchars($a['wasse_aggregate'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($a['cutoff_aggregate'] ?? ''); ?></td>
            <td><span class="badge bg-secondary"><?php echo htmlspecialchars(str_replace('_',' ',$a['status'])); ?></span></td>
            <td class="small text-muted"><?php echo htmlspecialchars($a['decided_reason'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($a['submitted_at']); ?></td>
            <td class="text-end">
                <form method="POST" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="app_id" value="<?php echo (int)$a['id']; ?>">
                  <button name="action" value="under_review" class="btn btn-outline-secondary btn-sm">Review</button>
                </form>
                <form method="POST" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="app_id" value="<?php echo (int)$a['id']; ?>">
                  <input type="hidden" name="offer_notes" value="Congratulations - Offer of Admission">
                  <button name="action" value="offer" class="btn btn-outline-primary btn-sm">Offer</button>
                </form>
                <form method="POST" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="app_id" value="<?php echo (int)$a['id']; ?>">
                  <button name="action" value="accept" class="btn btn-success btn-sm">Accept</button>
                </form>
                <form method="POST" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="app_id" value="<?php echo (int)$a['id']; ?>">
                  <button name="action" value="reject" class="btn btn-danger btn-sm">Reject</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <div class="d-flex gap-2 align-items-center">
        <select name="bulk_action" class="form-select form-select-sm" style="max-width: 220px;">
          <option value="">Bulk action...</option>
          <option value="under_review">Mark Under Review</option>
          <option value="offer">Offer</option>
          <option value="accept">Accept</option>
          <option value="reject">Reject</option>
        </select>
        <input type="text" name="offer_notes" class="form-control form-control-sm" placeholder="Offer notes (optional)" style="max-width: 280px;">
        <button type="submit" class="btn btn-sm btn-primary">Apply to selected</button>
      </div>
      </form>
    </div>
    <?php if ($pages > 1): ?>
      <nav>
        <ul class="pagination pagination-sm">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <li class="page-item <?php echo $i===$page?'active':''; ?>">
              <a class="page-link" href="?status=<?php echo urlencode($status); ?>&program_id=<?php echo (int)$programId; ?>&q=<?php echo urlencode($q); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>

<?php if ($credentialsToDisplay): ?>
<!-- Credentials Modal -->
<div class="modal fade" id="credentialsModal" tabindex="-1" aria-hidden="false" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Student Account Created</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong>Application accepted!</strong> A new student account has been created with the following credentials:</p>
        <div class="alert alert-info">
          <p class="mb-1"><strong>Email:</strong> <code><?php echo htmlspecialchars($credentialsToDisplay['email']); ?></code></p>
          <p class="mb-1"><strong>Username:</strong> <code><?php echo htmlspecialchars($credentialsToDisplay['username']); ?></code></p>
          <p class="mb-0"><strong>Temporary Password:</strong> <code><?php echo htmlspecialchars($credentialsToDisplay['password']); ?></code></p>
        </div>
        <p class="text-muted small">These credentials have been sent to the student's email. They will be prompted to change their password on first login.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
