<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';

class ReceiptRequiredException extends Exception {
    public function __construct(string $message = "RECEIPT_REQUIRED: Customer receipt must be printed before car can enter wash/work bay.", int $code = 422, ?Throwable $previous = null) {
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
     * Assert that the receipt for $orderId has been printed, or a valid manager override is provided.
     * Throws ReceiptRequiredException (code 422) if assertion fails.
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
        if (!self::isReceiptRequired($pdo)) {
            return true;
        }

        $stmt = $pdo->prepare("SELECT id, order_number, plate_number, receipt_printed_at, status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found");
        }

        // If receipt has already been printed, gate passes immediately
        if (!empty($order['receipt_printed_at'])) {
            return true;
        }

        // Check for manager PIN override
        $pin = trim((string)$managerPin);
        $reason = trim((string)$overrideReason);

        if ($pin !== '') {
            $manager = self::verifyOverrideManager($pdo, $pin, $managerUsername);
            if ($manager) {
                $overrideDetails = "Manager {$manager['full_name']} ({$manager['role']}) authorized 'No Receipt' override for Order #{$order['order_number']} (Plate: {$order['plate_number']}). Reason: " . ($reason !== '' ? $reason : 'Manual Manager Override');
                AuditLogger::log(
                    'RECEIPT_OVERRIDE',
                    $overrideDetails,
                    'order',
                    (string)$orderId,
                    (int)$manager['id'],
                    $manager['username'],
                    $manager['role']
                );
                return true;
            } else {
                AuditLogger::log(
                    'RECEIPT_OVERRIDE_FAILED',
                    "Failed manager PIN override attempt for Order #{$order['order_number']} (Plate: {$order['plate_number']})",
                    'order',
                    (string)$orderId,
                    $actorId,
                    $actorName,
                    $actorRole
                );
                throw new Exception("Invalid manager credentials for receipt override.");
            }
        }

        throw new ReceiptRequiredException("RECEIPT_REQUIRED: Customer receipt must be printed before vehicle {$order['plate_number']} (#{$order['order_number']}) can enter wash/work bay.");
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
