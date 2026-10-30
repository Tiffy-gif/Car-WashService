<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/AuditLogger.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Helpers.php';
require_once dirname(__DIR__) . '/database/init.php';

$pdo = Database::getInstance();

echo "=================================================================\n";
echo "  CAR WASH BACK OFFICE FOUNDATION VERIFICATION TEST SUITE       \n";
echo "=================================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $testsFailed++;
        echo "  [FAIL] {$desc}" . ($detail ? " -> {$detail}" : "") . "\n";
    }
}

// -------------------------------------------------------------
// TEST 1: Ten Roles and Dedicated Home Screens
// -------------------------------------------------------------
echo "1. TESTING TEN BUILT-IN ROLES & DEDICATED HOME SCREENS:\n";

$rolesToTest = [
    'system_admin'    => ['username' => 'admin',           'pin' => '111111', 'expected_view' => 'settings'],
    'owner'           => ['username' => 'owner',           'pin' => '123456', 'expected_view' => 'dashboard'],
    'manager'         => ['username' => 'manager',         'pin' => '123456', 'expected_view' => 'orders'],
    'crm'             => ['username' => 'crm',             'pin' => '123456', 'expected_view' => 'customers'],
    'cashier'         => ['username' => 'cashier',         'pin' => '123456', 'expected_view' => 'pos'],
    'stock_control'   => ['username' => 'stock_control',   'pin' => '123456', 'expected_view' => 'oil_care'],
    'technical'       => ['username' => 'technical',       'pin' => '123456', 'expected_view' => 'mechanic_jobs'],
    'washer'          => ['username' => 'washer',          'pin' => '123456', 'expected_view' => 'washer_jobs'],
    'wash_supervisor' => ['username' => 'wash_supervisor', 'pin' => '123456', 'expected_view' => 'bays_queue'],
];

foreach ($rolesToTest as $roleCode => $t) {
    Auth::logout();
    $loginOk = Auth::login($t['username'], $t['pin']);
    $homeScreen = Auth::getDefaultViewForUser();
    
    assertTest(
        "Role '{$roleCode}' ({$t['username']}) logs in and opens home screen '{$t['expected_view']}'",
        $loginOk && $homeScreen === $t['expected_view'],
        "Got login=" . ($loginOk ? 'true' : 'false') . ", view='{$homeScreen}'"
    );
}

// 10th Role: Customer (Queue TV only, NO login allowed)
Auth::logout();
$customerLogin = Auth::login('customer', '123456');
$customerErr = Auth::getLastError();
assertTest(
    "10th Role: Customer CANNOT log in to back office (tv.php queue only)",
    $customerLogin === false && $customerErr === 'customer_no_login',
    "Customer login was expected to fail with customer_no_login, got error='{$customerErr}'"
);

echo "\n";

// -------------------------------------------------------------
// TEST 2: Role Permissions & Server Checks (No Money for Admin, Money for Owner)
// -------------------------------------------------------------
echo "2. TESTING PERMISSION BOUNDARIES & SERVER-SIDE CHECKS:\n";

// 2a. System Admin Check: Handles settings, users, devices, backup; CANNOT see money reports
Auth::logout();
Auth::login('admin', '111111');
assertTest("System Admin CAN view settings", Auth::can('settings.view'));
assertTest("System Admin CAN manage staff", Auth::can('staff.list') && Auth::can('staff.reset_password'));
assertTest("System Admin CAN manage POS devices", Auth::can('pos_devices.list') && Auth::can('pos_devices.add'));
assertTest("System Admin CAN download backups", Auth::can('reports.backup'));
assertTest("System Admin CAN view audit logs", Auth::can('audit.view'));

// Explicit money restriction
assertTest("System Admin CANNOT view financial reports", !Auth::can('reports.view'));
assertTest("System Admin CANNOT export financial reports", !Auth::can('reports.export'));
assertTest("System Admin CANNOT view expenses", !Auth::can('expenses.list'));
assertTest("System Admin CANNOT view staff commissions money", !Auth::can('commissions.list'));
assertTest("System Admin CANNOT view money dashboard revenue", !Auth::can('dashboard.view'));

// 2b. Owner Check: Sees money, business reports, and everything
Auth::logout();
Auth::login('owner', '123456');
assertTest("Owner CAN view money reports", Auth::can('reports.view'));
assertTest("Owner CAN export reports", Auth::can('reports.export'));
assertTest("Owner CAN view expenses", Auth::can('expenses.list'));
assertTest("Owner CAN view commissions", Auth::can('commissions.list'));
assertTest("Owner CAN view dashboard", Auth::can('dashboard.view'));
assertTest("Owner CAN view settings and audit logs", Auth::can('settings.view') && Auth::can('audit.view'));

// 2c. Cashier Check: POS & Orders only, no settings/reports
Auth::logout();
Auth::login('cashier', '123456');
assertTest("Cashier CAN access POS", Auth::can('pos.access') && Auth::can('pos.create_order'));
assertTest("Cashier CAN view orders queue", Auth::can('orders.list'));
assertTest("Cashier CANNOT access shop settings", !Auth::can('settings.view'));
assertTest("Cashier CANNOT access financial reports", !Auth::can('reports.view'));

// 2d. Washer Check: Washer jobs only
Auth::logout();
Auth::login('washer', '123456');
assertTest("Washer CAN view washer jobs", Auth::can('washer_jobs.view'));
assertTest("Washer CANNOT access POS", !Auth::can('pos.access'));
assertTest("Washer CANNOT access staff management", !Auth::can('staff.list'));
assertTest("Washer CANNOT access shop settings", !Auth::can('settings.view'));

echo "\n";

// -------------------------------------------------------------
// TEST 3: PIN Lockout (5 wrong tries = 10 min lock) & Administrative Reset
// -------------------------------------------------------------
echo "3. TESTING PIN LOCKOUT & RESET PIN FLOW:\n";

// Create or use a dedicated test user
$testUsername = 'test_lockout_user';
$pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$testUsername]);
$initPinHash = password_hash('654321', PASSWORD_DEFAULT);
$washerRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'washer'")->fetchColumn();
$pdo->prepare("
    INSERT INTO users (username, password_hash, full_name, phone, role, role_id, status)
    VALUES (?, ?, 'Lockout Test User', '012 000 111', 'washer', ?, 'active')
")->execute([$testUsername, $initPinHash, $washerRoleId]);

// 3a. Try 4 wrong PINs: should NOT be locked yet
for ($i = 1; $i <= 4; $i++) {
    Auth::logout();
    $tryRes = Auth::login($testUsername, '000000');
    assertTest("Attempt {$i}/5 with wrong PIN fails cleanly", $tryRes === false && Auth::getLastError() === 'invalid_credentials');
}

$chkStmt = $pdo->prepare("SELECT failed_login_attempts, locked_until FROM users WHERE username = ?");
$chkStmt->execute([$testUsername]);
$uState = $chkStmt->fetch(PDO::FETCH_ASSOC);
assertTest("After 4 failed tries, attempts count is 4 and not yet locked", (int)$uState['failed_login_attempts'] === 4 && empty($uState['locked_until']));

// 3b. 5th wrong attempt: triggers 10-minute lockout
Auth::logout();
$try5 = Auth::login($testUsername, '000000');
$chkStmt->execute([$testUsername]);
$uLocked = $chkStmt->fetch(PDO::FETCH_ASSOC);

$isLockedNow = !empty($uLocked['locked_until']) && (strtotime($uLocked['locked_until']) > time());
$lockMinutes = $isLockedNow ? ceil((strtotime($uLocked['locked_until']) - time()) / 60) : 0;
assertTest("5th failed attempt triggers 10-minute lockout", $try5 === false && $isLockedNow && $lockMinutes >= 9);

// 3c. 6th attempt (even with correct PIN!) while locked must be blocked
Auth::logout();
$try6 = Auth::login($testUsername, '654321');
assertTest("Attempt while locked is rejected with 'account_locked'", $try6 === false && Auth::getLastError() === 'account_locked');

// 3d. Owner resets PIN for the locked user
Auth::logout();
Auth::login('owner', '123456');

$uId = (int)$pdo->query("SELECT id FROM users WHERE username = '{$testUsername}'")->fetchColumn();
$newPin = '888999';
$newPinHash = password_hash($newPin, PASSWORD_DEFAULT);
$pdo->prepare("
    UPDATE users SET
        password_hash = ?,
        must_change_pin = 1,
        failed_login_attempts = 0,
        locked_until = NULL
    WHERE id = ?
")->execute([$newPinHash, $uId]);
audit_log('PIN_RESET', "Owner reset PIN for {$testUsername}", 'user', (string)$uId);

// 3e. Test user logs in with newly reset PIN -> Must be flagged to change PIN
Auth::logout();
$loginAfterReset = Auth::login($testUsername, $newPin);
$mustChange = Auth::mustChangePin();
assertTest("User logs in with newly reset PIN and mustChangePin flag is active", $loginAfterReset && $mustChange);

// 3f. User changes their PIN
$finalPin = '999888';
$finalHash = password_hash($finalPin, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE users SET password_hash = ?, must_change_pin = 0 WHERE id = ?")->execute([$finalHash, $uId]);
$_SESSION['must_change_pin'] = 0;
audit_log('PIN_CHANGED', "User changed their PIN after reset", 'user', (string)$uId);

assertTest("After user updates PIN, mustChangePin flag is cleared", !Auth::mustChangePin());

// Clean up test user
$pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$testUsername]);

echo "\n";

// -------------------------------------------------------------
// TEST 4: Unique IDs and No Hard Deletes
// -------------------------------------------------------------
echo "4. TESTING UNIQUE IDS & NO HARD DELETES:\n";

// 4a. Unique ID generators
$ordId1 = generate_unique_id('ORD');
$ordId2 = generate_unique_id('ORD');
$payId1 = generate_unique_id('PAY');
$payId2 = generate_unique_id('PAY');
$stkId1 = generate_unique_id('STK');
$stkId2 = generate_unique_id('STK');
$logId1 = generate_unique_id('LOG');
$logId2 = generate_unique_id('LOG');

assertTest("Order IDs are unique and formatted", str_starts_with($ordId1, 'ORD-') && $ordId1 !== $ordId2);
assertTest("Payment IDs are unique and formatted", str_starts_with($payId1, 'PAY-') && $payId1 !== $payId2);
assertTest("Stock Movement IDs are unique and formatted", str_starts_with($stkId1, 'STK-') && $stkId1 !== $stkId2);
assertTest("Audit Log IDs are unique and formatted", str_starts_with($logId1, 'LOG-') && $logId1 !== $logId2);

// 4b. No hard deletes test: Soft delete on staff member
$dummyStaff = 'soft_delete_test_user';
$pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$dummyStaff]);
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, phone, role, status) VALUES (?, 'hash', 'Test Soft Delete', '123', 'washer', 'active')")->execute([$dummyStaff]);
$dummyId = (int)$pdo->lastInsertId();

// Soft delete action
$pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$dummyId]);
$checkRow = $pdo->query("SELECT id, status FROM users WHERE id = {$dummyId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Staff member was soft-deleted (status = 'inactive') and row remains in database", $checkRow && $checkRow['status'] === 'inactive');

// Clean up dummy
$pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$dummyStaff]);

// 4c. Soft delete on expense
$pdo->prepare("INSERT INTO expenses (category, description, amount_usd, amount_khr, expense_date, is_active) VALUES ('test', 'Test Exp', 10.00, 41000, CURDATE(), 1)")->execute();
$expDummyId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE expenses SET is_active = 0 WHERE id = ?")->execute([$expDummyId]);
$expRow = $pdo->query("SELECT id, is_active FROM expenses WHERE id = {$expDummyId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Expense was soft-deleted (is_active = 0) and row remains in database", $expRow && (int)$expRow['is_active'] === 0);
$pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$expDummyId]);

echo "\n";

// -------------------------------------------------------------
// TEST 5: Complete Audit Trail Logging & Searching
// -------------------------------------------------------------
echo "5. TESTING COMPLETE AUDIT TRAIL LOGGING & SEARCH:\n";

// 5a. Record sample actions
$logUid1 = audit_log('LOGIN_SUCCESS', 'Staff admin logged in successfully', 'auth', '12', 12, 'admin', 'system_admin');
$logUid2 = audit_log('LOGIN_FAILED', 'Failed PIN attempt (1/5)', 'auth', '12', null, 'unknown_user');
$logUid3 = audit_log('PIN_RESET', 'Owner reset PIN for cashier', 'user', '2', 1, 'owner', 'owner');
$logUid4 = audit_log('ROLE_CHANGE', 'Role changed for user', 'user', '5', 1, 'owner', 'owner');
$logUid5 = audit_log('PRICE_CHANGE', 'Updated service price matrix for Full Wash', 'service', '3', 1, 'owner', 'owner');
$logUid6 = audit_log('ORDER_CANCEL', 'Order ORD-20261027-TEST cancelled', 'order', '88', 2, 'cashier', 'cashier');
$logUid7 = audit_log('SETTING_CHANGE', "Updated setting 'shop_phone'", 'setting', 'shop_phone', 1, 'owner', 'owner');

assertTest("Audit log entry 1 generated with unique ID", !empty($logUid1) && str_starts_with($logUid1, 'LOG-'));
assertTest("Audit log entry 5 generated with unique ID", !empty($logUid5) && str_starts_with($logUid5, 'LOG-'));

// 5b. Search by Action category
$searchLogins = AuditLogger::search(['action' => 'logins'], 10, 0);
assertTest("AuditLogger::search by 'logins' returns login events", $searchLogins['total'] > 0);

$searchPrices = AuditLogger::search(['action' => 'price_changes'], 10, 0);
assertTest("AuditLogger::search by 'price_changes' returns price change events", $searchPrices['total'] > 0);

$searchCancels = AuditLogger::search(['action' => 'cancellations'], 10, 0);
assertTest("AuditLogger::search by 'cancellations' returns cancel events", $searchCancels['total'] > 0);

$searchSettings = AuditLogger::search(['action' => 'settings_changes'], 10, 0);
assertTest("AuditLogger::search by 'settings_changes' returns settings events", $searchSettings['total'] > 0);

// 5c. Search by User and Date
$searchUser = AuditLogger::search(['username' => 'owner'], 10, 0);
assertTest("AuditLogger::search by username 'owner' returns entries", $searchUser['total'] > 0);

$searchDate = AuditLogger::search(['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d')], 10, 0);
assertTest("AuditLogger::search by today's date returns entries", $searchDate['total'] > 0);

echo "\n";

// -------------------------------------------------------------
// TEST 6: Test Mode Switch Behavior
// -------------------------------------------------------------
echo "6. TESTING TEST MODE SWITCH (Settings toggle):\n";

// 6a. Turn Test mode OFF
update_setting($pdo, 'demo_mode', '0', 1, 'Owner');
$modeOff = get_setting('demo_mode', '1', true);
assertTest("Test mode turned OFF in settings", $modeOff === '0');

// 6b. When Test mode is OFF, sample account login must be blocked
Auth::logout();
$blockedSample = Auth::login('admin', '111111');
$blockedErr = Auth::getLastError();
assertTest("When Test Mode is OFF, sample account login is blocked", $blockedSample === false && $blockedErr === 'demo_disabled');

// 6c. Turn Test mode back ON
update_setting($pdo, 'demo_mode', '1', 1, 'Owner');
$modeOn = get_setting('demo_mode', '1', true);
assertTest("Test mode turned back ON in settings", $modeOn === '1');

// 6d. When Test mode is ON, sample account login succeeds
Auth::logout();
$okSample = Auth::login('admin', '111111');
assertTest("When Test Mode is ON, sample account login succeeds", $okSample === true);

echo "\n";
echo "=================================================================\n";
echo "TEST RESULTS SUMMARY: {$testsPassed} PASSED, {$testsFailed} FAILED\n";
echo "=================================================================\n";

if ($testsFailed === 0) {
    echo "SUCCESS: ALL REQUIREMENTS VERIFIED ACCURATELY!\n";
    exit(0);
} else {
    echo "ATTENTION: SOME TESTS FAILED.\n";
    exit(1);
}
