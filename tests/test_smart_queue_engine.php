<?php
declare(strict_types=1);

/**
 * Comprehensive Automated Test Suite for Smart Queue Engine & Duration Estimator
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Helpers.php';
require_once dirname(__DIR__) . '/core/BayManager.php';
require_once dirname(__DIR__) . '/core/SmartQueueEngine.php';
require_once dirname(__DIR__) . '/core/NotificationManager.php';

// ISOLATED TEMPORARY SQLITE DATABASE SETUP
$tempDbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_sqe_' . uniqid() . '.sqlite';
$pdo = new PDO('sqlite:' . $tempDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;");

// Polyfill SQLite functions
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'));
$pdo->sqliteCreateFunction('CURRENT_DATE', fn() => date('Y-m-d'));
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $dt1, $dt2) {
    if (!$dt1 || !$dt2) return 0;
    $t1 = strtotime((string)$dt1);
    $t2 = strtotime((string)$dt2);
    if ($t1 <= 0 || $t2 <= 0) return 0;
    return (int)round(($t2 - $t1) / 60);
});

// Inject temporary PDO instance into Database singleton via reflection
$refProp = new ReflectionProperty('Database', 'instance');
$refProp->setAccessible(true);
$refProp->setValue(null, $pdo);

$refDriver = new ReflectionProperty('Database', 'driver');
$refDriver->setAccessible(true);
$refDriver->setValue(null, 'sqlite');

// Initialize isolated schema
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        password_hash TEXT DEFAULT 'hash',
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        effective_role TEXT DEFAULT 'washer',
        status TEXT DEFAULT 'active',
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        name_kh TEXT DEFAULT '',
        category TEXT DEFAULT 'wash',
        base_duration_minutes INTEGER DEFAULT 30,
        price_usd REAL DEFAULT 5.00,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        category TEXT DEFAULT 'general',
        price_usd REAL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS bays (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bay_number INTEGER NOT NULL,
        bay_name TEXT NOT NULL,
        bay_type TEXT NOT NULL DEFAULT 'wash',
        skill_tag TEXT DEFAULT 'wash_only',
        status TEXT NOT NULL DEFAULT 'free',
        current_order_id INTEGER DEFAULT NULL,
        occupied_at TEXT DEFAULT NULL,
        last_freed_at TEXT DEFAULT NULL,
        sort_order INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL,
        order_uid TEXT DEFAULT NULL,
        customer_id INTEGER DEFAULT NULL,
        customer_name TEXT DEFAULT NULL,
        customer_phone TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        vehicle_type TEXT DEFAULT 'small_car',
        bay_id INTEGER DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'waiting',
        payment_status TEXT NOT NULL DEFAULT 'unpaid',
        payment_method TEXT DEFAULT 'cash',
        subtotal_usd REAL DEFAULT 0,
        total_usd REAL DEFAULT 0,
        total_khr INTEGER DEFAULT 0,
        repair_status TEXT DEFAULT NULL,
        repair_notes TEXT DEFAULT NULL,
        next_oil_mileage INTEGER DEFAULT NULL,
        estimated_duration_minutes INTEGER DEFAULT 25,
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT NULL,
        arrived_at TEXT DEFAULT NULL,
        started_at TEXT DEFAULT NULL,
        finished_at TEXT DEFAULT NULL,
        paid_at TEXT DEFAULT NULL,
        receipt_printed_at TEXT DEFAULT NULL,
        receipt_issued_at TEXT DEFAULT NULL,
        is_express INTEGER DEFAULT 0,
        is_mixed INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        queue_priority INTEGER DEFAULT 0,
        priority_level TEXT DEFAULT 'P3',
        appointment_time TEXT DEFAULT NULL,
        idempotency_key TEXT DEFAULT NULL,
        pos_device_id TEXT DEFAULT NULL,
        pos_device_name TEXT DEFAULT NULL,
        source TEXT DEFAULT 'pos_app',
        delay_reason TEXT DEFAULT NULL,
        delay_logged_at DATETIME DEFAULT NULL,
        delay_logged_by INTEGER DEFAULT NULL,
        MINUTE TEXT DEFAULT 'MINUTE',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_uid TEXT NOT NULL,
        user_id INTEGER DEFAULT NULL,
        username TEXT DEFAULT NULL,
        role TEXT DEFAULT NULL,
        action TEXT NOT NULL,
        entity_type TEXT DEFAULT NULL,
        entity_id TEXT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip_address TEXT DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        device_name TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER DEFAULT NULL,
        product_id INTEGER DEFAULT NULL,
        item_type TEXT DEFAULT 'service',
        service_name TEXT NOT NULL,
        price_usd REAL DEFAULT 0,
        price_khr INTEGER DEFAULT 0,
        qty INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS order_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        task_type TEXT NOT NULL,
        title TEXT NOT NULL,
        sequence INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'waiting',
        assigned_user_id INTEGER DEFAULT NULL,
        base_duration_minutes INTEGER DEFAULT 15,
        estimated_duration_minutes INTEGER DEFAULT 15,
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT NULL,
        started_at TEXT DEFAULT NULL,
        completed_at TEXT DEFAULT NULL,
        MINUTE TEXT DEFAULT 'MINUTE',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        phone TEXT,
        plate_number TEXT,
        membership_level TEXT DEFAULT 'regular',
        loyalty_points INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS order_washers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        washer_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS order_mechanics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        mechanic_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS bay_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        bay_id INTEGER DEFAULT NULL,
        bay_name TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        action TEXT NOT NULL,
        performed_by INTEGER DEFAULT NULL,
        performed_by_name TEXT DEFAULT NULL,
        performed_by_role TEXT DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS parts_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_uid TEXT NOT NULL UNIQUE,
        order_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        product_name TEXT NOT NULL,
        qty_requested INTEGER DEFAULT 1,
        status TEXT DEFAULT 'pending',
        requested_by_id INTEGER DEFAULT NULL,
        requested_by_name TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_name TEXT NOT NULL,
        customer_phone TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        vehicle_type TEXT DEFAULT 'small_car',
        service_id INTEGER DEFAULT NULL,
        appointment_date TEXT NOT NULL,
        appointment_time TEXT NOT NULL,
        status TEXT DEFAULT 'confirmed',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS service_duration_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id INTEGER DEFAULT NULL,
        service_name TEXT NOT NULL,
        vehicle_type TEXT NOT NULL,
        order_id INTEGER NOT NULL,
        task_id INTEGER DEFAULT NULL,
        estimated_minutes INTEGER NOT NULL,
        actual_minutes INTEGER NOT NULL,
        over_percentage REAL NOT NULL,
        washer_answered_longer INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending',
        reviewed_by INTEGER DEFAULT NULL,
        reviewed_at TEXT DEFAULT NULL,
        resolution_notes TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS system_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        notification_uid TEXT NOT NULL UNIQUE,
        target_role TEXT DEFAULT NULL,
        target_user_id INTEGER DEFAULT NULL,
        category TEXT NOT NULL,
        title_en TEXT NOT NULL,
        title_km TEXT DEFAULT NULL,
        message_en TEXT NOT NULL,
        message_km TEXT DEFAULT NULL,
        action_url TEXT DEFAULT NULL,
        sound_type TEXT DEFAULT 'chime',
        reference_id TEXT DEFAULT NULL,
        is_read INTEGER DEFAULT 0,
        read_at TEXT DEFAULT NULL,
        read_by_id INTEGER DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
");

// Populate isolated test fixtures
$pdo->exec("
    INSERT INTO services (id, name_en, category, base_duration_minutes, price_usd) VALUES
    (1, 'Exterior Wash', 'wash', 30, 5.00),
    (7, 'Tire Change & Wheel Balance', 'maintenance', 45, 25.00);

    INSERT INTO users (id, username, full_name, role, effective_role, status, is_active) VALUES
    (1, 'washer1', 'Washer Sopheap', 'washer', 'washer', 'active', 1),
    (2, 'manager1', 'Owner Panha', 'owner', 'owner', 'active', 1),
    (3, 'tech1', 'Tech Bunthoeun', 'mechanic', 'mechanic', 'active', 1);

    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, is_active) VALUES
    (1, 1, 'Bay 1', 'wash', 'wash_only', 'free', 1),
    (2, 2, 'Bay 2', 'wash', 'wash_only', 'free', 1),
    (3, 3, 'Bay 3', 'wash', 'wash_only', 'free', 1),
    (4, 4, 'Bay 4', 'oil_change', 'oil_only', 'free', 1);

    INSERT INTO settings (setting_key, setting_value) VALUES
    ('estimate_buffer_percent', '10');
");

BayManager::ensureTables($pdo);

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $message, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

echo "=== SMART QUEUE ENGINE COMPREHENSIVE TEST SUITE ===\n\n";

// ---------------------------------------------------------
// TEST 1: Service Duration & Vehicle Multipliers
// ---------------------------------------------------------
echo "[1] Testing Vehicle Size Multipliers & Service Benchmark Durations...\n";

$multSedan = SmartQueueEngine::getVehicleMultiplier($pdo, 'small_car');
$multSuv = SmartQueueEngine::getVehicleMultiplier($pdo, 'suv_pickup');
$multTruck = SmartQueueEngine::getVehicleMultiplier($pdo, 'van_truck');
$multMotorbike = SmartQueueEngine::getVehicleMultiplier($pdo, 'motorbike');

assertTest($multSedan === 1.0, "Sedan multiplier is 1.0", "Got: {$multSedan}");
assertTest($multSuv === 1.25, "SUV / Pickup multiplier is 1.25", "Got: {$multSuv}");
assertTest($multTruck === 1.4, "Van / Truck multiplier is 1.40", "Got: {$multTruck}");
assertTest($multMotorbike === 0.6, "Motorbike multiplier is 0.60", "Got: {$multMotorbike}");

// Base duration for Basic Wash & Oil
$washSvc = $pdo->query("SELECT id, name_en, base_duration_minutes FROM services WHERE category IN ('wash', 'car_wash') OR name_en LIKE '%Wash%' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$oilSvc  = $pdo->query("SELECT id, name_en, base_duration_minutes FROM services WHERE category IN ('oil', 'oil_change', 'maintenance', 'repair') OR name_en LIKE '%Oil%' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assertTest(!empty($washSvc), "Wash service found in catalog: #{$washSvc['id']} - {$washSvc['name_en']}");
assertTest(!empty($oilSvc), "Oil service found in catalog: #{$oilSvc['id']} - {$oilSvc['name_en']}");

$washSedan = SmartQueueEngine::calculateEstimatedDuration($pdo, [(int)$washSvc['id']], 'small_car');
$washSuv   = SmartQueueEngine::calculateEstimatedDuration($pdo, [(int)$washSvc['id']], 'suv_pickup');

assertTest($washSuv['total_estimated_minutes'] > $washSedan['total_estimated_minutes'],
    "SUV estimate ({$washSuv['total_estimated_minutes']}m) is longer than Sedan estimate ({$washSedan['total_estimated_minutes']}m) using multiplier");


// ---------------------------------------------------------
// TEST 2: Technician & Washer Efficiency Rolling Average
// ---------------------------------------------------------
echo "\n[2] Testing Staff Efficiency Rolling Average...\n";

// Fetch an existing washer or create test washer
$testWasher = $pdo->query("SELECT id, full_name, username, role FROM users WHERE role IN ('washer', 'washer1', 'wash_supervisor') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$testWasher) {
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, status, is_active) VALUES ('test_washer_sq', 'hash', 'Test Washer SQ', 'washer', 'active', 1)")->execute();
    $testWasherId = (int)$pdo->lastInsertId();
} else {
    $testWasherId = (int)$testWasher['id'];
}

$effBefore = SmartQueueEngine::getStaffEfficiency($pdo, $testWasherId);
$effFactor = (float)($effBefore['efficiency_factor'] ?? 1.0);
assertTest($effFactor >= 0.6 && $effFactor <= 2.2, "Staff rolling efficiency is clamped within healthy bounds (0.6 - 2.2): factor = {$effFactor}");

$allEff = SmartQueueEngine::getAllStaffEfficiency($pdo);
assertTest(is_array($allEff), "Manager & Owner can fetch list of staff efficiencies (Total: " . count($allEff) . ")");


// ---------------------------------------------------------
// TEST 3: Learning Review Flagging & Owner Manual Approval
// ---------------------------------------------------------
echo "\n[3] Testing Learning Engine Flagging (>20% overdue and washer feedback)...\n";

// Create a simulated finished order that took 35 mins on a 15 min estimate (>20% over)
$testOrdUid = 'TEST-SQ-' . time();
$pdo->prepare("
    INSERT INTO orders (order_number, order_uid, customer_name, plate_number, vehicle_type, status, subtotal_usd, total_usd, total_khr, is_active, payment_method, payment_status, created_at, started_at)
    VALUES (?, ?, 'SQ Test Customer', '2SQ-9999', 'small_car', 'ready_to_pay', 5.0, 5.0, 20000, 1, 'cash', 'unpaid', NOW(), NOW())
")->execute(['CW-TEST-SQ-' . time(), $testOrdUid]);
$testOrderId = (int)$pdo->lastInsertId();

// Assign service item
$pdo->prepare("
    INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, price_khr, qty)
    VALUES (?, ?, 'service', ?, 5.0, 20000, 1)
")->execute([$testOrderId, (int)$washSvc['id'], $washSvc['name_en']]);

// Simulate finishing order with took_longer = 1 and actual 35 min vs 15 min estimate
$revFlag = SmartQueueEngine::checkAndFlagForReview($pdo, $testOrderId, null, 15, 35, 1);
assertTest($revFlag !== null && $revFlag > 0, "Job >20% over estimate was flagged for review (Review ID #{$revFlag})");

$pendingList = SmartQueueEngine::getPendingReviews($pdo);
$foundPending = false;
foreach ($pendingList as $pItem) {
    if ((int)$pItem['id'] === $revFlag) {
        $foundPending = true;
        break;
    }
}
assertTest($foundPending, "Flagged job appears in Pending Reviews list for Manager & Owner");

// Verify server does NOT adjust base duration automatically
$currBase = SmartQueueEngine::getServiceBaseDuration($pdo, (int)$washSvc['id']);
assertTest($currBase === (int)$washSvc['base_duration_minutes'], "Server did NOT change base duration automatically (Still {$currBase}m)");

// Manager manually resolves and updates base duration
$resolveRes = SmartQueueEngine::resolveReview($pdo, $revFlag, 'update', 18, 1, 'Owner Panha', 'Adjusted base duration after team review');
assertTest($resolveRes['success'] === true, "Owner/Manager successfully approved and adjusted base duration");

$updatedBase = SmartQueueEngine::getServiceBaseDuration($pdo, (int)$washSvc['id']);
assertTest($updatedBase === 18, "Service base duration is now updated to 18m in catalog");

// Restore original base duration
$pdo->prepare("UPDATE services SET base_duration_minutes = ? WHERE id = ?")->execute([(int)$washSvc['base_duration_minutes'], (int)$washSvc['id']]);


// ---------------------------------------------------------
// TEST 4: Automatic Bay Choice & Scoring Engine
// ---------------------------------------------------------
echo "\n[4] Testing Automatic Bay Choice Scoring & Supervisor Override...\n";

// Order with wash only
$scoredBays = SmartQueueEngine::scoreBaysForCar($pdo, $testOrderId);
assertTest(!empty($scoredBays), "Bay scoring engine evaluated bays for vehicle (Evaluated " . count($scoredBays) . " bays)");
assertTest(isset($scoredBays[0]['score']) && $scoredBays[0]['score'] > 0, "Top bay has positive score: Bay #{$scoredBays[0]['bay_number']} (Score: {$scoredBays[0]['score']})");

// Verify Wash Bays (1-3) score higher for wash than Oil Bay (Bay 4)
$washBayScore = 0;
$oilBayScore = 0;
foreach ($scoredBays as $sb) {
    if ($sb['skill_tag'] === 'wash_only') $washBayScore = max($washBayScore, $sb['score']);
    if ($sb['skill_tag'] === 'oil_only') $oilBayScore = max($oilBayScore, $sb['score']);
}
assertTest($washBayScore > $oilBayScore, "Wash bay scored higher ({$washBayScore}) than Oil bay ({$oilBayScore}) for wash job");


// ---------------------------------------------------------
// TEST 5: Remaining Time & Estimate Buffer
// ---------------------------------------------------------
echo "\n[5] Testing Remaining Time with Estimate Buffer...\n";

$bufferPct = (int)get_setting('estimate_buffer_percent', '10');
assertTest($bufferPct === 10, "Estimate buffer setting exists and defaults to 10%", "Got: {$bufferPct}");

// Put car in Bay 1 for remaining time check
$bay1 = $pdo->query("SELECT id FROM bays WHERE bay_number = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($bay1) {
    $pdo->prepare("UPDATE bays SET status = 'busy', current_order_id = ?, occupied_at = NOW() WHERE id = ?")->execute([$testOrderId, $bay1['id']]);
    $pdo->prepare("UPDATE orders SET bay_id = ?, started_at = NOW(), estimated_duration_minutes = 20 WHERE id = ?")->execute([$bay1['id'], $testOrderId]);

    $remTime = SmartQueueEngine::getBayRemainingMinutes($pdo, (int)$bay1['id']);
    assertTest($remTime['remaining_minutes'] !== null, "Remaining time calculated: {$remTime['remaining_minutes']}m left (Buffered: {$remTime['buffered_remaining_minutes']}m)");
}


// ---------------------------------------------------------
// TEST 6: Delay Reasons & Auto-Detection for Parts
// ---------------------------------------------------------
echo "\n[6] Testing Delay Reasons & Parts Approval Delay Detection...\n";

$reasonsList = SmartQueueEngine::getAvailableDelayReasons($pdo);
assertTest(count($reasonsList) >= 4, "Available delay reasons list loaded (Count: " . count($reasonsList) . ")");

// Set delay reason
$setOk = SmartQueueEngine::setOrderDelayReason($pdo, $testOrderId, 'Heavy soil - extra rinse', 1, 'Wash Supervisor');
assertTest($setOk === true, "Supervisor successfully recorded delay reason");

$checkRem = SmartQueueEngine::getBayRemainingMinutes($pdo, (int)$bay1['id']);
assertTest($checkRem['delay_reason'] === 'Heavy soil - extra rinse', "Delay reason saved and attached to bay: '{$checkRem['delay_reason']}'");

// Simulate pending parts request
$partUid = 'REQ-TEST-' . time();
$pdo->prepare("
    INSERT INTO parts_requests (request_uid, order_id, product_id, product_name, qty_requested, status, requested_by_id, requested_by_name, created_at)
    VALUES (?, ?, 1, 'Oil Filter OEM', 1, 'pending', 1, 'Mechanic SQ', NOW())
")->execute([$partUid, $testOrderId]);
$reqId = (int)$pdo->lastInsertId();

$autoPartsDelay = SmartQueueEngine::autoCheckPartsDelay($pdo, $testOrderId);
assertTest($autoPartsDelay === 'Waiting for part approval', "Auto-detected delay for pending part request: '{$autoPartsDelay}'");

// Clean up parts request
$pdo->prepare("DELETE FROM parts_requests WHERE id = ?")->execute([$reqId]);


// ---------------------------------------------------------
// TEST 7: Queue TV Feed Privacy & Delay Banner
// ---------------------------------------------------------
echo "\n[7] Testing Queue TV Feed Privacy & Delay Display...\n";

$tvData = BayManager::getTvDisplayData($pdo);
assertTest(isset($tvData['bays']) && is_array($tvData['bays']), "TV Feed returned bays data array");

$tvBay1 = null;
foreach ($tvData['bays'] as $tb) {
    if ((int)$tb['bay_number'] === 1) {
        $tvBay1 = $tb;
        break;
    }
}

assertTest($tvBay1 !== null && $tvBay1['is_busy'] === true, "TV Feed shows Bay 1 is busy with vehicle");
assertTest(isset($tvBay1['remaining_minutes']), "TV Feed includes remaining minutes: {$tvBay1['remaining_minutes']}m left");
assertTest(!empty($tvBay1['delay_reason']), "TV Feed includes delay banner for late car: '{$tvBay1['delay_reason']}'");

// STRICT PRIVACY CHECK: No customer name, phone, price or loyalty points in TV feed JSON
$tvJson = json_encode($tvData);
assertTest(!str_contains($tvJson, 'SQ Test Customer'), "PRIVACY VERIFIED: No customer names in TV payload");
assertTest(!str_contains($tvJson, 'customer_phone'), "PRIVACY VERIFIED: No customer phone in TV payload");
assertTest(!str_contains($tvJson, 'price_usd') && !str_contains($tvJson, 'loyalty_points'), "PRIVACY VERIFIED: No prices or loyalty points in TV payload");


// ---------------------------------------------------------
// TEST 8: Appointments Priority (On-Time P0 vs Late P3)
// ---------------------------------------------------------
echo "\n[8] Testing Appointment Priority (Within 10m P0 vs Late P3) & Hourly Slots...\n";

$todayStr = date('Y-m-d');
$nowSlotTime = date('H:i:00');

// Create test appointment
$pdo->prepare("
    INSERT INTO appointments (customer_name, customer_phone, plate_number, vehicle_type, service_id, appointment_date, appointment_time, status, created_at)
    VALUES ('Appt Tester', '012999888', '2AP-8888', 'small_car', ?, ?, ?, 'confirmed', NOW())
")->execute([(int)$washSvc['id'], $todayStr, $nowSlotTime]);
$apptId = (int)$pdo->lastInsertId();

// Arriving ON TIME (same time slot)
$prioOnTime = BayManager::determinePriority([
    'customer_id' => null,
    'plate_number' => '2AP-8888',
    'vehicle_type' => 'small_car',
    'estimated_duration_minutes' => 20,
    'arrived_at' => date('Y-m-d H:i:s')
], 0);
assertTest($prioOnTime['code'] === 'P0', "Customer arriving within 10 minutes of appointment gets P0 priority", "Got: {$prioOnTime['code']} ({$prioOnTime['label']})");

// Customer arriving LATE (> 10 mins after slot, e.g. 45 mins late)
$pastSlot = date('H:i:00', strtotime('-45 minutes'));
$pdo->prepare("UPDATE appointments SET appointment_time = ? WHERE id = ?")->execute([$pastSlot, $apptId]);

$prioLate = BayManager::determinePriority([
    'customer_id' => null,
    'plate_number' => '2AP-8888',
    'vehicle_type' => 'small_car',
    'estimated_duration_minutes' => 20,
    'arrived_at' => date('Y-m-d H:i:s')
], 0);
assertTest($prioLate['code'] === 'P3', "Customer arriving late (>10 minutes) falls back to normal walk-in P3 priority", "Got: {$prioLate['code']} ({$prioLate['label']})");

// Test free appointment slots calculation
$freeSlots = SmartQueueEngine::getFreeAppointmentSlots($pdo, $todayStr, 30);
assertTest(count($freeSlots) >= 8, "Calculated hourly appointment slots for today (Found " . count($freeSlots) . " hourly slots)");
assertTest(isset($freeSlots[0]['available_capacity']) && isset($freeSlots[0]['is_free']), "Slot capacity & free status determined by service duration");

// Clean up appointment
$pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([$apptId]);


// ---------------------------------------------------------
// TEST 9: Estimated Wait Time for POS & Supervisor Alert (>45m)
// ---------------------------------------------------------
echo "\n[9] Testing POS Estimated Wait Time & Long Wait Alert (>45 mins)...\n";

$waitCalc = SmartQueueEngine::calculateEstimatedWaitTime($pdo, (int)$washSvc['id'], 'small_car');
assertTest(isset($waitCalc['estimated_wait_minutes']), "Calculated estimated wait time: {$waitCalc['estimated_wait_minutes']}m");

// Test triggering >45m notification by simulating multiple queued jobs
$tempOrdIds = [];
for ($i = 0; $i < 6; $i++) {
    $pdo->prepare("
        INSERT INTO orders (order_number, order_uid, customer_name, plate_number, vehicle_type, status, estimated_duration_minutes, is_active, payment_method, payment_status, created_at, arrived_at)
        VALUES (?, ?, 'Queue Load Car', '2LD-000{$i}', 'suv_pickup', 'waiting', 35, 1, 'cash', 'unpaid', NOW(), NOW())
    ")->execute(["CW-LOAD-{$i}", "UID-LOAD-{$i}"]);
    $tempOrdIds[] = (int)$pdo->lastInsertId();
}

$heavyWait = SmartQueueEngine::calculateEstimatedWaitTime($pdo, (int)$oilSvc['id'], 'van_truck');
assertTest($heavyWait['is_over_45'] === true && $heavyWait['estimated_wait_minutes'] > 45,
    "Heavy queue triggers wait time > 45 minutes: {$heavyWait['estimated_wait_minutes']}m");

// Verify Supervisor received notification
NotificationManager::ensureTables($pdo);
$supNotif = $pdo->query("SELECT * FROM system_notifications WHERE target_role = 'wash_supervisor' AND category = 'long_wait' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($supNotif) && str_contains($supNotif['title_en'], 'Wait time over 45 minutes'),
    "Wash supervisor received notification: '{$supNotif['title_en']}'");

// Clean up temporary orders and notifications
foreach ($tempOrdIds as $tId) {
    $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$tId]);
}
if (!empty($supNotif)) {
    $pdo->prepare("DELETE FROM system_notifications WHERE id = ?")->execute([$supNotif['id']]);
}

// Clean up test order
$pdo->prepare("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = ?")->execute([$bay1['id']]);
$pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$testOrderId]);
$pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$testOrderId]);
$pdo->prepare("DELETE FROM service_duration_reviews WHERE order_id = ?")->execute([$testOrderId]);

// Clean up temporary database files
unset($pdo);
@unlink($tempDbPath);
@unlink($tempDbPath . '-wal');
@unlink($tempDbPath . '-shm');

// ---------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------
echo "\n=======================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
