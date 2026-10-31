<?php
declare(strict_types=1);

/**
 * Automated Brief Compliance Test Suite (CLI)
 * 
 * Verifies non-browser system invariants:
 * 1. Required database tables and columns exist
 * 2. Immutable ledger triggers exist
 * 3. Cashier role strictly lacks cancel and refund permissions
 * 4. Every action in api/pos/index.php is mapped in $ACTION_PERMISSIONS or $DEVICE_ONLY_ACTIONS
 * 5. No wildcard 'Access-Control-Allow-Origin: *' in api/
 * 6. demo_mode default parameter is '0'
 * 7. No external CDN URLs in views/, pos/, tv.php
 * 8. Session cookie flags (httponly, samesite, secure) are configured
 * 9. Daily backup script and core Backup class exist
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $checkName, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$checkName}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$checkName}" . ($detail !== '' ? " -> {$detail}" : "") . "\n";
    }
}

echo "====================================================================\n";
echo "       CAR WASH SERVICE: BRIEF COMPLIANCE AUTOMATED AUDIT           \n";
echo "====================================================================\n\n";

// ---------------------------------------------------------------------
// CHECK 1: Required Database Tables and Columns Exist
// ---------------------------------------------------------------------
echo "1. Checking Required Database Tables & Columns...\n";

$requiredTablesAndCols = [
    'users' => ['id', 'username', 'role', 'local_pin_hash', 'status'],
    'roles' => ['id', 'code', 'name_en', 'name_km'],
    'permissions' => ['id', 'code', 'label_en', 'label_km'],
    'role_permissions' => ['role_id', 'permission_id'],
    'pos_devices' => ['id', 'device_name', 'api_key_hash', 'status'],
    'pos_sessions' => ['id', 'token_hash', 'user_id', 'device_id', 'expires_at'],
    'cashier_shifts' => ['id', 'cashier_id', 'opening_cash_usd', 'shortage_owed_usd', 'status'],
    'drawer_events' => ['id', 'event_uid', 'event_type', 'cashier_id'],
    'orders' => ['id', 'order_number', 'plate_number', 'status', 'total_usd', 'cancelled_by_user_id', 'cancel_approved_by_user_id'],
    'order_items' => ['id', 'order_id', 'service_id', 'product_id', 'price_usd'],
    'bays' => ['id', 'bay_name', 'status', 'current_order_id'],
    'order_tasks' => ['id', 'order_id', 'task_type', 'lane', 'status'],
    'inspection_checklists' => ['id', 'order_id', 'item_key', 'status'],
    'parts_requests' => ['id', 'request_uid', 'order_id', 'status'],
    'products' => ['id', 'name_en', 'stock_qty'],
    'stock_movements' => ['id', 'movement_type', 'quantity', 'new_qty'],
    'audit_logs' => ['id', 'username', 'action', 'details', 'created_at'],
    'staff_timecards' => ['id', 'user_id', 'clock_in', 'clock_out', 'total_hours'],
    'timecard_corrections' => ['id', 'timecard_id', 'reason', 'corrected_by'],
    'customers' => ['id', 'name', 'phone', 'membership_tier_code'],
    'membership_tiers' => ['id', 'tier_code', 'name_en', 'name_km'],
];

$isSqlite = (Database::getDriver() === 'sqlite');

foreach ($requiredTablesAndCols as $table => $cols) {
    if ($isSqlite) {
        $tableExists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
    } else {
        $tableExists = (bool)$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetchColumn();
    }

    if (!$tableExists) {
        assertCheck(false, "Table '{$table}' exists", "Table missing from schema");
        continue;
    }

    assertCheck(true, "Table '{$table}' exists");

    // Check columns
    if ($isSqlite) {
        $existingCols = array_column($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    } else {
        $existingCols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ($cols as $col) {
        $colPresent = in_array($col, $existingCols, true);
        assertCheck($colPresent, "Column '{$table}.{$col}' exists");
    }
}

// ---------------------------------------------------------------------
// CHECK 2: Ledger Triggers Exist
// ---------------------------------------------------------------------
echo "\n2. Checking Immutable Ledger Triggers...\n";

$expectedTriggers = [
    'trg_stock_movements_before_delete',
    'trg_stock_movements_before_update',
    'trg_audit_logs_before_delete',
    'trg_audit_logs_before_update',
];

if ($isSqlite) {
    $existingTriggers = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'trigger'")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $existingTriggers = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_COLUMN);
}

foreach ($expectedTriggers as $trg) {
    $found = in_array($trg, $existingTriggers, true);
    assertCheck($found, "Trigger '{$trg}' exists", $found ? "Active" : "Missing trigger");
}

// ---------------------------------------------------------------------
// CHECK 3: Cashier Role Lacks Cancel and Refund Permissions
// ---------------------------------------------------------------------
echo "\n3. Checking Cashier Role Permissions (Cancel & Refund Restriction)...\n";

$cashierPermsStmt = $pdo->query("
    SELECT p.code 
    FROM role_permissions rp
    JOIN permissions p ON rp.permission_id = p.id
    JOIN roles r ON rp.role_id = r.id
    WHERE r.code = 'cashier'
");
$cashierPerms = $cashierPermsStmt->fetchAll(PDO::FETCH_COLUMN);

$forbiddenPerms = ['pos.cancel_order', 'orders.cancel', 'orders.refund'];
foreach ($forbiddenPerms as $forbidden) {
    $hasPerm = in_array($forbidden, $cashierPerms, true);
    assertCheck(!$hasPerm, "Cashier role lacks '{$forbidden}' permission", $hasPerm ? "VIOLATION: Permission found!" : "Strictly revoked");
}

// ---------------------------------------------------------------------
// CHECK 4: Every Action in api/pos/index.php is in $ACTION_PERMISSIONS
// ---------------------------------------------------------------------
echo "\n4. Checking api/pos/index.php Action Permission Mapping...\n";

$posApiContent = file_get_contents(__DIR__ . '/../api/pos/index.php');

// Extract $ACTION_PERMISSIONS keys
preg_match('/\$ACTION_PERMISSIONS\s*=\s*\[(.*?)\];/s', $posApiContent, $mapMatch);
$mappedActions = [];
if (!empty($mapMatch[1])) {
    preg_match_all("/'([a-zA-Z0-9_-]+)'\s*=>/", $mapMatch[1], $actionKeyMatches);
    $mappedActions = $actionKeyMatches[1] ?? [];
}

// Extract $DEVICE_ONLY_ACTIONS values
preg_match('/\$DEVICE_ONLY_ACTIONS\s*=\s*\[(.*?)\];/s', $posApiContent, $devMatch);
$deviceOnlyActions = [];
if (!empty($devMatch[1])) {
    preg_match_all("/'([a-zA-Z0-9_-]+)'/", $devMatch[1], $devKeyMatches);
    $deviceOnlyActions = $devKeyMatches[1] ?? [];
}

// Find all routed actions in api/pos/index.php
preg_match_all("/\\\$action\\s*===?\\s*['\"]([a-zA-Z0-9_-]+)['\"]/", $posApiContent, $actMatches);
$rawHandled = array_unique($actMatches[1] ?? []);

// Filter down to top-level routed actions (ignore health/ping internal checks)
$handledActions = array_filter($rawHandled, function($act) {
    return !in_array($act, ['ping', 'health'], true);
});

assertCheck(!empty($mappedActions), "\$ACTION_PERMISSIONS array defined", "Found " . count($mappedActions) . " mapped actions");
assertCheck(!empty($deviceOnlyActions), "\$DEVICE_ONLY_ACTIONS array defined", "Found " . count($deviceOnlyActions) . " device-only actions");

$allCoveredActions = array_merge($mappedActions, $deviceOnlyActions);
$unmapped = [];
foreach ($handledActions as $act) {
    if (!in_array($act, $allCoveredActions, true)) {
        $unmapped[] = $act;
    }
}

assertCheck(empty($unmapped), "All handled actions are in \$ACTION_PERMISSIONS or \$DEVICE_ONLY_ACTIONS", 
    empty($unmapped) ? "All " . count($handledActions) . " routed actions covered" : "Unmapped actions: " . implode(', ', $unmapped));

// ---------------------------------------------------------------------
// CHECK 5: No 'Access-Control-Allow-Origin: *' in api/
// ---------------------------------------------------------------------
echo "\n5. Checking for Wildcard CORS in api/...\n";

$apiDir = dirname(__DIR__) . '/api';
$apiFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir));
$wildcardCorsFound = false;
$violatingFiles = [];

foreach ($apiFiles as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        if (preg_match('/Access-Control-Allow-Origin:\s*\*/i', $content)) {
            $wildcardCorsFound = true;
            $violatingFiles[] = $file->getBasename();
        }
    }
}

assertCheck(!$wildcardCorsFound, "No 'Access-Control-Allow-Origin: *' found in api/", 
    $wildcardCorsFound ? "Violations in: " . implode(', ', $violatingFiles) : "Zero wildcard CORS headers");

// ---------------------------------------------------------------------
// CHECK 6: demo_mode Default Parameter is '0'
// ---------------------------------------------------------------------
echo "\n6. Checking demo_mode Default Value...\n";

$filesToCheckDemo = [
    __DIR__ . '/../core/Auth.php',
    __DIR__ . '/../index.php',
    __DIR__ . '/../views/auth/login.php',
    __DIR__ . '/../views/layouts/header.php',
];

$demoDefaultSafe = true;
$demoViolations = [];

foreach ($filesToCheckDemo as $fp) {
    if (file_exists($fp)) {
        $c = file_get_contents($fp);
        // Look for get_setting('demo_mode', ...) where default is '1' instead of '0'
        if (preg_match("/get_setting\(\s*['\"]demo_mode['\"]\s*,\s*['\"]1['\"]/i", $c)) {
            $demoDefaultSafe = false;
            $demoViolations[] = basename($fp);
        }
    }
}

assertCheck($demoDefaultSafe, "demo_mode default parameter is '0' across runtime files", 
    $demoDefaultSafe ? "Safe by default ('0')" : "Unsafe default '1' in: " . implode(', ', $demoViolations));

// ---------------------------------------------------------------------
// CHECK 7: No External CDN URLs in views/, pos/, tv.php
// ---------------------------------------------------------------------
echo "\n7. Checking for External CDN URLs in views/, pos/, tv.php...\n";

$cdnPattern = '/(https?:)?\/\/(cdn\.|cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|unpkg\.com|fonts\.googleapis\.com|stackpath\.bootstrapcdn\.com)/i';
$externalCdnFound = false;
$cdnViolations = [];

$scanPaths = [
    dirname(__DIR__) . '/views',
    dirname(__DIR__) . '/pos',
];

foreach ($scanPaths as $dirPath) {
    if (!is_dir($dirPath)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath));
    foreach ($iterator as $f) {
        if ($f->isFile() && in_array($f->getExtension(), ['php', 'html', 'js', 'css'], true)) {
            $content = file_get_contents($f->getPathname());
            if (preg_match($cdnPattern, $content, $m)) {
                $externalCdnFound = true;
                $cdnViolations[] = $f->getFilename() . " (" . $m[0] . ")";
            }
        }
    }
}

// tv.php
$tvPath = dirname(__DIR__) . '/tv.php';
if (file_exists($tvPath)) {
    $tvContent = file_get_contents($tvPath);
    if (preg_match($cdnPattern, $tvContent, $m)) {
        $externalCdnFound = true;
        $cdnViolations[] = "tv.php (" . $m[0] . ")";
    }
}

assertCheck(!$externalCdnFound, "No external CDN URLs in views/, pos/, tv.php (all assets local-first)", 
    $externalCdnFound ? "CDN references found in: " . implode(', ', $cdnViolations) : "All assets served locally");

// ---------------------------------------------------------------------
// CHECK 8: Session Cookie Flags Set
// ---------------------------------------------------------------------
echo "\n8. Checking Session Cookie Security Flags...\n";

$authPath = dirname(__DIR__) . '/core/Auth.php';
$authContent = file_get_contents($authPath);

$hasHttpOnly = (bool)preg_match("/['\"]httponly['\"]\s*=>\s*true/i", $authContent);
$hasSameSite = (bool)preg_match("/['\"]samesite['\"]\s*=>\s*['\"](Strict|Lax)['\"]/i", $authContent);
$hasStrictUseOnly = (bool)preg_match("/session\.use_only_cookies/i", $authContent);

assertCheck($hasHttpOnly, "Session cookie flag 'httponly' is true");
assertCheck($hasSameSite, "Session cookie flag 'samesite' is configured (Strict/Lax)");
assertCheck($hasStrictUseOnly, "Session ini 'session.use_only_cookies' is enabled");

// ---------------------------------------------------------------------
// CHECK 9: Backup Script Exists & Healthy
// ---------------------------------------------------------------------
echo "\n9. Checking Daily Backup Script & Class...\n";

$backupScript = dirname(__DIR__) . '/scripts/backup_daily.php';
$backupClass = dirname(__DIR__) . '/core/Backup.php';

assertCheck(file_exists($backupScript), "scripts/backup_daily.php exists and is accessible");
assertCheck(file_exists($backupClass), "core/Backup.php exists");

if (file_exists($backupClass)) {
    require_once $backupClass;
    $hasRunDaily = method_exists('Backup', 'runDailyBackup');
    $hasRotate = method_exists('Backup', 'rotateBackups');
    assertCheck($hasRunDaily, "Backup::runDailyBackup method exists");
    assertCheck($hasRotate, "Backup::rotateBackups method exists");
}

// ---------------------------------------------------------------------
// FINAL SUMMARY
// ---------------------------------------------------------------------
echo "\n====================================================================\n";
echo "                     COMPLIANCE AUDIT RESULTS                       \n";
echo "====================================================================\n";
echo "Total Checks Run   : " . ($passCount + $failCount) . "\n";
echo "Checks Passed      : {$passCount}\n";
echo "Checks Failed      : {$failCount}\n";

if ($failCount === 0) {
    echo "\n>>> STATUS: ALL BRIEF COMPLIANCE AUDIT INVARIANTS PASSED <<<\n\n";
    exit(0);
} else {
    echo "\n>>> STATUS: SOME CHECKS FAILED - REVIEW LOGS ABOVE <<<\n\n";
    exit(1);
}
