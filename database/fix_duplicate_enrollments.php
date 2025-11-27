<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$apply = in_array('--apply', $argv);
echo "Duplicate enrollment fixer\n";
echo $apply ? "Running in APPLY mode (will delete duplicates)\n" : "Dry run (no changes). Add --apply to remove duplicates.\n";

$dups = db_query(
    'SELECT e.student_id, s.student_id AS student_code, c.course_code, COUNT(*) AS cnt
     FROM enrollments e
     JOIN course_sections cs ON cs.id = e.course_section_id
     JOIN courses c ON c.id = cs.course_id
     JOIN students s ON s.id = e.student_id
     GROUP BY e.student_id, c.id
     HAVING cnt > 1'
);

if (empty($dups)) {
    echo "No duplicate enrollments found.\n";
    exit(0);
}

foreach ($dups as $d) {
    $studentId = (int)$d['student_id'];
    $studentCode = $d['student_code'];
    $courseCode = $d['course_code'];
    echo "\nFound duplicates for student $studentCode - $courseCode\n";

    $rows = db_query(
        'SELECT e.id, e.enrollment_date, e.final_grade, e.status, cs.id AS section_id, cs.semester_id, ay.year_name, s.semester_name
         FROM enrollments e
         JOIN course_sections cs ON cs.id = e.course_section_id
         JOIN semesters s ON s.id = cs.semester_id
         JOIN academic_years ay ON ay.id = cs.academic_year_id
         WHERE e.student_id = ? AND cs.course_id = (SELECT id FROM courses WHERE course_code = ? LIMIT 1)
         ORDER BY e.enrollment_date ASC, e.id ASC',
        [$studentId, $courseCode]
    );

    if (empty($rows)) continue;

    // Determine keep id: prefer an enrollment with final_grade not null, otherwise earliest by date
    $keep = null;
    foreach ($rows as $r) {
        if ($r['final_grade'] !== null && $r['final_grade'] !== '') {
            $keep = $r['id'];
            break;
        }
    }
    if ($keep === null) $keep = $rows[0]['id'];

    echo " Keeping enrollment id $keep (details):\n";
    foreach ($rows as $r) {
        $mark = ($r['id'] == $keep) ? '*' : ' ';
        echo sprintf(" %s id=%d date=%s grade=%s status=%s year=%s sem=%s\n", $mark, $r['id'], $r['enrollment_date'], $r['final_grade'] ?? '-', $r['status'], $r['year_name'], $r['semester_name']);
    }

    $toDelete = array_filter($rows, fn($r) => $r['id'] != $keep);
    if ($apply && !empty($toDelete)) {
        foreach ($toDelete as $drow) {
            // Delete grades, then enrollment (keep course_sections intact)
            db_execute('DELETE FROM grades WHERE enrollment_id = ?', [(int)$drow['id']]);
            db_execute('DELETE FROM enrollments WHERE id = ?', [(int)$drow['id']]);
            echo "  Deleted enrollment id " . (int)$drow['id'] . "\n";
        }
    } else {
        if (!empty($toDelete)) {
            echo "  Would delete enrollment ids: " . implode(', ', array_map(fn($r) => $r['id'], $toDelete)) . "\n";
        }
    }
}

echo "\nDone.\n";
