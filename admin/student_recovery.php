<?php
/**
 * Student Recovery Page
 * Allows admins to fix students with missing enrollments
 */

require_once '../includes/database.php';
require_once '../includes/functions.php';

// Check authorization
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$message = '';
$messageType = '';

// Handle recovery action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'auto_enroll' && isset($_POST['student_id'])) {
        $studentId = (int)$_POST['student_id'];
        $result = auto_enroll_student_in_program_courses($studentId);
        $messageType = $result['success'] ? 'success' : 'danger';
        $message = $result['message'];
    } elseif ($_POST['action'] === 'promote' && isset($_POST['student_id'])) {
        $studentId = (int)$_POST['student_id'];
        $result = promote_student_to_next_level($studentId);
        $messageType = $result['success'] ? 'success' : 'danger';
        $message = $result['message'];
    }
}

// Get students with no current enrollments
$studentsWithoutCourses = db_query(
    "SELECT s.id, s.student_id, s.first_name, s.last_name, p.program_name, l.level_name, l.level_order,
            COUNT(e.id) as enrollment_count
     FROM students s
     LEFT JOIN programs p ON s.program_id = p.id
     LEFT JOIN levels l ON s.current_level_id = l.id
     LEFT JOIN enrollments e ON s.id = e.student_id 
        AND e.academic_year_id = (SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1)
        AND e.semester_id = (SELECT id FROM semesters WHERE is_current = 1 LIMIT 1)
     WHERE s.status = 'active'
     GROUP BY s.id
     HAVING enrollment_count = 0
     ORDER BY l.level_order DESC, s.created_at DESC"
);

include 'header.php';
?>

<div class="container mt-4">
    <h2>Student Recovery</h2>
    <p class="text-muted">Fix students with missing course enrollments</p>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5>Students Without Current Enrollments</h5>
        </div>
        <div class="card-body">
            <?php if (empty($studentsWithoutCourses)): ?>
                <p class="text-success">✓ All students have current course enrollments!</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Program</th>
                                <th>Level</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentsWithoutCourses as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['level_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="auto_enroll">
                                            <input type="hidden" name="student_id" value="<?php echo (int)$student['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                Enroll in Level <?php echo (int)$student['level_order'] * 100; ?> Courses
                                            </button>
                                        </form>
                                        <?php if ((int)$student['level_order'] < 4): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="promote">
                                                <input type="hidden" name="student_id" value="<?php echo (int)$student['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    Promote to Level <?php echo ((int)$student['level_order'] + 1) * 100; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
