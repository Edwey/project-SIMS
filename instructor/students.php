<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/instructor_auth.php';

$actorUserId = (int)current_user_id();
$deptId = get_instructor_department_id($actorUserId);
if (!$deptId) { set_flash_message('error','Instructor profile not found.'); }

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'invite') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $sectionId = (int)($_POST['section_id'] ?? 0);
        // Get term from section
        $sec = db_query_one('SELECT semester_id, academic_year_id FROM course_sections WHERE id = ? LIMIT 1', [$sectionId]);
        if ($sec) {
            $res = create_enrollment_invite($actorUserId, $studentId, $sectionId, (int)$sec['semester_id'], (int)$sec['academic_year_id']);
            $messages[] = ($res['success'] ? 'success:' : 'error:') . ($res['message'] ?? '');
        } else {
            $messages[] = 'error:Section not found.';
        }
    } elseif ($action === 'manual_enroll') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $forceCap = isset($_POST['override_capacity']);
        $sec = db_query_one('SELECT semester_id, academic_year_id FROM course_sections WHERE id = ? LIMIT 1', [$sectionId]);
        if ($sec) {
            $res = enroll_student_manual($actorUserId, $studentId, $sectionId, (int)$sec['semester_id'], (int)$sec['academic_year_id'], $forceCap);
            $messages[] = ($res['success'] ? 'success:' : 'error:') . ($res['message'] ?? '');
        } else {
            $messages[] = 'error:Section not found.';
        }
    }
}

// Fetch instructor id
$instr = db_query_one('SELECT id, first_name, last_name FROM instructors WHERE user_id = ? LIMIT 1', [$actorUserId]);
$instrId = $instr ? (int)$instr['id'] : 0;

// Sections owned by instructor (current and future terms first)
$sections = db_query(
    'SELECT cs.id, cs.section_name, c.course_code, c.course_name, c.level_id, s.semester_name, ay.year_name
     FROM course_sections cs
     JOIN courses c ON c.id = cs.course_id
     JOIN semesters s ON s.id = cs.semester_id
     JOIN academic_years ay ON ay.id = cs.academic_year_id
     WHERE cs.instructor_id = ?
     ORDER BY c.course_code, cs.section_name',
    [$instrId]
);

// Students currently in instructor's sections
$students_in_my_sections = db_query(
    'SELECT DISTINCT st.id, st.student_id AS sid_code, st.first_name, st.last_name
     FROM enrollments e
     JOIN students st ON st.id = e.student_id
     JOIN course_sections cs ON cs.id = e.course_section_id
     WHERE cs.instructor_id = ? AND e.status = "enrolled"
     ORDER BY st.last_name, st.first_name',
    [$instrId]
);

// Department students
$dept_students = [];
if ($deptId) {
    $dept_students = db_query(
        'SELECT st.id, st.student_id AS sid_code, st.first_name, st.last_name
         FROM students st
         WHERE st.department_id = ?
         ORDER BY st.last_name, st.first_name',
        [$deptId]
    );
}

// Department students not in my sections
$student_ids_in_my_sections = array_map(fn($r) => (int)$r['id'], $students_in_my_sections);
$dept_not_in_my = array_values(array_filter($dept_students, function($r) use ($student_ids_in_my_sections){
    return !in_array((int)$r['id'], $student_ids_in_my_sections, true);
}));

function filter_sections_for_student(array $sections, int $studentId): array {
    $student = db_query_one('SELECT s.current_level_id FROM students s WHERE s.id = ? LIMIT 1', [$studentId]);
    if (!$student || empty($student['current_level_id'])) {
        return $sections;
    }
    $requiredLevelId = (int)$student['current_level_id'];
    return array_values(array_filter($sections, function($section) use ($requiredLevelId) {
        $sectionLevelId = isset($section['level_id']) ? (int)$section['level_id'] : 0;
        if ($sectionLevelId === 0) {
            return true;
        }
        return $sectionLevelId === $requiredLevelId;
    }));
}

function render_student_row($st, $sections) {
    $filteredSections = filter_sections_for_student($sections, (int)$st['id']);
    ?>
    <tr>
      <td><?= htmlspecialchars($st['sid_code'] . ' - ' . $st['first_name'] . ' ' . $st['last_name']) ?></td>
      <td>
        <form class="d-inline" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="invite">
          <input type="hidden" name="student_id" value="<?= (int)$st['id'] ?>">
          <select name="section_id" class="form-select form-select-sm d-inline-block" style="width:280px" required>
            <option value="">Invite to section...</option>
            <?php foreach ($filteredSections as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?php
                $label = sprintf('%s · %s · %s · %s %s',
                    $s['course_code'],
                    $s['course_name'],
                    $s['section_name'],
                    $s['semester_name'],
                    $s['year_name']
                );
                echo htmlspecialchars($label);
              ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-primary btn-sm" type="submit">Invite</button>
        </form>
        <form class="d-inline ms-2" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="manual_enroll">
          <input type="hidden" name="student_id" value="<?= (int)$st['id'] ?>">
          <select name="section_id" class="form-select form-select-sm d-inline-block" style="width:280px" required>
            <option value="">Enroll to section...</option>
            <?php foreach ($filteredSections as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?php
                $label = sprintf('%s · %s · %s · %s %s',
                    $s['course_code'],
                    $s['course_name'],
                    $s['section_name'],
                    $s['semester_name'],
                    $s['year_name']
                );
                echo htmlspecialchars($label);
              ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-check form-check-inline ms-2">
            <input class="form-check-input" type="checkbox" name="override_capacity" id="ov<?= (int)$st['id'] ?>">
            <label class="form-check-label" for="ov<?= (int)$st['id'] ?>">Override capacity</label>
          </div>
          <button class="btn btn-outline-success btn-sm" type="submit">Enroll now</button>
        </form>
      </td>
    </tr>
    <?php
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Students (Instructor)</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
  <meta name="app-base-url" content="<?= htmlspecialchars(rtrim(SITE_URL, '/'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <?php $current_page = 'students.php'; include __DIR__ . '/navbar.php'; ?>
  <main class="container py-4">
    <h3 class="mb-3">Students</h3>

    <div class="card card-shadow mb-4">
      <div class="card-header bg-white border-0 py-3">
        <h5 class="mb-0">Quick Add to Section</h5>
      </div>
      <div class="card-body">
        <form method="post" class="row g-3 align-items-end" id="quickAddForm">
          <?= csrf_field() ?>
          <input type="hidden" name="student_id" id="qa_student_id" value="">
          <div class="col-md-6">
            <label class="form-label">Search student (name, code, or email)</label>
            <div class="position-relative">
              <input type="text" class="form-control" id="qa_search" autocomplete="off" placeholder="Start typing...">
              <div class="list-group position-absolute w-100 shadow-sm d-none" id="qa_results" style="z-index:1050; max-height: 240px; overflow:auto;"></div>
            </div>
            <div class="form-text">Only students in your department are shown.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Section</label>
            <select name="section_id" class="form-select" required>
              <option value="">Select section…</option>
              <?php foreach ($sections as $s): ?>
                <option value="<?= (int)$s['id'] ?>" data-level="<?= (int)($s['level_id'] ?? 0) ?>"><?php
                  $label = sprintf('%s · %s · %s · %s %s',
                      $s['course_code'],
                      $s['course_name'],
                      $s['section_name'],
                      $s['semester_name'],
                      $s['year_name']
                  );
                  echo htmlspecialchars($label);
                ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-outline-primary flex-fill" type="submit" name="action" value="invite">Invite</button>
            <button class="btn btn-success flex-fill" type="submit" name="action" value="manual_enroll">Enroll</button>
          </div>
        </form>
      </div>
    </div>
    <h4 class="mb-3">My Students</h4>

    <?php foreach ($messages as $m): $type = str_starts_with($m, 'success:') ? 'success' : 'danger'; $text = substr($m, strpos($m, ':')+1); ?>
      <div class="alert alert-<?= $type ?>"><?= htmlspecialchars($text) ?></div>
    <?php endforeach; ?>

    <div class="border-start border-end border-bottom p-3 mt-2">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light"><tr><th>Student</th><th style="width:420px">Actions</th></tr></thead>
          <tbody>
            <?php if (empty($students_in_my_sections)): ?>
              <tr><td colspan="2" class="text-muted">No students currently in your sections.</td></tr>
            <?php else: foreach ($students_in_my_sections as $st) { render_student_row($st, $sections); } endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
  <script src="../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/js/instructor_students.js"></script>
</body>
</html>
