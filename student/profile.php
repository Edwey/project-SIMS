<?php
require_once __DIR__ . '/../includes/functions.php';

require_auth('student');

$current_page = 'profile.php';
$student = get_current_user_profile();

if (!$student) {
    set_flash_message('error', 'Unable to load your profile data.');
    redirect('/logout.php');
}

$studentId = (int)$student['student_internal_id'];
$userId = current_user_id();

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request token. Please try again.';
    } elseif (isset($_POST['update_profile'])) {
        $email = sanitize_input($_POST['email'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        $address = sanitize_input($_POST['address'] ?? '');
        $mfa = isset($_POST['mfa_email_enabled']) ? 1 : 0;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            db_execute('UPDATE students SET email = ?, phone = ?, address = ? WHERE id = ?',
                [$email, $phone, $address, $studentId]);
            db_execute('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
            db_execute('UPDATE users SET mfa_email_enabled = ? WHERE id = ?', [$mfa, $userId]);
            $success = 'Profile updated successfully.';
            $student = get_current_user_profile();
        }
    }

    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $user = db_query_one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!$user || !verify_password($currentPassword, $user['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        } else {
            db_execute('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($newPassword), $userId]);
            $success = 'Password changed successfully.';
        }
    }

    if (isset($_POST['forget_trusted'])) {
        $uid = current_user_id();
        if ($uid) { clear_trusted_device_cookie((int)$uid); }
        $success = 'Trusted devices cleared on this browser.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - University Management System</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <main class="container py-5">
        <div class="row g-4">
            <div class="col-12">
                <div class="profile-hero card-shadow">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="display-6 mb-1">My Profile</h1>
                            <p class="text-muted mb-0">
                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['dept_name']); ?>
                                &middot; <?php echo htmlspecialchars($student['level_name']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <div class="avatar-initials" aria-label="Avatar">
                                <?php echo htmlspecialchars(strtoupper($student['first_name'][0] . $student['last_name'][0])); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Personal Information</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <?php foreach ($errors as $error): ?>
                                    <div><?php echo htmlspecialchars($error); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="row g-3">
                            <?php echo csrf_field(); ?>
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['first_name']); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['last_name']); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Student ID</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['student_id']); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['dept_name']); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Level</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['level_name']); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Enrollment Date</label>
                                <input type="text" class="form-control" value="<?php echo format_date($student['enrollment_date']); ?>" disabled>
                            </div>

                            <div class="col-12"><hr></div>

                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($student['email']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                            </div>

                            <?php $mfaRow = db_query_one('SELECT mfa_email_enabled FROM users WHERE id = ? LIMIT 1', [$userId]); $mfaEnabled = (int)($mfaRow['mfa_email_enabled'] ?? 0) === 1; ?>
                            <div class="col-12 form-check">
                                <input class="form-check-input" type="checkbox" id="mfa_email_enabled" name="mfa_email_enabled" <?php echo $mfaEnabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="mfa_email_enabled">
                                    Enable Email-based MFA (One-Time Code at login)
                                </label>
                            </div>

                            <div class="col-12 text-end">
                                <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                        <form method="POST" class="mt-3">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="forget_trusted" class="btn btn-outline-secondary btn-sm">Forget trusted devices on this browser</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-shadow mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Change Password</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-outline-primary w-100">Update Password</button>
                        </form>
                    </div>
                </div>

                <div class="card card-shadow">
                    <div class="card-body">
                        <h5 class="section-title mb-3">Account Details</h5>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><strong>Username:</strong> <?php echo htmlspecialchars($student['username']); ?></li>
                            <li class="mb-2"><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></li>
                            <li class="mb-2"><strong>Department:</strong> <?php echo htmlspecialchars($student['dept_name']); ?></li>
                            <li class="mb-2"><strong>Level:</strong> <?php echo htmlspecialchars($student['level_name']); ?></li>
                            <li><strong>Enrollment:</strong> <?php echo format_date($student['enrollment_date']); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
