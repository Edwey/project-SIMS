<?php
require_once __DIR__ . '/../includes/instructor_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'waitlists.php';
$instructor = current_user_id();

$ensure = function() { ensure_waitlists_table(); };
$ensure();

$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token.');
        redirect('/instructor/waitlists.php' . ($sectionId ? ('?section_id=' . $sectionId) : ''));
    }

    // Instructors can only act on their own sections
    $own = db_query_one('SELECT id, semester_id, academic_year_id FROM course_sections WHERE id = ? AND instructor_id = (SELECT id FROM instructors WHERE user_id = ?) LIMIT 1', [$sectionId, $instructor]);
    if (!$own) {
        set_flash_message('error', 'You are not authorized for this section.');
        redirect('/instructor/waitlists.php');
    }

    if (isset($_POST['promote_next'])) {
        $ok = promote_next_from_waitlist($sectionId, (int)$own['semester_id'], (int)$own['academic_year_id']);
        set_flash_message($ok ? 'success' : 'error', $ok ? 'Promoted next student from waitlist.' : 'Unable to promote (no seat available or error).');
        redirect('/instructor/waitlists.php?section_id=' . $sectionId);
    }

    if (isset($_POST['remove_entry']) && isset($_POST['waitlist_id'])) {
        $wlId = (int)$_POST['waitlist_id'];
        db_execute('DELETE FROM waitlists WHERE id = ? AND course_section_id = ?', [$wlId, $sectionId]);
        set_flash_message('success', 'Removed from waitlist.');
        redirect('/instructor/waitlists.php?section_id=' . $sectionId);
    }
}

// Sections owned by instructor that have waitlists
$ensure();
$mySections = db_query("SELECT cs.id, cs.section_name, c.course_code, c.course_name, COUNT(w.id) AS wl_count
    FROM course_sections cs
    JOIN instructors i ON cs.instructor_id = i.id
    JOIN courses c ON cs.course_id = c.id
    LEFT JOIN waitlists w ON w.course_section_id = cs.id
    WHERE i.user_id = ?
    GROUP BY cs.id, cs.section_name, c.course_code, c.course_name
    HAVING wl_count > 0
    ORDER BY wl_count DESC, c.course_code", [current_user_id()]);

$entries = [];
$sectionMeta = null;
if ($sectionId > 0) {
    $sectionMeta = db_query_one("SELECT cs.id, cs.section_name, cs.capacity, cs.enrolled_count, c.course_code, c.course_name
        FROM course_sections cs JOIN courses c ON cs.course_id = c.id WHERE cs.id = ?", [$sectionId]);
    $entries = get_section_waitlist($sectionId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Waitlists - Instructor</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="container py-4">
  <?php render_flash_messages(); ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card card-shadow">
        <div class="card-header bg-white border-0 py-3"><h5 class="mb-0">My Sections with Waitlists</h5></div>
        <div class="card-body">
          <?php if (empty($mySections)): ?>
            <p class="text-muted mb-0">No waitlists for your sections.</p>
          <?php else: ?>
            <ul class="list-unstyled mb-0">
            <?php foreach ($mySections as $s): ?>
              <li class="border-bottom py-2 d-flex justify-content-between align-items-center">
                <div>
                  <span class="badge bg-primary me-2"><?php echo htmlspecialchars($s['course_code']); ?></span>
                  <?php echo htmlspecialchars($s['course_name']); ?>
                  <span class="text-muted small">&middot; Section <?php echo htmlspecialchars($s['section_name']); ?></span>
                </div>
                <div>
                  <span class="badge bg-warning text-dark me-2"><?php echo (int)$s['wl_count']; ?> waiting</span>
                  <a class="btn btn-sm btn-outline-secondary" href="<?php echo SITE_URL; ?>/instructor/waitlists.php?section_id=<?php echo (int)$s['id']; ?>">Manage</a>
                </div>
              </li>
            <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card card-shadow">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Waitlist Details</h5>
          <?php if ($sectionMeta): ?>
            <form method="POST" class="d-inline">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="promote_next" value="1">
              <button class="btn btn-primary btn-sm">Promote Next</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!$sectionMeta): ?>
            <p class="text-muted mb-0">Select a section to view and manage its waitlist.</p>
          <?php else: ?>
            <div class="mb-3">
              <span class="badge bg-primary me-2"><?php echo htmlspecialchars($sectionMeta['course_code']); ?></span>
              <?php echo htmlspecialchars($sectionMeta['course_name']); ?>
              <span class="text-muted small">&middot; Section <?php echo htmlspecialchars($sectionMeta['section_name']); ?></span>
              <div class="small text-muted">Capacity <?php echo (int)$sectionMeta['capacity']; ?> · Enrolled <?php echo (int)$sectionMeta['enrolled_count']; ?></div>
            </div>

            <?php if (empty($entries)): ?>
              <p class="text-muted mb-0">No students on waitlist for this section.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead><tr><th>Student</th><th>Student ID</th><th>Requested</th><th class="text-end"></th></tr></thead>
                  <tbody>
                    <?php foreach ($entries as $e): ?>
                      <tr>
                        <td><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($e['sid_code']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($e['requested_at']); ?></td>
                        <td class="text-end">
                          <form method="POST" class="d-inline" onsubmit="return confirm('Remove this entry from waitlist?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="waitlist_id" value="<?php echo (int)$e['id']; ?>">
                            <button class="btn btn-link text-danger btn-sm" name="remove_entry" value="1">Remove</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
