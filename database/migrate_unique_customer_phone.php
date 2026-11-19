<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_unique_customer_phone(): bool {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();

    migrateLog("Starting Unique Customer Phone Migration...");

    // 1. Delete test customers #1 and #2 if they exist and have no orders or vehicles
    foreach ([1, 2] as $testId) {
        $c = $pdo->query("SELECT id, name, phone FROM customers WHERE id = {$testId}")->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $orderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_id = {$testId}")->fetchColumn();
            $vehCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE customer_id = {$testId}")->fetchColumn();
            
            if ($orderCount === 0 && $vehCount === 0) {
                // Delete any test loyalty transactions if any exist
                try {
                    $pdo->exec("DELETE FROM loyalty_transactions WHERE customer_id = {$testId}");
                } catch (Throwable $e) {}
                
                $pdo->exec("DELETE FROM customers WHERE id = {$testId}");
                migrateLog("Deleted unused test customer #{$testId} ('{$c['name']}', phone: {$c['phone']}).");
            } else {
                migrateLog("SKIPPED customer #{$testId}: Customer has {$orderCount} orders or {$vehCount} vehicles.");
            }
        }
    }

    // 2. Ensure empty or whitespace-only phone strings are normalized to NULL
    $updated = $pdo->exec("UPDATE customers SET phone = NULL WHERE phone IS NOT NULL AND TRIM(phone) = ''");
    if ($updated > 0) {
        migrateLog("Sanitized {$updated} blank phone values to NULL.");
    }

    // 3. Add UNIQUE constraint to customers(phone)
    if ($driver === 'mysql') {
        $indexes = $pdo->query("SHOW INDEX FROM customers WHERE Key_name = 'uniq_customer_phone'")->fetchAll();
        if (empty($indexes)) {
            $pdo->exec("ALTER TABLE customers ADD UNIQUE KEY uniq_customer_phone (phone)");
            migrateLog("Added UNIQUE KEY 'uniq_customer_phone' to customers(phone).");
        } else {
            migrateLog("UNIQUE KEY 'uniq_customer_phone' already exists.");
        }
    } else {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_customer_phone ON customers(phone)");
        migrateLog("Created UNIQUE INDEX 'uniq_customer_phone' on customers(phone).");
    }

    // 4. Record migration in schema_migrations
    try {
        $stmt = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
        $stmt->execute(['migrate_unique_customer_phone']);
    } catch (Throwable $e) {
        // already recorded
    }

    migrateLog("Unique Customer Phone Migration completed successfully.");
    return true;
}

// Execute if run directly from CLI
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    try {
        migrate_unique_customer_phone();
        echo "Migration finished.\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}
