<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CashierControl.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Auth.php';

class StockControl {

    /**
     * Check if product has enough available stock (stock_qty - reserved_qty).
     */
    public static function checkAvailability(int $productId, int $requestedQty = 1): array {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, name_en, name_km, stock_qty, reserved_qty, is_active FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            return [
                'available'     => false,
                'error'         => 'PRODUCT_NOT_FOUND',
                'message'       => "Product ID {$productId} was not found."
            ];
        }

        $stockQty = (int)$prod['stock_qty'];
        $reservedQty = (int)($prod['reserved_qty'] ?? 0);
        $availableQty = max(0, $stockQty - $reservedQty);

        if ($stockQty <= 0 || $availableQty < $requestedQty) {
            return [
                'available'     => false,
                'error'         => 'OUT_OF_STOCK',
                'product_id'    => (int)$prod['id'],
                'product_name'  => $prod['name_en'],
                'stock_qty'     => $stockQty,
                'reserved_qty'  => $reservedQty,
                'available_qty' => $availableQty,
                'requested_qty' => $requestedQty,
                'message'       => "Product '{$prod['name_en']}' has zero available stock (Total: {$stockQty}, Reserved: {$reservedQty}). Sale is blocked unless overridden by Manager PIN."
            ];
        }

        return [
            'available'     => true,
            'product_id'    => (int)$prod['id'],
            'product_name'  => $prod['name_en'],
            'stock_qty'     => $stockQty,
            'reserved_qty'  => $reservedQty,
            'available_qty' => $availableQty,
            'requested_qty' => $requestedQty
        ];
    }

    /**
     * Reserve stock for an order when drafted/intake.
     * Decrements available stock by incrementing reserved_qty.
     */
    public static function reserveProduct(
        int $productId,
        int $qty,
        string $orderNumber,
        ?int $userId = null,
        bool $isManagerOverride = false,
        ?string $managerUsername = null,
        ?string $managerPin = null
    ): array {
        $pdo = Database::getInstance();

        $check = self::checkAvailability($productId, $qty);
        if (!$check['available']) {
            if (!$isManagerOverride) {
                throw new Exception($check['message']);
            }
            // Verify Manager PIN for override
            if (empty($managerUsername) || empty($managerPin)) {
                throw new Exception("Manager username and 6-digit PIN are required to override zero-stock sale for '{$check['product_name']}'.");
            }
            $mgr = CashierControl::verifyManagerPin($managerUsername, $managerPin);
            $overrideNote = " [Manager Override by {$mgr['manager_name']}]";
            AuditLogger::log('STOCK_OVERRIDE', "Zero-stock sale overridden for product '{$check['product_name']}' on order {$orderNumber} by Manager {$mgr['manager_name']}", 'product', (string)$productId);
        } else {
            $overrideNote = "";
        }

        $prodStmt = $pdo->prepare("SELECT id, name_en, stock_qty, reserved_qty FROM products WHERE id = ?");
        $prodStmt->execute([$productId]);
        $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);

        $prevStock = (int)$prod['stock_qty'];
        $prevReserved = (int)($prod['reserved_qty'] ?? 0);
        $newReserved = $prevReserved + $qty;
        $prevAvailable = max(0, $prevStock - $prevReserved);
        $newAvailable = max(0, $prevStock - $newReserved);

        // Update reserved_qty
        $pdo->prepare("UPDATE products SET reserved_qty = ? WHERE id = ?")->execute([$newReserved, $productId]);

        // Record in ledger
        $uid = self::addLedgerEntry(
            $productId,
            $prod['name_en'],
            'reserved',
            (float)$qty,
            (float)$prevAvailable,
            (float)$newAvailable,
            $orderNumber,
            "Stock reserved for order {$orderNumber}" . $overrideNote,
            $userId
        );

        return [
            'success'       => true,
            'movement_uid'  => $uid,
            'product_id'    => $productId,
            'reserved_qty'  => $qty,
            'new_available' => $newAvailable
        ];
    }

    /**
     * Release reserved stock when an order is cancelled.
     */
    public static function releaseOrderReservations(int $orderId, string $orderNumber, ?int $userId = null, string $reason = 'Order cancelled'): void {
        $pdo = Database::getInstance();
        $itemsStmt = $pdo->prepare("SELECT product_id, service_name, qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $pId = (int)$item['product_id'];
            $qty = (int)$item['qty'];
            if ($pId <= 0 || $qty <= 0) continue;

            $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty, reserved_qty FROM products WHERE id = ?");
            $pStmt->execute([$pId]);
            $p = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) continue;

            $prevStock = (int)$p['stock_qty'];
            $prevReserved = (int)($p['reserved_qty'] ?? 0);
            $newReserved = max(0, $prevReserved - $qty);
            $prevAvailable = max(0, $prevStock - $prevReserved);
            $newAvailable = max(0, $prevStock - $newReserved);

            $pdo->prepare("UPDATE products SET reserved_qty = ? WHERE id = ?")->execute([$newReserved, $pId]);

            self::addLedgerEntry(
                $pId,
                $p['name_en'],
                'released',
                (float)$qty,
                (float)$prevAvailable,
                (float)$newAvailable,
                $orderNumber,
                "Stock reservation released: {$reason}",
                $userId
            );
        }
    }

    /**
     * Commit reserved stock into used/sold when order payment is confirmed.
     */
    public static function commitOrderStock(int $orderId, string $orderNumber, ?int $userId = null): void {
        $pdo = Database::getInstance();
        $itemsStmt = $pdo->prepare("SELECT product_id, service_name, qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $pId = (int)$item['product_id'];
            $qty = (int)$item['qty'];
            if ($pId <= 0 || $qty <= 0) continue;

            $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty, reserved_qty FROM products WHERE id = ?");
            $pStmt->execute([$pId]);
            $p = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) continue;

            $prevStock = (int)$p['stock_qty'];
            $prevReserved = (int)($p['reserved_qty'] ?? 0);
            $newStock = max(0, $prevStock - $qty);
            $newReserved = max(0, $prevReserved - $qty);

            $pdo->prepare("UPDATE products SET stock_qty = ?, reserved_qty = ? WHERE id = ?")->execute([$newStock, $newReserved, $pId]);

            self::addLedgerEntry(
                $pId,
                $p['name_en'],
                'used',
                (float)$qty,
                (float)$prevStock,
                (float)$newStock,
                $orderNumber,
                "Stock used/sold on paid order {$orderNumber}",
                $userId
            );
        }
    }

    /**
     * Add a stock movement line to the immutable ledger.
     * Valid movement types: received, used, reserved, released, adjusted, returned
     */
    public static function addLedgerEntry(
        int $productId,
        string $productName,
        string $movementType,
        float $quantity,
        float $previousQty,
        float $newQty,
        ?string $referenceId = null,
        ?string $notes = null,
        ?int $userId = null
    ): string {
        $allowedTypes = ['received', 'used', 'reserved', 'released', 'adjusted', 'returned'];
        if (!in_array($movementType, $allowedTypes, true)) {
            $movementType = 'adjusted';
        }

        $pdo = Database::getInstance();
        $uid = generate_unique_id('STK');
        $uId = $userId ?? (class_exists('Auth') ? Auth::getUserId() : null);

        $performerName = 'System';
        if ($uId) {
            $uStmt = $pdo->prepare("SELECT full_name, username, role FROM users WHERE id = ?");
            $uStmt->execute([$uId]);
            $u = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $performerName = "{$u['full_name']} (@{$u['username']})";
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO stock_movements (
                movement_uid, product_id, product_name, movement_type, quantity,
                previous_qty, new_qty, reference_id, notes, performed_by, performer_name, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $uid,
            $productId,
            $productName,
            $movementType,
            $quantity,
            $previousQty,
            $newQty,
            $referenceId,
            $notes,
            $uId,
            $performerName
        ]);

        return $uid;
    }

    /**
     * Add a manual correcting line to the ledger (fix mistake without editing old lines).
     * Strictly requires Manager PIN authorization.
     */
    public static function addCorrectionLine(
        int $productId,
        int $correctNewBalance,
        string $reason,
        string $managerUsername,
        string $managerPin,
        ?int $userId = null
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("A detailed reason is required to add a stock correction line.");
        }

        $mgr = CashierControl::verifyManagerPin($managerUsername, $managerPin);

        $pdo = Database::getInstance();
        $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty FROM products WHERE id = ?");
        $pStmt->execute([$productId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("Product ID {$productId} not found.");
        }

        $prevStock = (int)$prod['stock_qty'];
        $diff = $correctNewBalance - $prevStock;

        // Update product balance
        $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?")->execute([$correctNewBalance, $productId]);

        $movementUid = self::addLedgerEntry(
            $productId,
            $prod['name_en'],
            'adjusted',
            (float)abs($diff),
            (float)$prevStock,
            (float)$correctNewBalance,
            "CORR-" . date('Ymd-His'),
            "Stock balance correction: {$reason} [Authorized by Manager {$mgr['manager_name']}]",
            $mgr['manager_id']
        );

        AuditLogger::log('STOCK_ADJUSTMENT', "Stock balance for '{$prod['name_en']}' corrected from {$prevStock} to {$correctNewBalance} (diff: {$diff}) by Manager {$mgr['manager_name']}. Reason: {$reason}", 'product', (string)$productId, $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        return [
            'success'       => true,
            'movement_uid'  => $movementUid,
            'product_name'  => $prod['name_en'],
            'prev_stock'    => $prevStock,
            'new_stock'     => $correctNewBalance,
            'difference'    => $diff,
            'manager_name'  => $mgr['manager_name'],
            'message'       => "Correcting line recorded in ledger. Product '{$prod['name_en']}' new balance is {$correctNewBalance}."
        ];
    }

    /**
     * Enforce immutability: Rejects any attempt to edit or delete stock movement lines.
     */
    public static function disallowMutation(string $attemptedAction): void {
        throw new Exception("Stock history lines cannot be {$attemptedAction}. The stock ledger is immutable. Fix mistakes by adding a correcting line.");
    }
}
