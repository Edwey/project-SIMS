<?php
$currentAdminPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<aside class="bg-dark text-white p-3" style="min-height:100vh; width:260px;">
  <div class="d-flex align-items-center mb-4">
    <span class="fs-5 fw-semibold">Admin Console</span>
  </div>
  <ul class="nav nav-pills flex-column gap-1">
    <?php
    // Top-level important links
    $top = [
      ['Dashboard', 'dashboard.php'],
      ['Manage Users', 'manage_users.php'],
    ];
    foreach ($top as [$label, $file]) {
      $isActive = $currentAdminPage === $file;
      $classes = 'nav-link text-white d-flex align-items-center';
      $classes .= $isActive ? ' active bg-primary' : ' text-opacity-75';
      echo '<li class="nav-item">'
        . '<a class="' . $classes . '" href="/project/admin/' . $file . '">' . htmlspecialchars($label) . '</a>'
        . '</li>';
    }

    // Grouped dropdowns
    $groups = [
      'Admissions' => [
        ['Admissions', 'admissions.php'],
        ['Admissions Analytics', 'analytics_admissions.php'],
      ],
      'Catalog' => [
        ['Programs', 'catalog_programs.php'],
        ['Requirements', 'catalog_requirements.php'],
        ['Departments', 'manage_departments.php'],
        ['Courses', 'manage_courses.php'],
        ['Levels', 'manage_levels.php'],
      ],
      'Academics' => [
        ['Academic Years', 'manage_academic_years.php'],
        ['Semesters', 'manage_semesters.php'],
        ['Sections', 'manage_sections.php'],
        ['Students', 'manage_students.php'],
        ['Instructors', 'manage_instructors.php'],
        ['Waitlists', 'waitlists.php'],
        ['Enrollments', 'enrollments.php'],
      ],
      'Analytics' => [
        ['Academic Analytics', 'analytics_academics.php'],
        ['Finance Analytics', 'analytics_finance.php'],
      ],
      'Finance' => [
        ['Fee Payments', 'fee_payments.php'],
      ],
      'Notifications' => [
        ['Notifications', 'notifications.php'],
      ],
      'Profile' => [
        ['Profile', 'profile.php'],
      ],
    ];

    $groupIndex = 0;
    foreach ($groups as $groupName => $items) {
      $groupId = 'grp' . (++$groupIndex);
      // Determine if any child is active
      $isGroupActive = false;
      foreach ($items as [$label, $file]) {
        if ($currentAdminPage === $file) { $isGroupActive = true; break; }
      }
      $btnClasses = 'nav-link text-white d-flex justify-content-between align-items-center';
      $btnClasses .= $isGroupActive ? ' bg-secondary' : ' text-opacity-75';
      echo '<li class="nav-item">';
      echo '<button class="' . $btnClasses . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . $groupId . '" aria-expanded="' . ($isGroupActive ? 'true' : 'false') . '" aria-controls="' . $groupId . '">'
          . '<span>' . htmlspecialchars($groupName) . '</span>'
          . '<span class="ms-2">▾</span>'
          . '</button>';
      echo '<div class="collapse ' . ($isGroupActive ? 'show' : '') . '" id="' . $groupId . '">';
      echo '<ul class="nav flex-column ms-3 my-1">';
      foreach ($items as [$label, $file]) {
        $isActive = $currentAdminPage === $file;
        $classes = 'nav-link text-white';
        $classes .= $isActive ? ' active bg-primary' : ' text-opacity-75';
        echo '<li class="nav-item"><a class="' . $classes . '" href="/project/admin/' . $file . '">' . htmlspecialchars($label) . '</a></li>';
      }
      echo '</ul></div></li>';
    }
    ?>
  </ul>
</aside>
