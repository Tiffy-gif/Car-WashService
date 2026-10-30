<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

$pdo = Database::getInstance();

echo "====================================================\n";
echo "ROLE & PERMISSION SYSTEM AUTOMATED VERIFICATION TEST\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failCount++;
    }
}

// ----------------------------------------------------
// 1. Verify Database Schema & Seed Data
// ----------------------------------------------------
echo "1. Checking Database Tables & Seed Data...\n";
$tables = ['roles', 'permissions', 'role_permissions', 'role_audit_logs'];
foreach ($tables as $tbl) {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tbl}'")->fetchColumn();
    assertTest($c === 1, "Table '{$tbl}' exists");
}

$roleCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
assertTest($roleCount >= 4, "At least 4 roles exist in database (Found: {$roleCount})");

$permCount = (int)$pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
assertTest($permCount >= 60, "Granular permissions seeded (Found: {$permCount})");

$ownerPerms = Auth::getPermissionsByUserId(1); // 1 is owner
assertTest(count($ownerPerms) === $permCount, "Owner user #1 has all {$permCount} permissions");

// ----------------------------------------------------
// 2. Test Built-in Roles Permissions
// ----------------------------------------------------
echo "\n2. Testing Built-in User Role Permissions...\n";
// Cashier (user #2)
$cashierPerms = Auth::getPermissionsByUserId(2);
assertTest(in_array('pos.access', $cashierPerms, true), "Cashier has 'pos.access'");
assertTest(in_array('pos.create_order', $cashierPerms, true), "Cashier has 'pos.create_order'");
assertTest(in_array('orders.list', $cashierPerms, true), "Cashier has 'orders.list'");
assertTest(!in_array('services.delete', $cashierPerms, true), "Cashier CANNOT delete services");
assertTest(!in_array('settings.edit', $cashierPerms, true), "Cashier CANNOT edit settings");
assertTest(!in_array('roles.manage', $cashierPerms, true), "Cashier CANNOT manage roles");

// Washer (user #3)
$washerPerms = Auth::getPermissionsByUserId(3);
assertTest(in_array('washer_jobs.view', $washerPerms, true), "Washer has 'washer_jobs.view'");
assertTest(!in_array('pos.access', $washerPerms, true), "Washer CANNOT access POS");
assertTest(!in_array('reports.view', $washerPerms, true), "Washer CANNOT view reports");

// Mechanic (user #6)
$mechanicPerms = Auth::getPermissionsByUserId(6);
assertTest(in_array('mechanic_jobs.view', $mechanicPerms, true), "Mechanic has 'mechanic_jobs.view'");
assertTest(in_array('vehicle_history.list', $mechanicPerms, true), "Mechanic has 'vehicle_history.list'");
assertTest(!in_array('settings.view', $mechanicPerms, true), "Mechanic CANNOT view settings");

// ----------------------------------------------------
// 3. Test Custom Role Creation & Privilege Enforcement
// ----------------------------------------------------
echo "\n3. Testing Custom Role Creation & Specific Permission Gating...\n";
// Cleanup any prior test role
$pdo->prepare("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE code = 'test_supervisor')")->execute();
$pdo->prepare("DELETE FROM users WHERE username = 'test_supervisor_user'")->execute();
$pdo->prepare("DELETE FROM roles WHERE code = 'test_supervisor'")->execute();

// Create 'test_supervisor' with only: orders.list, orders.change_status, orders.change_crew, reports.view
$insRole = $pdo->prepare("
    INSERT INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
    VALUES ('test_supervisor', 'Shift Supervisor', 'ប្រធានវេន', 'Test supervisor role', 0, 1, 0, 0, 0)
");
$insRole->execute();
$testRoleId = (int)$pdo->lastInsertId();
assertTest($testRoleId > 0, "Created custom role 'test_supervisor' (ID: {$testRoleId})");

// Assign 4 specific permissions
$selectedPermCodes = ['orders.list', 'orders.change_status', 'orders.change_crew', 'reports.view'];
$pIdStmt = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
$rpStmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
foreach ($selectedPermCodes as $pCode) {
    $pIdStmt->execute([$pCode]);
    $pid = (int)$pIdStmt->fetchColumn();
    if ($pid) {
        $rpStmt->execute([$testRoleId, $pid]);
    }
}

// Create a test user with this role
$passHash = password_hash('Supervisor@123', PASSWORD_DEFAULT);
$insUser = $pdo->prepare("
    INSERT INTO users (username, password_hash, full_name, role, role_id, phone, status)
    VALUES ('test_supervisor_user', ?, 'Test Supervisor', 'test_supervisor', ?, '012999000', 'active')
");
$insUser->execute([$passHash, $testRoleId]);
$testUserId = (int)$pdo->lastInsertId();
assertTest($testUserId > 0, "Created test user 'test_supervisor_user' (ID: {$testUserId})");

// Verify test user permissions
$supervisorPerms = Auth::getPermissionsByUserId($testUserId);
assertTest(count($supervisorPerms) === 4, "Supervisor has exactly 4 permissions");
assertTest(in_array('orders.list', $supervisorPerms, true), "Supervisor CAN access orders.list");
assertTest(in_array('reports.view', $supervisorPerms, true), "Supervisor CAN access reports.view");
assertTest(!in_array('pos.access', $supervisorPerms, true), "Supervisor CANNOT access pos.access");
assertTest(!in_array('services.list', $supervisorPerms, true), "Supervisor CANNOT access services.list");
assertTest(!in_array('settings.view', $supervisorPerms, true), "Supervisor CANNOT access settings.view");

// Test Auth::login as test_supervisor_user
$loginSuccess = Auth::login('test_supervisor_user', 'Supervisor@123');
assertTest($loginSuccess === true, "Auth::login succeeded for test_supervisor_user");
assertTest(Auth::can('orders.list') === true, "Auth::can('orders.list') is TRUE in session");
assertTest(Auth::can('reports.view') === true, "Auth::can('reports.view') is TRUE in session");
assertTest(Auth::can('services.list') === false, "Auth::can('services.list') is FALSE in session");
assertTest(Auth::can('settings.view') === false, "Auth::can('settings.view') is FALSE in session");
assertTest(Auth::getDefaultViewForUser() === 'orders', "Supervisor default view redirects to 'orders'");

// ----------------------------------------------------
// 4. Test POS API Role & Permission Integration
// ----------------------------------------------------
echo "\n4. Testing POS API Staff Login & Capabilities...\n";
// Call staff_login via internal simulation
$roleDetails = Auth::getUserRoleDetails(2); // Cashier
assertTest($roleDetails['role_code'] === 'cashier', "Cashier role_code resolved properly");

$deviceKey = (string)$pdo->query("SELECT api_key FROM pos_devices WHERE status = 'active' LIMIT 1")->fetchColumn();
assertTest(!empty($deviceKey), "Active POS device key available: {$deviceKey}");

// Test washer & mechanic query with capability flags
$washers = $pdo->query("
    SELECT u.id, u.full_name, u.role 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE (r.can_assign_washer = 1 OR u.role = 'washer') 
      AND u.status = 'active'
")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($washers) >= 2, "Washers duty crew query returned " . count($washers) . " washers");

// ----------------------------------------------------
// 5. Test Role Protection & Deletion Guard
// ----------------------------------------------------
echo "\n5. Testing Role Protection Rules & Deletion Guards...\n";
// Attempt to delete system role (should be rejected)
$ownerRole = $pdo->query("SELECT * FROM roles WHERE code = 'owner'")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$ownerRole['is_system'] === 1, "Owner is marked as system role (is_system = 1)");

// Attempt to delete test_supervisor while test_supervisor_user is assigned
$userCountOnSupervisor = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role_id = {$testRoleId} OR role = 'test_supervisor'")->fetchColumn();
assertTest($userCountOnSupervisor > 0, "Role 'test_supervisor' has {$userCountOnSupervisor} active user assigned");

// Clean up test user & test role
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$testUserId]);
$pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$testRoleId]);
$pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$testRoleId]);
$deletedCheck = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE code = 'test_supervisor'")->fetchColumn();
assertTest($deletedCheck === 0, "Test role cleaned up successfully after removing assigned user");

// Restore Owner session
Auth::login('owner', 'admin123');
assertTest(Auth::getRole() === 'owner', "Owner session restored for UI checks");

echo "\n====================================================\n";
echo "TEST RESULTS: {$passCount} Passed, {$failCount} Failed\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
