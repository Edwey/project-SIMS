<?php
require_once __DIR__ . '/../includes/functions.php';
require_auth('admin');

$appId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$app = get_application_by_id($appId);
if (!$app) {
    set_flash_message('error', 'Application not found.');
    redirect('/admin/admissions.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/admin/admission_detail.php?id=' . (int)$appId);
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            add_application_note($appId, current_user_id(), $note);
            set_flash_message('success', 'Note added.');
        }
        redirect('/admin/admission_detail.php?id=' . (int)$appId);
    }
}

$notes = get_application_notes($appId);
$pageTitle = 'Application #' . (int)$appId;
include __DIR__ . '/header.php';
render_flash_messages();
?>

<div class="card card-shadow mb-3">
  <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Application Detail</h5>
    <a class="btn btn-sm btn-outline-secondary" href="/project/admin/admissions.php">Back to list</a>
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-6">
        <h6>Applicant</h6>
        <dl class="row mb-0">
          <dt class="col-sm-4">Name</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></dd>
          <dt class="col-sm-4">Email</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['prospect_email']); ?></dd>
          <dt class="col-sm-4">Phone</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['phone'] ?? ''); ?></dd>
        </dl>
      </div>
      <div class="col-md-6">
        <h6>Program</h6>
        <dl class="row mb-0">
          <dt class="col-sm-4">Program</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['program_name']); ?></dd>
          <dt class="col-sm-4">Aggregate</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['wasse_aggregate'] ?? ''); ?></dd>
          <dt class="col-sm-4">Cutoff</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['cutoff_aggregate'] ?? ''); ?></dd>
        </dl>
      </div>
    </div>

    <hr>

    <div class="row g-4">
      <div class="col-md-6">
        <h6>Status</h6>
        <dl class="row mb-0">
          <dt class="col-sm-4">Current</dt>
          <dd class="col-sm-8"><span class="badge bg-secondary"><?php echo htmlspecialchars(str_replace('_',' ',$app['status'])); ?></span></dd>
          <dt class="col-sm-4">Submitted</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['submitted_at']); ?></dd>
          <dt class="col-sm-4">Decided</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['decided_at'] ?? ''); ?></dd>
          <dt class="col-sm-4">Reason</dt>
          <dd class="col-sm-8"><?php echo htmlspecialchars($app['decided_reason'] ?? ''); ?></dd>
        </dl>
      </div>
      <div class="col-md-6">
        <h6>Actions</h6>
        <form method="POST" class="d-inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="app_id" value="<?php echo (int)$appId; ?>">
          <button name="action" value="under_review" formaction="/project/admin/admissions.php" class="btn btn-outline-secondary btn-sm">Mark Under Review</button>
        </form>
        <form method="POST" class="d-inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="app_id" value="<?php echo (int)$appId; ?>">
          <input type="hidden" name="offer_notes" value="Congratulations - Offer of Admission">
          <button name="action" value="offer" formaction="/project/admin/admissions.php" class="btn btn-outline-primary btn-sm">Offer</button>
        </form>
        <form method="POST" class="d-inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="app_id" value="<?php echo (int)$appId; ?>">
          <button name="action" value="accept" formaction="/project/admin/admissions.php" class="btn btn-success btn-sm">Accept</button>
        </form>
        <form method="POST" class="d-inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="app_id" value="<?php echo (int)$appId; ?>">
          <button name="action" value="reject" formaction="/project/admin/admissions.php" class="btn btn-danger btn-sm">Reject</button>
        </form>
      </div>
    </div>

    <hr>

    <div class="row g-4">
      <div class="col-md-6">
        <h6>Internal Notes</h6>
        <form method="POST" class="mb-3">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="add_note">
          <div class="input-group">
            <input type="text" name="note" class="form-control" placeholder="Add a note...">
            <button class="btn btn-primary" type="submit">Add</button>
          </div>
        </form>
        <ul class="list-group">
          <?php if (empty($notes)): ?>
            <li class="list-group-item text-muted">No notes yet.</li>
          <?php else: foreach ($notes as $n): $nv = json_decode($n['new_values'], true); ?>
            <li class="list-group-item">
              <div class="small text-muted"><?php echo htmlspecialchars($n['created_at']); ?></div>
              <div><?php echo htmlspecialchars($nv['note'] ?? ''); ?></div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
