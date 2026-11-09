<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockControl.php';
require_once __DIR__ . '/BayManager.php';
require_once __DIR__ . '/OrderGate.php';

class TechnicalManager {

    /**
     * Ensure a Job Order and its 2-lane tasks exist for the given order.
     */
    public static function ensureJobOrder(PDO $pdo, int $orderId, ?int $techId = null, ?string $techName = null): array {
        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order ID {$orderId} not found.");
        }

        // Generate job order UID if not set
        $jobUid = $order['job_order_uid'];
        if (empty($jobUid)) {
            $jobUid = generate_unique_id('JOB');
            $pdo->prepare("UPDATE orders SET job_order_uid = ? WHERE id = ?")->execute([$jobUid, $orderId]);
        }

        // Assign mechanic if provided
        if ($techId) {
            $chkOm = $pdo->prepare("SELECT COUNT(*) FROM order_mechanics WHERE order_id = ? AND mechanic_id = ?");
            $chkOm->execute([$orderId, $techId]);
            if (((int)$chkOm->fetchColumn()) === 0) {
                $pdo->prepare("INSERT INTO order_mechanics (order_id, mechanic_id, commission_usd) VALUES (?, ?, 0.00)")
                    ->execute([$orderId, $techId]);
            }
        }

        // Check existing tasks for this order
        $tasksStmt = $pdo->prepare("SELECT * FROM order_tasks WHERE order_id = ? ORDER BY sequence ASC, id ASC");
        $tasksStmt->execute([$orderId]);
        $existingTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch order items to determine services
        $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasOilService = false;
        $reservedOilLiters = 4.0; // default standard oil capacity
        foreach ($orderItems as $it) {
            $nameLower = strtolower($it['service_name'] ?? '');
            if (str_contains($nameLower, 'oil') || str_contains($nameLower, 'engine oil') || str_contains($nameLower, 'filter')) {
                $hasOilService = true;
                if (!empty($it['qty']) && (float)$it['qty'] > 0) {
                    $reservedOilLiters = (float)$it['qty'];
                }
            }
        }

        $lane1Exists = false;
        $lane2Exists = false;

        foreach ($existingTasks as $t) {
            if (($t['lane'] ?? 'lane_1') === 'lane_1') {
                $lane1Exists = true;
            }
            if (($t['lane'] ?? '') === 'lane_2' || $t['task_type'] === 'inspection') {
                $lane2Exists = true;
            }
        }

        // Create Lane 1: Mechanical / Oil Drain & Refill task if missing
        if (!$lane1Exists) {
            $title = $hasOilService ? 'Engine Oil Drain & Refill' : 'Mechanical Service & Repair';
            $taskType = $hasOilService ? 'oil_change' : 'repair';
            $pdo->prepare("
                INSERT INTO order_tasks (
                    order_id, task_type, title, sequence, lane, status, bay_id,
                    assigned_user_id, reserved_oil_liters, actual_oil_used, parts_status, created_at, updated_at
                ) VALUES (?, ?, ?, 1, 'lane_1', 'waiting', ?, ?, ?, ?, 'none', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([$orderId, $taskType, $title, $order['bay_id'] ?? 4, $techId, $reservedOilLiters, $reservedOilLiters]);
        }

        // Create Lane 2: Guided Multi-Point Inspection task if missing
        if (!$lane2Exists) {
            $pdo->prepare("
                INSERT INTO order_tasks (
                    order_id, task_type, title, sequence, lane, status, bay_id,
                    assigned_user_id, parts_status, created_at, updated_at
                ) VALUES (?, 'inspection', 'Guided Multi-Point Safety Inspection', 2, 'lane_2', 'waiting', ?, ?, 'none', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([$orderId, $order['bay_id'] ?? 4, $techId]);
        }

        // Ensure default guided inspection checklist items exist
        self::ensureDefaultChecklist($pdo, $orderId);

        AuditLogger::log('JOB_ORDER_CREATED', "Job Order {$jobUid} initialized with 2 simultaneous lanes (Oil/Mechanical & Guided Inspection) for vehicle {$order['plate_number']}", 'order', (string)$orderId, $techId, $techName ?? 'Technician');

        return [
            'success' => true,
            'job_order_uid' => $jobUid,
            'order_id' => $orderId
        ];
    }

    /**
     * Ensure standard guided inspection items exist for an order.
     */
    public static function ensureDefaultChecklist(PDO $pdo, int $orderId): void {
        $chkCount = (int)$pdo->query("SELECT COUNT(*) FROM inspection_checklists WHERE order_id = {$orderId}")->fetchColumn();
        if ($chkCount > 0) {
            return;
        }

        $defaultItems = [
            [
                'key' => 'brake_pad_thickness',
                'name' => 'Brake Pad Thickness (mm)',
                'mandatory' => 1,
                'notes' => 'Minimum safe thickness 3.0mm'
            ],
            [
                'key' => 'tire_tread_depth',
                'name' => 'Tire Tread Depth (mm)',
                'mandatory' => 1,
                'notes' => 'Minimum legal depth 2.0mm'
            ],
            [
                'key' => 'battery_health',
                'name' => 'Battery Voltage & Terminals',
                'mandatory' => 1,
                'notes' => 'Check cranking voltage (12.4V - 12.8V) and terminal corrosion'
            ],
            [
                'key' => 'underbody_leaks',
                'name' => 'Underbody & Oil Filter Seal Check',
                'mandatory' => 1,
                'notes' => 'Verify drain plug washer and filter rubber gasket seal'
            ],
        ];

        $ins = $pdo->prepare("
            INSERT INTO inspection_checklists (
                order_id, item_key, item_name, status, is_mandatory, notes, created_at, updated_at
            ) VALUES (?, ?, ?, 'pending', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        foreach ($defaultItems as $item) {
            $ins->execute([$orderId, $item['key'], $item['name'], $item['mandatory'], $item['notes']]);
        }
    }

    /**
     * Start a specific task.
     */
    public static function startTask(
        PDO $pdo,
        int $taskId,
        int $userId,
        string $userName,
        ?string $managerPin = null,
        ?string $overrideReason = null,
        ?string $managerUsername = null
    ): array {
        $tStmt = $pdo->prepare("SELECT * FROM order_tasks WHERE id = ?");
        $tStmt->execute([$taskId]);
        $task = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            throw new Exception("Task ID {$taskId} not found.");
        }

        $orderId = (int)$task['order_id'];

        // Enforce "No receipt, no wash/work"
        OrderGate::assertReceiptPrinted($pdo, $orderId, $managerPin, $overrideReason, $managerUsername, $userId, $userName, 'mechanic');

        $pdo->prepare("
            UPDATE order_tasks 
            SET status = 'in_progress', started_at = COALESCE(started_at, CURRENT_TIMESTAMP), assigned_user_id = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$userId, $taskId]);

        // Keep order repair_status in 'working'
        $pdo->prepare("UPDATE orders SET repair_status = 'working', started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = ?")
            ->execute([$orderId]);

        AuditLogger::log('TASK_STARTED', "Technician started task '{$task['title']}' (Lane: {$task['lane']}) on Order #{$orderId}", 'order_task', (string)$taskId, $userId, $userName);

        return ['success' => true, 'message' => "Task '{$task['title']}' is now in progress."];
    }

    /**
     * Update an inspection checklist item.
     */
    public static function updateChecklistItem(
        PDO $pdo,
        int $itemId,
        string $status,
        ?string $measurement = null,
        ?string $photoUrl = null,
        ?string $notes = null,
        ?int $userId = null,
        ?string $userName = null
    ): array {
        $validStatuses = ['pass', 'fail', 'pending'];
        if (!in_array($status, $validStatuses, true)) {
            throw new Exception("Invalid inspection status: {$status}");
        }

        $stmt = $pdo->prepare("SELECT * FROM inspection_checklists WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception("Checklist item ID {$itemId} not found.");
        }

        // Keep existing photo if new one is not uploaded
        $finalPhotoUrl = $photoUrl ?: ($item['photo_url'] ?? null);

        $pdo->prepare("
            UPDATE inspection_checklists
            SET status = ?, measurement_value = ?, photo_url = ?, notes = ?, checked_by_id = ?, checked_by_name = ?, checked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$status, $measurement, $finalPhotoUrl, $notes, $userId, $userName, $itemId]);

        AuditLogger::log('INSPECTION_ITEM_UPDATED', "Inspection item '{$item['item_name']}' set to {$status} (Val: {$measurement}) by {$userName}", 'inspection', (string)$itemId, $userId, $userName);

        return [
            'success' => true,
            'item_id' => $itemId,
            'status' => $status,
            'photo_url' => $finalPhotoUrl,
            'message' => "Checklist item '{$item['item_name']}' updated to {$status}."
        ];
    }

    /**
     * Validate that all mandatory checklist items are PASSED with photos.
     * Throws Exception or returns array.
     */
    public static function validateInspectionChecklist(PDO $pdo, int $orderId): array {
        $stmt = $pdo->prepare("SELECT * FROM inspection_checklists WHERE order_id = ? AND is_mandatory = 1");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['passed' => true, 'count' => 0];
        }

        foreach ($items as $it) {
            if ($it['status'] !== 'pass') {
                return [
                    'passed' => false,
                    'error' => 'MANDATORY_INSPECTION_INCOMPLETE',
                    'blocking_item' => $it,
                    'message' => "Cannot finish job: Mandatory inspection item '{$it['item_name']}' has not passed (Current status: " . strtoupper($it['status']) . "). Every mandatory item must be marked 'PASS'."
                ];
            }
            if (empty($it['photo_url'])) {
                return [
                    'passed' => false,
                    'error' => 'MANDATORY_PHOTO_REQUIRED',
                    'blocking_item' => $it,
                    'message' => "Cannot finish job: Mandatory inspection item '{$it['item_name']}' requires an inspection photo before completion."
                ];
            }
        }

        return ['passed' => true, 'count' => count($items)];
    }

    /**
     * Adjust oil quantity if actual amount used differs from reserved amount.
     * Automatically adjusts stock deduction and updates order item.
     */
    public static function adjustOilUsage(PDO $pdo, int $orderId, float $actualOilUsed, int $userId, string $userName): array {
        if ($actualOilUsed <= 0) {
            throw new Exception("Actual oil used must be greater than zero.");
        }

        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order ID {$orderId} not found.");
        }

        // Find oil item on order
        $itStmt = $pdo->prepare("
            SELECT * FROM order_items 
            WHERE order_id = ? AND (product_id IS NOT NULL OR LOWER(service_name) LIKE '%oil%') 
            ORDER BY id ASC LIMIT 1
        ");
        $itStmt->execute([$orderId]);
        $oilItem = $itStmt->fetch(PDO::FETCH_ASSOC);

        $reservedQty = (float)($oilItem['qty'] ?? 4.0);
        $diff = round($actualOilUsed - $reservedQty, 2);

        // Update actual_oil_used on orders and order_tasks
        $pdo->prepare("UPDATE orders SET actual_oil_used = ? WHERE id = ?")->execute([$actualOilUsed, $orderId]);
        $pdo->prepare("UPDATE order_tasks SET actual_oil_used = ? WHERE order_id = ? AND task_type = 'oil_change'")
            ->execute([$actualOilUsed, $orderId]);

        // If actual exceeds reserved, deduct extra oil from inventory ledger
        if ($diff > 0 && !empty($oilItem['product_id'])) {
            $prodId = (int)$oilItem['product_id'];
            $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty FROM products WHERE id = ?");
            $pStmt->execute([$prodId]);
            $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

            if ($prod) {
                $prevStock = (int)$prod['stock_qty'];
                $extraToDeduct = (int)ceil($diff); // Can/liter unit
                $newStock = max(0, $prevStock - $extraToDeduct);

                $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?")->execute([$newStock, $prodId]);

                StockControl::addLedgerEntry(
                    $prodId,
                    $prod['name_en'],
                    'used',
                    (float)$extraToDeduct,
                    (float)$prevStock,
                    (float)$newStock,
                    $order['order_number'],
                    "Extra oil consumed ({$actualOilUsed}L used vs {$reservedQty}L reserved, +{$diff}L) on Order {$order['order_number']}",
                    $userId
                );
            }
        }

        // Update order_items quantity and line total if product item
        if ($oilItem) {
            $unitPrice = (float)$oilItem['price_usd'] / max(1.0, (float)$oilItem['qty']);
            $newPrice = round($unitPrice * $actualOilUsed, 2);
            $pdo->prepare("UPDATE order_items SET qty = ?, price_usd = ? WHERE id = ?")
                ->execute([$actualOilUsed, $newPrice, $oilItem['id']]);

            // Re-sum total on order
            $sum = (float)$pdo->query("SELECT COALESCE(SUM(price_usd), 0) FROM order_items WHERE order_id = {$orderId}")->fetchColumn();
            $pdo->prepare("UPDATE orders SET subtotal_usd = ?, total_usd = ? - COALESCE(discount_usd, 0) WHERE id = ?")
                ->execute([$sum, $sum, $orderId]);
        }

        AuditLogger::log('OIL_ADJUSTED', "Technician {$userName} adjusted oil from {$reservedQty}L to {$actualOilUsed}L on Order #{$orderId} (diff: {$diff}L). Stock deduction updated.", 'order', (string)$orderId, $userId, $userName);

        return [
            'success' => true,
            'order_id' => $orderId,
            'previous_reserved' => $reservedQty,
            'actual_oil_used' => $actualOilUsed,
            'difference' => $diff,
            'message' => "Oil usage adjusted to {$actualOilUsed}L. Inventory deduction follows adjusted amount."
        ];
    }

    /**
     * Finish a single task (Lane 1 or Lane 2).
     */
    public static function finishTask(
        PDO $pdo,
        int $taskId,
        int $userId,
        string $userName,
        ?float $actualOilUsed = null
    ): array {
        $tStmt = $pdo->prepare("SELECT * FROM order_tasks WHERE id = ?");
        $tStmt->execute([$taskId]);
        $task = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            throw new Exception("Task ID {$taskId} not found.");
        }

        $orderId = (int)$task['order_id'];

        // If Lane 2 (Inspection) or inspection task: strictly validate mandatory checklist items
        if ($task['task_type'] === 'inspection' || $task['lane'] === 'lane_2') {
            $check = self::validateInspectionChecklist($pdo, $orderId);
            if (!$check['passed']) {
                throw new Exception($check['message']);
            }
        }

        // If Lane 1 / Oil change: handle oil adjustment if provided
        if ($task['task_type'] === 'oil_change' && $actualOilUsed !== null && $actualOilUsed > 0) {
            self::adjustOilUsage($pdo, $orderId, $actualOilUsed, $userId, $userName);
        }

        $pdo->prepare("
            UPDATE order_tasks 
            SET status = 'completed', completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ")->execute([$taskId]);

        AuditLogger::log('TASK_FINISHED', "Technician completed task '{$task['title']}' (Lane: {$task['lane']}) on Order #{$orderId}", 'order_task', (string)$taskId, $userId, $userName);

        return [
            'success' => true,
            'task_id' => $taskId,
            'message' => "Task '{$task['title']}' completed successfully."
        ];
    }

    /**
     * Finish the entire Technical Job Order.
     * Enforces that ALL mandatory inspection items have passed with photos.
     */
    public static function finishJobOrder(
        PDO $pdo,
        int $orderId,
        int $userId,
        string $userName,
        string $userRole = 'technical',
        ?int $tookLonger = null
    ): array {
        // 1. Mandatory Checklist Verification
        $check = self::validateInspectionChecklist($pdo, $orderId);
        if (!$check['passed']) {
            throw new Exception($check['message']);
        }

        // 2. Verify all technical child tasks are completed (excluding pending wash tasks belonging to Step 2)
        $pendingTasks = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = {$orderId} AND status != 'completed' AND task_type != 'wash'")->fetchColumn();
        if ($pendingTasks > 0) {
            throw new Exception("Cannot finish job: There are {$pendingTasks} unfinished technical tasks in progress. All tasks in both lanes must be finished first.");
        }

        // 3. Mark Order as Finished & Free Bay (hands over to wash queue if mixed)
        $finishRes = BayManager::finishCar($pdo, null, $orderId, $userId, $userName, $userRole, $tookLonger);
        if (empty($finishRes['success'])) {
            throw new Exception($finishRes['message'] ?? 'Could not complete job order.');
        }

        AuditLogger::log('JOB_ORDER_FINISHED', "Technician {$userName} finished Job Order on vehicle Order #{$orderId}. All mandatory checklist items verified.", 'order', (string)$orderId, $userId, $userName, $userRole);

        $msg = !empty($finishRes['is_handshake'])
            ? "Mechanical service finished! Vehicle has joined the Wash Queue."
            : "Technical Job Order completed! Vehicle is ready for cashier checkout.";

        return [
            'success' => true,
            'order_id' => $orderId,
            'is_handshake' => !empty($finishRes['is_handshake']),
            'message' => $msg
        ];
    }

    /**
     * Technician requests spare parts or materials from Warehouse / Stock Control.
     */
    public static function requestParts(
        PDO $pdo,
        int $orderId,
        int $productId,
        int $qty,
        int $userId,
        string $userName,
        ?int $taskId = null,
        ?string $notes = null
    ): array {
        if ($qty <= 0) {
            throw new Exception("Parts request quantity must be at least 1.");
        }

        $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty FROM products WHERE id = ? AND is_active = 1");
        $pStmt->execute([$productId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("Product ID {$productId} not found in inventory catalog.");
        }

        $reqUid = generate_unique_id('REQ');

        $stmt = $pdo->prepare("
            INSERT INTO parts_requests (
                request_uid, order_id, task_id, product_id, product_name, qty_requested,
                status, requested_by_id, requested_by_name, requested_at, notes, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, CURRENT_TIMESTAMP, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $reqUid,
            $orderId,
            $taskId,
            $productId,
            $prod['name_en'],
            $qty,
            $userId,
            $userName,
            $notes
        ]);

        // Flag order as having pending parts request
        $pdo->prepare("UPDATE orders SET has_pending_parts_request = 1 WHERE id = ?")->execute([$orderId]);

        // Update task status to waiting_parts
        if ($taskId) {
            $pdo->prepare("UPDATE order_tasks SET parts_status = 'waiting_parts' WHERE id = ?")->execute([$taskId]);
        } else {
            $pdo->prepare("UPDATE order_tasks SET parts_status = 'waiting_parts' WHERE order_id = ? AND status != 'completed'")->execute([$orderId]);
        }

        AuditLogger::log('PARTS_REQUESTED', "Technician {$userName} requested {$qty}x '{$prod['name_en']}' for Order #{$orderId} ({$reqUid})", 'parts_request', $reqUid, $userId, $userName);

        return [
            'success' => true,
            'request_uid' => $reqUid,
            'product_name' => $prod['name_en'],
            'qty' => $qty,
            'status' => 'pending',
            'message' => "Parts request for {$qty}x '{$prod['name_en']}' submitted. Waiting for Stock Control approval."
        ];
    }

    /**
     * Stock Control approves parts request.
     * Moves stock out with an immutable stock history ledger entry.
     */
    public static function approvePartsRequest(
        PDO $pdo,
        int $requestId,
        int $reviewerId,
        string $reviewerName,
        string $reviewerRole = 'stock_control'
    ): array {
        $stmt = $pdo->prepare("SELECT * FROM parts_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            throw new Exception("Parts request ID {$requestId} not found.");
        }

        if ($req['status'] !== 'pending') {
            throw new Exception("Parts request is already {$req['status']}.");
        }

        $prodId = (int)$req['product_id'];
        $qty = (int)$req['qty_requested'];
        $orderId = (int)$req['order_id'];

        $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty, price_usd FROM products WHERE id = ?");
        $pStmt->execute([$prodId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("Product ID {$prodId} not found.");
        }

        $prevStock = (int)$prod['stock_qty'];
        if ($prevStock < $qty) {
            throw new Exception("Cannot approve: Physical stock ({$prevStock}) is insufficient for requested quantity ({$qty}).");
        }

        $newStock = max(0, $prevStock - $qty);

        // Deduct physical stock
        $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?")->execute([$newStock, $prodId]);

        // Get order number for ledger reference
        $oNum = (string)$pdo->query("SELECT order_number FROM orders WHERE id = {$orderId}")->fetchColumn();

        // Immutable stock movement ledger entry
        $movUid = StockControl::addLedgerEntry(
            $prodId,
            $prod['name_en'],
            'used',
            (float)$qty,
            (float)$prevStock,
            (float)$newStock,
            $oNum ?: "ORD-{$orderId}",
            "Parts request {$req['request_uid']} approved by {$reviewerName} ({$reviewerRole}) for Order #{$orderId}",
            $reviewerId
        );

        // Update parts_request status to approved
        $pdo->prepare("
            UPDATE parts_requests 
            SET status = 'approved', reviewed_by_id = ?, reviewed_by_name = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$reviewerId, $reviewerName, $requestId]);

        // Add part to order_items so it is billed/tracked on the order
        $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, service_name, item_type, qty, price_usd)
            VALUES (?, ?, ?, 'spare_part', ?, ?)
        ")->execute([$orderId, $prodId, $prod['name_en'], $qty, (float)$prod['price_usd'] * $qty]);

        // Check if other pending requests remain for this order
        $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM parts_requests WHERE order_id = {$orderId} AND status = 'pending'")->fetchColumn();
        if ($pendingCount === 0) {
            $pdo->prepare("UPDATE orders SET has_pending_parts_request = 0 WHERE id = ?")->execute([$orderId]);
        }

        // Update tasks parts_status to 'parts_received'
        $pdo->prepare("UPDATE order_tasks SET parts_status = 'parts_received' WHERE order_id = ?")->execute([$orderId]);

        AuditLogger::log('PARTS_APPROVED', "Stock Control {$reviewerName} approved {$qty}x '{$prod['name_en']}' for Order #{$orderId} ({$req['request_uid']}). Stock deducted from {$prevStock} to {$newStock}.", 'parts_request', $req['request_uid'], $reviewerId, $reviewerName, $reviewerRole);

        return [
            'success' => true,
            'request_uid' => $req['request_uid'],
            'movement_uid' => $movUid,
            'product_name' => $prod['name_en'],
            'new_stock' => $newStock,
            'message' => "Parts request approved! Stock moved out and technician notified ('Parts Received')."
        ];
    }

    /**
     * Stock Control rejects parts request with mandatory reason.
     */
    public static function rejectPartsRequest(
        PDO $pdo,
        int $requestId,
        string $reason,
        int $reviewerId,
        string $reviewerName,
        string $reviewerRole = 'stock_control'
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("A rejection reason is strictly required.");
        }

        $stmt = $pdo->prepare("SELECT * FROM parts_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            throw new Exception("Parts request ID {$requestId} not found.");
        }

        if ($req['status'] !== 'pending') {
            throw new Exception("Parts request is already {$req['status']}.");
        }

        $orderId = (int)$req['order_id'];

        $pdo->prepare("
            UPDATE parts_requests
            SET status = 'rejected', review_reason = ?, reviewed_by_id = ?, reviewed_by_name = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$reason, $reviewerId, $reviewerName, $requestId]);

        // Check if other pending requests remain for this order
        $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM parts_requests WHERE order_id = {$orderId} AND status = 'pending'")->fetchColumn();
        if ($pendingCount === 0) {
            $pdo->prepare("UPDATE orders SET has_pending_parts_request = 0 WHERE id = ?")->execute([$orderId]);
        }

        // Update tasks parts_status to 'request_rejected'
        $pdo->prepare("UPDATE order_tasks SET parts_status = 'request_rejected' WHERE order_id = ?")->execute([$orderId]);

        AuditLogger::log('PARTS_REJECTED', "Stock Control {$reviewerName} rejected parts request {$req['request_uid']} for Order #{$orderId}. Reason: {$reason}", 'parts_request', $req['request_uid'], $reviewerId, $reviewerName, $reviewerRole);

        return [
            'success' => true,
            'request_uid' => $req['request_uid'],
            'reason' => $reason,
            'message' => "Parts request rejected with reason: '{$reason}'."
        ];
    }

    /**
     * Stock Control logs physical inventory count discrepancy.
     */
    public static function logDiscrepancy(
        PDO $pdo,
        int $productId,
        int $countedQty,
        string $reason,
        int $userId,
        string $userName,
        string $userRole = 'stock_control'
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("A detailed reason is required to log a stock discrepancy.");
        }

        $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty FROM products WHERE id = ?");
        $pStmt->execute([$productId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("Product ID {$productId} not found.");
        }

        $systemQty = (int)$prod['stock_qty'];
        $diff = $countedQty - $systemQty;

        $discUid = generate_unique_id('DISC');
        $deviceName = $_SERVER['HTTP_USER_AGENT'] ?? 'BackOffice';

        $pdo->prepare("
            INSERT INTO stock_discrepancies (
                discrepancy_uid, product_id, product_name, system_qty, counted_qty,
                difference_qty, reason, logged_by_id, logged_by_name, logged_by_role,
                logged_at, device_name, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, 1)
        ")->execute([
            $discUid,
            $productId,
            $prod['name_en'],
            $systemQty,
            $countedQty,
            $diff,
            $reason,
            $userId,
            $userName,
            $userRole,
            $deviceName
        ]);

        // Adjust physical stock in products table
        $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?")->execute([$countedQty, $productId]);

        // Write correcting line into immutable stock_movements ledger
        $movUid = StockControl::addLedgerEntry(
            $productId,
            $prod['name_en'],
            'adjusted',
            (float)abs($diff),
            (float)$systemQty,
            (float)$countedQty,
            $discUid,
            "Physical count audit discrepancy: {$reason} (Diff: {$diff}) logged by {$userName}",
            $userId
        );

        AuditLogger::log('STOCK_DISCREPANCY', "Stock Control {$userName} recorded physical count discrepancy for '{$prod['name_en']}'. System: {$systemQty}, Counted: {$countedQty}, Diff: {$diff}. Reason: {$reason}", 'product', (string)$productId, $userId, $userName, $userRole);

        return [
            'success' => true,
            'discrepancy_uid' => $discUid,
            'movement_uid' => $movUid,
            'product_name' => $prod['name_en'],
            'system_qty' => $systemQty,
            'counted_qty' => $countedQty,
            'difference' => $diff,
            'message' => "Physical count discrepancy logged. System stock updated from {$systemQty} to {$countedQty}."
        ];
    }
}
