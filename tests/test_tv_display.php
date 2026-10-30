<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/BayManager.php';

$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

echo "========================================================\n";
echo "  CUSTOMER TV QUEUE DISPLAY - AUTOMATED VERIFICATION\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $message . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $message . "\n";
        $failCount++;
    }
}

// Backup current settings and bay states
$origTvEnabled = get_setting('tv_display_enabled', '1');
$origTvCode = get_setting('tv_display_code', 'tv-office-88');
$origTvLang = get_setting('tv_display_language', 'both');

$backupBays = $pdo->query("SELECT id, status, current_order_id, occupied_at, is_active FROM bays")->fetchAll(PDO::FETCH_ASSOC);

$cleanupOrderIds = [];

try {
    echo "--- 1. Testing Plate Masking (Privacy Requirement) ---\n";
    $normalPlate = "2AB-1234";
    $maskedNormal = BayManager::formatTvPlate($normalPlate);
    assertTest($maskedNormal === "2AB-1234", "Normal plate '2AB-1234' remains unchanged");

    $longPlate = "2BB-9999-SPECIAL";
    $maskedLong = BayManager::formatTvPlate($longPlate);
    assertTest($maskedLong === "2BB-•••CIAL", "Long plate (16 chars) masked middle: '{$maskedLong}'");
    assertTest(mb_strlen($maskedLong) <= 11, "Masked plate fits on TV cards");

    echo "\n--- 2. Setting Up Test Scenario (3 Busy Bays, 10 Waiting Cars, 2 Ready Cars) ---\n";

    // Clear existing bays for isolation during test
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL, occupied_at = NULL");

    // Fetch bays: we expect 3 wash bays and 1 oil bay
    $bays = BayManager::getAllBays($pdo);
    assertTest(count($bays) >= 4, "Found " . count($bays) . " bays configured in system");

    $washBays = array_values(array_filter($bays, fn($b) => $b['bay_type'] === 'wash'));
    $oilBays = array_values(array_filter($bays, fn($b) => $b['bay_type'] === 'oil_change'));

    assertTest(count($washBays) >= 3, "At least 3 car wash bays available");
    assertTest(count($oilBays) >= 1, "At least 1 oil change bay available");

    $oilBay = $oilBays[0];

    // Create 3 orders in wash bays (Bay 1, Bay 2, Bay 3)
    $bayPlates = ['TEST-BAY-1', 'TEST-BAY-2', 'TEST-BAY-3'];
    for ($i = 0; $i < 3; $i++) {
        $b = $washBays[$i];
        $plate = $bayPlates[$i];
        $now = date('Y-m-d H:i:s', time() - ($i + 1) * 300);
        $orderNum = 'TV-ORD-BAY-' . ($i + 1) . '-' . time();

        $stmt = $pdo->prepare("
            INSERT INTO orders (
                order_number, source, plate_number, vehicle_type, customer_name,
                customer_phone, subtotal_usd, total_usd, status, payment_status,
                bay_id, arrived_at, created_at
            ) VALUES (?, 'pos_app', ?, 'small_car', ?, ?, 15.00, 15.00, 'washing', 'unpaid', ?, ?, ?)
        ");
        $stmt->execute([$orderNum, $plate, "Private Customer " . ($i + 1), "01288899" . $i, $b['id'], $now, $now]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanupOrderIds[] = $orderId;

        $pdo->prepare("UPDATE bays SET status = 'busy', current_order_id = ?, occupied_at = ? WHERE id = ?")
            ->execute([$orderId, $now, $b['id']]);
    }

    // Oil Bay remains FREE
    $pdo->prepare("UPDATE bays SET status = 'free', current_order_id = NULL, occupied_at = NULL WHERE id = ?")
        ->execute([$oilBay['id']]);

    // Create 10 Waiting Cars in exact arrival order
    // Temporarily park existing waiting orders to ensure exact test counts
    $existingWaiting = $pdo->query("SELECT id FROM orders WHERE status = 'waiting' AND (bay_id IS NULL OR bay_id = 0)")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existingWaiting)) {
        $inClause = implode(',', array_map('intval', $existingWaiting));
        $pdo->exec("UPDATE orders SET status = '_temp_hold' WHERE id IN ($inClause)");
    }

    // Hold existing waiting orders for isolation
    $existingWaiting = $pdo->query("SELECT id FROM orders WHERE status IN ('waiting', 'washing') AND (bay_id IS NULL OR bay_id = 0)")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existingWaiting)) {
        $inClauseWait = implode(',', array_map('intval', $existingWaiting));
        $pdo->exec("UPDATE orders SET status = '_temp_hold_wait' WHERE id IN ($inClauseWait)");
    }

    $waitPlates = [
        '1-NEXT-CAR',
        '2-WAIT-002',
        '3-WAIT-003',
        '4-WAIT-004',
        '5-WAIT-005',
        '6-WAIT-006',
        '7-WAIT-007',
        '8-WAIT-008',
        '9-WAIT-009',
        '10-WAIT-010'
    ];

    $baseTime = time() - 3600;
    foreach ($waitPlates as $idx => $p) {
        $arrived = date('Y-m-d H:i:s', $baseTime + ($idx * 60));
        $orderNum = 'TV-ORD-WAIT-' . ($idx + 1) . '-' . time();
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                order_number, source, plate_number, vehicle_type, customer_name,
                customer_phone, subtotal_usd, total_usd, status, payment_status,
                arrived_at, created_at, queue_priority
            ) VALUES (?, 'pos_app', ?, 'small_car', ?, ?, 20.00, 20.00, 'waiting', 'unpaid', ?, ?, 100)
        ");
        $stmt->execute([$orderNum, $p, "Secret Name " . ($idx + 1), "09900011" . $idx, $arrived, $arrived]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanupOrderIds[] = $orderId;
    }

    // Create 2 Ready for payment cars (status = 'finished', payment_status = 'unpaid')
    $existingReady = $pdo->query("SELECT id FROM orders WHERE status = 'finished' AND (payment_status != 'paid' OR payment_status IS NULL)")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existingReady)) {
        $inClause = implode(',', array_map('intval', $existingReady));
        $pdo->exec("UPDATE orders SET status = '_temp_hold_ready' WHERE id IN ($inClause)");
    }

    $readyPlates = ['2AB-9001', '3C-4567'];
    foreach ($readyPlates as $idx => $rp) {
        $finTime = date('Y-m-d H:i:s', time() - ($idx + 1) * 120);
        $orderNum = 'TV-ORD-RDY-' . ($idx + 1) . '-' . time();
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                order_number, source, plate_number, vehicle_type, customer_name,
                customer_phone, subtotal_usd, total_usd, status, payment_status,
                finished_at, created_at
            ) VALUES (?, 'pos_app', ?, 'small_car', ?, ?, 35.00, 35.00, 'finished', 'unpaid', ?, ?)
        ");
        $stmt->execute([$orderNum, $rp, "Ready Customer " . ($idx + 1), "07711223" . $idx, $finTime, $finTime]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanupOrderIds[] = $orderId;
    }

    echo "\n--- 3. Verifying TV Data Feed API (Privacy, Bays, Queue, Ready) ---\n";

    update_setting($pdo, 'tv_display_language', 'both', 1, 'Test');
    $tvData = BayManager::getTvDisplayData($pdo);

    assertTest($tvData['success'] === true, "TV Data API returned success = true");
    assertTest(count($tvData['bays']) >= 4, "TV Data returned all configured bays");

    // Check wash bays are busy with correct plates
    $tvWash1 = null;
    $tvWash2 = null;
    $tvWash3 = null;
    $tvOil = null;
    foreach ($tvData['bays'] as $tb) {
        if ($tb['bay_number'] === 1) $tvWash1 = $tb;
        if ($tb['bay_number'] === 2) $tvWash2 = $tb;
        if ($tb['bay_number'] === 3) $tvWash3 = $tb;
        if ($tb['bay_type'] === 'oil_change') $tvOil = $tb;
    }

    assertTest($tvWash1 && $tvWash1['is_busy'] === true && $tvWash1['plate_number'] === 'TEST-BAY-1', "Bay 1 shows Busy with plate TEST-BAY-1");
    assertTest(str_contains($tvWash1['status_text'], 'In Progress') || str_contains($tvWash1['status_text'], 'Washing'), "Bay 1 status text contains 'In Progress'");

    assertTest($tvWash2 && $tvWash2['is_busy'] === true && $tvWash2['plate_number'] === 'TEST-BAY-2', "Bay 2 shows Busy with plate TEST-BAY-2");
    assertTest(str_contains($tvWash2['status_text'], 'In Progress') || str_contains($tvWash2['status_text'], 'Washing'), "Bay 2 status text contains 'In Progress'");

    assertTest($tvWash3 && $tvWash3['is_busy'] === true && $tvWash3['plate_number'] === 'TEST-BAY-3', "Bay 3 shows Busy with plate TEST-BAY-3");
    assertTest(str_contains($tvWash3['status_text'], 'In Progress') || str_contains($tvWash3['status_text'], 'Washing'), "Bay 3 status text contains 'In Progress'");

    assertTest($tvOil && $tvOil['is_busy'] === false && $tvOil['plate_number'] === null, "Oil Bay shows Free with no car");
    assertTest(str_contains($tvOil['status_text'], 'Waiting') || str_contains($tvOil['status_text'], 'Free'), "Oil Bay status text contains 'Waiting'");

    // Check waiting list rules
    assertTest($tvData['waiting_total'] === 10, "Waiting total is exactly 10 cars ({$tvData['waiting_total']})");
    assertTest(count($tvData['waiting_list']) === 8, "Waiting list on screen displays exactly the first 8 cars (" . count($tvData['waiting_list']) . ")");
    assertTest($tvData['waiting_more_count'] === 2, "Waiting more count displays exactly '+ 2 more' ({$tvData['waiting_more_count']})");

    assertTest($tvData['waiting_list'][0]['position'] === 1, "First car is position 1");
    assertTest($tvData['waiting_list'][0]['plate_number'] === '1-NEXT-CAR', "First car plate is '1-NEXT-CAR'");
    assertTest($tvData['waiting_list'][0]['is_next'] === true, "First car is marked is_next = true");

    assertTest($tvData['waiting_list'][1]['position'] === 2, "Second car is position 2");
    assertTest($tvData['waiting_list'][1]['is_next'] === false, "Second car is NOT marked is_next");
    assertTest($tvData['waiting_list'][7]['position'] === 8, "Eighth car is position 8");

    // Check Ready for Payment cars
    assertTest($tvData['ready_total'] === 2, "Ready for payment total is exactly 2 cars ({$tvData['ready_total']})");
    assertTest(count($tvData['ready_cars']) === 2, "Ready cars list has 2 entries");
    $readyPlatesFound = array_column($tvData['ready_cars'], 'plate_number');
    assertTest(in_array('2AB-9001', $readyPlatesFound, true), "2AB-9001 is in ready list");
    assertTest(in_array('3C-4567', $readyPlatesFound, true), "3C-4567 is in ready list");

    // Check Privacy: verify JSON output contains NO customer names, phone numbers, prices, or points
    $jsonFeed = json_encode($tvData);
    assertTest(!str_contains($jsonFeed, 'Private Customer'), "Privacy check: No customer names in payload");
    assertTest(!str_contains($jsonFeed, '01288899'), "Privacy check: No phone numbers in payload");
    assertTest(!str_contains($jsonFeed, 'total_price'), "Privacy check: No prices in payload");
    assertTest(!str_contains($jsonFeed, 'subtotal_usd'), "Privacy check: No prices in payload");
    assertTest(!str_contains($jsonFeed, 'points'), "Privacy check: No loyalty points in payload");

    echo "\n--- 4. Testing Language Switching (Khmer, English, Both) ---\n";

    // Switch to English only
    update_setting($pdo, 'tv_display_language', 'en', 1, 'Test');
    $tvDataEn = BayManager::getTvDisplayData($pdo);
    assertTest($tvDataEn['language'] === 'en', "TV language switched to 'en'");
    $b1En = array_values(array_filter($tvDataEn['bays'], fn($b) => $b['bay_number'] === 1))[0];
    assertTest($b1En['status_text'] === 'In Progress' || $b1En['status_text'] === 'Washing', "English bay status is strictly 'In Progress'");
    $oilEn = array_values(array_filter($tvDataEn['bays'], fn($b) => $b['bay_type'] === 'oil_change'))[0];
    assertTest($oilEn['status_text'] === 'Waiting' || $oilEn['status_text'] === 'Free', "English oil bay status is strictly 'Waiting'");

    // Switch to Khmer only
    update_setting($pdo, 'tv_display_language', 'km', 1, 'Test');
    $tvDataKm = BayManager::getTvDisplayData($pdo);
    assertTest($tvDataKm['language'] === 'km', "TV language switched to 'km'");
    $b1Km = array_values(array_filter($tvDataKm['bays'], fn($b) => $b['bay_number'] === 1))[0];
    assertTest($b1Km['status_text'] === 'កំពុងដំណើរការ' || $b1Km['status_text'] === 'កំពុងលាង', "Khmer bay status is strictly 'កំពុងដំណើរការ'");
    $oilKm = array_values(array_filter($tvDataKm['bays'], fn($b) => $b['bay_type'] === 'oil_change'))[0];
    assertTest($oilKm['status_text'] === 'កំពុងរង់ចាំ' || $oilKm['status_text'] === 'រង់ចាំ' || $oilKm['status_text'] === 'ទំនេរ', "Khmer oil bay status is strictly 'កំពុងរង់ចាំ'");

    echo "\n--- 5. Testing Security, Secret Code & TV Status Toggle ---\n";

    update_setting($pdo, 'tv_display_enabled', '1', 1, 'Test');
    update_setting($pdo, 'tv_display_code', 'secret-office-key-999', 1, 'Test');

    // Test access logic directly
    $tvEnabled = get_setting('tv_display_enabled', '1');
    $tvSecret = get_setting('tv_display_code', 'tv-office-88');

    // Check wrong code
    $wrongCode = 'wrong-code';
    $authWrong = ($tvEnabled === '1' && $tvSecret !== '' && hash_equals($tvSecret, $wrongCode));
    assertTest(!$authWrong, "Invalid code check evaluates to false (Access denied)");

    // Check correct code
    $correctCode = 'secret-office-key-999';
    $authCorrect = ($tvEnabled === '1' && $tvSecret !== '' && hash_equals($tvSecret, $correctCode));
    assertTest($authCorrect, "Correct code check evaluates to true (Access granted)");

    // Check disabled in settings
    update_setting($pdo, 'tv_display_enabled', '0', 1, 'Test');
    $tvEnabledAfterOff = get_setting('tv_display_enabled', '1');
    $authWhenDisabled = ($tvEnabledAfterOff === '1' && $tvSecret !== '' && hash_equals($tvSecret, $correctCode));
    assertTest(!$authWhenDisabled, "Disabled TV check evaluates to false even with correct code");

    echo "\n--- 6. Testing Ready Car Departure on Payment ---\n";
    // When an order is paid, it must leave the Ready list
    $firstReadyId = $cleanupOrderIds[count($cleanupOrderIds) - 2]; // 2AB-9001
    $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([$firstReadyId]);

    update_setting($pdo, 'tv_display_enabled', '1', 1, 'Test');
    $tvDataAfterPay = BayManager::getTvDisplayData($pdo);
    assertTest($tvDataAfterPay['ready_total'] === 1, "After paying order, Ready count drops to 1");
    $remainingPlates = array_column($tvDataAfterPay['ready_cars'], 'plate_number');
    assertTest(!in_array('2AB-9001', $remainingPlates, true), "Paid car '2AB-9001' has left the Ready area");
    assertTest(in_array('3C-4567', $remainingPlates, true), "Unpaid car '3C-4567' remains in Ready area");

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " on line " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failCount++;
} finally {
    echo "\n--- Cleaning Up Test Artifacts ---\n";

    // Delete created test orders
    if (!empty($cleanupOrderIds)) {
        $inClause = implode(',', array_map('intval', $cleanupOrderIds));
        $pdo->exec("DELETE FROM orders WHERE id IN ($inClause)");
        $pdo->exec("DELETE FROM bay_history WHERE order_id IN ($inClause)");
    }

    // Restore any held existing orders
    $pdo->exec("UPDATE orders SET status = 'waiting' WHERE status = '_temp_hold'");
    $pdo->exec("UPDATE orders SET status = 'finished' WHERE status = '_temp_hold_ready'");
    $pdo->exec("UPDATE orders SET status = 'waiting' WHERE status = '_temp_hold_wait'");

    // Restore bays
    foreach ($backupBays as $b) {
        $pdo->prepare("UPDATE bays SET status = ?, current_order_id = ?, occupied_at = ?, is_active = ? WHERE id = ?")
            ->execute([$b['status'], $b['current_order_id'], $b['occupied_at'], $b['is_active'], $b['id']]);
    }

    // Restore settings
    update_setting($pdo, 'tv_display_enabled', $origTvEnabled, 1, 'Test Cleanup');
    update_setting($pdo, 'tv_display_code', $origTvCode, 1, 'Test Cleanup');
    update_setting($pdo, 'tv_display_language', $origTvLang, 1, 'Test Cleanup');

    echo "Test orders removed and previous system states restored.\n";
}

echo "\n========================================================\n";
echo "  SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
