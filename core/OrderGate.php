<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';

class ReceiptRequiredException extends Exception {
    public function __construct(string $message = "RECEIPT_REQUIRED: Customer receipt must be printed before car can enter wash/work bay.", int $code = 422, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

class PaymentRequiredException extends Exception {
    public function __construct(string $message = "PAYMENT_REQUIRED: Upfront payment is mandatory before car can enter wash/work bay.", int $code = 402, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

class OrderGate {
    /**
     * Check if receipt requirement setting is active. Default is '1' (enabled).
     */
    public static function isReceiptRequired(?PDO $pdo = null): bool {
        if (function_exists('get_setting')) {
            return (string)get_setting('require_receipt_before_wash', '1') === '1';
        }
        $pdo = $pdo ?? Database::getInstance();
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'require_receipt_before_wash' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return ($val === false || (string)$val === '1');
        } catch (Throwable $t) {
            return true;
        }
    }

    /**
     * Mark an order receipt as printed.
     */
    public static function markReceiptPrinted(PDO $pdo, int $orderId, ?int $actorId = null, ?string $actorName = null, ?string $actorRole = null): bool {
        $stmt = $pdo->prepare("SELECT id, order_number, receipt_printed_at FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $upd = $pdo->prepare("UPDATE orders SET receipt_printed_at = ? WHERE id = ?");
        $upd->execute([$now, $orderId]);

        AuditLogger::log(
            'RECEIPT_PRINTED',
            "Receipt printed for order #{$order['order_number']}",
            'order',
            (string)$orderId,
            $actorId,
            $actorName,
            $actorRole
        );

        return true;
    }

    /**
     * Acknowledge that the electronic receipt has been successfully received by the client/POS.
     * Idempotently stamps receipt_issued_at only for paid orders.
     */
    public static function ackReceiptIssued(
        PDO $pdo,
        int $orderId,
        ?string $orderNumber = null,
        ?int $actorId = null,
        ?string $actorName = null,
        ?string $actorRole = null,
        ?int $posDeviceId = null
    ): array {
        $stmt = $pdo->prepare("SELECT id, order_number, plate_number, payment_status, receipt_issued_at, pos_device_id FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found", 404);
        }

        // Validate matching order number if supplied
        if ($orderNumber !== null && $orderNumber !== '' && $order['order_number'] !== $orderNumber) {
            throw new Exception("Order number mismatch: expected {$order['order_number']}, got {$orderNumber}", 400);
        }

        // Validate device if device ID is set on order and supplied
        if ($posDeviceId !== null && $posDeviceId > 0 && !empty($order['pos_device_id']) && (int)$order['pos_device_id'] !== $posDeviceId) {
            throw new Exception("POS device mismatch for order #{$order['order_number']}", 403);
        }

        // Validate payment status: Unpaid orders CANNOT be acknowledged
        if (($order['payment_status'] ?? '') !== 'paid') {
            throw new PaymentRequiredException("Cannot acknowledge receipt for unpaid order #{$order['order_number']}. Payment status is '{$order['payment_status']}'", 402);
        }

        $now = date('Y-m-d H:i:s');
        $wasAlreadySet = !empty($order['receipt_issued_at']);
        $issuedAt = $wasAlreadySet ? $order['receipt_issued_at'] : $now;

        if (!$wasAlreadySet) {
            $upd = $pdo->prepare("UPDATE orders SET receipt_issued_at = ? WHERE id = ? AND payment_status = 'paid' AND receipt_issued_at IS NULL");
            $upd->execute([$now, $orderId]);

            AuditLogger::log(
                'RECEIPT_ACKNOWLEDGED',
                "Electronic receipt acknowledged by client for order #{$order['order_number']}",
                'order',
                (string)$orderId,
                $actorId,
                $actorName,
                $actorRole
            );
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $order['order_number'],
            'receipt_issued_at' => $issuedAt,
            'already_acknowledged' => $wasAlreadySet
        ];
    }

    /**
     * Assert that an order satisfies mandatory payment and electronic receipt admission criteria before entering a bay.
     * Enforces strict hierarchy:
     * 1. Order must exist.
     * 2. Mandatory payment check (payment_status === 'paid') - NO bypass, Manager PIN cannot admit unpaid car.
     * 3. Electronic receipt issuance (receipt_issued_at IS NOT NULL) is unconditionally mandatory.
     * 4. Physical printing (receipt_printed_at) is advisory only and never substitutes for electronic issuance.
     * 5. Manager PIN CANNOT bypass confirmed payment or electronic receipt issuance.
     */
    public static function assertServiceAdmissible(
        PDO $pdo,
        int $orderId,
        ?string $managerPin = null,
        ?string $overrideReason = null,
        ?string $managerUsername = null,
        ?int $actorId = null,
        ?string $actorName = null,
        ?string $actorRole = null
    ): bool {
        // 1. Fetch order
        $stmt = $pdo->prepare("SELECT id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found", 404);
        }

        // 2. Mandatory payment enforcement (STRICT: No manager PIN bypass permitted)
        $paymentStatus = (string)($order['payment_status'] ?? '');
        if ($paymentStatus !== 'paid') {
            throw new PaymentRequiredException("PAYMENT_REQUIRED: Upfront payment is mandatory before vehicle {$order['plate_number']} (#{$order['order_number']}) can enter wash/work bay.");
        }

        // 3. Strict unconditional electronic receipt issuance check (receipt_issued_at must be non-empty)
        // Configuration settings, legacy physical printing, or manager PIN CANNOT bypass this requirement.
        if (!empty($order['receipt_issued_at'])) {
            return true;
        }

        // 4. Strict rule: Electronic receipt issuance (receipt_issued_at) is unconditionally mandatory before service admission.
        // Physical receipt printing (receipt_printed_at) is advisory only and NEVER substitutes for electronic issuance.
        // Manager PIN CANNOT bypass confirmed payment or electronic receipt issuance.
        throw new ReceiptRequiredException("RECEIPT_REQUIRED: Customer electronic receipt must be issued before vehicle {$order['plate_number']} (#{$order['order_number']}) can enter wash/work bay.");
    }

    /**
     * Backward-compatible alias for assertServiceAdmissible.
     */
    public static function assertReceiptPrinted(
        PDO $pdo,
        int $orderId,
        ?string $managerPin = null,
        ?string $overrideReason = null,
        ?string $managerUsername = null,
        ?int $actorId = null,
        ?string $actorName = null,
        ?string $actorRole = null
    ): bool {
        return self::assertServiceAdmissible(
            $pdo,
            $orderId,
            $managerPin,
            $overrideReason,
            $managerUsername,
            $actorId,
            $actorName,
            $actorRole
        );
    }

    /**
     * Helper to verify manager PIN credentials.
     * Accepts PIN against bcrypt local_pin_hash, password_hash, or legacy local_pin.
     */
    public static function verifyOverrideManager(PDO $pdo, string $pin, ?string $managerUsername = null): ?array {
        $allowedRoles = ['owner', 'manager', 'system_admin'];
        $cleanPin = trim($pin);
        if ($cleanPin === '') {
            return null;
        }

        $cols = [];
        try {
            $colRows = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $cols = is_array($colRows) ? $colRows : [];
        } catch (Throwable $t) {
            // SQLite or permission fallback
            try {
                $tInfo = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
                $cols = array_column($tInfo, 'name');
            } catch (Throwable $t2) {}
        }

        $selectFields = ['id', 'username', 'full_name', 'role', 'status', 'password_hash'];
        if (in_array('local_pin_hash', $cols, true)) {
            $selectFields[] = 'local_pin_hash';
        }
        if (in_array('local_pin', $cols, true)) {
            $selectFields[] = 'local_pin';
        }

        $fieldStr = implode(', ', $selectFields);

        if ($managerUsername !== null && trim($managerUsername) !== '') {
            $stmt = $pdo->prepare("SELECT {$fieldStr} FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([trim($managerUsername)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && $user['status'] === 'active' && in_array($user['role'], $allowedRoles, true)) {
                if (self::checkPinMatch($cleanPin, $user)) {
                    return $user;
                }
            }
            return null;
        }

        // If username not provided, search active managers/owners
        $stmt = $pdo->prepare("SELECT {$fieldStr} FROM users WHERE status = 'active' AND role IN ('owner', 'manager', 'system_admin')");
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidates as $cand) {
            if (self::checkPinMatch($cleanPin, $cand)) {
                return $cand;
            }
        }
        return null;
    }

    private static function checkPinMatch(string $pin, array $user): bool {
        // 1. local_pin_hash bcrypt check
        if (!empty($user['local_pin_hash']) && password_verify($pin, $user['local_pin_hash'])) {
            return true;
        }
        // 2. password_hash bcrypt check
        if (!empty($user['password_hash']) && password_verify($pin, $user['password_hash'])) {
            return true;
        }
        // 3. legacy local_pin plain text check (fallback)
        if (isset($user['local_pin']) && $user['local_pin'] !== null && (string)$user['local_pin'] === $pin) {
            return true;
        }
        return false;
    }
}
