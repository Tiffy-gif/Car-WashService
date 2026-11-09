<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/BayManager.php';

class NotificationManager {

    private static bool $tablesEnsured = false;

    public static function ensureTables(PDO $pdo): void {
        if (self::$tablesEnsured) {
            return;
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $t) {
            $driver = Database::getDriver();
        }
        $isSqlite = ($driver === 'sqlite');
        $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $charsetEngine = $isSqlite ? '' : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $pdo->exec("CREATE TABLE IF NOT EXISTS system_notifications (
            id {$autoInc},
            notification_uid VARCHAR(50) NOT NULL UNIQUE,
            target_role VARCHAR(50) DEFAULT NULL,
            target_user_id INT DEFAULT NULL,
            category VARCHAR(50) NOT NULL,
            title_en VARCHAR(150) NOT NULL,
            title_km VARCHAR(150) DEFAULT NULL,
            message_en TEXT NOT NULL,
            message_km TEXT DEFAULT NULL,
            action_url VARCHAR(255) DEFAULT NULL,
            sound_type VARCHAR(20) NOT NULL DEFAULT 'chime',
            reference_id VARCHAR(50) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME DEFAULT NULL,
            read_by_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charsetEngine}");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notification_settings (
            user_id INT PRIMARY KEY,
            sound_muted TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charsetEngine}");

        self::$tablesEnsured = true;
    }

    /**
     * Dispatch a notification to a specific role, user, or 'all'.
     */
    public static function notify(PDO $pdo, array $data): string {
        self::ensureTables($pdo);

        $targetRole = $data['target_role'] ?? null;
        $targetUserId = !empty($data['target_user_id']) ? (int)$data['target_user_id'] : null;
        $category = $data['category'] ?? 'system';
        $refId = $data['reference_id'] ?? null;

        // Dedup: If unread notification for same category & reference already exists, skip
        if (!empty($refId)) {
            $chk = $pdo->prepare("
                SELECT COUNT(*) FROM system_notifications 
                WHERE category = ? AND reference_id = ? AND is_read = 0
            ");
            $chk->execute([$category, (string)$refId]);
            if ((int)$chk->fetchColumn() > 0) {
                return '';
            }
        }

        $uid = generate_unique_id('NOTIF');
        $titleEn = trim($data['title_en'] ?? 'Notification');
        $titleKm = trim($data['title_km'] ?? $titleEn);
        $msgEn = trim($data['message_en'] ?? '');
        $msgKm = trim($data['message_km'] ?? $msgEn);
        $actionUrl = $data['action_url'] ?? null;
        $soundType = in_array($data['sound_type'] ?? '', ['chime', 'alert', 'none'], true) ? $data['sound_type'] : 'chime';

        $stmt = $pdo->prepare("
            INSERT INTO system_notifications (
                notification_uid, target_role, target_user_id, category, title_en, title_km,
                message_en, message_km, action_url, sound_type, reference_id, is_read, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $uid, $targetRole, $targetUserId, $category, $titleEn, $titleKm,
            $msgEn, $msgKm, $actionUrl, $soundType, $refId
        ]);

        return $uid;
    }

    /**
     * Get unread notifications count for a specific user and role.
     */
    public static function getUnreadCount(PDO $pdo, int $userId, string $userRole): int {
        self::ensureTables($pdo);

        $roleAliases = self::getRoleAliases($userRole);
        $inRoles = implode("','", array_map('addslashes', $roleAliases));

        $sql = "
            SELECT COUNT(*) 
            FROM system_notifications 
            WHERE is_read = 0 
              AND (
                  target_user_id = ? 
                  OR target_role IN ('{$inRoles}', 'all')
                  OR (? = 'owner')
              )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userRole]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get recent notifications list.
     */
    public static function getNotifications(PDO $pdo, int $userId, string $userRole, int $limit = 20): array {
        self::ensureTables($pdo);

        $roleAliases = self::getRoleAliases($userRole);
        $inRoles = implode("','", array_map('addslashes', $roleAliases));

        $sql = "
            SELECT * 
            FROM system_notifications 
            WHERE target_user_id = ? 
               OR target_role IN ('{$inRoles}', 'all')
               OR (? = 'owner')
            ORDER BY is_read ASC, id DESC 
            LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userRole]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($rows as &$r) {
            $createdTs = strtotime($r['created_at']);
            $diffMins = max(0, (int)round(($now - $createdTs) / 60));
            if ($diffMins === 0) {
                $r['time_ago'] = 'Just now';
            } elseif ($diffMins < 60) {
                $r['time_ago'] = "{$diffMins}m ago";
            } else {
                $hours = (int)floor($diffMins / 60);
                $r['time_ago'] = "{$hours}h ago";
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Mark a single notification as read.
     */
    public static function markAsRead(PDO $pdo, int $notificationId, int $userId): bool {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare("
            UPDATE system_notifications 
            SET is_read = 1, read_at = CURRENT_TIMESTAMP, read_by_id = ? 
            WHERE id = ? AND is_read = 0
        ");
        $stmt->execute([$userId, $notificationId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark all notifications as read for this user/role.
     */
    public static function markAllAsRead(PDO $pdo, int $userId, string $userRole): bool {
        self::ensureTables($pdo);

        $roleAliases = self::getRoleAliases($userRole);
        $inRoles = implode("','", array_map('addslashes', $roleAliases));

        $sql = "
            UPDATE system_notifications 
            SET is_read = 1, read_at = CURRENT_TIMESTAMP, read_by_id = ? 
            WHERE is_read = 0 
              AND (
                  target_user_id = ? 
                  OR target_role IN ('{$inRoles}', 'all')
                  OR (? = 'owner')
              )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId, $userRole]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if user has muted sound.
     */
    public static function isSoundMuted(PDO $pdo, int $userId): bool {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare("SELECT sound_muted FROM user_notification_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return ($val !== false && (int)$val === 1);
    }

    /**
     * Toggle or set sound mute state.
     */
    public static function toggleSoundMute(PDO $pdo, int $userId, ?bool $mute = null): bool {
        self::ensureTables($pdo);

        if ($mute === null) {
            $isMuted = self::isSoundMuted($pdo, $userId);
            $newVal = $isMuted ? 0 : 1;
        } else {
            $newVal = $mute ? 1 : 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_notification_settings (user_id, sound_muted, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE sound_muted = VALUES(sound_muted), updated_at = CURRENT_TIMESTAMP
        ");
        try {
            $stmt->execute([$userId, $newVal]);
        } catch (Throwable $e) {
            // SQLite fallback
            $pdo->prepare("INSERT OR REPLACE INTO user_notification_settings (user_id, sound_muted, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
                ->execute([$userId, $newVal]);
        }

        return ($newVal === 1);
    }

    /**
     * Helper to resolve role aliases (e.g. technical <-> mechanic, stock_control <-> warehouse).
     */
    public static function getRoleAliases(string $role): array {
        return match($role) {
            'technical', 'mechanic'           => ['technical', 'mechanic'],
            'stock_control', 'warehouse'      => ['stock_control', 'warehouse'],
            'wash_supervisor', 'supervisor'   => ['wash_supervisor', 'supervisor'],
            'crm', 'crm_coordinator'         => ['crm', 'crm_coordinator'],
            'system_admin', 'admin'           => ['system_admin', 'admin'],
            default                           => [$role]
        };
    }

    /**
     * Real-time automated event scanner.
     * Generates live notifications for real system state.
     */
    public static function syncSystemEvents(PDO $pdo): void {
        self::ensureTables($pdo);

        // 1. Bank transfer pending approval (for Cashier & Manager)
        $pendingTransfers = $pdo->query("
            SELECT id, order_number, plate_number, total_usd 
            FROM orders 
            WHERE payment_method IN ('aba', 'khqr', 'bank_transfer') 
              AND payment_status = 'pending_verification' 
              AND is_active = 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendingTransfers as $pt) {
            self::notify($pdo, [
                'target_role' => 'cashier',
                'category' => 'bank_transfer',
                'reference_id' => 'BANK-' . $pt['id'],
                'title_en' => 'Bank Transfer Pending Approval',
                'title_km' => 'ការផ្ទេរប្រាក់រង់ចាំការអនុម័ត',
                'message_en' => "Order #{$pt['order_number']} (" . formatUSD((float)$pt['total_usd']) . ") requires bank slip verification.",
                'message_km' => "ការបញ្ជាទិញ #{$pt['order_number']} (" . formatUSD((float)$pt['total_usd']) . ") ត្រូវការផ្ទៀងផ្ទាត់បង្កាន់ដៃ។",
                'action_url' => 'index.php?view=orders',
                'sound_type' => 'chime'
            ]);
            // Also notify manager
            self::notify($pdo, [
                'target_role' => 'manager',
                'category' => 'bank_transfer',
                'reference_id' => 'BANK-MGR-' . $pt['id'],
                'title_en' => 'Bank Transfer Pending Approval',
                'title_km' => 'ការផ្ទេរប្រាក់រង់ចាំការអនុម័ត',
                'message_en' => "Order #{$pt['order_number']} (" . formatUSD((float)$pt['total_usd']) . ") requires manager review.",
                'message_km' => "ការបញ្ជាទិញ #{$pt['order_number']} (" . formatUSD((float)$pt['total_usd']) . ") ត្រូវការការត្រួតពិនិត្យរបស់អ្នកគ្រប់គ្រង។",
                'action_url' => 'index.php?view=orders',
                'sound_type' => 'chime'
            ]);
        }

        // 2. New car in queue (for Washer and Technical)
        $recentQueued = $pdo->query("
            SELECT id, order_number, plate_number, is_mixed, created_at,
                   (SELECT GROUP_CONCAT(service_name SEPARATOR ', ') FROM order_items WHERE order_id = orders.id) as services
            FROM orders 
            WHERE status = 'washing' AND bay_id IS NULL AND is_active = 1
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recentQueued as $rq) {
            $serv = $rq['services'] ?: 'Wash Service';
            self::notify($pdo, [
                'target_role' => 'washer',
                'category' => 'job_queue',
                'reference_id' => 'QUEUE-WSH-' . $rq['id'],
                'title_en' => 'New Car in Queue',
                'title_km' => 'រថយន្តថ្មីក្នុងជួររង់ចាំ',
                'message_en' => "New vehicle in queue: {$rq['plate_number']} ({$serv})",
                'message_km' => "រថយន្តថ្មីក្នុងជួរ: {$rq['plate_number']} ({$serv})",
                'action_url' => 'index.php?view=washer_jobs',
                'sound_type' => 'chime'
            ]);

            // If mixed order or technical, notify technical as well
            if ((int)$rq['is_mixed'] === 1 || str_contains(strtolower($serv), 'oil')) {
                self::notify($pdo, [
                    'target_role' => 'technical',
                    'category' => 'job_queue',
                    'reference_id' => 'QUEUE-TECH-' . $rq['id'],
                    'title_en' => 'New Car in Queue (Technical)',
                    'title_km' => 'រថយន្តថ្មីក្នុងជួរ (បច្ចេកទេស)',
                    'message_en' => "New technical/oil vehicle in queue: {$rq['plate_number']}",
                    'message_km' => "រថយន្តបច្ចេកទេស/ប្តូរប្រេងថ្មីក្នុងជួរ: {$rq['plate_number']}",
                    'action_url' => 'index.php?view=mechanic_jobs',
                    'sound_type' => 'chime'
                ]);
            }
        }

        // 3. Parts request from Tech (for Warehouse / Stock Control)
        $pendingParts = $pdo->query("
            SELECT pr.id, pr.request_uid, pr.product_name, pr.qty_requested, o.plate_number, pr.requested_by_name
            FROM parts_requests pr
            JOIN orders o ON pr.order_id = o.id
            WHERE pr.status = 'pending' AND pr.is_active = 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendingParts as $pp) {
            self::notify($pdo, [
                'target_role' => 'stock_control',
                'category' => 'parts_request',
                'reference_id' => 'PARTS-' . $pp['id'],
                'title_en' => 'Part Request from Tech',
                'title_km' => 'សំណើគ្រឿងបន្លាស់ពីផ្នែកជាង',
                'message_en' => "Part request: {$pp['product_name']} x{$pp['qty_requested']} for Car {$pp['plate_number']} (from {$pp['requested_by_name']})",
                'message_km' => "សំណើគ្រឿងបន្លាស់: {$pp['product_name']} x{$pp['qty_requested']} សម្រាប់រថយន្ត {$pp['plate_number']}",
                'action_url' => 'index.php?view=oil_care&tab=requests',
                'sound_type' => 'alert' // Stronger alert sound
            ]);
        }

        // 4. Supervisor Alerts: Queue wait > 45 minutes & Bay idle > 5 minutes
        $queues = BayManager::getQueues($pdo);
        $allWaiting = $queues['all_queue'];
        foreach ($allWaiting as $aw) {
            if ($aw['wait_minutes'] >= 45) {
                self::notify($pdo, [
                    'target_role' => 'wash_supervisor',
                    'category' => 'bay_alert',
                    'reference_id' => 'WAIT-45-' . $aw['order_id'],
                    'title_en' => 'Wait Time Over 45 Minutes',
                    'title_km' => 'ពេលវេលារង់ចាំលើសពី 45 នាទី',
                    'message_en' => "Vehicle {$aw['plate_number']} waiting {$aw['wait_minutes']} mins (Priority {$aw['priority_code']})",
                    'message_km' => "រថយន្ត {$aw['plate_number']} រង់ចាំបាន {$aw['wait_minutes']} នាទី",
                    'action_url' => 'index.php?view=bays_queue',
                    'sound_type' => 'alert'
                ]);
            }
        }

        $health = BayManager::getQueueHealth($pdo);
        if (!empty($health['idle_alert']) && !empty($health['idle_bays'])) {
            foreach ($health['idle_bays'] as $ib) {
                self::notify($pdo, [
                    'target_role' => 'wash_supervisor',
                    'category' => 'bay_alert',
                    'reference_id' => 'IDLE-' . $ib['bay_id'] . '-' . date('YmdH'),
                    'title_en' => 'Bay Idle More Than 5 Minutes',
                    'title_km' => 'កន្លែងលាងទំនេរលើសពី 5 នាទី',
                    'message_en' => "{$ib['bay_name']} idle for {$ib['idle_minutes']} mins while cars wait in line",
                    'message_km' => "{$ib['bay_name']} ទំនេរ {$ib['idle_minutes']} នាទី ខណៈមានរថយន្តកំពុងរង់ចាំ",
                    'action_url' => 'index.php?view=bays_queue',
                    'sound_type' => 'alert'
                ]);
            }
        }

        // 5. Cash Variance Alert (for Owner & Manager)
        $shortageShifts = $pdo->query("
            SELECT id, shift_uid, cashier_name, shortage_owed_usd, ended_at
            FROM cashier_shifts
            WHERE status = 'ended' AND shortage_owed_usd > 0
              AND ended_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($shortageShifts as $ss) {
            $shiftLabel = !empty($ss['shift_uid']) ? $ss['shift_uid'] : ('#' . $ss['id']);
            self::notify($pdo, [
                'target_role' => 'owner',
                'category' => 'cash_variance',
                'reference_id' => 'SHORTAGE-' . $ss['id'],
                'title_en' => 'Cash Variance Alert',
                'title_km' => 'ការជូនដំណឹងអំពីគម្លាតសាច់ប្រាក់',
                'message_en' => "Cash variance alert: " . formatUSD((float)$ss['shortage_owed_usd']) . " shortage on shift {$shiftLabel} ({$ss['cashier_name']})",
                'message_km' => "គម្លាតសាច់ប្រាក់: " . formatUSD((float)$ss['shortage_owed_usd']) . " ខ្វះលើវេន {$shiftLabel} ({$ss['cashier_name']})",
                'action_url' => 'index.php?view=shift',
                'sound_type' => 'alert'
            ]);
        }
    }
}
