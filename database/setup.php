<?php
/**
 * Database Setup Script 
 *This script expects your existing helper functions and DB wrapper:
 * - includes/database.php
 * - includes/functions.php
 * And the helper functions used below:
 * - db_execute($sql, $params = [])
 * - db_query_one($sql, $params = [])
 * - db_query($sql, $params = [])
 * - db_last_id()
 * - hash_password($plain)
 * - generate_student_id($year, $dept_code, $seq)
 * - enroll_student($student_id, $course_section_id, $semester_id, $academic_year_id)
 *
 * Run only after verifying you have a backup (this script is idempotent but it inserts real demo data).
 */

require_once '../includes/database.php';
require_once '../includes/functions.php';

ini_set('max_execution_time', 0); // disable timeout for this script
ignore_user_abort(true);

echo "<h1> University - Database Setup </h1>";

function execute_sql_file($filename) {
    $sql = file_get_contents($filename);

    // Remove block comments and inline comments
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*#.*$/m', '', $sql);

    // Split into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (empty($statement)) {
            continue;
        }

        // Skip comments
        if (preg_match('/^(--|\/\*)/', $statement)) {
            continue;
        }

        // Block destructive statements to avoid data loss when re-running setup
        $statementTrim = ltrim($statement);
        $statementUpper = strtoupper($statementTrim);
        if (strpos($statementUpper, 'DROP ') === 0) {
            echo "↷ Skipped destructive statement: " . htmlspecialchars(substr($statementTrim, 0, 70)) . "...<br>";
            continue;
        }

        try {
            // Handle CREATE DATABASE separately
            if (strpos($statementUpper, 'CREATE DATABASE') === 0) {
                db_execute($statement);
                echo "✓ Executed: " . substr($statement, 0, 70) . "...<br>";

                // After creating the database, select it
                $db = Database::getInstance();
                $db->selectDatabase(DB_NAME);
                echo "✓ Switched to database: " . DB_NAME . "<br>";
                continue;
            }

            // Handle USE statements by selecting database
            if (strpos($statementUpper, 'USE ') === 0) {
                $parts = preg_split('/\s+/', $statement);
                $dbName = trim($parts[1], "`\"'");
                $db = Database::getInstance();
                $db->selectDatabase($dbName);
                echo "✓ Switched to database: " . $dbName . "<br>";
                continue;
            }

            db_execute($statement);
            echo "✓ Executed: " . substr($statement, 0, 70) . "...<br>";

        } catch (Exception $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                echo "✗ Failed: " . htmlspecialchars($e->getMessage()) . "<br>";
            } else {
                if (function_exists('log_exception')) { log_exception($e, 'SQL statement failed during setup'); }
                echo "✗ Failed executing a statement. See logs for details.<br>";
            }
        }
    }
}

try {
    echo "<h2>Creating Database Schema...</h2>";
    execute_sql_file('../database/schema.sql');

    // Post-schema hardening: ensure new columns/tables exist even on older DBs
    echo "<h3>Applying post-schema migrations...</h3>";
    try {
        db_execute('ALTER TABLE notifications ADD COLUMN sender_user_id INT NULL');
    } catch (Exception $e) { /* likely exists */ }
    try {
        db_execute('ALTER TABLE users ADD COLUMN graduation_lock_at DATETIME NULL AFTER locked_until');
    } catch (Exception $e) { /* may already exist */ }
    try {
        db_execute('ALTER TABLE notifications ADD CONSTRAINT fk_notifications_sender FOREIGN KEY (sender_user_id) REFERENCES users(id)');
    } catch (Exception $e) { /* may already exist */ }
    try {
        db_execute('CREATE INDEX idx_notifications_sender ON notifications(sender_user_id)');
    } catch (Exception $e) { /*  */ }
    try {
        db_execute("CREATE TABLE IF NOT EXISTS waitlists (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            course_section_id INT NOT NULL,
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_section (student_id, course_section_id),
            FOREIGN KEY (student_id) REFERENCES students(id),
            FOREIGN KEY (course_section_id) REFERENCES course_sections(id)
        ) ENGINE=InnoDB");
    } catch (Exception $e) { /* */ }
    try {
        db_execute('ALTER TABLE students ADD CONSTRAINT fk_students_program FOREIGN KEY (program_id) REFERENCES programs(id)');
    } catch (Exception $e) { /* */ }
    try {
        db_execute('ALTER TABLE semesters ADD COLUMN registration_deadline DATE NULL');
    } catch (Exception $e) { /* */ }
    try {
        db_execute('ALTER TABLE semesters ADD COLUMN exam_period_start DATE NULL');
    } catch (Exception $e) { /* */ }
    try {
        db_execute('ALTER TABLE semesters ADD COLUMN exam_period_end DATE NULL');
    } catch (Exception $e) { /* */ }
    try {
        db_execute('ALTER TABLE semesters ADD COLUMN notes TEXT NULL');
    } catch (Exception $e) { /* */ }
    // must_change_password flag on users
    try {
        db_execute('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
    } catch (Exception $e) { /* */ }
    try {
        db_execute('ALTER TABLE semesters ADD CONSTRAINT uniq_semester_per_year UNIQUE (academic_year_id, semester_name)');
    } catch (Exception $e) { /* */ }

    echo "<h2>Inserting Sample Data...</h2>";

    // ------------------------------
    // Academic years (4 years)
    // ------------------------------
    echo "<h3>Academic Years...</h3>";
    db_execute("INSERT IGNORE INTO academic_years (id, year_name, start_date, end_date, is_current, created_at) VALUES
               (1, '2022-2023', '2022-08-01', '2023-07-31', 0, NOW()),
               (2, '2023-2024', '2023-08-01', '2024-07-31', 0, NOW()),
               (3, '2024-2025', '2024-08-01', '2025-07-31', 1, NOW()),
               (4, '2025-2026', '2025-08-01', '2026-07-31', 0, NOW())");

    // Ensure current year set
    db_execute("UPDATE academic_years SET is_current = (year_name = '2024-2025')");

    // ------------------------------
    // Semesters (Fall, Spring, Summer) for each academic year (safe inserts)
    // ------------------------------
    echo "<h3>Semesters...</h3>";
    $ayrs = db_query("SELECT id, year_name FROM academic_years");
    foreach ($ayrs as $yr) {
        // compute approximate dates based on the year string
        $startYear = intval(substr($yr['year_name'], 0, 4));
        $first_start = "{$startYear}-08-01";
        $first_end = "{$startYear}-12-31";
        $second_start = ($startYear + 1) . "-01-01";
        $second_end = ($startYear + 1) . "-05-31";

        $registration_deadline = date('Y-m-d', strtotime($first_start . ' -14 days'));
        $exam_start = date('Y-m-d', strtotime($first_end . ' -21 days'));
        $exam_end = date('Y-m-d', strtotime($first_end . ' -7 days'));
        $second_registration_deadline = date('Y-m-d', strtotime($second_start . ' -14 days'));
        $second_exam_start = date('Y-m-d', strtotime($second_end . ' -21 days'));
        $second_exam_end = date('Y-m-d', strtotime($second_end . ' -7 days'));

        db_execute(
            "INSERT INTO semesters (semester_name, academic_year_id, start_date, end_date, is_current, registration_deadline, exam_period_start, exam_period_end, notes, created_at)
             VALUES
               ('First Semester', ?, ?, ?, 0, ?, ?, ?, 'Orientation week in first month.', NOW()),
               ('Second Semester', ?, ?, ?, 0, ?, ?, ?, 'Includes mid-year internship fair.', NOW())
             ON DUPLICATE KEY UPDATE
               start_date = VALUES(start_date),
               end_date = VALUES(end_date),
               registration_deadline = VALUES(registration_deadline),
               exam_period_start = VALUES(exam_period_start),
               exam_period_end = VALUES(exam_period_end),
               notes = VALUES(notes)",
            [
                $yr['id'], $first_start, $first_end, $registration_deadline, $exam_start, $exam_end,
                $yr['id'], $second_start, $second_end, $second_registration_deadline, $second_exam_start, $second_exam_end
            ]
        );
    }

    // Set First Semester for 2024-2025 as current semester
    $current_ay = db_query_one("SELECT id FROM academic_years WHERE year_name = '2024-2025'");
    if ($current_ay) {
        db_execute("UPDATE semesters SET is_current = CASE WHEN semester_name = 'First Semester' AND academic_year_id = ? THEN 1 ELSE 0 END", [$current_ay['id']]);
    }

    $current_semester_id = null;
    if ($current_ay) {
        // Prefer semester covering today's date; fallback to current flag; then earliest
        $today = date('Y-m-d');
        $current_semester = db_query_one("SELECT id FROM semesters WHERE academic_year_id = ? AND ? BETWEEN start_date AND end_date LIMIT 1", [$current_ay['id'], $today]);
        if (!$current_semester) {
            $current_semester = db_query_one("SELECT id FROM semesters WHERE academic_year_id = ? AND is_current = 1 LIMIT 1", [$current_ay['id']]);
        }
        if (!$current_semester) {
            $current_semester = db_query_one("SELECT id FROM semesters WHERE academic_year_id = ? ORDER BY start_date LIMIT 1", [$current_ay['id']]);
        }

        if ($current_semester) {
            $current_semester_id = (int)$current_semester['id'];
        } else {
            echo "⚠️ Unable to determine current semester for academic year {$current_ay['id']}.\n";
        }
    }

    // ------------------------------
    // Departments (9 departments)
    // ------------------------------
    echo "<h3>Departments...</h3>";
    db_execute("INSERT IGNORE INTO departments (dept_code, dept_name, dept_head, description, created_at) VALUES
               ('CS', 'Computer Science', 'Dr. Samuel Owusu', 'Department of Computer Science and Software Engineering', NOW()),
               ('IT', 'Information Technology', 'Dr. Nana Akua Ofori', 'Department of Information Technology and Systems', NOW()),
               ('EE', 'Electrical Engineering', 'Dr. Kofi Boateng', 'Department of Electrical and Electronics Engineering', NOW()),
               ('ME', 'Mechanical Engineering', 'Dr. Agnes Tetteh', 'Department of Mechanical Engineering', NOW()),
               ('BA', 'Business Administration', 'Dr. Adom Mensah', 'Department of Business Administration and Management', NOW()),
               ('NS', 'Nursing', 'Dr. Afua Serwaa', 'Department of Nursing and Health Sciences', NOW()),
               ('LAW', 'Law', 'Prof. Yaw Agyapong', 'Faculty of Law', NOW()),
               ('EDU', 'Education', 'Dr. Yaa Asantewaa', 'Faculty of Education', NOW()),
               ('AGR', 'Agriculture', 'Dr. Kojo Agyemang', 'Faculty of Agriculture', NOW())");

    // Programs (seed after departments so admissions/apply shows options even if schema-time seed skipped)
    echo "<h3>Programs...</h3>";
    $deptCS  = db_query_one("SELECT id FROM departments WHERE dept_code = 'CS'");
    $deptIT  = db_query_one("SELECT id FROM departments WHERE dept_code = 'IT'");
    $deptEE  = db_query_one("SELECT id FROM departments WHERE dept_code = 'EE'");
    $deptME  = db_query_one("SELECT id FROM departments WHERE dept_code = 'ME'");
    $deptBA  = db_query_one("SELECT id FROM departments WHERE dept_code = 'BA'");
    $deptNS  = db_query_one("SELECT id FROM departments WHERE dept_code = 'NS'");
    $deptLAW = db_query_one("SELECT id FROM departments WHERE dept_code = 'LAW'");
    $deptEDU = db_query_one("SELECT id FROM departments WHERE dept_code = 'EDU'");
    $deptAGR = db_query_one("SELECT id FROM departments WHERE dept_code = 'AGR'");
    if ($deptCS) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BSC-CS','B.Sc. Computer Science', ?, 120, NOW()),
                   ('BSC-SWE','B.Sc. Software Engineering', ?, 120, NOW()),
                   ('BSC-DS','B.Sc. Data Science', ?, 120, NOW()),
                   ('BSC-CYBER','B.Sc. Cybersecurity', ?, 120, NOW())",
                   [$deptCS['id'], $deptCS['id'], $deptCS['id'], $deptCS['id']]);
    }
    if ($deptIT) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BSC-IT','B.Sc. Information Technology', ?, 120, NOW()),
                   ('BSC-IS','B.Sc. Information Systems', ?, 120, NOW()),
                   ('BSC-NET','B.Sc. Networking & Telecommunications', ?, 120, NOW())",
                   [$deptIT['id'], $deptIT['id'], $deptIT['id']]);
    }
    if ($deptEE) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BSC-EE','B.Sc. Electrical Engineering', ?, 120, NOW()),
                   ('BSC-CE','B.Sc. Computer Engineering', ?, 120, NOW())",
                   [$deptEE['id'], $deptEE['id']]);
    }
    if ($deptME) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BSC-ME','B.Sc. Mechanical Engineering', ?, 120, NOW()),
                   ('BSC-IE','B.Sc. Industrial Engineering', ?, 120, NOW())",
                   [$deptME['id'], $deptME['id']]);
    }
    if ($deptBA) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BBA','B.B.A. Business Administration', ?, 120, NOW()),
                   ('BBA-ACC','B.B.A. Accounting', ?, 120, NOW()),
                   ('BBA-MKT','B.B.A. Marketing', ?, 120, NOW()),
                   ('BSC-FIN','B.Sc. Finance', ?, 120, NOW()),
                   ('BBA-HRM','B.B.A. Human Resource Management', ?, 120, NOW())",
                   [$deptBA['id'], $deptBA['id'], $deptBA['id'], $deptBA['id'], $deptBA['id']]);
    }
    if ($deptNS) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BSC-NS','B.Sc. Nursing', ?, 120, NOW()),
                   ('BSC-MID','B.Sc. Midwifery', ?, 120, NOW()),
                   ('BSC-PHN','B.Sc. Public Health Nursing', ?, 120, NOW())",
                   [$deptNS['id'], $deptNS['id'], $deptNS['id']]);
    }
    if ($deptLAW) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('LLB','Bachelor of Laws (LLB)', ?, 120, NOW()),
                   ('BA-LAW','B.A. Legal Studies', ?, 120, NOW())",
                   [$deptLAW['id'], $deptLAW['id']]);
    }
    if ($deptEDU) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BED','B.Ed. Education', ?, 120, NOW()),
                   ('BED-PRIM','B.Ed. Primary Education', ?, 120, NOW()),
                   ('BED-MATH','B.Ed. Mathematics Education', ?, 120, NOW()),
                   ('BED-SCI','B.Ed. Science Education', ?, 120, NOW()),
                   ('BED-ICT','B.Ed. ICT Education', ?, 120, NOW())",
                   [$deptEDU['id'], $deptEDU['id'], $deptEDU['id'], $deptEDU['id'], $deptEDU['id']]);
    }
    if ($deptAGR) {
        db_execute("INSERT IGNORE INTO programs (program_code, program_name, department_id, total_credits, created_at) VALUES
                   ('BSC-AGR','B.Sc. Agriculture', ?, 120, NOW()),
                   ('BSC-AGB','B.Sc. Agribusiness', ?, 120, NOW()),
                   ('BSC-ANSC','B.Sc. Animal Science', ?, 120, NOW()),
                   ('BSC-CROP','B.Sc. Crop Science', ?, 120, NOW())",
                   [$deptAGR['id'], $deptAGR['id'], $deptAGR['id'], $deptAGR['id']]);
    }
    echo "✓ Programs seeded (if departments exist)<br>";

    // Set default cutoff aggregates if missing
    try {
        if ($deptCS) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 12) WHERE program_code = 'BSC-CS'");
        }
        if ($deptIT) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 15) WHERE program_code IN ('BSC-IT','BSC-IS','BSC-NET')");
        }
        if ($deptEE) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 13) WHERE program_code = 'BSC-EE'");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 12) WHERE program_code = 'BSC-CE'");
        }
        if ($deptME) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 13) WHERE program_code = 'BSC-ME'");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 14) WHERE program_code = 'BSC-IE'");
        }
        if ($deptBA) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 18) WHERE program_code IN ('BBA','BBA-MKT','BBA-HRM')");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 14) WHERE program_code IN ('BBA-ACC','BSC-FIN')");
        }
        if ($deptNS) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 16) WHERE program_code = 'BSC-NS'");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 12) WHERE program_code = 'BSC-MID'");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 13) WHERE program_code = 'BSC-PHN'");
        }
        if ($deptLAW) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 10) WHERE program_code = 'LLB'");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 14) WHERE program_code = 'BA-LAW'");
        }
        if ($deptEDU) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 20) WHERE program_code IN ('BED','BED-PRIM')");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 18) WHERE program_code IN ('BED-MATH','BED-SCI','BED-ICT')");
        }
        if ($deptAGR) {
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 18) WHERE program_code IN ('BSC-AGR','BSC-AGB')");
            db_execute("UPDATE programs SET cutoff_aggregate = COALESCE(cutoff_aggregate, 20) WHERE program_code IN ('BSC-ANSC','BSC-CROP')");
        }
        echo "✓ Program cutoff aggregates set<br>";
    } catch (Exception $e) { /*  */ }

    // ------------------------------
    // Levels
    // ------------------------------
    echo "<h3>Academic Levels...</h3>";
    db_execute("INSERT IGNORE INTO levels (id, level_name, level_order, created_at) VALUES
               (1, 'Level 100', 1, NOW()),
               (2, 'Level 200', 2, NOW()),
               (3, 'Level 300', 3, NOW()),
               (4, 'Level 400', 4, NOW())");

    // ------------------------------
    // Courses (initial + extended)
    // ------------------------------
    echo "<h3>Courses...</h3>";
    $courses = [
        // code, name, dept_code, level_order, credits, description
        ['CS101','Introduction to Programming','CS',1,3,'Intro to programming using Python'],
        ['CS102','Computer Systems','CS',1,3,'Principles of computer hardware and OS'],
        ['CS201','Data Structures','CS',2,4,'Algorithms and data structures'],
        ['CS301','Database Systems','CS',3,3,'Database design, SQL, normalization'],
        ['CS401','Software Engineering','CS',4,3,'Software lifecycle and project work'],

        ['IT101','Foundations of IT','IT',1,3,'Fundamentals of IT systems'],
        ['IT201','Networking Basics','IT',2,3,'Network models and basic configuration'],
        ['IT301','Web Development','IT',3,3,'Front-end and back-end web development'],

        ['EE101','Circuit Analysis','EE',1,4,'Basic circuit laws and techniques'],
        ['EE201','Digital Systems','EE',2,3,'Logic design and digital circuits'],
        ['ME101','Engineering Mechanics','ME',1,4,'Statics and dynamics basics'],
        ['ME201','Thermodynamics','ME',2,3,'Energy systems and thermal principles'],

        ['BA101','Principles of Management','BA',1,3,'Intro to management and organizations'],
        ['BA201','Financial Accounting','BA',2,3,'Intro to accounting principles'],
        ['BA202','Marketing Management','BA',2,3,'Principles of marketing and market research'],

        ['NS101','Human Anatomy I','NS',1,4,'Anatomy for nursing - part I'],
        ['NS201','Patient Care Fundamentals','NS',2,3,'Foundations of clinical nursing care'],

        ['LAW101','Intro to Law','LAW',1,3,'Foundations of legal systems'],
        ['EDU101','Foundations of Education','EDU',1,3,'Philosophy and methods of education'],

        ['AGR101','Agronomy I','AGR',1,3,'Introduction to crop production'],
        ['AGR201','Animal Husbandry','AGR',2,3,'Basics of livestock management']
    ];

    foreach ($courses as $c) {
        $dept = db_query_one("SELECT id FROM departments WHERE dept_code = ?", [$c[2]]);
        $lvl = db_query_one("SELECT id FROM levels WHERE level_order = ?", [$c[3]]);
        if ($dept && $lvl) {
            db_execute("INSERT IGNORE INTO courses (course_code, course_name, department_id, level_id, credits, description, created_at) VALUES
                       (?, ?, ?, ?, ?, ?, NOW())", [$c[0], $c[1], $dept['id'], $lvl['id'], $c[4], $c[5]]);
        }
    }

    // ------------------------------
    // Program course requirements & prerequisites
    // ------------------------------
    echo "<h3>Program Requirements...</h3>";

    $programRows = db_query("SELECT id, program_code FROM programs");
    $programCodeToId = [];
    foreach ($programRows as $progRow) {
        $programCodeToId[$progRow['program_code']] = (int)$progRow['id'];
    }

    $courseRows = db_query("SELECT id, course_code FROM courses");
    $courseCodeToId = [];
    foreach ($courseRows as $courseRow) {
        $courseCodeToId[$courseRow['course_code']] = (int)$courseRow['id'];
    }

    $seedRequirements = [
        'BSC-CS' => [
            ['CS101', 1, 1],
            ['CS102', 1, 1],
            ['CS201', 2, 1],
            ['CS301', 3, 1],
            ['CS401', 4, 1],
            ['IT301', 4, 0]
        ],
        'BSC-IT' => [
            ['IT101', 1, 1],
            ['IT201', 2, 1],
            ['IT301', 3, 1],
            ['CS101', 1, 0]
        ],
        'BBA' => [
            ['BA101', 1, 1],
            ['BA201', 2, 1],
            ['BA202', 2, 0],
            ['IT101', 3, 0]
        ],
        'BSC-EE' => [
            ['EE101', 1, 1],
            ['EE201', 2, 1],
            ['CS101', 1, 0]
        ],
        'BSC-AGR' => [
            ['AGR101', 1, 1],
            ['AGR201', 2, 1],
            ['ME201', 3, 0]
        ]
    ];

    foreach ($seedRequirements as $programCode => $entries) {
        $programId = $programCodeToId[$programCode] ?? null;
        if (!$programId) {
            echo "⚠️ Skipping requirements seed for program {$programCode} (program not found).<br>";
            continue;
        }

        foreach ($entries as [$courseCode, $termNumber, $requiredFlag]) {
            $courseId = $courseCodeToId[$courseCode] ?? null;
            if (!$courseId) {
                echo "⚠️ Skipping course {$courseCode} for program {$programCode} (course not found).<br>";
                continue;
            }

            db_execute(
                "INSERT IGNORE INTO program_courses (program_id, course_id, term_number, required, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$programId, $courseId, $termNumber, $requiredFlag]
            );
        }
        echo "✓ Requirements seeded for program {$programCode}<br>";
    }

    $seedPrereqs = [
        'CS201' => ['CS101'],
        'CS301' => ['CS201'],
        'CS401' => ['CS301'],
        'IT301' => ['IT201'],
        'EE201' => ['EE101']
    ];

    foreach ($seedPrereqs as $courseCode => $prereqCodes) {
        $courseId = $courseCodeToId[$courseCode] ?? null;
        if (!$courseId) {
            echo "⚠️ Skipping prerequisites for {$courseCode} (course not found).<br>";
            continue;
        }

        foreach ($prereqCodes as $prereqCode) {
            $prereqId = $courseCodeToId[$prereqCode] ?? null;
            if (!$prereqId) {
                echo "⚠️ Skipping prerequisite {$prereqCode} for {$courseCode} (course not found).<br>";
                continue;
            }

            db_execute(
                "INSERT IGNORE INTO course_prerequisites (course_id, prereq_course_id, created_at)
                 VALUES (?, ?, NOW())",
                [$courseId, $prereqId]
            );
        }
    }
    echo "✓ Program requirements and prerequisites seeded<br>";

    // ------------------------------
    // s: Admin + instructor accounts 
    // ------------------------------
    echo "<h3>Admin & Instructors...</h3>";
    
    $admin = db_query_one("SELECT id FROM users WHERE username = 'vt_admin'");
    if (!$admin) {
        $pwd = hash_password('VoltaAdmin@123');
        db_execute("INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES
                   ('vt_admin', 'oboysika000@gmail.com', ?, 'admin', 1, NOW())", [$pwd]);
        echo "✓ Admin account created (vt_admin / VoltaAdmin@123)<br>";
    } else {
        echo "✓ Admin account exists<br>";
    }

    // instructors list (username, email, dept_code, display name)
    $instructors = [
        ['sam_owusu','oboysika001@gmail.com','CS','Samuel Owusu'],
        ['nana_ofori','nana.ofori@voltatech.edu.gh','IT','Nana Akua Ofori'],
        ['kofi_boat','kofi.boat@voltatech.edu.gh','EE','Kofi Boateng'],
        ['agnes_tet','agnes.tet@voltatech.edu.gh','ME','Agnes Tetteh'],
        ['adom_mensah','adom.mensah@voltatech.edu.gh','BA','Adom Mensah'],
        ['afua_serwaa','afua.serwaa@voltatech.edu.gh','NS','Afua Serwaa'],
        ['yaw_agya','yaw.agya@voltatech.edu.gh','LAW','Yaw Agyapong'],
        ['yaa_asant','yaa.asant@voltatech.edu.gh','EDU','Yaa Asantewaa'],
        ['kojo_agye','kojo.agye@voltatech.edu.gh','AGR','Kojo Agyemang']
    ];

    foreach ($instructors as $ins) {
        $exists = db_query_one("SELECT id FROM users WHERE username = ?", [$ins[0]]);
        if (!$exists) {
            $pwd = hash_password('Instructor@123');
            db_execute("INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES
                       (?, ?, ?, 'instructor', 1, NOW())", [$ins[0], $ins[1], $pwd]);
            $user_id = db_last_id();

            $dept = db_query_one("SELECT id FROM departments WHERE dept_code = ?", [$ins[2]]);
            if ($dept) {
                // split display name
                $parts = explode(' ', $ins[3]);
                $fn = $parts[0];
                $ln = isset($parts[1]) ? $parts[1] : 'Lecturer';
                db_execute("INSERT IGNORE INTO instructors (first_name, last_name, email, phone, department_id, user_id, hire_date, created_at) VALUES
                           (?, ?, ?, ?, ?, ?, CURDATE(), NOW())", [$fn, $ln, $ins[1], '+233500000000', $dept['id'], $user_id]);
                echo "✓ Instructor created: {$ins[3]} ({$ins[2]})<br>";
            }
        } else {
            echo "✓ Instructor {$ins[0]} already exists<br>";
        }
    }

    // ------------------------------
    // Students 
    // ------------------------------
    echo "<h3>Students...</h3>";

    $programsByDepartment = [];
    $programRows = db_query("SELECT id, department_id FROM programs ORDER BY program_code");
    foreach ($programRows as $prog) {
        $deptId = (int)$prog['department_id'];
        if (!isset($programsByDepartment[$deptId])) {
            $programsByDepartment[$deptId] = (int)$prog['id'];
        }
    }
    $students = [
        // username, email, dept_code, level_order, first, last, dob (Y-m-d)
        ['ama_koranteng','ama.koranteng@students.voltatech.edu.gh','CS',1,'Ama','Koranteng','2004-03-14'],
        ['emmanuel_boat','emmanuel.boat@students.voltatech.edu.gh','CS',2,'Emmanuel','Boateng','2003-07-22'],
        ['mavis_agyek','mavis.agyek@students.voltatech.edu.gh','CS',1,'Mavis','Agyekum','2005-01-18'],
        ['kojo_asare','kojo.asare@students.voltatech.edu.gh','EE',1,'Kojo','Asare','2004-05-04'],
        ['efua_owusu','efua.owusu@students.voltatech.edu.gh','EE',2,'Efua','Owusu','2003-08-11'],
        ['john_doe','john.doe@students.voltatech.edu.gh','ME',1,'John','Doe','2005-02-02'],
        ['aba_smith','aba.smith@students.voltatech.edu.gh','ME',2,'Aba','Smith','2004-09-30'],
        ['selorm_tetteh','selorm.tetteh@students.voltatech.edu.gh','CE',2,'Selorm','Tetteh','2003-04-25'],
        ['adwoa_mensa','adwoa.mensa@students.voltatech.edu.gh','ARCH',1,'Adwoa','Mensah','2004-07-19'],
        ['yaw_appiah','yaw.appiah@students.voltatech.edu.gh','ARCH',2,'Yaw','Appiah','2003-02-08'],


        ['priscilla_stamp','priscilla.stamp@students.voltatech.edu.gh','CS',1,'Priscilla','Stamp','2005-07-08'],
        ['daniel_badu','daniel.badu@students.voltatech.edu.gh','IT',2,'Daniel','Badu','2004-02-22'],
        ['mercyl_ope','mercy.lope@students.voltatech.edu.gh','EE',1,'Mercy','Lope','2005-01-10'],
        ['isaac_kwak','isaac.kwak@students.voltatech.edu.gh','BA',2,'Isaac','Kwakye','2002-10-02'],
        ['gloria_nana','gloria.nana@students.voltatech.edu.gh','NS',1,'Gloria','Nana','2004-12-23'],
        ['nelson_ame','nelson.ame@students.voltatech.edu.gh','AGR',1,'Nelson','Amegov','2003-09-09'],
        ['ruth_ebene','ruth.ebene@students.voltatech.edu.gh','EDU',1,'Ruth','Ebenezer','2005-06-14'],
        ['frank_quaye','frank.quaye@students.voltatech.edu.gh','ME',2,'Frank','Quaye','2002-11-05'],
        ['beth_owuor','beth.owuor@students.voltatech.edu.gh','LAW',1,'Beth','Owuor','2004-04-01'],
        ['samuel_nk','samuel.nk@students.voltatech.edu.gh','CS',2,'Samuel','Nkansah','2002-08-16']
    ];

    // Delete or replace older sample students? We will insert new users & students; old ones remain but app will have Ghanaian data primary.
    foreach ($students as $idx => $s) {
        $exists = db_query_one("SELECT id FROM users WHERE username = ?", [$s[0]]);
        if (!$exists) {
            $pwd = hash_password('Student@123');
            db_execute("INSERT INTO users (username, email, password_hash, role, is_active, created_at) VALUES
                       (?, ?, ?, 'student', 1, NOW())", [$s[0], $s[1], $pwd]);
            $user_id = db_last_id();

            $dept = db_query_one("SELECT id FROM departments WHERE dept_code = ?", [$s[2]]);
            $level = db_query_one("SELECT id FROM levels WHERE level_order = ?", [$s[3]]);
            if ($dept && $level) {
                $student_id = generate_student_id('2024', $s[2], ($idx + 1));
                $programId = $programsByDepartment[(int)$dept['id']] ?? null;
                db_execute("INSERT INTO students (student_id, first_name, last_name, email, phone, date_of_birth, address, current_level_id, user_id, department_id, program_id, enrollment_date, status, gpa, created_at, updated_at) VALUES
                           (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active', ?, NOW(), NOW())",
                           [$student_id, $s[4], $s[5], $s[1], '+233'.strval(240000000 + $idx), $s[6], 'Accra, Greater Accra', $level['id'], $user_id, $dept['id'], $programId, round(2.5 + (rand(0,150)/100), 2)]);
                echo "✓ Student created: {$s[4]} {$s[5]} (ID: $student_id)<br>";
            }
        } else {
            echo "✓ Student {$s[0]} already exists<br>";
        }
    }

    // ------------------------------
    // Course sections: create section A/B for all courses and attach instructor where possible
    // ------------------------------
    echo "<h3>Course Sections...</h3>";
    $all_courses = db_query("SELECT c.id AS cid, c.course_code, d.id AS did FROM courses c JOIN departments d ON c.department_id = d.id");
    $all_semesters = db_query("SELECT id, semester_name, academic_year_id FROM semesters ORDER BY start_date");
    $instructorByDept = [];
    $defaultInstructorId = null;
    $sectionPatterns = [
        'A' => ['schedule' => 'Mon/Wed 09:00-10:30', 'room' => 'Room 101'],
        'B' => ['schedule' => 'Tue/Thu 11:00-12:30', 'room' => 'Room 202'],
        'C' => ['schedule' => 'Fri 13:00-16:00', 'room' => 'Lab 303']
    ];


    if (empty($all_semesters)) {
        echo "⚠️ No semesters found; skipping section generation.<br>";
    } else {
        foreach ($all_semesters as $sem) {
            foreach ($all_courses as $c) {
                if (!isset($instructorByDept[$c['did']])) {
                    $instr = db_query_one('SELECT id FROM instructors WHERE department_id = ? LIMIT 1', [$c['did']]);
                    if ($instr) {
                        $instructorByDept[$c['did']] = (int)$instr['id'];
                    } else {
                        if ($defaultInstructorId === null) {
                            $fallback = db_query_one('SELECT id FROM instructors LIMIT 1');
                            $defaultInstructorId = $fallback ? (int)$fallback['id'] : 0;
                        }
                        $instructorByDept[$c['did']] = $defaultInstructorId;
                    }
                }
                $instr_id = $instructorByDept[$c['did']] ?? 0;
                if ($instr_id <= 0) {
                    continue;
                }

                $levelsMap = [
                    'A' => 'L1',
                    'B' => 'L2',
                    'C' => 'L3'
                ];
                $usedSections = [];
                foreach ($sectionPatterns as $suffix => $pattern) {
                    $sectionName = $levelsMap[$suffix] . ' ' . $suffix;
                    $schedule = $pattern['schedule'];
                    $room = $pattern['room'];
                    $capacity = 35;
                    if (isset($usedSections[$sectionName])) {
                        continue;
                    }
                    $usedSections[$sectionName] = true;
                    db_execute(
                        "INSERT INTO course_sections (course_id, section_name, instructor_id, schedule, room, capacity, enrolled_count, semester_id, academic_year_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE instructor_id = VALUES(instructor_id), schedule = VALUES(schedule), room = VALUES(room), capacity = VALUES(capacity), academic_year_id = VALUES(academic_year_id)",
                        [
                            $c['cid'],
                            $sectionName,
                            $instr_id,
                            $schedule,
                            $room,
                            $capacity,
                            $sem['id'],
                            $sem['academic_year_id']
                        ]
                    );
                }
            }
            echo "✓ Sections ensured for {$sem['semester_name']} ({$sem['id']})<br>";
        }
    }

    // ------------------------------
    // Enrollments: enroll each student in 3-5 courses 
    // ------------------------------
    echo "<h3>Enrollments...</h3>";
    $students_db = db_query("SELECT id FROM students");
    $all_sections = db_query("SELECT id, semester_id, academic_year_id FROM course_sections");
    $sections_by_semester = [];
    foreach ($all_sections as $sec) {
        $semId = (int)$sec['semester_id'];
        if (!isset($sections_by_semester[$semId])) {
            $sections_by_semester[$semId] = [];
        }
        $sections_by_semester[$semId][] = [
            'id' => (int)$sec['id'],
            'semester_id' => $semId,
            'academic_year_id' => (int)$sec['academic_year_id'],
        ];
    }

    if (empty($sections_by_semester)) {
        echo "⚠️ No course sections available for enrollment seeding.<br>";
    } else {
        foreach ($students_db as $s) {
            $studentTotal = 0;
            foreach ($sections_by_semester as $semId => $secList) {
                if (empty($secList)) continue;
                $work = $secList;
                shuffle($work);
                $enrolledThisSem = 0;
                foreach ($work as $sec) {
                    if ($enrolledThisSem >= 3) {
                        break;
                    }
                    $exists = db_query_one('SELECT id FROM enrollments WHERE student_id = ? AND course_section_id = ?', [$s['id'], $sec['id']]);
                    if ($exists) {
                        continue;
                    }
                    $result = enroll_student($s['id'], $sec['id'], $sec['semester_id'], $sec['academic_year_id'], true);
                    if (!empty($result['success'])) {
                        $enrolledThisSem++;
                        $studentTotal++;
                    }
                }
            }
            if ($studentTotal > 0) {
                echo "✓ Student {$s['id']} enrolled in {$studentTotal} courses across semesters<br>";
            }
        }
    }

    // ------------------------------
    // Grades: create sample grades for recent enrollments
    // ------------------------------
    echo "<h3>Grades...</h3>";
    $recent_enrollments = db_query("SELECT id FROM enrollments LIMIT 100");
    foreach ($recent_enrollments as $enr) {
        $gexists = db_query_one("SELECT id FROM grades WHERE enrollment_id = ? LIMIT 1", [$enr['id']]);
        if (!$gexists) {
            // simulate realistic scoring
            $quiz = rand(8,15);
            $assign = rand(14,20);
            $final = rand(40,65);
            db_execute("INSERT INTO grades (enrollment_id, assessment_type, assessment_name, score, max_score, weight, grade_date, created_at) VALUES
                       (?, 'quiz', 'Quiz 1', ?, 15.00, 10.00, CURDATE(), NOW()),
                       (?, 'assignment', 'Assgn 1', ?, 20.00, 20.00, CURDATE(), NOW()),
                       (?, 'final', 'Final Exam', ?, 70.00, 70.00, CURDATE(), NOW())",
                       [$enr['id'], $quiz, $enr['id'], $assign, $enr['id'], $final]);
            echo "✓ Grades added for enrollment {$enr['id']}<br>";
        }
    }

    $finalizeGradesDuringSeed = false;
    echo "<h3>Finalizing Grades...</h3>";
    if ($finalizeGradesDuringSeed) {
        $all_enrollments = db_query("SELECT id FROM enrollments");
        foreach ($all_enrollments as $enr) {
            $result = calculate_enrollment_final_grade((int)$enr['id']);
            if ($result['success']) {
                echo "✓ Finalized enrollment {$enr['id']} (Grade: {$result['final_grade']}, Points: {$result['grade_points']})<br>";
            }
        }
    } else {
        echo "Skipping grade finalization during seed; enrollments remain in 'enrolled' status.<br>";
    }

    echo "<h3>Resetting finalized enrollments...</h3>";
    $finalized = db_query_one("SELECT COUNT(*) AS c FROM enrollments WHERE status IN ('completed','passed')");
    $finalizedTotal = (int)($finalized['c'] ?? 0);
    if ($finalizedTotal > 0) {
        db_execute("UPDATE enrollments SET status = 'enrolled', final_grade = NULL, grade_points = NULL WHERE status IN ('completed','passed')");
        echo "✓ Reset {$finalizedTotal} enrollments back to 'enrolled' status<br>";
    } else {
        echo "No finalized enrollments to reset.<br>";
    }

    echo "<h3>Recalculating Aggregates...</h3>";
    db_execute("UPDATE course_sections cs
                SET enrolled_count = (
                    SELECT COUNT(*)
                    FROM enrollments e
                    WHERE e.course_section_id = cs.id
                      AND e.status = 'enrolled'
                )");
    echo "✓ Course section enrollment counts updated<br>";

    echo "<h3>Refreshing summary tables (no views)...</h3>";
    try {
        db_execute('TRUNCATE TABLE student_enrollments_view');
        db_execute("INSERT INTO student_enrollments_view (
                        id,
                        student_id,
                        student_name,
                        course_code,
                        course_name,
                        section_name,
                        instructor_name,
                        dept_name,
                        level_name,
                        semester_name,
                        year_name,
                        status,
                        final_grade,
                        grade_points
                    )
                    SELECT
                        e.id,
                        s.student_id,
                        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                        c.course_code,
                        c.course_name,
                        cs.section_name,
                        CONCAT(i.first_name, ' ', i.last_name) AS instructor_name,
                        d.dept_name,
                        l.level_name,
                        sem.semester_name,
                        ay.year_name,
                        e.status,
                        e.final_grade,
                        e.grade_points
                    FROM enrollments e
                    JOIN students s ON e.student_id = s.id
                    JOIN course_sections cs ON e.course_section_id = cs.id
                    JOIN courses c ON cs.course_id = c.id
                    JOIN instructors i ON cs.instructor_id = i.id
                    JOIN departments d ON c.department_id = d.id
                    JOIN levels l ON c.level_id = l.id
                    JOIN semesters sem ON e.semester_id = sem.id
                    JOIN academic_years ay ON e.academic_year_id = ay.id");
        echo "✓ student_enrollments_view refreshed<br>";
    } catch (Exception $e) {
        echo "⚠️ Unable to refresh student_enrollments_view: " . htmlspecialchars($e->getMessage()) . "<br>";
    }

    try {
        db_execute('TRUNCATE TABLE course_enrollment_summary');
        db_execute("INSERT INTO course_enrollment_summary (
                        id,
                        course_code,
                        course_name,
                        section_name,
                        instructor_name,
                        capacity,
                        enrolled_count,
                        available_spots,
                        semester_name,
                        year_name,
                        room,
                        schedule
                    )
                    SELECT
                        cs.id,
                        c.course_code,
                        c.course_name,
                        cs.section_name,
                        CONCAT(i.first_name, ' ', i.last_name) AS instructor_name,
                        cs.capacity,
                        cs.enrolled_count,
                        (cs.capacity - cs.enrolled_count) AS available_spots,
                        sem.semester_name,
                        ay.year_name,
                        cs.room,
                        cs.schedule
                    FROM course_sections cs
                    JOIN courses c ON cs.course_id = c.id
                    JOIN instructors i ON cs.instructor_id = i.id
                    JOIN semesters sem ON cs.semester_id = sem.id
                    JOIN academic_years ay ON cs.academic_year_id = ay.id");
        echo "✓ course_enrollment_summary refreshed<br>";
    } catch (Exception $e) {
        echo "⚠️ Unable to refresh course_enrollment_summary: " . htmlspecialchars($e->getMessage()) . "<br>";
    }

    try {
        db_execute('TRUNCATE TABLE student_gpa_view');
        db_execute("INSERT INTO student_gpa_view (
                        id,
                        student_id,
                        student_name,
                        department_id,
                        dept_name,
                        total_courses,
                        total_credits,
                        total_grade_points,
                        calculated_gpa
                    )
                    SELECT
                        s.id,
                        s.student_id,
                        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                        s.department_id,
                        d.dept_name,
                        COUNT(e.id) AS total_courses,
                        COALESCE(SUM(c.credits), 0) AS total_credits,
                        COALESCE(SUM(e.grade_points * c.credits), 0) AS total_grade_points,
                        CASE
                            WHEN COALESCE(SUM(c.credits), 0) > 0 THEN
                                ROUND(COALESCE(SUM(e.grade_points * c.credits), 0) / SUM(c.credits), 2)
                            ELSE 0.00
                        END AS calculated_gpa
                    FROM students s
                    LEFT JOIN enrollments e ON s.id = e.student_id AND e.status IN ('completed','passed') AND e.final_grade IS NOT NULL
                    LEFT JOIN course_sections cs ON e.course_section_id = cs.id
                    LEFT JOIN courses c ON cs.course_id = c.id
                    LEFT JOIN departments d ON s.department_id = d.id
                    GROUP BY s.id, s.student_id, s.first_name, s.last_name, s.department_id, d.dept_name");
        echo "✓ student_gpa_view refreshed<br>";
    } catch (Exception $e) {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo "<div style=\"color:red\">Setup failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        } else {
            if (function_exists('log_exception')) { log_exception($e, 'Refreshing student_gpa_view failed'); }
            echo "<div style=\"color:red\">Setup failed. Please check the application logs for details.</div>";
        }
    }

    db_execute("UPDATE students s
                LEFT JOIN student_gpa_view g ON g.id = s.id
                SET s.gpa = COALESCE(g.calculated_gpa, 0.00)");
    echo "✓ Student GPA column synchronized with latest calculations<br>";

    // ------------------------------
    // Historical data: seed prior-year sections, enrollments, and grades (idempotent)
    // ------------------------------
    echo "<h3>Seeding prior-year history...</h3>";
    $currentAyId = $current_ay['id'] ?? null;
    $prior_years = [];
    if ($currentAyId) {
        $prior_years = db_query("SELECT id, year_name FROM academic_years WHERE id <> ? ORDER BY id", [$currentAyId]);
    } else {
        $prior_years = db_query("SELECT id, year_name FROM academic_years ORDER BY id");
    }

    // Build a reusable list of courses and an instructor per department
    $courses_by_dept = db_query("SELECT c.id AS cid, c.course_code, d.id AS did FROM courses c JOIN departments d ON c.department_id = d.id");
    $instructor_cache = [];

    foreach ($prior_years as $yr) {
        // two semesters per year
        $sems = db_query("SELECT id, semester_name FROM semesters WHERE academic_year_id = ? ORDER BY start_date", [$yr['id']]);
        foreach ($sems as $sem) {
            // Create sections for a subset of courses to limit data volume
            $count = 0;
            foreach ($courses_by_dept as $c) {
                if ($count >= 10) break; // limit per semester for demo
                if (!isset($instructor_cache[$c['did']])) {
                    $inst = db_query_one("SELECT id FROM instructors WHERE department_id = ? LIMIT 1", [$c['did']]);
                    if (!$inst) { $inst = db_query_one("SELECT id FROM instructors LIMIT 1"); }
                    $instructor_cache[$c['did']] = $inst ? (int)$inst['id'] : null;
                }
                $instr_id = $instructor_cache[$c['did']];
                if (!$instr_id) continue;

                // Create a single section per course per prior term
                $histRoom = 'HIST-' . (string)$yr['id'];
                db_execute("INSERT IGNORE INTO course_sections (course_id, section_name, instructor_id, schedule, room, capacity, enrolled_count, semester_id, academic_year_id, created_at) VALUES
                           (?, 'A', ?, 'MWF 08:00-09:00', ?, 45, 0, ?, ?, NOW())",
                           [$c['cid'], $instr_id, $histRoom, $sem['id'], $yr['id']]);
                $count++;
            }

            // Enroll a sample of students and assign grades
            $prior_sections = db_query("SELECT id FROM course_sections WHERE academic_year_id = ? AND semester_id = ?", [$yr['id'], $sem['id']]);
            if (!empty($prior_sections)) {
                $studs = db_query("SELECT id FROM students");
                foreach ($studs as $s) {
                    $toEnroll = 0;
                    $shuffled = $prior_sections;
                    shuffle($shuffled);
                    foreach ($shuffled as $sec) {
                        if ($toEnroll >= 3) break;
                        $exists = db_query_one("SELECT id FROM enrollments WHERE student_id = ? AND course_section_id = ?", [$s['id'], $sec['id']]);
                        if ($exists) continue;
                        $res = enroll_student($s['id'], $sec['id'], (int)$sem['id'], (int)$yr['id'], true);
                        if (isset($res['success']) && $res['success']) {
                            $toEnroll++;
                        }
                    }
                }

                // Add grades for these historical enrollments
                $hist_enr = db_query("SELECT id FROM enrollments WHERE academic_year_id = ? AND semester_id = ?", [$yr['id'], $sem['id']]);
                foreach ($hist_enr as $enr) {
                    $gexists = db_query_one("SELECT id FROM grades WHERE enrollment_id = ? LIMIT 1", [$enr['id']]);
                    if (!$gexists) {
                        $quiz = rand(8,15);
                        $assign = rand(14,20);
                        $final = rand(40,65);
                        db_execute("INSERT INTO grades (enrollment_id, assessment_type, assessment_name, score, max_score, weight, grade_date, created_at) VALUES
                                   (?, 'quiz', 'Quiz 1', ?, 15.00, 10.00, DATE_SUB(CURDATE(), INTERVAL 365 DAY), NOW()),
                                   (?, 'assignment', 'Assgn 1', ?, 20.00, 20.00, DATE_SUB(CURDATE(), INTERVAL 360 DAY), NOW()),
                                   (?, 'final', 'Final Exam', ?, 70.00, 70.00, DATE_SUB(CURDATE(), INTERVAL 330 DAY), NOW())",
                                   [$enr['id'], $quiz, $enr['id'], $assign, $enr['id'], $final]);
                    }
                }

                // Finalize grades
                foreach ($hist_enr as $enr) {
                    $result = calculate_enrollment_final_grade((int)$enr['id']);
                }
            }
        }
    }
    echo "✓ Prior-year sections, enrollments, and grades seeded<br>";

    // ------------------------------
    // Attendance: give each of some enrollments 5 records
    // ------------------------------
    echo "<h3>Attendance samples...</h3>";
    $some_enrolls = db_query("SELECT id FROM enrollments LIMIT 80");
    foreach ($some_enrolls as $enr) {
        $aexists = db_query_one("SELECT id FROM attendance WHERE enrollment_id = ? LIMIT 1", [$enr['id']]);
        if (!$aexists) {
            $statuses = ['present','present','late','present','absent'];
            $dayOffset = 0;
            foreach ($statuses as $st) {
                db_execute("INSERT IGNORE INTO attendance (enrollment_id, attendance_date, status, notes, marked_by, created_at) VALUES
                           (?, DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, ?, (SELECT id FROM instructors LIMIT 1), NOW())", [$enr['id'], $dayOffset, $st, 'Auto-generated sample']);
                $dayOffset += 2;
            }
            echo "✓ Attendance created for enrollment {$enr['id']}<br>";
        }
    }

    // ------------------------------
    // Fee payments: create payments for each student for current semester
    // ------------------------------
    echo "<h3>Fee Payments...</h3>";
    $all_students = db_query("SELECT id FROM students");
    foreach ($all_students as $st) {
        $pay_exists = db_query_one("SELECT id FROM fee_payments WHERE student_id = ? AND academic_year_id = ?", [$st['id'], $current_ay['id']]);
        if (!$pay_exists) {
            // random partial vs full
            $amount = (rand(0,100) > 40) ? 2000.00 : 1200.00;
            $status = ($amount >= 2000.00) ? 'paid' : 'pending';
            db_execute("INSERT INTO fee_payments (student_id, amount, payment_date, status, payment_method, transaction_id, semester_id, academic_year_id, scholarship_amount, notes, created_at) VALUES
                       (?, ?, CURDATE(), ?, 'Mobile Money', ?, ?, ?, 0.00, ?, NOW())",
                       [$st['id'], $amount, $status, 'MM-'.uniqid(), $current_semester_id, $current_ay['id'], ($status === 'paid') ? 'Paid in full' : 'Partial/awaiting balance']);
            echo "✓ Payment ({$status}) for student {$st['id']} inserted<br>";
        }
    }

    // ------------------------------
    // Student advisors: assign one per student
    // ------------------------------
    echo "<h3>Assigning Advisors...</h3>";
    foreach ($all_students as $st) {
        $advisor_exists = db_query_one("SELECT id FROM student_advisors WHERE student_id = ?", [$st['id']]);
        if (!$advisor_exists) {
            // try match instructor in student's dept
            $stu = db_query_one("SELECT department_id FROM students WHERE id = ?", [$st['id']]);
            $instr = null;
            if ($stu && $stu['department_id']) {
                $instr = db_query_one("SELECT id FROM instructors WHERE department_id = ? LIMIT 1", [$stu['department_id']]);
            }
            if (!$instr) $instr = db_query_one("SELECT id FROM instructors LIMIT 1");
            if ($instr) {
                db_execute("INSERT IGNORE INTO student_advisors (student_id, instructor_id, assigned_date, is_active, created_at) VALUES
                           (?, ?, CURDATE(), 1, NOW())", [$st['id'], $instr['id']]);
                echo "✓ Advisor (inst id {$instr['id']}) assigned to student {$st['id']}<br>";
            }
        }
    }

    // ------------------------------
    // Notifications & system logs (few samples)
    // ------------------------------
    echo "<h3>System Logs & Notifications...</h3>";
    // sample logs
    db_execute("INSERT IGNORE INTO system_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) VALUES
               ((SELECT id FROM users WHERE username = 'vt_admin' LIMIT 1), 'system_setup', 'academic_years', 3, '127.0.0.1', 'setup-script', NOW())");
    // sample notification to a sample user
    $sample_user = db_query_one("SELECT id FROM users WHERE role = 'student' LIMIT 1");
    if ($sample_user) {
        db_execute("INSERT IGNORE INTO notifications (user_id, title, message, type, is_read, created_at) VALUES
                   (?, 'Welcome to VoltaTech', 'Your account has been created at VoltaTech University. Use your assigned credentials to login.', 'info', 0, NOW())", [$sample_user['id']]);
        echo "✓ Sample notification created for user id {$sample_user['id']}<br>";
    }

    echo "<h2>Setup Complete!</h2>";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h3>✅ University database setup completed successfully!</h3>";
    echo "<p><strong>Default Login Credentials (change these after first login):</strong></p>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> vt_admin / VoltaAdmin@123</li>";
    echo "<li><strong>Instructor sample:</strong> sam_owusu / Instructor@123</li>";
    echo "<li><strong>Student sample:</strong> kwame_owusu / Student@123</li>";
    echo "</ul>";
    echo "<p>You can now <a href='../login.php'>login to the system</a> and start using VoltaTech University demo data.</p>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h3>❌ Setup failed!</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
