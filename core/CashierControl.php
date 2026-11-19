<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/TelegramNotifier.php';

class ManagerPinThrottledException extends Exception {}

class CashierControl {

    /**
     * Ensure pos_device_pin_attempts table exists for device-level rate limiting.
     */
    public static function ensurePinAttemptsTable(?PDO $pdo = null): void {
        $pdo = $pdo ?? Database::getInstance();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pos_device_pin_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                device_id INT NOT NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dev_time (device_id, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Check if a POS device is throttled due to exceeding failed manager-PIN attempts.
     * Max 10 failed manager-PIN attempts per device per 10 minutes.
     */
    public static function isDeviceThrottled(int $deviceId): bool {
        if ($deviceId <= 0) {
            return false;
        }
        $pdo = Database::getInstance();
        self::ensurePinAttemptsTable($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM pos_device_pin_attempts 
            WHERE device_id = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmt->execute([$deviceId]);
        return ((int)$stmt->fetchColumn()) >= 10;
    }

    /**
     * Record a failed manager-PIN attempt for a device.
     */
    public static function recordFailedPinAttempt(int $deviceId): void {
        if ($deviceId <= 0) {
            return;
        }
        try {
            $pdo = Database::getInstance();
            self::ensurePinAttemptsTable($pdo);
            $stmt = $pdo->prepare("INSERT INTO pos_device_pin_attempts (device_id, attempted_at) VALUES (?, NOW())");
            $stmt->execute([$deviceId]);

            // Periodic cleanup (5% chance): delete records older than 24 hours
            if (mt_rand(1, 20) === 1) {
                $pdo->exec("DELETE FROM pos_device_pin_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            }
        } catch (Exception $e) {
            // Silently continue if attempt recording fails
        }
    }

    /**
     * Clear failed manager-PIN attempts for a device upon successful authentication.
     */
    public static function clearFailedPinAttempts(int $deviceId): void {
        if ($deviceId <= 0) {
            return;
        }
        try {
            $pdo = Database::getInstance();
            self::ensurePinAttemptsTable($pdo);
            $stmt = $pdo->prepare("DELETE FROM pos_device_pin_attempts WHERE device_id = ?");
            $stmt->execute([$deviceId]);
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Ensure pos_device_login_attempts table exists for device-level login rate limiting.
     */
    public static function ensureLoginAttemptsTable(?PDO $pdo = null): void {
        $pdo = $pdo ?? Database::getInstance();
        $isSqlite = (Database::getDriver() === 'sqlite');
        if ($isSqlite) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS pos_device_login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    device_id INT NOT NULL,
                    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_dev_login_time ON pos_device_login_attempts(device_id, attempted_at);
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS pos_device_login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    device_id INT NOT NULL,
                    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_dev_login_time (device_id, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    /**
     * Check if a POS device is throttled for staff_login.
     * Max 15 failed logins per device per 10 minutes.
     */
    public static function isDeviceLoginThrottled(int $deviceId): bool {
        if ($deviceId <= 0) {
            return false;
        }
        $pdo = Database::getInstance();
        self::ensureLoginAttemptsTable($pdo);
        $isSqlite = (Database::getDriver() === 'sqlite');
        $timeClause = $isSqlite ? "datetime('now', '-10 minutes')" : "DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM pos_device_login_attempts 
            WHERE device_id = ? AND attempted_at >= {$timeClause}
        ");
        $stmt->execute([$deviceId]);
        return ((int)$stmt->fetchColumn()) >= 15;
    }

    /**
     * Record a failed staff_login attempt for a POS device.
     */
    public static function recordFailedDeviceLogin(int $deviceId): void {
        if ($deviceId <= 0) {
            return;
        }
        try {
            $pdo = Database::getInstance();
            self::ensureLoginAttemptsTable($pdo);
            $stmt = $pdo->prepare("INSERT INTO pos_device_login_attempts (device_id, attempted_at) VALUES (?, NOW())");
            $stmt->execute([$deviceId]);

            // Periodic cleanup (5% chance): delete records older than 24 hours
            if (mt_rand(1, 20) === 1) {
                $isSqlite = (Database::getDriver() === 'sqlite');
                $timeClause = $isSqlite ? "datetime('now', '-24 hours')" : "DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                $pdo->exec("DELETE FROM pos_device_login_attempts WHERE attempted_at < {$timeClause}");
            }
        } catch (Exception $e) {
            // Silently continue
        }
    }

    /**
     * Clear failed staff_login attempts for a POS device on successful login.
     */
    public static function clearFailedDeviceLogins(int $deviceId): void {
        if ($deviceId <= 0) {
            return;
        }
        try {
            $pdo = Database::getInstance();
            self::ensureLoginAttemptsTable($pdo);
            $stmt = $pdo->prepare("DELETE FROM pos_device_login_attempts WHERE device_id = ?");
            $stmt->execute([$deviceId]);
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Verify Manager or Owner 6-digit PIN for sensitive action authorization.
     * Returns manager data on success, throws Exception on failure.
     * Throws ManagerPinThrottledException if device failed 10+ attempts in 10 minutes.
     */
    public static function verifyManagerPin(string $managerUsername, string $managerPin, ?int $deviceId = null): array {
        $managerUsername = trim($managerUsername);
        $managerPin = trim($managerPin);

        $devId = $deviceId ?? ($GLOBALS['authDeviceId'] ?? null);
        if ($devId !== null && (int)$devId > 0 && self::isDeviceThrottled((int)$devId)) {
            throw new ManagerPinThrottledException("Too many failed manager PIN attempts from this device. Please wait 10 minutes.");
        }

        if ($managerUsername === '' || $managerPin === '') {
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("Invalid manager credentials");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, username, full_name, role, password_hash, local_pin_hash, status, failed_login_attempts, locked_until
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$managerUsername]);
        $mgr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mgr) {
            AuditLogger::log('MANAGER_PIN_FAILED', "Manager PIN verification failed for unknown username '{$managerUsername}'", 'auth');
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("Invalid manager credentials");
        }

        if (!in_array($mgr['role'], ['owner', 'manager', 'admin', 'system_admin'], true)) {
            AuditLogger::log('MANAGER_PIN_FAILED', "User '{$managerUsername}' has role '{$mgr['role']}' and cannot authorize manager actions.", 'auth');
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("Invalid manager credentials");
        }

        if ($mgr['status'] !== 'active') {
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("Invalid manager credentials");
        }

        // Lock check
        if (!empty($mgr['locked_until']) && strtotime($mgr['locked_until']) > time()) {
            $remMins = (int)ceil((strtotime($mgr['locked_until']) - time()) / 60);
            throw new Exception("Manager account is temporarily locked ({$remMins} minutes remaining).");
        }

        $pinValid = password_verify($managerPin, $mgr['password_hash']) || (!empty($mgr['local_pin_hash']) && password_verify($managerPin, $mgr['local_pin_hash']));
        if (!$pinValid) {
            $attempts = (int)$mgr['failed_login_attempts'] + 1;
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            if ($attempts >= 5) {
                $lockUntil = date('Y-m-d H:i:s', time() + 600);
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?")->execute([$attempts, $lockUntil, $mgr['id']]);
                AuditLogger::log('ACCOUNT_LOCKED', "Manager account '{$managerUsername}' locked for 10 minutes due to 5 failed PIN attempts", 'auth', (string)$mgr['id']);
                throw new Exception("Manager account locked for 10 minutes due to 5 wrong PIN attempts.");
            } else {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")->execute([$attempts, $mgr['id']]);
                AuditLogger::log('MANAGER_PIN_FAILED', "Manager PIN authorization failed ({$attempts}/5) for '{$managerUsername}'", 'auth', (string)$mgr['id']);
                throw new Exception("Invalid manager credentials");
            }
        }

        // Reset failed attempts on success
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$mgr['id']]);
        if ($devId !== null && (int)$devId > 0) {
            self::clearFailedPinAttempts((int)$devId);
        }

        return [
            'manager_id'       => (int)$mgr['id'],
            'manager_username' => $mgr['username'],
            'username'         => $mgr['username'],
            'manager_name'     => $mgr['full_name'],
            'manager_role'     => $mgr['role']
        ];
    }

    /**
     * Verify credentials for Close Day (Z-Report).
     * Allows Owner, Manager, Admin, or Cashier with daily_close.close permission.
     */
    public static function verifyCloseDayUser(string $username, string $pin, ?int $deviceId = null): array {
        $username = trim($username);
        $pin = trim($pin);

        $devId = $deviceId ?? ($GLOBALS['authDeviceId'] ?? null);
        if ($devId !== null && (int)$devId > 0 && self::isDeviceThrottled((int)$devId)) {
            throw new ManagerPinThrottledException("Too many failed PIN attempts from this device. Please wait 10 minutes.");
        }

        if ($username === '' || $pin === '') {
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("Invalid credentials");
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, username, full_name, role, password_hash, local_pin_hash, status, failed_login_attempts, locked_until
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$u) {
            AuditLogger::log('CLOSE_DAY_PIN_FAILED', "PIN verification failed for unknown username '{$username}'", 'auth');
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("Invalid credentials");
        }

        // Role / Permission check: Owner, Admin, Manager, or Cashier with daily_close.close permission
        $isAllowedRole = in_array($u['role'], ['owner', 'manager', 'admin', 'system_admin', 'cashier'], true);
        $hasPermission = (class_exists('Auth') && (Auth::userHasPermission((int)$u['id'], 'daily_close.close') || Auth::userHasPermission((int)$u['id'], 'pos.close_day')));
        if (!$isAllowedRole && !$hasPermission) {
            AuditLogger::log('CLOSE_DAY_PIN_FAILED', "User '{$username}' has role '{$u['role']}' and cannot close day.", 'auth');
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("User does not have permission to close the day.");
        }

        if ($u['status'] !== 'active') {
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            throw new Exception("Invalid credentials");
        }

        // Lock check
        if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()) {
            $remMins = (int)ceil((strtotime($u['locked_until']) - time()) / 60);
            throw new Exception("Account is temporarily locked ({$remMins} minutes remaining).");
        }

        $pinValid = password_verify($pin, $u['password_hash']) || (!empty($u['local_pin_hash']) && password_verify($pin, $u['local_pin_hash']));
        if (!$pinValid) {
            $attempts = (int)$u['failed_login_attempts'] + 1;
            if ($devId !== null && (int)$devId > 0) {
                self::recordFailedPinAttempt((int)$devId);
            }
            if ($attempts >= 5) {
                $lockUntil = date('Y-m-d H:i:s', time() + 600);
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?")->execute([$attempts, $lockUntil, $u['id']]);
                AuditLogger::log('ACCOUNT_LOCKED', "Account '{$username}' locked for 10 minutes due to 5 failed PIN attempts", 'auth', (string)$u['id']);
                throw new Exception("Account locked for 10 minutes due to 5 wrong PIN attempts.");
            } else {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")->execute([$attempts, $u['id']]);
                AuditLogger::log('CLOSE_DAY_PIN_FAILED', "PIN authorization failed ({$attempts}/5) for '{$username}'", 'auth', (string)$u['id']);
                throw new Exception("Invalid credentials");
            }
        }

        if ((int)$u['failed_login_attempts'] > 0) {
            $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$u['id']]);
        }
        if ($devId !== null && (int)$devId > 0) {
            self::clearFailedPinAttempts((int)$devId);
        }

        $resolvedName = !empty($u['full_name']) ? $u['full_name'] : $u['username'];
        return [
            'user_id'          => (int)$u['id'],
            'username'         => $u['username'],
            'user_name'        => $resolvedName,
            'user_role'        => $u['role'],
            'manager_id'       => (int)$u['id'],
            'manager_username' => $u['username'],
            'manager_name'     => $resolvedName,
            'manager_role'     => $u['role']
        ];
    }

    /**
     * Ensure additional shift columns exist for manager review.
     */
    public static function ensureShiftColumns(?PDO $pdo = null): void {
        $pdo = $pdo ?? Database::getInstance();
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM cashier_shifts")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($cols, 'Field');
            if (!in_array('review_reason', $names, true)) {
                $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN review_reason TEXT DEFAULT NULL AFTER notes");
            }
            if (!in_array('reviewed_by_manager_id', $names, true)) {
                $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN reviewed_by_manager_id INT DEFAULT NULL AFTER review_reason");
            }
            if (!in_array('reviewed_by_manager_name', $names, true)) {
                $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN reviewed_by_manager_name VARCHAR(100) DEFAULT NULL AFTER reviewed_by_manager_id");
            }
            if (!in_array('reviewed_at', $names, true)) {
                $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by_manager_name");
            }
        } catch (Throwable $t) {
            // Silently continue if SQLite or already exists
        }
    }

    /**
     * Helper to verify a user's 6-digit PIN against both local_pin_hash and password_hash.
     */
    public static function verifyUserPin(int $userId, string $pin, ?PDO $pdo = null): bool {
        $pdo = $pdo ?? Database::getInstance();
        $pin = trim($pin);
        if ($pin === '' || $userId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id, username, password_hash, local_pin_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return false;
        }
        if (!empty($user['local_pin_hash']) && password_verify($pin, $user['local_pin_hash'])) {
            return true;
        }
        if (!empty($user['password_hash']) && password_verify($pin, $user['password_hash'])) {
            return true;
        }
        return false;
    }

    /**
     * Mark any shift left open from a previous day as 'needs_review'.
     * Rule: Never auto-close; manager must review and close with counted cash.
     */
    public static function markOvernightShiftsForReview(?PDO $pdo = null): int {
        $pdo = $pdo ?? Database::getInstance();
        self::ensureShiftColumns($pdo);
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            UPDATE cashier_shifts 
            SET status = 'needs_review' 
            WHERE status = 'open' AND DATE(started_at) < ?
        ");
        $stmt->execute([$today]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            AuditLogger::log('SHIFTS_FLAGGED_FOR_REVIEW', "Flagged {$count} overnight shift(s) as 'needs_review' for manager inspection", 'shift');
        }
        return $count;
    }

    /**
     * Get old shift left open from previous day needing manager review.
     */
    public static function getOldShiftNeedingReview(int $cashierId, ?PDO $pdo = null): ?array {
        $pdo = $pdo ?? Database::getInstance();
        self::markOvernightShiftsForReview($pdo);
        $stmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE cashier_id = ? AND status = 'needs_review' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cashierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all shifts left open from previous days needing manager review.
     */
    public static function getAllShiftsNeedingReview(?PDO $pdo = null): array {
        $pdo = $pdo ?? Database::getInstance();
        self::markOvernightShiftsForReview($pdo);
        $stmt = $pdo->query("SELECT * FROM cashier_shifts WHERE status = 'needs_review' ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        ?string $notes = null,
        ?string $customStartedAt = null
    ): array {
        $pdo = Database::getInstance();
        self::markOvernightShiftsForReview($pdo);

        $startedAtStr = $customStartedAt ?: date('Y-m-d H:i:s');
        $today = date('Y-m-d', strtotime($startedAtStr));

        // Check if day is locked (e.g. today has already been closed by manager)
        $dayLock = self::isDayLocked($today);
        if (!empty($dayLock['locked'])) {
            throw new Exception($dayLock['message']);
        }

        // Check if there is an unreviewed shift from previous days for this cashier
        $oldReview = self::getOldShiftNeedingReview($cashierId, $pdo);
        if ($oldReview) {
            throw new Exception("You have an unfinished shift from " . date('M d, Y', strtotime($oldReview['started_at'])) . " that needs manager review before starting a new shift.");
        }

        // Check if there is already an active shift for this cashier today
        $checkStmt = $pdo->prepare("SELECT id, shift_uid, started_at FROM cashier_shifts WHERE cashier_id = ? AND status = 'open' AND DATE(started_at) = ? LIMIT 1");
        $checkStmt->execute([$cashierId, $today]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $slipData = self::getOpenShiftSlipData((int)$existing['id']);
            return [
                'success' => true,
                'already_active' => true,
                'shift_id' => (int)$existing['id'],
                'shift_uid' => $existing['shift_uid'],
                'opening_cash_usd' => $openingUsd,
                'opening_cash_khr' => $openingKhr,
                'slip' => $slipData,
                'message' => 'Shift is already open for today.'
            ];
        }

        $exchangeRate = get_exchange_rate();
        $shiftUid = generate_unique_id('SFT');
        $stmt = $pdo->prepare("
            INSERT INTO cashier_shifts (
                shift_uid, pos_device_id, pos_device_name, cashier_id, cashier_name,
                started_at, opening_cash_usd, opening_cash_khr, exchange_rate, status, notes, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, 1, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $shiftUid,
            $deviceId,
            $deviceName,
            $cashierId,
            $cashierName,
            $startedAtStr,
            $openingUsd,
            $openingKhr,
            $exchangeRate,
            $notes
        ]);
        $shiftId = (int)$pdo->lastInsertId();

        $uRoleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $uRoleStmt->execute([$cashierId]);
        $userRole = $uRoleStmt->fetchColumn() ?: 'cashier';
        $userRoleLabel = ucfirst($userRole);

        // Check if this is the first shift of the day (Open Day)
        $chkFirst = $pdo->prepare("SELECT COUNT(*) FROM cashier_shifts WHERE DATE(started_at) = ? AND id != ?");
        $chkFirst->execute([$today, $shiftId]);
        $isFirstShiftOfDay = ((int)$chkFirst->fetchColumn() === 0);

        AuditLogger::log('SHIFT_START', "{$userRoleLabel} {$cashierName} started shift with starting cash $" . number_format($openingUsd, 2), 'shift', (string)$shiftId, $cashierId, $cashierName, $userRole);

        if ($isFirstShiftOfDay) {
            AuditLogger::log('DAY_OPENED', "Day {$today} opened by {$userRoleLabel} {$cashierName} with opening float $" . number_format($openingUsd, 2), 'day_open', (string)$shiftId, $cashierId, $cashierName, $userRole);
        }

        $slipData = self::getOpenShiftSlipData($shiftId);

        return [
            'success' => true,
            'shift_id' => $shiftId,
            'shift_uid' => $shiftUid,
            'opening_cash_usd' => $openingUsd,
            'opening_cash_khr' => $openingKhr,
            'exchange_rate' => $exchangeRate,
            'slip' => $slipData,
            'message' => 'Shift started successfully with change fund $' . number_format($openingUsd, 2)
        ];
    }

    /**
     * Get active shift for a cashier for TODAY.
     */
    public static function getActiveShift(int $cashierId, ?PDO $pdo = null): ?array {
        $pdo = $pdo ?? Database::getInstance();
        self::markOvernightShiftsForReview($pdo);
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE cashier_id = ? AND status = 'open' AND DATE(started_at) = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cashierId, $today]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Alias for getActiveShift explicitly for today.
     */
    public static function getActiveShiftForToday(int $cashierId, ?PDO $pdo = null): ?array {
        return self::getActiveShift($cashierId, $pdo);
    }

    /**
     * Calculate current expected cash in drawer for an active shift.
     * Formula: Expected cash = opening float + cash received - change given - cash refunds paid - cash out + cash in.
     * ABA and KHQR payments are strictly separated and NEVER counted in drawer cash.
     */
    public static function calculateShiftExpectedCash(int $shiftId): array {
        $pdo = Database::getInstance();
        $sStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
        $sStmt->execute([$shiftId]);
        $shift = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            return [
                'expected_cash_usd' => 0.00,
                'expected_cash_khr' => 0,
                'opening_cash_usd'  => 0.00,
                'opening_cash_khr'  => 0,
                'cash_received_usd' => 0.00,
                'cash_received_khr' => 0,
                'change_given_usd'  => 0.00,
                'change_given_khr'  => 0,
                'cash_refunds_usd'  => 0.00,
                'cash_refunds_khr'  => 0,
                'cash_out_usd'      => 0.00,
                'cash_out_khr'      => 0,
                'cash_in_usd'       => 0.00,
                'cash_in_khr'       => 0,
                'cash_sales_count'  => 0,
                'cash_sales_usd'    => 0.00,
                'cash_sales_khr'    => 0,
                'bank_sales_count'  => 0,
                'bank_sales_usd'    => 0.00,
                'total_orders_count'=> 0,
                'total_sales_usd'   => 0.00,
                'exchange_rate'     => get_exchange_rate()
            ];
        }

        $startTime = $shift['started_at'];
        $endTime = $shift['ended_at'] ?? date('Y-m-d H:i:s');
        $cashierId = (int)$shift['cashier_id'];
        $rate = (int)($shift['exchange_rate'] ?: get_exchange_rate());

        // 1. Cash sales in shift: track cash received (tendered) and change given
        $salesStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(total_usd), 0) as cash_usd,
                COALESCE(SUM(total_khr), 0) as cash_khr,
                COALESCE(SUM(CASE WHEN amount_tendered_usd IS NOT NULL AND amount_tendered_usd > 0 THEN amount_tendered_usd ELSE (CASE WHEN amount_tendered_khr IS NOT NULL AND amount_tendered_khr > 0 THEN 0 ELSE total_usd END) END), 0) as tendered_usd,
                COALESCE(SUM(COALESCE(amount_tendered_khr, 0)), 0) as tendered_khr,
                COALESCE(SUM(COALESCE(change_usd, 0)), 0) as change_usd,
                COALESCE(SUM(COALESCE(change_khr, 0)), 0) as change_khr
            FROM orders
            WHERE (shift_id = ? OR (shift_id IS NULL AND cashier_id = ? AND created_at >= ? AND created_at <= ?))
              AND payment_method = 'cash'
              AND (status = 'paid' OR payment_status = 'paid')
              AND status != 'cancelled'
        ");
        $salesStmt->execute([$shiftId, $cashierId, $startTime, $endTime]);
        $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);

        $cashSalesCount = (int)($sales['count'] ?? 0);
        $cashSalesUsd = (float)($sales['cash_usd'] ?? 0);
        $cashSalesKhr = (int)($sales['cash_khr'] ?? 0);
        $cashReceivedUsd = (float)($sales['tendered_usd'] ?? 0);
        $cashReceivedKhr = (int)($sales['tendered_khr'] ?? 0);
        $changeGivenUsd = (float)($sales['change_usd'] ?? 0);
        $changeGivenKhr = (int)($sales['change_khr'] ?? 0);

        // 2. Bank / Digital Sales (ABA and KHQR) - NEVER COUNTED IN DRAWER CASH!
        $bankSalesStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(total_usd), 0) as bank_usd
            FROM orders
            WHERE (shift_id = ? OR (shift_id IS NULL AND cashier_id = ? AND created_at >= ? AND created_at <= ?))
              AND payment_method IN ('aba', 'khqr')
              AND (status = 'paid' OR payment_status = 'paid')
              AND status != 'cancelled'
        ");
        $bankSalesStmt->execute([$shiftId, $cashierId, $startTime, $endTime]);
        $bankSales = $bankSalesStmt->fetch(PDO::FETCH_ASSOC);
        $bankSalesCount = (int)($bankSales['count'] ?? 0);
        $bankSalesUsd = (float)($bankSales['bank_usd'] ?? 0);

        $totalOrdersCount = $cashSalesCount + $bankSalesCount;
        $totalSalesUsd = round($cashSalesUsd + $bankSalesUsd, 2);

        // 3. Cash refunds given in shift
        $refStmt = $pdo->prepare("
            SELECT COALESCE(SUM(refund_amount_usd), 0) as ref_usd
            FROM orders
            WHERE (shift_id = ? OR (shift_id IS NULL AND cashier_id = ? AND refunded_at >= ? AND refunded_at <= ?))
              AND payment_method = 'cash'
              AND refund_status = 'refunded'
        ");
        $refStmt->execute([$shiftId, $cashierId, $startTime, $endTime]);
        $cashRefundsUsd = (float)($refStmt->fetchColumn() ?: 0);
        $cashRefundsKhr = 0;

        // 4. Cash In & Cash Out from drawer_events and expenses
        $drawerStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN event_type = 'cash_in' THEN amount_usd ELSE 0 END), 0) as in_usd,
                COALESCE(SUM(CASE WHEN event_type = 'cash_in' THEN amount_khr ELSE 0 END), 0) as in_khr,
                COALESCE(SUM(CASE WHEN event_type = 'cash_out' THEN amount_usd ELSE 0 END), 0) as out_usd,
                COALESCE(SUM(CASE WHEN event_type = 'cash_out' THEN amount_khr ELSE 0 END), 0) as out_khr
            FROM drawer_events
            WHERE (shift_id = ? OR (shift_id IS NULL AND cashier_id = ? AND created_at >= ? AND created_at <= ?))
              AND is_active = 1
        ");
        $drawerStmt->execute([$shiftId, $cashierId, $startTime, $endTime]);
        $drawerEvents = $drawerStmt->fetch(PDO::FETCH_ASSOC);

        $cashInUsd = (float)($drawerEvents['in_usd'] ?? 0);
        $cashInKhr = (int)($drawerEvents['in_khr'] ?? 0);
        $cashOutUsd = (float)($drawerEvents['out_usd'] ?? 0);
        $cashOutKhr = (int)($drawerEvents['out_khr'] ?? 0);

        // Also check any general expenses recorded during shift
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
        if ($expUsd > $cashOutUsd) {
            $cashOutUsd = $expUsd;
        }
        if ($expKhr > $cashOutKhr) {
            $cashOutKhr = $expKhr;
        }

        $openingUsd = (float)$shift['opening_cash_usd'];
        $openingKhr = (int)$shift['opening_cash_khr'];

        // Drawer calculation formula:
        // Expected cash = opening float + cash received - change given - cash refunds paid - cash out + cash in
        $expectedUsd = round($openingUsd + $cashReceivedUsd - $changeGivenUsd - $cashRefundsUsd - $cashOutUsd + $cashInUsd, 2);
        $expectedKhr = (int)round($openingKhr + $cashReceivedKhr - $changeGivenKhr - $cashRefundsKhr - $cashOutKhr + $cashInKhr);

        return [
            'opening_cash_usd'  => $openingUsd,
            'opening_cash_khr'  => $openingKhr,
            'exchange_rate'     => $rate,
            'cash_received_usd' => $cashReceivedUsd,
            'cash_received_khr' => $cashReceivedKhr,
            'change_given_usd'  => $changeGivenUsd,
            'change_given_khr'  => $changeGivenKhr,
            'cash_refunds_usd'  => $cashRefundsUsd,
            'cash_refunds_khr'  => $cashRefundsKhr,
            'cash_out_usd'      => $cashOutUsd,
            'cash_out_khr'      => $cashOutKhr,
            'cash_in_usd'       => $cashInUsd,
            'cash_in_khr'       => $cashInKhr,
            'expected_cash_usd' => $expectedUsd,
            'expected_cash_khr' => $expectedKhr,
            'cash_sales_count'  => $cashSalesCount,
            'cash_sales_usd'    => $cashSalesUsd,
            'cash_sales_khr'    => $cashSalesKhr,
            'bank_sales_count'  => $bankSalesCount,
            'bank_sales_usd'    => $bankSalesUsd,
            'total_orders_count'=> $totalOrdersCount,
            'total_sales_usd'   => $totalSalesUsd,
        ];
    }

    /**
     * Record Cash In or Cash Out event for the active drawer / shift.
     */
    public static function recordCashMovement(
        string $type,
        float $amountUsd,
        int $amountKhr,
        string $reason,
        int $cashierId,
        ?int $deviceId = null,
        ?string $mgrUser = null,
        ?string $mgrPin = null
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("Reason for cash movement is required.");
        }
        if ($amountUsd <= 0 && $amountKhr <= 0) {
            throw new Exception("Amount in USD or KHR must be greater than zero.");
        }
        $pdo = Database::getInstance();
        $type = in_array(strtolower($type), ['cash_in', 'in'], true) ? 'cash_in' : 'cash_out';

        $cStmt = $pdo->prepare("SELECT id, full_name, username FROM users WHERE id = ?");
        $cStmt->execute([$cashierId]);
        $cashier = $cStmt->fetch(PDO::FETCH_ASSOC);
        $cashierName = $cashier['full_name'] ?? ('Cashier #' . $cashierId);

        $dayLock = self::isDayLocked();
        if (!empty($dayLock['locked'])) {
            throw new Exception("Action rejected: Business day is closed.");
        }

        $activeShift = self::getActiveShift($cashierId, $pdo);
        if (!$activeShift) {
            throw new Exception("Action rejected: No active shift is open for this cashier. Please open a shift first.");
        }
        $shiftId = (int)$activeShift['id'];

        $rate = (int)($activeShift['exchange_rate'] ?: get_exchange_rate());
        $totalUsdEquivalent = $amountUsd + ($amountKhr > 0 ? ($amountKhr / max(1, $rate)) : 0.0);

        $mgrInfo = null;
        if ($type === 'cash_out' && $totalUsdEquivalent > 10.00) {
            if (empty($mgrUser) || empty($mgrPin)) {
                throw new Exception("Manager authorization (username & PIN) is required for Cash Out greater than $10.");
            }
            $mgrInfo = self::verifyManagerPin($mgrUser, $mgrPin, $deviceId);
        } elseif (!empty($mgrUser) && !empty($mgrPin)) {
            $mgrInfo = self::verifyManagerPin($mgrUser, $mgrPin, $deviceId);
        }

        $eventUid = generate_unique_id('DRW');
        $ins = $pdo->prepare("
            INSERT INTO drawer_events (
                event_uid, event_type, pos_device_id, shift_id, cashier_id, cashier_name,
                amount_usd, amount_khr, manager_id, manager_name, reason, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            $eventUid,
            $type,
            $deviceId,
            $shiftId,
            $cashierId,
            $cashierName,
            $amountUsd,
            $amountKhr,
            $mgrInfo['manager_id'] ?? null,
            $mgrInfo['manager_name'] ?? null,
            $reason
        ]);

        // If cash out, also record in expenses table so general ledger matches
        if ($type === 'cash_out') {
            try {
                $expIns = $pdo->prepare("
                    INSERT INTO expenses (category, description, amount_usd, amount_khr, expense_date, recorded_by, is_active, created_at)
                    VALUES ('Drawer Cash Out', ?, ?, ?, CURDATE(), ?, 1, CURRENT_TIMESTAMP)
                ");
                $expIns->execute([
                    $reason . " (Recorded by {$cashierName})",
                    $amountUsd,
                    $amountKhr,
                    $cashierId
                ]);
            } catch (Exception $e) {
                // Ignore if expense table differs
            }
        }

        $typeLabel = ($type === 'cash_in') ? 'Cash In' : 'Cash Out';
        $logMsg = "Drawer {$typeLabel}: \${$amountUsd} / " . number_format($amountKhr) . " KHR. Reason: {$reason}. Cashier: {$cashierName}";
        AuditLogger::log(strtoupper($type), $logMsg, 'drawer', $eventUid, $cashierId, $cashier['username'] ?? '', 'cashier');

        return [
            'success' => true,
            'event_uid' => $eventUid,
            'type' => $type,
            'amount_usd' => $amountUsd,
            'amount_khr' => $amountKhr,
            'shift_id' => $shiftId,
            'cashier_name' => $cashierName,
            'manager_name' => $mgrInfo['manager_name'] ?? null,
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
            'message' => "Drawer {$typeLabel} of \${$amountUsd} / " . number_format($amountKhr) . " KHR recorded."
        ];
    }

    /**
     * End Shift / Handover:
     * - Outgoing cashier enters blind counted cash and confirms their PIN.
     * - Incoming cashier enters their username and PIN to sign off on handover.
     * - Calculates expected cash, computes difference.
     * - Saves all snapshot metrics permanently to cashier_shifts.
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
        $outUserStmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ?");
        $outUserStmt->execute([(int)$shift['cashier_id']]);
        $outUser = $outUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$outUser || !self::verifyUserPin((int)$outUser['id'], $outgoingPin, $pdo)) {
            AuditLogger::log('SHIFT_HANDOVER_FAILED', "Outgoing cashier PIN verification failed for {$shift['cashier_name']}", 'shift', (string)$shiftId);
            throw new Exception("Invalid PIN for outgoing cashier ({$shift['cashier_name']}).");
        }

        // 2. Verify Incoming Cashier PIN
        $inUserStmt = $pdo->prepare("SELECT id, username, full_name, role, status FROM users WHERE username = ?");
        $inUserStmt->execute([trim($incomingUsername)]);
        $inUser = $inUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$inUser || $inUser['status'] !== 'active') {
            throw new Exception("Incoming cashier account '{$incomingUsername}' not found or inactive.");
        }

        if (!self::verifyUserPin((int)$inUser['id'], $incomingPin, $pdo)) {
            AuditLogger::log('SHIFT_HANDOVER_FAILED', "Incoming cashier PIN verification failed for {$incomingUsername}", 'shift', (string)$shiftId);
            throw new Exception("Invalid PIN for incoming cashier ({$incomingUsername}).");
        }

        // 3. Calculate expected cash
        $calc = self::calculateShiftExpectedCash($shiftId);
        $expectedUsd = (float)$calc['expected_cash_usd'];
        $expectedKhr = (int)$calc['expected_cash_khr'];
        $rate = (int)($shift['exchange_rate'] ?: get_exchange_rate());

        $diffUsd = round($countedUsd - $expectedUsd, 2);
        $diffKhr = $countedKhr - $expectedKhr;
        $totalDiffUsd = round($diffUsd + ($diffKhr / $rate), 2);
        $shortageOwedUsd = ($totalDiffUsd < 0) ? abs($totalDiffUsd) : 0.00;

        // 4. Update Shift record with permanent snapshot metrics
        $upd = $pdo->prepare("
            UPDATE cashier_shifts SET
                ended_at = CURRENT_TIMESTAMP,
                exchange_rate = ?,
                expected_cash_usd = ?,
                expected_cash_khr = ?,
                counted_cash_usd = ?,
                counted_cash_khr = ?,
                difference_usd = ?,
                difference_khr = ?,
                total_difference_usd = ?,
                cash_received_usd = ?,
                cash_received_khr = ?,
                change_given_usd = ?,
                change_given_khr = ?,
                cash_refunds_usd = ?,
                cash_refunds_khr = ?,
                cash_out_usd = ?,
                cash_out_khr = ?,
                cash_in_usd = ?,
                cash_in_khr = ?,
                cash_sales_count = ?,
                cash_sales_usd = ?,
                cash_sales_khr = ?,
                bank_sales_count = ?,
                bank_sales_usd = ?,
                total_orders_count = ?,
                total_sales_usd = ?,
                shortage_owed_usd = ?,
                handover_to_cashier_id = ?,
                handover_to_cashier_name = ?,
                status = 'closed',
                notes = ?,
                is_active = 0
            WHERE id = ?
        ");
        $upd->execute([
            $rate,
            $expectedUsd,
            $expectedKhr,
            $countedUsd,
            $countedKhr,
            $diffUsd,
            $diffKhr,
            $totalDiffUsd,
            $calc['cash_received_usd'],
            $calc['cash_received_khr'],
            $calc['change_given_usd'],
            $calc['change_given_khr'],
            $calc['cash_refunds_usd'],
            $calc['cash_refunds_khr'],
            $calc['cash_out_usd'],
            $calc['cash_out_khr'],
            $calc['cash_in_usd'],
            $calc['cash_in_khr'],
            $calc['cash_sales_count'],
            $calc['cash_sales_usd'],
            $calc['cash_sales_khr'],
            $calc['bank_sales_count'],
            $calc['bank_sales_usd'],
            $calc['total_orders_count'],
            $calc['total_sales_usd'],
            $shortageOwedUsd,
            $inUser['id'],
            $inUser['full_name'],
            $notes,
            $shiftId
        ]);

        // 5. Audit Log
        $handoverDetails = "Shift {$shift['shift_uid']} handover from {$outUser['full_name']} to {$inUser['full_name']}. Counted: $" . number_format($countedUsd, 2) . " / " . number_format($countedKhr) . " KHR, Expected: $" . number_format($expectedUsd, 2) . " / " . number_format($expectedKhr) . " KHR, Diff: $" . number_format($diffUsd, 2) . " (Total Diff: $" . number_format($totalDiffUsd, 2) . ")";
        AuditLogger::log('SHIFT_HANDOVER', $handoverDetails, 'shift', (string)$shiftId, (int)$outUser['id'], $outUser['username'], 'cashier');

        if ($shortageOwedUsd > 0 || $totalDiffUsd < -0.01) {
            AuditLogger::log('SHIFT_SHORTAGE', "Cash shortage of $" . number_format($shortageOwedUsd, 2) . " recorded as owed by Cashier {$outUser['full_name']} (#{$outUser['id']})", 'shift', (string)$shiftId, (int)$outUser['id'], $outUser['username'], 'cashier');
            TelegramNotifier::notifyShiftShortage((string)$outUser['full_name'], $diffUsd, $diffKhr, (string)$shift['shift_uid'], $totalDiffUsd);
        } elseif ($totalDiffUsd > 0.01) {
            TelegramNotifier::notifyShiftOver((string)$outUser['full_name'], $diffUsd, $diffKhr, (string)$shift['shift_uid'], $totalDiffUsd);
        }

        $slip = self::getEndShiftSlipData($shiftId);

        return [
            'success'              => true,
            'shift_id'             => $shiftId,
            'shift_uid'            => $shift['shift_uid'],
            'outgoing_cashier'     => $outUser['full_name'],
            'incoming_cashier'     => $inUser['full_name'],
            'counted_cash_usd'     => $countedUsd,
            'counted_cash_khr'     => $countedKhr,
            'expected_cash_usd'    => $expectedUsd,
            'expected_cash_khr'    => $expectedKhr,
            'difference_usd'       => $diffUsd,
            'difference_khr'       => $diffKhr,
            'total_difference_usd' => $totalDiffUsd,
            'diff_usd_status'      => ($diffUsd < 0 ? 'short' : ($diffUsd > 0 ? 'over' : 'exact')),
            'diff_khr_status'      => ($diffKhr < 0 ? 'short' : ($diffKhr > 0 ? 'over' : 'exact')),
            'total_diff_status'    => ($totalDiffUsd < 0 ? 'short' : ($totalDiffUsd > 0 ? 'over' : 'exact')),
            'shortage_owed_usd'    => $shortageOwedUsd,
            'has_shortage'         => ($shortageOwedUsd > 0),
            'slip'                 => $slip,
            'message'              => 'Shift ended and handover signed off successfully.'
        ];
    }

    /**
     * Solo End Shift (Single Cashier / Final Shift of the day):
     * Requires only the cashier's own PIN.
     * Cashier enters counted cash; expected cash and difference are calculated, saved permanently, and returned for printing.
     */
    public static function endShiftSolo(
        int $shiftId,
        float $countedUsd,
        int $countedKhr,
        string $cashierPin,
        ?string $notes = null
    ): array {
        $pdo = Database::getInstance();
        self::ensureShiftColumns($pdo);
        $sStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
        $sStmt->execute([$shiftId]);
        $shift = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift || in_array($shift['status'], ['closed', 'ended'], true)) {
            throw new Exception("Shift not found or already closed.");
        }

        // 1. Verify Cashier PIN
        $cashierId = (int)$shift['cashier_id'];
        if (!self::verifyUserPin($cashierId, $cashierPin, $pdo)) {
            AuditLogger::log('SHIFT_END_FAILED', "PIN verification failed for cashier {$shift['cashier_name']}", 'shift', (string)$shiftId);
            throw new Exception("Invalid PIN for cashier ({$shift['cashier_name']}).");
        }

        // 2. Calculate expected cash
        $calc = self::calculateShiftExpectedCash($shiftId);
        $expectedUsd = (float)$calc['expected_cash_usd'];
        $expectedKhr = (int)$calc['expected_cash_khr'];
        $rate = (int)($shift['exchange_rate'] ?: get_exchange_rate());

        $diffUsd = round($countedUsd - $expectedUsd, 2);
        $diffKhr = $countedKhr - $expectedKhr;
        $totalDiffUsd = round($diffUsd + ($diffKhr / $rate), 2);
        $shortageOwedUsd = ($totalDiffUsd < 0) ? abs($totalDiffUsd) : 0.00;

        $endNotes = trim(($notes ? $notes . " | " : "") . "[Solo End Shift - Signed off by {$shift['cashier_name']}]");

        // 3. Update shift record with permanent snapshot metrics
        $upd = $pdo->prepare("
            UPDATE cashier_shifts SET
                ended_at = CURRENT_TIMESTAMP,
                exchange_rate = ?,
                expected_cash_usd = ?,
                expected_cash_khr = ?,
                counted_cash_usd = ?,
                counted_cash_khr = ?,
                difference_usd = ?,
                difference_khr = ?,
                total_difference_usd = ?,
                cash_received_usd = ?,
                cash_received_khr = ?,
                change_given_usd = ?,
                change_given_khr = ?,
                cash_refunds_usd = ?,
                cash_refunds_khr = ?,
                cash_out_usd = ?,
                cash_out_khr = ?,
                cash_in_usd = ?,
                cash_in_khr = ?,
                cash_sales_count = ?,
                cash_sales_usd = ?,
                cash_sales_khr = ?,
                bank_sales_count = ?,
                bank_sales_usd = ?,
                total_orders_count = ?,
                total_sales_usd = ?,
                shortage_owed_usd = ?,
                status = 'closed',
                notes = ?,
                is_active = 0
            WHERE id = ?
        ");
        $upd->execute([
            $rate,
            $expectedUsd,
            $expectedKhr,
            $countedUsd,
            $countedKhr,
            $diffUsd,
            $diffKhr,
            $totalDiffUsd,
            $calc['cash_received_usd'],
            $calc['cash_received_khr'],
            $calc['change_given_usd'],
            $calc['change_given_khr'],
            $calc['cash_refunds_usd'],
            $calc['cash_refunds_khr'],
            $calc['cash_out_usd'],
            $calc['cash_out_khr'],
            $calc['cash_in_usd'],
            $calc['cash_in_khr'],
            $calc['cash_sales_count'],
            $calc['cash_sales_usd'],
            $calc['cash_sales_khr'],
            $calc['bank_sales_count'],
            $calc['bank_sales_usd'],
            $calc['total_orders_count'],
            $calc['total_sales_usd'],
            $shortageOwedUsd,
            $endNotes,
            $shiftId
        ]);

        AuditLogger::log(
            'SHIFT_ENDED_SOLO',
            "Cashier {$shift['cashier_name']} solo-closed shift #{$shift['shift_uid']}. Expected: \${$expectedUsd} / {$expectedKhr}៛, Counted: \${$countedUsd} / {$countedKhr}៛, Diff: \${$diffUsd} (Total Diff: \${$totalDiffUsd})",
            'shift',
            (string)$shiftId,
            $cashierId,
            $shift['cashier_name'],
            'cashier'
        );

        if ($shortageOwedUsd > 0 || $totalDiffUsd < -0.01) {
            AuditLogger::log(
                'SHIFT_SHORTAGE',
                "Cash shortage of $" . number_format($shortageOwedUsd, 2) . " recorded on solo-closed shift #{$shift['shift_uid']}",
                'shift',
                (string)$shiftId,
                $cashierId,
                $shift['cashier_name'],
                'cashier'
            );
            TelegramNotifier::notifyShiftShortage((string)$shift['cashier_name'], $diffUsd, $diffKhr, (string)$shift['shift_uid'], $totalDiffUsd);
        } elseif ($totalDiffUsd > 0.01) {
            TelegramNotifier::notifyShiftOver((string)$shift['cashier_name'], $diffUsd, $diffKhr, (string)$shift['shift_uid'], $totalDiffUsd);
        }

        $slip = self::getEndShiftSlipData($shiftId);

        return [
            'success'              => true,
            'is_solo'              => true,
            'shift_id'             => $shiftId,
            'shift_uid'            => $shift['shift_uid'],
            'cashier_name'         => $shift['cashier_name'],
            'started_at'           => $shift['started_at'],
            'ended_at'             => date('Y-m-d H:i:s'),
            'opening_cash_usd'     => (float)$shift['opening_cash_usd'],
            'opening_cash_khr'     => (int)$shift['opening_cash_khr'],
            'exchange_rate'        => $rate,
            'cash_received_usd'    => $calc['cash_received_usd'],
            'cash_received_khr'    => $calc['cash_received_khr'],
            'change_given_usd'     => $calc['change_given_usd'],
            'change_given_khr'     => $calc['change_given_khr'],
            'cash_sales_usd'       => $calc['cash_sales_usd'],
            'cash_sales_khr'       => $calc['cash_sales_khr'],
            'cash_sales_count'     => $calc['cash_sales_count'],
            'bank_sales_usd'       => $calc['bank_sales_usd'],
            'bank_sales_count'     => $calc['bank_sales_count'],
            'total_sales_usd'      => $calc['total_sales_usd'],
            'expenses_usd'         => $calc['cash_out_usd'],
            'refunds_usd'          => $calc['cash_refunds_usd'],
            'expected_cash_usd'    => $expectedUsd,
            'expected_cash_khr'    => $expectedKhr,
            'counted_cash_usd'     => $countedUsd,
            'counted_cash_khr'     => $countedKhr,
            'difference_usd'       => $diffUsd,
            'difference_khr'       => $diffKhr,
            'total_difference_usd' => $totalDiffUsd,
            'diff_usd_status'      => ($diffUsd < 0 ? 'short' : ($diffUsd > 0 ? 'over' : 'exact')),
            'diff_khr_status'      => ($diffKhr < 0 ? 'short' : ($diffKhr > 0 ? 'over' : 'exact')),
            'total_diff_status'    => ($totalDiffUsd < 0 ? 'short' : ($totalDiffUsd > 0 ? 'over' : 'exact')),
            'has_shortage'         => ($totalDiffUsd < 0),
            'shortage_owed_usd'    => $shortageOwedUsd,
            'slip'                 => $slip,
            'message'              => 'Shift ended successfully. Cash drawer counted and recorded.'
        ];
    }

    /**
     * Manager Review & Close of Old Shift Left Open from Previous Day:
     * Rule: Never auto-closed. Manager must count the cash and close it with a written reason.
     */
    public static function managerCloseReviewShift(
        int $shiftId,
        float $countedUsd,
        int $countedKhr,
        string $managerUsername,
        string $managerPin,
        string $reason
    ): array {
        $pdo = Database::getInstance();
        self::ensureShiftColumns($pdo);

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) < 3) {
            throw new Exception("A written review reason is required to close this shift.");
        }

        // Verify manager PIN
        $mgr = self::verifyManagerPin($managerUsername, $managerPin);
        if (in_array(strtolower($mgr['manager_role']), ['cashier', 'washer', 'mechanic', 'technical'], true)) {
            throw new Exception("Only a Manager can reopen a closed day, never a cashier.");
        }

        $sStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
        $sStmt->execute([$shiftId]);
        $shift = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift || in_array($shift['status'], ['closed', 'ended'], true)) {
            throw new Exception("Shift not found or already closed.");
        }

        // Calculate expected cash based on actual transactions
        $calc = self::calculateShiftExpectedCash($shiftId);
        $expectedUsd = (float)$calc['expected_cash_usd'];
        $expectedKhr = (int)$calc['expected_cash_khr'];

        $diffUsd = round($countedUsd - $expectedUsd, 2);
        $diffKhr = $countedKhr - $expectedKhr;
        $shortageOwed = ($diffUsd < 0) ? abs($diffUsd) : 0.00;

        $revNotes = trim(($shift['notes'] ? $shift['notes'] . " | " : "") . "[Manager Review Closed by {$mgr['manager_name']}: {$reason}]");

        $upd = $pdo->prepare("
            UPDATE cashier_shifts SET
                ended_at = CURRENT_TIMESTAMP,
                expected_cash_usd = ?,
                expected_cash_khr = ?,
                counted_cash_usd = ?,
                counted_cash_khr = ?,
                difference_usd = ?,
                shortage_owed_usd = ?,
                status = 'closed',
                notes = ?,
                review_reason = ?,
                reviewed_by_manager_id = ?,
                reviewed_by_manager_name = ?,
                reviewed_at = CURRENT_TIMESTAMP,
                is_active = 0
            WHERE id = ?
        ");
        $upd->execute([
            $expectedUsd,
            $expectedKhr,
            $countedUsd,
            $countedKhr,
            $diffUsd,
            $shortageOwed,
            $revNotes,
            $reason,
            $mgr['manager_id'],
            $mgr['manager_name'],
            $shiftId
        ]);

        AuditLogger::log(
            'SHIFT_REVIEW_CLOSED',
            "Manager {$mgr['manager_name']} reviewed and closed shift #{$shift['shift_uid']}. Reason: {$reason}. Counted: \${$countedUsd}, Expected: \${$expectedUsd}, Diff: \${$diffUsd}",
            'shift',
            (string)$shiftId,
            $mgr['manager_id'],
            $mgr['manager_name'],
            'manager'
        );

        return [
            'success'           => true,
            'shift_id'          => $shiftId,
            'shift_uid'         => $shift['shift_uid'],
            'cashier_name'      => $shift['cashier_name'],
            'manager_name'      => $mgr['manager_name'],
            'counted_cash_usd'  => $countedUsd,
            'counted_cash_khr'  => $countedKhr,
            'expected_cash_usd' => $expectedUsd,
            'expected_cash_khr' => $expectedKhr,
            'difference_usd'    => $diffUsd,
            'difference_khr'    => $diffKhr,
            'shortage_owed_usd' => $shortageOwed,
            'message'           => "Shift #{$shift['shift_uid']} successfully reviewed and closed by {$mgr['manager_name']}."
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
        if (in_array(strtolower($mgr['manager_role']), ['cashier', 'washer', 'mechanic', 'technical'], true)) {
            throw new Exception("Only a Manager can reopen a closed day, never a cashier.");
        }

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

        $dayLock = self::isDayLocked();
        if (!empty($dayLock['locked'])) {
            throw new Exception("Action rejected: Business day is closed.");
        }

        $pdo = Database::getInstance();
        $activeShift = self::getActiveShift($cashierId, $pdo);
        if (!$activeShift) {
            throw new Exception("Action rejected: No active shift is open for this cashier. Please open a shift first.");
        }
        $shiftId = (int)$activeShift['id'];

        $mgr = self::verifyManagerPin($managerUsername, $managerPin, $deviceId);

        $cStmt = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
        $cStmt->execute([$cashierId]);
        $cashier = $cStmt->fetch(PDO::FETCH_ASSOC);
        $cashierName = $cashier['full_name'] ?? (string)$cashierId;

        $eventUid = generate_unique_id('DRW');
        $ins = $pdo->prepare("
            INSERT INTO drawer_events (
                event_uid, event_type, pos_device_id, shift_id, cashier_id, cashier_name,
                manager_id, manager_name, reason, is_active, created_at
            ) VALUES (?, 'no_sale_open', ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            $eventUid,
            $deviceId,
            $shiftId,
            $cashierId,
            $cashierName,
            $mgr['manager_id'],
            $mgr['manager_name'],
            $reason
        ]);

        $logMsg = "Cash drawer opened without sale for Shift #{$shiftId} by Cashier {$cashierName} authorized by Manager {$mgr['manager_name']}. Reason: {$reason}";
        AuditLogger::log('DRAWER_OPEN_NO_SALE', $logMsg, 'drawer', $eventUid, $cashierId, $cashier['username'] ?? '', 'cashier');

        return [
            'success'      => true,
            'event_uid'    => $eventUid,
            'shift_id'     => $shiftId,
            'cashier_name' => $cashierName,
            'manager_name' => $mgr['manager_name'],
            'reason'       => $reason,
            'created_at'   => date('Y-m-d H:i:s'),
            'message'      => 'Cash drawer opening authorized and logged.'
        ];
    }

    /**
     * Edit Opening Float for active shift.
     * Strictly requires Manager PIN authorization and reason.
     */
    public static function editOpeningFloat(
        float $newUsd,
        int $newKhr,
        string $reason,
        string $managerUsername,
        string $managerPin,
        int $cashierId,
        ?int $deviceId = null
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception("Reason for editing opening float is required.");
        }
        if ($newUsd < 0 || $newKhr < 0) {
            throw new Exception("Opening float amounts cannot be negative.");
        }

        $dayLock = self::isDayLocked();
        if (!empty($dayLock['locked'])) {
            throw new Exception("Action rejected: Business day is closed.");
        }

        $pdo = Database::getInstance();
        $activeShift = self::getActiveShift($cashierId, $pdo);
        if (!$activeShift) {
            throw new Exception("Action rejected: No active shift is open for this cashier. Please open a shift first.");
        }
        $shiftId = (int)$activeShift['id'];

        $mgr = self::verifyManagerPin($managerUsername, $managerPin, $deviceId);

        $cStmt = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
        $cStmt->execute([$cashierId]);
        $cashier = $cStmt->fetch(PDO::FETCH_ASSOC);
        $cashierName = $cashier['full_name'] ?? (string)$cashierId;

        $oldUsd = (float)$activeShift['opening_cash_usd'];
        $oldKhr = (int)$activeShift['opening_cash_khr'];

        $upd = $pdo->prepare("UPDATE cashier_shifts SET opening_cash_usd = ?, opening_cash_khr = ? WHERE id = ?");
        $upd->execute([$newUsd, $newKhr, $shiftId]);

        $logMsg = "Opening float edited for Shift #{$shiftId} by Cashier {$cashierName} authorized by Manager {$mgr['manager_name']}. Old: \${$oldUsd} / {$oldKhr} KHR -> New: \${$newUsd} / {$newKhr} KHR. Reason: {$reason}";
        AuditLogger::log('EDIT_OPENING_FLOAT', $logMsg, 'shift', (string)$shiftId, $cashierId, $cashier['username'] ?? '', 'cashier');

        return [
            'success'      => true,
            'shift_id'     => $shiftId,
            'old_usd'      => $oldUsd,
            'old_khr'      => $oldKhr,
            'new_usd'      => $newUsd,
            'new_khr'      => $newKhr,
            'cashier_name' => $cashierName,
            'manager_name' => $mgr['manager_name'],
            'message'      => "Opening float updated to \${$newUsd} / " . number_format($newKhr) . " KHR."
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
        if (in_array(strtolower($mgr['manager_role']), ['cashier', 'washer', 'mechanic', 'technical'], true)) {
            throw new Exception("Only a Manager can reopen a closed day, never a cashier.");
        }

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
                'message'  => "Today is closed. Sales will open again tomorrow."
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
     * Rule: All active shifts must be closed or reviewed before day can be closed.
     */
    public static function closeDayZReport(
        string $managerUsername,
        string $managerPin,
        float $countedUsd,
        int $countedKhr = 0,
        ?string $notes = null,
        ?string $closeDate = null
    ): array {
        $mgr = self::verifyCloseDayUser($managerUsername, $managerPin);
        $date = $closeDate ?? date('Y-m-d');
        $pdo = Database::getInstance();
        $rate = get_exchange_rate();

        // 1. Check for active open shifts on this date or earlier
        $openShiftsStmt = $pdo->prepare("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open' AND (DATE(started_at) = ? OR DATE(started_at) <= ?)");
        $openShiftsStmt->execute([$date, $date]);
        $openCount = (int)$openShiftsStmt->fetchColumn();
        if ($openCount > 0) {
            throw new Exception("Cannot close day: There are {$openCount} active open shift(s). All cashiers must count and end their shifts before closing the day.");
        }

        // 2. Fetch all shifts of today
        $shiftsStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE DATE(started_at) = ? ORDER BY id ASC");
        $shiftsStmt->execute([$date]);
        $allShifts = $shiftsStmt->fetchAll(PDO::FETCH_ASSOC);

        $sumOpeningUsd = 0.00;
        $sumOpeningKhr = 0;
        $sumCashReceivedUsd = 0.00;
        $sumCashReceivedKhr = 0;
        $sumChangeGivenUsd = 0.00;
        $sumChangeGivenKhr = 0;
        $sumCashRefundsUsd = 0.00;
        $sumCashRefundsKhr = 0;
        $sumCashOutUsd = 0.00;
        $sumCashOutKhr = 0;
        $sumCashInUsd = 0.00;
        $sumCashInKhr = 0;
        $sumShiftCountedUsd = 0.00;
        $sumShiftCountedKhr = 0;

        $shiftsSummary = [];
        foreach ($allShifts as $s) {
            $sumOpeningUsd += (float)$s['opening_cash_usd'];
            $sumOpeningKhr += (int)$s['opening_cash_khr'];
            $sumCashReceivedUsd += (float)($s['cash_received_usd'] ?? 0);
            $sumCashReceivedKhr += (int)($s['cash_received_khr'] ?? 0);
            $sumChangeGivenUsd += (float)($s['change_given_usd'] ?? 0);
            $sumChangeGivenKhr += (int)($s['change_given_khr'] ?? 0);
            $sumCashRefundsUsd += (float)($s['cash_refunds_usd'] ?? 0);
            $sumCashRefundsKhr += (int)($s['cash_refunds_khr'] ?? 0);
            $sumCashOutUsd += (float)($s['cash_out_usd'] ?? 0);
            $sumCashOutKhr += (int)($s['cash_out_khr'] ?? 0);
            $sumCashInUsd += (float)($s['cash_in_usd'] ?? 0);
            $sumCashInKhr += (int)($s['cash_in_khr'] ?? 0);
            $sumShiftCountedUsd += (float)$s['counted_cash_usd'];
            $sumShiftCountedKhr += (int)$s['counted_cash_khr'];

            $sDiffUsd = (float)$s['difference_usd'];
            $sDiffKhr = (int)($s['difference_khr'] ?? 0);
            $sTotDiff = (float)($s['total_difference_usd'] ?? round($sDiffUsd + ($sDiffKhr / $rate), 2));

            $shiftsSummary[] = [
                'shift_id'       => (int)$s['id'],
                'shift_uid'      => $s['shift_uid'],
                'cashier_name'   => $s['cashier_name'],
                'started_at'     => $s['started_at'],
                'ended_at'       => $s['ended_at'],
                'opening_usd'    => (float)$s['opening_cash_usd'],
                'opening_khr'    => (int)$s['opening_cash_khr'],
                'expected_usd'   => (float)$s['expected_cash_usd'],
                'expected_khr'   => (int)$s['expected_cash_khr'],
                'counted_usd'    => (float)$s['counted_cash_usd'],
                'counted_khr'    => (int)$s['counted_cash_khr'],
                'diff_usd'       => $sDiffUsd,
                'diff_khr'       => $sDiffKhr,
                'total_diff_usd' => $sTotDiff
            ];
        }

        // Expected cash for the day:
        // Expected cash = opening float + cash received - change given - cash refunds paid - cash out + cash in
        $dayExpectedUsd = round($sumOpeningUsd + $sumCashReceivedUsd - $sumChangeGivenUsd - $sumCashRefundsUsd - $sumCashOutUsd + $sumCashInUsd, 2);
        $dayExpectedKhr = (int)round($sumOpeningKhr + $sumCashReceivedKhr - $sumChangeGivenKhr - $sumCashRefundsKhr - $sumCashOutKhr + $sumCashInKhr);

        if ($countedUsd <= 0.00 && $countedKhr <= 0 && ($sumShiftCountedUsd > 0 || $sumShiftCountedKhr > 0)) {
            $countedUsd = $sumShiftCountedUsd;
            $countedKhr = $sumShiftCountedKhr;
        }

        $diffUsd = round($countedUsd - $dayExpectedUsd, 2);
        $diffKhr = $countedKhr - $dayExpectedKhr;
        $totalDiffUsd = round($diffUsd + ($diffKhr / $rate), 2);
        $shortageOwedUsd = ($totalDiffUsd < 0) ? abs($totalDiffUsd) : 0.00;

        $isDiscrepancy = ($totalDiffUsd != 0.00 || $diffUsd != 0.00 || $diffKhr != 0);
        if ($isDiscrepancy) {
            $discrepancyType = ($totalDiffUsd < 0) ? 'Short' : 'Over';
            $reviewNotice = "[Needs Manager Review] Cash {$discrepancyType}: " . ($totalDiffUsd < 0 ? '-' : '+') . "$" . number_format(abs($totalDiffUsd), 2);
            if ($diffKhr != 0) {
                $reviewNotice .= " / " . ($diffKhr < 0 ? '-' : '+') . number_format(abs($diffKhr)) . " KHR";
            }
            $notes = trim($reviewNotice . (!empty($notes) ? "\n" . $notes : ''));
        }

        // Day sales breakdown
        $totalsStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as paid_count,
                COALESCE(SUM(total_usd), 0) as total_sales_usd,
                COALESCE(SUM(total_khr), 0) as total_sales_khr,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_usd ELSE 0 END), 0) as cash_usd,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END), 0) as cash_count,
                COALESCE(SUM(CASE WHEN payment_method = 'aba' THEN total_usd ELSE 0 END), 0) as aba_usd,
                COALESCE(SUM(CASE WHEN payment_method = 'aba' THEN 1 ELSE 0 END), 0) as aba_count,
                COALESCE(SUM(CASE WHEN payment_method = 'khqr' THEN total_usd ELSE 0 END), 0) as khqr_usd,
                COALESCE(SUM(CASE WHEN payment_method = 'khqr' THEN 1 ELSE 0 END), 0) as khqr_count,
                COALESCE(SUM(discount_usd), 0) as discount_usd
            FROM orders
            WHERE DATE(created_at) = ? AND (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled'
        ");
        $totalsStmt->execute([$date]);
        $t = $totalsStmt->fetch(PDO::FETCH_ASSOC);

        // Voids / cancellations today
        $voidStmt = $pdo->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(total_usd), 0) as total_usd
            FROM orders
            WHERE DATE(created_at) = ? AND status = 'cancelled'
        ");
        $voidStmt->execute([$date]);
        $voids = $voidStmt->fetch(PDO::FETCH_ASSOC);
        $voidsCount = (int)($voids['count'] ?? 0);
        $voidsTotalUsd = (float)($voids['total_usd'] ?? 0);

        // Day refunds
        $refStmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(refund_amount_usd), 0) as total_usd FROM orders WHERE DATE(refunded_at) = ? AND refund_status = 'refunded'");
        $refStmt->execute([$date]);
        $dayRefunds = $refStmt->fetch(PDO::FETCH_ASSOC);
        $refundsCount = (int)($dayRefunds['count'] ?? 0);
        $refundsTotalUsd = (float)($dayRefunds['total_usd'] ?? 0);

        $bankSalesUsd = round((float)$t['aba_usd'] + (float)$t['khqr_usd'], 2);
        $bankSalesCount = (int)$t['aba_count'] + (int)$t['khqr_count'];

        $zReportNumber = generate_unique_id('ZREP');

        // Check if there was an existing record or reopen history
        $chkExist = $pdo->prepare("SELECT id, reopen_history_json, reopen_reason, reopened_at FROM pos_daily_closes WHERE close_date = ?");
        $chkExist->execute([$date]);
        $exist = $chkExist->fetch(PDO::FETCH_ASSOC);

        $reopenHistory = [];
        if ($exist && !empty($exist['reopen_history_json'])) {
            $reopenHistory = json_decode($exist['reopen_history_json'], true) ?: [];
        } elseif ($exist && !empty($exist['reopen_reason'])) {
            $reopenHistory[] = [
                'reason' => $exist['reopen_reason'],
                'reopened_at' => $exist['reopened_at']
            ];
        }

        $shiftsJson = json_encode($shiftsSummary);
        $reopenJson = json_encode($reopenHistory);

        if ($exist) {
            $upd = $pdo->prepare("
                UPDATE pos_daily_closes SET
                    closed_by_user_id = ?, closed_by_name = ?, manager_id = ?, manager_name = ?,
                    role = ?,
                    closed_at = CURRENT_TIMESTAMP, exchange_rate = ?, opening_cash_usd = ?, opening_cash_khr = ?,
                    total_sales_usd = ?, total_sales_khr = ?, cash_sales_usd = ?, aba_sales_usd = ?, khqr_sales_usd = ?,
                    expenses_usd = ?, discount_total_usd = ?, expected_cash_usd = ?, expected_cash_khr = ?,
                    counted_cash_usd = ?, counted_cash_khr = ?, difference_usd = ?, difference_khr = ?, total_difference_usd = ?,
                    cash_received_usd = ?, cash_received_khr = ?, change_given_usd = ?, change_given_khr = ?,
                    cash_refunds_usd = ?, cash_refunds_khr = ?, cash_out_usd = ?, cash_out_khr = ?, cash_in_usd = ?, cash_in_khr = ?,
                    cash_sales_count = ?, bank_sales_count = ?, bank_sales_usd = ?, voids_count = ?, voids_total_usd = ?,
                    shortage_owed_usd = ?, paid_orders_count = ?, z_report_number = ?, status = 'closed', notes = ?,
                    shifts_summary_json = ?, reopen_history_json = ?
                WHERE id = ?
            ");
            $upd->execute([
                $mgr['user_id'], $mgr['user_name'], $mgr['manager_id'], $mgr['manager_name'],
                $mgr['user_role'],
                $rate, $sumOpeningUsd, $sumOpeningKhr,
                (float)$t['total_sales_usd'], (int)$t['total_sales_khr'], (float)$t['cash_usd'], (float)$t['aba_usd'], (float)$t['khqr_usd'],
                $sumCashOutUsd, (float)$t['discount_usd'], $dayExpectedUsd, $dayExpectedKhr,
                $countedUsd, $countedKhr, $diffUsd, $diffKhr, $totalDiffUsd,
                $sumCashReceivedUsd, $sumCashReceivedKhr, $sumChangeGivenUsd, $sumChangeGivenKhr,
                $sumCashRefundsUsd, $sumCashRefundsKhr, $sumCashOutUsd, $sumCashOutKhr, $sumCashInUsd, $sumCashInKhr,
                (int)$t['cash_count'], $bankSalesCount, $bankSalesUsd, $voidsCount, $voidsTotalUsd,
                $shortageOwedUsd, (int)$t['paid_count'], $zReportNumber, $notes,
                $shiftsJson, $reopenJson,
                $exist['id']
            ]);
            $closeId = (int)$exist['id'];
        } else {
            $ins = $pdo->prepare("
                INSERT INTO pos_daily_closes (
                    close_date, closed_by_user_id, closed_by_name, manager_id, manager_name,
                    role, closed_at, exchange_rate, opening_cash_usd, opening_cash_khr,
                    total_sales_usd, total_sales_khr, cash_sales_usd, aba_sales_usd, khqr_sales_usd,
                    expenses_usd, discount_total_usd, expected_cash_usd, expected_cash_khr,
                    counted_cash_usd, counted_cash_khr, difference_usd, difference_khr, total_difference_usd,
                    cash_received_usd, cash_received_khr, change_given_usd, change_given_khr,
                    cash_refunds_usd, cash_refunds_khr, cash_out_usd, cash_out_khr, cash_in_usd, cash_in_khr,
                    cash_sales_count, bank_sales_count, bank_sales_usd, voids_count, voids_total_usd,
                    shortage_owed_usd, paid_orders_count, z_report_number, status, notes,
                    shifts_summary_json, reopen_history_json, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, CURRENT_TIMESTAMP, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 'closed', ?,
                    ?, ?, CURRENT_TIMESTAMP
                )
            ");
            $ins->execute([
                $date, $mgr['user_id'], $mgr['user_name'], $mgr['manager_id'], $mgr['manager_name'],
                $mgr['user_role'], $rate, $sumOpeningUsd, $sumOpeningKhr,
                (float)$t['total_sales_usd'], (int)$t['total_sales_khr'], (float)$t['cash_usd'], (float)$t['aba_usd'], (float)$t['khqr_usd'],
                $sumCashOutUsd, (float)$t['discount_usd'], $dayExpectedUsd, $dayExpectedKhr,
                $countedUsd, $countedKhr, $diffUsd, $diffKhr, $totalDiffUsd,
                $sumCashReceivedUsd, $sumCashReceivedKhr, $sumChangeGivenUsd, $sumChangeGivenKhr,
                $sumCashRefundsUsd, $sumCashRefundsKhr, $sumCashOutUsd, $sumCashOutKhr, $sumCashInUsd, $sumCashInKhr,
                (int)$t['cash_count'], $bankSalesCount, $bankSalesUsd, $voidsCount, $voidsTotalUsd,
                $shortageOwedUsd, (int)$t['paid_count'], $zReportNumber, $notes,
                $shiftsJson, $reopenJson
            ]);
            $closeId = (int)$pdo->lastInsertId();
        }

        $closerRoleLabel = ucfirst($mgr['user_role']);
        $logMsg = "Day {$date} closed by {$closerRoleLabel} {$mgr['user_name']}. Final Z-Report generated: {$zReportNumber}. Total Sales: $" . number_format((float)$t['total_sales_usd'], 2) . ", Expected Cash: $" . number_format($dayExpectedUsd, 2) . ", Counted: $" . number_format($countedUsd, 2) . ", Diff: $" . number_format($diffUsd, 2);
        AuditLogger::log('DAY_CLOSED', $logMsg, 'z_report', $zReportNumber, $mgr['user_id'], $mgr['user_name'], $mgr['user_role']);

        if ($isDiscrepancy) {
            $diffType = ($totalDiffUsd < 0) ? 'shortage' : 'overage';
            AuditLogger::log('DAY_DISCREPANCY', "Day {$date} final close recorded cash {$diffType} of $" . number_format(abs($totalDiffUsd), 2) . " by {$closerRoleLabel} {$mgr['user_name']}. Needs Manager Review.", 'z_report', $zReportNumber, $mgr['user_id'], $mgr['user_name'], $mgr['user_role']);
        }

        // Send non-blocking Telegram alert to owner
        TelegramNotifier::notifyDayClosed([
            'close_date'           => $date,
            'closed_by_name'       => $mgr['user_name'],
            'role'                 => $mgr['user_role'],
            'total_sales_usd'      => (float)$t['total_sales_usd'],
            'total_sales_khr'      => (int)$t['total_sales_khr'],
            'difference_usd'       => $diffUsd,
            'difference_khr'       => $diffKhr,
            'total_difference_usd' => $totalDiffUsd,
            'paid_orders_count'    => (int)$t['paid_count'],
            'z_report_number'      => $zReportNumber
        ]);

        $slip = self::getCloseDaySlipData((string)$closeId);

        return [
            'success'              => true,
            'close_id'             => $closeId,
            'close_date'           => $date,
            'z_report_number'      => $zReportNumber,
            'closed_by_name'       => $mgr['user_name'],
            'closed_by_user_id'    => $mgr['user_id'],
            'role'                 => $mgr['user_role'],
            'closed_by_role'       => $closerRoleLabel,
            'manager_name'         => $mgr['user_name'],
            'needs_manager_review' => $isDiscrepancy,
            'review_status'        => $isDiscrepancy ? 'Needs Manager Review' : 'Balanced',
            'total_sales_usd'      => (float)$t['total_sales_usd'],
            'cash_sales_usd'       => (float)$t['cash_usd'],
            'aba_sales_usd'        => (float)$t['aba_usd'],
            'khqr_sales_usd'       => (float)$t['khqr_usd'],
            'expenses_usd'         => $sumCashOutUsd,
            'expected_cash_usd'    => $dayExpectedUsd,
            'expected_cash_khr'    => $dayExpectedKhr,
            'counted_cash_usd'     => $countedUsd,
            'counted_cash_khr'     => $countedKhr,
            'difference_usd'       => $diffUsd,
            'difference_khr'       => $diffKhr,
            'total_difference_usd' => $totalDiffUsd,
            'diff_usd_status'      => ($diffUsd < 0 ? 'short' : ($diffUsd > 0 ? 'over' : 'exact')),
            'diff_khr_status'      => ($diffKhr < 0 ? 'short' : ($diffKhr > 0 ? 'over' : 'exact')),
            'total_diff_status'    => ($totalDiffUsd < 0 ? 'short' : ($totalDiffUsd > 0 ? 'over' : 'exact')),
            'paid_orders_count'    => (int)$t['paid_count'],
            'voids_count'          => $voidsCount,
            'refunds_count'        => $refundsCount,
            'slip'                 => $slip,
            'message'              => "Day closed and Z-Report {$zReportNumber} created successfully."
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
        if (in_array(strtolower($mgr['manager_role']), ['cashier', 'washer', 'mechanic', 'technical'], true)) {
            throw new Exception("Only a Manager can reopen a closed day, never a cashier.");
        }
        $pdo = Database::getInstance();

        $chk = $pdo->prepare("SELECT id, status, z_report_number, reopen_history_json FROM pos_daily_closes WHERE close_date = ?");
        $chk->execute([$closeDate]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['status'] !== 'closed') {
            throw new Exception("Day {$closeDate} is not currently closed.");
        }

        $history = [];
        if (!empty($row['reopen_history_json'])) {
            $history = json_decode($row['reopen_history_json'], true) ?: [];
        }
        $history[] = [
            'manager_id'   => $mgr['manager_id'],
            'manager_name' => $mgr['manager_name'],
            'reason'       => $reason,
            'reopened_at'  => date('Y-m-d H:i:s')
        ];

        $upd = $pdo->prepare("
            UPDATE pos_daily_closes SET
                status = 'open',
                reopened_by_user_id = ?,
                reopened_at = CURRENT_TIMESTAMP,
                reopen_reason = ?,
                reopen_history_json = ?
            WHERE close_date = ?
        ");
        $upd->execute([$mgr['manager_id'], $reason, json_encode($history), $closeDate]);

        $logMsg = "Day {$closeDate} (Z-Report: {$row['z_report_number']}) REOPENED by Manager/Owner {$mgr['manager_name']}. Reason: {$reason}";
        AuditLogger::log('DAY_REOPENED', $logMsg, 'z_report', (string)$row['z_report_number'], $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        TelegramNotifier::notifyDayReopened(
            $closeDate,
            (string)($row['z_report_number'] ?? ''),
            (string)$mgr['manager_name'],
            (string)$mgr['manager_role'],
            $reason
        );

        return [
            'success'      => true,
            'close_date'   => $closeDate,
            'manager_name' => $mgr['manager_name'],
            'message'      => "Day {$closeDate} has been reopened successfully."
        ];
    }

    /**
     * Slip Data Generator: Open Float Slip
     */
    public static function getOpenShiftSlipData(int $shiftId): array {
        $pdo = Database::getInstance();
        $sStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
        $sStmt->execute([$shiftId]);
        $shift = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$shift) {
            throw new Exception("Shift #{$shiftId} not found.");
        }

        $uRoleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $uRoleStmt->execute([(int)$shift['cashier_id']]);
        $uRole = $uRoleStmt->fetchColumn() ?: 'cashier';
        $uRoleLabel = ucfirst($uRole);

        $settings = self::getShopSettings($pdo);
        $rate = (int)($shift['exchange_rate'] ?: get_exchange_rate());
        $opUsd = (float)$shift['opening_cash_usd'];
        $opKhr = (int)$shift['opening_cash_khr'];
        $totalOpUsd = round($opUsd + ($opKhr / $rate), 2);

        return [
            'type'              => 'open_shift',
            'shift_id'          => (int)$shift['id'],
            'shift_uid'         => $shift['shift_uid'],
            'cashier_id'        => (int)$shift['cashier_id'],
            'cashier_name'      => $shift['cashier_name'],
            'cashier_role'      => $uRoleLabel,
            'opened_by_name'    => $shift['cashier_name'],
            'opened_by_role'    => $uRoleLabel,
            'device_name'       => $shift['pos_device_name'] ?: 'POS Terminal',
            'started_at'        => $shift['started_at'],
            'exchange_rate'     => $rate,
            'opening_cash_usd'  => $opUsd,
            'opening_cash_khr'  => $opKhr,
            'total_opening_usd' => $totalOpUsd,
            'notes'             => $shift['notes'] ?: '',
            'shop'              => $settings,
            'printed_at'        => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Slip Data Generator: End Shift Slip (Handover or Solo)
     */
    public static function getEndShiftSlipData(int $shiftId): array {
        $pdo = Database::getInstance();
        $sStmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
        $sStmt->execute([$shiftId]);
        $shift = $sStmt->fetch(PDO::FETCH_ASSOC);
        if (!$shift) {
            throw new Exception("Shift #{$shiftId} not found.");
        }

        $outRoleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $outRoleStmt->execute([(int)$shift['cashier_id']]);
        $outRole = $outRoleStmt->fetchColumn() ?: 'cashier';

        $inRole = null;
        if (!empty($shift['handover_to_cashier_id'])) {
            $inRoleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $inRoleStmt->execute([(int)$shift['handover_to_cashier_id']]);
            $inRole = $inRoleStmt->fetchColumn() ?: 'cashier';
        }

        // Fetch unpaid or in-progress orders to carry over to the next shift
        $shiftDate = date('Y-m-d', strtotime($shift['started_at']));
        $pendingOrdersStmt = $pdo->prepare("
            SELECT id, order_number, customer_name, plate_number as vehicle_plate, total_usd, status, payment_status, created_at
            FROM orders
            WHERE DATE(created_at) = ? AND status != 'cancelled' AND (status != 'paid' AND payment_status != 'paid')
            ORDER BY id ASC
        ");
        $pendingOrdersStmt->execute([$shiftDate]);
        $unpaidOrders = $pendingOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = self::getShopSettings($pdo);
        $rate = (int)($shift['exchange_rate'] ?: get_exchange_rate());

        $opUsd = (float)$shift['opening_cash_usd'];
        $opKhr = (int)$shift['opening_cash_khr'];
        $expUsd = (float)$shift['expected_cash_usd'];
        $expKhr = (int)$shift['expected_cash_khr'];
        $cntUsd = (float)$shift['counted_cash_usd'];
        $cntKhr = (int)$shift['counted_cash_khr'];
        $diffUsd = (float)$shift['difference_usd'];
        $diffKhr = (int)$shift['difference_khr'];
        $totDiffUsd = (float)($shift['total_difference_usd'] ?? round($diffUsd + ($diffKhr / $rate), 2));

        return [
            'type'                 => 'end_shift',
            'is_solo'              => empty($shift['handover_to_cashier_name']),
            'shift_id'             => (int)$shift['id'],
            'shift_uid'            => $shift['shift_uid'],
            'cashier_id'           => (int)$shift['cashier_id'],
            'cashier_name'         => $shift['cashier_name'],
            'cashier_role'         => ucfirst($outRole),
            'handover_to_name'     => $shift['handover_to_cashier_name'] ?: null,
            'handover_to_role'     => $inRole ? ucfirst($inRole) : null,
            'device_name'          => $shift['pos_device_name'] ?: 'POS Terminal',
            'started_at'           => $shift['started_at'],
            'ended_at'             => $shift['ended_at'] ?: date('Y-m-d H:i:s'),
            'exchange_rate'        => $rate,
            'opening_cash_usd'     => $opUsd,
            'opening_cash_khr'     => $opKhr,
            'cash_received_usd'    => (float)($shift['cash_received_usd'] ?? 0),
            'cash_received_khr'    => (int)($shift['cash_received_khr'] ?? 0),
            'change_given_usd'     => (float)($shift['change_given_usd'] ?? 0),
            'change_given_khr'     => (int)($shift['change_given_khr'] ?? 0),
            'cash_refunds_usd'     => (float)($shift['cash_refunds_usd'] ?? 0),
            'cash_refunds_khr'     => (int)($shift['cash_refunds_khr'] ?? 0),
            'cash_out_usd'         => (float)($shift['cash_out_usd'] ?? 0),
            'cash_out_khr'         => (int)($shift['cash_out_khr'] ?? 0),
            'cash_in_usd'          => (float)($shift['cash_in_usd'] ?? 0),
            'cash_in_khr'          => (int)($shift['cash_in_khr'] ?? 0),
            'expected_cash_usd'    => $expUsd,
            'expected_cash_khr'    => $expKhr,
            'counted_cash_usd'     => $cntUsd,
            'counted_cash_khr'     => $cntKhr,
            'difference_usd'       => $diffUsd,
            'difference_khr'       => $diffKhr,
            'total_difference_usd' => $totDiffUsd,
            'diff_usd_status'      => ($diffUsd < 0 ? 'short' : ($diffUsd > 0 ? 'over' : 'exact')),
            'diff_khr_status'      => ($diffKhr < 0 ? 'short' : ($diffKhr > 0 ? 'over' : 'exact')),
            'total_diff_status'    => ($totDiffUsd < 0 ? 'short' : ($totDiffUsd > 0 ? 'over' : 'exact')),
            'shortage_owed_usd'    => (float)($shift['shortage_owed_usd'] ?? ($totDiffUsd < 0 ? abs($totDiffUsd) : 0)),
            'cash_sales_count'     => (int)($shift['cash_sales_count'] ?? 0),
            'cash_sales_usd'       => (float)($shift['cash_sales_usd'] ?? 0),
            'cash_sales_khr'       => (int)($shift['cash_sales_khr'] ?? 0),
            'bank_sales_count'     => (int)($shift['bank_sales_count'] ?? 0),
            'bank_sales_usd'       => (float)($shift['bank_sales_usd'] ?? 0),
            'total_orders_count'   => (int)($shift['total_orders_count'] ?? 0),
            'total_sales_usd'      => (float)($shift['total_sales_usd'] ?? 0),
            'unpaid_orders'        => $unpaidOrders,
            'notes'                => $shift['notes'] ?: '',
            'shop'                 => $settings,
            'printed_at'           => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Slip Data Generator: Close Day Slip (Z-Report)
     */
    public static function getCloseDaySlipData(string $closeIdOrDateOrZrep): array {
        $pdo = Database::getInstance();
        if (is_numeric($closeIdOrDateOrZrep)) {
            $stmt = $pdo->prepare("SELECT * FROM pos_daily_closes WHERE id = ?");
            $stmt->execute([(int)$closeIdOrDateOrZrep]);
        } elseif (strpos($closeIdOrDateOrZrep, 'ZREP') === 0) {
            $stmt = $pdo->prepare("SELECT * FROM pos_daily_closes WHERE z_report_number = ?");
            $stmt->execute([$closeIdOrDateOrZrep]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM pos_daily_closes WHERE close_date = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$closeIdOrDateOrZrep]);
        }
        $close = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$close) {
            throw new Exception("Daily close record '{$closeIdOrDateOrZrep}' not found.");
        }

        $settings = self::getShopSettings($pdo);
        $rate = (int)($close['exchange_rate'] ?: get_exchange_rate());

        $shiftsSummary = [];
        if (!empty($close['shifts_summary_json'])) {
            $shiftsSummary = json_decode($close['shifts_summary_json'], true) ?: [];
        }

        $reopenHistory = [];
        if (!empty($close['reopen_history_json'])) {
            $reopenHistory = json_decode($close['reopen_history_json'], true) ?: [];
        }

        $diffUsd = (float)$close['difference_usd'];
        $diffKhr = (int)($close['difference_khr'] ?? 0);
        $totDiffUsd = (float)($close['total_difference_usd'] ?? round($diffUsd + ($diffKhr / $rate), 2));

        // Find who opened the first shift of the day
        $firstShiftStmt = $pdo->prepare("
            SELECT s.cashier_id, s.cashier_name, s.started_at, u.role
            FROM cashier_shifts s
            LEFT JOIN users u ON s.cashier_id = u.id
            WHERE DATE(s.started_at) = ?
            ORDER BY s.id ASC
            LIMIT 1
        ");
        $firstShiftStmt->execute([$close['close_date']]);
        $firstShift = $firstShiftStmt->fetch(PDO::FETCH_ASSOC);

        $openedByName = $firstShift['cashier_name'] ?? $close['closed_by_name'] ?? 'N/A';
        $openedByRole = !empty($firstShift['role']) ? ucfirst($firstShift['role']) : (!empty($close['role']) ? ucfirst($close['role']) : 'Cashier');
        $openedAt = $firstShift['started_at'] ?? $close['opening_time'] ?? null;

        $closedByName = $close['closed_by_name'] ?: ($close['manager_name'] ?: 'N/A');
        $closedByRole = !empty($close['role']) ? ucfirst($close['role']) : 'Cashier';

        $isDiscrepancy = ($totDiffUsd != 0.00 || $diffUsd != 0.00 || $diffKhr != 0 || (isset($close['notes']) && strpos($close['notes'], 'Needs Manager Review') !== false));

        return [
            'type'                 => 'close_day',
            'close_id'             => (int)$close['id'],
            'close_date'           => $close['close_date'],
            'z_report_number'      => $close['z_report_number'],
            'opened_by_name'       => $openedByName,
            'opened_by_role'       => $openedByRole,
            'opened_at'            => $openedAt,
            'closed_by_name'       => $closedByName,
            'closed_by_role'       => $closedByRole,
            'manager_name'         => $closedByName,
            'needs_manager_review' => $isDiscrepancy,
            'review_status'        => $isDiscrepancy ? 'Needs Manager Review' : 'Balanced',
            'closed_at'            => $close['closed_at'],
            'exchange_rate'        => $rate,
            'total_sales_usd'      => (float)$close['total_sales_usd'],
            'total_sales_khr'      => (int)$close['total_sales_khr'],
            'cash_sales_count'     => (int)($close['cash_sales_count'] ?? 0),
            'cash_sales_usd'       => (float)$close['cash_sales_usd'],
            'aba_sales_usd'        => (float)$close['aba_sales_usd'],
            'khqr_sales_usd'       => (float)$close['khqr_sales_usd'],
            'bank_sales_count'     => (int)($close['bank_sales_count'] ?? 0),
            'bank_sales_usd'       => (float)($close['bank_sales_usd'] ?? round((float)$close['aba_sales_usd'] + (float)$close['khqr_usd'], 2)),
            'discount_total_usd'   => (float)$close['discount_total_usd'],
            'paid_orders_count'    => (int)$close['paid_orders_count'],
            'voids_count'          => (int)($close['voids_count'] ?? 0),
            'voids_total_usd'      => (float)($close['voids_total_usd'] ?? 0),
            'refunds_count'        => 0,
            'cash_refunds_usd'     => (float)($close['cash_refunds_usd'] ?? 0),
            'cash_refunds_khr'     => (int)($close['cash_refunds_khr'] ?? 0),
            'opening_cash_usd'     => (float)($close['opening_cash_usd'] ?? 0),
            'opening_cash_khr'     => (int)($close['opening_cash_khr'] ?? 0),
            'cash_received_usd'    => (float)($close['cash_received_usd'] ?? 0),
            'cash_received_khr'    => (int)($close['cash_received_khr'] ?? 0),
            'change_given_usd'     => (float)($close['change_given_usd'] ?? 0),
            'change_given_khr'     => (int)($close['change_given_khr'] ?? 0),
            'cash_out_usd'         => (float)($close['cash_out_usd'] ?? $close['expenses_usd'] ?? 0),
            'cash_out_khr'         => (int)($close['cash_out_khr'] ?? 0),
            'cash_in_usd'          => (float)($close['cash_in_usd'] ?? 0),
            'cash_in_khr'          => (int)($close['cash_in_khr'] ?? 0),
            'expected_cash_usd'    => (float)$close['expected_cash_usd'],
            'expected_cash_khr'    => (int)($close['expected_cash_khr'] ?? 0),
            'counted_cash_usd'     => (float)$close['counted_cash_usd'],
            'counted_cash_khr'     => (int)$close['counted_cash_khr'],
            'difference_usd'       => $diffUsd,
            'difference_khr'       => $diffKhr,
            'total_difference_usd' => $totDiffUsd,
            'diff_usd_status'      => ($diffUsd < 0 ? 'short' : ($diffUsd > 0 ? 'over' : 'exact')),
            'diff_khr_status'      => ($diffKhr < 0 ? 'short' : ($diffKhr > 0 ? 'over' : 'exact')),
            'total_diff_status'    => ($totDiffUsd < 0 ? 'short' : ($totDiffUsd > 0 ? 'over' : 'exact')),
            'shortage_owed_usd'    => (float)($close['shortage_owed_usd'] ?? ($totDiffUsd < 0 ? abs($totDiffUsd) : 0)),
            'shifts'               => $shiftsSummary,
            'reopen_history'       => $reopenHistory,
            'notes'                => $close['notes'] ?: '',
            'shop'                 => $settings,
            'printed_at'           => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Helper to load shop settings for receipt and slip headers
     */
    private static function getShopSettings(PDO $pdo): array {
        $settings = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        while ($r = $stmt->fetch()) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
        return [
            'shop_name_en' => $settings['shop_name_en'] ?? 'My Car Wash',
            'shop_name_km' => $settings['shop_name_km'] ?? 'សេវាកម្មលាងរថយន្ត និងម៉ូតូ',
            'shop_phone'   => $settings['shop_phone'] ?? '012 334 556',
            'shop_address' => $settings['shop_address'] ?? 'Phnom Penh, Cambodia',
            'shop_logo'    => $settings['shop_logo'] ?? '',
        ];
    }
}
