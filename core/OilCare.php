<?php
declare(strict_types=1);

/**
 * OilCare Service
 * Manages oil change tracking and cancellation reversals.
 */
class OilCare {
    /**
     * Reverses oil change log when an order is cancelled.
     * Only restores vehicle's previous oil mileage/date if the cancelled record
     * was the vehicle's most recent active one.
     *
     * @param PDO $pdo
     * @param int $orderId
     * @param string $reason
     * @return array
     */
    public static function reverseOilChangeForOrder(PDO $pdo, int $orderId, string $reason = 'Order cancelled'): array {
        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'Invalid order ID'];
        }

        // Check whether status column exists (in case migration has not run yet on this database)
        $hasStatusCol = false;
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM oil_change_logs LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
            $hasStatusCol = !empty($colCheck);
        } catch (Throwable $e) {
            $hasStatusCol = false;
        }

        // 1. Find active oil log tied to this order
        $logSql = $hasStatusCol
            ? "SELECT * FROM oil_change_logs WHERE order_id = ? AND (status IS NULL OR status != 'cancelled') LIMIT 1"
            : "SELECT * FROM oil_change_logs WHERE order_id = ? LIMIT 1";
        $logStmt = $pdo->prepare($logSql);
        $logStmt->execute([$orderId]);
        $oilLog = $logStmt->fetch(PDO::FETCH_ASSOC);

        if (!$oilLog) {
            return ['success' => true, 'reversed' => false, 'message' => 'No active oil change log found for order'];
        }

        $logId = (int)$oilLog['id'];
        $vehicleId = !empty($oilLog['vehicle_id']) ? (int)$oilLog['vehicle_id'] : null;
        $plateNumber = trim((string)($oilLog['plate_number'] ?? ''));

        // 2. Mark this oil change record as cancelled
        $cancelNote = " [CANCELLED: {$reason}]";
        if ($hasStatusCol) {
            $updateLogStmt = $pdo->prepare("
                UPDATE oil_change_logs 
                SET status = 'cancelled', 
                    notes = CONCAT(COALESCE(notes, ''), ?) 
                WHERE id = ?
            ");
            $updateLogStmt->execute([$cancelNote, $logId]);
        } else {
            $updateLogStmt = $pdo->prepare("
                UPDATE oil_change_logs 
                SET notes = CONCAT(COALESCE(notes, ''), ?) 
                WHERE id = ?
            ");
            $updateLogStmt->execute([$cancelNote, $logId]);
        }

        // If no vehicle_id or plate, nothing more to revert on vehicle table
        if (!$vehicleId && empty($plateNumber)) {
            return ['success' => true, 'reversed' => true, 'vehicle_updated' => false];
        }

        // 3. Check if any newer active log exists for this vehicle
        $statusClause = $hasStatusCol ? " AND (status IS NULL OR status != 'cancelled')" : "";
        $newerStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM oil_change_logs 
            WHERE (vehicle_id = ? OR (vehicle_id IS NULL AND plate_number = ?))
              {$statusClause}
              AND (oil_change_date > ? OR (oil_change_date = ? AND id > ?))
        ");
        $newerStmt->execute([$vehicleId, $plateNumber, $oilLog['oil_change_date'], $oilLog['oil_change_date'], $logId]);
        $hasNewerLog = ((int)$newerStmt->fetchColumn() > 0);

        if ($hasNewerLog) {
            // A more recent active oil change exists; do NOT alter vehicle's current mileage/date
            return [
                'success' => true,
                'reversed' => true,
                'vehicle_updated' => false,
                'reason' => 'Newer active oil change exists on vehicle'
            ];
        }

        // 4. Find vehicle's previous active oil log
        $prevStmt = $pdo->prepare("
            SELECT current_mileage, next_due_mileage, next_due_date 
            FROM oil_change_logs 
            WHERE (vehicle_id = ? OR (vehicle_id IS NULL AND plate_number = ?))
              AND id != ?
              {$statusClause}
            ORDER BY oil_change_date DESC, id DESC 
            LIMIT 1
        ");
        $prevStmt->execute([$vehicleId, $plateNumber, $logId]);
        $prevLog = $prevStmt->fetch(PDO::FETCH_ASSOC);

        // 5. Restore vehicle record
        if ($vehicleId) {
            if ($prevLog) {
                $vehUpdate = $pdo->prepare("
                    UPDATE vehicles 
                    SET last_mileage = ?, next_oil_mileage = ?, next_oil_date = ? 
                    WHERE id = ?
                ");
                $vehUpdate->execute([
                    $prevLog['current_mileage'],
                    $prevLog['next_due_mileage'],
                    $prevLog['next_due_date'],
                    $vehicleId
                ]);
            } else {
                // No prior active oil log exists on record
                $vehUpdate = $pdo->prepare("
                    UPDATE vehicles 
                    SET last_mileage = NULL, next_oil_mileage = NULL, next_oil_date = NULL 
                    WHERE id = ?
                ");
                $vehUpdate->execute([$vehicleId]);
            }
        }

        return [
            'success' => true,
            'reversed' => true,
            'vehicle_updated' => (bool)$vehicleId,
            'restored_from' => $prevLog ?: null
        ];
    }
}
