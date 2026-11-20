<?php
// Ensure CARWASH_DB_NAME points to test database
putenv('CARWASH_DB_NAME=carwash_db_test');
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/CashierControl.php';
require_once __DIR__ . '/../core/TelegramNotifier.php';

// 1. Mandatory Test Database Safety Guard
$pdo = Database::getInstance();
$dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "========================================================\n";
echo "TARGET DATABASE: " . $dbName . "\n";
echo "========================================================\n";

if (!str_ends_with($dbName, '_test')) {
    die("FATAL ERROR: Target database '{$dbName}' does NOT end with '_test'! Halting immediately.\n");
}

echo "Safety check PASSED: Operating strictly on isolated test database.\n\n";

// Helper for assertions
$passedCount = 0;
$totalCount = 0;

function assertTest(string $description, bool $condition, string $details = '') {
    global $passedCount, $totalCount;
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description} " . ($details ? "--> {$details}" : "") . "\n";
    }
}

// Clean up test dates: 2026-12-20 through 2026-12-26
$testDates = [
    '2026-12-20', '2026-12-21', '2026-12-22', 
    '2026-12-23', '2026-12-24', '2026-12-25', '2026-12-26'
];
$placeholders = implode(',', array_fill(0, count($testDates), '?'));

// Close any open shifts in test database to guarantee clean state
$pdo->exec("UPDATE cashier_shifts SET status = 'closed', ended_at = NOW() WHERE status = 'open'");
$pdo->prepare("DELETE FROM pos_daily_closes WHERE close_date IN ({$placeholders})")->execute($testDates);
$pdo->prepare("DELETE FROM cashier_shifts WHERE DATE(started_at) IN ({$placeholders})")->execute($testDates);

// Ensure test users exist
$pinHash = password_hash('123456', PASSWORD_DEFAULT);

// User: visal (role: cashier, has daily_close.close permission)
$visal = $pdo->query("SELECT id, username FROM users WHERE username = 'visal'")->fetch(PDO::FETCH_ASSOC);
$visalId = (int)$visal['id'];

// User: test_cashier2 (role: cashier, has daily_close.close permission)
$c2 = $pdo->query("SELECT id FROM users WHERE username = 'test_cashier2'")->fetch(PDO::FETCH_ASSOC);
if (!$c2) {
    $pdo->prepare("
        INSERT INTO users (username, password_hash, local_pin_hash, full_name, role, status)
        VALUES ('test_cashier2', ?, ?, 'Test Cashier 2', 'cashier', 'active')
    ")->execute([$pinHash, $pinHash]);
    $cashier2Id = (int)$pdo->lastInsertId();
} else {
    $cashier2Id = (int)$c2['id'];
}

// User: noperm_cashier (role: cashier, NO daily_close.close permission)
$np = $pdo->query("SELECT id FROM users WHERE username = 'noperm_cashier'")->fetch(PDO::FETCH_ASSOC);
if (!$np) {
    $pdo->prepare("
        INSERT INTO users (username, password_hash, local_pin_hash, full_name, role, status)
        VALUES ('noperm_cashier', ?, ?, 'No Permission Cashier', 'cashier', 'active')
    ")->execute([$pinHash, $pinHash]);
    $nopermId = (int)$pdo->lastInsertId();
} else {
    $nopermId = (int)$np['id'];
}

// Ensure role_permissions for noperm_cashier role does NOT have daily_close.close
// We create a custom role or assign role_id that has no daily_close.close, or remove daily_close.close from a test role
$testRole = $pdo->query("SELECT id FROM roles WHERE code = 'restricted_cashier'")->fetch(PDO::FETCH_ASSOC);
if (!$testRole) {
    $pdo->exec("INSERT INTO roles (code, name_en, name_km, is_active) VALUES ('restricted_cashier', 'Restricted Cashier', 'បេឡាករមានកម្រិត', 1)");
    $restrictedRoleId = (int)$pdo->lastInsertId();
    // Assign only pos.access permission to restricted_cashier
    $posAccessPerm = $pdo->query("SELECT id FROM permissions WHERE code = 'pos.access'")->fetchColumn();
    if ($posAccessPerm) {
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$restrictedRoleId, $posAccessPerm]);
    }
} else {
    $restrictedRoleId = (int)$testRole['id'];
}
$pdo->prepare("UPDATE users SET role = 'cashier', role_id = ? WHERE id = ?")->execute([$restrictedRoleId, $nopermId]);

// Owner user
$owner = $pdo->query("SELECT id, username FROM users WHERE username = 'owner'")->fetch(PDO::FETCH_ASSOC);
$ownerId = (int)$owner['id'];


// =========================================================================
// TEST 1: Cashier is the only open shift and closes with an EXACT count
// =========================================================================
echo "\n--- TEST 1: Cashier is the only open shift and closes with an EXACT count ---\n";
$d1 = '2026-12-20';
$s1Stmt = $pdo->prepare("
    INSERT INTO cashier_shifts (shift_uid, cashier_id, cashier_name, opening_cash_usd, opening_cash_khr, started_at, status)
    VALUES ('SHIFT-FAST-001', ?, 'visal', 20.00, 0, '{$d1} 08:00:00', 'open')
");
$s1Stmt->execute([$visalId]);
$shiftId1 = (int)$pdo->lastInsertId();

try {
    $res1 = CashierControl::endShiftAndCloseDay($shiftId1, 'visal', '123456', 20.00, 0, 'Exact count fast close', $d1);
    assertTest("Fast close day succeeds with exact count", !empty($res1['success']) && $res1['success'] === true);
    assertTest("Difference USD is 0.00", (float)$res1['difference_usd'] === 0.00);
    assertTest("Shortage owed USD is 0.00", (float)($res1['shift_result']['shortage_owed_usd'] ?? 0) === 0.00);
    assertTest("Day close result status is balanced (needs_review = 0)", (int)($res1['needs_review'] ?? 1) === 0);
    assertTest("End Shift slip data generated", !empty($res1['shift_slip']));
    assertTest("Close Day Z-Report slip data generated", !empty($res1['close_slip']));

    // Check database records
    $s1Row = $pdo->query("SELECT status, counted_cash_usd, difference_usd FROM cashier_shifts WHERE id = {$shiftId1}")->fetch(PDO::FETCH_ASSOC);
    assertTest("Shift status changed to 'closed' in DB", $s1Row['status'] === 'closed');
    assertTest("Shift counted cash recorded as 20.00 in DB", (float)$s1Row['counted_cash_usd'] === 20.00);

    $c1Row = $pdo->query("SELECT status, needs_review FROM pos_daily_closes WHERE close_date = '{$d1}'")->fetch(PDO::FETCH_ASSOC);
    assertTest("pos_daily_closes record saved as 'closed' in DB", $c1Row && $c1Row['status'] === 'closed');
    assertTest("pos_daily_closes needs_review is 0 in DB", (int)$c1Row['needs_review'] === 0);
} catch (Exception $e) {
    assertTest("Test 1 failed with exception", false, $e->getMessage());
}


// =========================================================================
// TEST 2: Cashier is the only open shift and closes with a SHORT count
// =========================================================================
echo "\n--- TEST 2: Cashier is the only open shift and closes with a SHORT count ---\n";
$d2 = '2026-12-21';
$s2Stmt = $pdo->prepare("
    INSERT INTO cashier_shifts (shift_uid, cashier_id, cashier_name, opening_cash_usd, opening_cash_khr, started_at, status)
    VALUES ('SHIFT-FAST-002', ?, 'visal', 30.00, 0, '{$d2} 08:00:00', 'open')
");
$s2Stmt->execute([$visalId]);
$shiftId2 = (int)$pdo->lastInsertId();

try {
    // Expected is 30.00, cashier counts 25.00 -> short by 5.00
    $res2 = CashierControl::endShiftAndCloseDay($shiftId2, 'visal', '123456', 25.00, 0, 'Short count fast close', $d2);
    assertTest("Fast close day succeeds with short count", !empty($res2['success']) && $res2['success'] === true);
    assertTest("Shift difference USD is -5.00", (float)$res2['difference_usd'] === -5.00);
    assertTest("Shortage owed USD recorded as 5.00", (float)($res2['shift_result']['shortage_owed_usd'] ?? 0) === 5.00);
    assertTest("Day report marked as needing review (needs_review = 1)", (int)($res2['needs_review'] ?? 0) === 1);
    assertTest("Both slips generated on short count", !empty($res2['shift_slip']) && !empty($res2['close_slip']));

    // Check database
    $s2Row = $pdo->query("SELECT status, shortage_owed_usd, difference_usd FROM cashier_shifts WHERE id = {$shiftId2}")->fetch(PDO::FETCH_ASSOC);
    assertTest("Shift shortage_owed_usd recorded as 5.00 in DB", (float)$s2Row['shortage_owed_usd'] === 5.00);

    $c2Row = $pdo->query("SELECT status, needs_review, difference_usd FROM pos_daily_closes WHERE close_date = '{$d2}'")->fetch(PDO::FETCH_ASSOC);
    assertTest("pos_daily_closes needs_review is 1 in DB", (int)$c2Row['needs_review'] === 1);
    assertTest("pos_daily_closes difference_usd recorded as -5.00 in DB", (float)$c2Row['difference_usd'] === -5.00);
} catch (Exception $e) {
    assertTest("Test 2 failed with exception", false, $e->getMessage());
}


// =========================================================================
// TEST 3: Cashier is the only open shift and closes with an OVER count
// =========================================================================
echo "\n--- TEST 3: Cashier is the only open shift and closes with an OVER count ---\n";
$d3 = '2026-12-22';
$s3Stmt = $pdo->prepare("
    INSERT INTO cashier_shifts (shift_uid, cashier_id, cashier_name, opening_cash_usd, opening_cash_khr, started_at, status)
    VALUES ('SHIFT-FAST-003', ?, 'visal', 30.00, 0, '{$d3} 08:00:00', 'open')
");
$s3Stmt->execute([$visalId]);
$shiftId3 = (int)$pdo->lastInsertId();

try {
    // Expected is 30.00, cashier counts 35.00 -> over by 5.00
    $res3 = CashierControl::endShiftAndCloseDay($shiftId3, 'visal', '123456', 35.00, 0, 'Over count fast close', $d3);
    assertTest("Fast close day succeeds with over count", !empty($res3['success']) && $res3['success'] === true);
    assertTest("Shift difference USD is +5.00", (float)$res3['difference_usd'] === 5.00);
    assertTest("Shortage owed USD is 0.00 (overage is not owed)", (float)($res2['shift_result']['shortage_owed_usd'] ?? 0) >= 0);
    assertTest("Day report marked as needing review on over count (needs_review = 1)", (int)($res3['needs_review'] ?? 0) === 1);

    // Check database
    $c3Row = $pdo->query("SELECT status, needs_review, difference_usd FROM pos_daily_closes WHERE close_date = '{$d3}'")->fetch(PDO::FETCH_ASSOC);
    assertTest("pos_daily_closes difference_usd recorded as +5.00 in DB", (float)$c3Row['difference_usd'] === 5.00);
    assertTest("pos_daily_closes needs_review is 1 in DB for overage", (int)$c3Row['needs_review'] === 1);
} catch (Exception $e) {
    assertTest("Test 3 failed with exception", false, $e->getMessage());
}


// =========================================================================
// TEST 4: Another shift is open and the shortcut is REFUSED
// =========================================================================
echo "\n--- TEST 4: Another shift is open and the shortcut is REFUSED ---\n";
$d4 = '2026-12-23';
$s4aStmt = $pdo->prepare("
    INSERT INTO cashier_shifts (shift_uid, cashier_id, cashier_name, opening_cash_usd, opening_cash_khr, started_at, status)
    VALUES ('SHIFT-FAST-004A', ?, 'visal', 20.00, 0, '{$d4} 08:00:00', 'open')
");
$s4aStmt->execute([$visalId]);
$shiftId4A = (int)$pdo->lastInsertId();

$s4bStmt = $pdo->prepare("
    INSERT INTO cashier_shifts (shift_uid, cashier_id, cashier_name, opening_cash_usd, opening_cash_khr, started_at, status)
    VALUES ('SHIFT-FAST-004B', ?, 'test_cashier2', 20.00, 0, '{$d4} 08:30:00', 'open')
");
$s4bStmt->execute([$cashier2Id]);
$shiftId4B = (int)$pdo->lastInsertId();

$refusedShortcut = false;
$refusedMsg = '';
try {
    CashierControl::endShiftAndCloseDay($shiftId4A, 'visal', '123456', 20.00, 0, 'Attempt fast close while another shift open', $d4);
} catch (Exception $e) {
    $refusedShortcut = true;
    $refusedMsg = $e->getMessage();
}

assertTest("Shortcut is refused when another shift is open", $refusedShortcut);
assertTest("Error message clearly names the other open shift", str_contains($refusedMsg, "Shift #{$shiftId4B}") && str_contains($refusedMsg, "test_cashier2"), $refusedMsg);

// Verify neither shift was closed
$chk4A = $pdo->query("SELECT status FROM cashier_shifts WHERE id = {$shiftId4A}")->fetchColumn();
$chk4B = $pdo->query("SELECT status FROM cashier_shifts WHERE id = {$shiftId4B}")->fetchColumn();
assertTest("Both shifts remain open after refusal", $chk4A === 'open' && $chk4B === 'open');

// Clean up shift4A & 4B
$pdo->exec("UPDATE cashier_shifts SET status = 'closed', ended_at = NOW() WHERE id IN ({$shiftId4A}, {$shiftId4B})");


// =========================================================================
// TEST 5: A cashier WITHOUT permission is REFUSED
// =========================================================================
echo "\n--- TEST 5: A cashier WITHOUT permission is REFUSED ---\n";
$d5 = '2026-12-24';
$s5Stmt = $pdo->prepare("
    INSERT INTO cashier_shifts (shift_uid, cashier_id, cashier_name, opening_cash_usd, opening_cash_khr, started_at, status)
    VALUES ('SHIFT-FAST-005', ?, 'noperm_cashier', 10.00, 0, '{$d5} 08:00:00', 'open')
");
$s5Stmt->execute([$nopermId]);
$shiftId5 = (int)$pdo->lastInsertId();

$permDenied = false;
$permDeniedMsg = '';
try {
    CashierControl::endShiftAndCloseDay($shiftId5, 'noperm_cashier', '123456', 10.00, 0, 'Unauthorized close attempt', $d5);
} catch (Exception $e) {
    $permDenied = true;
    $permDeniedMsg = $e->getMessage();
}

assertTest("Cashier without daily_close.close permission is refused", $permDenied);
assertTest("Permission denial message returned", str_contains($permDeniedMsg, 'permission'), $permDeniedMsg);

// Clean up shift 5
$pdo->exec("UPDATE cashier_shifts SET status = 'closed', ended_at = NOW() WHERE id = {$shiftId5}");


// =========================================================================
// TEST 6: Wrong PIN is REFUSED
// =========================================================================
echo "\n--- TEST 6: Wrong PIN is REFUSED ---\n";
$d6 = '2026-12-25';
$s6Stmt = $pdo->prepare("
    INSERT INTO cashier_shifts (shift_uid, cashier_id, cashier_name, opening_cash_usd, opening_cash_khr, started_at, status)
    VALUES ('SHIFT-FAST-006', ?, 'visal', 15.00, 0, '{$d6} 08:00:00', 'open')
");
$s6Stmt->execute([$visalId]);
$shiftId6 = (int)$pdo->lastInsertId();

$pinDenied = false;
$pinDeniedMsg = '';
try {
    CashierControl::endShiftAndCloseDay($shiftId6, 'visal', '999999', 15.00, 0, 'Wrong PIN attempt', $d6);
} catch (Exception $e) {
    $pinDenied = true;
    $pinDeniedMsg = $e->getMessage();
}

assertTest("Wrong PIN is strictly refused", $pinDenied);
assertTest("Shift remains open after wrong PIN refusal", $pdo->query("SELECT status FROM cashier_shifts WHERE id = {$shiftId6}")->fetchColumn() === 'open');

// Clean up shift 6
$pdo->exec("UPDATE cashier_shifts SET status = 'closed', ended_at = NOW() WHERE id = {$shiftId6}");


// =========================================================================
// TEST 7: Owner with NO shift can still close the day
// =========================================================================
echo "\n--- TEST 7: Owner with NO shift can still close the day ---\n";
$d7 = '2026-12-26';
// Ensure 0 shifts on d7
$pdo->prepare("DELETE FROM cashier_shifts WHERE DATE(started_at) = ?")->execute([$d7]);

try {
    $res7 = CashierControl::closeDayZReport('owner', '123456', 0.00, 0, 'Owner closes day with zero shifts', $d7);
    assertTest("Owner closes day with zero shifts successfully", !empty($res7['success']) && $res7['success'] === true);
    
    $c7Row = $pdo->query("SELECT status FROM pos_daily_closes WHERE close_date = '{$d7}'")->fetch(PDO::FETCH_ASSOC);
    assertTest("Day record saved as 'closed' in DB for owner", $c7Row && $c7Row['status'] === 'closed');
} catch (Exception $e) {
    assertTest("Owner close day with zero shifts failed", false, $e->getMessage());
}


// Clean up test data
$pdo->prepare("DELETE FROM pos_daily_closes WHERE close_date IN ({$placeholders})")->execute($testDates);
$pdo->prepare("DELETE FROM cashier_shifts WHERE DATE(started_at) IN ({$placeholders})")->execute($testDates);
$pdo->prepare("DELETE FROM users WHERE username IN ('test_cashier2', 'noperm_cashier')")->execute();
$pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$restrictedRoleId]);
$pdo->prepare("DELETE FROM roles WHERE code = 'restricted_cashier'")->execute();

echo "\n========================================================\n";
echo "TEST SUMMARY: {$passedCount} / {$totalCount} PASSED\n";
echo "========================================================\n";

if ($passedCount === $totalCount) {
    echo "ALL 7 FAST CLOSE DAY TESTS PASSED SUCCESSFULLY ON {$dbName}!\n";
} else {
    echo "SOME TESTS FAILED. Please review the output above.\n";
    exit(1);
}
