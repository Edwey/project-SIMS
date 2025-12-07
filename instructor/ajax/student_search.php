<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/instructor_auth.php';

header('Content-Type: application/json');

$actorUserId = (int)current_user_id();
$deptId = get_instructor_department_id($actorUserId);

$q = trim($_GET['q'] ?? '');
$results = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $rows = db_query(
        "SELECT DISTINCT s.id, s.student_id, s.first_name, s.last_name, u.email, s.status,
                l.level_name, d.dept_name
         FROM students s
         JOIN users u ON u.id = s.user_id
         JOIN levels l ON l.id = s.current_level_id
         JOIN departments d ON d.id = s.department_id
         LEFT JOIN enrollments e ON e.student_id = s.id
         LEFT JOIN course_sections cs ON cs.id = e.course_section_id
         LEFT JOIN instructors inst ON inst.id = cs.instructor_id
         WHERE (
                (? IS NULL OR s.department_id = ?)
                OR inst.user_id = ?
           )
           AND (
                s.student_id LIKE ?
                OR u.email LIKE ?
                OR s.first_name LIKE ?
                OR s.last_name LIKE ?
                OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
           )
         ORDER BY s.last_name ASC, s.first_name ASC
         LIMIT 10",
        [$deptId, $deptId, $actorUserId, $like, $like, $like, $like, $like]
    );
    foreach ($rows as $r) {
        $results[] = [
            'id' => (int)$r['id'],
            'student_id' => (string)$r['student_id'],
            'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'email' => (string)($r['email'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
            'level' => (string)($r['level_name'] ?? ''),
            'department' => (string)($r['dept_name'] ?? '')
        ];
    }
}

echo json_encode(['results' => $results]);
