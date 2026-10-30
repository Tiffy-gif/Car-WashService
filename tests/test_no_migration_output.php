<?php
declare(strict_types=1);

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
echo "  CAR WASH BACK OFFICE: ZERO MIGRATION OUTPUT & FAST SETUP TEST   \n";
echo "=================================================================\n\n";

// ---------------------------------------------------------------------
// TEST 1: Requiring database/init.php produces ZERO output
// ---------------------------------------------------------------------
echo "1. Testing that loading database/init.php produces zero output...\n";

// Clear output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

ob_start();
require __DIR__ . '/../database/init.php';
$initOutput = ob_get_clean();

assertCondition("database/init.php produces exactly 0 characters of output", $initOutput === '', "Output length: " . strlen($initOutput));
if ($initOutput !== '') {
    echo "    Unexpected output was:\n" . substr($initOutput, 0, 300) . "\n";
}

// ---------------------------------------------------------------------
// TEST 2: Schema migrations table has all steps recorded
// ---------------------------------------------------------------------
echo "\n2. Verifying schema_migrations history table...\n";
$pdo = Database::getInstance();
$executed = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);

$requiredSteps = [
    'init_database',
    'base_migrations',
    'migrate_categories',
    'migrate_loyalty',
    'migrate_roles',
    'pos_device_sales_and_closings',
    'migrate_bays_queue',
    'migrate_foundation',
    'migrate_cashier_controls',
    'migrate_bank_stock_controls'
];

foreach ($requiredSteps as $step) {
    assertCondition("Migration step '{$step}' recorded in schema_migrations", in_array($step, $executed, true));
}

// ---------------------------------------------------------------------
// TEST 3: Web Page Rendering as OWNER (Dashboard, Orders, Customers, History, Bays, POS)
// ---------------------------------------------------------------------
echo "\n3. Testing Web Page Rendering as OWNER (No migration text at top)...\n";

$ownerUser = $pdo->query("SELECT * FROM users WHERE role = 'owner' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function simulatePageView(string $view, array $user): string {
    $script = dirname(__DIR__) . '/tests/runner_sim.php';
    $uJson = base64_encode(json_encode($user));
    $output = shell_exec("php " . escapeshellarg($script) . " " . escapeshellarg($view) . " " . escapeshellarg($uJson)) ?? '';
    return $output;
}

$viewsToTest = [
    'dashboard'       => 'Dashboard',
    'orders'          => 'Orders',
    'customers'       => 'Customers',
    'mechanic_jobs'   => 'Vehicle Repair History',
    'bays_queue'      => 'Bays & Queue',
    'pos'             => 'POS'
];

foreach ($viewsToTest as $viewKey => $viewName) {
    $html = simulatePageView($viewKey, $ownerUser);
    
    // Check for bad plain text
    $hasMigrationText = (
        stripos($html, 'Running Bank Transfer Approval') !== false ||
        stripos($html, 'Bank Transfer Approval & Stock Control Migration completed') !== false ||
        stripos($html, 'Running Foundation migration') !== false ||
        stripos($html, 'Starting Bays & Queue Migration') !== false ||
        stripos($html, 'Running Cashier & Money Controls migration') !== false ||
        stripos($html, 'Migration completed successfully') !== false
    );

    assertCondition("Owner -> {$viewName}: No migration text appears", !$hasMigrationText);
    
    // Check that HTML starts cleanly with <!DOCTYPE or <html
    $trimmed = ltrim($html);
    $startsCleanly = (str_starts_with($trimmed, '<!DOCTYPE') || str_starts_with($trimmed, '<html') || str_starts_with($trimmed, '<'));
    assertCondition("Owner -> {$viewName}: Page starts cleanly with HTML tag", $startsCleanly);
}

// ---------------------------------------------------------------------
// TEST 4: Web Page Rendering as CASHIER (No migration text, no admin warnings)
// ---------------------------------------------------------------------
echo "\n4. Testing Web Page Rendering as CASHIER (No migration text)...\n";

$cashierUser = $pdo->query("SELECT * FROM users WHERE role = 'cashier' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$cashierViews = [
    'pos'        => 'POS',
    'orders'     => 'Orders',
    'bays_queue' => 'Bays & Queue'
];

foreach ($cashierViews as $viewKey => $viewName) {
    $html = simulatePageView($viewKey, $cashierUser);
    
    $hasMigrationText = (
        stripos($html, 'Running Bank Transfer Approval') !== false ||
        stripos($html, 'Bank Transfer Approval & Stock Control Migration completed') !== false ||
        stripos($html, 'Running Foundation migration') !== false ||
        stripos($html, 'Starting Bays & Queue Migration') !== false ||
        stripos($html, 'Running Cashier & Money Controls migration') !== false ||
        stripos($html, 'Migration completed successfully') !== false
    );

    assertCondition("Cashier -> {$viewName}: No migration text appears", !$hasMigrationText);
    
    $trimmed = ltrim($html);
    $startsCleanly = (str_starts_with($trimmed, '<!DOCTYPE') || str_starts_with($trimmed, '<html') || str_starts_with($trimmed, '<'));
    assertCondition("Cashier -> {$viewName}: Page starts cleanly with HTML tag", $startsCleanly);
}

// ---------------------------------------------------------------------
// TEST 5: Owner Settings Failure Notification & Visitor/Cashier Isolation
// ---------------------------------------------------------------------
echo "\n5. Testing Migration Failure Handling in Settings...\n";

// Set a test migration error in settings
$pdo->prepare("
    INSERT INTO settings (setting_key, setting_value) VALUES ('migration_last_error', 'Sample test error: Connection timed out')
    ON DUPLICATE KEY UPDATE setting_value = 'Sample test error: Connection timed out'
")->execute();

// Owner visits Settings -> Must see clear warning banner
$ownerSettingsHtml = simulatePageView('settings', $ownerUser);
$ownerSeesWarning = (stripos($ownerSettingsHtml, 'Database Migration Warning (Owner Notice)') !== false);
assertCondition("Owner in Settings sees clear Database Migration Warning banner", $ownerSeesWarning);

// Cashier visits POS or Orders -> Must see NOTHING about the warning
$cashierPosHtml = simulatePageView('pos', $cashierUser);
$cashierSeesWarning = (stripos($cashierPosHtml, 'Database Migration Warning') !== false || stripos($cashierPosHtml, 'Sample test error') !== false);
assertCondition("Cashier sees NOTHING about the migration error", !$cashierSeesWarning);

// Clean up test error by running runMigrations(true)
require_once __DIR__ . '/../database/migrate.php';
$runRes = runMigrations(true);
assertCondition("runMigrations(true) succeeds and clears error", $runRes['success'] === true);

$errInDb = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'migration_last_error'")->fetchColumn();
assertCondition("migration_last_error is cleared from settings after successful run", empty($errInDb));

// ---------------------------------------------------------------------
// TEST 6: Migration Log file records actions
// ---------------------------------------------------------------------
echo "\n6. Verifying storage/logs/migration.log...\n";
$logFile = dirname(__DIR__) . '/storage/logs/migration.log';
assertCondition("Migration log file exists", file_exists($logFile));
$logContents = file_get_contents($logFile);
assertCondition("Migration log contains timestamps and entries", strpos($logContents, '[INFO]') !== false || strpos($logContents, 'All migrations applied successfully') !== false);

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n=================================================================\n";
echo "  TEST SUMMARY: {$passedCount} Passed, {$failedCount} Failed (Total {$testCount})\n";
echo "=================================================================\n";

if ($failedCount > 0) {
    exit(1);
}
exit(0);
