<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Helpers.php';

class CashierControl {

    /**
     * Verify Manager or Owner 6-digit PIN for sensitive action authorization.
     * Returns manager data on success, throws Exception on failure.
     */
    public static function verifyManagerPin(string $managerUsername, string $managerPin): array {
        $managerUsername = trim($managerUsername);
        $managerPin = trim($managerPin);

        if ($managerUsername === '' || $managerPin === '') {
            throw new Exception("Manager username and 6-digit PIN are required.");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, username, full_name, role, password_hash, status, failed_login_attempts, locked_until
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$managerUsername]);
        $mgr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mgr) {
            AuditLogger::log('MANAGER_PIN_FAILED', "Manager PIN verification failed for unknown username '{$managerUsername}'", 'auth');
            throw new Exception("Manager username '{$managerUsername}' not found.");
        }

        if (!in_array($mgr['role'], ['owner', 'manager', 'system_admin'], true)) {
            AuditLogger::log('MANAGER_PIN_FAILED', "User '{$managerUsername}' has role '{$mgr['role']}' and cannot authorize manager actions.", 'auth');
            throw new Exception("User '{$managerUsername}' does not have Manager or Owner privileges.");
        }

        if ($mgr['status'] !== 'active') {
            throw new Exception("Manager account '{$managerUsername}' is inactive.");
        }

        // Lock check
        if (!empty($mgr['locked_until']) && strtotime($mgr['locked_until']) > time()) {
            $remMins = (int)ceil((strtotime($mgr['locked_until']) - time()) / 60);
            throw new Exception("Manager account is temporarily locked ({$remMins} minutes remaining).");
        }

        if (!password_verify($managerPin, $mgr['password_hash'])) {
            $attempts = (int)$mgr['failed_login_attempts'] + 1;
            if ($attempts >= 5) {
                $lockUntil = date('Y-m-d H:i:s', time() + 600);
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?")->execute([$attempts, $lockUntil, $mgr['id']]);
                AuditLogger::log('ACCOUNT_LOCKED', "Manager account '{$managerUsername}' locked for 10 minutes due to 5 failed PIN attempts", 'auth', (string)$mgr['id']);
                throw new Exception("Manager account locked for 10 minutes due to 5 wrong PIN attempts.");
            } else {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")->execute([$attempts, $mgr['id']]);
                AuditLogger::log('MANAGER_PIN_FAILED', "Manager PIN authorization failed ({$attempts}/5) for '{$managerUsername}'", 'auth', (string)$mgr['id']);
                throw new Exception("Invalid Manager PIN. (" . (5 - $attempts) . " attempts remaining before lockout)");
            }
        }

        // Reset failed attempts on success
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$mgr['id']]);

        return [
            'manager_id'       => (int)$mgr['id'],
            'manager_username' => $mgr['username'],
            'username'         => $mgr['username'],
            'manager_name'     => $mgr['full_name'],
            'manager_role'     => $mgr['role']
        ];
    }

    /**
     * Start a new cashier shift with starting cash (change fund / float).
     */
    public static function startShift(
        int $cashierId,
        string $cashierName,
        float $openingUsd,
        int $openingKhr = 0,
        ?int $deviceId = null,
        ?string $deviceName = null,
        ?string $notes = null
    ): array {
        $pdo = Database::getInstance();

        // Check if there is already an active shift for this cashier
        $checkStmt = $pdo->prepare("SELECT id, shift_uid, started_at FROM cashier_shifts WHERE cashier_id = ? AND status = 'open' LIMIT 1");
        $checkStmt->execute([$cashierId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return [
                'success' => true,
                'already_active' => true,
                'shift_id' => (int)$existing['id'],
                'shift_uid' => $existing['shift_uid'],
                'message' => 'Shift is already open.'
            ];
        }

        $shiftUid = generate_unique_id('SFT');
        $stmt = $pdo->prepare("
            INSERT INTO cashier_shifts (
                shift_uid, pos_device_id, pos_device_name, cashier_id, cashier_name,
                started_at, opening_cash_usd, opening_cash_khr, status, notes, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, 'open', ?, 1, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $shiftUid,
            $deviceId,
            $deviceName,
            $cashierId,
            $cashierName,
            $openingUsd,
            $openingKhr,
            $notes
        ]);
        $shiftId = (int)$pdo->lastInsertId();

        AuditLogger::log('SHIFT_START', "Cashier {$cashierName} started shift with starting cash $" . number_format($openingUsd, 2), 'shift', (string)$shiftId, $cashierId, $cashierName, 'cashier');

        return [
            'success' => true,
            'shift_id' => $shiftId,
            'shift_uid' => $shiftUid,
            'opening_cash_usd' => $openingUsd,
            'opening_cash_khr' => $openingKhr,
            'message' => 'Shift started successfully with change fund $' . number_format($openingUsd, 2)
        ];
    }

    /**
     * Get active shift for a cashier.
     */
    public static function getActiveShift(int $cashierId): ?array {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE cashier_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cashierId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Calculate current expected cash in drawer for an active shift.
     * Expected Cash = Opening Cash + Cash Orders Paid in Shift - Cash Expenses in Shift - Cash Refunds
     */
    public static function calculateShiftExpectedCash(int $shiftId): array {
        $pdo = Database::getInstance();
        $sStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
        $sStmt->execute([$shiftId]);
        $shift = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            return ['expected_cash_usd' => 0.00, 'expected_cash_khr' => 0];
        }

        $startTime = $shift['started_at'];
        $endTime = $shift['ended_at'] ?? date('Y-m-d H:i:s');
        $cashierId = (int)$shift['cashier_id'];

        // Cash sales in shift
        $salesStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_usd), 0) as cash_usd, COALESCE(SUM(total_khr), 0) as cash_khr
            FROM orders
            WHERE cashier_id = ?
              AND payment_method = 'cash'
              AND (status = 'paid' OR payment_status = 'paid')
              AND status != 'cancelled'
              AND created_at >= ? AND created_at <= ?
        ");
        $salesStmt->execute([$cashierId, $startTime, $endTime]);
        $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);
        $cashSalesUsd = (float)($sales['cash_usd'] ?? 0);
        $cashSalesKhr = (int)($sales['cash_khr'] ?? 0);

        // Cash expenses recorded in shift
        $expStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_usd), 0) as exp_usd, COALESCE(SUM(amount_khr), 0) as exp_khr
            FROM expenses
            WHERE recorded_by = ?
              AND (is_active IS NULL OR is_active = 1)
              AND created_at >= ? AND created_at <= ?
        ");
        $expStmt->execute([$cashierId, $startTime, $endTime]);
        $exp = $expStmt->fetch(PDO::FETCH_ASSOC);
        $expUsd = (float)($exp['exp_usd'] ?? 0);
        $expKhr = (int)($exp['exp_khr'] ?? 0);

        // Cash refunds given in shift
        $refStmt = $pdo->prepare("
            SELECT COALESCE(SUM(refund_amount_usd), 0) as ref_usd
            FROM orders
            WHERE cashier_id = ?
              AND payment_method = 'cash'
              AND refund_status = 'refunded'
              AND refunded_at >= ? AND refunded_at <= ?
        ");
        $refStmt->execute([$cashierId, $startTime, $endTime]);
        $refUsd = (float)($refStmt->fetchColumn() ?: 0);

        $openingUsd = (float)$shift['opening_cash_usd'];
        $openingKhr = (int)$shift['opening_cash_khr'];

        $expectedUsd = round($openingUsd + $cashSalesUsd - $expUsd - $refUsd, 2);
        $expectedKhr = (int)($openingKhr + $cashSalesKhr - $expKhr);

        return [
            'opening_cash_usd'  => $openingUsd,
            'opening_cash_khr'  => $openingKhr,
            'cash_sales_usd'    => $cashSalesUsd,
            'cash_sales_khr'    => $cashSalesKhr,
            'expenses_usd'      => $expUsd,
            'expenses_khr'      => $expKhr,
            'refunds_usd'       => $refUsd,
            'expected_cash_usd' => $expectedUsd,
            'expected_cash_khr' => $expectedKhr,
        ];
    }

    /**
     * End Shift / Handover:
     * - Outgoing cashier enters blind counted cash and confirms their PIN.
     * - Incoming cashier enters their username and PIN to sign off on handover.
     * - Calculates expected cash, computes difference.
     * - Records shortage as owed by outgoing cashier.
     */
    public static function endShift(
        int $shiftId,
        float $countedUsd,
        int $countedKhr,
        string $outgoingPin,
        string $incomingUsername,
        string $incomingPin,
        ?string $notes = null
    ): array {
        $pdo = Database::getInstance();
        $sStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
        $sStmt->execute([$shiftId]);
        $shift = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift || $shift['status'] !== 'open') {
            throw new Exception("Shift not found or already closed.");
        }

        // 1. Verify Outgoing Cashier PIN
        $outUserStmt = $pdo->prepare("SELECT id, username, full_name, password_hash FROM users WHERE id = ?");
        $outUserStmt->execute([(int)$shift['cashier_id']]);
        $outUser = $outUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$outUser || !password_verify(trim($outgoingPin), $outUser['password_hash'])) {
            AuditLogger::log('SHIFT_HANDOVER_FAILED', "Outgoing cashier PIN verification failed for {$shift['cashier_name']}", 'shift', (string)$shiftId);
            throw new Exception("Invalid PIN for outgoing cashier ({$shift['cashier_name']}).");
        }

        // 2. Verify Incoming Cashier PIN
        $inUserStmt = $pdo->prepare("SELECT id, username, full_name, password_hash, role, status FROM users WHERE username = ?");
        $inUserStmt->execute([trim($incomingUsername)]);
        $inUser = $inUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$inUser || $inUser['status'] !== 'active') {
            throw new Exception("Incoming cashier account '{$incomingUsername}' not found or inactive.");
        }

        if (!password_verify(trim($incomingPin), $inUser['password_hash'])) {
            AuditLogger::log('SHIFT_HANDOVER_FAILED', "Incoming cashier PIN verification failed for {$incomingUsername}", 'shift', (string)$shiftId);
            throw new Exception("Invalid PIN for incoming cashier ({$incomingUsername}).");
        }

        // 3. Calculate expected cash
        $calc = self::calculateShiftExpectedCash($shiftId);
        $expectedUsd = (float)$calc['expected_cash_usd'];
        $expectedKhr = (int)$calc['expected_cash_khr'];

        $diffUsd = round($countedUsd - $expectedUsd, 2);
        $diffKhr = $countedKhr - $expectedKhr;

        // Shortage is recorded if counted is less than expected
        $shortageOwedUsd = ($diffUsd < 0) ? abs($diffUsd) : 0.00;

        // 4. Update Shift record
        $upd = $pdo->prepare("
            UPDATE cashier_shifts SET
                ended_at = CURRENT_TIMESTAMP,
                expected_cash_usd = ?,
                expected_cash_khr = ?,
                counted_cash_usd = ?,
                counted_cash_khr = ?,
                difference_usd = ?,
                shortage_owed_usd = ?,
                handover_to_cashier_id = ?,
                handover_to_cashier_name = ?,
                status = 'ended',
                notes = ?
            WHERE id = ?
        ");
        $upd->execute([
            $expectedUsd,
            $expectedKhr,
            $countedUsd,
            $countedKhr,
            $diffUsd,
            $shortageOwedUsd,
            $inUser['id'],
            $inUser['full_name'],
            $notes,
            $shiftId
        ]);

        // 5. Audit Log
        $handoverDetails = "Shift {$shift['shift_uid']} handover from {$outUser['full_name']} to {$inUser['full_name']} signed off with both PINs. Counted: $" . number_format($countedUsd, 2) . ", Expected: $" . number_format($expectedUsd, 2) . ", Diff: $" . number_format($diffUsd, 2);
        AuditLogger::log('SHIFT_HANDOVER', $handoverDetails, 'shift', (string)$shiftId, (int)$outUser['id'], $outUser['username'], 'cashier');

        if ($shortageOwedUsd > 0) {
            AuditLogger::log('SHIFT_SHORTAGE', "Cash shortage of $" . number_format($shortageOwedUsd, 2) . " recorded as owed by Cashier {$outUser['full_name']} (#{$outUser['id']})", 'shift', (string)$shiftId, (int)$outUser['id'], $outUser['username'], 'cashier');
        }

        return [
            'success'           => true,
            'shift_uid'         => $shift['shift_uid'],
            'outgoing_cashier'  => $outUser['full_name'],
            'incoming_cashier'  => $inUser['full_name'],
            'counted_cash_usd'  => $countedUsd,
            'expected_cash_usd' => $expectedUsd,
            'difference_usd'    => $diffUsd,
            'shortage_owed_usd' => $shortageOwedUsd,
            'has_shortage'      => ($shortageOwedUsd > 0),
            'message'           => 'Shift ended and handover signed off successfully.'
        ];
    }

    /**
     * Conduct an unannounced Spot Audit on an active cashier / shift.
     * Cashier counts drawer blindly with no warning before; system records difference.
     */
    public static function conductSpotAudit(
        int $cashierId,
        float $countedUsd,
        int $countedKhr,
        string $managerUsername,
        string $managerPin,
        ?string $notes = null
    ): array {
        $mgr = self::verifyManagerPin($managerUsername, $managerPin);

        $pdo = Database::getInstance();
        $uStmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ?");
        $uStmt->execute([$cashierId]);
        $cashier = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$cashier) {
            throw new Exception("Cashier user not found.");
        }

        // Fetch active shift if any
        $activeShift = self::getActiveShift($cashierId);
        $shiftId = $activeShift ? (int)$activeShift['id'] : null;

        if ($shiftId) {
            $calc = self::calculateShiftExpectedCash($shiftId);
            $expectedUsd = (float)$calc['expected_cash_usd'];
        } else {
            // If no formal shift started, sum today's cash orders handled by cashier
            $calcStmt = $pdo->prepare("SELECT COALESCE(SUM(total_usd), 0) FROM orders WHERE cashier_id = ? AND payment_method = 'cash' AND DATE(created_at) = CURDATE() AND (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled'");
            $calcStmt->execute([$cashierId]);
            $expectedUsd = (float)$calcStmt->fetchColumn();
        }

        $diffUsd = round($countedUsd - $expectedUsd, 2);
        $shortageOwedUsd = ($diffUsd < 0) ? abs($diffUsd) : 0.00;

        $auditUid = generate_unique_id('SPT');
        $ins = $pdo->prepare("
            INSERT INTO spot_audits (
                audit_uid, shift_id, cashier_id, cashier_name, manager_id, manager_name,
                expected_cash_usd, counted_cash_usd, counted_cash_khr, difference_usd, shortage_owed_usd,
                notes, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            $auditUid,
            $shiftId,
            $cashierId,
            $cashier['full_name'],
            $mgr['manager_id'],
            $mgr['manager_name'],
            $expectedUsd,
            $countedUsd,
            $countedKhr,
            $diffUsd,
            $shortageOwedUsd,
            $notes
        ]);
        $spotAuditId = (int)$pdo->lastInsertId();

        $logMsg = "Spot Audit {$auditUid} conducted by Manager {$mgr['manager_name']} on Cashier {$cashier['full_name']}. Counted: $" . number_format($countedUsd, 2) . ", Expected: $" . number_format($expectedUsd, 2) . ", Diff: $" . number_format($diffUsd, 2);
        AuditLogger::log('SPOT_AUDIT', $logMsg, 'spot_audit', (string)$spotAuditId, $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        if ($shortageOwedUsd > 0) {
            AuditLogger::log('SPOT_SHORTAGE', "Spot audit shortage of $" . number_format($shortageOwedUsd, 2) . " owed by Cashier {$cashier['full_name']}", 'spot_audit', (string)$spotAuditId, $cashierId, $cashier['username'], 'cashier');
        }

        return [
            'success'           => true,
            'audit_uid'         => $auditUid,
            'cashier_name'      => $cashier['full_name'],
            'manager_name'      => $mgr['manager_name'],
            'expected_cash_usd' => $expectedUsd,
            'counted_cash_usd'  => $countedUsd,
            'difference_usd'    => $diffUsd,
            'shortage_owed_usd' => $shortageOwedUsd,
            'message'           => 'Spot audit recorded successfully.'
        ];
    }

    /**
     * Open cash drawer without a sale (no-sale kick).
     * Strictly requires Manager PIN authorization.
     */
    public static function openDrawerNoSale(
        string $reason,
        string $managerUsername,
        string $managerPin,
        int $cashierId,
        ?int $deviceId = null
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("Reason for opening cash drawer without sale is required.");
        }

        $mgr = self::verifyManagerPin($managerUsername, $managerPin);

        $pdo = Database::getInstance();
        $cStmt = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
        $cStmt->execute([$cashierId]);
        $cashier = $cStmt->fetch(PDO::FETCH_ASSOC);
        $cashierName = $cashier['full_name'] ?? (string)$cashierId;

        $eventUid = generate_unique_id('DRW');
        $ins = $pdo->prepare("
            INSERT INTO drawer_events (
                event_uid, event_type, pos_device_id, cashier_id, cashier_name,
                manager_id, manager_name, reason, is_active, created_at
            ) VALUES (?, 'no_sale_open', ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            $eventUid,
            $deviceId,
            $cashierId,
            $cashierName,
            $mgr['manager_id'],
            $mgr['manager_name'],
            $reason
        ]);

        $logMsg = "Cash drawer opened without sale by Cashier {$cashierName} authorized by Manager {$mgr['manager_name']}. Reason: {$reason}";
        AuditLogger::log('DRAWER_OPEN_NO_SALE', $logMsg, 'drawer', $eventUid, $cashierId, $cashier['username'] ?? '', 'cashier');

        return [
            'success'   => true,
            'event_uid' => $eventUid,
            'message'   => 'Cash drawer opening authorized and logged.'
        ];
    }

    /**
     * Process Refund on a Paid Order.
     * Strictly requires Manager PIN authorization.
     * Offline refunds are NOT allowed.
     */
    public static function processRefund(
        int $orderId,
        float $refundAmountUsd,
        string $reason,
        string $managerUsername,
        string $managerPin,
        int $cashierId,
        bool $isOnline = true
    ): array {
        // Requirement 4: Offline refunds are not allowed!
        if (!$isOnline) {
            throw new Exception("Offline refunds are strictly prohibited. If connection is lost, use the paper logbook signed by the Manager.");
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("Refund reason is required.");
        }

        $mgr = self::verifyManagerPin($managerUsername, $managerPin);

        $pdo = Database::getInstance();
        $oStmt = $pdo->prepare("SELECT id, order_number, total_usd, status, payment_status, payment_method FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found.");
        }

        if ($order['status'] !== 'paid' && $order['payment_status'] !== 'paid') {
            throw new Exception("Cannot refund order #{$order['order_number']} because it is not marked as paid.");
        }

        if ($refundAmountUsd <= 0 || $refundAmountUsd > (float)$order['total_usd']) {
            throw new Exception("Refund amount ($" . number_format($refundAmountUsd, 2) . ") must be greater than zero and cannot exceed order total ($" . number_format((float)$order['total_usd'], 2) . ").");
        }

        // Get Cashier info
        $cStmt = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
        $cStmt->execute([$cashierId]);
        $cashier = $cStmt->fetch(PDO::FETCH_ASSOC);
        $cashierName = $cashier['full_name'] ?? (string)$cashierId;

        // Update Order record
        $upd = $pdo->prepare("
            UPDATE orders SET
                refund_status = 'refunded',
                refund_amount_usd = ?,
                refund_reason = ?,
                refunded_by_manager_id = ?,
                refunded_by_manager_name = ?,
                refunded_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $upd->execute([
            $refundAmountUsd,
            $reason,
            $mgr['manager_id'],
            $mgr['manager_name'],
            $orderId
        ]);

        $logMsg = "Refund of $" . number_format($refundAmountUsd, 2) . " on order #{$order['order_number']} initiated by Cashier {$cashierName} authorized by Manager {$mgr['manager_name']}. Reason: {$reason}";
        AuditLogger::log('MANAGER_REFUND', $logMsg, 'order', (string)$orderId, $cashierId, $cashier['username'] ?? '', 'cashier');

        return [
            'success'           => true,
            'order_number'      => $order['order_number'],
            'refund_amount_usd' => $refundAmountUsd,
            'manager_name'      => $mgr['manager_name'],
            'message'           => "Refund of $" . number_format($refundAmountUsd, 2) . " authorized and recorded."
        ];
    }

    /**
     * Check if sales are locked (11:59 PM cutoff or closed day).
     * Refuses orders and payments when locked.
     */
    public static function isDayLocked(?string $checkDate = null, ?string $checkTime = null): array {
        $date = $checkDate ?? date('Y-m-d');
        $time = $checkTime ?? date('H:i:s');
        $pdo = Database::getInstance();

        // 1. Check if today's business day has already been closed
        $chkClosed = $pdo->prepare("SELECT id, z_report_number, closed_at FROM pos_daily_closes WHERE close_date = ? AND status = 'closed' LIMIT 1");
        $chkClosed->execute([$date]);
        $closedRow = $chkClosed->fetch(PDO::FETCH_ASSOC);

        if ($closedRow) {
            return [
                'locked'   => true,
                'reason'   => 'day_closed',
                'message'  => "Sales are locked for {$date}. Day was closed at {$closedRow['closed_at']} (Z-Report: {$closedRow['z_report_number']}). Only Owner or Manager can reopen."
            ];
        }

        // 2. Check 11:59 PM Cutoff: All sales lock at 23:59:00
        if ($time >= '23:59:00') {
            return [
                'locked'  => true,
                'reason'  => 'time_cutoff_1159',
                'message' => "Sales are automatically locked at 11:59 PM. The Manager must enter their PIN to Close the Day and generate the final Z-Report before sales can resume."
            ];
        }

        return ['locked' => false];
    }

    /**
     * Close the Day & generate final Z-Report.
     * Strictly requires Manager PIN.
     */
    public static function closeDayZReport(
        string $managerUsername,
        string $managerPin,
        float $countedUsd,
        int $countedKhr = 0,
        ?string $notes = null,
        ?string $closeDate = null
    ): array {
        $mgr = self::verifyManagerPin($managerUsername, $managerPin);
        $date = $closeDate ?? date('Y-m-d');
        $pdo = Database::getInstance();

        // Calculate whole day totals
        $totalsStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as paid_count,
                COALESCE(SUM(total_usd), 0) as total_sales_usd,
                COALESCE(SUM(total_khr), 0) as total_sales_khr,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_usd ELSE 0 END), 0) as cash_usd,
                COALESCE(SUM(CASE WHEN payment_method = 'aba' THEN total_usd ELSE 0 END), 0) as aba_usd,
                COALESCE(SUM(CASE WHEN payment_method = 'khqr' THEN total_usd ELSE 0 END), 0) as khqr_usd,
                COALESCE(SUM(discount_usd), 0) as discount_usd
            FROM orders
            WHERE DATE(created_at) = ? AND (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled'
        ");
        $totalsStmt->execute([$date]);
        $t = $totalsStmt->fetch(PDO::FETCH_ASSOC);

        // Day expenses
        $expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE expense_date = ? AND (is_active IS NULL OR is_active = 1)");
        $expStmt->execute([$date]);
        $expensesUsd = (float)$expStmt->fetchColumn();

        // Day refunds
        $refStmt = $pdo->prepare("SELECT COALESCE(SUM(refund_amount_usd), 0) FROM orders WHERE DATE(refunded_at) = ? AND refund_status = 'refunded'");
        $refStmt->execute([$date]);
        $refundsUsd = (float)$refStmt->fetchColumn();

        // Opening cash from shifts today
        $opStmt = $pdo->prepare("SELECT COALESCE(SUM(opening_cash_usd), 0) FROM cashier_shifts WHERE DATE(started_at) = ?");
        $opStmt->execute([$date]);
        $openingUsd = (float)$opStmt->fetchColumn();

        $cashSalesUsd = (float)$t['cash_usd'];
        $expectedUsd = round($openingUsd + $cashSalesUsd - $expensesUsd - $refundsUsd, 2);
        $diffUsd = round($countedUsd - $expectedUsd, 2);
        $shortageOwedUsd = ($diffUsd < 0) ? abs($diffUsd) : 0.00;

        $zReportNumber = generate_unique_id('ZREP');

        // Insert or update pos_daily_closes
        $chkExist = $pdo->prepare("SELECT id FROM pos_daily_closes WHERE close_date = ?");
        $chkExist->execute([$date]);
        $existId = $chkExist->fetchColumn();

        if ($existId) {
            $upd = $pdo->prepare("
                UPDATE pos_daily_closes SET
                    closed_by_user_id = ?,
                    closed_by_name = ?,
                    manager_id = ?,
                    manager_name = ?,
                    closed_at = CURRENT_TIMESTAMP,
                    total_sales_usd = ?,
                    total_sales_khr = ?,
                    cash_sales_usd = ?,
                    aba_sales_usd = ?,
                    khqr_sales_usd = ?,
                    expenses_usd = ?,
                    discount_total_usd = ?,
                    expected_cash_usd = ?,
                    counted_cash_usd = ?,
                    counted_cash_khr = ?,
                    difference_usd = ?,
                    shortage_owed_usd = ?,
                    paid_orders_count = ?,
                    z_report_number = ?,
                    status = 'closed',
                    notes = ?
                WHERE id = ?
            ");
            $upd->execute([
                $mgr['manager_id'],
                $mgr['manager_name'],
                $mgr['manager_id'],
                $mgr['manager_name'],
                (float)$t['total_sales_usd'],
                (int)$t['total_sales_khr'],
                $cashSalesUsd,
                (float)$t['aba_usd'],
                (float)$t['khqr_usd'],
                $expensesUsd,
                (float)$t['discount_usd'],
                $expectedUsd,
                $countedUsd,
                $countedKhr,
                $diffUsd,
                $shortageOwedUsd,
                (int)$t['paid_count'],
                $zReportNumber,
                $notes,
                $existId
            ]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO pos_daily_closes (
                    close_date, closed_by_user_id, closed_by_name, manager_id, manager_name,
                    role, closed_at, total_sales_usd, total_sales_khr, cash_sales_usd, aba_sales_usd, khqr_sales_usd,
                    expenses_usd, discount_total_usd, expected_cash_usd, counted_cash_usd, counted_cash_khr,
                    difference_usd, shortage_owed_usd, paid_orders_count, z_report_number, status, notes
                ) VALUES (?, ?, ?, ?, ?, 'manager', CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'closed', ?)
            ");
            $ins->execute([
                $date,
                $mgr['manager_id'],
                $mgr['manager_name'],
                $mgr['manager_id'],
                $mgr['manager_name'],
                (float)$t['total_sales_usd'],
                (int)$t['total_sales_khr'],
                $cashSalesUsd,
                (float)$t['aba_usd'],
                (float)$t['khqr_usd'],
                $expensesUsd,
                (float)$t['discount_usd'],
                $expectedUsd,
                $countedUsd,
                $countedKhr,
                $diffUsd,
                $shortageOwedUsd,
                (int)$t['paid_count'],
                $zReportNumber,
                $notes
            ]);
        }

        // Also close all active cashier shifts for today
        $pdo->prepare("UPDATE cashier_shifts SET status = 'ended', ended_at = CURRENT_TIMESTAMP WHERE status = 'open' AND DATE(started_at) = ?")
            ->execute([$date]);

        $logMsg = "Day {$date} closed by Manager {$mgr['manager_name']}. Final Z-Report generated: {$zReportNumber}. Total Sales: $" . number_format((float)$t['total_sales_usd'], 2) . ", Expected Cash: $" . number_format($expectedUsd, 2) . ", Counted: $" . number_format($countedUsd, 2) . ", Diff: $" . number_format($diffUsd, 2);
        AuditLogger::log('DAY_CLOSED', $logMsg, 'z_report', $zReportNumber, $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        if ($shortageOwedUsd > 0) {
            AuditLogger::log('DAY_SHORTAGE', "Day {$date} final close recorded cash shortage of $" . number_format($shortageOwedUsd, 2), 'z_report', $zReportNumber, $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);
        }

        return [
            'success'           => true,
            'close_date'        => $date,
            'z_report_number'   => $zReportNumber,
            'manager_name'      => $mgr['manager_name'],
            'total_sales_usd'   => (float)$t['total_sales_usd'],
            'cash_sales_usd'    => $cashSalesUsd,
            'aba_sales_usd'     => (float)$t['aba_usd'],
            'khqr_sales_usd'    => (float)$t['khqr_usd'],
            'expenses_usd'      => $expensesUsd,
            'expected_cash_usd' => $expectedUsd,
            'counted_cash_usd'  => $countedUsd,
            'difference_usd'    => $diffUsd,
            'shortage_owed_usd' => $shortageOwedUsd,
            'paid_orders_count' => (int)$t['paid_count'],
            'message'           => "Day closed and Z-Report {$zReportNumber} created successfully."
        ];
    }

    /**
     * Reopen a closed day.
     * Only Owner or Manager can reopen, with mandatory reason, logged in audit trail.
     */
    public static function reopenClosedDay(
        string $closeDate,
        string $reason,
        string $managerUsername,
        string $managerPin
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("A valid reason is required to reopen a closed day.");
        }

        $mgr = self::verifyManagerPin($managerUsername, $managerPin);
        $pdo = Database::getInstance();

        $chk = $pdo->prepare("SELECT id, status, z_report_number FROM pos_daily_closes WHERE close_date = ?");
        $chk->execute([$closeDate]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['status'] !== 'closed') {
            throw new Exception("Day {$closeDate} is not currently closed.");
        }

        $upd = $pdo->prepare("
            UPDATE pos_daily_closes SET
                status = 'open',
                reopened_by_user_id = ?,
                reopened_at = CURRENT_TIMESTAMP,
                reopen_reason = ?
            WHERE close_date = ?
        ");
        $upd->execute([$mgr['manager_id'], $reason, $closeDate]);

        $logMsg = "Day {$closeDate} (Z-Report: {$row['z_report_number']}) REOPENED by Manager/Owner {$mgr['manager_name']}. Reason: {$reason}";
        AuditLogger::log('DAY_REOPENED', $logMsg, 'z_report', (string)$row['z_report_number'], $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        return [
            'success'      => true,
            'close_date'   => $closeDate,
            'manager_name' => $mgr['manager_name'],
            'message'      => "Day {$closeDate} has been reopened successfully."
        ];
    }
}
