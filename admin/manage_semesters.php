<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$years = get_academic_years_admin();
$selectedYearId = isset($_GET['year_id']) ? (int)$_GET['year_id'] : (int)($years[0]['id'] ?? 0);

// Handle create/update submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/admin/manage_semesters.php?year_id=' . (int)($_POST['academic_year_id'] ?? 0));
    }
    // Recompute current term by today's date
    if (isset($_POST['refresh_current_term'])) {
        refresh_current_academic_term();
        set_flash_message('success', 'Current academic year and semester have been refreshed by today\'s date.');
        redirect('/admin/manage_semesters.php?year_id=' . (int)($_POST['academic_year_id'] ?? 0));
    }
    // Delete semester
    if (isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        $ayId = (int)($_POST['academic_year_id'] ?? 0);
        // Guards: cannot delete if referenced by course_sections or enrollments
        $ref = db_query_one('SELECT 
                (SELECT COUNT(*) FROM course_sections WHERE semester_id = ?) AS sec_count,
                (SELECT COUNT(*) FROM enrollments WHERE semester_id = ?) AS enr_count', [$delId,$delId]);
        if ($ref && ((int)$ref['sec_count'] > 0 || (int)$ref['enr_count'] > 0)) {
            set_flash_message('error', 'Cannot delete: semester has sections or enrollments.');
        } else {
            db_execute('DELETE FROM semesters WHERE id = ?', [$delId]);
            set_flash_message('success', 'Semester deleted.');
        }
        redirect('/admin/manage_semesters.php?year_id=' . $ayId);
    }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $academic_year_id = (int)($_POST['academic_year_id'] ?? 0);
    $semester_name = sanitize_input($_POST['semester_name'] ?? '');
    $start_date = sanitize_input($_POST['start_date'] ?? '');
    $end_date = sanitize_input($_POST['end_date'] ?? '');
    $registration_deadline = sanitize_input($_POST['registration_deadline'] ?? '') ?: null;
    $exam_period_start = sanitize_input($_POST['exam_period_start'] ?? '') ?: null;
    $exam_period_end = sanitize_input($_POST['exam_period_end'] ?? '') ?: null;
    $notes = sanitize_input($_POST['notes'] ?? '') ?: null;
    $is_current = isset($_POST['is_current']) && $_POST['is_current'] === '1';

    if ($id > 0) {
        $res = update_semester($id, $academic_year_id, $semester_name, $start_date, $end_date, $is_current, $registration_deadline, $exam_period_start, $exam_period_end, $notes);
    } else {
        $res = create_semester($academic_year_id, $semester_name, $start_date, $end_date, $is_current, $registration_deadline, $exam_period_start, $exam_period_end, $notes);
    }

    if ($res['success']) {
        set_flash_message('success', $res['message']);
    } else {
        set_flash_message('error', $res['message']);
    }

    redirect('/admin/manage_semesters.php?year_id=' . $academic_year_id);
}

// Prefill edit
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRecord = $editId > 0 ? get_semester_by_id($editId) : null;
$selectedYearId = $editRecord ? (int)$editRecord['academic_year_id'] : $selectedYearId;
$semesters = $selectedYearId ? get_semesters_by_year($selectedYearId) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Semesters - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body<?php if ($editRecord): ?> data-edit-modal="semesterEditModal" data-remove-param="id"<?php endif; ?>>
<?php include __DIR__ . '/header.php'; ?>
<main class="container py-4">
    <?php render_flash_messages(); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <form method="get" class="d-flex align-items-center gap-2">
            <label for="year_id" class="form-label mb-0">Academic Year</label>
            <select class="form-select" id="year_id" name="year_id">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int)$y['id']; ?>" <?php echo ((int)$y['id'] === $selectedYearId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($y['year_name']); ?> (<?php echo htmlspecialchars(format_date_display($y['start_date'])); ?> - <?php echo htmlspecialchars(format_date_display($y['end_date'])); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-primary" type="submit">Load</button>
        </form>
        <div class="d-flex align-items-center gap-2">
            <form method="post" class="d-inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedYearId; ?>">
                <button class="btn btn-outline-secondary" name="refresh_current_term" value="1" type="submit">Set Current Term by Date</button>
            </form>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#semesterAddModal">Add Semester</button>
        </div>
    </div>

    <div class="card card-shadow">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <?php
            $selectedYear = null;
            foreach ($years as $yearCandidate) {
                if ((int)$yearCandidate['id'] === $selectedYearId) {
                    $selectedYear = $yearCandidate;
                    break;
                }
            }
            ?>
            <h5 class="mb-0">
                Semesters in <?php echo htmlspecialchars($selectedYear['year_name'] ?? ''); ?>
            </h5>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-full-width align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Registration Deadline</th>
                        <th>Exam Period</th>
                        <th>Current</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($semesters as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['semester_name']); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars(format_date_display($s['start_date'])); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars(format_date_display($s['end_date'])); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars(format_date_display($s['registration_deadline'])); ?></td>
                        <td class="text-muted small">
                            <?php if (!empty($s['exam_period_start']) || !empty($s['exam_period_end'])): ?>
                                <?php
                                    $examStartLabel = !empty($s['exam_period_start']) ? format_date_display($s['exam_period_start']) : 'TBD';
                                    $examEndLabel = !empty($s['exam_period_end']) ? format_date_display($s['exam_period_end']) : 'TBD';
                                ?>
                                <?php echo htmlspecialchars($examStartLabel . ' - ' . $examEndLabel); ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <form method="GET" class="d-inline">
                                <input type="hidden" name="year_id" value="<?php echo (int)$selectedYearId; ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                <button class="btn btn-outline-secondary btn-sm">Edit</button>
                            </form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this semester?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedYearId; ?>">
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

    <!-- Add Modal: Semester -->
    <div class="modal fade" id="semesterAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Semester</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedYearId; ?>">
                        <div class="mb-3">
                            <label class="form-label">Semester</label>
                            <select name="semester_name" class="form-select" required>
                                <option value="First Semester">First Semester</option>
                                <option value="Second Semester">Second Semester</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Registration Deadline</label>
                            <input type="date" name="registration_deadline" class="form-control" value="">
                            <div class="form-text">Optional. Must be on or before the semester start date.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col">
                                <label class="form-label">Exam Period Start</label>
                                <input type="date" name="exam_period_start" class="form-control" value="">
                            </div>
                            <div class="col">
                                <label class="form-label">Exam Period End</label>
                                <input type="date" name="exam_period_end" class="form-control" value="">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Optional details (eg. orientation dates, remarks)"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="is_current_add" name="is_current" value="1">
                            <label class="form-check-label" for="is_current_add">Set as current semester for this year</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button class="btn btn-primary" type="submit">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal: Semester -->
    <div class="modal fade" id="semesterEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Semester</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <?php echo csrf_field(); ?>
                        <?php if ($editRecord): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editRecord['id']; ?>">
                        <?php endif; ?>
                        <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedYearId; ?>">
                        <div class="mb-3">
                            <label class="form-label">Semester</label>
                            <select name="semester_name" class="form-select" required>
                                <?php $name = $editRecord['semester_name'] ?? ''; ?>
                                <option value="First Semester" <?php echo ($name === 'First Semester') ? 'selected' : ''; ?>>First Semester</option>
                                <option value="Second Semester" <?php echo ($name === 'Second Semester') ? 'selected' : ''; ?>>Second Semester</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($editRecord['start_date'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($editRecord['end_date'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Registration Deadline</label>
                            <input type="date" name="registration_deadline" class="form-control" value="<?php echo htmlspecialchars($editRecord['registration_deadline'] ?? ''); ?>">
                            <div class="form-text">Optional. Must be on or before the semester start date.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col">
                                <label class="form-label">Exam Period Start</label>
                                <input type="date" name="exam_period_start" class="form-control" value="<?php echo htmlspecialchars($editRecord['exam_period_start'] ?? ''); ?>">
                            </div>
                            <div class="col">
                                <label class="form-label">Exam Period End</label>
                                <input type="date" name="exam_period_end" class="form-control" value="<?php echo htmlspecialchars($editRecord['exam_period_end'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Optional details (eg. orientation dates, remarks)"><?php echo htmlspecialchars($editRecord['notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="is_current_edit" name="is_current" value="1" <?php echo ($editRecord && (int)$editRecord['is_current'] === 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_current_edit">Set as current semester for this year</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button class="btn btn-primary" type="submit">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/footer.php'; ?>
