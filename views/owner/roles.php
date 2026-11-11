<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('roles.list');
$pageTitle = __('roles_management');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';
$action = $_GET['action'] ?? 'list';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// -------------------------------------------------------------
// POST ACTIONS: save_role, toggle_status, delete_role
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $postAction = $_POST['action'] ?? '';

    // Helper to log audit
    $logAudit = function(?int $roleId, string $roleCode, string $act, string $details) use ($pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO role_audit_logs (role_id, role_code, action, details, user_id, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $roleId,
                $roleCode,
                $act,
                $details,
                Auth::getUserId(),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Exception $e) {}
    };

    // 1. SAVE ROLE (Create or Edit)
    if ($postAction === 'save_role') {
        $roleId = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameKm = trim($_POST['name_km'] ?? '');
        $code   = trim($_POST['code'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $isComm = isset($_POST['is_commission_eligible']) ? 1 : 0;
        $canWasher = isset($_POST['can_assign_washer']) ? 1 : 0;
        $canMech   = isset($_POST['can_assign_mechanic']) ? 1 : 0;
        $selectedPerms = $_POST['permissions'] ?? [];

        // Check permission for add/edit
        if ($roleId) {
            if (!Auth::can('roles.edit')) {
                $message = 'Access denied: You do not have permission to edit roles.';
                $messageType = 'danger';
                goto endPost;
            }
        } else {
            if (!Auth::can('roles.add')) {
                $message = 'Access denied: You do not have permission to add roles.';
                $messageType = 'danger';
                goto endPost;
            }
        }

        // Prevent non-owners from editing their own role to gain permissions
        if ($roleId && $roleId === Auth::getRoleId() && Auth::getRole() !== 'owner') {
            $message = 'Security blocked: You cannot edit your own role permissions.';
            $messageType = 'danger';
            goto endPost;
        }

        // Auto-slug code if empty
        if ($code === '') {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $nameEn));
            $code = trim($code, '_');
        } else {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $code));
            $code = trim($code, '_');
        }

        if ($nameEn === '' || $code === '') {
            $message = 'Role name (English) and role code/slug are required.';
            $messageType = 'danger';
        } elseif (empty($selectedPerms)) {
            $message = 'Please select at least one permission for this role.';
            $messageType = 'danger';
        } else {
            if ($nameKm === '') {
                $nameKm = $nameEn;
            }

            // Check if modifying owner system role
            if ($roleId) {
                $chkRole = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
                $chkRole->execute([$roleId]);
                $existingRole = $chkRole->fetch();

                if (!$existingRole) {
                    $message = 'Role not found.';
                    $messageType = 'danger';
                    goto endPost;
                }

                if ($existingRole['code'] === 'owner') {
                    $message = 'The Owner role is locked and cannot be edited.';
                    $messageType = 'danger';
                    goto endPost;
                }
            }

            // Check uniqueness of code
            $dupSql = "SELECT id FROM roles WHERE code = ?";
            $dupParams = [$code];
            if ($roleId) {
                $dupSql .= " AND id != ?";
                $dupParams[] = $roleId;
            }
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute($dupParams);
            if ($dupStmt->fetch()) {
                $message = "A role with the code '{$code}' already exists. Please choose a different code or name.";
                $messageType = 'danger';
            } else {
                $pdo->beginTransaction();
                try {
                    if ($roleId) {
                        $updStmt = $pdo->prepare("
                            UPDATE roles SET 
                                name_en = ?, name_km = ?, code = ?, description = ?,
                                is_commission_eligible = ?, can_assign_washer = ?, can_assign_mechanic = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $updStmt->execute([$nameEn, $nameKm, $code, $desc, $isComm, $canWasher, $canMech, $roleId]);

                        // Sync role string on users table if code changed
                        $pdo->prepare("UPDATE users SET role = ? WHERE role_id = ?")->execute([$code, $roleId]);

                        $logAudit($roleId, $code, 'update', "Updated role '{$nameEn}' ({$code}) with " . count($selectedPerms) . " permissions.");
                        $message = "Role '{$nameEn}' updated successfully!";
                    } else {
                        $insStmt = $pdo->prepare("
                            INSERT INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
                            VALUES (?, ?, ?, ?, 0, 1, ?, ?, ?)
                        ");
                        $insStmt->execute([$code, $nameEn, $nameKm, $desc, $isComm, $canWasher, $canMech]);
                        $roleId = (int)$pdo->lastInsertId();

                        $logAudit($roleId, $code, 'create', "Created new role '{$nameEn}' ({$code}) with " . count($selectedPerms) . " permissions.");
                        $message = "Role '{$nameEn}' created successfully!";
                    }

                    // Update permissions mapping
                    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);

                    // Fetch permission IDs for the selected codes
                    if (!empty($selectedPerms)) {
                        $placeholders = implode(',', array_fill(0, count($selectedPerms), '?'));
                        $pStmt = $pdo->prepare("SELECT id FROM permissions WHERE code IN ({$placeholders})");
                        $pStmt->execute(array_values($selectedPerms));
                        $permIds = $pStmt->fetchAll(PDO::FETCH_COLUMN);

                        $insRp = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                        foreach ($permIds as $pid) {
                            $insRp->execute([$roleId, $pid]);
                        }
                    }

                    $pdo->commit();

                    // Refresh active session permissions if affected
                    if (Auth::getRoleId() === $roleId) {
                        Auth::refreshPermissions();
                    }

                    $action = 'list';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Error saving role: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    // 2. TOGGLE STATUS
    elseif ($postAction === 'toggle_status') {
        if (!Auth::can('roles.edit')) {
            $message = 'Access denied: You do not have permission to modify roles.';
            $messageType = 'danger';
            goto endPost;
        }

        $roleId = (int)($_POST['role_id'] ?? 0);
        $newStatus = (int)($_POST['is_active'] ?? 1);

        $rStmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
        $rStmt->execute([$roleId]);
        $targetRole = $rStmt->fetch();

        if ($targetRole) {
            if ($targetRole['code'] === 'owner') {
                $message = 'Action blocked: The Owner role cannot be disabled.';
                $messageType = 'danger';
            } else {
                $pdo->prepare("UPDATE roles SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                    ->execute([$newStatus, $roleId]);
                $statusLabel = $newStatus ? 'enabled' : 'disabled';
                $logAudit($roleId, $targetRole['code'], 'toggle_status', "Set status to {$statusLabel}.");
                $message = "Role '{$targetRole['name_en']}' has been {$statusLabel}.";
            }
        }
    }

    // 3. DELETE ROLE
    elseif ($postAction === 'delete_role') {
        if (!Auth::can('roles.delete')) {
            $message = 'Access denied: You do not have permission to delete roles.';
            $messageType = 'danger';
            goto endPost;
        }

        $roleId = (int)($_POST['role_id'] ?? 0);
        $rStmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
        $rStmt->execute([$roleId]);
        $targetRole = $rStmt->fetch();

        if ($targetRole) {
            if ($targetRole['is_system'] == 1 || $targetRole['code'] === 'owner') {
                $message = "Action blocked: Core system role '{$targetRole['name_en']}' cannot be deleted.";
                $messageType = 'danger';
            } else {
                // Check if any users are currently assigned to this role
                $uStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ? OR role = ?");
                $uStmt->execute([$roleId, $targetRole['code']]);
                $assignedCount = (int)$uStmt->fetchColumn();

                if ($assignedCount > 0) {
                    $message = "Cannot delete role '{$targetRole['name_en']}' because {$assignedCount} staff member(s) are currently assigned to it. Please reassign them to another role or disable this role instead.";
                    $messageType = 'danger';
                } else {
                    try {
                        // SOFT DELETE per requirement: "Nothing is permanently deleted anywhere. Use an 'inactive' flag instead."
                        $pdo->prepare("UPDATE roles SET is_active = 0 WHERE id = ?")->execute([$roleId]);
                        $logAudit($roleId, $targetRole['code'], 'delete', "Deactivated custom role '{$targetRole['name_en']}' (soft delete).");
                        audit_log('ROLE_CHANGE', "Soft-deleted (deactivated) custom role '{$targetRole['name_en']}' ({$targetRole['code']})", 'role', (string)$roleId);
                        $message = "Role '{$targetRole['name_en']}' marked inactive (soft deleted).";
                    } catch (Exception $e) {
                        $message = 'Error deleting role: ' . $e->getMessage();
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}
endPost:

// Fetch all permissions grouped by Area -> Module
$permRows = $pdo->query("SELECT * FROM permissions ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$permissionsByArea = [
    'sales' => [
        'title_en' => 'Sales & Operations',
        'title_km' => 'ផ្នែកលក់ & ប្រតិបត្តិការ',
        'modules' => []
    ],
    'catalog' => [
        'title_en' => 'Catalog & Inventory',
        'title_km' => 'ទំនិញ សេវាកម្ម & ស្តុក',
        'modules' => []
    ],
    'people' => [
        'title_en' => 'People & Staff',
        'title_km' => 'អតិថិជន & បុគ្គលិក',
        'modules' => []
    ],
    'reports' => [
        'title_en' => 'Reports & Finances',
        'title_km' => 'របាយការណ៍ & ហិរញ្ញវត្ថុ',
        'modules' => []
    ],
    'settings' => [
        'title_en' => 'Administration & Settings',
        'title_km' => 'ការកំណត់ទូទៅ & ប្រព័ន្ធ',
        'modules' => []
    ],
];

// Module title mapping for cards
$moduleMeta = [
    'pos'             => ['en' => 'New Order / POS', 'km' => 'ផ្ទាំងលក់ POS'],
    'orders'          => ['en' => 'Orders & Queue', 'km' => 'ការកុម្ម៉ង់ & ជួររង់ចាំ'],
    'daily_close'     => ['en' => 'Daily Close (POS)', 'km' => 'បិទបញ្ជីប្រចាំថ្ងៃ'],
    'washer_jobs'     => ['en' => 'Washer Jobs Screen', 'km' => 'ផ្ទាំងការងារលាងរថយន្ត'],
    'mechanic_jobs'   => ['en' => 'Mechanic Jobs Screen', 'km' => 'ផ្ទាំងការងារជួសជុល'],
    'vehicle_history' => ['en' => 'Vehicle Repair History', 'km' => 'ប្រវត្តិជួសជុលរថយន្ត'],

    'services'        => ['en' => 'Services & Prices', 'km' => 'សេវាកម្ម & តម្លៃ'],
    'categories'      => ['en' => 'Category Control', 'km' => 'គ្រប់គ្រងប្រភេទ'],
    'oil_care'        => ['en' => 'Oil & Car Care', 'km' => 'ប្រេងម៉ាស៊ីន & ថែទាំ'],
    'extras'          => ['en' => 'Extras & Stock', 'km' => 'ទំនិញ & ស្តុកបន្ថែម'],

    'customers'       => ['en' => 'Customers & Vehicles', 'km' => 'អតិថិជន & យានយន្ត'],
    'staff'           => ['en' => 'Manage Staff', 'km' => 'គ្រប់គ្រងបុគ្គលិក'],
    'roles'           => ['en' => 'Roles & Permissions', 'km' => 'តួនាទី & សិទ្ធិ'],
    'commissions'     => ['en' => 'Crew Commissions', 'km' => 'កម្រៃជើងសារ'],

    'dashboard'       => ['en' => 'Dashboard', 'km' => 'ផ្ទាំងគ្រប់គ្រង'],
    'reports'         => ['en' => 'Reports & Profits', 'km' => 'របាយការណ៍ & ប្រាក់ចំណេញ'],
    'expenses'        => ['en' => 'Daily Expenses', 'km' => 'ចំណាយប្រចាំថ្ងៃ'],

    'settings'        => ['en' => 'Shop Settings', 'km' => 'ការកំណត់ហាង'],
    'pos_devices'     => ['en' => 'POS Devices & API', 'km' => 'ឧបករណ៍ POS & API'],
];

foreach ($permRows as $p) {
    $area = $p['area'] ?? 'sales';
    if (!isset($permissionsByArea[$area])) {
        $area = 'sales';
    }
    $mod = $p['module'];
    if (!isset($permissionsByArea[$area]['modules'][$mod])) {
        $permissionsByArea[$area]['modules'][$mod] = [
            'name_en' => $moduleMeta[$mod]['en'] ?? ucfirst(str_replace('_', ' ', $mod)),
            'name_km' => $moduleMeta[$mod]['km'] ?? $moduleMeta[$mod]['en'] ?? ucfirst($mod),
            'items' => []
        ];
    }
    $permissionsByArea[$area]['modules'][$mod]['items'][] = $p;
}

// -------------------------------------------------------------
// PREPARE DATA FOR FORM (Add, Edit, Duplicate)
// -------------------------------------------------------------
$editRole = null;
$selectedPermissionCodes = [];

if (in_array($action, ['edit', 'duplicate'], true)) {
    $targetId = (int)($_GET['id'] ?? 0);
    if ($targetId > 0) {
        $rStmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
        $rStmt->execute([$targetId]);
        $editRole = $rStmt->fetch();

        if ($editRole) {
            $pStmt = $pdo->prepare("
                SELECT p.code 
                FROM role_permissions rp 
                JOIN permissions p ON rp.permission_id = p.id 
                WHERE rp.role_id = ?
            ");
            $pStmt->execute([$targetId]);
            $selectedPermissionCodes = $pStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($action === 'duplicate') {
                $editRole['id'] = null;
                $editRole['code'] = $editRole['code'] . '_copy';
                $editRole['name_en'] = $editRole['name_en'] . ' (Copy)';
                $editRole['name_km'] = $editRole['name_km'] . ' (ចម្លង)';
                $editRole['is_system'] = 0;
            }
        }
    }
}

// Total permissions count in system
$totalPermissionsCount = count($permRows);

// If action is list, fetch all roles with stats
$rolesList = [];
if ($action === 'list') {
    $rolesList = $pdo->query("
        SELECT r.*,
               COUNT(DISTINCT u.id) as users_count,
               COUNT(DISTINCT rp.permission_id) as permissions_count
        FROM roles r
        LEFT JOIN users u ON u.role_id = r.id OR u.role = r.code
        LEFT JOIN role_permissions rp ON rp.role_id = r.id
        GROUP BY r.id
        ORDER BY r.is_system DESC, r.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<style>
/* Custom Styling for Roles & Permissions (Project Azure / Sky Blue System) */
.role-form-container {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: var(--radius-lg, 16px);
    box-shadow: var(--shadow-sm, 0 1px 2px 0 rgba(0, 0, 0, 0.05));
    padding: 32px 28px;
    margin-bottom: 30px;
}
.role-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted, #64748b);
}
.role-breadcrumb a {
    color: var(--primary, #0284c7);
    text-decoration: none;
    font-weight: 500;
}
.role-breadcrumb a:hover {
    text-decoration: underline;
}
.role-section-divider {
    display: flex;
    align-items: center;
    text-align: center;
    margin: 32px 0 20px 0;
    color: var(--primary, #0284c7);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.role-section-divider::before,
.role-section-divider::after {
    content: '';
    flex: 1;
    border-bottom: 1px dashed var(--border, #e2e8f0);
}
.role-section-divider:not(:empty)::before {
    margin-right: 18px;
}
.role-section-divider:not(:empty)::after {
    margin-left: 18px;
}

/* 4-column responsive grid */
.role-perm-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 1320px) {
    .role-perm-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 980px) {
    .role-perm-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 640px) {
    .role-perm-grid {
        grid-template-columns: 1fr;
    }
}

/* Permission Card - Clean Project Sky Blue Theme */
.perm-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: var(--radius-md, 10px);
    padding: 16px 18px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    display: flex;
    flex-direction: column;
}
.perm-card:hover {
    border-color: var(--primary, #0284c7);
    box-shadow: 0 4px 14px rgba(2, 132, 199, 0.08);
}
[data-theme="dark"] .perm-card:hover {
    border-color: var(--primary, #38bdf8);
    box-shadow: 0 4px 14px rgba(56, 189, 248, 0.12);
}
.perm-card.has-selected {
    background: rgba(2, 132, 199, 0.035);
    border-color: rgba(2, 132, 199, 0.35);
}
[data-theme="dark"] .perm-card.has-selected {
    background: rgba(56, 189, 248, 0.05);
    border-color: rgba(56, 189, 248, 0.35);
}
.perm-card-header {
    border-bottom: 1px solid var(--border, #e2e8f0);
    padding-bottom: 10px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.perm-card.has-selected .perm-card-header {
    border-bottom-color: rgba(2, 132, 199, 0.25);
}
[data-theme="dark"] .perm-card.has-selected .perm-card-header {
    border-bottom-color: rgba(56, 189, 248, 0.25);
}
.perm-card-header label {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-main, #0f172a);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    user-select: none;
    flex: 1;
    min-width: 0;
}
.perm-card-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.perm-item-label {
    font-size: 13px;
    color: var(--text-main, #1e293b);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 9px;
    user-select: none;
    line-height: 1.4;
    transition: color 0.15s ease;
}
.perm-item-label:hover {
    color: var(--primary, #0284c7);
}

/* Custom Checkbox accent matching project primary */
.role-check-input {
    width: 17px;
    height: 17px;
    border-radius: 4px;
    cursor: pointer;
    accent-color: var(--primary, #0284c7);
}
</style>

<!-- Alert Message Banner -->
<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<?php if (in_array($action, ['add', 'edit', 'duplicate'], true)): ?>
    <!-- ======================================================== -->
    <!-- ADD / EDIT / DUPLICATE ROLE FORM (MATCHING SCREENSHOT)    -->
    <!-- ======================================================== -->
    <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
        <div class="role-breadcrumb">
            <a href="<?= APP_URL ?>/index.php?view=dashboard"><i class="fa-solid fa-house"></i></a>
            <span>/</span>
            <a href="<?= APP_URL ?>/index.php?view=roles"><?= htmlspecialchars(__('nav_roles')) ?></a>
            <span>/</span>
            <span style="font-weight: 600; color: var(--text-main);">
                <?= ($action === 'edit') ? __('edit_role') : (($action === 'duplicate') ? __('duplicate_role') : __('add_role')) ?>
            </span>
        </div>
    </div>

    <form method="POST" action="<?= APP_URL ?>/index.php?view=roles" id="roleForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_role">
        <?php if ($action === 'edit' && !empty($editRole['id'])): ?>
            <input type="hidden" name="role_id" value="<?= (int)$editRole['id'] ?>">
        <?php endif; ?>

        <div class="role-form-container">
            <!-- Role Details Section -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <div>
                    <label class="form-label" style="font-weight: 700;">
                        <?= htmlspecialchars(__('role_name_en')) ?> <span style="color: var(--danger, #ef4444);">*</span>
                    </label>
                    <input type="text" name="name_en" id="role_name_en" class="form-input" required 
                           placeholder="e.g. Supervisor, Shift Manager" 
                           value="<?= htmlspecialchars($editRole['name_en'] ?? '') ?>"
                           onkeyup="autoGenerateSlug(this.value)">
                </div>
                <div>
                    <label class="form-label" style="font-weight: 700;">
                        <?= htmlspecialchars(__('role_name_km')) ?>
                    </label>
                    <input type="text" name="name_km" id="role_name_km" class="form-input" 
                           placeholder="ឧ. ប្រធានវេន, អ្នកគ្រប់គ្រងទូទៅ" 
                           value="<?= htmlspecialchars($editRole['name_km'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label" style="font-weight: 700;">
                        <?= htmlspecialchars(__('role_code')) ?> <span style="color: var(--danger, #ef4444);">*</span>
                    </label>
                    <input type="text" name="code" id="role_code" class="form-input" required 
                           placeholder="e.g. supervisor" 
                           value="<?= htmlspecialchars($editRole['code'] ?? '') ?>"
                           <?= ($action === 'edit' && !empty($editRole['is_system'])) ? 'readonly' : '' ?>>
                    <small style="font-size: 12px; color: var(--text-muted);">Unique identifier slug (lowercase letters, numbers, underscore).</small>
                </div>
            </div>

            <!-- Role Options & Capabilities -->
            <div style="background: var(--bg-body, #f8fafc); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 20px; margin-bottom: 28px;">
                <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.5px;">
                    Job & Crew Capabilities
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 24px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer;">
                        <input type="checkbox" name="is_commission_eligible" value="1" class="role-check-input"
                               <?= (!empty($editRole['is_commission_eligible'])) ? 'checked' : '' ?>>
                        <span style="font-weight: 600;">Eligible for crew commissions</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer;">
                        <input type="checkbox" name="can_assign_washer" value="1" class="role-check-input"
                               <?= (!empty($editRole['can_assign_washer'])) ? 'checked' : '' ?>>
                        <span style="font-weight: 600;">Can be assigned to jobs as Washer</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer;">
                        <input type="checkbox" name="can_assign_mechanic" value="1" class="role-check-input"
                               <?= (!empty($editRole['can_assign_mechanic'])) ? 'checked' : '' ?>>
                        <span style="font-weight: 600;">Can be assigned to jobs as Mechanic</span>
                    </label>
                </div>
            </div>

            <!-- Menu Access Header with Select All (Exact as in Screenshot) -->
            <div style="display: flex; align-items: center; gap: 24px; margin-bottom: 10px;">
                <label style="font-size: 16px; font-weight: 800; color: var(--text-main); margin: 0;">
                    <?= htmlspecialchars(__('menu_access')) ?> <span style="color: var(--danger, #ef4444);">*</span>
                </label>
                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; cursor: pointer; color: var(--primary);">
                    <input type="checkbox" id="globalSelectAll" class="role-check-input">
                    <span><?= htmlspecialchars(__('select_all')) ?></span>
                </label>
            </div>

            <!-- Permission Areas -->
            <?php foreach ($permissionsByArea as $areaKey => $area): ?>
                <?php if (!empty($area['modules'])): ?>
                    <div class="role-section-divider">
                        <?= (Language::current() === 'km') ? htmlspecialchars($area['title_km']) : htmlspecialchars($area['title_en']) ?>
                    </div>

                    <div class="role-perm-grid">
                        <?php foreach ($area['modules'] as $modKey => $module): ?>
                            <div class="perm-card" id="card_<?= htmlspecialchars($modKey) ?>">
                                <div class="perm-card-header">
                                    <label>
                                        <input type="checkbox" class="role-check-input card-parent-check" 
                                               data-module="<?= htmlspecialchars($modKey) ?>"
                                               id="parent_<?= htmlspecialchars($modKey) ?>">
                                        <span><?= (Language::current() === 'km') ? htmlspecialchars($module['name_km']) : htmlspecialchars($module['name_en']) ?></span>
                                    </label>
                                    <span class="badge badge-primary" style="font-size: 11px; font-weight: 700; padding: 2px 7px;" id="count_<?= htmlspecialchars($modKey) ?>"></span>
                                </div>
                                <div class="perm-card-body">
                                    <?php foreach ($module['items'] as $item): ?>
                                        <?php $isChecked = in_array($item['code'], $selectedPermissionCodes, true); ?>
                                        <label class="perm-item-label">
                                            <input type="checkbox" name="permissions[]" 
                                                   value="<?= htmlspecialchars($item['code']) ?>" 
                                                   class="role-check-input perm-child-check" 
                                                   data-module="<?= htmlspecialchars($modKey) ?>"
                                                   <?= $isChecked ? 'checked' : '' ?>>
                                            <span><?= (Language::current() === 'km') ? htmlspecialchars($item['label_km']) : htmlspecialchars($item['label_en']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Form Buttons -->
            <div style="display: flex; justify-content: flex-end; gap: 14px; margin-top: 36px; padding-top: 24px; border-top: 1px solid var(--border);">
                <a href="<?= APP_URL ?>/index.php?view=roles" class="btn btn-secondary">
                    <?= htmlspecialchars(__('cancel')) ?>
                </a>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> <?= htmlspecialchars(__('save')) ?>
                </button>
            </div>
        </div>
    </form>

    <script>
    function autoGenerateSlug(val) {
        <?php if ($action !== 'edit'): ?>
        const codeInput = document.getElementById('role_code');
        if (codeInput && !codeInput.dataset.touched) {
            codeInput.value = val.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        }
        <?php endif; ?>
    }

    document.addEventListener('DOMContentLoaded', function() {
        const codeInput = document.getElementById('role_code');
        if (codeInput) {
            codeInput.addEventListener('input', function() {
                this.dataset.touched = 'true';
            });
        }

        const globalSelectAll = document.getElementById('globalSelectAll');
        const parentChecks = document.querySelectorAll('.card-parent-check');
        const childChecks = document.querySelectorAll('.perm-child-check');

        // Update single card parent state
        function updateParentState(modKey) {
            const parent = document.getElementById('parent_' + modKey);
            if (!parent) return;

            const children = document.querySelectorAll('.perm-child-check[data-module="' + modKey + '"]');
            let checkedCount = 0;
            children.forEach(c => { if (c.checked) checkedCount++; });

            if (checkedCount === children.length && children.length > 0) {
                parent.checked = true;
                parent.indeterminate = false;
            } else if (checkedCount > 0) {
                parent.checked = false;
                parent.indeterminate = true;
            } else {
                parent.checked = false;
                parent.indeterminate = false;
            }

            const card = document.getElementById('card_' + modKey);
            if (card) {
                if (checkedCount > 0) {
                    card.classList.add('has-selected');
                } else {
                    card.classList.remove('has-selected');
                }
            }

            const countEl = document.getElementById('count_' + modKey);
            if (countEl) {
                countEl.textContent = checkedCount + '/' + children.length;
                if (checkedCount === 0) {
                    countEl.style.opacity = '0.5';
                } else {
                    countEl.style.opacity = '1';
                }
            }
        }

        // Update global select-all state
        function updateGlobalState() {
            let total = childChecks.length;
            let checked = 0;
            childChecks.forEach(c => { if (c.checked) checked++; });

            if (checked === total && total > 0) {
                globalSelectAll.checked = true;
                globalSelectAll.indeterminate = false;
            } else if (checked > 0) {
                globalSelectAll.checked = false;
                globalSelectAll.indeterminate = true;
            } else {
                globalSelectAll.checked = false;
                globalSelectAll.indeterminate = false;
            }
        }

        // Initial setup for all cards
        parentChecks.forEach(p => {
            updateParentState(p.dataset.module);
        });
        updateGlobalState();

        // Handle Card Parent Click
        parentChecks.forEach(p => {
            p.addEventListener('change', function() {
                const mod = this.dataset.module;
                const isChecked = this.checked;
                const children = document.querySelectorAll('.perm-child-check[data-module="' + mod + '"]');
                children.forEach(c => { c.checked = isChecked; });
                this.indeterminate = false;
                updateGlobalState();
            });
        });

        // Handle Child Click
        childChecks.forEach(c => {
            c.addEventListener('change', function() {
                updateParentState(this.dataset.module);
                updateGlobalState();
            });
        });

        // Handle Global Select All Click
        if (globalSelectAll) {
            globalSelectAll.addEventListener('change', function() {
                const isChecked = this.checked;
                childChecks.forEach(c => { c.checked = isChecked; });
                parentChecks.forEach(p => {
                    p.checked = isChecked;
                    p.indeterminate = false;
                });
                this.indeterminate = false;
            });
        }

        // Form Validation on Submit
        const form = document.getElementById('roleForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                let anyChecked = false;
                childChecks.forEach(c => { if (c.checked) anyChecked = true; });
                if (!anyChecked) {
                    e.preventDefault();
                    alert('Please select at least one permission for this role.');
                }
            });
        }
    });
    </script>

<?php else: ?>
    <!-- ======================================================== -->
    <!-- ROLES LIST VIEW                                          -->
    <!-- ======================================================== -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
                <i class="fa-solid fa-user-lock mr-1"></i> <?= count($rolesList) ?> Configured Roles
            </span>
            <span style="font-size: 13px; color: var(--text-muted);">
                Granular Permissions &amp; System Access Control
            </span>
        </div>
        <div>
            <?php if (Auth::can('roles.add')): ?>
                <a href="<?= APP_URL ?>/index.php?view=roles&action=add" class="btn btn-primary">
                    <i class="fa-solid fa-plus mr-1"></i> <?= htmlspecialchars(__('add_role')) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Roles Table Card -->
    <div class="card" style="border-radius: var(--radius-lg); overflow: hidden;">
        <div class="table-responsive">
            <table class="table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="min-width: 280px; width: 300px;">Role Name</th>
                        <th style="width: 130px;">Code / Slug</th>
                        <th style="width: 100px;">Type</th>
                        <th style="width: 130px;">Assigned Staff</th>
                        <th style="width: 140px;">Permissions</th>
                        <th>Job Capabilities</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 160px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rolesList)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                No roles found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rolesList as $r): ?>
                            <tr>
                                <td style="min-width: 280px;">
                                    <div style="font-weight: 700; color: var(--text-main); font-size: 14px;">
                                        <?= (Language::current() === 'km') ? htmlspecialchars($r['name_km'] ?: $r['name_en']) : htmlspecialchars($r['name_en']) ?>
                                    </div>
                                    <div style="font-size: 12px; color: var(--text-muted);">
                                        <?= (Language::current() === 'km') ? htmlspecialchars($r['name_en']) : htmlspecialchars($r['name_km']) ?>
                                    </div>
                                </td>
                                <td>
                                    <code style="background: var(--bg-body); padding: 3px 8px; border-radius: 6px; font-size: 12px; border: 1px solid var(--border);">
                                        <?= htmlspecialchars($r['code']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?php if ($r['is_system'] == 1): ?>
                                        <span class="badge badge-primary">
                                            <i class="fa-solid fa-lock mr-1" style="font-size: 10px;"></i> System
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-slate">
                                            Custom
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-slate" style="font-size: 12px;">
                                        <i class="fa-solid fa-users mr-1" style="color: var(--primary);"></i> <?= (int)$r['users_count'] ?> staff
                                    </span>
                                </td>
                                <td>
                                    <?php if ($r['code'] === 'owner'): ?>
                                        <span class="badge badge-success" style="font-weight: 700;">
                                            <i class="fa-solid fa-shield mr-1"></i> All Access (<?= $totalPermissionsCount ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="font-weight: 600;">
                                            <?= (int)$r['permissions_count'] ?> / <?= $totalPermissionsCount ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php if (!empty($r['is_commission_eligible'])): ?>
                                            <span class="badge badge-warning" style="font-size: 11px;">
                                                <i class="fa-solid fa-percent mr-1"></i> Commission
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['can_assign_washer'])): ?>
                                            <span class="badge badge-info" style="font-size: 11px;">
                                                <i class="fa-solid fa-soap mr-1"></i> Washer Job
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['can_assign_mechanic'])): ?>
                                            <span class="badge badge-amber" style="font-size: 11px;">
                                                <i class="fa-solid fa-wrench mr-1"></i> Mechanic Job
                                            </span>
                                        <?php endif; ?>
                                        <?php if (empty($r['is_commission_eligible']) && empty($r['can_assign_washer']) && empty($r['can_assign_mechanic'])): ?>
                                            <span style="font-size: 12px; color: var(--text-muted);">&mdash;</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($r['is_active'])): ?>
                                        <span class="badge badge-success"><?= htmlspecialchars(__('status_active', 'Active')) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><?= htmlspecialchars(__('status_inactive', 'Inactive')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <?php if ($r['code'] !== 'owner' && Auth::can('roles.edit')): ?>
                                            <a href="<?= APP_URL ?>/index.php?view=roles&action=edit&id=<?= (int)$r['id'] ?>" 
                                               class="btn btn-sm btn-secondary" title="Edit Role">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if (Auth::can('roles.add')): ?>
                                            <a href="<?= APP_URL ?>/index.php?view=roles&action=duplicate&id=<?= (int)$r['id'] ?>" 
                                               class="btn btn-sm btn-secondary" title="Duplicate Role">
                                                <i class="fa-solid fa-copy"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($r['code'] !== 'owner' && Auth::can('roles.edit')): ?>
                                            <form method="POST" action="<?= APP_URL ?>/index.php?view=roles" style="display: inline;" 
                                                  onsubmit="return confirm('Change status for role <?= htmlspecialchars($r['name_en']) ?>?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                                                <input type="hidden" name="is_active" value="<?= $r['is_active'] ? '0' : '1' ?>">
                                                <button type="submit" class="btn btn-sm btn-secondary" 
                                                        title="<?= $r['is_active'] ? 'Disable Role' : 'Enable Role' ?>">
                                                    <i class="fa-solid <?= $r['is_active'] ? 'fa-ban text-warning' : 'fa-check text-success' ?>"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($r['code'] !== 'owner' && $r['is_system'] == 0 && Auth::can('roles.delete')): ?>
                                            <form method="POST" action="<?= APP_URL ?>/index.php?view=roles" style="display: inline;" 
                                                  onsubmit="return confirm('Are you sure you want to permanently delete role <?= htmlspecialchars($r['name_en']) ?>?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_role">
                                                <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-secondary" 
                                                        style="color: var(--danger);" title="Delete Role">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
include dirname(__DIR__) . '/layouts/footer.php';
?>
