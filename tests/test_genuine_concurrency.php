<?php
/**
 * Genuine Multi-Process Concurrency & Race Condition Verification
 * 
 * Verifies real simultaneous execution using separate OS processes (proc_open)
 * and separate database connections on an isolated temporary SQLite database file.
 */

declare(strict_types=1);

$tempDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_concurrency_' . uniqid() . '.sqlite';
echo "=================================================================\n";
echo "GENUINE MULTI-PROCESS CONCURRENCY TEST\n";
echo "Isolated Temp DB: {$tempDb}\n";
echo "PHP Binary: " . PHP_BINARY . "\n";
echo "=================================================================\n\n";

// Initialize base schema on temp database
$pdo = new PDO('sqlite:' . $tempDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA journal_mode = WAL;");
$pdo->exec("PRAGMA busy_timeout = 5000;");

// Polyfill functions
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        effective_role TEXT NOT NULL,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        name_kh TEXT DEFAULT '',
        category TEXT DEFAULT 'wash',
        price_usd REAL DEFAULT 5.00,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        category TEXT DEFAULT 'general',
        price_usd REAL DEFAULT 0
    );

    CREATE TABLE bays (
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

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL,
        order_uid TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        customer_id INTEGER DEFAULT NULL,
        customer_name TEXT DEFAULT NULL,
        customer_phone TEXT DEFAULT NULL,
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_items (
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

    CREATE TABLE order_washers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        washer_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE order_mechanics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        mechanic_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE order_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        task_type TEXT NOT NULL,
        title TEXT NOT NULL,
        sequence INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'waiting',
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT NULL,
        started_at TEXT DEFAULT NULL,
        completed_at TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE bay_history (
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

    CREATE TABLE inspection_checklists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        item_name TEXT NOT NULL,
        is_mandatory INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'pass',
        photo_url TEXT DEFAULT 'uploads/inspections/sample.jpg',
        notes TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE service_duration_reviews (
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

    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        role TEXT DEFAULT NULL,
        title TEXT,
        message TEXT,
        type TEXT DEFAULT 'info',
        is_read INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    INSERT INTO users (id, username, full_name, role, effective_role) VALUES
    (1, 'cashier1', 'Cashier Dara', 'cashier', 'cashier'),
    (2, 'washer1', 'Washer Sopheap', 'washer', 'washer'),
    (3, 'tech1', 'Tech Bunthoeun', 'mechanic', 'mechanic'),
    (4, 'owner1', 'Owner Panha', 'owner', 'owner');

    INSERT INTO services (id, name_en, category, price_usd) VALUES
    (1, 'Full Exterior Wash', 'wash', 5.00),
    (2, 'Oil Change & Filter', 'maintenance', 25.00);

    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, sort_order) VALUES
    (1, 1, 'Bay 1', 'wash', 'wash_only', 'free', 1),
    (2, 2, 'Bay 2', 'wash', 'wash_only', 'free', 2),
    (3, 3, 'Bay 3', 'wash', 'wash_only', 'free', 3),
    (4, 4, 'Bay 4 (Lift)', 'oil_change', 'oil_only', 'free', 4);
");

$passedCount = 0;
$failedCount = 0;

function assertCheck(bool $condition, string $label): void {
    global $passedCount, $failedCount;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        $passedCount++;
    } else {
        echo "  [FAIL] {$label}\n";
        $failedCount++;
    }
}

// -------------------------------------------------------------
// HELPER: Run child PHP script with proc_open
// -------------------------------------------------------------
function spawnChildWorker(string $scriptCode, array &$processPointers): array {
    $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worker_' . uniqid() . '.php';
    file_put_contents($tempScript, $scriptCode);
    
    $descriptors = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"], // stderr
    ];
    
    $cmd = PHP_BINARY . " " . escapeshellarg($tempScript);
    $proc = proc_open($cmd, $descriptors, $pipes);
    
    $processPointers[] = [
        'proc' => $proc,
        'pipes' => $pipes,
        'script' => $tempScript
    ];
    
    return [
        'proc' => $proc,
        'pipes' => $pipes,
        'script' => $tempScript
    ];
}

function waitAndCollect(array &$workers): array {
    $results = [];
    foreach ($workers as $w) {
        fclose($w['pipes'][0]);
        $stdout = stream_get_contents($w['pipes'][1]);
        fclose($w['pipes'][1]);
        $stderr = stream_get_contents($w['pipes'][2]);
        fclose($w['pipes'][2]);
        $exitCode = proc_close($w['proc']);
        @unlink($w['script']);
        
        $results[] = [
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr)
        ];
    }
    $workers = [];
    return $results;
}

// =================================================================
// TEST 1: TWO PROCESSES SIMULTANEOUSLY CREATING TASKS FOR SAME ORDER
// =================================================================
echo "--- TEST 1: SIMULTANEOUS TASK CREATION BY TWO PROCESSES ---\n";

$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status) VALUES (100, 'ORD-SIM-100', '2BC-100', 'waiting')");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (100, 1, 'Full Exterior Wash', 'service')");

$workerCode = <<<PHP
<?php
require_once 'C:/xampp/htdocs/Car-WashService/core/Database.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/BayManager.php';

\$pdo = new PDO('sqlite:{$tempDb}');
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec("PRAGMA busy_timeout = 5000;");
\$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));

try {
    \$tasks = BayManager::ensureOrderTasks(\$pdo, 100);
    echo json_encode(['success' => true, 'task_count' => count(\$tasks)]);
} catch (Throwable \$e) {
    echo json_encode(['success' => false, 'error' => \$e->getMessage()]);
}
PHP;

$workers = [];
spawnChildWorker($workerCode, $workers);
spawnChildWorker($workerCode, $workers);

$outputs = waitAndCollect($workers);
$resA = json_decode($outputs[0]['stdout'], true);
$resB = json_decode($outputs[1]['stdout'], true);

assertCheck(!empty($resA['success']), "Worker A executed successfully (Output: " . $outputs[0]['stdout'] . ")");
assertCheck(!empty($resB['success']), "Worker B executed successfully (Output: " . $outputs[1]['stdout'] . ")");

$totalTasksInDb = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = 100")->fetchColumn();
assertCheck($totalTasksInDb === 1, "Simultaneous execution produced exactly 1 task record in DB (Found: {$totalTasksInDb})");

// =================================================================
// TEST 2: CONCURRENT TASK CREATION WITH EXISTING TASKS
// =================================================================
echo "\n--- TEST 2: CONCURRENT TASK CREATION WITH EXISTING TASKS ---\n";

$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status) VALUES (101, 'ORD-SIM-101', '2BC-101', 'waiting')");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (101, 1, 'Full Exterior Wash', 'service')");
$pdo->exec("INSERT INTO order_tasks (order_id, task_type, title, sequence, status) VALUES (101, 'wash', 'Full Exterior Wash', 1, 'waiting')");

$workerCode2 = <<<PHP
<?php
require_once 'C:/xampp/htdocs/Car-WashService/core/Database.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/BayManager.php';

\$pdo = new PDO('sqlite:{$tempDb}');
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec("PRAGMA busy_timeout = 5000;");
\$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));

try {
    \$tasks = BayManager::ensureOrderTasks(\$pdo, 101);
    echo json_encode(['success' => true, 'task_count' => count(\$tasks)]);
} catch (Throwable \$e) {
    echo json_encode(['success' => false, 'error' => \$e->getMessage()]);
}
PHP;

$workers = [];
spawnChildWorker($workerCode2, $workers);
spawnChildWorker($workerCode2, $workers);

$outputs2 = waitAndCollect($workers);
$res2A = json_decode($outputs2[0]['stdout'], true);
$res2B = json_decode($outputs2[1]['stdout'], true);

assertCheck(!empty($res2A['success']) && $res2A['task_count'] === 1, "Worker A saw existing task without modification");
assertCheck(!empty($res2B['success']) && $res2B['task_count'] === 1, "Worker B saw existing task without modification");

$count101 = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = 101")->fetchColumn();
assertCheck($count101 === 1, "Existing tasks remained strictly 1 record (Found: {$count101})");

// =================================================================
// TEST 3: CONCURRENT BAY COMPLETION ATTEMPTS (RACE CONDITION ON FINISH)
// =================================================================
echo "\n--- TEST 3: CONCURRENT BAY COMPLETION ATTEMPTS ---\n";

$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, bay_id) VALUES (200, 'ORD-SIM-200', '2BC-200', 'in_progress', 2)");
$pdo->exec("UPDATE bays SET status = 'busy', current_order_id = 200, occupied_at = CURRENT_TIMESTAMP WHERE id = 2");
$pdo->exec("INSERT INTO order_tasks (order_id, task_type, title, sequence, status) VALUES (200, 'wash', 'Full Exterior Wash', 1, 'in_progress')");

$finishWorkerCode = <<<PHP
<?php
require_once 'C:/xampp/htdocs/Car-WashService/core/Database.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/BayManager.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/NotificationManager.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/AuditLogger.php';

\$pdo = new PDO('sqlite:{$tempDb}');
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec("PRAGMA busy_timeout = 5000;");
\$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));

\$res = BayManager::finishCar(\$pdo, 2, 200, 2, 'Washer Sopheap', 'washer', 0);
echo json_encode(\$res);
PHP;

$workers = [];
spawnChildWorker($finishWorkerCode, $workers);
spawnChildWorker($finishWorkerCode, $workers);

$outputs3 = waitAndCollect($workers);
$res3A = json_decode($outputs3[0]['stdout'], true);
$res3B = json_decode($outputs3[1]['stdout'], true);

$successes = 0;
$conflicts = 0;

if (!empty($res3A['success'])) $successes++; else $conflicts++;
if (!empty($res3B['success'])) $successes++; else $conflicts++;

assertCheck($successes === 1, "Exactly ONE worker succeeded in finishing the car (Successes: {$successes})");
assertCheck($conflicts === 1, "Compensating worker was safely rejected with conflict (Conflicts: {$conflicts})");

$bay2 = $pdo->query("SELECT * FROM bays WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay2['status'] === 'free' && empty($bay2['current_order_id']), "Bay 2 is cleanly free and has no occupant");

// =================================================================
// TEST 4: CONFLICTING BAY REASSIGNMENT WHILE OCCUPIED
// =================================================================
echo "\n--- TEST 4: CONFLICTING BAY REASSIGNMENT ---\n";

$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, bay_id) VALUES (300, 'ORD-SIM-300', '2BC-300', 'in_progress', 3)");
$pdo->exec("UPDATE bays SET status = 'busy', current_order_id = 300, occupied_at = CURRENT_TIMESTAMP WHERE id = 3");
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (301, 'ORD-SIM-301', '2BC-301', 'waiting', 'paid', CURRENT_TIMESTAMP)");

require_once 'C:/xampp/htdocs/Car-WashService/core/BayManager.php';
$assignRes = BayManager::assignCarToBay($pdo, 3, 301, 1, 'Cashier User', 'cashier');

assertCheck($assignRes['success'] === false, "Reassignment attempt to occupied bay was rejected");
assertCheck($assignRes['error'] === 'BAY_ALREADY_TAKEN', "Error code is strictly BAY_ALREADY_TAKEN (Got: " . ($assignRes['error'] ?? '') . ")");

$bay3 = $pdo->query("SELECT * FROM bays WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
assertCheck((int)$bay3['current_order_id'] === 300, "Bay 3 remains exclusively occupied by original Order 300");

// =================================================================
// TEST 5: ROLLBACK AFTER A FAILED CONDITIONAL UPDATE
// =================================================================
echo "\n--- TEST 5: ROLLBACK AFTER FAILED CONDITIONAL UPDATE ---\n";

// Attempt finish with mismatched order ID
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, bay_id) VALUES (400, 'ORD-SIM-400', '2BC-400', 'in_progress', 1)");
$pdo->exec("UPDATE bays SET status = 'busy', current_order_id = 400, occupied_at = CURRENT_TIMESTAMP WHERE id = 1");

$mismatchFinish = BayManager::finishCar($pdo, 1, 999, 2, 'Washer Sopheap', 'washer');
assertCheck($mismatchFinish['success'] === false, "Mismatched finish failed as expected");
assertCheck($mismatchFinish['error'] === 'ORDER_NOT_FOUND', "Error code is ORDER_NOT_FOUND");

$bay1 = $pdo->query("SELECT * FROM bays WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assertCheck((int)$bay1['current_order_id'] === 400, "Bay 1 state was NOT rolled over or modified");

// =================================================================
// TEST 6: NOTIFICATION DISPATCH ONLY AFTER SUCCESSFUL COMMIT
// =================================================================
echo "\n--- TEST 6: NOTIFICATION DISPATCH ONLY AFTER SUCCESSFUL COMMIT ---\n";

$notifCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();

// Execute a rejected finish (e.g. mandatory inspection incomplete on Bay 4)
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, bay_id, repair_status) VALUES (500, 'ORD-SIM-500', '2BC-500', 'in_progress', 4, 'in_progress')");
$pdo->exec("UPDATE bays SET status = 'busy', current_order_id = 500 WHERE id = 4");
$pdo->exec("INSERT INTO inspection_checklists (order_id, item_name, is_mandatory, status, photo_url) VALUES (500, 'Brake Fluid', 1, 'fail', '')");

$failFinish = BayManager::finishCar($pdo, 4, 500, 3, 'Tech Bunthoeun', 'mechanic');
assertCheck($failFinish['success'] === false, "Inspection incomplete finish rejected");

$notifCountAfterFail = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
assertCheck($notifCountAfterFail === $notifCountBefore, "ZERO notifications were dispatched on rejected finish");

// =================================================================
// TEST 7: SQLITE BOUNDED RETRY & TRANSACTION OWNERSHIP LIFECYCLE
// =================================================================
echo "\n--- TEST 7: SQLITE BOUNDED RETRY & TRANSACTION OWNERSHIP LIFECYCLE ---\n";

// 7.1 Preservation of existing outer transaction
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status) VALUES (600, 'ORD-SIM-600', '2BC-600', 'waiting')");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (600, 1, 'Full Exterior Wash', 'service')");

$pdo->beginTransaction();
$tasksInsideOuter = BayManager::ensureOrderTasks($pdo, 600);
assertCheck($pdo->inTransaction() === true, "Outer transaction strictly preserved (still active in caller)");
assertCheck(count($tasksInsideOuter) === 1, "Tasks created inside outer transaction successfully");
$pdo->commit();
assertCheck($pdo->inTransaction() === false, "Caller transaction committed cleanly");

// 7.2 When another process creates tasks during retry window
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status) VALUES (601, 'ORD-SIM-601', '2BC-601', 'waiting')");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (601, 1, 'Full Exterior Wash', 'service')");

// Pre-create tasks manually to simulate competitor committing right before/during ensureOrderTasks
$pdo->exec("INSERT INTO order_tasks (order_id, task_type, title, sequence, status) VALUES (601, 'wash', 'Full Exterior Wash', 1, 'waiting')");
$recheckTasks = BayManager::ensureOrderTasks($pdo, 601);
assertCheck(count($recheckTasks) === 1, "Detects competitor committed tasks and returns without duplicate insert");
$count601 = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = 601")->fetchColumn();
assertCheck($count601 === 1, "Total tasks in DB for Order 601 remains exactly 1");

// 7.3 Bounded retry failure reporting (persistent database is locked)
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status) VALUES (602, 'ORD-SIM-602', '2BC-602', 'waiting')");
$pdoMockFail = new class('sqlite:' . $tempDb) extends PDO {
    public function beginTransaction(): bool {
        throw new PDOException("SQLSTATE[HY000]: General error: 5 database is locked");
    }
};
$failedAsExpected = false;
try {
    BayManager::ensureOrderTasks($pdoMockFail, 602);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'database is locked')) {
        $failedAsExpected = true;
    }
}
assertCheck($failedAsExpected === true, "Persistent lock throws exception after exhausting bounded retries (not swallowed)");

// Clean up temp DB
unset($pdo);
unset($pdoMockFail);
@unlink($tempDb);
@unlink($tempDb . '-wal');
@unlink($tempDb . '-shm');

echo "\n=================================================================\n";
echo "CONCURRENCY TEST SUMMARY: {$passedCount} PASSED, {$failedCount} FAILED\n";
echo "=================================================================\n";

exit($failedCount > 0 ? 1 : 0);
