<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This test script must be executed via CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/database/migrate_helpers.php';
require_once dirname(__DIR__) . '/database/migrate_immutable_ledgers.php';

$pdo = Database::getInstance();

echo "========================================================\n";
echo "Immutable Ledger Triggers Test Suite (CLI)\n";
echo "Testing: stock_movements and audit_logs\n";
echo "========================================================\n\n";

$allPassed = true;
$stkUid = 'TEST_STK_' . bin2hex(random_bytes(8));
$audUid = 'TEST_AUD_' . bin2hex(random_bytes(8));
$stkId = null;
$audId = null;

try {
    // ----------------------------------------------------
    // TEST 1: INSERT into stock_movements => works
    // ----------------------------------------------------
    try {
        $ins = $pdo->prepare("
            INSERT INTO stock_movements (
                movement_uid, product_id, product_name, movement_type,
                quantity, previous_qty, new_qty, reference_id, notes,
                is_active, is_synced, created_at
            ) VALUES (
                ?, 1, 'Test Product', 'adjusted',
                5.00, 10.00, 15.00, 'TEST_REF', 'Initial test entry',
                1, 0, NOW()
            )
        ");
        $ins->execute([$stkUid]);
        $stkId = (int)$pdo->lastInsertId();

        if ($stkId > 0) {
            echo "[PASS] Test 1: INSERT into stock_movements => works (ID: {$stkId})\n";
        } else {
            echo "[FAIL] Test 1: INSERT succeeded but no insert ID returned\n";
            $allPassed = false;
        }
    } catch (Throwable $e) {
        echo "[FAIL] Test 1: INSERT into stock_movements failed: " . $e->getMessage() . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // TEST 2: UPDATE of qty in stock_movements => fails
    // ----------------------------------------------------
    if ($stkId) {
        try {
            $pdo->prepare("UPDATE stock_movements SET quantity = 99.00 WHERE id = ?")->execute([$stkId]);
            echo "[FAIL] Test 2: UPDATE of qty unexpectedly succeeded (trigger did not block)\n";
            $allPassed = false;
        } catch (PDOException $e) {
            if ($e->getCode() === '45000' || str_contains($e->getMessage(), 'Ledger rows are immutable')) {
                echo "[PASS] Test 2: UPDATE of qty => fails (Blocked: {$e->getMessage()})\n";
            } else {
                echo "[FAIL] Test 2: UPDATE failed with unexpected code/message: " . $e->getMessage() . "\n";
                $allPassed = false;
            }
        }
    }

    // ----------------------------------------------------
    // TEST 3: UPDATE of is_synced in stock_movements => works
    // ----------------------------------------------------
    if ($stkId) {
        try {
            $upd = $pdo->prepare("UPDATE stock_movements SET is_synced = 1, synced_at = NOW() WHERE id = ?");
            $upd->execute([$stkId]);
            $chk = $pdo->prepare("SELECT is_synced FROM stock_movements WHERE id = ?");
            $chk->execute([$stkId]);
            $val = (int)$chk->fetchColumn();

            if ($val === 1) {
                echo "[PASS] Test 3: UPDATE of is_synced => works (sync flags allowed)\n";
            } else {
                echo "[FAIL] Test 3: UPDATE succeeded but is_synced was not updated\n";
                $allPassed = false;
            }
        } catch (Throwable $e) {
            echo "[FAIL] Test 3: UPDATE of is_synced failed: " . $e->getMessage() . "\n";
            $allPassed = false;
        }
    }

    // ----------------------------------------------------
    // TEST 4: DELETE from stock_movements => fails
    // ----------------------------------------------------
    if ($stkId) {
        try {
            $pdo->prepare("DELETE FROM stock_movements WHERE id = ?")->execute([$stkId]);
            echo "[FAIL] Test 4: DELETE unexpectedly succeeded (trigger did not block)\n";
            $allPassed = false;
        } catch (PDOException $e) {
            if ($e->getCode() === '45000' || str_contains($e->getMessage(), 'Ledger rows are immutable')) {
                echo "[PASS] Test 4: DELETE => fails (Blocked: {$e->getMessage()})\n";
            } else {
                echo "[FAIL] Test 4: DELETE failed with unexpected code/message: " . $e->getMessage() . "\n";
                $allPassed = false;
            }
        }
    }

    // ----------------------------------------------------
    // TEST 5: INSERT into audit_logs => works
    // ----------------------------------------------------
    try {
        $insAud = $pdo->prepare("
            INSERT INTO audit_logs (
                log_uid, action, entity_type, details, is_synced, created_at
            ) VALUES (
                ?, 'TEST_IMMUTABLE', 'system', 'Test audit log entry', 0, NOW()
            )
        ");
        $insAud->execute([$audUid]);
        $audId = (int)$pdo->lastInsertId();

        if ($audId > 0) {
            echo "[PASS] Test 5: INSERT into audit_logs => works (ID: {$audId})\n";
        } else {
            echo "[FAIL] Test 5: INSERT into audit_logs failed\n";
            $allPassed = false;
        }
    } catch (Throwable $e) {
        echo "[FAIL] Test 5: INSERT into audit_logs failed: " . $e->getMessage() . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // TEST 6: UPDATE of details in audit_logs => fails
    // ----------------------------------------------------
    if ($audId) {
        try {
            $pdo->prepare("UPDATE audit_logs SET details = 'Hacked details' WHERE id = ?")->execute([$audId]);
            echo "[FAIL] Test 6: UPDATE of details unexpectedly succeeded\n";
            $allPassed = false;
        } catch (PDOException $e) {
            if ($e->getCode() === '45000' || str_contains($e->getMessage(), 'Ledger rows are immutable')) {
                echo "[PASS] Test 6: UPDATE of details in audit_logs => fails (Blocked: {$e->getMessage()})\n";
            } else {
                echo "[FAIL] Test 6: Unexpected error: " . $e->getMessage() . "\n";
                $allPassed = false;
            }
        }
    }

    // ----------------------------------------------------
    // TEST 7: UPDATE of is_synced in audit_logs => works
    // ----------------------------------------------------
    if ($audId) {
        try {
            $pdo->prepare("UPDATE audit_logs SET is_synced = 1, synced_at = NOW() WHERE id = ?")->execute([$audId]);
            $chk = $pdo->prepare("SELECT is_synced FROM audit_logs WHERE id = ?");
            $chk->execute([$audId]);
            $val = (int)$chk->fetchColumn();

            if ($val === 1) {
                echo "[PASS] Test 7: UPDATE of is_synced in audit_logs => works (sync flags allowed)\n";
            } else {
                echo "[FAIL] Test 7: UPDATE succeeded but is_synced was not updated\n";
                $allPassed = false;
            }
        } catch (Throwable $e) {
            echo "[FAIL] Test 7: UPDATE of is_synced in audit_logs failed: " . $e->getMessage() . "\n";
            $allPassed = false;
        }
    }

    // ----------------------------------------------------
    // TEST 8: DELETE from audit_logs => fails
    // ----------------------------------------------------
    if ($audId) {
        try {
            $pdo->prepare("DELETE FROM audit_logs WHERE id = ?")->execute([$audId]);
            echo "[FAIL] Test 8: DELETE from audit_logs unexpectedly succeeded\n";
            $allPassed = false;
        } catch (PDOException $e) {
            if ($e->getCode() === '45000' || str_contains($e->getMessage(), 'Ledger rows are immutable')) {
                echo "[PASS] Test 8: DELETE from audit_logs => fails (Blocked: {$e->getMessage()})\n";
            } else {
                echo "[FAIL] Test 8: Unexpected error: " . $e->getMessage() . "\n";
                $allPassed = false;
            }
        }
    }

} finally {
    // Clean up throwaway test records by temporarily dropping the delete trigger and restoring triggers
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS trg_stock_movements_before_delete");
        $pdo->exec("DROP TRIGGER IF EXISTS trg_audit_logs_before_delete");
        if ($stkId) {
            $pdo->prepare("DELETE FROM stock_movements WHERE id = ?")->execute([$stkId]);
        }
        if ($audId) {
            $pdo->prepare("DELETE FROM audit_logs WHERE id = ?")->execute([$audId]);
        }
        migrateImmutableLedgers(true);
    } catch (Throwable $e) {
        // Silently continue
    }
}

echo "\n========================================================\n";
if ($allPassed) {
    echo "ALL IMMUTABLE LEDGER TESTS PASSED!\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "SOME IMMUTABLE LEDGER TESTS FAILED!\n";
    echo "========================================================\n";
    exit(1);
}
