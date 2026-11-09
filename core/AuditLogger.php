<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class AuditLogger {
    /**
     * Generate a collision-proof unique ID with a specified prefix.
     * E.g. LOG-20261027-A84D2F9C
     */
    public static function generateUid(string $prefix = 'LOG'): string {
        $datePart = date('Ymd');
        $randomPart = strtoupper(bin2hex(random_bytes(4)));
        return "{$prefix}-{$datePart}-{$randomPart}";
    }

    /**
     * Record an immutable audit log entry.
     */
    public static function log(
        string $action,
        string $details,
        ?string $entityType = null,
        ?string $entityId = null,
        ?int $userId = null,
        ?string $username = null,
        ?string $role = null,
        ?PDO $pdo = null,
        bool $throwOnError = false
    ): string {
        $db = $pdo ?? Database::getInstance();
        $logUid = self::generateUid('LOG');

        // Resolve user information from session if not explicitly provided
        if ($userId === null && class_exists('Auth') && Auth::check()) {
            $userId = Auth::getUserId();
            $username = $username ?? Auth::getUsername();
            $role = $role ?? Auth::getRole();
        }

        $ipAddress = self::getClientIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown / CLI', 0, 255);
        $deviceName = self::parseDeviceName($userAgent);

        try {
            $stmt = $db->prepare("
                INSERT INTO audit_logs (
                    log_uid, user_id, username, role, action, entity_type, entity_id,
                    details, ip_address, user_agent, device_name, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $logUid,
                $userId,
                $username,
                $role,
                $action,
                $entityType,
                $entityId,
                $details,
                $ipAddress,
                $userAgent,
                $deviceName
            ]);
        } catch (Exception $e) {
            @error_log("AuditLogger Error: " . $e->getMessage());
            if ($throwOnError) {
                throw new RuntimeException("Audit log write failed: " . $e->getMessage(), 0, $e);
            }
        }

        return $logUid;
    }

    /**
     * Strict audit log method: guarantees audit record is written within current transaction/connection,
     * and strictly throws RuntimeException if the insert fails.
     */
    public static function logStrict(
        PDO $pdo,
        string $action,
        string $details,
        ?string $entityType = null,
        ?string $entityId = null,
        ?int $userId = null,
        ?string $username = null,
        ?string $role = null
    ): string {
        return self::log($action, $details, $entityType, $entityId, $userId, $username, $role, $pdo, true);
    }

    /**
     * Extract client IP safely.
     */
    public static function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Friendly device / platform parser.
     */
    public static function parseDeviceName(string $ua): string {
        if (str_contains($ua, 'Mobile') || str_contains($ua, 'Android') || str_contains($ua, 'iPhone')) {
            return 'Mobile Browser / POS Tablet';
        }
        if (str_contains($ua, 'Windows')) {
            return 'Windows Desktop';
        }
        if (str_contains($ua, 'Macintosh')) {
            return 'Mac Desktop';
        }
        if (str_contains($ua, 'Linux')) {
            return 'Linux Station';
        }
        if (php_sapi_name() === 'cli') {
            return 'Console / CLI Runner';
        }
        return 'Web Client';
    }

    /**
     * Query audit logs with flexible filtering.
     */
    public static function search(array $filters = [], int $limit = 50, int $offset = 0): array {
        $pdo = Database::getInstance();
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int)$filters['user_id'];
        } elseif (!empty($filters['username'])) {
            $where[] = 'username LIKE ?';
            $params[] = '%' . $filters['username'] . '%';
        }

        if (!empty($filters['action'])) {
            if ($filters['action'] === 'logins') {
                $where[] = "action IN ('LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGIN_LOCKED', 'LOGIN_BLOCKED')";
            } elseif ($filters['action'] === 'pin_resets') {
                $where[] = "action IN ('PIN_RESET', 'PIN_CHANGED', 'PIN_RESET_FAILED')";
            } elseif ($filters['action'] === 'role_changes') {
                $where[] = "action IN ('ROLE_CHANGE', 'ROLE_CREATED', 'ROLE_UPDATED', 'ROLE_DEACTIVATED')";
            } elseif ($filters['action'] === 'price_changes') {
                $where[] = "action IN ('PRICE_CHANGE', 'SERVICE_PRICE_UPDATE', 'PRODUCT_PRICE_UPDATE')";
            } elseif ($filters['action'] === 'cancellations') {
                $where[] = "action IN ('ORDER_CANCEL', 'EXPENSE_CANCEL', 'PAYMENT_CANCEL', 'BOOKING_CANCEL')";
            } elseif ($filters['action'] === 'settings_changes') {
                $where[] = "action IN ('SETTING_CHANGE', 'SYSTEM_CONFIG_UPDATE', 'TEST_MODE_TOGGLE')";
            } else {
                $where[] = 'action = ?';
                $params[] = $filters['action'];
            }
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['keyword'])) {
            $where[] = '(details LIKE ? OR log_uid LIKE ? OR ip_address LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereClause = implode(' AND ', $where);

        // Count total matching
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch logs
        $stmt = $pdo->prepare("
            SELECT * FROM audit_logs
            WHERE {$whereClause}
            ORDER BY id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total' => $total,
            'records' => $records,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
}
