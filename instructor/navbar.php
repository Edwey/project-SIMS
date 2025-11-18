<?php $current_page = $current_page ?? basename($_SERVER['PHP_SELF']); ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?php echo SITE_URL; ?>/instructor/dashboard.php">
      <i class="fas fa-chalkboard-teacher me-1"></i> Instructor
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#instrNav" aria-controls="instrNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="instrNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='dashboard.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='courses.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/courses.php">Courses</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='students.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/students.php">Students</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='gradebook.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/gradebook.php">Gradebook</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='attendance.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/attendance.php">Attendance</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='advising.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/advising.php">Advising</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='waitlists.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/waitlists.php">Waitlists</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $current_page==='notifications.php'?'active':''; ?>" href="<?php echo SITE_URL; ?>/instructor/notifications.php">Notifications<?php $uc = is_logged_in()?get_unread_notification_count((int)current_user_id()):0; if($uc>0){ echo ' <span class=\"badge bg-danger\">'.$uc.'</span>'; } ?></a></li>
      </ul>
      <ul class="navbar-nav">
        <?php $unread = is_logged_in()?get_unread_notification_count((int)current_user_id()):0; $recentNotifs = is_logged_in()?get_user_notifications((int)current_user_id(), false, 5):[]; ?>
        <li class="nav-item dropdown me-2">
          <a class="nav-link dropdown-toggle position-relative" href="#" id="bellDropdownInstr" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-bell"></i>
            <?php if ($unread>0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $unread; ?></span><?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-wide" aria-labelledby="bellDropdownInstr">
            <li class="dropdown-header">Notifications</li>
            <?php if (empty($recentNotifs)): ?>
              <li><span class="dropdown-item text-muted">No notifications</span></li>
            <?php else: foreach ($recentNotifs as $n): ?>
              <li>
                <a class="dropdown-item small" href="<?php echo SITE_URL; ?>/instructor/notifications.php">
                  <div class="fw-semibold">[<?php echo strtoupper($n['type']); ?>] <?php echo htmlspecialchars($n['title']); ?></div>
                  <div class="text-muted">
                    <?php echo htmlspecialchars($n['created_at']); ?> · From: <?php echo htmlspecialchars($n['sender_username'] ?? 'System'); ?>
                  </div>
                </a>
              </li>
            <?php endforeach; endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/instructor/notifications.php">View all</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
              <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/admin/dashboard.php"><i class="fas fa-gauge me-2"></i>Admin Dashboard</a></li>
              <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/admin/manage_sections.php"><i class="fas fa-layer-group me-2"></i>Manage Sections</a></li>
              <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/instructor/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
            <li><a class="dropdown-item text-danger" href="<?php echo SITE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
