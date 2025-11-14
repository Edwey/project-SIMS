<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'profile.php';
$userId = current_user_id();

$user = db_query_one('SELECT id, username, email FROM users WHERE id = ? LIMIT 1', [$userId]);
if (!$user) {
    set_flash_message('error', 'Unable to load your profile information.');
    redirect('/logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/admin/profile.php');
    }

    if (isset($_POST['update_contact'])) {
        $username = trim($_POST['username'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $_POST['email'] : '';

        if ($username === '' || $email === '') {
            set_flash_message('error', 'Username and a valid email address are required.');
        } else {
            // ensure uniqueness for username/email (excluding self)
            $dupeUser = db_query_one('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1', [$username, $userId]);
            $dupeEmail = db_query_one('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $userId]);
            if ($dupeUser) {
                set_flash_message('error', 'That username is already taken.');
            } elseif ($dupeEmail) {
                set_flash_message('error', 'That email is already in use.');
            } else {
                db_execute('UPDATE users SET username = ?, email = ? WHERE id = ?', [$username, $email, $userId]);
                $_SESSION['username'] = $username;
                set_flash_message('success', 'Profile updated successfully.');
            }
        }
        redirect('/admin/profile.php');
    }

    if (isset($_POST['update_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword === '' || $newPassword !== $confirmPassword) {
            set_flash_message('error', 'New password and confirmation must match.');
        } else {
            $auth = db_query_one('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [$userId]);
            if (!$auth || !verify_password($currentPassword, $auth['password_hash'])) {
                set_flash_message('error', 'Current password is incorrect.');
            } else {
                $hash = hash_password($newPassword);
                db_execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $userId]);
                set_flash_message('success', 'Password updated successfully.');
            }
        }
        redirect('/admin/profile.php');
    }

    if (isset($_POST['forget_trusted'])) {
        $uid = current_user_id();
        if ($uid) { clear_trusted_device_cookie((int)$uid); }
        set_flash_message('success', 'Trusted devices cleared on this browser.');
        redirect('/admin/profile.php');
    }

    if (isset($_POST['forget_all_trusted'])) {
        $uid = current_user_id();
        if ($uid) {
            forget_all_trusted_devices((int)$uid);
            clear_trusted_device_cookie((int)$uid);
        }
        set_flash_message('success', 'All trusted devices revoked. You will be asked for MFA on next login across all devices.');
        redirect('/admin/profile.php');
    }

    if (isset($_POST['revoke_device_id'])) {
        $uid = current_user_id();
        $did = (int)($_POST['revoke_device_id'] ?? 0);
        if ($uid && $did > 0) {
            revoke_trusted_device((int)$uid, $did);
        }
        set_flash_message('success', 'Trusted device revoked.');
        redirect('/admin/profile.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>
<main class="container py-5">
    <?php render_flash_messages(); ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card card-shadow mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="section-title mb-0"><i class="fas fa-user text-primary me-2"></i>Account Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <?php echo csrf_field(); ?>
                        <div class="col-12">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" name="update_contact" class="btn btn-primary">Update Profile</button>
                        </div>
                    </form>
                    <form method="POST" class="mt-3">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="forget_trusted" class="btn btn-outline-secondary btn-sm">Forget trusted devices on this browser</button>
                    </form>
                    <form method="POST" class="mt-2">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="forget_all_trusted" class="btn btn-outline-danger btn-sm">Forget ALL devices</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="section-title mb-0"><i class="fas fa-key text-warning me-2"></i>Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <?php echo csrf_field(); ?>
                        <div class="col-12">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" name="update_password" class="btn btn-warning text-white">Update Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-2">
        <div class="col-12">
            <div class="card card-shadow">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="section-title mb-0"><i class="fas fa-shield-alt text-secondary me-2"></i>Trusted Devices</h5>
                    <form method="POST" class="mb-0">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="forget_all_trusted" class="btn btn-outline-danger btn-sm">Forget ALL devices</button>
                    </form>
                </div>
                <div class="card-body">
                    <?php $devices = get_trusted_devices($userId); ?>
                    <?php if (!$devices): ?>
                        <div class="text-muted">No active trusted devices.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>UA</th>
                                        <th>IP Prefix</th>
                                        <th>Created</th>
                                        <th>Expires</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($devices as $d): ?>
                                    <tr>
                                        <td class="small text-muted">UA#<?php echo htmlspecialchars(substr($d['ua_hash'], 0, 8)); ?></td>
                                        <td><code><?php echo htmlspecialchars($d['ip_prefix']); ?></code></td>
                                        <td class="small text-muted"><?php echo format_datetime($d['created_at']); ?></td>
                                        <td class="small text-muted"><?php echo format_datetime($d['expires_at']); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this device?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="revoke_device_id" value="<?php echo (int)$d['id']; ?>">
                                                <button class="btn btn-sm btn-outline-secondary">Revoke</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php include __DIR__ . '/footer.php'; ?>
