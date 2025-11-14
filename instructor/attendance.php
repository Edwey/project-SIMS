<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('instructor');

$current_page = 'attendance.php';
$instructorId = get_current_instructor_id();
if (!$instructorId) {
    set_flash_message('error', 'Your account is not linked to an instructor profile. Please contact admin.');
    $sections = [];
} else {
$sections = get_instructor_sections($instructorId);
}

$sectionId = null;
if (isset($_GET['section_id'])) {
    $sectionId = (int)$_GET['section_id'];
}
if (isset($_POST['section_id'])) {
    $sectionId = (int)$_POST['section_id'];
}

$selectedSection = null;
if ($sectionId && $instructorId) {
    $selectedSection = get_section_details($sectionId, $instructorId);
    if (!$selectedSection) {
        set_flash_message('error', 'You do not have access to that section.');
        redirect('/instructor/attendance.php');
    }
}

$dateFilter = isset($_GET['date']) ? $_GET['date'] : null;
if (isset($_POST['attendance_date'])) {
    $dateFilter = $_POST['attendance_date'];
}
if (!$dateFilter) {
    $dateFilter = date('Y-m-d');
}

if ($selectedSection && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/instructor/attendance.php?section_id=' . (int)$sectionId . '&date=' . urlencode($dateFilter));
    }
    if (isset($_POST['save_attendance'])) {
        $attendanceDate = $_POST['attendance_date'] ?? date('Y-m-d');
        foreach ($_POST['attendance'] as $enrollmentId => $status) {
            $notes = $_POST['notes'][$enrollmentId] ?? null;
            upsert_attendance_record((int)$enrollmentId, $attendanceDate, $status, $notes, $instructorId);
        }
        set_flash_message('success', 'Attendance saved for ' . format_date($attendanceDate) . '.');
        redirect('/instructor/attendance.php?section_id=' . $sectionId . '&date=' . urlencode($attendanceDate));
    }
}

$attendanceRecords = $selectedSection ? get_section_attendance_records($sectionId, $dateFilter) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <div class="card card-shadow mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label for="section_id" class="form-label">Select Course Section</label>
                        <select name="section_id" id="section_id" class="form-select" required>
                            <option value="">-- Choose a section --</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?php echo (int)$section['id']; ?>" <?php echo ($sectionId === (int)$section['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['course_code'] . ' - ' . $section['course_name']); ?>
                                    (<?php echo htmlspecialchars($section['section_name']); ?> · <?php echo htmlspecialchars($section['semester_name'] . ' ' . $section['year_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="date" class="form-label">Attendance Date</label>
                        <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($dateFilter); ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Load</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selectedSection): ?>
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="section-title mb-0"><?php echo htmlspecialchars($selectedSection['course_code'] . ' - ' . $selectedSection['course_name']); ?></h5>
                        <p class="text-muted small mb-0">
                            Section <?php echo htmlspecialchars($selectedSection['section_name']); ?> &middot;
                            <?php echo htmlspecialchars($selectedSection['semester_name'] . ' ' . $selectedSection['year_name']); ?> &middot;
                            Schedule: <?php echo htmlspecialchars($selectedSection['schedule']); ?>
                        </p>
                    </div>
                    <div>
                        <!-- quick summary actions could go here if needed -->
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($attendanceRecords)): ?>
                        <p class="text-muted mb-0">No enrollments found for this section.</p>
                    <?php else: ?>
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
                            <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($dateFilter); ?>">
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Status</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($attendanceRecords as $record): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></strong><br>
                                                <span class="text-muted small"><?php echo htmlspecialchars($record['student_id']); ?></span>
                                            </td>
                                            <td>
                                                <select name="attendance[<?php echo (int)$record['enrollment_id']; ?>]" class="form-select">
                                                    <?php
                                                    $options = ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'excused' => 'Excused'];
                                                    $currentStatus = $record['status'] ?? 'present';
                                                    foreach ($options as $value => $label):
                                                    ?>
                                                        <option value="<?php echo $value; ?>" <?php echo $currentStatus === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="notes[<?php echo (int)$record['enrollment_id']; ?>]" class="form-control" value="<?php echo htmlspecialchars($record['notes'] ?? ''); ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" name="save_attendance" class="btn btn-primary">Save Attendance</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="alert alert-info">Select a course section to manage attendance.</div>
        <?php endif; ?>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
