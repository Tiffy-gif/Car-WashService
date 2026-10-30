<?php
/**
 * Test Suite: Shop Server Preparation, Backup, Disaster Recovery & Security
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Backup.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

$totalTests = 0;
$passedTests = 0;

function assertTest(bool $condition, string $message) {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "[PASS] {$message}\n";
    } else {
        echo "[FAIL] {$message}\n";
    }
}

echo "=== 1. DAILY AUTOMATIC BACKUP & 14-DAY ROTATION ===\n";

$backupDir = dirname(__DIR__) . '/storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Test daily backup generation
$backupRes = Backup::runDailyBackup($pdo, $backupDir, 14);
$backupPath = $backupRes['file_path'];
assertTest($backupRes['success'] === true && file_exists($backupPath), "Backup file created successfully at {$backupPath}");
assertTest(filesize($backupPath) > 50000, "Backup file size is healthy (>50KB, actual: " . round(filesize($backupPath)/1024) . " KB)");

$backupContent = file_get_contents($backupPath, false, null, 0, 2000);
assertTest(strpos($backupContent, 'CREATE TABLE') !== false, "Backup file contains SQL schema definition");

// Test 14-day retention rotation
$oldTestFile = $backupDir . '/CarWash_AutoBackup_2025-01-01.sql';
file_put_contents($oldTestFile, "-- Dummy old backup for retention test");
touch($oldTestFile, time() - (20 * 86400));
$pruned = Backup::rotateBackups($backupDir, 14);
assertTest(in_array('CarWash_AutoBackup_2025-01-01.sql', $pruned), "14-day retention pruned old backup: CarWash_AutoBackup_2025-01-01.sql");
assertTest(!file_exists($oldTestFile), "Old backup was actually removed from disk");

$available = Backup::getAvailableBackups($backupDir);
assertTest(count($available) > 0, "Available backups list returns at least 1 file");
assertTest(isset($available[0]['filename']) && isset($available[0]['size_formatted']), "Backup list has filename and formatted size");

echo "\n=== 2. DATABASE RESTORATION ===\n";

// Test restore capability with a temporary test table
$testTableSql = "-- Car Wash Service Pro Backup Dump\n" .
                "SET FOREIGN_KEY_CHECKS=0;\n" .
                "DROP TABLE IF EXISTS `__test_restore_table`;\n" .
                "CREATE TABLE `__test_restore_table` (`id` int(11) NOT NULL AUTO_INCREMENT, `val` varchar(50) DEFAULT NULL, PRIMARY KEY (`id`));\n" .
                "INSERT INTO `__test_restore_table` (`val`) VALUES ('restored_value_123');";

$restoreResult = Backup::restoreFromSql($pdo, $testTableSql);
assertTest($restoreResult['success'] === true, "Backup::restoreFromSql executed test schema restore successfully");

$stmt = $pdo->query("SELECT `val` FROM `__test_restore_table` WHERE id = 1");
$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
assertTest($row && $row['val'] === 'restored_value_123', "Restored test table contains exact expected data");

// Cleanup test table
$pdo->exec("DROP TABLE IF EXISTS `__test_restore_table`");

echo "\n=== 3. DISASTER MODE & PAPER FORMS ===\n";

// Check printable paper forms view exists
$paperFormsFile = dirname(__DIR__) . '/views/recovery/paper_forms.php';
assertTest(file_exists($paperFormsFile), "Printable paper forms view exists (views/recovery/paper_forms.php)");
$paperFormsContent = file_get_contents($paperFormsFile);
assertTest(strpos($paperFormsContent, 'EMERGENCY JOB CARD') !== false, "Paper forms contains Emergency Job Card template");
assertTest(strpos($paperFormsContent, 'RECEPTION LOGBOOK SHEET') !== false, "Paper forms contains Reception Logbook Sheet template");
assertTest(strpos($paperFormsContent, '@media print') !== false, "Paper forms has print CSS styling (@media print)");

// Check bulk recovery entry view exists
$bulkEntryFile = dirname(__DIR__) . '/views/recovery/bulk_entry.php';
assertTest(file_exists($bulkEntryFile), "Bulk recovery entry view exists (views/recovery/bulk_entry.php)");
$bulkEntryContent = file_get_contents($bulkEntryFile);
assertTest(strpos($bulkEntryContent, 'entered after recovery') !== false, "Bulk entry view sets 'entered after recovery' flag");
assertTest(strpos($bulkEntryContent, 'paper_slip_number') !== false, "Bulk entry view captures paper slip numbers");

// Test bulk recovery record insertion into database
// Check orders table columns
$colsStmt = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'is_recovered'");
assertTest($colsStmt->rowCount() > 0, "orders table has column 'is_recovered'");

$colsSlip = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'paper_slip_number'");
assertTest($colsSlip->rowCount() > 0, "orders table has column 'paper_slip_number'");

$colsNotes = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'recovery_notes'");
assertTest($colsNotes->rowCount() > 0, "orders table has column 'recovery_notes'");

// Simulate inserting a recovered order record
$testOrderNum = 'REC-TEST-' . time();
$slipNum = 'SLIP-999';
$insStmt = $pdo->prepare("
    INSERT INTO orders (
        order_number, total_usd, total_khr,
        payment_method, payment_status, status, is_recovered,
        paper_slip_number, recovered_by_id, recovered_by_name,
        recovered_at, recovery_notes, created_at, arrived_at, finished_at, paid_at
    ) VALUES (
        :num, 15.00, 61500,
        'cash', 'paid', 'completed', 1,
        :slip, 1, 'Manager Panha',
        NOW(), 'entered after recovery (Test run)', '2026-10-28 09:30:00',
        '2026-10-28 09:30:00', '2026-10-28 09:50:00', '2026-10-28 09:50:00'
    )
");
$insRes = $insStmt->execute([
    ':num' => $testOrderNum,
    ':slip' => $slipNum
]);
assertTest($insRes, "Successfully inserted test paper-recovery order record");

$fetchStmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num");
$fetchStmt->execute([':num' => $testOrderNum]);
$recOrder = $fetchStmt->fetch(PDO::FETCH_ASSOC);

assertTest($recOrder && (int)$recOrder['is_recovered'] === 1, "Order is marked as is_recovered = 1");
assertTest($recOrder['paper_slip_number'] === $slipNum, "Order has paper slip number SLIP-999");
assertTest($recOrder['recovered_by_name'] === 'Manager Panha', "Order records who recovered it");
assertTest(strpos($recOrder['recovery_notes'], 'entered after recovery') !== false, "Order contains 'entered after recovery' note");

// Clean up test order
$pdo->prepare("DELETE FROM orders WHERE order_number = :num")->execute([':num' => $testOrderNum]);

echo "\n=== 4. HEARTBEAT & MANUAL MODE RECEIPT ===\n";

$appJsFile = dirname(__DIR__) . '/public/js/app.js';
assertTest(file_exists($appJsFile), "public/js/app.js exists");
$appJsContent = file_get_contents($appJsFile);
assertTest(strpos($appJsContent, 'Offline - Use Manual Mode') !== false, "app.js contains yellow 'Offline - Use Manual Mode' banner");
assertTest(strpos($appJsContent, '60000') !== false || strpos($appJsContent, 'missedHeartbeats') !== false, "app.js enforces 60-second offline threshold");

$manualReceiptFile = dirname(__DIR__) . '/views/orders/manual_receipt.php';
assertTest(file_exists($manualReceiptFile), "views/orders/manual_receipt.php exists");
$manualReceiptContent = file_get_contents($manualReceiptFile);
assertTest(strpos($manualReceiptContent, 'Manual Mode Receipt') !== false || strpos($manualReceiptContent, 'MANUAL RECEIPT') !== false, "Manual receipt view contains manual mode title");
assertTest(strpos($manualReceiptContent, 'Save as PDF') !== false || strpos($manualReceiptContent, 'window.print') !== false, "Manual receipt has print/PDF trigger");

echo "\n=== 5. SAFETY CHECKS: COOKIES, DIRECTORY PRIVACY & LOCAL ASSETS ===\n";

// Session cookie configuration in Auth.php
$authFile = dirname(__DIR__) . '/core/Auth.php';
$authContent = file_get_contents($authFile);
assertTest(strpos($authContent, "'samesite' => 'Strict'") !== false, "Auth.php enforces SameSite=Strict cookies");
assertTest(strpos($authContent, "'httponly' => true") !== false, "Auth.php enforces HttpOnly cookies");
assertTest(strpos($authContent, "'session.use_only_cookies', '1'") !== false, "Auth.php enforces use_only_cookies");

// .htaccess protection
$htaccessFile = dirname(__DIR__) . '/.htaccess';
assertTest(file_exists($htaccessFile), "Root .htaccess file exists");
$htaccessContent = file_get_contents($htaccessFile);
assertTest(strpos($htaccessContent, 'Options -Indexes') !== false, ".htaccess disables directory browsing (-Indexes)");
assertTest(strpos($htaccessContent, 'test_') !== false, ".htaccess blocks direct web access to test files (test_*.php)");
assertTest(strpos($htaccessContent, 'RewriteRule ^(config|core|database|storage|tests|docs|bin|scripts)/') !== false, ".htaccess blocks private system directories");

// Verify no external CDNs or external fonts in critical pages
$filesToCheck = [
    'tv.php',
    'views/orders/receipt.php',
    'views/recovery/paper_forms.php',
    'views/recovery/bulk_entry.php',
    'views/orders/manual_receipt.php',
    'public/css/style.css'
];

$hasExternal = false;
foreach ($filesToCheck as $f) {
    $path = dirname(__DIR__) . '/' . $f;
    if (file_exists($path)) {
        $c = file_get_contents($path);
        if (preg_match('/https?:\/\/(cdnjs\.cloudflare\.com|fonts\.googleapis\.com|cdn\.jsdelivr\.net)/i', $c, $m)) {
            $hasExternal = true;
            echo "[WARN] Found external reference {$m[0]} in {$f}\n";
        }
    }
}
assertTest(!$hasExternal, "No external CDNs (Cloudflare, Google Fonts, jsDelivr) used in core views/css");

echo "\n=== 6. INSTALLATION GUIDE & SERVICES SCRIPT ===\n";

$guideFile = dirname(__DIR__) . '/docs/MINI_PC_INSTALLATION_GUIDE.md';
assertTest(file_exists($guideFile), "docs/MINI_PC_INSTALLATION_GUIDE.md exists");
$guideContent = file_get_contents($guideFile);
assertTest(strpos($guideContent, 'NSSM') !== false, "Guide explains NSSM auto-restarting services for power cuts");
assertTest(strpos($guideContent, '3307') !== false || strpos($guideContent, 'port') !== false, "Guide covers changing default database port");
assertTest(strpos($guideContent, 'phpMyAdmin') !== false, "Guide covers locking down phpMyAdmin");
assertTest(strpos($guideContent, 'Guest Wi-Fi') !== false || strpos($guideContent, 'VLAN') !== false, "Guide covers isolating Guest Wi-Fi");

$batFile = dirname(__DIR__) . '/bin/backup_daily.bat';
assertTest(file_exists($batFile), "bin/backup_daily.bat exists for Windows Task Scheduler at 2:00 AM");

echo "\n==========================================\n";
echo "SUMMARY: Passed {$passedTests} / {$totalTests} tests.\n";
if ($passedTests === $totalTests) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
