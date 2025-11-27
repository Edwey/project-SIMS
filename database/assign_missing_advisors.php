<?php
/**
 * Assign missing advisors to students (idempotent)
 * Usage (dry-run): php assign_missing_advisors.php
 * Apply changes: php assign_missing_advisors.php --apply
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Simple CLI options
$apply = false;
foreach ($argv as $arg) {
    if ($arg === '--apply') { $apply = true; }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php assign_missing_advisors.php [--apply]\n";
        echo "  --apply   : actually insert advisor assignments (dry-run otherwise)\n";
        exit(0);
    }
}

echo "Assign missing advisors - " . ($apply ? "APPLY mode" : "DRY RUN") . "\n";

// Load instructors
$instructors = db_query('SELECT id FROM instructors ORDER BY id ASC');
$instrList = array_map(function($r){ return (int)$r['id']; }, $instructors);
if (empty($instrList)) {
    echo "No instructors found. Nothing to do.\n";
    exit(0);
}

// Build current advisee counts
$counts = [];
foreach ($instrList as $iid) { $counts[$iid] = 0; }
$rows = db_query('SELECT instructor_id, COUNT(*) AS c FROM student_advisors WHERE is_active = 1 GROUP BY instructor_id');
foreach ($rows as $r) {
    $iid = (int)$r['instructor_id'];
    if (isset($counts[$iid])) { $counts[$iid] = (int)$r['c']; }
}

// Find students missing an active advisor
$missing = db_query("SELECT s.id, s.student_id, s.first_name, s.last_name
    FROM students s
    LEFT JOIN student_advisors sa ON sa.student_id = s.id AND sa.is_active = 1
    WHERE sa.id IS NULL
    ORDER BY s.id ASC");

$total = count($missing);
echo "Found $total students without active advisors.\n";
if ($total === 0) {
    exit(0);
}

$assigned = 0;
$appliedList = [];

// We'll pick instructor with smallest current count for each student, then increment count
foreach ($missing as $stu) {
    // choose instructor with minimum count
    asort($counts);
    $assignTo = (int)array_key_first($counts);

    $summary = sprintf("Student %s (%s %s) -> Instructor ID %d", $stu['student_id'], $stu['first_name'], $stu['last_name'], $assignTo);
    echo $summary . "\n";

    if ($apply) {
        db_execute('INSERT INTO student_advisors (student_id, instructor_id, assigned_date, is_active, created_at) VALUES (?, ?, CURDATE(), 1, NOW())', [(int)$stu['id'], $assignTo]);
        $assigned++;
        $appliedList[] = ['student_id' => (int)$stu['id'], 'instructor_id' => $assignTo];
    }

    // increment in-memory count to keep balance
    $counts[$assignTo]++;
}

echo "\n";
if ($apply) {
    echo "Applied assignments: $assigned\n";
} else {
    echo "Dry run complete. To apply these assignments run with --apply.\n";
}

exit(0);
