<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('instructor');

$current_page = 'profile.php';
$userId = current_user_id();
$profile = get_instructor_profile($userId);

if (!$profile) {
    set_flash_message('error', 'Unable to load your profile information.');
    redirect('/logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Invalid request token. Please try again.');
        redirect('/instructor/profile.php');
    }
    if (isset($_POST['update_contact'])) {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $_POST['email'] : '';
        $phone = sanitize_input($_POST['phone'] ?? '');
        $mfa = isset($_POST['mfa_email_enabled']) ? 1 : 0;

        if ($email === '') {
            set_flash_message('error', 'A valid email address is required.');
        } else {
            update_instructor_contact($userId, $email, $phone ?: null);
            db_execute('UPDATE users SET mfa_email_enabled = ? WHERE id = ?', [$mfa, $userId]);
            set_flash_message('success', 'Contact details updated successfully.');
        }

        redirect('/instructor/profile.php');
    }

    if (isset($_POST['update_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== $confirmPassword) {
            set_flash_message('error', 'New password and confirmation do not match.');
        } else {
            $result = update_instructor_password($userId, $currentPassword, $newPassword);
            if ($result['success']) {
                set_flash_message('success', 'Password updated successfully.');
            } else {
                set_flash_message('error', $result['message']);
            }
        }

        redirect('/instructor/profile.php');
    }

    if (isset($_POST['forget_trusted'])) {
        $uid = current_user_id();
        if ($uid) { clear_trusted_device_cookie((int)$uid); }
        set_flash_message('success', 'Trusted devices cleared on this browser.');
        redirect('/instructor/profile.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Profile - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <?php render_flash_messages(); ?>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0"><i class="fas fa-id-card text-primary me-2"></i>Instructor Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <span class="text-muted small d-block">Name</span>
                            <strong><?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?></strong>
                        </div>
                        <div class="mb-3">
                            <span class="text-muted small d-block">Department</span>
                            <strong><?php echo htmlspecialchars($profile['dept_name'] ?? 'Unassigned'); ?></strong>
                        </div>
                        <div class="mb-3">
                            <span class="text-muted small d-block">Hire Date</span>
                            <strong><?php echo $profile['hire_date'] ? format_date($profile['hire_date']) : 'N/A'; ?></strong>
                        </div>
                        <div class="mb-3">
                            <span class="text-muted small d-block">Username</span>
                            <strong><?php echo htmlspecialchars($profile['username']); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0"><i class="fas fa-envelope text-success me-2"></i>Contact Information</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <?php echo csrf_field(); ?>
                            <div class="col-12">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($profile['email']); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>">
                            </div>
                            <?php $mfaRow = db_query_one('SELECT mfa_email_enabled FROM users WHERE id = ? LIMIT 1', [$userId]); $mfaEnabled = (int)($mfaRow['mfa_email_enabled'] ?? 0) === 1; ?>
                            <div class="col-12 form-check">
                                <input class="form-check-input" type="checkbox" id="mfa_email_enabled" name="mfa_email_enabled" <?php echo $mfaEnabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="mfa_email_enabled">
                                    Enable Email-based MFA (One-Time Code at login)
                                </label>
                            </div>
                            <div class="col-12">
                                <button type="submit" name="update_contact" class="btn btn-primary">Update Contact</button>
                            </div>
                        </form>
                        <form method="POST" class="mt-3">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="forget_trusted" class="btn btn-outline-secondary btn-sm">Forget trusted devices on this browser</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
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
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
