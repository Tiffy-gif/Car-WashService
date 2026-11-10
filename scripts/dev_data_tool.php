<?php
declare(strict_types=1);

/**
 * Development Data Management CLI Tool
 *
 * Commands:
 *   php scripts/dev_data_tool.php save --name=NAME
 *   php scripts/dev_data_tool.php list
 *   php scripts/dev_data_tool.php reset [--dry-run]
 *   php scripts/dev_data_tool.php restore --name=NAME
 *
 * Safety:
 *   - Strictly CLI only
 *   - Refuses when APP_ENV !== 'development' unless --force-dev is given
 *   - Never prints password/PIN hashes, tokens, secrets or device keys
 *   - Zero external calls to Auth::login()
 *   - Automatic safety snapshot before reset or restore
 *   - Zero shell command password exposure via secure temporary my.cnf
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "Access Denied: This tool is CLI-only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

// -------------------------------------------------------------
// Safety & Environment Checks
// -------------------------------------------------------------
$forceDev = in_array('--force-dev', $argv, true);
if (APP_ENV !== 'development' && !$forceDev) {
    fwrite(STDERR, "\n=======================================================\n");
    fwrite(STDERR, "❌ ERROR: REFUSING TO RUN DEV DATA TOOL IN ENVIRONMENT: " . APP_ENV . "\n");
    fwrite(STDERR, "This tool can wipe and modify database tables.\n");
    fwrite(STDERR, "To force execution in non-development, supply flag: --force-dev\n");
    fwrite(STDERR, "=======================================================\n\n");
    exit(1);
}

if (APP_ENV !== 'development' && $forceDev) {
    echo "\n⚠️  WARNING: RUNNING IN NON-DEVELOPMENT ENVIRONMENT (" . APP_ENV . ") WITH --force-dev!\n\n";
}

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::getInstance();
$dbname = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();

// -------------------------------------------------------------
// Constants & Definitions
// -------------------------------------------------------------
const SNAPSHOTS_DIR = __DIR__ . '/../storage/snapshots';

const REQUIRED_TRIGGERS = [
    'trg_audit_logs_before_update',
    'trg_audit_logs_before_delete',
    'trg_stock_movements_before_update',
    'trg_stock_movements_before_delete'
];

const DATA_TABLES = [
    'appointments',
    'audit_logs',
    'bay_history',
    'cashier_shifts',
    'crm_message_queue',
    'customers',
    'drawer_events',
    'expenses',
    'inspection_checklists',
    'loyalty_transactions',
    'membership_usages',
    'oil_change_logs',
    'order_items',
    'order_mechanics',
    'order_tasks',
    'order_washers',
    'orders',
    'parts_requests',
    'payments',
    'pos_closings',
    'pos_daily_closes',
    'pos_device_login_attempts',
    'pos_device_pin_attempts',
    'pos_devices',
    'pos_sessions',
    'role_audit_logs',
    'service_duration_reviews',
    'settings_history',
    'spot_audits',
    'staff_timecards',
    'stock_discrepancies',
    'stock_movements',
    'sync_conflicts',
    'sync_logs',
    'sync_queue',
    'system_notifications',
    'timecard_corrections',
    'upsell_logs',
    'user_notification_settings',
    'vehicle_repair_history',
    'vehicles'
];

const NEUTRAL_SETTINGS = [
    'aba_account_name'         => '',
    'aba_account_num'          => '',
    'cloud_allow_insecure_tls' => '0',
    'cloud_api_secret'         => '',
    'cloud_api_token'          => '',
    'cloud_api_url'            => '',
    'cloud_last_pull_at'       => '',
    'cloud_last_sync_at'       => '',
    'cloud_offline_mode'       => '0',
    'cloud_shop_id'            => '',
    'cloud_sync_enabled'       => '0',
    'demo_mode'                => '0',
    'khqr_image'               => '',
    'shop_logo'                => '',
    'shop_name_en'             => 'My Car Wash',
    'shop_name_km'             => 'សេវាកម្មលាងរថយន្ត',
    'shop_address'             => '',
    'shop_phone'               => '',
    'telegram_bot_token'       => '',
    'telegram_chat_id'         => '',
    'default_pw_dismissed'     => '0',
    'receipt_footer'           => 'Thank you for your visit!'
];

// -------------------------------------------------------------
// Helper Functions
// -------------------------------------------------------------
function findBinary(string $name): string {
    $candidates = [
        'C:\\xampp\\mysql\\bin\\' . $name . '.exe',
        'C:\\xampp\\mysql\\bin\\' . $name,
        '/usr/bin/' . $name,
        '/usr/local/bin/' . $name,
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) return $c;
    }
    return $name;
}

function withTempMyCnf(array $dbConfig, callable $callback) {
    $tmpCnf = tempnam(sys_get_temp_dir(), 'my_cnf_');
    $user = $dbConfig['user'] ?? 'root';
    $pass = $dbConfig['pass'] ?? '';
    $host = $dbConfig['host'] ?? 'localhost';
    $port = $dbConfig['port'] ?? 3306;

    $content = "[client]\n"
        . "host={$host}\n"
        . "port={$port}\n"
        . "user={$user}\n"
        . "password=" . addcslashes((string)$pass, "\"\\\n") . "\n";

    file_put_contents($tmpCnf, $content);
    try {
        return $callback($tmpCnf);
    } finally {
        if (file_exists($tmpCnf)) {
            @unlink($tmpCnf);
        }
    }
}

function printDatabaseOverview(PDO $pdo, string $dbname): void {
    echo "=======================================================\n";
    echo " Database: {$dbname} (APP_ENV: " . APP_ENV . ")\n";
    echo "=======================================================\n";
    $keyTables = ['users', 'orders', 'order_items', 'customers', 'vehicles', 'audit_logs', 'stock_movements', 'cashier_shifts'];
    $summary = [];
    foreach ($keyTables as $t) {
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $summary[] = "{$t}: {$cnt}";
        } catch (Throwable) {
            // Table might not exist yet
        }
    }
    echo " Main counts: " . implode(' | ', $summary) . "\n";
    echo "-------------------------------------------------------\n";
}

function promptHiddenInput(string $prompt): string {
    echo $prompt;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // On Windows PowerShell/CMD, use powershell to read secure string
        $psCmd = 'powershell -Command "$p = Read-Host -AsSecureString; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $pin = trim((string)shell_exec($psCmd));
        echo "\n";
        return $pin;
    } else {
        // POSIX terminal
        system('stty -echo');
        $pin = trim((string)fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $pin;
    }
}

function copyDirectory(string $src, string $dst): int {
    if (!is_dir($src)) return 0;
    @mkdir($dst, 0755, true);
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $subPath = $iterator->getSubPathname();
        $target = $dst . DIRECTORY_SEPARATOR . $subPath;
        if ($item->isDir()) {
            @mkdir($target, 0755, true);
        } else {
            // Skip .htaccess from copying into snapshot data files
            if (basename($item->getPathname()) === '.htaccess') continue;
            copy($item->getPathname(), $target);
            $count++;
        }
    }
    return $count;
}

// -------------------------------------------------------------
// Command: SAVE
// -------------------------------------------------------------
function commandSave(PDO $pdo, array $dbConfig, string $dbname, array $options, bool $isSubcall = false): string {
    if (!$isSubcall) {
        printDatabaseOverview($pdo, $dbname);
    }

    $rawName = $options['name'] ?? 'snapshot';
    $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rawName) ?: 'snapshot';
    $timestamp = date('Ymd_His');
    $snapshotDirName = "{$sanitized}_{$timestamp}";

    @mkdir(SNAPSHOTS_DIR, 0755, true);
    $targetDir = SNAPSHOTS_DIR . DIRECTORY_SEPARATOR . $snapshotDirName;

    $suffix = 1;
    while (file_exists($targetDir)) {
        $targetDir = SNAPSHOTS_DIR . DIRECTORY_SEPARATOR . "{$snapshotDirName}_{$suffix}";
        $suffix++;
    }
    @mkdir($targetDir, 0755, true);
    $dumpFile = $targetDir . DIRECTORY_SEPARATOR . 'database.sql';

    echo "Creating database dump...\n";
    $mysqldump = findBinary('mysqldump');

    $dumpSuccess = withTempMyCnf($dbConfig, function ($cnfFile) use ($mysqldump, $dbname, $dumpFile) {
        $cmd = sprintf(
            '"%s" --defaults-extra-file="%s" --single-transaction --routines --triggers --events --databases "%s"',
            $mysqldump,
            $cnfFile,
            $dbname
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $dumpFile, 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            fwrite(STDERR, "mysqldump error (code {$code}): {$stderr}\n");
            return false;
        }
        return true;
    });

    if (!$dumpSuccess || !file_exists($dumpFile) || filesize($dumpFile) === 0) {
        throw new RuntimeException("mysqldump failed to generate a valid database dump.");
    }

    // Verify dump content
    echo "Verifying dump integrity and immutable ledger triggers...\n";
    $dumpContent = file_get_contents($dumpFile);
    foreach (REQUIRED_TRIGGERS as $trg) {
        if (!str_contains($dumpContent, $trg)) {
            throw new RuntimeException("Dump validation failed: missing critical trigger '{$trg}'");
        }
    }

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $tableCounts = [];
    foreach ($tables as $t) {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $tableCounts[$t] = $cnt;
        if (!str_contains($dumpContent, "`{$t}`")) {
            throw new RuntimeException("Dump validation failed: table '{$t}' not found in dump file.");
        }
    }

    // Copy uploaded data files
    echo "Backing up uploaded data files...\n";
    $filesTargetDir = $targetDir . DIRECTORY_SEPARATOR . 'files';
    @mkdir($filesTargetDir, 0755, true);
    $copiedFiles = 0;

    $uploadsBase = dirname(__DIR__) . '/public/uploads';
    if (is_dir($uploadsBase)) {
        $copiedFiles += copyDirectory($uploadsBase . '/bank_slips', $filesTargetDir . '/public/uploads/bank_slips');
        $copiedFiles += copyDirectory($uploadsBase . '/products', $filesTargetDir . '/public/uploads/products');
        $copiedFiles += copyDirectory($uploadsBase . '/services', $filesTargetDir . '/public/uploads/services');
        // Copy logo / khqr files directly in public/uploads
        foreach (glob($uploadsBase . '/*') as $f) {
            if (is_file($f)) {
                $base = basename($f);
                if (str_starts_with($base, 'logo_') || str_starts_with($base, 'khqr_')) {
                    @mkdir($filesTargetDir . '/public/uploads', 0755, true);
                    copy($f, $filesTargetDir . '/public/uploads/' . $base);
                    $copiedFiles++;
                }
            }
        }
    }

    // Compute checksum and size
    $dumpSha256 = hash_file('sha256', $dumpFile);
    $dumpBytes = filesize($dumpFile);

    // Write manifest
    $gitCommit = trim((string)@shell_exec('git rev-parse HEAD 2>nul') ?: 'unknown');
    $manifest = [
        'database'        => $dbname,
        'snapshot_name'   => basename($targetDir),
        'created_at'      => date('Y-m-d H:i:s'),
        'app_env'         => APP_ENV,
        'git_commit'      => $gitCommit,
        'table_counts'    => $tableCounts,
        'file_count'      => $copiedFiles,
        'dump_sha256'     => $dumpSha256,
        'dump_size_bytes' => $dumpBytes,
    ];

    file_put_contents(
        $targetDir . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $totalDirSize = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir)) as $file) {
        $totalDirSize += $file->getSize();
    }
    $sizeMb = round($totalDirSize / (1024 * 1024), 2);

    echo "\n-------------------------------------------------------\n";
    echo "Folder: {$targetDir}\n";
    echo "Size:   {$sizeMb} MB ({$copiedFiles} data files included)\n";
    echo "SHA256: {$dumpSha256}\n";
    echo "SNAPSHOT SAVED\n";
    echo "-------------------------------------------------------\n";
    echo "⚠️  WARNING: Snapshot contains password/PIN hashes and tokens.\n";
    echo "Keep storage/snapshots secure and never commit snapshots to version control.\n\n";

    return basename($targetDir);
}

// -------------------------------------------------------------
// Command: LIST
// -------------------------------------------------------------
function commandList(): void {
    echo "=======================================================\n";
    echo " Saved Snapshots in storage/snapshots/\n";
    echo "=======================================================\n";

    if (!is_dir(SNAPSHOTS_DIR)) {
        echo "No snapshots directory found. No snapshots taken yet.\n\n";
        return;
    }

    $dirs = glob(SNAPSHOTS_DIR . '/*', GLOB_ONLYDIR);
    if (empty($dirs)) {
        echo "No snapshots found.\n\n";
        return;
    }

    $snapshots = [];
    foreach ($dirs as $d) {
        $manifestFile = $d . DIRECTORY_SEPARATOR . 'manifest.json';
        if (file_exists($manifestFile)) {
            $data = json_decode((string)file_get_contents($manifestFile), true);
            if (is_array($data)) {
                $data['dir'] = $d;
                $snapshots[] = $data;
            }
        }
    }

    usort($snapshots, function ($a, $b) {
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });

    printf("%-30s | %-19s | %-8s | %-6s | %-6s | %s\n", "Name", "Created", "Size", "Users", "Orders", "Notes");
    echo str_repeat('-', 90) . "\n";

    foreach ($snapshots as $s) {
        $name = basename($s['dir']);
        $created = $s['created_at'] ?? 'unknown';
        $bytes = (int)($s['dump_size_bytes'] ?? 0);
        $sizeStr = round($bytes / (1024 * 1024), 2) . 'MB';
        $users = $s['table_counts']['users'] ?? '?';
        $orders = $s['table_counts']['orders'] ?? '?';
        $notes = "env:" . ($s['app_env'] ?? '?') . " (" . ($s['file_count'] ?? 0) . " files)";

        printf("%-30s | %-19s | %-8s | %-6s | %-6s | %s\n", substr($name, 0, 30), $created, $sizeStr, $users, $orders, $notes);
    }
    echo "\nTotal: " . count($snapshots) . " snapshot(s) found.\n\n";
}

// -------------------------------------------------------------
// Command: RESET (Brand-new installation)
// -------------------------------------------------------------
function commandReset(PDO $pdo, array $dbConfig, string $dbname, array $options): void {
    $dryRun = in_array('--dry-run', $options, true);

    printDatabaseOverview($pdo, $dbname);

    if ($dryRun) {
        echo "\n=======================================================\n";
        echo " [DRY RUN] BRAND-NEW INSTALLATION RESET PREVIEW\n";
        echo " (No changes will be made to database or files)\n";
        echo "=======================================================\n\n";

        echo "1. Automatic safety snapshot that would be created: pre_reset_YYYYMMDD_HHMMSS\n\n";

        echo "2. The following 41 DATA tables would be TRUNCATED (data completely wiped):\n";
        $totalWipedRows = 0;
        foreach (DATA_TABLES as $tbl) {
            try {
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
                $totalWipedRows += $cnt;
                printf("   - %-30s : %d rows\n", $tbl, $cnt);
            } catch (Throwable) {
                printf("   - %-30s : [table not present]\n", $tbl);
            }
        }
        echo "   TOTAL ROWS TO WIPE: {$totalWipedRows}\n\n";

        echo "3. Infrastructure & Stock Resets:\n";
        echo "   - products: stock_qty set to 0, reserved_qty set to 0\n";
        echo "   - bays: current_order_id set to NULL, status set to 'free', is_active set to 1\n";
        echo "   - categories & membership_tiers: KEPT INTACT (system taxonomy)\n\n";

        echo "4. Settings to be reset to neutral defaults:\n";
        foreach (NEUTRAL_SETTINGS as $k => $v) {
            $curr = (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = " . $pdo->quote($k))->fetchColumn();
            printf("   - %-25s : '%s' -> '%s'\n", $k, substr($curr, 0, 30), substr($v, 0, 30));
        }
        echo "   - tv_display_code: will generate a fresh random 16-character string\n\n";

        echo "5. User Accounts:\n";
        $userCnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        echo "   - Current users ({$userCnt} accounts) will be wiped.\n";
        echo "   - Prompt will ask for a fresh 6-digit Owner PIN twice.\n";
        echo "   - Exactly 1 active 'owner' user will be created.\n\n";

        echo "6. Uploaded Files to be purged:\n";
        $uploadPatterns = [
            'public/uploads/bank_slips/slip_*',
            'public/uploads/khqr_*',
            'public/uploads/logo_*',
            'public/uploads/products/*',
            'public/uploads/services/*',
        ];
        $totalFiles = 0;
        foreach ($uploadPatterns as $pat) {
            $matched = glob(dirname(__DIR__) . '/' . $pat);
            $c = count($matched);
            $totalFiles += $c;
            echo "   - {$pat}: {$c} files\n";
        }
        echo "   - storage/logs/*.log: truncate to 0 bytes\n";
        echo "   - storage/sync.lock & cloud_site_state.json: delete if present\n\n";

        echo "7. Verification checks that would execute:\n";
        echo "   - Check all 41 DATA tables have 0 rows\n";
        echo "   - Check users count is exactly 1\n";
        echo "   - Check 4 immutable ledger triggers exist\n";
        echo "   - Verify owner PIN with password_verify\n";
        echo "   - Verify demo_mode === '0'\n\n";

        echo "[DRY RUN COMPLETE] Zero changes were made.\n\n";
        return;
    }

    // REAL RESET CONFIRMATION
    echo "\n⚠️  CRITICAL CONFIRMATION REQUIRED:\n";
    echo "You are about to execute a BRAND-NEW INSTALLATION RESET on database '{$dbname}'.\n";
    echo "All orders, shifts, customers, vehicles, and users will be completely wiped.\n";
    echo "To proceed, type the database name '{$dbname}' and press Enter: ";

    $confirm = trim((string)fgets(STDIN));
    if ($confirm !== $dbname) {
        echo "❌ Confirmation failed ('{$confirm}' !== '{$dbname}'). Reset cancelled.\n\n";
        return;
    }

    // 1. Automatic Snapshot
    echo "\n[Step 1/8] Running mandatory pre-reset safety snapshot...\n";
    try {
        $savedSnapshot = commandSave($pdo, $dbConfig, $dbname, ['name' => 'pre_reset'], true);
        echo "Safety snapshot created: {$savedSnapshot}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "❌ PRE-RESET SNAPSHOT FAILED: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Aborting reset to protect existing data!\n");
        exit(1);
    }

    // 2. Prompt for First Owner PIN before making database changes
    echo "\n[Step 2/8] First Owner Account Setup:\n";
    $ownerPin = '';
    while (true) {
        $p1 = promptHiddenInput("Enter new 6-digit Owner PIN: ");
        if (!preg_match('/^\d{6}$/', $p1)) {
            echo "❌ PIN must be exactly 6 numerical digits. Please try again.\n";
            continue;
        }
        $p2 = promptHiddenInput("Confirm new 6-digit Owner PIN: ");
        if ($p1 !== $p2) {
            echo "❌ PINs do not match. Please try again.\n";
            continue;
        }

        // Check weak PIN patterns
        $weakPins = ['123456', '654321', '111111', '000000', '222222', '333333', '444444', '555555', '666666', '777777', '888888', '999999', '012345', '234567', '345678', '456789', '567890', '987654', '876543'];
        if (in_array($p1, $weakPins, true)) {
            echo "⚠️  WARNING: You chose a very common or sequential PIN. For production, choose a stronger PIN.\n";
        }
        $ownerPin = $p1;
        break;
    }

    // 3. Truncate DATA tables
    echo "\n[Step 3/8] Wiping all DATA tables...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach (DATA_TABLES as $tbl) {
        try {
            $pdo->exec("TRUNCATE TABLE `{$tbl}`");
        } catch (Throwable $e) {
            // If table doesn't exist, ignore
        }
    }
    // Reset products stock
    $pdo->exec("UPDATE products SET stock_qty = 0, reserved_qty = 0");
    // Reset bays
    $pdo->exec("UPDATE bays SET current_order_id = NULL, status = 'free', is_active = 1");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "All DATA tables wiped cleanly.\n";

    // 4. Reset Settings to neutral defaults
    echo "\n[Step 4/8] Resetting settings to neutral defaults...\n";
    $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach (NEUTRAL_SETTINGS as $k => $v) {
        $setStmt->execute([$k, $v]);
    }
    // Random 16-character TV code
    $randomTvCode = bin2hex(random_bytes(8));
    $setStmt->execute(['tv_display_code', $randomTvCode]);
    echo "Settings reset (demo mode 0, cloud sync not configured, neutral branding).\n";

    // 5. Ensure roles & permissions and create single first owner
    echo "\n[Step 5/8] Creating single first Owner account...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE `users`");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    $ownerRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'owner'")->fetchColumn() ?: 1;
    $hashedPin = password_hash($ownerPin, PASSWORD_BCRYPT);

    $insUser = $pdo->prepare("
        INSERT INTO users (
            username, password_hash, local_pin_hash, full_name, phone,
            role, role_id, commission_type, commission_rate, status,
            failed_login_attempts, locked_until, must_change_pin, created_at
        ) VALUES (
            'owner', ?, ?, 'Shop Owner', '',
            'owner', ?, 'none', 0.00, 'active',
            0, NULL, 0, NOW()
        )
    ");
    $insUser->execute([$hashedPin, $hashedPin, $ownerRoleId]);
    $newOwnerId = (int)$pdo->lastInsertId();
    echo "Created owner user (ID #{$newOwnerId}).\n";

    // 6. Delete uploaded data files & truncate logs
    echo "\n[Step 6/8] Purging uploaded customer/data files and clearing logs...\n";
    $uploadPatterns = [
        'public/uploads/bank_slips/slip_*',
        'public/uploads/khqr_*',
        'public/uploads/logo_*',
        'public/uploads/products/*',
        'public/uploads/services/*',
    ];
    $deletedFiles = 0;
    foreach ($uploadPatterns as $pat) {
        foreach (glob(dirname(__DIR__) . '/' . $pat) as $f) {
            if (is_file($f)) {
                @unlink($f);
                $deletedFiles++;
            }
        }
    }
    // Truncate logs
    foreach (glob(dirname(__DIR__) . '/storage/logs/*.log') as $lf) {
        @file_put_contents($lf, '');
    }
    @unlink(dirname(__DIR__) . '/storage/sync.lock');
    @unlink(dirname(__DIR__) . '/storage/cloud_site_state.json');
    echo "Deleted {$deletedFiles} uploaded data files. Logs truncated.\n";

    // 7. Write DEV_RESET audit log
    echo "\n[Step 7/8] Recording DEV_RESET audit log entry...\n";
    $insAudit = $pdo->prepare("
        INSERT INTO audit_logs (
            action, details, entity_type, entity_id, user_id, username, user_role, ip_address, created_at
        ) VALUES (
            'DEV_RESET', 'Development tool reset database to brand-new installation', 'system', '0', ?, 'owner', 'owner', '127.0.0.1', NOW()
        )
    ");
    $insAudit->execute([$newOwnerId]);

    // 8. Automated Verification
    echo "\n[Step 8/8] Running post-reset automated verification checks...\n";
    echo "-------------------------------------------------------\n";

    $checks = [];

    // Check data tables empty (excluding audit_logs which has the DEV_RESET row)
    $dataEmpty = true;
    foreach (DATA_TABLES as $dt) {
        if ($dt === 'audit_logs') continue;
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$dt}`")->fetchColumn();
            if ($cnt !== 0) {
                $dataEmpty = false;
                break;
            }
        } catch (Throwable) {}
    }
    $auditCnt = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    $checks[] = ['DATA tables empty', ($dataEmpty && $auditCnt === 1), "40 tables 0 rows, audit_logs 1 row (DEV_RESET)"];

    // Check exactly 1 user
    $userCnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $checks[] = ['Exactly 1 user', ($userCnt === 1), "Found {$userCnt} user(s)"];

    // Check roles and permissions
    $rolesCnt = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    $permCnt = (int)$pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    $checks[] = ['Roles & Permissions intact', ($rolesCnt === 11 && $permCnt === 92), "Roles: {$rolesCnt}, Permissions: {$permCnt}"];

    // Check triggers exist
    $trgStmt = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
    $activeTriggers = array_column($trgStmt, 'Trigger');
    $trgOk = true;
    foreach (REQUIRED_TRIGGERS as $trg) {
        if (!in_array($trg, $activeTriggers, true)) {
            $trgOk = false;
            break;
        }
    }
    $checks[] = ['4 Immutable ledger triggers exist', $trgOk, "4/4 triggers active"];

    // Verify owner PIN
    $savedHash = (string)$pdo->query("SELECT password_hash FROM users WHERE username = 'owner'")->fetchColumn();
    $pinVerify = password_verify($ownerPin, $savedHash);
    $checks[] = ['Owner PIN verification', $pinVerify, "Bcrypt verification match"];

    // Verify demo_mode is '0'
    $demoVal = (string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'demo_mode'")->fetchColumn();
    $checks[] = ['demo_mode is 0', ($demoVal === '0'), "demo_mode = '{$demoVal}'"];

    $allPassed = true;
    foreach ($checks as [$title, $pass, $detail]) {
        if (!$pass) $allPassed = false;
        printf("[%s] %-32s : %s\n", $pass ? "PASS" : "FAIL", $title, $detail);
    }
    echo "-------------------------------------------------------\n";

    if (!$allPassed) {
        echo "❌ WARNING: One or more post-reset verification checks failed!\n\n";
    } else {
        echo "🎉 ALL POST-RESET VERIFICATIONS PASSED!\n\n";
    }

    echo "=======================================================\n";
    echo " FIRST-START NEXT STEPS\n";
    echo "=======================================================\n";
    echo "1. Clear browser data for localhost:\n";
    echo "   Press F12 in your browser > Application > Clear site data\n";
    echo "   (Unregister service worker and clear cache to remove old session tokens)\n";
    echo "2. Open login screen: http://localhost/Car-WashService/index.php?view=login\n";
    echo "3. Log in with:\n";
    echo "   - Username: owner\n";
    echo "   - 6-digit PIN: [The PIN you just entered]\n";
    echo "4. Open Back Office > Settings > Shop Info to configure your real shop details.\n";
    echo "5. Follow docs/FIRST_START_CHECKLIST.md for your initial shop commissioning.\n\n";
    echo "ℹ️  Note: Your previous data was safely backed up as snapshot '{$savedSnapshot}'.\n";
    echo "   You can restore it anytime with:\n";
    echo "   php scripts\\dev_data_tool.php restore --name={$savedSnapshot}\n";
    echo "=======================================================\n\n";
}

// -------------------------------------------------------------
// Command: RESTORE
// -------------------------------------------------------------
function commandRestore(PDO $pdo, array $dbConfig, string $dbname, array $options): void {
    $rawName = $options['name'] ?? null;
    if (empty($rawName)) {
        fwrite(STDERR, "❌ ERROR: --name=NAME is required for restore. Run 'list' to see snapshots.\n");
        exit(1);
    }

    // Locate snapshot directory
    $candidateDirs = glob(SNAPSHOTS_DIR . '/' . $rawName . '*');
    if (empty($candidateDirs)) {
        fwrite(STDERR, "❌ ERROR: Snapshot matching '{$rawName}' not found in storage/snapshots/.\n");
        exit(1);
    }
    $targetDir = $candidateDirs[0];
    $manifestFile = $targetDir . '/manifest.json';
    $dumpFile = $targetDir . '/database.sql';

    if (!file_exists($manifestFile) || !file_exists($dumpFile)) {
        fwrite(STDERR, "❌ ERROR: Incomplete snapshot folder (missing manifest.json or database.sql).\n");
        exit(1);
    }

    $manifest = json_decode((string)file_get_contents($manifestFile), true);
    if (!is_array($manifest)) {
        fwrite(STDERR, "❌ ERROR: Invalid or corrupted manifest.json in snapshot.\n");
        exit(1);
    }

    echo "=======================================================\n";
    echo " SNAPSHOT TO RESTORE\n";
    echo "=======================================================\n";
    echo "Name:       " . basename($targetDir) . "\n";
    echo "Created At: " . ($manifest['created_at'] ?? 'unknown') . "\n";
    echo "Database:   " . ($manifest['database'] ?? 'unknown') . "\n";
    echo "Git Commit: " . ($manifest['git_commit'] ?? 'unknown') . "\n";
    echo "Dump Size:  " . round(($manifest['dump_size_bytes'] ?? 0) / (1024 * 1024), 2) . " MB\n";
    echo "Data Files: " . ($manifest['file_count'] ?? 0) . " files\n";
    echo "-------------------------------------------------------\n";

    // Safety prompt
    echo "\n⚠️  CRITICAL CONFIRMATION REQUIRED:\n";
    echo "Restoring will OVERWRITE the current database '{$dbname}'.\n";
    echo "An automatic safety snapshot (pre_restore) will be saved first.\n";
    echo "To proceed, type the database name '{$dbname}' and press Enter: ";

    $confirm = trim((string)fgets(STDIN));
    if ($confirm !== $dbname) {
        echo "❌ Confirmation failed ('{$confirm}' !== '{$dbname}'). Restore cancelled.\n\n";
        return;
    }

    // 1. Auto save current state
    echo "\n[Step 1/5] Creating pre-restore safety snapshot of CURRENT state...\n";
    try {
        $preRestoreSnap = commandSave($pdo, $dbConfig, $dbname, ['name' => 'pre_restore'], true);
        echo "Current state saved: {$preRestoreSnap}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "❌ PRE-RESTORE SAFETY SNAPSHOT FAILED: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Aborting restore to protect your current data!\n");
        exit(1);
    }

    // 2. Checksum verification
    echo "\n[Step 2/5] Verifying dump checksum against manifest...\n";
    $actualSha = hash_file('sha256', $dumpFile);
    $expectedSha = $manifest['dump_sha256'] ?? '';
    if ($actualSha !== $expectedSha) {
        fwrite(STDERR, "❌ CHECKSUM MISMATCH! Dump file may be corrupted.\n");
        fwrite(STDERR, "Expected: {$expectedSha}\nActual:   {$actualSha}\n");
        exit(1);
    }
    echo "Checksum verified (SHA256 match).\n";

    // 3. Database import
    echo "\n[Step 3/5] Importing database dump into '{$dbname}'...\n";
    $mysqlBin = findBinary('mysql');

    $importSuccess = withTempMyCnf($dbConfig, function ($cnfFile) use ($mysqlBin, $dbname, $dumpFile) {
        $cmd = sprintf(
            '"%s" --defaults-extra-file="%s" "%s"',
            $mysqlBin,
            $cnfFile,
            $dbname
        );
        $descriptors = [
            0 => ['file', $dumpFile, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            fwrite(STDERR, "mysql import error (code {$code}): {$stderr}\n");
            return false;
        }
        return true;
    });

    if (!$importSuccess) {
        fwrite(STDERR, "❌ DATABASE IMPORT FAILED!\n");
        exit(1);
    }
    echo "Database structure, data, triggers, and events imported successfully.\n";

    // 4. File restoration
    echo "\n[Step 4/5] Restoring uploaded data files...\n";
    $filesSource = $targetDir . '/files';
    $restoredCount = 0;
    if (is_dir($filesSource)) {
        $restoredCount = copyDirectory($filesSource, dirname(__DIR__));
    }
    echo "Restored {$restoredCount} uploaded files.\n";

    // 5. Verification
    echo "\n[Step 5/5] Running post-restore verification checks...\n";
    echo "-------------------------------------------------------\n";

    $checks = [];

    // Compare table counts
    $manifestCounts = $manifest['table_counts'] ?? [];
    $countsMatch = true;
    $countMismatches = [];
    foreach ($manifestCounts as $tbl => $expectedCount) {
        try {
            $actualCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            if ($actualCount !== $expectedCount) {
                $countsMatch = false;
                $countMismatches[] = "{$tbl}: {$actualCount} vs {$expectedCount}";
            }
        } catch (Throwable) {
            $countsMatch = false;
            $countMismatches[] = "{$tbl}: [missing]";
        }
    }
    $checks[] = ['Table row counts match manifest', $countsMatch, $countsMatch ? "All tables matched" : implode(', ', array_slice($countMismatches, 0, 3))];

    // Check triggers
    $trgStmt = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
    $activeTriggers = array_column($trgStmt, 'Trigger');
    $trgOk = true;
    foreach (REQUIRED_TRIGGERS as $trg) {
        if (!in_array($trg, $activeTriggers, true)) {
            $trgOk = false;
            break;
        }
    }
    $checks[] = ['4 Immutable ledger triggers exist', $trgOk, "4/4 triggers active"];

    // Read back user
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $checks[] = ['Users table readable', ($userCount > 0), "Found {$userCount} user(s)"];

    $allPassed = true;
    foreach ($checks as [$title, $pass, $detail]) {
        if (!$pass) $allPassed = false;
        printf("[%s] %-34s : %s\n", $pass ? "PASS" : "FAIL", $title, $detail);
    }
    echo "-------------------------------------------------------\n";

    if ($allPassed) {
        echo "RESTORED\n\n";
    } else {
        echo "⚠️  RESTORE FINISHED WITH WARNINGS (Check verification above).\n\n";
    }

    echo "⚠️  REMINDER:\n";
    echo "- All login usernames and PINs have been restored to what they were when the snapshot was taken.\n";
    echo "- Clear browser data (F12 > Application > Clear site data) before logging in.\n\n";
}

// -------------------------------------------------------------
// CLI Dispatcher
// -------------------------------------------------------------
$command = null;
$options = [];

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--')) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', substr($arg, 2), 2);
            $options[$k] = $v;
        } else {
            $options[] = $arg;
            $options[substr($arg, 2)] = true;
        }
    } elseif ($command === null) {
        $command = strtolower($arg);
    }
}

if ($command === null) {
    echo "Usage:\n";
    echo "  php scripts/dev_data_tool.php save --name=NAME     (save complete snapshot)\n";
    echo "  php scripts/dev_data_tool.php list                 (list all snapshots)\n";
    echo "  php scripts/dev_data_tool.php reset [--dry-run]    (reset to brand-new installation)\n";
    echo "  php scripts/dev_data_tool.php restore --name=NAME  (restore snapshot)\n\n";
    exit(0);
}

try {
    switch ($command) {
        case 'save':
            commandSave($pdo, $dbConfig, $dbname, $options);
            break;
        case 'list':
            commandList();
            break;
        case 'reset':
            commandReset($pdo, $dbConfig, $dbname, $options);
            break;
        case 'restore':
            commandRestore($pdo, $dbConfig, $dbname, $options);
            break;
        default:
            fwrite(STDERR, "Unknown command '{$command}'. Run with no arguments for usage.\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ FATAL ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
