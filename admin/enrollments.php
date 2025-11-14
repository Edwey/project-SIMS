<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_auth.php';

// Simple semester selector
$semesters = db_query('SELECT s.id, s.semester_name, ay.year_name FROM semesters s JOIN academic_years ay ON ay.id = s.academic_year_id ORDER BY ay.start_date DESC, s.start_date DESC');
$today = date('Y-m-d');
$semesterByDate = db_query_one('SELECT s.id FROM semesters s WHERE ? BETWEEN s.start_date AND s.end_date ORDER BY s.start_date LIMIT 1', [$today]);
$semesterByFlag = db_query_one('SELECT s.id FROM semesters s WHERE s.is_current = 1 LIMIT 1');
$defaultSemesterId = 0;
if ($semesterByDate && isset($semesterByDate['id'])) {
    $defaultSemesterId = (int)$semesterByDate['id'];
} elseif ($semesterByFlag && isset($semesterByFlag['id'])) {
    $defaultSemesterId = (int)$semesterByFlag['id'];
} elseif (!empty($semesters)) {
    $defaultSemesterId = (int)$semesters[0]['id'];
}

$semesterId = (int)($_GET['semester_id'] ?? $defaultSemesterId);
if ($semesterId <= 0) {
    $semesterId = $defaultSemesterId;
}

$messages = [];
$pageScripts = [
    '../assets/js/student_autocomplete.js',
    '../assets/js/admin_enrollments.js'
];

// Handle add/remove/move
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        // Only allow: (a) selection from suggestions (resolved_student_id) OR (b) exact student code
        $studentId = 0;
        $resolved = (int)($_POST['resolved_student_id'] ?? 0);
        $studentCode = trim((string)($_POST['student_code'] ?? ''));
        $studentQuery = trim((string)($_POST['student_query'] ?? ''));

        if ($resolved > 0) {
            $studentId = $resolved;
        } elseif ($studentCode !== '') {
            $row = db_query_one('SELECT id FROM students WHERE student_id = ? LIMIT 1', [$studentCode]);
            if ($row) { $studentId = (int)$row['id']; }
        } else {
            $messages[] = 'error:Please select a student from suggestions or enter an exact Student Code.';
        }
        if ($resolved === 0 && $studentCode === '' && $studentQuery !== '') {
            // User typed but did not choose
            $messages[] = 'error:You typed a name/email, but you must click a suggestion to select a student.';
        }
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $semId = (int)($_POST['sem_id'] ?? 0);
        $ayId = (int)($_POST['ay_id'] ?? 0);
        $override = isset($_POST['override_capacity']);
        if ($studentId > 0) {
            $res = enroll_student_manual((int)current_user_id(), $studentId, $sectionId, $semId, $ayId, $override);
            $messages[] = ($res['success'] ? 'success:' : 'error:') . ($res['message'] ?? '');
        }
    } elseif ($action === 'remove') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $res = drop_enrollment($studentId, $sectionId);
        $messages[] = ($res['success'] ? 'success:' : 'error:') . ($res['message'] ?? '');
    } elseif ($action === 'move') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $fromSectionId = (int)($_POST['from_section_id'] ?? 0);
        $toSectionId = (int)($_POST['to_section_id'] ?? 0);
        // Load toSection term
        $to = db_query_one('SELECT semester_id, academic_year_id FROM course_sections WHERE id = ? LIMIT 1', [$toSectionId]);
        if ($to) {
            $drop = drop_enrollment($studentId, $fromSectionId);
            if ($drop['success']) {
                $add = enroll_student_manual((int)current_user_id(), $studentId, $toSectionId, (int)$to['semester_id'], (int)$to['academic_year_id'], true);
                $messages[] = ($add['success'] ? 'success:' : 'error:') . ($add['message'] ?? '');
            } else {
                $messages[] = 'error:' . ($drop['message'] ?? 'Unable to move enrollment.');
            }
        }
    }
}

// Fetch sections for selected semester
$sections = db_query(
    'SELECT cs.id, cs.section_name, cs.capacity, cs.enrolled_count,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_section_id = cs.id AND e.status = "enrolled") AS enrolled_live,
            c.course_code, c.course_name, i.first_name, i.last_name, ay.id AS ay_id, s.id AS sem_id
     FROM course_sections cs
     JOIN courses c ON c.id = cs.course_id
     JOIN instructors i ON i.id = cs.instructor_id
     JOIN semesters s ON s.id = cs.semester_id
     JOIN academic_years ay ON ay.id = cs.academic_year_id
     WHERE cs.semester_id = ?
     ORDER BY c.course_code, cs.section_name',
     [$semesterId]
);

function get_section_students($sectionId) {
    return db_query(
        'SELECT e.student_id, s.student_id AS sid_code, s.first_name, s.last_name
         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         WHERE e.course_section_id = ? AND e.status = "enrolled"
         ORDER BY s.last_name, s.first_name',
        [$sectionId]
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Enrollments</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
  <meta name="app-base-url" content="<?= htmlspecialchars(rtrim(SITE_URL, '/'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>
  <main class="container py-4">
    <?php render_flash_messages(); ?>
    <h3 class="mb-3">Manage Enrollments</h3>

    <?php foreach ($messages as $m): $type = str_starts_with($m, 'success:') ? 'success' : 'danger'; $text = substr($m, strpos($m, ':')+1); ?>
      <div class="alert alert-<?= $type ?>"><?= htmlspecialchars($text) ?></div>
    <?php endforeach; ?>

    <form class="row g-2 align-items-end mb-3" method="get">
      <div class="col-auto">
        <label class="form-label">Semester</label>
        <select name="semester_id" class="form-select">
          <?php foreach ($semesters as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $semesterId ? 'selected' : '') ?>><?= htmlspecialchars($s['year_name'] . ' - ' . $s['semester_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary" type="submit">Go</button>
      </div>
    </form>

    <div class="accordion" id="sectionsAcc">
      <?php foreach ($sections as $sec): $list = get_section_students((int)$sec['id']);
            $liveCount = isset($sec['enrolled_live']) ? (int)$sec['enrolled_live'] : count($list);
      ?>
        <div class="accordion-item mb-2">
          <h2 class="accordion-header" id="h<?= (int)$sec['id'] ?>">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c<?= (int)$sec['id'] ?>">
              <div class="w-100 d-flex justify-content-between">
                <div>
                  <strong><?= htmlspecialchars($sec['course_code']) ?> - <?= htmlspecialchars($sec['course_name']) ?></strong>
                  <span class="ms-2 badge bg-secondary">Section <?= htmlspecialchars($sec['section_name']) ?></span>
                  <span class="ms-2 text-muted small">Instructor: <?= htmlspecialchars($sec['first_name'] . ' ' . $sec['last_name']) ?></span>
                </div>
                <div>
                  <span class="badge bg-info">Capacity: <?= (int)$sec['capacity'] ?></span>
                  <span class="badge bg-success">Enrolled: <?= $liveCount ?></span>
                  <?php if (isset($sec['enrolled_count']) && (int)$sec['enrolled_count'] !== $liveCount): ?>
                    <span class="badge bg-warning text-dark">Stored: <?= (int)$sec['enrolled_count'] ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </button>
          </h2>
          <div id="c<?= (int)$sec['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#sectionsAcc">
            <div class="accordion-body">
              <div class="mb-3">
                <form class="row g-2 align-items-end enroll-add-form" method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="section_id" value="<?= (int)$sec['id'] ?>">
                  <input type="hidden" name="sem_id" value="<?= (int)$sec['sem_id'] ?>">
                  <input type="hidden" name="ay_id" value="<?= (int)$sec['ay_id'] ?>">
                  <input type="hidden" name="resolved_student_id" class="resolved-student-id" value="">
                  <div class="col-md-3">
                    <label class="form-label small mb-1">Student Code</label>
                    <input type="text" class="form-control" name="student_code" placeholder="e.g. 2024CS0001">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label small mb-1">Email or Name</label>
                    <div class="position-relative d-flex gap-2">
                      <div class="flex-grow-1 position-relative">
                        <input type="text" class="form-control student-autocomplete" autocomplete="off" name="student_query" placeholder="Type 2+ characters..." data-section="<?= (int)$sec['id'] ?>">
                        <div class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 1050; max-height: 240px; overflow: auto;"></div>
                      </div>
                      <button type="button" class="btn btn-outline-secondary student-search-btn">Search</button>
                    </div>
                    <div class="form-text">Start typing name or email; pick from suggestions or enter a student code.</div>
                  </div>
                  <div class="col-auto form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="override_capacity" id="ov<?= (int)$sec['id'] ?>" checked>
                    <label class="form-check-label" for="ov<?= (int)$sec['id'] ?>">Override capacity</label>
                  </div>
                  <div class="col-auto">
                    <button class="btn btn-primary" type="submit">Add Student</button>
                  </div>
                </form>
              </div>

              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>Student</th>
                      <th style="width: 280px">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($list)): ?>
                      <tr><td colspan="2" class="text-muted">No students enrolled.</td></tr>
                    <?php else: foreach ($list as $st): ?>
                      <tr>
                        <td><?= htmlspecialchars($st['sid_code'] . ' - ' . $st['first_name'] . ' ' . $st['last_name']) ?></td>
                        <td>
                          <form class="d-inline" method="post" onsubmit="return confirm('Remove this student?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="student_id" value="<?= (int)$st['student_id'] ?>">
                            <input type="hidden" name="section_id" value="<?= (int)$sec['id'] ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit">Remove</button>
                          </form>
                          <form class="d-inline ms-2" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="student_id" value="<?= (int)$st['student_id'] ?>">
                            <input type="hidden" name="from_section_id" value="<?= (int)$sec['id'] ?>">
                            <input type="number" class="form-control form-control-sm d-inline-block" name="to_section_id" placeholder="Move to section ID" style="width:160px" required>
                            <button class="btn btn-outline-secondary btn-sm" type="submit">Move</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
  <?php include __DIR__ . '/footer.php'; ?>
  
