<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_auth.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$results = [];
if ($q !== '') {
    // Search by student_id exact/like, email like, or name like
    $like = '%' . $q . '%';
    $rows = db_query(
        "SELECT s.id, s.student_id, s.first_name, s.last_name, s.email, s.status,
                l.level_name, d.dept_name
         FROM students s
         JOIN levels l ON l.id = s.current_level_id
         JOIN departments d ON d.id = s.department_id
         WHERE s.student_id LIKE ? OR s.email LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?
         ORDER BY s.last_name ASC, s.first_name ASC
         LIMIT 10",
        [$like, $like, $like, $like]
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
