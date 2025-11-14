<?php
$unreadCount = is_logged_in() ? get_unread_notification_count((int)current_user_id()) : 0;
$recentNotifications = is_logged_in() ? get_user_notifications((int)current_user_id(), false, 5) : [];
?>
<header class="admin-topbar border-bottom bg-white shadow-sm">
    <div class="container-fluid py-2 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="h5 mb-0 fw-semibold text-primary">Admin Panel</h1>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="dropdown">
                <button class="btn btn-link position-relative text-decoration-none" id="adminNotifDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bell fa-lg"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $unreadCount; ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-wide" aria-labelledby="adminNotifDropdown">
                    <h6 class="dropdown-header">Notifications</h6>
                    <?php if (empty($recentNotifications)): ?>
                        <span class="dropdown-item text-muted">No notifications</span>
                    <?php else: ?>
                        <?php foreach ($recentNotifications as $notification): ?>
                            <a class="dropdown-item small" href="/project/admin/notifications.php">
                                <div class="fw-semibold">[<?php echo strtoupper($notification['type']); ?>] <?php echo htmlspecialchars($notification['title']); ?></div>
                                <div class="text-muted">
                                    <?php echo htmlspecialchars($notification['created_at']); ?> · From: <?php echo htmlspecialchars($notification['sender_username'] ?? 'System'); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <div><hr class="dropdown-divider"></div>
                        <a class="dropdown-item" href="/project/admin/notifications.php">View all notifications</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="adminUserDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminUserDropdown">
                    <li><a class="dropdown-item" href="/project/admin/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="/project/admin/manage_users.php"><i class="fas fa-users-cog me-2"></i>Manage Users</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="/project/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</header>
