<?php
/**
 * Database Setup and Seeding Script
 * Seeds realistic Ghanaian university data with historical records
 * Leaves current semester empty for auto-enrollment testing
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

set_time_limit(300); // 5 minutes for seeding
ini_set('memory_limit', '512M');

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Database Setup</title>";
echo "<style>body{font-family:Arial,sans-serif;max-width:1200px;margin:20px auto;padding:20px;}";
echo ".success{color:#28a745;}.error{color:#dc3545;}.info{color:#17a2b8;}";
echo "h2{border-bottom:2px solid #333;padding-bottom:10px;margin-top:30px;}";
echo "pre{background:#f4f4f4;padding:10px;border-radius:5px;overflow-x:auto;}</style></head><body>";

echo "<h1>🎓 University Management System - Database Setup</h1>";
echo "<p class='info'>Setting up Ghanaian university data with historical records...</p>";

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function log_step($message, $type = 'info') {
    $class = $type === 'error' ? 'error' : ($type === 'success' ? 'success' : 'info');
    echo "<p class='$class'>► $message</p>";
    flush();
    ob_flush();
}

function seed_academic_years() {
    log_step("Seeding academic years...");
    
    $currentYear = (int)date('Y');
    $years = [];
    
    // Create 5 academic years (4 historical + 1 current)
    for ($i = 4; $i >= 0; $i--) {
        $startYear = $currentYear - $i;
        $endYear = $startYear + 1;
        $yearName = "$startYear/$endYear";
        $startDate = "$startYear-09-01";
        $endDate = "$endYear-06-30";
        $isCurrent = ($i === 0) ? 1 : 0;
        
        $existing = db_query_one('SELECT id FROM academic_years WHERE year_name = ? LIMIT 1', [$yearName]);
        if (!$existing) {
            db_execute('INSERT INTO academic_years (year_name, start_date, end_date, is_current) VALUES (?, ?, ?, ?)',
                [$yearName, $startDate, $endDate, $isCurrent]);
            $years[] = ['id' => (int)db_last_id(), 'name' => $yearName, 'start' => $startDate, 'is_current' => $isCurrent];
        } else {
            $years[] = ['id' => (int)$existing['id'], 'name' => $yearName, 'start' => $startDate, 'is_current' => $isCurrent];
        }
    }
    
    log_step("Created " . count($years) . " academic years", 'success');
    return $years;
}

function seed_semesters($academicYears) {
    log_step("Seeding semesters...");
    
    $semesters = [];
    foreach ($academicYears as $year) {
        $yearParts = explode('/', $year['name']);
        $startYear = (int)$yearParts[0];
        $endYear = (int)$yearParts[1];
        
        // First Semester (September - December)
        $sem1Start = "$startYear-09-01";
        $sem1End = "$startYear-12-31";
        $sem1RegDeadline = "$startYear-09-15";
        $sem1ExamStart = "$startYear-12-01";
        $sem1ExamEnd = "$startYear-12-20";
        
        $existing = db_query_one('SELECT id FROM semesters WHERE academic_year_id = ? AND semester_name = ? LIMIT 1',
            [$year['id'], 'First Semester']);
        
        if (!$existing) {
            db_execute('INSERT INTO semesters (semester_name, academic_year_id, start_date, end_date, is_current, registration_deadline, exam_period_start, exam_period_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                ['First Semester', $year['id'], $sem1Start, $sem1End, 0, $sem1RegDeadline, $sem1ExamStart, $sem1ExamEnd]);
            $sem1Id = (int)db_last_id();
        } else {
            $sem1Id = (int)$existing['id'];
        }
        
        $semesters[] = [
            'id' => $sem1Id,
            'name' => 'First Semester',
            'academic_year_id' => $year['id'],
            'academic_year_name' => $year['name'],
            'start_date' => $sem1Start,
            'is_current_year' => $year['is_current'],
            'is_current' => false,
        ];
        
        // Second Semester (January - June)
        $sem2Start = "$endYear-01-15";
        $sem2End = "$endYear-06-30";
        $sem2RegDeadline = "$endYear-01-30";
        $sem2ExamStart = "$endYear-06-01";
        $sem2ExamEnd = "$endYear-06-20";
        
        $existing = db_query_one('SELECT id FROM semesters WHERE academic_year_id = ? AND semester_name = ? LIMIT 1',
            [$year['id'], 'Second Semester']);
        
        if (!$existing) {
            db_execute('INSERT INTO semesters (semester_name, academic_year_id, start_date, end_date, is_current, registration_deadline, exam_period_start, exam_period_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                ['Second Semester', $year['id'], $sem2Start, $sem2End, 0, $sem2RegDeadline, $sem2ExamStart, $sem2ExamEnd]);
            $sem2Id = (int)db_last_id();
        } else {
            $sem2Id = (int)$existing['id'];
        }
        
        $semesters[] = [
            'id' => $sem2Id,
            'name' => 'Second Semester',
            'academic_year_id' => $year['id'],
            'academic_year_name' => $year['name'],
            'start_date' => $sem2Start,
            'is_current_year' => $year['is_current'],
            'is_current' => false,
        ];
    }
    
    // Set current semester (Second Semester of current year)
    $currentMonth = (int)date('n');
    if ($currentMonth >= 9 || $currentMonth <= 12) {
        // First semester is current (Sep-Dec)
        $currentSem = array_filter($semesters, fn($s) => $s['is_current_year'] && $s['name'] === 'First Semester');
    } else {
        // Second semester is current (Jan-Jun)
        $currentSem = array_filter($semesters, fn($s) => $s['is_current_year'] && $s['name'] === 'Second Semester');
    }
    
    if (!empty($currentSem)) {
        $currentSem = array_values($currentSem)[0];
        db_execute('UPDATE semesters SET is_current = 0');
        db_execute('UPDATE semesters SET is_current = 1 WHERE id = ?', [$currentSem['id']]);
        log_step("Set current semester: {$currentSem['name']} {$currentSem['academic_year_name']}", 'success');
    }
    
    $currentSemRow = db_query_one('SELECT id FROM semesters WHERE is_current = 1 LIMIT 1');
    if ($currentSemRow) {
        $currentSemId = (int)$currentSemRow['id'];
        foreach ($semesters as &$semRef) {
            $semRef['is_current'] = ($semRef['id'] === $currentSemId);
        }
        unset($semRef);
    }

    log_step("Created " . count($semesters) . " semesters", 'success');
    return $semesters;
}

function seed_departments() {
    log_step("Seeding departments...");
    
    $departments = [
        ['CS', 'Computer Science', 'Dr. Kwame Mensah', 'Department of Computer Science and Information Technology'],
        ['IT', 'Information Technology', 'Dr. Ama Owusu', 'Department of Information Technology and Systems'],
        ['ENG', 'English Language', 'Prof. Yaa Asante', 'Department of English and Communication Studies'],
        ['MATH', 'Mathematics', 'Dr. Kofi Adjei', 'Department of Mathematics and Statistics'],
        ['BUS', 'Business Administration', 'Prof. Akua Darko', 'School of Business and Management'],
        ['EDU', 'Education', 'Dr. Kwesi Boateng', 'Faculty of Education and Social Sciences'],
    ];
    
    $deptIds = [];
    foreach ($departments as $dept) {
        $existing = db_query_one('SELECT id FROM departments WHERE dept_code = ? LIMIT 1', [$dept[0]]);
        if (!$existing) {
            db_execute('INSERT INTO departments (dept_code, dept_name, dept_head, description) VALUES (?, ?, ?, ?)',
                [$dept[0], $dept[1], $dept[2], $dept[3]]);
            $deptIds[$dept[0]] = (int)db_last_id();
        } else {
            $deptIds[$dept[0]] = (int)$existing['id'];
        }
    }
    
    log_step("Created " . count($departments) . " departments", 'success');
    return $deptIds;
}

function seed_levels() {
    log_step("Seeding academic levels...");
    
    $levels = [
        ['Level 100', 1],
        ['Level 200', 2],
        ['Level 300', 3],
        ['Level 400', 4],
    ];
    
    $levelIds = [];
    foreach ($levels as $level) {
        $existing = db_query_one('SELECT id FROM levels WHERE level_name = ? LIMIT 1', [$level[0]]);
        if (!$existing) {
            db_execute('INSERT INTO levels (level_name, level_order) VALUES (?, ?)', [$level[0], $level[1]]);
            $levelIds[$level[1]] = (int)db_last_id();
        } else {
            $levelIds[$level[1]] = (int)$existing['id'];
        }
    }
    
    log_step("Created " . count($levels) . " levels", 'success');
    return $levelIds;
}

function seed_courses($deptIds, $levelIds) {
    log_step("Seeding courses...");
    
    $courses = [
        // Computer Science - Level 100 (First Year)
        ['CS101', 'Introduction to Computer Science', 'CS', 1, 3, 1, null, 'Fundamentals of computing and programming'],
        ['CS102', 'Programming Fundamentals', 'CS', 1, 3, 1, 'CS101', 'Basic programming concepts with Python'],
        ['MATH101', 'Calculus I', 'MATH', 1, 3, 1, null, 'Differential and integral calculus'],
        ['CS111', 'Discrete Mathematics', 'CS', 1, 3, 2, null, 'Logic, sets, relations, and graph theory'],
        ['CS112', 'Web Technologies I', 'CS', 1, 3, 2, 'CS102', 'HTML, CSS, and basic JavaScript'],
        
        // Computer Science - Level 200 (Second Year)
        ['CS201', 'Data Structures', 'CS', 2, 4, 1, 'CS102', 'Arrays, linked lists, trees, and algorithms'],
        ['CS202', 'Object-Oriented Programming', 'CS', 2, 4, 1, 'CS102', 'OOP concepts with Java'],
        ['CS203', 'Computer Organization', 'CS', 2, 3, 1, 'CS101', 'Hardware architecture and assembly'],
        ['CS211', 'Database Systems', 'CS', 2, 4, 2, 'CS201', 'Relational databases and SQL'],
        ['CS212', 'Web Technologies II', 'CS', 2, 3, 2, 'CS112', 'Advanced web development and frameworks'],
        
        // Computer Science - Level 300 (Third Year)
        ['CS301', 'Algorithms and Complexity', 'CS', 3, 4, 1, 'CS201', 'Algorithm design and analysis'],
        ['CS302', 'Software Engineering', 'CS', 3, 4, 1, 'CS202', 'SDLC, design patterns, and testing'],
        ['CS303', 'Operating Systems', 'CS', 3, 4, 1, 'CS203', 'Process management and memory'],
        ['CS311', 'Computer Networks', 'CS', 3, 3, 2, 'CS203', 'Network protocols and security'],
        ['CS312', 'Artificial Intelligence', 'CS', 3, 4, 2, 'CS301', 'AI concepts and machine learning basics'],
        
        // Computer Science - Level 400 (Fourth Year)
        ['CS401', 'Advanced Databases', 'CS', 4, 3, 1, 'CS211', 'NoSQL, distributed databases'],
        ['CS402', 'Mobile Application Development', 'CS', 4, 4, 1, 'CS212', 'iOS and Android development'],
        ['CS403', 'Cybersecurity', 'CS', 4, 4, 1, 'CS311', 'Security threats and cryptography'],
        ['CS411', 'Cloud Computing', 'CS', 4, 3, 2, 'CS311', 'Cloud platforms and services'],
        ['CS499', 'Final Year Project', 'CS', 4, 6, 2, 'CS302', 'Capstone project'],
        
        // Information Technology
        ['IT101', 'Introduction to IT', 'IT', 1, 3, 1, null, 'IT fundamentals and career paths'],
        ['IT102', 'Programming for IT', 'IT', 1, 3, 1, null, 'Basic programming with Python'],
        ['IT201', 'Network Fundamentals', 'IT', 2, 4, 1, 'IT101', 'Networking basics and protocols'],
        ['IT202', 'Systems Administration', 'IT', 2, 3, 1, 'IT101', 'Server and system management'],
        ['IT301', 'IT Project Management', 'IT', 3, 4, 1, 'IT202', 'Agile, Scrum, and project planning'],
        ['IT401', 'IT Security and Compliance', 'IT', 4, 4, 1, 'IT201', 'Security standards and compliance'],
        
        // English Language
        ['ENG101', 'Introduction to Literature', 'ENG', 1, 3, 1, null, 'Literary genres and analysis'],
        ['ENG102', 'Academic Writing', 'ENG', 1, 3, 1, null, 'Essay writing and research skills'],
        ['ENG201', 'African Literature', 'ENG', 2, 3, 1, 'ENG101', 'West African literary works'],
        ['ENG202', 'Linguistics', 'ENG', 2, 3, 2, 'ENG101', 'Language structure and analysis'],
        ['ENG301', 'Shakespeare Studies', 'ENG', 3, 4, 1, 'ENG201', 'Major works of Shakespeare'],
        ['ENG401', 'Contemporary World Literature', 'ENG', 4, 4, 1, 'ENG301', 'Modern global literature'],
        
        // Mathematics
        ['MATH102', 'Linear Algebra', 'MATH', 1, 3, 2, 'MATH101', 'Vectors, matrices, and transformations'],
        ['MATH201', 'Calculus II', 'MATH', 2, 4, 1, 'MATH101', 'Multivariable calculus'],
        ['MATH202', 'Probability and Statistics', 'MATH', 2, 4, 2, 'MATH101', 'Statistical methods'],
        
        // Business Administration
        ['BUS101', 'Introduction to Business', 'BUS', 1, 3, 1, null, 'Business fundamentals'],
        ['BUS201', 'Accounting Principles', 'BUS', 2, 4, 1, 'BUS101', 'Financial and managerial accounting'],
        ['BUS301', 'Marketing Management', 'BUS', 3, 4, 1, 'BUS201', 'Marketing strategies'],
        ['BUS401', 'Strategic Management', 'BUS', 4, 4, 1, 'BUS301', 'Business strategy'],
        
        // Education
        ['EDU101', 'Foundations of Education', 'EDU', 1, 3, 1, null, 'Educational philosophy'],
        ['EDU201', 'Educational Psychology', 'EDU', 2, 3, 1, 'EDU101', 'Learning theories'],
        ['EDU301', 'Curriculum Development', 'EDU', 3, 4, 1, 'EDU201', 'Curriculum design'],
        ['EDU401', 'Teaching Practice', 'EDU', 4, 6, 2, 'EDU301', 'Supervised teaching'],
    ];
    
    $courseIds = [];
    foreach ($courses as $c) {
        $existing = db_query_one('SELECT id FROM courses WHERE course_code = ? LIMIT 1', [$c[0]]);
        if (!$existing) {
            db_execute('INSERT INTO courses (course_code, course_name, department_id, level_id, credits, prerequisites, description) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$c[0], $c[1], $deptIds[$c[2]], $levelIds[$c[3]], $c[4], $c[6], $c[7]]);
            $courseIds[$c[0]] = ['id' => (int)db_last_id(), 'level' => $c[3], 'semester' => $c[5], 'dept' => $c[2]];
        } else {
            $courseIds[$c[0]] = ['id' => (int)$existing['id'], 'level' => $c[3], 'semester' => $c[5], 'dept' => $c[2]];
        }
    }
    
    log_step("Created " . count($courses) . " courses", 'success');
    return $courseIds;
}

function seed_programs($deptIds) {
    log_step("Seeding programs...");
    
    $programs = [
        ['BSC-CS', 'BSc Computer Science', 'CS', 120],
        ['BSC-IT', 'BSc Information Technology', 'IT', 120],
        ['BA-ENG', 'BA English Language', 'ENG', 120],
        ['BSC-MATH', 'BSc Mathematics', 'MATH', 120],
        ['BBA', 'Bachelor of Business Administration', 'BUS', 120],
        ['BED', 'Bachelor of Education', 'EDU', 120],
    ];
    
    $programIds = [];
    foreach ($programs as $p) {
        $existing = db_query_one('SELECT id FROM programs WHERE program_code = ? LIMIT 1', [$p[0]]);
        if (!$existing) {
            db_execute('INSERT INTO programs (program_code, program_name, department_id, total_credits) VALUES (?, ?, ?, ?)',
                [$p[0], $p[1], $deptIds[$p[2]], $p[3]]);
            $programIds[$p[0]] = ['id' => (int)db_last_id(), 'dept' => $p[2]];
        } else {
            $programIds[$p[0]] = ['id' => (int)$existing['id'], 'dept' => $p[2]];
        }
    }
    
    log_step("Created " . count($programs) . " programs", 'success');
    return $programIds;
}

function seed_program_courses($programIds, $courseIds) {
    log_step("Mapping courses to programs...");
    
    $count = 0;
    
    // Map CS courses to CS program
    foreach ($courseIds as $code => $info) {
        if ($info['dept'] === 'CS') {
            $existing = db_query_one('SELECT id FROM program_courses WHERE program_id = ? AND course_id = ? LIMIT 1',
                [$programIds['BSC-CS']['id'], $info['id']]);
            if (!$existing) {
                db_execute('INSERT INTO program_courses (program_id, course_id, term_number, required) VALUES (?, ?, ?, ?)',
                    [$programIds['BSC-CS']['id'], $info['id'], $info['level'], 1]);
                $count++;
            }
        }
    }
    
    // Map IT courses to IT program
    foreach ($courseIds as $code => $info) {
        if ($info['dept'] === 'IT') {
            $existing = db_query_one('SELECT id FROM program_courses WHERE program_id = ? AND course_id = ? LIMIT 1',
                [$programIds['BSC-IT']['id'], $info['id']]);
            if (!$existing) {
                db_execute('INSERT INTO program_courses (program_id, course_id, term_number, required) VALUES (?, ?, ?, ?)',
                    [$programIds['BSC-IT']['id'], $info['id'], $info['level'], 1]);
                $count++;
            }
        }
    }
    
    // Map ENG courses to ENG program
    foreach ($courseIds as $code => $info) {
        if ($info['dept'] === 'ENG') {
            $existing = db_query_one('SELECT id FROM program_courses WHERE program_id = ? AND course_id = ? LIMIT 1',
                [$programIds['BA-ENG']['id'], $info['id']]);
            if (!$existing) {
                db_execute('INSERT INTO program_courses (program_id, course_id, term_number, required) VALUES (?, ?, ?, ?)',
                    [$programIds['BA-ENG']['id'], $info['id'], $info['level'], 1]);
                $count++;
            }
        }
    }
    
    log_step("Created $count program-course mappings", 'success');
}

function seed_instructors($deptIds) {
    log_step("Seeding instructors...");
    
    $instructors = [
        ['kwame.mensah', 'oboysika001@gmail.com', 'Kwame', 'Mensah', 'CS', '0244123456', '2015-09-01'],
        ['ama.owusu', 'ama.owusu@university.edu.gh', 'Ama', 'Owusu', 'IT', '0244234567', '2016-01-15'],
        ['yaa.asante', 'yaa.asante@university.edu.gh', 'Yaa', 'Asante', 'ENG', '0244345678', '2014-09-01'],
        ['kofi.adjei', 'kofi.adjei@university.edu.gh', 'Kofi', 'Adjei', 'MATH', '0244456789', '2017-09-01'],
        ['akua.darko', 'akua.darko@university.edu.gh', 'Akua', 'Darko', 'BUS', '0244567890', '2015-01-15'],
        ['kwesi.boateng', 'kwesi.boateng@university.edu.gh', 'Kwesi', 'Boateng', 'EDU', '0244678901', '2016-09-01'],
        ['abena.gyamfi', 'abena.gyamfi@university.edu.gh', 'Abena', 'Gyamfi', 'CS', '0244789012', '2018-09-01'],
        ['kwabena.osei', 'kwabena.osei@university.edu.gh', 'Kwabena', 'Osei', 'IT', '0244890123', '2019-01-15'],
        ['efua.ansah', 'efua.ansah@university.edu.gh', 'Efua', 'Ansah', 'ENG', '0244901234', '2017-09-01'],
        ['kojo.mensah', 'kojo.mensah@university.edu.gh', 'Kojo', 'Mensah', 'MATH', '0245012345', '2018-01-15'],
    ];
    
    $instructorIds = [];
    foreach ($instructors as $inst) {
        $existingUser = db_query_one('SELECT id FROM users WHERE username = ? LIMIT 1', [$inst[0]]);
        
        if (!$existingUser) {
            db_execute('INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)',
                [$inst[0], $inst[1], hash_password('instructor123'), 'instructor', 1]);
            $userId = (int)db_last_id();
        } else {
            $userId = (int)$existingUser['id'];
        }
        
        $existingInst = db_query_one('SELECT id FROM instructors WHERE user_id = ? LIMIT 1', [$userId]);
        if (!$existingInst) {
            db_execute('INSERT INTO instructors (first_name, last_name, phone, department_id, user_id, hire_date) VALUES (?, ?, ?, ?, ?, ?)',
                [$inst[2], $inst[3], $inst[5], $deptIds[$inst[4]], $userId, $inst[6]]);
            $instructorIds[$inst[4]][] = (int)db_last_id();
        } else {
            $instructorIds[$inst[4]][] = (int)$existingInst['id'];
        }
    }
    
    log_step("Created " . count($instructors) . " instructors", 'success');
    return $instructorIds;
}

function seed_students($deptIds, $programIds, $levelIds, $academicYears) {
    log_step("Seeding students with deterministic credentials...");

    $namePool = [
        ['first' => 'Ama', 'last' => 'Mensah'],
        ['first' => 'Kofi', 'last' => 'Boateng'],
        ['first' => 'Akua', 'last' => 'Owusu'],
        ['first' => 'Yaw', 'last' => 'Asante'],
        ['first' => 'Abena', 'last' => 'Appiah'],
        ['first' => 'Kojo', 'last' => 'Agyeman'],
        ['first' => 'Efua', 'last' => 'Nyarko'],
        ['first' => 'Kwesi', 'last' => 'Danso'],
        ['first' => 'Adjoa', 'last' => 'Opoku'],
        ['first' => 'Kwabena', 'last' => 'Gyamfi'],
    ];

    $students = [];
    $studentCount = 60;
    $poolSize = count($namePool);

    // Distribute students evenly across levels (4 levels)
    $studentsPerLevel = (int)ceil($studentCount / 4);

    for ($i = 0; $i < $studentCount; $i++) {
        $template = $namePool[$i % $poolSize];
        $series = intdiv($i, $poolSize) + 1;
        $suffix = sprintf('%02d', $series);

        // Determine level based on overall student index, not just name-pool series
        $levelOrder = min(4, intdiv($i, $studentsPerLevel) + 1);
        $yearsAgo = ($levelOrder - 1);

        $programKeys = array_keys($programIds);
        $programKey = $programKeys[$i % count($programKeys)];
        $program = $programIds[$programKey];

        $enrollYear = (int)date('Y') - $yearsAgo;
        $enrollmentDate = "$enrollYear-09-15";

        $username = strtolower($template['first'] . '.' . $template['last']) . $suffix;
        $email = $username . '@student.university.edu.gh';
        $studentId = 'STU' . str_pad((string)($i + 1), 5, '0', STR_PAD_LEFT);

        $students[] = [
            'username' => $username,
            'email' => $email,
            'first_name' => $template['first'],
            'last_name' => $template['last'],
            'suffix' => $suffix,
            'student_id' => $studentId,
            'level_order' => $levelOrder,
            'level_id' => $levelIds[$levelOrder],
            'program_id' => $program['id'],
            'dept_id' => $deptIds[$program['dept']],
            'enrollment_date' => $enrollmentDate,
            'dob' => (2003 - $levelOrder) . '-' . str_pad((string)(($i % 12) + 1), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)(($i % 28) + 1), 2, '0', STR_PAD_LEFT),
        ];
    }

    $createdStudents = [];
    foreach ($students as $index => $s) {
        $existingUser = db_query_one('SELECT id FROM users WHERE username = ? LIMIT 1', [$s['username']]);

        if (!$existingUser) {
            db_execute('INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)',
                [$s['username'], $s['email'], hash_password('student123'), 'student', 1]);
            $userId = (int)db_last_id();
        } else {
            $userId = (int)$existingUser['id'];
        }

        $existingStudent = db_query_one('SELECT id FROM students WHERE user_id = ? LIMIT 1', [$userId]);
        if (!$existingStudent) {
            $phone = '024' . str_pad((string)(1000000 + $index), 7, '0', STR_PAD_LEFT);
            db_execute('INSERT INTO students (student_id, first_name, last_name, phone, date_of_birth, current_level_id, user_id, department_id, program_id, enrollment_date, status, gpa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$s['student_id'], $s['first_name'], $s['last_name'] . ' ' . $s['suffix'], $phone,
                 $s['dob'], $s['level_id'], $userId, $s['dept_id'], $s['program_id'], $s['enrollment_date'], 'active', 0.00]);
            $studentInternalId = (int)db_last_id();
        } else {
            $studentInternalId = (int)$existingStudent['id'];
        }

        $createdStudents[] = array_merge($s, ['id' => $studentInternalId, 'user_id' => $userId]);
    }

    log_step("Created " . count($createdStudents) . " students across all levels", 'success');
    log_step("  - Usernames use deterministic firstname.lastnameXX format (e.g., ama.mensah01)", 'info');
    log_step("  - All seeded student passwords are student123", 'info');

    return $createdStudents;
}

function seed_historical_sections($courseIds, $instructorIds, $semesters, $deptIds) {
    log_step("Creating course sections for all semesters...");
    
    $sections = [];
    
    foreach ($semesters as $sem) {
        $isCurrentSemester = !empty($sem['is_current']);
        
        $semesterNumber = $sem['name'] === 'First Semester' ? 1 : 2;
        
        foreach ($courseIds as $code => $info) {
            if ($info['semester'] !== $semesterNumber) {
                continue;
            }

            $deptInstructors = $instructorIds[$info['dept']] ?? [];
            if (empty($deptInstructors)) {
                continue;
            }

            $instructorId = $deptInstructors[array_rand($deptInstructors)];
            $sectionName = 'A';
            $schedule = ['Mon/Wed 9:00-10:30', 'Tue/Thu 11:00-12:30', 'Mon/Wed 14:00-15:30', 'Tue/Thu 16:00-17:30'][rand(0, 3)];
            $room = ['LH' . rand(1, 5), 'LAB' . rand(1, 3), 'ROOM ' . rand(101, 305)][rand(0, 2)];

            $existing = db_query_one('SELECT id FROM course_sections WHERE course_id = ? AND section_name = ? AND semester_id = ? LIMIT 1',
                [$info['id'], $sectionName, $sem['id']]);

            if ($existing) {
                $sectionId = (int)$existing['id'];
            } else {
                db_execute('INSERT INTO course_sections (course_id, section_name, instructor_id, schedule, room, capacity, enrolled_count, semester_id, academic_year_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$info['id'], $sectionName, $instructorId, $schedule, $room, 40, 0, $sem['id'], $sem['academic_year_id']]);
                $sectionId = (int)db_last_id();
            }

            $sections[] = [
                'id' => $sectionId,
                'course_id' => $info['id'],
                'course_code' => $code,
                'semester_id' => $sem['id'],
                'academic_year_id' => $sem['academic_year_id'],
                'instructor_id' => $instructorId,
                'level' => $info['level'],
                'is_current' => $isCurrentSemester,
            ];
        }
    }
    
    log_step("Created " . count($sections) . " course sections", 'success');
    return $sections;
}

function seed_historical_enrollments($students, $sections, $semesters, $academicYears) {
    log_step("Seeding historical enrollments with chronological accuracy...");
    
    $enrollmentCount = 0;
    $gradeCount = 0;
    
    // Prepare semester and section indexes using helper closures to keep main loop simple
    $allSemesters = db_query('SELECT id, semester_name, start_date, end_date, academic_year_id FROM semesters WHERE is_current = 0 ORDER BY start_date ASC');

    $indexSemestersByAcademicYear = function (array $allSem): array {
        $map = [];
        foreach ($allSem as $s) {
            $map[$s['academic_year_id']][] = $s;
        }
        return $map;
    };

    $indexSections = function (array $secs): array {
        $byLevelSemester = [];
        $bySemester = [];
        foreach ($secs as $sec) {
            if (!empty($sec['is_current'])) {
                continue;
            }
            $byLevelSemester[$sec['level']][$sec['semester_id']][] = $sec;
            $bySemester[$sec['semester_id']][] = $sec;
        }
        return [$byLevelSemester, $bySemester];
    };

    $semestersByAcademicYear = $indexSemestersByAcademicYear($allSemesters);
    list($sectionsByLevelSemester, $sectionsBySemester) = $indexSections($sections);
    
    foreach ($students as $sIndex => $student) {
        $studentEnrollDate = strtotime($student['enrollment_date']);
        $currentLevel = (int)$student['level_order'];
        
        // Build a set of course_ids already enrolled for this student to prevent duplicates
        $enrolledCourseIds = [];
        $already = db_query('SELECT cs.course_id FROM enrollments e JOIN course_sections cs ON cs.id = e.course_section_id WHERE e.student_id = ?', [$student['id']]);
        foreach ($already as $r) {
            $enrolledCourseIds[(int)$r['course_id']] = true;
        }

        // For each level the student has completed (Level 100 up to their current level - 1)
        for ($level = 1; $level < $currentLevel; $level++) {
            $enrollYear = (int)date('Y', $studentEnrollDate);
            $targetStartYear = $enrollYear + ($level - 1);
            $targetYearName = $targetStartYear . '/' . ($targetStartYear + 1);

            $targetAcademicYearId = null;
            foreach ($academicYears as $ay) {
                if ($ay['name'] === $targetYearName) {
                    $targetAcademicYearId = $ay['id'];
                    break;
                }
            }
            if ($targetAcademicYearId === null) {
                continue;
            }

            $levelSemesters = $semestersByAcademicYear[$targetAcademicYearId] ?? [];
            if (empty($levelSemesters)) {
                continue;
            }

            foreach ($levelSemesters as $semInfo) {
                $sectionsForSemester = $sectionsByLevelSemester[$level][$semInfo['id']] ?? [];

                if (count($sectionsForSemester) < 2) {
                    $allSemSections = $sectionsBySemester[$semInfo['id']] ?? [];
                    foreach ($allSemSections as $sec) {
                        if (count($sectionsForSemester) >= 2) {
                            break;
                        }
                        if (!empty($enrolledCourseIds[$sec['course_id']])) {
                            continue;
                        }
                        $courseRow = db_query_one('SELECT l.level_order FROM courses c JOIN levels l ON l.id = c.level_id WHERE c.id = ? LIMIT 1', [$sec['course_id']]);
                        if (!$courseRow) {
                            continue;
                        }
                        $courseLevel = (int)$courseRow['level_order'];
                        if ($courseLevel <= $level) {
                            $alreadyAdded = false;
                            foreach ($sectionsForSemester as $existing) {
                                if ($existing['course_id'] == $sec['course_id']) {
                                    $alreadyAdded = true;
                                    break;
                                }
                            }
                            if (!$alreadyAdded) {
                                $sectionsForSemester[] = $sec;
                            }
                        }
                    }
                }

                if (empty($sectionsForSemester)) {
                    continue;
                }

                $enrollCount = min(2, count($sectionsForSemester));
                for ($n = 0; $n < $enrollCount; $n++) {
                    $sec = $sectionsForSemester[($sIndex + $n) % count($sectionsForSemester)];

                    if (!empty($enrolledCourseIds[$sec['course_id']])) {
                        continue;
                    }

                    $exists = db_query_one('SELECT e.id FROM enrollments e JOIN course_sections cs ON cs.id = e.course_section_id WHERE e.student_id = ? AND cs.course_id = ? LIMIT 1', [$student['id'], $sec['course_id']]);
                    if ($exists) {
                        $enrolledCourseIds[$sec['course_id']] = true;
                        continue;
                    }

                    db_execute('INSERT INTO enrollments (student_id, course_section_id, semester_id, academic_year_id, enrollment_date, status) VALUES (?, ?, ?, ?, ?, ?)', [$student['id'], $sec['id'], $sec['semester_id'], $sec['academic_year_id'], $semInfo['start_date'], 'completed']);
                    $enrollmentId = (int)db_last_id();
                    $enrollmentCount++;
                    db_execute('UPDATE course_sections SET enrolled_count = enrolled_count + 1 WHERE id = ?', [$sec['id']]);

                    $assessments = [
                        ['Quiz 1', 'quiz', 15, rand(10, 15)],
                        ['Quiz 2', 'quiz', 15, rand(10, 15)],
                        ['Assignment', 'assignment', 20, rand(14, 20)],
                        ['Final Exam', 'final', 50, rand(30, 50)],
                    ];
                    $semStart = strtotime($semInfo['start_date']);
                    $semEnd = strtotime($semInfo['end_date']);
                    foreach ($assessments as $idx => $assess) {
                        $daysOffset = (int)(($semEnd - $semStart) * (($idx + 1) / (count($assessments) + 1)));
                        $gradeDate = date('Y-m-d', $semStart + $daysOffset);
                        db_execute('INSERT INTO grades (enrollment_id, assessment_name, assessment_type, score, max_score, weight, grade_date) VALUES (?, ?, ?, ?, ?, ?, ?)', [$enrollmentId, $assess[0], $assess[1], $assess[3], $assess[2], $assess[2], $gradeDate]);
                        $gradeCount++;
                    }
                    calculate_enrollment_final_grade($enrollmentId);
                    db_execute('UPDATE enrollments SET status = ? WHERE id = ?', ['completed', $enrollmentId]);
                    $enrolledCourseIds[$sec['course_id']] = true;
                }
            }
        }
    }
    
    log_step("Created $enrollmentCount historical enrollments", 'success');
    log_step("Created $gradeCount grade records", 'success');
    log_step("Enrollment dates use actual semester start dates based on each student's enrollment date and academic progression", 'info');
}

function seed_current_semester_enrollments($students, $sections) {
    log_step("Seeding current semester enrollments for immediate testing...");

    $currentSections = array_values(array_filter($sections, fn($sec) => !empty($sec['is_current'])));
    if (empty($currentSections)) {
        log_step("No current semester sections were detected; skipping current enrollments.", 'info');
        return;
    }

    // Get current semester start date from database
    $currentSemester = db_query_one('SELECT id, start_date FROM semesters WHERE is_current = 1 LIMIT 1');
    $enrollmentDate = $currentSemester ? $currentSemester['start_date'] : date('Y-m-d');

    $sectionsByLevel = [];
    foreach ($currentSections as $sec) {
        $sectionsByLevel[$sec['level']][] = $sec;
    }

    $created = 0;
    foreach ($students as $index => $student) {
        $level = $student['level_order'];
        if (empty($sectionsByLevel[$level])) {
            continue;
        }
        $levelSections = $sectionsByLevel[$level];
        $sectionCount = count($levelSections);
        if ($sectionCount < 2) {
            continue;
        }
        $desired = min(3, $sectionCount);
        $desired = max(2, $desired);

        for ($n = 0; $n < $desired; $n++) {
            $sec = $levelSections[($index + $n) % $sectionCount];
            $exists = db_query_one('SELECT id FROM enrollments WHERE student_id = ? AND course_section_id = ? LIMIT 1', [$student['id'], $sec['id']]);
            if ($exists) {
                continue;
            }
            // Use actual current semester start date, not "now"
            db_execute('INSERT INTO enrollments (student_id, course_section_id, semester_id, academic_year_id, enrollment_date, status) VALUES (?, ?, ?, ?, ?, ?)',
                [$student['id'], $sec['id'], $sec['semester_id'], $sec['academic_year_id'], $enrollmentDate, 'enrolled']);
            db_execute('UPDATE course_sections SET enrolled_count = enrolled_count + 1 WHERE id = ?', [$sec['id']]);
            $created++;
        }
    }

    log_step("Created $created current semester enrollments", 'success');
    log_step("Current semester enrollment dates use actual semester start_date: $enrollmentDate", 'info');
}

function setup_create_admin_user() {
    log_step("Creating admin user...");
    
    $existing = db_query_one('SELECT id FROM users WHERE username = ? LIMIT 1', ['admin']);
    if (!$existing) {
        db_execute('INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)',
            ['admin', 'oboysika000@gmail.com', hash_password('admin123'), 'admin', 1]);
        log_step("Admin user created: username=admin, password=admin123", 'success');
    } else {
        log_step("Admin user already exists", 'info');
    }
}

// =============================================================================
// MAIN EXECUTION
// =============================================================================

try {
    log_step("Starting database setup...", 'info');
    // Allow a force reseed mode to clear historical enrollments/grades before seeding.
    // CLI: pass `--force` | Web: use `?force=1` (development only)
    $force = false;
    if (php_sapi_name() === 'cli') {
        foreach ($argv as $arg) {
            if ($arg === '--force') {
                $force = true;
                break;
            }
        }
    } else {
        $force = isset($_GET['force']) && $_GET['force'] === '1';
    }

    if ($force) {
        log_step('Force reseed requested: clearing historical enrollments and grades...', 'info');

        // Determine current semester cutoff date; keep current semester enrollments
        $currentSemRow = db_query_one('SELECT id, start_date FROM semesters WHERE is_current = 1 LIMIT 1');
        $cutoffDate = $currentSemRow && !empty($currentSemRow['start_date']) ? $currentSemRow['start_date'] : date('Y-m-d');

        // Delete grade records for historical enrollments, then delete those enrollments
        db_execute('DELETE g FROM grades g JOIN enrollments e ON e.id = g.enrollment_id WHERE e.enrollment_date < ? OR e.status = ?', [$cutoffDate, 'completed']);
        db_execute('DELETE FROM enrollments WHERE enrollment_date < ? OR status = ?', [$cutoffDate, 'completed']);

        // Recompute enrolled counts on course_sections to keep counters accurate
        $allSections = db_query('SELECT id FROM course_sections');
        foreach ($allSections as $cs) {
            $countRow = db_query_one('SELECT COUNT(*) AS c FROM enrollments WHERE course_section_id = ?', [$cs['id']]);
            $countVal = $countRow ? (int)$countRow['c'] : 0;
            db_execute('UPDATE course_sections SET enrolled_count = ? WHERE id = ?', [$countVal, $cs['id']]);
        }

        // Ensure a unique index exists to prevent duplicate enrollments at the DB level
        $idx = db_query_one("SELECT 1 AS exists_flag FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments' AND INDEX_NAME = 'idx_enrollments_student_section' LIMIT 1");
        if (!$idx) {
            db_execute('ALTER TABLE enrollments ADD UNIQUE KEY idx_enrollments_student_section (student_id, course_section_id)');
            log_step('Created unique index idx_enrollments_student_section on enrollments(student_id, course_section_id)', 'success');
        } else {
            log_step('Unique index idx_enrollments_student_section already exists', 'info');
        }
        log_step('Force reseed cleanup complete.', 'success');
    }
    
    // Step 1: Create admin (local helper to avoid conflicts with global functions)
    setup_create_admin_user();
    
    // Step 2: Seed reference data
    $academicYears = seed_academic_years();
    $semesters = seed_semesters($academicYears);
    $deptIds = seed_departments();
    $levelIds = seed_levels();
    $courseIds = seed_courses($deptIds, $levelIds);
    $programIds = seed_programs($deptIds);
    
    // Step 3: Map courses to programs
    seed_program_courses($programIds, $courseIds);
    
    // Step 4: Seed people
    $instructorIds = seed_instructors($deptIds);
    $students = seed_students($deptIds, $programIds, $levelIds, $academicYears);

    // Step 4b: Assign advisors to seeded students (even distribution)
    log_step("Assigning advisors to seeded students...");
    $allInstructors = db_query('SELECT id FROM instructors ORDER BY id ASC');
    $instrList = array_map(fn($r) => (int)$r['id'], $allInstructors);
    $instrCount = count($instrList);
    $assigned = 0;
    if ($instrCount > 0) {
        foreach ($students as $si => $stu) {
            $studentInternalId = (int)$stu['id'];
            // Skip if already has an active advisor
            $has = db_query_one('SELECT id FROM student_advisors WHERE student_id = ? AND is_active = 1 LIMIT 1', [$studentInternalId]);
            if ($has) {
                continue;
            }

            $assignTo = $instrList[$si % $instrCount];
            db_execute('INSERT INTO student_advisors (student_id, instructor_id, assigned_date, is_active, created_at) VALUES (?, ?, CURDATE(), 1, NOW())', [$studentInternalId, $assignTo]);
            $assigned++;
        }
    } else {
        log_step('No instructors found to assign as advisors; skipping advisor assignment.', 'info');
    }
    log_step("Assigned $assigned advisors to seeded students", 'success');
    
    // Step 5: Create course sections for all semesters
    $sections = seed_historical_sections($courseIds, $instructorIds, $semesters, $deptIds);
    
    // Step 6: Seed historical enrollments and grades (skipping current semester)
    seed_historical_enrollments($students, $sections, $semesters, $academicYears);
    seed_current_semester_enrollments($students, $sections);
    
    // Step 7: Update GPAs
    log_step("Updating student GPAs...");
    foreach ($students as $s) {
        $gpaData = get_student_gpa_summary($s['id']);
        if ($gpaData && $gpaData['calculated_gpa'] > 0) {
            db_execute('UPDATE students SET gpa = ? WHERE id = ?', [$gpaData['calculated_gpa'], $s['id']]);
        }
    }
    
    echo "<h2 class='success'>✅ Database setup completed successfully!</h2>";
    echo "<div style='background:#e7f3ff;padding:15px;border-left:4px solid #007bff;margin:20px 0;'>";
    echo "<h3>Login Credentials:</h3>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username=<code>admin</code>, password=<code>admin123</code></li>";
    echo "<li><strong>Instructors:</strong> username=<code>kwame.mensah</code> (or any instructor), password=<code>instructor123</code></li>";
    // Show one example student per level so testers can sign in as real seeded users
    $examples = [];
    foreach ($students as $st) {
        $lvl = (int)$st['level_order'];
        if (empty($examples[$lvl])) {
            $examples[$lvl] = $st['username'];
        }
        // Stop early when we have all 4 levels
        if (count($examples) >= 4) {
            break;
        }
    }
    $exampleParts = [];
    for ($lo = 1; $lo <= 4; $lo++) {
        if (!empty($examples[$lo])) {
            $label = 'Level ' . ($lo * 100);
            $exampleParts[] = "$label: username=<code>" . htmlspecialchars($examples[$lo]) . "</code>";
        }
    }
    if (!empty($exampleParts)) {
        echo '<li><strong>Students:</strong> ' . implode(' &nbsp;|&nbsp; ', $exampleParts) . ", password=<code>student123</code> (seeded students use this password)</li>";
    } else {
        echo "<li><strong>Students:</strong> username=<code>ama.mensah01</code>, password=<code>student123</code> (pattern firstname.lastnameXX)</li>";
    }
    echo "</ul>";
    echo "<p><strong>Note:</strong> Current semester enrollments are pre-seeded so student dashboards show courses immediately.</p>";
    echo "</div>";
    
    echo "<div style='margin-top:30px;'>";
    echo "<a href='index.php' style='display:inline-block;padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:5px;'>Go to Home Page</a> ";
    echo "<a href='login.php' style='display:inline-block;padding:10px 20px;background:#28a745;color:white;text-decoration:none;border-radius:5px;'>Go to Login</a>";
    echo "</div>";
    
} catch (Exception $e) {
    log_step("Error: " . $e->getMessage(), 'error');
    log_step("Stack trace: " . $e->getTraceAsString(), 'error');
}

echo "</body></html>";
