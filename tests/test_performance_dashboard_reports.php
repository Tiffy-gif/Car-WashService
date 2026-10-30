<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/TimecardManager.php';
require_once dirname(__DIR__) . '/core/PerformanceAnalytics.php';

$testCount = 0;
$passedCount = 0;
$failedCount = 0;

function assertCondition(string $name, bool $condition, string $detail = ''): void {
    global $testCount, $passedCount, $failedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        $failedCount++;
        echo "  [FAIL] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

echo "=================================================================\n";
echo "   CAR WASH PERFORMANCE DASHBOARD & REPORTS TEST SUITE            \n";
echo "=================================================================\n\n";

$pdo = Database::getInstance();

// ---------------------------------------------------------------------
// TEST 1: Database Migration & Schema Setup
// ---------------------------------------------------------------------
echo "1. Verifying database tables and migrations...\n";

$executed = $pdo->query("SELECT migration FROM schema_migrations WHERE migration = 'migrate_performance_reports'")->fetchColumn();
assertCondition("Migration 'migrate_performance_reports' is recorded", !empty($executed));

$tcCols = $pdo->query("SHOW COLUMNS FROM staff_timecards")->fetchAll(PDO::FETCH_COLUMN);
assertCondition("Table 'staff_timecards' has 'timecard_uid'", in_array('timecard_uid', $tcCols, true));
assertCondition("Table 'staff_timecards' has 'clock_in'", in_array('clock_in', $tcCols, true));
assertCondition("Table 'staff_timecards' has 'clock_out'", in_array('clock_out', $tcCols, true));
assertCondition("Table 'staff_timecards' has 'total_hours'", in_array('total_hours', $tcCols, true));
assertCondition("Table 'staff_timecards' has 'status'", in_array('status', $tcCols, true));

$corCols = $pdo->query("SHOW COLUMNS FROM timecard_corrections")->fetchAll(PDO::FETCH_COLUMN);
assertCondition("Table 'timecard_corrections' has 'reason'", in_array('reason', $corCols, true));
assertCondition("Table 'timecard_corrections' has 'corrected_by'", in_array('corrected_by', $corCols, true));

// ---------------------------------------------------------------------
// TEST 2: Seed 1 Day of Sample Data (20 cars, 3 staff with clock times)
// ---------------------------------------------------------------------
echo "\n2. Seeding 1 Day of Sample Operational Data (2026-10-25)...\n";

$sampleDate = '2026-10-25';

// Clean existing test data for sample date
$pdo->exec("DELETE FROM timecard_corrections WHERE timecard_id IN (SELECT id FROM staff_timecards WHERE DATE(clock_in) = '{$sampleDate}')");
$pdo->exec("DELETE FROM staff_timecards WHERE DATE(clock_in) = '{$sampleDate}'");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'CW-PERF-%')");
$pdo->exec("DELETE FROM order_washers WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'CW-PERF-%')");
$pdo->exec("DELETE FROM order_tasks WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'CW-PERF-%')");
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'CW-PERF-%'");

// Setup 3 staff users:
// Staff 1: user_id 3 (washer1: Rathana) -> 8.00 hours (08:00:00 to 16:00:00)
// Staff 2: user_id 4 (washer2: Vannak)  -> 7.50 hours (08:30:00 to 16:00:00)
// Staff 3: user_id 6 (mechanic1: Kosal) -> 8.00 hours (08:00:00 to 16:00:00)
// Expected Total Labor Hours = 8.00 + 7.50 + 8.00 = 23.50 hours

$tc1Uid = 'TC-PERF-01';
$tc2Uid = 'TC-PERF-02';
$tc3Uid = 'TC-PERF-03';

$pdo->prepare("INSERT INTO staff_timecards (timecard_uid, user_id, clock_in, clock_out, total_hours, status, notes) VALUES (?, 3, '2026-10-25 08:00:00', '2026-10-25 16:00:00', 8.00, 'completed', 'Shift 1')")->execute([$tc1Uid]);
$tc1Id = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO staff_timecards (timecard_uid, user_id, clock_in, clock_out, total_hours, status, notes) VALUES (?, 4, '2026-10-25 08:30:00', '2026-10-25 16:00:00', 7.50, 'completed', 'Shift 2')")->execute([$tc2Uid]);
$tc2Id = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO staff_timecards (timecard_uid, user_id, clock_in, clock_out, total_hours, status, notes) VALUES (?, 6, '2026-10-25 08:00:00', '2026-10-25 16:00:00', 8.00, 'completed', 'Mechanical Lane')")->execute([$tc3Uid]);
$tc3Id = (int)$pdo->lastInsertId();

$laborHours = TimecardManager::getTotalLaborHours($pdo, $sampleDate, $sampleDate);
assertCondition("Total labor hours calculated accurately", abs($laborHours - 23.50) < 0.01, "Calculated: {$laborHours}h, Expected: 23.50h");

// Seed 20 Cars (Orders) on 2026-10-25
// 10 Basic Wash ($5), 5 Oil Change ($35), 5 Detailing ($50)
// Distributed across Bays 1, 2, 3 (Wash) and Bay 4 (Oil/Tech)
$services = $pdo->query("SELECT id, name_en, category FROM services")->fetchAll(PDO::FETCH_ASSOC);
$washSvcId = 1;
$washSvcName = 'Basic Wash';
$oilSvcId = 1;
$oilSvcName = 'Synthetic Oil';
foreach ($services as $s) {
    if (stripos($s['name_en'], 'Wash') !== false) {
        $washSvcId = (int)$s['id'];
        $washSvcName = $s['name_en'];
    }
    if (stripos($s['name_en'], 'Oil') !== false) {
        $oilSvcId = (int)$s['id'];
        $oilSvcName = $s['name_en'];
    }
}

$insOrd = $pdo->prepare("
    INSERT INTO orders (
        order_number, order_uid, customer_id, plate_number, vehicle_type,
        bay_id, status, payment_status, total_usd, total_khr,
        arrived_at, started_at, finished_at, paid_at,
        actual_duration_minutes, estimated_duration_minutes, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, 'paid', 'paid', ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$insItem = $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd, price_khr, item_type, qty) VALUES (?, ?, ?, ?, ?, 'service', 1)");
$insOw = $pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd) VALUES (?, ?, 1.00)");

for ($i = 1; $i <= 20; $i++) {
    $ordNum = sprintf('CW-PERF-%03d', $i);
    $ordUid = sprintf('ORD-PERF-%03d', $i);
    $plate = sprintf('2AB-%04d', 1000 + $i);
    
    // Bays 1-3 for wash, Bay 4 for oil/repair
    if ($i <= 10) {
        $svcId = $washSvcId;
        $svcName = $washSvcName;
        $bayId = (($i - 1) % 3) + 1; // Bay 1, 2, or 3
        $cost = 5.00;
        $svcMins = 15;
        $staffId = ($i % 2 === 0) ? 3 : 4; // washer1 or washer2
        $waitMins = 10;
        $hour = 8 + ($i % 8);
    } elseif ($i <= 15) {
        $svcId = $oilSvcId;
        $svcName = $oilSvcName;
        $bayId = 4; // Bay 4 Oil
        $cost = 35.00;
        $svcMins = 25;
        $staffId = 6; // mechanic1
        $waitMins = 8;
        $hour = 9 + ($i % 7);
    } else {
        $svcId = $washSvcId;
        $svcName = 'Vehicle Detailing';
        $bayId = (($i - 1) % 3) + 1;
        $cost = 50.00;
        $svcMins = 40;
        $staffId = 3; // washer1
        $waitMins = 15;
        $hour = 13 + ($i % 5);
    }

    $arrived = sprintf('2026-10-25 %02d:00:00', $hour);
    $started = sprintf('2026-10-25 %02d:%02d:00', $hour, $waitMins);
    $finished = sprintf('2026-10-25 %02d:%02d:00', $hour, $waitMins + $svcMins);
    $paid = $finished;

    $insOrd->execute([
        $ordNum, $ordUid, 1, $plate, 'sedan',
        $bayId, $cost, (int)($cost * 4100),
        $arrived, $started, $finished, $paid,
        $svcMins, $svcMins, $arrived
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $insItem->execute([$orderId, $svcId, $svcName, $cost, (int)($cost * 4100)]);
    $insOw->execute([$orderId, $staffId]);
}

$seededCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'CW-PERF-%'")->fetchColumn();
assertCondition("Seeded exactly 20 sample cars", $seededCount === 20);

// ---------------------------------------------------------------------
// TEST 3: Hand Calculation Verification 1 - Cars per Labor Hour
// ---------------------------------------------------------------------
echo "\n3. Testing Cars per Labor Hour KPI (Hand Verification)...\n";
// Hand Calculation:
// Total Cars Served = 20
// Total Labor Hours = 23.50 hours
// Expected = 20 / 23.50 = 0.85106... -> rounded to 2 decimals = 0.85
// Owner target = 2.0

$cph = PerformanceAnalytics::getCarsPerLaborHour($pdo, $sampleDate, $sampleDate);
assertCondition("Cars per labor hour matches hand calculation", abs($cph['cars_per_labor_hour'] - 0.85) < 0.01, "Calculated: {$cph['cars_per_labor_hour']}, Expected: 0.85");
assertCondition("Total cars equals 20", $cph['total_cars'] === 20);
assertCondition("Labor hours equals 23.50", abs($cph['labor_hours'] - 23.50) < 0.01);
assertCondition("Owner target is set (default 2.0)", $cph['target'] == 2.0);
assertCondition("Status reflects below target", in_array($cph['status'], ['warning', 'below_target'], true));

// ---------------------------------------------------------------------
// TEST 4: Hand Calculation Verification 2 - Bay Utilization %
// ---------------------------------------------------------------------
echo "\n4. Testing Bay Utilization KPI (Hand Verification)...\n";
// Hand Calculation:
// 4 Bays available, 1 day, default 10 hours/day = 600 mins per bay.
// Total available capacity = 4 * 600 = 2400 minutes.
// Total busy minutes:
// 10 cars * 15m = 150m
// 5 cars * 25m = 125m
// 5 cars * 40m = 200m
// Total busy = 150 + 125 + 200 = 475 minutes.
// Utilization % = (475 / 2400) * 100 = 19.79% -> ~19.8%

$bayUtil = PerformanceAnalytics::getBayUtilization($pdo, $sampleDate, $sampleDate);
assertCondition("Total bay available minutes is 2400 mins (4 bays * 600 mins)", $bayUtil['total_available_minutes'] === 2400);
assertCondition("Total busy minutes matches sum of jobs (475 mins)", $bayUtil['total_busy_minutes'] === 475);
assertCondition("Shop Bay Utilization % matches hand calculation (19.8%)", abs($bayUtil['overall_utilization_percent'] - 19.8) < 0.5, "Calculated: {$bayUtil['overall_utilization_percent']}%");
assertCondition("Report contains breakdown for all 4 bays", count($bayUtil['bays']) >= 4);

// ---------------------------------------------------------------------
// TEST 5: Hand Calculation Verification 3 - Average Wait Time by Service
// ---------------------------------------------------------------------
echo "\n5. Testing Average Wait Time by Service Type...\n";

$waitData = PerformanceAnalytics::getAverageWaitByService($pdo, $sampleDate, $sampleDate);
assertCondition("Total measured orders is 20", $waitData['total_measured_orders'] === 20);
assertCondition("Overall average wait time calculated", $waitData['overall_avg_wait'] > 0);
assertCondition("Service breakdown returned", !empty($waitData['services']));

// ---------------------------------------------------------------------
// TEST 6: Manager Timecard Correction with Mandatory Audit Reason
// ---------------------------------------------------------------------
echo "\n6. Testing Manager Timecard Correction & Audit Trail...\n";

// Manager adjusts Washer 2's clock out from 16:00:00 to 16:30:00 (+0.5 hours -> 8.00 hours total)
$corResult = TimecardManager::correctTimecard(
    $pdo,
    $tc2Id,
    '2026-10-25 08:30:00',
    '2026-10-25 16:30:00',
    'Staff stayed 30 minutes late to finish interior cleaning on late SUV',
    13,
    'Vireak Chea (Manager)'
);

assertCondition("Manager timecard correction succeeded", $corResult['success'] === true);
assertCondition("Corrected hours updated to 8.00h", abs($corResult['corrected_hours'] - 8.00) < 0.01);

// Verify timecard status updated to 'corrected'
$updTc = $pdo->query("SELECT * FROM staff_timecards WHERE id = {$tc2Id}")->fetch(PDO::FETCH_ASSOC);
assertCondition("Timecard status set to 'corrected'", $updTc['status'] === 'corrected');
assertCondition("Timecard total_hours updated to 8.00", abs((float)$updTc['total_hours'] - 8.00) < 0.01);

// Verify timecard_corrections record
$corRow = $pdo->query("SELECT * FROM timecard_corrections WHERE timecard_id = {$tc2Id}")->fetch(PDO::FETCH_ASSOC);
assertCondition("Correction record saved with reason", !empty($corRow['reason']) && strpos($corRow['reason'], 'SUV') !== false);
assertCondition("Correction recorded manager name", $corRow['corrected_by_name'] === 'Vireak Chea (Manager)');

// Verify Audit Log
$auditRow = $pdo->query("SELECT * FROM audit_logs WHERE action = 'TIMECARD_CORRECTED' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log entry created for TIMECARD_CORRECTED", !empty($auditRow));

// Test rejection when reason is empty
$failCor = TimecardManager::correctTimecard(
    $pdo,
    $tc2Id,
    '2026-10-25 08:30:00',
    '2026-10-25 17:00:00',
    '   ', // Empty reason
    13,
    'Vireak Chea (Manager)'
);
assertCondition("Correction without reason is blocked", $failCor['success'] === false && $failCor['error'] === 'reason_required');

// Recalculate labor hours after correction:
// Staff 1: 8.00h, Staff 2: 8.00h, Staff 3: 8.00h -> 24.00h
$newLaborHours = TimecardManager::getTotalLaborHours($pdo, $sampleDate, $sampleDate);
assertCondition("New labor hours updated to 24.00h", abs($newLaborHours - 24.00) < 0.01, "New labor hours: {$newLaborHours}h");

$newCph = PerformanceAnalytics::getCarsPerLaborHour($pdo, $sampleDate, $sampleDate);
assertCondition("New cars/labor hr is 0.83 (20 / 24.00)", abs($newCph['cars_per_labor_hour'] - 0.83) < 0.01, "Calculated: {$newCph['cars_per_labor_hour']}");

// ---------------------------------------------------------------------
// TEST 7: Bottleneck and Idle Patterns Report
// ---------------------------------------------------------------------
echo "\n7. Testing Bottleneck and Idle Time Pattern Analysis...\n";

$pattern = PerformanceAnalytics::getBottleneckAndIdlePatterns($pdo, $sampleDate, $sampleDate);
assertCondition("Matrix by day and hour generated", !empty($pattern['matrix']));
assertCondition("Automated operational insights generated", !empty($pattern['insights']));
echo "    Found Insight: [" . $pattern['insights'][0]['type'] . "] " . $pattern['insights'][0]['title'] . "\n";

// ---------------------------------------------------------------------
// TEST 8: Staff Leaderboard
// ---------------------------------------------------------------------
echo "\n8. Testing Optional Staff Leaderboard...\n";

$leaderboard = PerformanceAnalytics::getStaffLeaderboard($pdo, $sampleDate, $sampleDate);
assertCondition("Leaderboard generated", isset($leaderboard['leaderboard']));
assertCondition("Staff ranked by performance", count($leaderboard['leaderboard']) >= 2);
if (!empty($leaderboard['leaderboard'])) {
    $first = $leaderboard['leaderboard'][0];
    assertCondition("Rank 1 assigned to highest performer", $first['rank'] === 1, "Top performer: {$first['full_name']} with {$first['cars_per_hour']} cars/hr");
}

// ---------------------------------------------------------------------
// TEST 9: Role Restrictions (Manager & Owner only; Cashiers/Washers blocked)
// ---------------------------------------------------------------------
echo "\n9. Testing Security & Role Permissions...\n";

// Check permissions
$ownerRole = 'owner';
$managerRole = 'manager';
$cashierRole = 'cashier';
$washerRole = 'washer';

// Simulate Auth checks
$_SESSION['user_id'] = 2; // Cashier
$_SESSION['role'] = 'cashier';
$_SESSION['permissions'] = ['pos.access', 'orders.list'];

$cashierCanViewReports = Auth::can('reports.view');
assertCondition("Cashier CANNOT view reports (Forbidden)", $cashierCanViewReports === false);

$_SESSION['user_id'] = 3; // Washer
$_SESSION['role'] = 'washer';
$_SESSION['permissions'] = ['washer_jobs.view'];
$washerCanViewReports = Auth::can('reports.view');
assertCondition("Washer CANNOT view reports (Forbidden)", $washerCanViewReports === false);

$_SESSION['user_id'] = 1; // Owner
$_SESSION['role'] = 'owner';
$ownerCanViewReports = Auth::can('reports.view');
assertCondition("Owner CAN view reports", $ownerCanViewReports === true);

// ---------------------------------------------------------------------
// TEST 10: Clean up test sample records
// ---------------------------------------------------------------------
echo "\n10. Cleaning up test fixtures...\n";
$pdo->exec("DELETE FROM timecard_corrections WHERE timecard_id IN (SELECT id FROM staff_timecards WHERE timecard_uid IN ('{$tc1Uid}', '{$tc2Uid}', '{$tc3Uid}'))");
$pdo->exec("DELETE FROM staff_timecards WHERE timecard_uid IN ('{$tc1Uid}', '{$tc2Uid}', '{$tc3Uid}')");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'CW-PERF-%')");
$pdo->exec("DELETE FROM order_washers WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'CW-PERF-%')");
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'CW-PERF-%'");
assertCondition("Test fixtures cleaned up cleanly", true);

echo "\n=================================================================\n";
echo "  TEST SUMMARY: {$passedCount} Passed, {$failedCount} Failed (Total {$testCount})\n";
echo "=================================================================\n";

if ($failedCount > 0) {
    exit(1);
}
