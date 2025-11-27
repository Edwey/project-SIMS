<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Usage: php debug_student_transcript.php STU00023
$arg = $argv[1] ?? null;
if (!$arg) {
    echo "Usage: php debug_student_transcript.php <student_code_or_internal_id>\n";
    exit(1);
}

// Resolve student by student_id code or internal numeric id
if (is_numeric($arg)) {
    $student = db_query_one('SELECT id, student_id, first_name, last_name FROM students WHERE id = ? LIMIT 1', [(int)$arg]);
} else {
    $student = db_query_one('SELECT id, student_id, first_name, last_name FROM students WHERE student_id = ? LIMIT 1', [$arg]);
}

if (!$student) {
    echo "Student not found: $arg\n";
    exit(1);
}

echo "Student: {$student['first_name']} {$student['last_name']} ({$student['student_id']})\n";
echo str_repeat('=', 80) . "\n";

$rows = db_query(
    'SELECT e.id AS enrollment_id, c.course_code, c.course_name, c.credits, e.final_grade, e.grade_points, e.status, e.enrollment_date, s.semester_name, ay.year_name, s.start_date AS sem_start
     FROM enrollments e
     JOIN course_sections cs ON cs.id = e.course_section_id
     JOIN courses c ON c.id = cs.course_id
     JOIN semesters s ON s.id = e.semester_id
     JOIN academic_years ay ON ay.id = e.academic_year_id
     WHERE e.student_id = ?
     ORDER BY ay.start_date ASC, s.start_date ASC',
    [$student['id']]
);

if (empty($rows)) {
    echo "No enrollments found for this student.\n";
    exit(0);
}

$duplicates = [];
$seen = [];
foreach ($rows as $r) {
    $key = $r['course_code'];
    $semLabel = $r['year_name'] . ' - ' . $r['semester_name'];
    if (!isset($seen[$key])) $seen[$key] = [];
    $seen[$key][] = $semLabel;
}
foreach ($seen as $course => $places) {
    if (count($places) > 1) {
        $duplicates[$course] = $places;
    }
}

// Print enrollments grouped by academic year + semester
$grouped = [];
foreach ($rows as $r) {
    $groupKey = $r['year_name'] . '|' . $r['semester_name'];
    $grouped[$groupKey][] = $r;
}

foreach ($grouped as $group => $items) {
    list($year, $sem) = explode('|', $group);
    echo "\n$year - $sem\n";
    echo str_repeat('-', 40) . "\n";
    printf("%-10s %-40s %-6s %-6s %-10s\n", 'Code', 'Course', 'Creds', 'Grade', 'Status');
    foreach ($items as $it) {
        printf("%-10s %-40s %-6s %-6s %-10s\n", $it['course_code'], $it['course_name'], $it['credits'], $it['final_grade'] ?? '-', $it['status']);
    }
}

if (!empty($duplicates)) {
    echo "\nDuplicate course occurrences detected for this student:\n";
    foreach ($duplicates as $course => $places) {
        echo " - $course: " . implode(', ', $places) . "\n";
    }
}

echo "\n(End)\n";
