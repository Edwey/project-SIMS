<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Force error display for debugging on shared hosting
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {

    // Handle term switcher
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_current_term'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            set_flash_message('error', 'Invalid request token.');
            redirect('/admin/dashboard.php');
        }

        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $semesterId = (int)($_POST['semester_id'] ?? 0);

        if ($yearId > 0) {
            db_execute('UPDATE academic_years SET is_current = 0');
            db_execute('UPDATE academic_years SET is_current = 1 WHERE id = ?', [$yearId]);
        }
        if ($semesterId > 0) {
            db_execute('UPDATE semesters SET is_current = 0');
            db_execute('UPDATE semesters SET is_current = 1 WHERE id = ?', [$semesterId]);
        }

        set_flash_message('success', 'Current term updated.');
        redirect('/admin/dashboard.php');
    }

    // Simple stats
    $stats = [];
    $stats['users_total'] = (int)(db_query_one('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    $stats['users_admin'] = (int)(db_query_one("SELECT COUNT(*) AS c FROM users WHERE role='admin'")['c'] ?? 0);
    $stats['users_instructor'] = (int)(db_query_one("SELECT COUNT(*) AS c FROM users WHERE role='instructor'")['c'] ?? 0);
    $stats['users_student'] = (int)(db_query_one("SELECT COUNT(*) AS c FROM users WHERE role='student'")['c'] ?? 0);

    $stats['departments'] = (int)(db_query_one('SELECT COUNT(*) AS c FROM departments')['c'] ?? 0);
    $stats['courses'] = (int)(db_query_one('SELECT COUNT(*) AS c FROM courses')['c'] ?? 0);
    $stats['levels'] = (int)(db_query_one('SELECT COUNT(*) AS c FROM levels')['c'] ?? 0);
    $stats['academic_years'] = (int)(db_query_one('SELECT COUNT(*) AS c FROM academic_years')['c'] ?? 0);
    $stats['semesters'] = (int)(db_query_one('SELECT COUNT(*) AS c FROM semesters')['c'] ?? 0);

    $currentYear = db_query_one('SELECT id, year_name, start_date, end_date FROM academic_years WHERE is_current = 1 LIMIT 1');
    $currentSemester = db_query_one('SELECT id, semester_name, academic_year_id FROM semesters WHERE is_current = 1 LIMIT 1');

    $yearsAll = db_query('SELECT id, year_name FROM academic_years ORDER BY start_date DESC');
    $semestersForYear = $currentYear ? db_query('SELECT id, semester_name FROM semesters WHERE academic_year_id = ? ORDER BY id', [(int)$currentYear['id']]) : [];

    $recentLogs = db_query(
        'SELECT l.id, l.action, l.entity_type, l.entity_id, l.created_at, u.username
         FROM system_logs l LEFT JOIN users u ON l.user_id = u.id
         ORDER BY l.created_at DESC LIMIT 8'
    );

    // Scope "Top Courses" to current semester to avoid huge cross-joins on shared hosts
    $topCourses = db_query(
        'SELECT c.id, c.course_code, c.course_name, COUNT(e.id) AS enrolled
         FROM courses c
         LEFT JOIN course_sections cs ON cs.course_id = c.id
         LEFT JOIN enrollments e ON e.course_section_id = cs.id
         LEFT JOIN semesters sem ON sem.id = e.semester_id
         WHERE sem.is_current = 1
         GROUP BY c.id, c.course_code, c.course_name
         ORDER BY enrolled DESC
         LIMIT 5'
    );

    // Unassigned students count (no active advisor)
    $unassignedCount = db_query_one(
        "SELECT COUNT(s.id) AS cnt
         FROM students s
         LEFT JOIN student_advisors sa
           ON sa.student_id = s.id AND sa.is_active = 1
         WHERE sa.id IS NULL"
    );


    $pageTitle = 'Dashboard';
    include __DIR__ . '/header.php';
    render_flash_messages();
?>

    <div class="row g-4">
        <div class="col-12">
            <div class="card card-shadow">
                <div class="card-body d-flex flex-wrap gap-3">
                    <div class="stat-pill"><i class="fas fa-users"></i> Users: <?php echo $stats['users_total']; ?></div>
                    <div class="stat-pill"><i class="fas fa-user-shield"></i> Admins: <?php echo $stats['users_admin']; ?></div>
                    <div class="stat-pill"><i class="fas fa-chalkboard-teacher"></i> Instructors: <?php echo $stats['users_instructor']; ?></div>
                    <div class="stat-pill"><i class="fas fa-user-graduate"></i> Students: <?php echo $stats['users_student']; ?></div>
                    <div class="stat-pill"><i class="fas fa-building"></i> Departments: <?php echo $stats['departments']; ?></div>
                    <div class="stat-pill"><i class="fas fa-book"></i> Courses: <?php echo $stats['courses']; ?></div>
                    <div class="stat-pill"><i class="fas fa-layer-group"></i> Levels: <?php echo $stats['levels']; ?></div>
                    <div class="stat-pill"><i class="fas fa-calendar-alt"></i> Years: <?php echo $stats['academic_years']; ?></div>
                    <div class="stat-pill"><i class="fas fa-calendar"></i> Semesters: <?php echo $stats['semesters']; ?></div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card card-shadow mb-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="section-title mb-0"><i class="fas fa-gauge text-primary me-2"></i>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="/project/admin/manage_departments.php" class="btn btn-outline-primary"><i class="fas fa-building me-1"></i>Manage Departments</a>
                        <a href="/project/admin/manage_courses.php" class="btn btn-outline-primary"><i class="fas fa-book me-1"></i>Manage Courses</a>
                        <a href="/project/admin/manage_semesters.php" class="btn btn-outline-primary"><i class="fas fa-calendar me-1"></i>Manage Semesters</a>
                        <a href="/project/admin/manage_instructors.php?show_unassigned=1" class="btn btn-outline-info">
                            <i class="fas fa-user-slash"></i> View Unassigned Students (<?php echo (int)($unassignedCount['cnt'] ?? 0); ?>)
                        </a>
                    </div>
                </div>
            </div>

            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="section-title mb-0"><i class="fas fa-chart-bar text-success me-2"></i>Top Courses by Enrollment</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($topCourses)): ?>
                        <p class="text-muted mb-0">No course enrollment data yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($topCourses as $tc): ?>
                                <li class="border-bottom py-2 d-flex justify-content-between">
                                    <div>
                                        <span class="badge bg-primary me-2"><?php echo htmlspecialchars($tc['course_code']); ?></span>
                                        <?php echo htmlspecialchars($tc['course_name']); ?>
                                    </div>
                                    <div class="text-muted small"><?php echo (int)$tc['enrolled']; ?> enrolled</div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card card-shadow mb-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="section-title mb-0"><i class="fas fa-calendar-check text-info me-2"></i>Current Term</h5>
                </div>
                <div class="card-body">
                    <?php if (!$currentYear): ?>
                        <p class="text-muted mb-0">No current academic year set.</p>
                    <?php else: ?>
                        <div class="mb-2"><strong>Year:</strong> <?php echo htmlspecialchars($currentYear['year_name']); ?></div>
                        <div class="text-muted small mb-3">
                            <?php echo htmlspecialchars($currentYear['start_date']); ?> - <?php echo htmlspecialchars($currentYear['end_date']); ?>
                        </div>
                        <?php if ($currentSemester): ?>
                            <div><strong>Semester:</strong> <?php echo htmlspecialchars($currentSemester['semester_name']); ?></div>
                        <?php else: ?>
                            <div class="text-muted small">No current semester set.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <hr>
                    <form method="POST" class="row g-2 align-items-end">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="set_current_term" value="1">
                        <div class="col-12">
                            <label class="form-label">Set Academic Year</label>
                            <select name="academic_year_id" class="form-select">
                                <option value="0">-- Select Year --</option>
                                <?php foreach ($yearsAll as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>" <?php echo ($currentYear && (int)$currentYear['id']===(int)$y['id'])?'selected':''; ?>><?php echo htmlspecialchars($y['year_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Set Semester</label>
                            <select name="semester_id" class="form-select">
                                <option value="0">-- Select Semester --</option>
                                <?php foreach ($semestersForYear as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>" <?php echo ($currentSemester && (int)$currentSemester['id']===(int)$s['id'])?'selected':''; ?>><?php echo htmlspecialchars($s['semester_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary w-100">Set Current Term</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="section-title mb-0"><i class="fas fa-clock text-warning me-2"></i>Recent Activity</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentLogs)): ?>
                        <p class="text-muted mb-0">No recent activity.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach ($recentLogs as $log): ?>
                                <li class="border-bottom py-2">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($log['action']); ?></div>
                                    <div class="text-muted">by <?php echo htmlspecialchars($log['username'] ?? 'system'); ?> &middot; <?php echo htmlspecialchars($log['created_at']); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/footer.php'; ?>
<?php
} catch (Throwable $e) {
    echo '<pre>Dashboard error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}
?>
