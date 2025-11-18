<?php $current_page = $current_page ?? basename($_SERVER['PHP_SELF']); ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?php echo SITE_URL; ?>/student/dashboard.php">Student</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#studentNav" aria-controls="studentNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="studentNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='dashboard.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='my_courses.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/my_courses.php">My Courses</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='grades.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/grades.php">Grades</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='degree_audit.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/degree_audit.php">Degree Audit</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='attendance.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/attendance.php">Attendance</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='transcript.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/transcript.php">Transcript</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='fee_statement.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/fee_statement.php">Fees</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='notifications.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/notifications.php">Notifications<?php $uc = is_logged_in()?get_unread_notification_count((int)current_user_id()):0; if($uc>0){ echo ' <span class="badge bg-danger">'.$uc.'</span>'; } ?></a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='profile.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/student/profile.php">Profile</a></li>
      </ul>
      <ul class="navbar-nav">
        <?php $unread = is_logged_in()?get_unread_notification_count((int)current_user_id()):0; $recentNotifs = is_logged_in()?get_user_notifications((int)current_user_id(), false, 5):[]; ?>
        <li class="nav-item dropdown me-2">
          <a class="nav-link dropdown-toggle position-relative" href="#" id="bellDropdownStudent" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Notifications
            <?php if ($unread>0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $unread; ?></span><?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-wide dropdown-menu-scroll-60" aria-labelledby="bellDropdownStudent">
            <li class="dropdown-header">Notifications</li>
            <?php if (empty($recentNotifs)): ?>
              <li><span class="dropdown-item text-muted">No notifications</span></li>
            <?php else: foreach ($recentNotifs as $n): ?>
              <li>
                <a class="dropdown-item small" href="<?php echo SITE_URL; ?>/student/notifications.php">
                  <div class="fw-semibold">[<?php echo strtoupper($n['type']); ?>] <?php echo htmlspecialchars($n['title']); ?></div>
                  <div class="text-muted">
                    <?php echo htmlspecialchars($n['created_at']); ?> · From: <?php echo htmlspecialchars($n['sender_username'] ?? 'System'); ?>
                  </div>
                </a>
              </li>
            <?php endforeach; endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/student/notifications.php">View all</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-scroll-70" aria-labelledby="userDropdown">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
              <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/admin/dashboard.php">Admin Dashboard</a></li>
              <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/admin/manage_sections.php">Manage Sections</a></li>
              <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/student/profile.php">Profile</a></li>
            <li><a class="dropdown-item text-danger" href="<?php echo SITE_URL; ?>/logout.php">Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
