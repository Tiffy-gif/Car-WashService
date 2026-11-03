<?php
declare(strict_types=1);

/**
 * Development-Only Sample Accounts Login Reset Utility
 * 
 * DEVELOPMENT ONLY. Resets credentials and unlocks sample demo accounts
 * for local testing. Never to be deployed to production.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Helpers.php';
require_once dirname(__DIR__) . '/core/AuditLogger.php';

// Parse command-line flags
$isApply = false;
$forceDev = false;
$isYes = false;
$enableDemo = false;
$filterUsers = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $isApply = true;
    } elseif ($arg === '--force-dev') {
        $forceDev = true;
    } elseif ($arg === '--yes') {
        $isYes = true;
    } elseif ($arg === '--enable-demo') {
        $enableDemo = true;
    } elseif (str_starts_with($arg, '--users=')) {
        $val = substr($arg, 8);
        $filterUsers = array_filter(array_map('trim', explode(',', $val)));
    }
}

$appEnv = defined('APP_ENV') ? APP_ENV : 'production';

// Guard: Require development environment unless --force-dev is provided
if ($appEnv !== 'development' && !$forceDev) {
    echo "ERROR: APP_ENV is '{$appEnv}'. This development tool is blocked in non-development environments.\n";
    echo "Use --force-dev to bypass if you are sure you want to reset credentials.\n";
    exit(1);
}

// Print loud warning if bypassing in non-dev environment
if ($appEnv !== 'development' && $forceDev) {
    echo "*******************************************************************************\n";
    echo "  WARNING: RUNNING WITH --force-dev IN NON-DEVELOPMENT ENVIRONMENT (APP_ENV: {$appEnv})\n";
    echo "  THIS RESETS LOGIN CREDENTIALS FOR SAMPLE DEVELOPMENT ACCOUNTS!\n";
    echo "*******************************************************************************\n\n";
}

// Prompt for confirmation if --apply is set without --yes
if ($isApply && !$isYes) {
    echo "WARNING: This will reset credentials and clear lockouts for sample accounts.\n";
    echo "Type RESET to continue: ";
    $handle = fopen("php://stdin", "r");
    $input = trim((string)fgets($handle));
    fclose($handle);
    if ($input !== 'RESET') {
        echo "Aborted. No changes were made.\n";
        exit(0);
    }
}

try {
    $pdo = Database::getInstance();
} catch (Throwable $t) {
    echo "Fatal Error: Cannot connect to database: " . $t->getMessage() . "\n";
    exit(1);
}

// Check available columns on users table
$userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$hasMustChangePin = in_array('must_change_pin', $userCols, true);
$hasLocalPinHash = in_array('local_pin_hash', $userCols, true);

// If --enable-demo was requested and applying, update setting now
if ($isApply && $enableDemo) {
    update_setting($pdo, 'demo_mode', '1', 0, 'dev_reset_logins');
}

// Gather target accounts: config/demo_accounts.php PLUS seed users from database/init.php
$targetAccounts = [];
$demoFile = dirname(__DIR__) . '/config/demo_accounts.php';
if (file_exists($demoFile)) {
    $rawDemo = require $demoFile;
    if (is_array($rawDemo)) {
        foreach ($rawDemo as $entry) {
            if (!empty($entry['username']) && !empty($entry['pin'])) {
                $targetAccounts[$entry['username']] = (string)$entry['pin'];
            }
        }
    }
}

// Seed users from database/init.php
$seedUsers = ['owner', 'cashier', 'washer1', 'washer2', 'mechanic1'];
foreach ($seedUsers as $su) {
    if (!isset($targetAccounts[$su])) {
        $targetAccounts[$su] = '123456';
    }
}

// Filter usernames if --users=a,b,c flag was provided
if ($filterUsers !== null) {
    $filtered = [];
    foreach ($filterUsers as $u) {
        if (isset($targetAccounts[$u])) {
            $filtered[$u] = $targetAccounts[$u];
        } else {
            $filtered[$u] = '123456';
        }
    }
    $targetAccounts = $filtered;
}

// Fetch existing user records from database
$existingUsers = [];
if (!empty($targetAccounts)) {
    $placeholders = implode(',', array_fill(0, count($targetAccounts), '?'));
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username IN ({$placeholders})");
    $stmt->execute(array_keys($targetAccounts));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingUsers[$row['username']] = $row;
    }
}

$results = [];

if ($isApply) {
    $pdo->beginTransaction();
    try {
        $resetUsernames = [];
        $updCols = "password_hash = ?, status = 'active', failed_login_attempts = 0, locked_until = NULL";
        if ($hasLocalPinHash) {
            $updCols .= ", local_pin_hash = ?";
        }
        if ($hasMustChangePin) {
            $updCols .= ", must_change_pin = 0";
        }
        $updStmt = $pdo->prepare("UPDATE users SET {$updCols} WHERE id = ?");
        $expStmt = $pdo->prepare("UPDATE pos_sessions SET expires_at = NOW() WHERE user_id = ? AND expires_at > NOW()");

        foreach ($targetAccounts as $uname => $pin) {
            if (!isset($existingUsers[$uname])) {
                $results[$uname] = [
                    'username'    => $uname,
                    'role'        => 'n.a.',
                    'status'      => 'n.a.',
                    'pin'         => $pin,
                    'result'      => 'not found',
                    'login_check' => 'n.a.'
                ];
                continue;
            }

            $uId = (int)$existingUsers[$uname]['id'];
            $bcryptHash = password_hash($pin, PASSWORD_BCRYPT);

            $params = [$bcryptHash];
            if ($hasLocalPinHash) {
                $params[] = $bcryptHash;
            }
            $params[] = $uId;

            $updStmt->execute($params);
            $expStmt->execute([$uId]);
            $resetUsernames[] = $uname;

            // Verification check via password_verify against fresh database read
            $verifyStmt = $pdo->prepare("SELECT password_hash, local_pin_hash, status FROM users WHERE id = ?");
            $verifyStmt->execute([$uId]);
            $freshUser = $verifyStmt->fetch(PDO::FETCH_ASSOC);

            $checkHash = $freshUser['local_pin_hash'] ?? $freshUser['password_hash'] ?? '';
            $checkOk = (!empty($checkHash) && password_verify($pin, $checkHash));

            $results[$uname] = [
                'username'    => $uname,
                'role'        => $existingUsers[$uname]['role'] ?? 'user',
                'status'      => 'active',
                'pin'         => $pin,
                'result'      => 'reset',
                'login_check' => $checkOk ? 'OK' : 'FAILED'
            ];
        }

        if (!empty($resetUsernames)) {
            AuditLogger::log(
                'DEV_LOGIN_RESET',
                'Reset sample development accounts: ' . implode(', ', $resetUsernames),
                'users',
                null,
                0,
                'CLI',
                'system'
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "ERROR: Failed to reset accounts: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // DRY RUN mode: inspect and report what would change without modifying database
    foreach ($targetAccounts as $uname => $pin) {
        if (!isset($existingUsers[$uname])) {
            $results[$uname] = [
                'username'    => $uname,
                'role'        => 'n.a.',
                'status'      => 'n.a.',
                'pin'         => $pin,
                'result'      => 'not found',
                'login_check' => 'n.a.'
            ];
        } else {
            $results[$uname] = [
                'username'    => $uname,
                'role'        => $existingUsers[$uname]['role'] ?? 'user',
                'status'      => $existingUsers[$uname]['status'] ?? 'unknown',
                'pin'         => $pin,
                'result'      => 'would reset',
                'login_check' => 'n.a.'
            ];
        }
    }
}

// Current demo mode status
$demoMode = get_setting('demo_mode', '0', true);
$demoModeStatus = ($demoMode === '1') ? 'ON' : 'OFF';

$modeStr = $isApply ? 'APPLIED' : 'DRY RUN';
$appUrl = defined('APP_URL') ? APP_URL : '/Car-WashService';

// Output structured result message
echo "==== DEV LOGIN RESET RESULT ====\n";
echo "Mode: {$modeStr}     APP_ENV: {$appEnv}     Demo mode (Test Mode): {$demoModeStatus}\n\n";

// Table headers and rows
echo sprintf("%-16s | %-16s | %-10s | %-10s | %-13s | %-11s\n", 'Username', 'Role', 'Status', 'PIN to use', 'Result', 'Login check');
echo str_repeat('-', 94) . "\n";

foreach ($results as $r) {
    echo sprintf(
        "%-16s | %-16s | %-10s | %-10s | %-13s | %-11s\n",
        $r['username'],
        $r['role'],
        $r['status'],
        $r['pin'],
        $r['result'],
        $r['login_check']
    );
}

echo str_repeat('-', 94) . "\n\n";

echo "Back office login: {$appUrl}/index.php?view=login\n";
echo "POS login: {$appUrl}/pos/index.html#pos\n";

if ($demoModeStatus === 'OFF') {
    echo "WARNING: sample accounts are blocked while Test Mode is OFF. Run again with --enable-demo or switch Test Mode on in Settings.\n";
}

$cloudUrl = get_setting('cloud_api_url', '');
$cloudToken = get_setting('cloud_api_token', '');
if (!empty($cloudUrl) && !empty($cloudToken)) {
    echo "NOTE: the cloud sync can overwrite PINs of users that also exist in the cloud. Use different usernames there or set the same PIN in the cloud.\n";
}

echo "DEVELOPMENT ONLY. Set real PINs, turn Test Mode off and delete scripts/dev_reset_logins.php before go-live.\n";
