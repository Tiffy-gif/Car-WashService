<?php
declare(strict_types=1);

// Force test database
define('CARWASH_DB_NAME', 'carwash_db_test');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Language.php';
require_once __DIR__ . '/../core/Helpers.php';

// Simulate authenticated Owner session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'owner';
$_SESSION['role'] = 'owner';
$_SESSION['user_name'] = 'Test Owner';
$_SESSION['permissions'] = ['reports.view', 'reports.export', 'cashier.manage'];

$_GET['view'] = 'cashier_sessions';
$_GET['period'] = 'all';

ob_start();
try {
    include __DIR__ . '/../views/owner/cashier_sessions.php';
    $output = ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    echo "FAIL: Exception thrown during rendering: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

$checks = [
    'Accordion Container' => str_contains($output, 'id="sessionsAccordionContainer"') || str_contains($output, 'no_shifts_found'),
    'Shift Accordion Cards' => str_contains($output, 'class="shift-accordion-card card"'),
    'Shift Accordion Header' => str_contains($output, 'class="shift-accordion-header"'),
    'Chevron Toggle' => str_contains($output, 'shift-chevron'),
    'Drawer Balances Box 1' => str_contains($output, '1. Drawer Cash Balances'),
    'Reconciliation Box 2' => str_contains($output, '2. Reconciliation &amp; Variance'),
    'Audit Actions Box 3' => str_contains($output, '3. Handover &amp; Audit Actions'),
    'Expand All Shifts Button' => str_contains($output, 'expandAllShifts(true)'),
    'Collapse All Button' => str_contains($output, 'expandAllShifts(false)'),
    'Slip Modal Present' => str_contains($output, 'id="slipModal"'),
    'Toast Notification Present' => str_contains($output, 'id="toastNotification"'),
    'JS toggleShiftAccordion' => str_contains($output, 'function toggleShiftAccordion(key)'),
    'JS copyToClipboard' => str_contains($output, 'function copyToClipboard(text, label)'),
    'JS showToast' => str_contains($output, 'function showToast(msg)')
];

$failed = 0;
echo "--- CASHIER SESSIONS ACCORDION TEST REPORT ---\n";
foreach ($checks as $name => $passed) {
    if ($passed) {
        echo "[PASS] $name\n";
    } else {
        echo "[FAIL] $name\n";
        $failed++;
    }
}

if ($failed === 0) {
    echo "\nALL 14/14 CHECKS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "\n$failed CHECKS FAILED!\n";
    exit(1);
}
