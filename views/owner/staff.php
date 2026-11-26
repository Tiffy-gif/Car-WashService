<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('staff.list');
$pageTitle = __('nav_staff', 'Staff & User Management');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Fetch active roles for dropdowns and role mapping
$activeRoles = $pdo->query("SELECT id, code, name_en, name_km, is_commission_eligible, can_assign_washer, can_assign_mechanic FROM roles WHERE is_active = 1 ORDER BY is_system DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$roleCodeMap = [];
foreach ($activeRoles as $ar) {
    $roleCodeMap[$ar['code']] = $ar;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Add New Staff Member
    if ($action === 'create_staff') {
        if (!Auth::can('staff.add')) {
            $message = 'Access denied: You do not have permission to add staff.';
            $messageType = 'danger';
            goto endAction;
        }

        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $pin = trim($_POST['pin'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? 'washer');
        $commType = 'none';
        $commRate = 0.00;

        // Resolve role ID and code
        $matchedRole = $roleCodeMap[$role] ?? null;
        if (!$matchedRole) {
            $rStmt = $pdo->prepare("SELECT id, code FROM roles WHERE code = ? OR id = ? LIMIT 1");
            $rStmt->execute([$role, (int)$role]);
            $matchedRole = $rStmt->fetch();
        }

        if ($fullName === '' || $username === '') {
            $message = 'Full Name and Username are required.';
            $messageType = 'danger';
        } elseif ($pin === '' && $password === '') {
            $message = 'Please provide at least a 6-Digit PIN or a Password (or both).';
            $messageType = 'danger';
        } elseif ($pin !== '' && (strlen($pin) !== 6 || !ctype_digit($pin))) {
            $message = 'PIN must be exactly 6 numeric digits (0-9).';
            $messageType = 'danger';
        } elseif ($password !== '' && strlen($password) < 4) {
            $message = 'Password must be at least 4 characters long.';
            $messageType = 'danger';
        } elseif (!$matchedRole) {
            $message = 'Invalid role selected.';
            $messageType = 'danger';
        } else {
            // Check username uniqueness
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $checkStmt->execute([$username]);
            if ((int)$checkStmt->fetchColumn() > 0) {
                $message = "Username '{$username}' is already taken. Please choose another username.";
                $messageType = 'danger';
            } else {
                $pinHash = ($pin !== '') ? password_hash($pin, PASSWORD_DEFAULT) : null;
                $pwHash  = ($password !== '') ? password_hash($password, PASSWORD_DEFAULT) : null;
                $insStmt = $pdo->prepare("
                    INSERT INTO users (username, password_hash, local_pin_hash, full_name, phone, role, role_id, commission_type, commission_rate, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $insStmt->execute([$username, $pwHash, $pinHash, $fullName, $phone, $matchedRole['code'], (int)$matchedRole['id'], $commType, $commRate]);
                audit_log('STAFF_CREATED', "Added staff '{$fullName}' ({$username}) as role '{$matchedRole['code']}'", 'user');
                $message = "Staff member '{$fullName}' (@{$username}) added successfully!";
            }
        }
    }

    // 2. Edit Staff Member
    elseif ($action === 'edit_staff') {
        if (!Auth::can('staff.edit')) {
            $message = 'Access denied: You do not have permission to edit staff.';
            $messageType = 'danger';
            goto endAction;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $role = trim($_POST['role'] ?? 'washer');
        $commType = 'none';
        $commRate = 0.00;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        // Resolve role ID and code
        $matchedRole = $roleCodeMap[$role] ?? null;
        if (!$matchedRole) {
            $rStmt = $pdo->prepare("SELECT id, code FROM roles WHERE code = ? OR id = ? LIMIT 1");
            $rStmt->execute([$role, (int)$role]);
            $matchedRole = $rStmt->fetch();
        }

        if ($userId <= 0 || $fullName === '' || $username === '') {
            $message = 'Full Name and Username are required.';
            $messageType = 'danger';
        } elseif (!$matchedRole) {
            $message = 'Invalid role selected.';
            $messageType = 'danger';
        } else {
            // Fetch current user
            $curStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $curStmt->execute([$userId]);
            $currentUser = $curStmt->fetch();

            if (!$currentUser) {
                $message = 'User not found.';
                $messageType = 'danger';
            } else {
                // Check last owner safety: cannot demote or deactivate last active owner
                if ($currentUser['role'] === 'owner' && ($matchedRole['code'] !== 'owner' || $status === 'inactive')) {
                    $ownerCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active'");
                    $activeOwners = (int)$ownerCountStmt->fetchColumn();
                    if ($activeOwners <= 1) {
                        $message = 'Action blocked: You cannot deactivate or demote the last active Owner account.';
                        $messageType = 'danger';
                        goto endAction;
                    }
                }

                // Check username uniqueness if changed
                if ($username !== $currentUser['username']) {
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
                    $checkStmt->execute([$username, $userId]);
                    if ((int)$checkStmt->fetchColumn() > 0) {
                        $message = "Username '{$username}' is already in use by another user.";
                        $messageType = 'danger';
                        goto endAction;
                    }
                }

                $newPin = trim($_POST['new_pin'] ?? '');
                $newPassword = trim($_POST['new_password'] ?? '');

                if ($newPin !== '' && (strlen($newPin) !== 6 || !ctype_digit($newPin))) {
                    $message = 'PIN must be exactly 6 numeric digits (0-9).';
                    $messageType = 'danger';
                    goto endAction;
                }
                if ($newPassword !== '' && strlen($newPassword) < 4) {
                    $message = 'Password must be at least 4 characters long.';
                    $messageType = 'danger';
                    goto endAction;
                }

                $updSql = "UPDATE users SET full_name = ?, phone = ?, username = ?, role = ?, role_id = ?, commission_type = ?, commission_rate = ?, status = ?";
                $updParams = [$fullName, $phone, $username, $matchedRole['code'], (int)$matchedRole['id'], $commType, $commRate, $status];

                if ($newPin !== '') {
                    $updSql .= ", local_pin_hash = ?";
                    $updParams[] = password_hash($newPin, PASSWORD_DEFAULT);
                }
                if ($newPassword !== '') {
                    $updSql .= ", password_hash = ?";
                    $updParams[] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $updSql .= " WHERE id = ?";
                $updParams[] = $userId;

                $updStmt = $pdo->prepare($updSql);
                $updStmt->execute($updParams);

                if ($currentUser['role'] !== $matchedRole['code']) {
                    audit_log('ROLE_CHANGE', "Staff role for '{$username}' changed from '{$currentUser['role']}' to '{$matchedRole['code']}'", 'user', (string)$userId);
                }

                $message = "Staff profile for '{$fullName}' updated successfully!";
            }
        }
    }

    // 3. Reset Staff Credentials (PIN and/or Password)
    elseif ($action === 'reset_password') {
        if (!Auth::can('staff.reset_password')) {
            $message = 'Access denied: You do not have permission to reset credentials.';
            $messageType = 'danger';
            goto endAction;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $newPin = trim($_POST['new_pin'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');

        if ($userId <= 0) {
            $message = 'Invalid user selected.';
            $messageType = 'danger';
            goto endAction;
        }
        if ($newPin === '' && $newPass === '') {
            $message = 'Please provide at least a new 6-digit PIN or a new Password (or both).';
            $messageType = 'danger';
            goto endAction;
        }
        if ($newPin !== '' && (strlen($newPin) !== 6 || !ctype_digit($newPin))) {
            $message = 'PIN must be exactly 6 numeric digits (0-9).';
            $messageType = 'danger';
            goto endAction;
        }
        if ($newPass !== '' && strlen($newPass) < 4) {
            $message = 'Password must be at least 4 characters long.';
            $messageType = 'danger';
            goto endAction;
        }

        $curU = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
        $curU->execute([$userId]);
        $uRow = $curU->fetch();
        $targetName = $uRow['username'] ?? (string)$userId;

        $rstSql = "UPDATE users SET failed_login_attempts = 0, locked_until = NULL";
        $rstParams = [];

        if ($newPin !== '') {
            $rstSql .= ", local_pin_hash = ?, must_change_pin = 1";
            $rstParams[] = password_hash($newPin, PASSWORD_DEFAULT);
        }
        if ($newPass !== '') {
            $rstSql .= ", password_hash = ?";
            $rstParams[] = password_hash($newPass, PASSWORD_DEFAULT);
        }

        $rstSql .= " WHERE id = ?";
        $rstParams[] = $userId;

        $pdo->prepare($rstSql)->execute($rstParams);
        audit_log('CREDENTIALS_RESET', "Reset login credentials for user '{$targetName}'", 'user', (string)$userId);
        $message = "Login credentials successfully reset for {$targetName}! Account unlocked.";
    }

    // 4. Quick Toggle Status (Activate / Deactivate)
    elseif ($action === 'toggle_status') {
        if (!Auth::can('staff.edit')) {
            $message = 'Access denied: You do not have permission to edit staff status.';
            $messageType = 'danger';
            goto endAction;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $curStmt = $pdo->prepare("SELECT role, status, username FROM users WHERE id = ?");
        $curStmt->execute([$userId]);
        $u = $curStmt->fetch();

        if ($u) {
            $newStatus = ($u['status'] === 'active') ? 'inactive' : 'active';

            // Safety check if deactivating owner
            if ($u['role'] === 'owner' && $newStatus === 'inactive') {
                $ownerCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active'");
                if ((int)$ownerCountStmt->fetchColumn() <= 1) {
                    $message = 'Action blocked: Cannot deactivate the last remaining active Owner account.';
                    $messageType = 'danger';
                    goto endAction;
                }
            }

            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
            audit_log('USER_STATUS_CHANGE', "Changed status of user '{$u['username']}' to " . strtoupper($newStatus), 'user', (string)$userId);
            $message = "User status changed to " . strtoupper($newStatus) . "!";
        }
    }

    // 5. Delete Staff Member (Soft Delete: Marks Inactive, Never Hard Deletes)
    elseif ($action === 'delete_staff') {
        if (!Auth::can('staff.delete')) {
            $message = 'Access denied: You do not have permission to delete staff.';
            $messageType = 'danger';
            goto endAction;
        }

        $userId = (int)($_POST['user_id'] ?? 0);
        $curStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $curStmt->execute([$userId]);
        $u = $curStmt->fetch();

        if ($u) {
            if ($u['role'] === 'owner') {
                $ownerCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active'");
                if ((int)$ownerCountStmt->fetchColumn() <= 1) {
                    $message = 'Action blocked: Cannot delete the last active Owner account.';
                    $messageType = 'danger';
                    goto endAction;
                }
            }
            if ($userId === Auth::getUserId()) {
                $message = 'Action blocked: You cannot delete your own account.';
                $messageType = 'danger';
                goto endAction;
            }

            // SOFT DELETE per requirement: "Nothing is permanently deleted anywhere. Use an 'inactive' flag instead."
            $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$userId]);
            audit_log('USER_DEACTIVATED', "Soft-deleted (deactivated) staff member '{$u['full_name']}' ({$u['username']})", 'user', (string)$userId);
            $message = "Staff member '{$u['full_name']}' marked inactive. Historical orders remain safe.";
        }
    }

    // 5b. Toggle Demo Login Mode (Only Owner)
    elseif ($action === 'toggle_demo_mode') {
        if (Auth::getRole() !== 'owner') {
            $message = 'Access denied: Only the Shop Owner has permission to toggle Test Mode.';
            $messageType = 'danger';
            goto endAction;
        }
        $newMode = ($_POST['demo_mode'] === '1') ? '1' : '0';
        $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('demo_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('demo_mode', ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
        }
        $setStmt->execute([$newMode]);
        audit_log('TEST_MODE_TOGGLE', "Owner toggled Test Mode to " . ($newMode === '1' ? 'ON' : 'OFF'), 'setting', 'demo_mode');
        $message = ($newMode === '1') ? "Test Mode & 1-Click Demo Logins ENABLED." : "Test Mode DISABLED. Real username & PIN required.";
    }

    // 6. Dismiss Password Reminder
    elseif ($action === 'dismiss_pw_reminder') {
        $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('default_pw_dismissed', '1') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('default_pw_dismissed', '1') ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
        }
        $setStmt->execute();
    }
}
endAction:

// Fetch all staff members
$staffList = $pdo->query("
    SELECT u.*, r.name_en as role_name_en, r.name_km as role_name_km
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id OR u.role = r.code
    ORDER BY u.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch settings
$settings = [];
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $setStmt->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
$demoMode = $settings['demo_mode'] ?? '0';
$pwReminderDismissed = ($settings['default_pw_dismissed'] ?? '0') === '1';

// Detect if any sample password is still active
$defaultPasswordsInUse = [];
$sampleCredentials = [
    'owner' => 'admin123',
    'cashier' => 'cashier123',
    'washer1' => 'washer123',
    'washer2' => 'washer123'
];
foreach ($staffList as $st) {
    if (isset($sampleCredentials[$st['username']])) {
        if (password_verify($sampleCredentials[$st['username']], $st['password_hash'])) {
            $defaultPasswordsInUse[] = $st['username'];
        }
    }
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <i class="fa-solid fa-user-shield mr-1"></i> <?= count($staffList) ?> Team Members
        </span>
        <span style="font-size: 13px; color: var(--text-muted);">
            Staff Accounts, Roles &amp; Security PINs
        </span>
    </div>
    <div>
        <?php if (Auth::can('staff.add')): ?>
            <button type="button" class="btn btn-primary" onclick="openNewStaffModal()">
                <i class="fa-solid fa-user-plus mr-1"></i> Add New Staff Member
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Security Reminder Banner (if default passwords are detected and not dismissed) -->
<?php if (!empty($defaultPasswordsInUse) && !$pwReminderDismissed): ?>
    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 16px 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <i class="fa-solid fa-triangle-exclamation" style="color: #d97706; font-size: 20px; margin-top: 2px;"></i>
            <div>
                <div style="font-weight: 700; color: #92400e; font-size: 14.5px;">Security Recommendation: Default Passwords Detected</div>
                <div class="text-xs" style="color: #b45309; margin-top: 2px;">
                    The accounts (<strong><?= htmlspecialchars(implode(', ', $defaultPasswordsInUse)) ?></strong>) are still using demo sample passwords (e.g. <code>admin123</code>, <code>cashier123</code>). 
                    Before using the system with real money, please reset their passwords and turn off Quick Demo Login below.
                </div>
            </div>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dismiss_pw_reminder">
            <button type="submit" class="btn btn-secondary btn-sm" style="font-size: 12px;">
                Dismiss Reminder
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- Demo Mode & Production Toggle Bar -->
<div class="card" style="margin-bottom: 24px; padding: 16px 22px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div class="stat-icon <?= ($demoMode === '1') ? 'amber' : 'green' ?>" style="width: 44px; height: 44px; font-size: 18px;">
                <i class="fa-solid <?= ($demoMode === '1') ? 'fa-bolt' : 'fa-shield-halved' ?>"></i>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">
                    Quick Demo Login Buttons: 
                    <span style="color: <?= ($demoMode === '1') ? '#d97706' : '#16a34a' ?>;">
                        <?= ($demoMode === '1') ? 'ENABLED (Testing Mode)' : 'DISABLED (Production Mode)' ?>
                    </span>
                </div>
                <div class="text-xs text-muted">
                    <?= ($demoMode === '1') ? '1-click login buttons are currently visible on the login screen for testing convenience.' : '1-click buttons are hidden. Users must type their actual username & password to enter.' ?>
                </div>
            </div>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_demo_mode">
            <input type="hidden" name="demo_mode" value="<?= ($demoMode === '1') ? '0' : '1' ?>">
            <button type="submit" class="btn btn-sm <?= ($demoMode === '1') ? 'btn-danger' : 'btn-primary' ?>">
                <i class="fa-solid <?= ($demoMode === '1') ? 'fa-lock' : 'fa-lock-open' ?> mr-1"></i>
                <?= ($demoMode === '1') ? 'Disable Demo Login (Go Production)' : 'Enable Demo Login (For Testing)' ?>
            </button>
        </form>
    </div>
</div>

<!-- Search Bar -->
<div class="card" style="margin-bottom: 20px; padding: 14px 20px;">
    <div style="position: relative;">
        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 16px; top: 14px; color: var(--text-muted);"></i>
        <input type="text" id="tableSearchInput" class="form-input" style="padding-left: 44px; min-height: 42px;" placeholder="Search staff by name, username, or phone...">
    </div>
</div>

<!-- Staff List Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table searchable-table js-paginated-table">
            <thead>
                <tr>
                    <th style="width: 180px;">Full Name</th>
                    <th style="width: 130px;">Username</th>
                    <th style="width: 120px;">Phone</th>
                    <th style="width: 120px;">Role</th>
                    <th style="width: 160px;">Login Access</th>
                    <th style="width: 100px;">Status</th>
                    <th style="text-align: right; width: 170px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staffList as $st): 
                    $roleBadges = [
                        'owner'    => 'badge-primary',
                        'cashier'  => 'badge-success',
                        'washer'   => 'badge-purple',
                        'mechanic' => 'badge-amber'
                    ];
                    $rBadge = $roleBadges[$st['role']] ?? 'badge-slate';
                    $safeStaffJson = $st;
                    unset($safeStaffJson['password_hash'], $safeStaffJson['local_pin'], $safeStaffJson['local_pin_hash']);
                ?>
                <tr style="<?= ($st['status'] === 'inactive') ? 'opacity: 0.6; background: var(--bg-subtle);' : '' ?>">
                    <td>
                        <div style="font-weight: 700; color: var(--text-main); font-size: 15px;">
                            <?= htmlspecialchars($st['full_name']) ?>
                        </div>
                        <div class="text-xs text-muted">User ID: #<?= $st['id'] ?></div>
                    </td>
                    <td>
                        <span style="font-family: var(--font-mono); font-weight: 700; color: var(--text-main);">
                            @<?= htmlspecialchars($st['username']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="text-sm"><?= safeStr($st['phone']) ?></span>
                    </td>
                    <td>
                        <?php 
                        $roleTitle = (Language::current() === 'km' && !empty($st['role_name_km'])) ? $st['role_name_km'] : ($st['role_name_en'] ?? ucfirst($st['role']));
                        ?>
                        <span class="badge <?= $rBadge ?>">
                            <?= htmlspecialchars($roleTitle) ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                            <?php if (!empty($st['local_pin_hash'])): ?>
                                <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700;">
                                    <i class="fa-solid fa-key mr-1"></i> 6-Digit PIN
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($st['password_hash'])): ?>
                                <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700;">
                                    <i class="fa-solid fa-lock mr-1"></i> Password
                                </span>
                            <?php endif; ?>
                            <?php if (empty($st['local_pin_hash']) && empty($st['password_hash'])): ?>
                                <span class="badge badge-amber" style="font-size: 11px;">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> None
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php 
                        $isLocked = !empty($st['locked_until']) && (strtotime($st['locked_until']) > time());
                        $lockMins = $isLocked ? (int)ceil((strtotime($st['locked_until']) - time()) / 60) : 0;
                        ?>
                        <?php if ($st['status'] === 'active'): ?>
                            <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Active</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fa-solid fa-ban mr-1"></i> Inactive</span>
                        <?php endif; ?>

                        <?php if ($isLocked): ?>
                            <span class="badge badge-danger" style="background: #991b1b; color: white; margin-top: 4px; display: inline-block;" title="Locked due to 5 wrong tries">
                                <i class="fa-solid fa-lock mr-1"></i> Locked (<?= $lockMins ?>m)
                            </span>
                        <?php elseif (!empty($st['must_change_pin'])): ?>
                            <span class="badge badge-warning" style="margin-top: 4px; display: inline-block;" title="User must change PIN upon login">
                                <i class="fa-solid fa-key mr-1"></i> Change PIN Req
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <div style="display: inline-flex; gap: 6px;">
                            <?php if (Auth::can('staff.edit')): ?>
                                <!-- Edit Button -->
                                <button type="button" class="btn btn-secondary btn-sm" title="Edit Staff Member"
                                        data-staff="<?= htmlspecialchars(json_encode($safeStaffJson, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="openEditStaffModal(this)">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                            <?php endif; ?>

                            <?php if (Auth::can('staff.reset_password')): ?>
                                <!-- Reset Password Button -->
                                <button type="button" class="btn btn-secondary btn-sm" title="Reset Password"
                                        data-user-id="<?= (int)$st['id'] ?>"
                                        data-full-name="<?= htmlspecialchars($st['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-username="<?= htmlspecialchars($st['username'], ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="openResetPasswordModal(this)">
                                    <i class="fa-solid fa-key"></i>
                                </button>
                            <?php endif; ?>

                            <?php if (Auth::can('staff.edit')): ?>
                                <!-- Toggle Status Button (Deactivate / Activate) -->
                                <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to change the active status of this staff member?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $st['id'] ?>">
                                    <?php if ($st['status'] === 'active'): ?>
                                        <button type="submit" class="btn btn-danger btn-sm" title="Deactivate Staff Member">
                                            <i class="fa-solid fa-user-xmark"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" style="color: #16a34a;" title="Reactivate Staff Member">
                                            <i class="fa-solid fa-user-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>

                            <?php if (Auth::can('staff.delete') && $st['role'] !== 'owner'): ?>
                                <!-- Delete Staff Button -->
                                <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete staff member <?= htmlspecialchars($st['full_name'], ENT_QUOTES) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_staff">
                                    <input type="hidden" name="user_id" value="<?= $st['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Delete Staff Member">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal 1: Add New Staff Member -->
<div class="modal-backdrop" id="addStaffModal">
    <div class="modal" style="max-width: 540px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-user-plus mr-1" style="color: var(--primary);"></i> Add New Staff Member</h3>
            <button type="button" class="modal-close" onclick="closeModal('addStaffModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_staff">

            <div class="modal-body">
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-input" placeholder="e.g. Sopheak Touch" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-input" placeholder="e.g. 012 345 678">
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-input" placeholder="e.g. sopheak1" style="font-family: var(--font-mono);" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">System Role *</label>
                        <select name="role" id="add_role" class="form-select">
                            <?php foreach ($activeRoles as $ar): 
                                $rLabel = (Language::current() === 'km') ? ($ar['name_km'] ?: $ar['name_en']) : $ar['name_en'];
                            ?>
                                <option value="<?= htmlspecialchars($ar['code']) ?>" 
                                        <?= ($ar['code'] === 'washer') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rLabel) ?> (<?= htmlspecialchars($ar['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Login Credentials (PIN and/or Password) -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px;">
                    <div style="font-weight: 700; font-size: 13.5px; color: var(--text-main); margin-bottom: 4px;">
                        <i class="fa-solid fa-shield-halved mr-1" style="color: var(--primary);"></i> Login Credentials / ព័ត៌មានសម្ងាត់ចូលប្រើ
                    </div>
                    <div class="text-xs text-muted" style="margin-bottom: 12px;">
                        Set a 6-digit PIN, an alphanumeric Password, or both. At least one is required.
                    </div>
                    <div class="grid grid-cols-2" style="gap: 16px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 12px;">
                                <i class="fa-solid fa-key mr-1" style="color: var(--primary);"></i> 6-Digit Staff PIN (Optional)
                            </label>
                            <input type="password" name="pin" id="add_pin" class="form-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="e.g. 123456" style="letter-spacing: 2px;">
                            <div class="text-xs text-muted" style="margin-top: 4px;">Fast numpad login for bays.</div>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 12px;">
                                <i class="fa-solid fa-lock mr-1" style="color: #64748b;"></i> Password (Optional)
                            </label>
                            <input type="password" name="password" id="add_password" class="form-input" placeholder="e.g. Pass#123">
                            <div class="text-xs text-muted" style="margin-top: 4px;">For web portal login.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addStaffModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> Save Staff Member</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Edit Staff Member -->
<div class="modal-backdrop" id="editStaffModal">
    <div class="modal" style="max-width: 560px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-user-pen mr-1" style="color: var(--primary);"></i> Edit Staff Profile</h3>
            <button type="button" class="modal-close" onclick="closeModal('editStaffModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_staff">
            <input type="hidden" name="user_id" id="edit_user_id" value="">

            <div class="modal-body">
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" id="edit_phone" class="form-input">
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" id="edit_username" class="form-input" style="font-family: var(--font-mono);" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Status *</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active (Can log in)</option>
                            <option value="inactive">Inactive (Deactivated - No login)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">System Role *</label>
                    <select name="role" id="edit_role" class="form-select">
                        <?php foreach ($activeRoles as $ar): 
                            $rLabel = (Language::current() === 'km') ? ($ar['name_km'] ?: $ar['name_en']) : $ar['name_en'];
                        ?>
                            <option value="<?= htmlspecialchars($ar['code']) ?>">
                                <?= htmlspecialchars($rLabel) ?> (<?= htmlspecialchars($ar['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Update Credentials Section -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px;">
                    <div style="font-weight: 700; font-size: 13.5px; color: var(--text-main); margin-bottom: 4px;">
                        <i class="fa-solid fa-shield-halved mr-1" style="color: var(--primary);"></i> Update Credentials (Leave blank to keep unchanged)
                    </div>
                    <div class="grid grid-cols-2" style="gap: 16px; margin-top: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 12px;">
                                <i class="fa-solid fa-key mr-1" style="color: var(--primary);"></i> New 6-Digit PIN
                            </label>
                            <input type="password" name="new_pin" id="edit_new_pin" class="form-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="Leave blank to keep" style="letter-spacing: 2px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 12px;">
                                <i class="fa-solid fa-lock mr-1" style="color: #64748b;"></i> New Password
                            </label>
                            <input type="password" name="new_password" id="edit_new_password" class="form-input" placeholder="Leave blank to keep">
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editStaffModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> Update Staff</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Reset Credentials -->
<div class="modal-backdrop" id="resetPasswordModal">
    <div class="modal" style="max-width: 460px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-key mr-1" style="color: var(--primary);"></i> Reset Staff Credentials</h3>
            <button type="button" class="modal-close" onclick="closeModal('resetPasswordModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id" value="">

            <div class="modal-body">
                <p id="reset_user_label" style="font-weight: 700; color: var(--text-main); margin-bottom: 12px;"></p>
                <div class="text-xs text-muted" style="margin-bottom: 16px;">
                    Provide a new 6-digit PIN, an alphanumeric Password, or both. The account will be unlocked automatically.
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-size: 12px;"><i class="fa-solid fa-key mr-1" style="color: var(--primary);"></i> New 6-Digit Temporary PIN</label>
                    <input type="password" name="new_pin" id="reset_new_pin" class="form-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="e.g. 123456" style="letter-spacing: 2px;">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 12px;"><i class="fa-solid fa-lock mr-1" style="color: #64748b;"></i> New Password</label>
                    <input type="password" name="new_password" id="reset_new_password" class="form-input" placeholder="e.g. Secret#123">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('resetPasswordModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> Update Credentials &amp; Unlock</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewStaffModal() {
    openModal('addStaffModal');
}

function openEditStaffModal(btnOrUser) {
    let user = btnOrUser;
    if (btnOrUser && btnOrUser.getAttribute && btnOrUser.getAttribute('data-staff')) {
        try {
            user = JSON.parse(btnOrUser.getAttribute('data-staff'));
        } catch (e) {
            console.error('Failed to parse staff data', e);
            return;
        }
    }
    if (!user) return;

    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_phone').value = user.phone || '';
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_status').value = user.status;

    openModal('editStaffModal');
}

function openResetPasswordModal(btnOrUserId, fullName, username) {
    let userId = btnOrUserId;
    if (btnOrUserId && btnOrUserId.getAttribute && btnOrUserId.getAttribute('data-user-id')) {
        userId = btnOrUserId.getAttribute('data-user-id');
        fullName = btnOrUserId.getAttribute('data-full-name');
        username = btnOrUserId.getAttribute('data-username');
    }
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_user_label').innerText = 'Resetting password for: ' + fullName + ' (@' + username + ')';
    openModal('resetPasswordModal');
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
