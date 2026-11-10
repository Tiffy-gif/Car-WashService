<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/TechnicalManager.php';
require_once __DIR__ . '/NotificationManager.php';
require_once __DIR__ . '/OrderGate.php';

class BayManager {

    private static bool $tablesEnsured = false;

    /**
     * Ensure bays, order_tasks, and bay_history tables exist, columns exist, and default bays are seeded.
     */
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

        // 1. Bays Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS bays (
            id {$autoInc},
            bay_number INT NOT NULL UNIQUE,
            bay_name VARCHAR(100) NOT NULL,
            bay_type VARCHAR(50) NOT NULL DEFAULT 'wash', -- 'wash' or 'oil_change'
            skill_tag VARCHAR(50) NOT NULL DEFAULT 'wash_only', -- 'wash_only', 'oil_only', 'wash_and_oil', 'detailing'
            status VARCHAR(20) NOT NULL DEFAULT 'free', -- 'free' or 'busy'
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            current_order_id INT DEFAULT NULL,
            occupied_at DATETIME DEFAULT NULL,
            last_freed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charsetEngine}");

        // 2. Bay History Audit Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS bay_history (
            id {$autoInc},
            order_id INT NOT NULL,
            bay_id INT DEFAULT NULL,
            bay_name VARCHAR(100) DEFAULT NULL,
            plate_number VARCHAR(50) NOT NULL,
            action VARCHAR(50) NOT NULL, -- 'arrived', 'assigned', 'finished', 'queue_moved', 'reassigned', 'sent_to_tech', 'received_by_tech'
            performed_by INT DEFAULT NULL,
            performed_by_name VARCHAR(100) NOT NULL,
            performed_by_role VARCHAR(50) NOT NULL,
            reason TEXT DEFAULT NULL,
            details TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charsetEngine}");

        // 3. Order Child Tasks Table (for Mixed Orders & Handshakes)
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_tasks (
            id {$autoInc},
            order_id INT NOT NULL,
            task_type VARCHAR(50) NOT NULL, -- 'wash', 'oil_change', 'detailing'
            title VARCHAR(150) NOT NULL,
            sequence INT NOT NULL DEFAULT 1,
            status VARCHAR(50) NOT NULL DEFAULT 'waiting', -- 'pending_prereq', 'waiting', 'in_progress', 'ready_for_tech', 'completed'
            bay_id INT DEFAULT NULL,
            assigned_user_id INT DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            took_longer_than_expected TINYINT(1) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charsetEngine}");

        // 3b. Inspection Checklists Table (Safety and Finishing Pad QC)
        $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_checklists (
            id {$autoInc},
            order_id INT NOT NULL,
            task_id INT DEFAULT NULL,
            item_key VARCHAR(50) NOT NULL,
            item_name VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            measurement_value VARCHAR(50) DEFAULT NULL,
            photo_url VARCHAR(255) DEFAULT NULL,
            is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT DEFAULT NULL,
            checked_by_id INT DEFAULT NULL,
            checked_by_name VARCHAR(100) DEFAULT NULL,
            checked_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charsetEngine}");

        // 4. Ensure columns on bays table
        if (!$isSqlite) {
            $bayCols = $pdo->query("SHOW COLUMNS FROM bays")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('skill_tag', $bayCols, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN skill_tag VARCHAR(50) NOT NULL DEFAULT 'wash_only' AFTER bay_type");
            }
            if (!in_array('last_freed_at', $bayCols, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN last_freed_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER occupied_at");
            }
            $taskCols = $pdo->query("SHOW COLUMNS FROM order_tasks")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('lane', $taskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN lane VARCHAR(50) DEFAULT 'lane_1'");
            }
            if (!in_array('reserved_oil_liters', $taskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN reserved_oil_liters DECIMAL(5,2) DEFAULT NULL");
            }
            if (!in_array('actual_oil_used', $taskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN actual_oil_used DECIMAL(5,2) DEFAULT NULL");
            }
            if (!in_array('parts_status', $taskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN parts_status VARCHAR(50) DEFAULT 'none'");
            }
        } else {
            $tInfo = $pdo->query("PRAGMA table_info(bays)")->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_column($tInfo, 'name');
            if (!in_array('skill_tag', $existing, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN skill_tag VARCHAR(50) NOT NULL DEFAULT 'wash_only'");
            }
            if (!in_array('last_freed_at', $existing, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN last_freed_at DATETIME DEFAULT NULL");
            }
            $taskInfo = $pdo->query("PRAGMA table_info(order_tasks)")->fetchAll(PDO::FETCH_ASSOC);
            $existingTaskCols = array_column($taskInfo, 'name');
            if (!in_array('lane', $existingTaskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN lane VARCHAR(50) DEFAULT 'lane_1'");
            }
            if (!in_array('reserved_oil_liters', $existingTaskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN reserved_oil_liters DECIMAL(5,2) DEFAULT NULL");
            }
            if (!in_array('actual_oil_used', $existingTaskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN actual_oil_used DECIMAL(5,2) DEFAULT NULL");
            }
            if (!in_array('parts_status', $existingTaskCols, true)) {
                $pdo->exec("ALTER TABLE order_tasks ADD COLUMN parts_status VARCHAR(50) DEFAULT 'none'");
            }
        }

        // 5. Ensure columns on orders table
        if (!$isSqlite) {
            $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('bay_id', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN bay_id INT DEFAULT NULL AFTER pos_device_name");
            }
            if (!in_array('arrived_at', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN arrived_at DATETIME DEFAULT NULL AFTER repair_notes");
            }
            if (!in_array('queue_priority', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN queue_priority INT NOT NULL DEFAULT 0 AFTER arrived_at");
            }
            if (!in_array('priority_level', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN priority_level VARCHAR(10) NOT NULL DEFAULT 'P3' AFTER queue_priority");
            }
            if (!in_array('appointment_time', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN appointment_time DATETIME DEFAULT NULL AFTER priority_level");
            }
            if (!in_array('is_express', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN is_express TINYINT(1) NOT NULL DEFAULT 0 AFTER appointment_time");
            }
            if (!in_array('is_mixed', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN is_mixed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_express");
            }
            if (!in_array('service_sequence', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN service_sequence VARCHAR(20) NOT NULL DEFAULT 'lift_first' AFTER is_mixed");
            }
            if (!in_array('took_longer_than_expected', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN took_longer_than_expected TINYINT(1) DEFAULT NULL AFTER is_mixed");
            }
            if (!in_array('receipt_printed_at', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_printed_at DATETIME DEFAULT NULL AFTER paid_at");
            }
            if (!in_array('receipt_issued_at', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_issued_at DATETIME DEFAULT NULL AFTER receipt_printed_at");
            }
        } else {
            $tableInfo = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
            $existingCols = array_column($tableInfo, 'name');
            if (!in_array('bay_id', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN bay_id INT DEFAULT NULL");
            }
            if (!in_array('arrived_at', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN arrived_at DATETIME DEFAULT NULL");
            }
            if (!in_array('queue_priority', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN queue_priority INT NOT NULL DEFAULT 0");
            }
            if (!in_array('priority_level', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN priority_level VARCHAR(10) NOT NULL DEFAULT 'P3'");
            }
            if (!in_array('appointment_time', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN appointment_time DATETIME DEFAULT NULL");
            }
            if (!in_array('is_express', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN is_express TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!in_array('is_mixed', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN is_mixed TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!in_array('service_sequence', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN service_sequence VARCHAR(20) NOT NULL DEFAULT 'lift_first'");
            }
            if (!in_array('took_longer_than_expected', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN took_longer_than_expected TINYINT(1) DEFAULT NULL");
            }
            if (!in_array('receipt_printed_at', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_printed_at DATETIME DEFAULT NULL");
            }
            if (!in_array('receipt_issued_at', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_issued_at DATETIME DEFAULT NULL");
            }
        }

        // 6. Seed Default 4 Bays (Bays 1 to 3 for washing, Bay 4 for oil change)
        $bayCount = (int)$pdo->query("SELECT COUNT(*) FROM bays")->fetchColumn();
        if ($bayCount === 0) {
            $defaultBays = [
                ['bay_number' => 1, 'bay_name' => 'Bay 1', 'bay_type' => 'wash', 'skill_tag' => 'wash_only', 'sort_order' => 1],
                ['bay_number' => 2, 'bay_name' => 'Bay 2', 'bay_type' => 'wash', 'skill_tag' => 'wash_only', 'sort_order' => 2],
                ['bay_number' => 3, 'bay_name' => 'Bay 3', 'bay_type' => 'wash', 'skill_tag' => 'wash_only', 'sort_order' => 3],
                ['bay_number' => 4, 'bay_name' => 'Oil Change Bay', 'bay_type' => 'oil_change', 'skill_tag' => 'oil_only', 'sort_order' => 4],
            ];
            $ins = $pdo->prepare("INSERT INTO bays (bay_number, bay_name, bay_type, skill_tag, status, is_active, last_freed_at, sort_order) VALUES (?, ?, ?, ?, 'free', 1, CURRENT_TIMESTAMP, ?)");
            foreach ($defaultBays as $b) {
                $ins->execute([$b['bay_number'], $b['bay_name'], $b['bay_type'], $b['skill_tag'], $b['sort_order']]);
            }
        } else {
            // Normalize legacy bay statuses ('available' -> 'free')
            $pdo->exec("UPDATE bays SET status = 'free' WHERE status = 'available'");
            // Ensure skill_tags on existing rows if not set
            $pdo->exec("UPDATE bays SET skill_tag = 'wash_only' WHERE (skill_tag IS NULL OR skill_tag = '') AND bay_type = 'wash'");
            $pdo->exec("UPDATE bays SET skill_tag = 'oil_only' WHERE (skill_tag IS NULL OR skill_tag = '') AND bay_type = 'oil_change'");
            $pdo->exec("UPDATE bays SET last_freed_at = COALESCE(last_freed_at, updated_at, CURRENT_TIMESTAMP) WHERE status = 'free' AND last_freed_at IS NULL");
        }

        self::$tablesEnsured = true;
    }

    /**
     * Retrieve all bays with their current status, active car info, and skill tags.
     */
    public static function getAllBays(PDO $pdo): array {
        self::ensureTables($pdo);

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $t) {
            $driver = Database::getDriver();
        }
        $isSqlite = ($driver === 'sqlite');
        $groupConcat = $isSqlite ? "GROUP_CONCAT(DISTINCT oi.service_name)" : "GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ')";

        $sql = "
            SELECT b.*,
                   o.id as order_id, o.order_number, o.plate_number, o.vehicle_type,
                   o.customer_name, o.customer_phone, o.status as order_status,
                   o.started_at, o.repair_status, o.is_mixed,
                   {$groupConcat} as service_names
            FROM bays b
            LEFT JOIN orders o ON b.current_order_id = o.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            GROUP BY b.id
            ORDER BY b.sort_order ASC, b.id ASC
        ";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $now = time();
        foreach ($rows as $r) {
            $isBusy = ($r['status'] === 'busy' && !empty($r['current_order_id']));
            
            // Format duration in bay
            $durationMinutes = 0;
            if ($isBusy && !empty($r['occupied_at'])) {
                $occupiedTs = strtotime($r['occupied_at']);
                if ($occupiedTs > 0) {
                    $durationMinutes = max(0, (int)round(($now - $occupiedTs) / 60));
                }
            }

            // Calculate idle minutes if free
            $idleMinutes = 0;
            if (!$isBusy && (int)$r['is_active'] === 1) {
                $freedTimeStr = $r['last_freed_at'] ?: $r['updated_at'] ?: $r['created_at'];
                $freedTs = strtotime((string)$freedTimeStr);
                if ($freedTs > 0) {
                    $idleMinutes = max(0, (int)round(($now - $freedTs) / 60));
                }
            }

            $currentCar = null;
            if ($isBusy && !empty($r['plate_number'])) {
                $currentCar = [
                    'order_id' => (int)$r['order_id'],
                    'order_number' => $r['order_number'],
                    'plate_number' => $r['plate_number'],
                    'vehicle_type' => $r['vehicle_type'],
                    'customer_name' => $r['customer_name'] ?? 'Guest',
                    'customer_phone' => $r['customer_phone'] ?? '',
                    'service_names' => $r['service_names'] ?? '',
                    'order_status' => $r['order_status'],
                    'occupied_at' => $r['occupied_at'],
                    'started_at' => $r['started_at'],
                    'duration_minutes' => $durationMinutes,
                    'is_mixed' => (int)($r['is_mixed'] ?? 0)
                ];
            }

            $skillTag = $r['skill_tag'] ?? ($r['bay_type'] === 'oil_change' ? 'oil_only' : 'wash_only');

            $results[] = [
                'id' => (int)$r['id'],
                'bay_number' => (int)$r['bay_number'],
                'bay_name' => $r['bay_name'],
                'bay_type' => $r['bay_type'], // 'wash' or 'oil_change'
                'skill_tag' => $skillTag, // 'wash_only', 'oil_only', 'wash_and_oil', 'detailing'
                'status' => $isBusy ? 'busy' : 'free',
                'is_active' => (int)$r['is_active'],
                'current_order_id' => $isBusy && !empty($r['current_order_id']) ? (int)$r['current_order_id'] : null,
                'occupied_at' => $isBusy ? $r['occupied_at'] : null,
                'last_freed_at' => $r['last_freed_at'],
                'sort_order' => (int)$r['sort_order'],
                'duration_minutes' => $durationMinutes,
                'in_bay_minutes' => $durationMinutes,
                'idle_minutes' => $idleMinutes,
                'plate_number' => $isBusy && $currentCar ? $currentCar['plate_number'] : null,
                'service_names' => $isBusy && $currentCar ? $currentCar['service_names'] : null,
                'is_mixed' => $isBusy && $currentCar ? (int)$currentCar['is_mixed'] : 0,
                'current_car' => $isBusy ? $currentCar : null
            ];
        }

        return $results;
    }

    /**
     * Check if an order is technical / oil change vs car wash.
     */
    public static function isTechnicalOrder(array $order, PDO $pdo): bool {
        $orderId = (int)($order['id'] ?? 0);
        $validRepairStatuses = ['waiting', 'waiting_tech', 'working', 'in_progress', 'finished'];
        $orderRepairStatus = strtolower(trim((string)($order['repair_status'] ?? '')));
        if (in_array($orderRepairStatus, $validRepairStatuses, true)) {
            return true;
        }
        if (!empty($order['repair_notes'])) {
            return true;
        }
        if ($orderId <= 0) {
            return false;
        }

        // Check if mechanics assigned
        $stmtMech = $pdo->prepare("SELECT COUNT(*) FROM order_mechanics WHERE order_id = ?");
        $stmtMech->execute([$orderId]);
        if (((int)$stmtMech->fetchColumn()) > 0) {
            return true;
        }

        // Check categories of items
        $catCheck = $pdo->prepare("
            SELECT COUNT(*) FROM order_items oi
            LEFT JOIN services s ON oi.service_id = s.id
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ? AND (
                s.category IN ('repair', 'maintenance', 'care')
                OR p.category IN ('engine_oil', 'oil_filter', 'fluid', 'spare_part')
            )
        ");
        $catCheck->execute([$orderId]);
        if (((int)$catCheck->fetchColumn()) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Determine Queue Priority level and metadata.
     * Rules:
     * - P0: VIP & members
     * - P1: Appointments (within 10 minutes of time slot)
     * - P2: Express jobs (10 minutes or less)
     * - P3: Normal walk-ins in FIFO order
     * - Automatic promotion: Any P3 car waiting > 60 minutes is promoted automatically to P0.
     */
    public static function determinePriority(array $order, int $waitMinutes = 0): array {
        $priorityLevel = strtoupper(trim((string)($order['priority_level'] ?? 'P3')));
        $memLevel = strtolower(trim((string)($order['membership_level'] ?? '')));
        $loyaltyPoints = (int)($order['loyalty_points'] ?? 0);

        // 1. Check Appointment (Smart Queue Rule: Arrived within 10 minutes of time slot -> VIP Priority!)
        $isApptOnTime = false;
        $isApptLate = false;

        // Auto-lookup appointment for plate if not provided directly in array
        if (empty($order['appointment_time']) && !empty($order['plate_number'])) {
            $pdo = Database::getInstance();
            $aStmt = $pdo->prepare("
                SELECT appointment_time 
                FROM appointments 
                WHERE plate_number = ? AND appointment_date = CURRENT_DATE AND status NOT IN ('cancelled', 'completed')
                ORDER BY id DESC LIMIT 1
            ");
            $aStmt->execute([$order['plate_number']]);
            if ($aRow = $aStmt->fetch(PDO::FETCH_ASSOC)) {
                $order['appointment_time'] = $aRow['appointment_time'];
            }
        }

        if (!empty($order['appointment_time'])) {
            $apptTs = strtotime($order['appointment_time']);
            $arrTs = !empty($order['arrived_at']) ? strtotime($order['arrived_at']) : time();
            $diffMins = (int)round(($arrTs - $apptTs) / 60);

            if (abs($diffMins) <= 10) {
                // Arrived within 10 minutes before/after slot -> Treated as VIP!
                $isApptOnTime = true;
            } else {
                // Arrived late (> 10 mins after slot) -> Loses priority and treated as normal walk-in
                $isApptLate = true;
            }
        }

        // 2. Check VIP & Members OR On-Time Appointment -> P0
        $isVip = false;
        if (in_array($memLevel, ['vip', 'gold', 'platinum', 'member', 'diamond'], true) || $loyaltyPoints >= 500) {
            $isVip = true;
        }
        if ($priorityLevel === 'P0' || !empty($order['is_vip']) || $isApptOnTime) {
            $isVip = true;
        }

        // 3. Check Scheduled Appointment -> P1 (if explicitly scheduled and not late)
        $isAppt = false;
        if ($priorityLevel === 'P1' && !$isApptLate) {
            $isAppt = true;
        }

        // 4. Check Express (10 minutes or less) -> P2
        $isExpress = false;
        if (!empty($order['is_express']) || $priorityLevel === 'P2') {
            $isExpress = true;
        }

        // 5. Determine final priority
        if ($isVip) {
            return [
                'code' => 'P0',
                'label' => $isApptOnTime ? 'P0 VIP / Appointment' : 'P0 VIP / Member',
                'weight' => 0,
                'badge_class' => 'badge-purple',
                'is_promoted' => false
            ];
        }

        if ($isAppt) {
            return [
                'code' => 'P1',
                'label' => 'P1 Appointment',
                'weight' => 1,
                'badge_class' => 'badge-warning',
                'is_promoted' => false
            ];
        }

        if ($isExpress) {
            return [
                'code' => 'P2',
                'label' => 'P2 Express (≤10m)',
                'weight' => 2,
                'badge_class' => 'badge-info',
                'is_promoted' => false
            ];
        }

        // P3 Normal Walk-in
        if ($waitMinutes >= 60) {
            // Promoted automatically to P0!
            return [
                'code' => 'P0',
                'label' => 'P0 Promoted (>60m wait)',
                'weight' => 0,
                'badge_class' => 'badge-danger',
                'is_promoted' => true
            ];
        }

        return [
            'code' => 'P3',
            'label' => $isApptLate ? 'P3 Normal Walk-in (Late Appt)' : 'P3 Normal Walk-in',
            'weight' => 3,
            'badge_class' => 'badge-slate',
            'is_promoted' => false
        ];
    }

    /**
     * Ensure child tasks exist for an order (Mixed orders & single orders).
     * Enforces approved lifecycle: Mechanical Service in Bay 4 (Step 1) -> Wash Bay (Step 2).
     * Concurrency-safe: acquires parent row lock on orders to serialize simultaneous task creation.
     */
    public static function ensureOrderTasks(PDO $pdo, int $orderId): array {
        if ($orderId <= 0) {
            return [];
        }

        $wasInTransaction = $pdo->inTransaction();
        $maxAttempts = (!$wasInTransaction) ? 3 : 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Quick check without locking if tasks are already established
            $existing = $pdo->prepare("SELECT * FROM order_tasks WHERE order_id = ? ORDER BY sequence ASC, id ASC");
            $existing->execute([$orderId]);
            $tasks = $existing->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($tasks)) {
                return $tasks;
            }

            if (!$wasInTransaction && !$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            try {
                try {
                    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                } catch (Throwable $t) {
                    $driver = Database::getDriver();
                }
                $isMySql = ($driver === 'mysql');
                $forUpdate = $isMySql ? ' FOR UPDATE' : '';

                // 1. Lock the parent order row to serialize concurrent task generation for this order
                $ordLock = $pdo->prepare("SELECT * FROM orders WHERE id = ?" . $forUpdate);
                $ordLock->execute([$orderId]);
                $order = $ordLock->fetch(PDO::FETCH_ASSOC);

                if (!$order) {
                    if (!$wasInTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return [];
                }

                // 2. Re-check existing tasks inside the serialized lock using locking read (FOR UPDATE on MySQL/MariaDB)
                // to bypass REPEATABLE READ MVCC snapshot and read newly committed tasks from competing transactions
                $taskLockStmt = $pdo->prepare("SELECT * FROM order_tasks WHERE order_id = ? ORDER BY sequence ASC, id ASC" . $forUpdate);
                $taskLockStmt->execute([$orderId]);
                $tasks = $taskLockStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($tasks)) {
                    if (!$wasInTransaction && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    return $tasks;
                }

                // 3. Inspect items to determine task types
                $itemsStmt = $pdo->prepare("
                    SELECT oi.*, s.category as service_category, p.category as product_category
                    FROM order_items oi
                    LEFT JOIN services s ON oi.service_id = s.id
                    LEFT JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id = ?
                ");
                $itemsStmt->execute([$orderId]);
                $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                $hasWash = false;
                $hasOil = false;

                foreach ($items as $it) {
                    $sc = $it['service_category'] ?? '';
                    $pc = $it['product_category'] ?? '';
                    $name = strtolower($it['service_name'] ?? '');

                    if ($sc === 'wash' || $sc === 'detailing' || str_contains($name, 'wash') || str_contains($name, 'vacuum')) {
                        $hasWash = true;
                    }
                    if ($sc === 'repair' || $sc === 'maintenance' || $sc === 'care' ||
                        in_array($pc, ['engine_oil', 'oil_filter', 'fluid', 'spare_part'], true) ||
                        str_contains($name, 'oil') || str_contains($name, 'filter') || str_contains($name, 'repair')) {
                        $hasOil = true;
                    }
                }

                // Check repair status, mechanics, and repair notes
                // Payment status must NEVER determine whether an order contains mechanical work!
                $validRepairStatuses = ['waiting', 'waiting_tech', 'working', 'in_progress', 'finished'];
                $orderRepairStatus = strtolower(trim((string)($order['repair_status'] ?? '')));
                $hasValidRepairStatus = in_array($orderRepairStatus, $validRepairStatuses, true);

                if (!empty($items)) {
                    if (!$hasOil && !empty($order['repair_notes'])) {
                        $hasOil = true;
                    }
                    $stmtMech = $pdo->prepare("SELECT COUNT(*) FROM order_mechanics WHERE order_id = ?");
                    $stmtMech->execute([$orderId]);
                    if (((int)$stmtMech->fetchColumn()) > 0) {
                        $hasOil = true;
                    }
                } else {
                    // Fallback for orders without order_items (historical or synthetic test orders)
                    if ($hasValidRepairStatus || !empty($order['repair_notes']) || ((int)($order['is_mixed'] ?? 0) === 1)) {
                        $hasOil = true;
                    }
                }

                // If neither detected, default to car wash
                if (!$hasWash && !$hasOil) {
                    $hasWash = true;
                }

                $isMixed = ($hasWash && $hasOil) ? 1 : 0;
                $pdo->prepare("UPDATE orders SET is_mixed = ? WHERE id = ?")->execute([$isMixed, $orderId]);

                $repairFinished = (!empty($order['repair_status']) && strtolower(trim((string)$order['repair_status'])) === 'finished');

                $insTask = $pdo->prepare("
                    INSERT INTO order_tasks (order_id, task_type, title, sequence, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");

                if ($isMixed) {
                    $seqType = $order['service_sequence'] ?? 'lift_first';
                    if ($repairFinished) {
                        // Historical mixed order with finished mechanical service:
                        // Step 1: completed, Step 2: waiting in Wash Queue, Step 3: pending_prereq on Finishing Pad
                        $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Inspection (Step 1)', 1, 'completed']);
                        $insTask->execute([$orderId, 'wash', 'Exterior & Interior Wash (Step 2)', 2, 'waiting']);
                        $insTask->execute([$orderId, 'qc', 'Finishing Pad Quality Control (QC)', 3, 'pending_prereq']);
                    } elseif ($seqType === 'wash_first') {
                        // Wash First workflow (Bay 4 busy bypass):
                        // Step 1: waiting in Wash Queue, Step 2: pending_prereq in Bay 4 Lift, Step 3: pending_prereq on Finishing Pad
                        $insTask->execute([$orderId, 'wash', 'Exterior & Interior Wash (Step 1)', 1, 'waiting']);
                        $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Inspection (Step 2)', 2, 'pending_prereq']);
                        $insTask->execute([$orderId, 'qc', 'Finishing Pad Quality Control (QC)', 3, 'pending_prereq']);
                    } else {
                        // Standard mixed order: Lift First (Step 1: waiting, Step 2: pending_prereq, Step 3: pending_prereq)
                        $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Inspection (Step 1)', 1, 'waiting']);
                        $insTask->execute([$orderId, 'wash', 'Exterior & Interior Wash (Step 2)', 2, 'pending_prereq']);
                        $insTask->execute([$orderId, 'qc', 'Finishing Pad Quality Control (QC)', 3, 'pending_prereq']);
                    }
                } elseif ($hasOil) {
                    // Mechanical-only order: completes in Bay 4, bypasses wash & wash QC
                    $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Technical Service', 1, 'waiting']);
                } else {
                    // Wash-only order: Step 1 wash, Step 2 Finishing Pad QC
                    $insTask->execute([$orderId, 'wash', 'Car Wash Service', 1, 'waiting']);
                    $insTask->execute([$orderId, 'qc', 'Finishing Pad Quality Control (QC)', 2, 'pending_prereq']);
                }

                $existing->execute([$orderId]);
                $createdTasks = $existing->fetchAll(PDO::FETCH_ASSOC);

                if (!$wasInTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }

                return $createdTasks;
            } catch (Throwable $e) {
                if (!$wasInTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Never retry if an outer transaction already exists ($wasInTransaction === true)
                // Only retry if this method owns the transaction and error is SQLite locked/busy
                $isLockError = str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'busy');
                if (!$wasInTransaction && $isLockError && $attempt < $maxAttempts) {
                    // Exponential backoff with progressive sleep: 25ms, 50ms
                    usleep($attempt * 25000);
                    // Check if competing process already committed tasks before retrying transaction
                    $retryTasks = self::getOrderTasksReadOnly($pdo, $orderId);
                    if (!empty($retryTasks)) {
                        return $retryTasks;
                    }
                    continue;
                }

                throw $e;
            }
        }

        return [];
    }

    /**
     * Read-only task retrieval for queue operations.
     * Guarantees ZERO database writes on GET queue requests while preserving workflow sequence.
     */
    public static function getOrderTasksReadOnly(PDO $pdo, int $orderId, ?array $order = null): array {
        $existing = $pdo->prepare("SELECT * FROM order_tasks WHERE order_id = ? ORDER BY sequence ASC, id ASC");
        $existing->execute([$orderId]);
        $tasks = $existing->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($tasks)) {
            return $tasks;
        }

        // If tasks have not yet been persisted to DB, synthesize in-memory without database writes
        if (!$order) {
            $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $ordStmt->execute([$orderId]);
            $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$order) {
            return [];
        }

        $itemsStmt = $pdo->prepare("
            SELECT oi.*, s.category as service_category, p.category as product_category
            FROM order_items oi
            LEFT JOIN services s ON oi.service_id = s.id
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasWash = false;
        $hasOil = false;

        foreach ($items as $it) {
            $sc = $it['service_category'] ?? '';
            $pc = $it['product_category'] ?? '';
            $name = strtolower($it['service_name'] ?? '');

            if ($sc === 'wash' || $sc === 'detailing' || str_contains($name, 'wash') || str_contains($name, 'vacuum')) {
                $hasWash = true;
            }
            if ($sc === 'repair' || $sc === 'maintenance' || $sc === 'care' ||
                in_array($pc, ['engine_oil', 'oil_filter', 'fluid', 'spare_part'], true) ||
                str_contains($name, 'oil') || str_contains($name, 'filter') || str_contains($name, 'repair')) {
                $hasOil = true;
            }
        }

        $validRepairStatuses = ['waiting', 'waiting_tech', 'working', 'in_progress', 'finished'];
        $orderRepairStatus = strtolower(trim((string)($order['repair_status'] ?? '')));
        $hasValidRepairStatus = in_array($orderRepairStatus, $validRepairStatuses, true);

        if (!empty($items)) {
            if (!$hasOil && !empty($order['repair_notes'])) {
                $hasOil = true;
            }
            $stmtMech = $pdo->prepare("SELECT COUNT(*) FROM order_mechanics WHERE order_id = ?");
            $stmtMech->execute([$orderId]);
            if (((int)$stmtMech->fetchColumn()) > 0) {
                $hasOil = true;
            }
        } else {
            // Fallback for orders without order_items (historical or synthetic test orders)
            if ($hasValidRepairStatus || !empty($order['repair_notes']) || ((int)($order['is_mixed'] ?? 0) === 1)) {
                $hasOil = true;
            }
        }

        if (!$hasWash && !$hasOil) {
            $hasWash = true;
        }

        $isMixed = ($hasWash && $hasOil);
        $simulated = [];
        if ($isMixed) {
            $repairFinished = (!empty($order['repair_status']) && strtolower(trim((string)$order['repair_status'])) === 'finished');

            if ($repairFinished) {
                // Historical mixed order with finished mechanical service:
                // Step 1: completed, Step 2: waiting in Wash Queue, Step 3: pending_prereq on Finishing Pad
                $simulated[] = [
                    'id' => 0,
                    'order_id' => $orderId,
                    'task_type' => 'oil_change',
                    'title' => 'Engine Oil Change & Inspection (Step 1)',
                    'sequence' => 1,
                    'status' => 'completed'
                ];
                $simulated[] = [
                    'id' => 0,
                    'order_id' => $orderId,
                    'task_type' => 'wash',
                    'title' => 'Exterior & Interior Wash (Step 2)',
                    'sequence' => 2,
                    'status' => 'waiting'
                ];
                $simulated[] = [
                    'id' => 0,
                    'order_id' => $orderId,
                    'task_type' => 'qc',
                    'title' => 'Finishing Pad Quality Control (QC)',
                    'sequence' => 3,
                    'status' => 'pending_prereq'
                ];
            } else {
                // Historical mixed order with unfinished mechanical service:
                // Step 1: waiting in Technical Queue (Lift First), Step 2: pending_prereq (Wash Second), Step 3: pending_prereq (QC)
                $simulated[] = [
                    'id' => 0,
                    'order_id' => $orderId,
                    'task_type' => 'oil_change',
                    'title' => 'Engine Oil Change & Inspection (Step 1)',
                    'sequence' => 1,
                    'status' => 'waiting'
                ];
                $simulated[] = [
                    'id' => 0,
                    'order_id' => $orderId,
                    'task_type' => 'wash',
                    'title' => 'Exterior & Interior Wash (Step 2)',
                    'sequence' => 2,
                    'status' => 'pending_prereq'
                ];
                $simulated[] = [
                    'id' => 0,
                    'order_id' => $orderId,
                    'task_type' => 'qc',
                    'title' => 'Finishing Pad Quality Control (QC)',
                    'sequence' => 3,
                    'status' => 'pending_prereq'
                ];
            }
        } elseif ($hasOil) {
            $simulated[] = [
                'id' => 0,
                'order_id' => $orderId,
                'task_type' => 'oil_change',
                'title' => 'Engine Oil Change & Technical Service',
                'sequence' => 1,
                'status' => 'waiting'
            ];
        } else {
            $simulated[] = [
                'id' => 0,
                'order_id' => $orderId,
                'task_type' => 'wash',
                'title' => 'Car Wash Service',
                'sequence' => 1,
                'status' => 'waiting'
            ];
            $simulated[] = [
                'id' => 0,
                'order_id' => $orderId,
                'task_type' => 'qc',
                'title' => 'Finishing Pad Quality Control (QC)',
                'sequence' => 2,
                'status' => 'pending_prereq'
            ];
        }

        return $simulated;
    }

    /**
     * Get waiting queue for wash and technical orders, ordered strictly by priority and FIFO.
     */
    public static function getQueues(PDO $pdo): array {
        self::ensureTables($pdo);

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $t) {
            $driver = Database::getDriver();
        }
        $isSqlite = ($driver === 'sqlite');
        $groupConcat = $isSqlite ? "GROUP_CONCAT(DISTINCT oi.service_name)" : "GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ')";

        // Fetch all waiting orders joined with customer membership
        $sql = "
            SELECT o.*,
                   c.membership_level, c.loyalty_points,
                   {$groupConcat} as service_names,
                   (SELECT COUNT(*) FROM order_mechanics om WHERE om.order_id = o.id) as mechanic_count
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.status IN ('waiting', 'washing') AND (o.bay_id IS NULL OR o.bay_id = 0) AND (o.is_active = 1 OR o.is_active IS NULL)
            GROUP BY o.id
            ORDER BY CASE WHEN o.queue_priority > 0 THEN o.queue_priority ELSE 999999 END ASC, COALESCE(o.arrived_at, o.created_at) ASC, o.id ASC
        ";

        $orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $processedOrders = [];

        foreach ($orders as $ord) {
            $orderId = (int)$ord['id'];
            $tasks = self::getOrderTasksReadOnly($pdo, $orderId, $ord);

            // Determine current active task
            $activeTask = null;
            $allDone = true;
            foreach ($tasks as $t) {
                if ($t['status'] !== 'completed') {
                    $allDone = false;
                    if ($activeTask === null && in_array($t['status'], ['waiting', 'ready_for_tech', 'in_progress'], true)) {
                        $activeTask = $t;
                    }
                }
            }

            // If order has no active waiting task (e.g., all tasks done), skip
            if ($allDone && count($tasks) > 0) {
                continue;
            }

            $arrivedTimeStr = $ord['arrived_at'] ?: $ord['created_at'];
            $arrivedTs = strtotime((string)$arrivedTimeStr);
            $waitMinutes = ($arrivedTs > 0) ? max(0, (int)round(($now - $arrivedTs) / 60)) : 0;

            $priorityMeta = self::determinePriority($ord, $waitMinutes);

            // Determine if next required step is technical vs wash
            $isTechNext = false;
            if ($activeTask) {
                $isTechNext = ($activeTask['task_type'] === 'oil_change');
            } else {
                $isTechNext = self::isTechnicalOrder($ord, $pdo);
            }

            $isWaitingTechHandover = ($activeTask && $activeTask['status'] === 'ready_for_tech');

            $processedOrders[] = [
                'order_id' => $orderId,
                'order_number' => $ord['order_number'],
                'customer_name' => $ord['customer_name'] ?? 'Guest',
                'customer_phone' => $ord['customer_phone'] ?? '',
                'plate_number' => $ord['plate_number'],
                'vehicle_type' => $ord['vehicle_type'],
                'service_names' => $ord['service_names'] ?? '',
                'status' => $ord['status'],
                'repair_status' => $ord['repair_status'] ?? null,
                'arrived_at' => $arrivedTimeStr,
                'created_at' => $ord['created_at'] ?? null,
                'wait_minutes' => $waitMinutes,
                'estimated_duration_minutes' => (int)($ord['estimated_duration_minutes'] ?? 0),
                'queue_priority' => (int)($ord['queue_priority'] ?? 0),
                'priority_code' => $priorityMeta['code'],
                'priority_label' => $priorityMeta['label'],
                'priority_weight' => $priorityMeta['weight'],
                'priority_badge' => $priorityMeta['badge_class'],
                'is_promoted' => $priorityMeta['is_promoted'],
                'is_technical' => $isTechNext,
                'is_mixed' => (int)($ord['is_mixed'] ?? 0),
                'is_post_mechanical' => ((int)($ord['is_mixed'] ?? 0) === 1 && ($ord['repair_status'] ?? '') === 'finished'),
                'is_waiting_wash_handover' => ((int)($ord['is_mixed'] ?? 0) === 1 && ($ord['repair_status'] ?? '') === 'finished'),
                'active_task' => $activeTask,
                'is_waiting_tech_handover' => $isWaitingTechHandover
            ];
        }

        // Sort queues: First by positive supervisor override (> 0, smaller positive first),
        // non-positive values (0 or negative) are treated as non-overridden,
        // then by dynamic priority_weight ASC (P0=0, P1=1, P2=2, P3=3),
        // within the same priority tier: exact arrival timestamp FIFO (earlier arrival = first),
        // deterministic tiebreaker: order_id ASC.
        usort($processedOrders, function($a, $b) {
            $pA = (int)($a['queue_priority'] ?? 0);
            $pB = (int)($b['queue_priority'] ?? 0);
            $overrideA = ($pA > 0);
            $overrideB = ($pB > 0);

            // 1. Partition: Positive supervisor override always precedes non-overridden (<= 0)
            if ($overrideA !== $overrideB) {
                return $overrideA ? -1 : 1;
            }

            // 2. Both are supervisor overrides: Smaller positive number precedes larger positive
            if ($overrideA && $overrideB) {
                if ($pA !== $pB) {
                    return $pA <=> $pB;
                }
            }

            // 3. Both non-overridden (or identical override value): Dynamic Priority Tier (P0=0, P1=1, P2=2, P3=3)
            if ($a['priority_weight'] !== $b['priority_weight']) {
                return $a['priority_weight'] <=> $b['priority_weight'];
            }

            // 4. Within same tier: Exact arrival timestamp FIFO (arrived_at with created_at fallback)
            $timeA = !empty($a['arrived_at']) ? $a['arrived_at'] : (!empty($a['created_at']) ? $a['created_at'] : null);
            $timeB = !empty($b['arrived_at']) ? $b['arrived_at'] : (!empty($b['created_at']) ? $b['created_at'] : null);
            $tsA = $timeA ? strtotime((string)$timeA) : 0;
            $tsB = $timeB ? strtotime((string)$timeB) : 0;
            if ($tsA !== $tsB) {
                return $tsA <=> $tsB;
            }

            // 5. Deterministic tiebreaker: order_id ASC
            return (int)$a['order_id'] <=> (int)$b['order_id'];
        });

        $washQueue = [];
        $techQueue = [];
        $qcQueue = [];
        $washPos = 1;
        $techPos = 1;
        $qcPos = 1;

        foreach ($processedOrders as $item) {
            $taskType = $item['active_task']['task_type'] ?? '';
            if ($taskType === 'qc') {
                $item['position'] = $qcPos;
                $item['position_label'] = self::formatPositionNumber($qcPos);
                $qcQueue[] = $item;
                $qcPos++;
            } elseif ($item['is_technical']) {
                $item['position'] = $techPos;
                $item['position_label'] = self::formatPositionNumber($techPos);
                $techQueue[] = $item;
                $techPos++;
            } else {
                $item['position'] = $washPos;
                $item['position_label'] = self::formatPositionNumber($washPos);
                $washQueue[] = $item;
                $washPos++;
            }
        }

        return [
            'wash_queue' => $washQueue,
            'technical_queue' => $techQueue,
            'qc_queue' => $qcQueue,
            'finishing_pad_queue' => $qcQueue,
            'all_queue' => $processedOrders,
            'total_waiting' => count($processedOrders)
        ];
    }

    /**
     * Compute Queue Health metrics:
     * - Number waiting
     * - Average wait time
     * - Bottleneck bay highlighted
     * - Idle bay alert (bay idle > 5 mins while matching car waiting)
     */
    public static function getQueueHealth(PDO $pdo): array {
        $bays = self::getAllBays($pdo);
        $queues = self::getQueues($pdo);
        $totalWaiting = $queues['total_waiting'];

        $allWaiting = $queues['all_queue'];
        $totalWaitMinutes = 0;
        foreach ($allWaiting as $w) {
            $totalWaitMinutes += $w['wait_minutes'];
        }
        $avgWaitMinutes = ($totalWaiting > 0) ? (int)round($totalWaitMinutes / $totalWaiting) : 0;

        // Bottleneck Bay: Find occupied bay with longest duration
        $bottleneckBay = null;
        $maxDuration = -1;
        foreach ($bays as $b) {
            if ($b['is_active'] && $b['status'] === 'busy' && $b['duration_minutes'] > $maxDuration) {
                $maxDuration = $b['duration_minutes'];
                $bottleneckBay = $b;
            }
        }

        // Idle Bay Alert: Free active bay idle for > 5 minutes while a matching car is waiting
        $idleAlertBays = [];
        $washWaitingCount = count($queues['wash_queue']);
        $techWaitingCount = count($queues['technical_queue']);

        foreach ($bays as $b) {
            if ($b['is_active'] && $b['status'] === 'free') {
                $skill = $b['skill_tag'];
                $hasMatchingCar = false;

                if (in_array($skill, ['wash_only', 'detailing'], true) && $washWaitingCount > 0) {
                    $hasMatchingCar = true;
                } elseif ($skill === 'oil_only' && $techWaitingCount > 0) {
                    $hasMatchingCar = true;
                } elseif ($skill === 'wash_and_oil' && ($washWaitingCount > 0 || $techWaitingCount > 0)) {
                    $hasMatchingCar = true;
                }

                if ($b['idle_minutes'] >= 5 && $hasMatchingCar) {
                    $idleAlertBays[] = [
                        'bay_id' => $b['id'],
                        'bay_number' => $b['bay_number'],
                        'bay_name' => $b['bay_name'],
                        'skill_tag' => $b['skill_tag'],
                        'idle_minutes' => $b['idle_minutes'],
                        'matching_waiting' => ($skill === 'oil_only' ? $techWaitingCount : $washWaitingCount)
                    ];
                }
            }
        }

        return [
            'total_waiting' => $totalWaiting,
            'wash_waiting' => $washWaitingCount,
            'tech_waiting' => $techWaitingCount,
            'avg_wait_minutes' => $avgWaitMinutes,
            'bottleneck_bay' => $bottleneckBay,
            'has_idle_alert' => !empty($idleAlertBays),
            'idle_alert_bays' => $idleAlertBays
        ];
    }

    public static function formatPositionNumber(int $pos): string {
        if ($pos % 100 >= 11 && $pos % 100 <= 13) {
            return $pos . 'th';
        }
        return match ($pos % 10) {
            1 => $pos . 'st',
            2 => $pos . 'nd',
            3 => $pos . 'rd',
            default => $pos . 'th'
        };
    }

    /**
     * Mark an order as "Customer arrived" -> joins waiting queue in FIFO order.
     */
    public static function markCustomerArrived(PDO $pdo, int $orderId, int $userId, string $userName, string $userRole): array {
        self::ensureTables($pdo);

        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found'];
        }

        if (in_array($order['status'], ['cancelled'], true) || ($order['status'] === 'finished' && !empty($order['finished_at']) && $order['payment_status'] === 'paid')) {
            return ['success' => false, 'error' => 'INVALID_STATUS', 'message' => 'Cannot mark arrived: Order is ' . $order['status']];
        }

        $now = date('Y-m-d H:i:s');
        $arrivedAt = $order['arrived_at'] ?: $now;

        // Ensure tasks exist
        self::ensureOrderTasks($pdo, $orderId);

        $pdo->prepare("
            UPDATE orders SET
                arrived_at = ?,
                status = CASE WHEN status IN ('draft', 'pending', 'paid') THEN 'waiting' ELSE status END
            WHERE id = ?
        ")->execute([$arrivedAt, $orderId]);

        // Audit log
        $pdo->prepare("
            INSERT INTO bay_history (order_id, plate_number, action, performed_by, performed_by_name, performed_by_role, details, created_at)
            VALUES (?, ?, 'arrived', ?, ?, ?, ?, ?)
        ")->execute([
            $orderId, $order['plate_number'], $userId, $userName, $userRole,
            "Customer arrived. Car joined the queue.", $now
        ]);

        // Notify shop floor
        NotificationManager::notify($pdo, [
            'target_role' => 'washer',
            'category' => 'job_queue',
            'reference_id' => 'ARRIVED-' . $orderId,
            'title_en' => 'Customer Arrived',
            'title_km' => 'អតិថិជនបានមកដល់',
            'message_en' => "Vehicle {$order['plate_number']} has arrived and joined the wash queue.",
            'message_km' => "រថយន្ត {$order['plate_number']} បានមកដល់ហើយចូលក្នុងជួររង់ចាំ។",
            'action_url' => 'index.php?view=washer_jobs',
            'sound_type' => 'chime'
        ]);

        $isMixedOrTech = ((int)($order['is_mixed'] ?? 0) === 1) || self::isTechnicalOrder($order, $pdo);
        if ($isMixedOrTech) {
            NotificationManager::notify($pdo, [
                'target_role' => 'technical',
                'category' => 'job_queue',
                'reference_id' => 'ARRIVED-TECH-' . $orderId,
                'title_en' => 'Customer Arrived (Technical)',
                'title_km' => 'អតិថិជនបានមកដល់ (បច្ចេកទេស)',
                'message_en' => "Technical vehicle {$order['plate_number']} has arrived and joined the queue.",
                'message_km' => "រថយន្តបច្ចេកទេស {$order['plate_number']} បានមកដល់ហើយចូលក្នុងជួររង់ចាំ។",
                'action_url' => 'index.php?view=mechanic_jobs',
                'sound_type' => 'chime'
            ]);
        }

        return [
            'success' => true,
            'message' => "Customer arrived for {$order['plate_number']}. Car has joined the queue.",
            'order_id' => $orderId,
            'arrived_at' => $arrivedAt
        ];
    }

    /**
     * Check if a bay's skill_tag allows a specific task_type.
     */
    public static function isBaySkillCompatible(string $skillTag, string $taskType): bool {
        if ($skillTag === 'wash_and_oil') {
            return true;
        }
        if ($taskType === 'wash') {
            return in_array($skillTag, ['wash_only', 'wash_and_oil', 'wash_detail', 'detailing'], true);
        }
        if ($taskType === 'oil_change') {
            return in_array($skillTag, ['oil_only', 'wash_and_oil'], true);
        }
        if ($taskType === 'detailing') {
            return in_array($skillTag, ['detailing', 'detail_only', 'wash_detail', 'wash_only', 'wash_and_oil'], true);
        }
        return false;
    }

    /**
     * Assign a car to a bay.
     * Concurrency check: If two people try to take the same bay at the same time,
     * only the first one succeeds and the other gets "Bay already taken".
     * Server-side enforced with DB transactions and atomic row matching.
     */
    public static function assignCarToBay(
        PDO $pdo,
        int $bayId,
        int $orderId,
        int $userId,
        string $userName,
        string $userRole,
        ?string $reason = null,
        ?string $managerPin = null,
        ?string $managerUsername = null,
        bool $allowWashFirst = false
    ): array {
        self::ensureTables($pdo);

        // "No receipt, no wash" rule enforcement
        try {
            OrderGate::assertReceiptPrinted($pdo, $orderId, $managerPin, $reason, $managerUsername, $userId, $userName, $userRole);
        } catch (PaymentRequiredException $pe) {
            return [
                'success' => false,
                'error' => 'PAYMENT_REQUIRED',
                'message' => $pe->getMessage()
            ];
        } catch (ReceiptRequiredException $re) {
            return [
                'success' => false,
                'error' => 'RECEIPT_REQUIRED',
                'message' => $re->getMessage()
            ];
        } catch (Throwable $e) {
            if ($e->getCode() === 404 || strpos($e->getMessage(), 'not found') !== false) {
                return [
                    'success' => false,
                    'error' => 'ORDER_NOT_FOUND',
                    'message' => $e->getMessage()
                ];
            }
            return [
                'success' => false,
                'error' => 'RECEIPT_REQUIRED',
                'message' => $e->getMessage()
            ];
        }

        // 1. Fetch bay
        $bayStmt = $pdo->prepare("SELECT * FROM bays WHERE id = ?");
        $bayStmt->execute([$bayId]);
        $bay = $bayStmt->fetch();

        if (!$bay) {
            return ['success' => false, 'error' => 'BAY_NOT_FOUND', 'message' => 'Bay not found'];
        }

        if ((int)$bay['is_active'] !== 1) {
            return ['success' => false, 'error' => 'BAY_INACTIVE', 'message' => 'Bay is currently turned off in Settings'];
        }

        if ($bay['status'] === 'busy' || !empty($bay['current_order_id'])) {
            return ['success' => false, 'error' => 'BAY_ALREADY_TAKEN', 'message' => 'Bay already taken'];
        }

        // 2. Fetch order
        $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found'];
        }

        if (in_array($order['status'], ['finished', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'INVALID_ORDER_STATUS', 'message' => "Cannot assign: order is {$order['status']}"];
        }

        // Check if this car is already in another bay
        $otherBayStmt = $pdo->prepare("SELECT id, bay_name FROM bays WHERE current_order_id = ? AND id != ?");
        $otherBayStmt->execute([$orderId, $bayId]);
        $otherBay = $otherBayStmt->fetch();
        if ($otherBay) {
            return ['success' => false, 'error' => 'CAR_ALREADY_IN_BAY', 'message' => "Car is already assigned to {$otherBay['bay_name']}"];
        }

        // 3. Child Tasks & Step Enforcement
        $tasks = self::ensureOrderTasks($pdo, $orderId);
        $activeTask = null;
        foreach ($tasks as $t) {
            if ($t['status'] !== 'completed') {
                $activeTask = $t;
                break;
            }
        }

        $taskType = $activeTask ? $activeTask['task_type'] : ($bay['bay_type'] === 'oil_change' ? 'oil_change' : 'wash');

        // Sequence Rule: Nobody skips a step!
        if ($activeTask && $activeTask['sequence'] > 1 && $activeTask['status'] === 'pending_prereq') {
            return [
                'success' => false,
                'error' => 'PREREQUISITE_STEP_INCOMPLETE',
                'message' => 'Sequence Rule: Previous step must be finished before this step can begin. Nobody skips a step.'
            ];
        }

        // Workflow Sequence Gating:
        $isWashBay = ($bay['bay_type'] !== 'oil_change');
        if ($isWashBay) {
            $washTask = null;
            $oilTask = null;
            foreach ($tasks as $t) {
                if ($t['task_type'] === 'wash') $washTask = $t;
                if ($t['task_type'] === 'oil_change') $oilTask = $t;
            }
            if ($oilTask && $oilTask['status'] !== 'completed' && $washTask && $oilTask['sequence'] < $washTask['sequence']) {
                if ($allowWashFirst) {
                    self::switchServiceSequence($pdo, $orderId, 'wash_first', $userId, $userName, $userRole);
                    $tasks = self::ensureOrderTasks($pdo, $orderId);
                    foreach ($tasks as $t) {
                        if ($t['status'] !== 'completed') {
                            $activeTask = $t;
                            break;
                        }
                    }
                    $taskType = $activeTask ? $activeTask['task_type'] : 'wash';
                } else {
                    return [
                        'success' => false,
                        'error' => 'PREREQUISITE_STEP_INCOMPLETE',
                        'can_wash_first' => true,
                        'message' => 'Sequence Rule: Step 1 (Mechanical Service in Bay 4) must be finished before entering a Wash Bay. Nobody skips a step.'
                    ];
                }
            }
        } else {
            // Bay 4 (Oil Change): Gated if vehicle is scheduled for Wash First and Wash is incomplete
            $washTask = null;
            $oilTask = null;
            foreach ($tasks as $t) {
                if ($t['task_type'] === 'wash') $washTask = $t;
                if ($t['task_type'] === 'oil_change') $oilTask = $t;
            }
            if ($washTask && $washTask['status'] !== 'completed' && $oilTask && $washTask['sequence'] < $oilTask['sequence']) {
                return [
                    'success' => false,
                    'error' => 'PREREQUISITE_STEP_INCOMPLETE',
                    'message' => 'Sequence Rule: Vehicle is scheduled for Wash First. Step 1 (Car Wash) must be finished before entering Bay 4.'
                ];
            }
        }

        // Skill tag compatibility check
        $skillTag = $bay['skill_tag'] ?? ($bay['bay_type'] === 'oil_change' ? 'oil_only' : 'wash_only');
        if (!self::isBaySkillCompatible($skillTag, $taskType)) {
            $humanSkill = ucwords(str_replace('_', ' ', $skillTag));
            $humanTask = ucwords(str_replace('_', ' ', $taskType));
            return [
                'success' => false,
                'error' => 'BAY_SKILL_MISMATCH',
                'message' => "Skill Mismatch: Bay #{$bay['bay_number']} is configured for '{$humanSkill}' and cannot accept a '{$humanTask}' job."
            ];
        }

        // Automatic bay choice & override verification
        require_once __DIR__ . '/SmartQueueEngine.php';
        $scoredBays = SmartQueueEngine::scoreBaysForCar($pdo, $order);
        $suggestedBay = !empty($scoredBays) ? $scoredBays[0] : null;
        $isOverride = ($suggestedBay && (int)$suggestedBay['bay_id'] !== $bayId && $suggestedBay['score'] > 0);

        $trimmedReason = trim((string)$reason);
        if ($isOverride && ($trimmedReason === '' || mb_strlen($trimmedReason) < 3)) {
            return [
                'success' => false,
                'error' => 'OVERRIDE_REASON_REQUIRED',
                'message' => "Bay override: Bay #{$suggestedBay['bay_number']} ({$suggestedBay['bay_name']}) was recommended as the optimal choice. To assign to {$bay['bay_name']} instead, an override reason is required (minimum 3 characters)."
            ];
        }

        $now = date('Y-m-d H:i:s');

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        try {
            // 4. Atomic Conditional Update on bay
            $upBay = $pdo->prepare("
                UPDATE bays 
                SET status = 'busy', current_order_id = ?, occupied_at = ?, updated_at = ? 
                WHERE id = ? AND status IN ('free', 'available') AND is_active = 1
            ");
            $upBay->execute([$orderId, $now, $now, $bayId]);

            if ($upBay->rowCount() === 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                return ['success' => false, 'error' => 'BAY_ALREADY_TAKEN', 'message' => 'Bay already taken'];
            }

            // 5. Update active task status to in_progress
            if ($activeTask) {
                $pdo->prepare("
                    UPDATE order_tasks 
                    SET status = 'in_progress', bay_id = ?, assigned_user_id = ?, started_at = COALESCE(started_at, ?), updated_at = ?
                    WHERE id = ?
                ")->execute([$bayId, $userId, $now, $now, $activeTask['id']]);
            }

            // 6. Update order status:
            // Decision 2 (Option 2A): During Bay 4 mechanical work, status = 'waiting' and repair_status = 'working'
            // For wash bays (Bays 1-3), status = 'washing'
            if ($bay['bay_type'] === 'oil_change' || $taskType === 'oil_change') {
                $newStatus = 'waiting';
                $repairSql = ", repair_status = 'working'";
                $techUserId = in_array($userRole, ['mechanic', 'technical'], true) ? $userId : null;
                if ($techUserId) {
                    $chkOm = $pdo->prepare("SELECT COUNT(*) FROM order_mechanics WHERE order_id = ? AND mechanic_id = ?");
                    $chkOm->execute([$orderId, $techUserId]);
                    if (((int)$chkOm->fetchColumn()) === 0) {
                        $pdo->prepare("INSERT INTO order_mechanics (order_id, mechanic_id, commission_usd) VALUES (?, ?, 0.00)")
                            ->execute([$orderId, $techUserId]);
                    }
                }
            } else {
                $newStatus = 'washing';
                $repairSql = "";
            }
            $suggestedId = $suggestedBay ? (int)$suggestedBay['bay_id'] : $bayId;

            $pdo->prepare("
                UPDATE orders 
                SET bay_id = ?, status = ?, started_at = COALESCE(started_at, ?), suggested_bay_id = ?, bay_override_reason = ? {$repairSql}
                WHERE id = ?
            ")->execute([$bayId, $newStatus, $now, $suggestedId, $isOverride ? $reason : null, $orderId]);

            // If override, record mandatory audit log (override must fail if audit log fails)
            if ($isOverride) {
                if (!class_exists('AuditLogger')) {
                    require_once __DIR__ . '/AuditLogger.php';
                }
                AuditLogger::logStrict(
                    $pdo,
                    'BAY_OVERRIDE',
                    "Supervisor/Staff {$userName} ({$userRole}) overrode recommended Bay #{$suggestedBay['bay_number']} for Bay #{$bay['bay_number']}. Reason: {$trimmedReason}",
                    'orders',
                    (string)$orderId,
                    $userId,
                    $userName,
                    $userRole
                );
            }

            // 7. History audit
            $reasonText = !empty($trimmedReason) ? " Reason: {$trimmedReason}" : "";
            $details = "Staff {$userName} ({$userRole}) assigned car {$order['plate_number']} to {$bay['bay_name']} [{$skillTag}].{$reasonText}";
            $pdo->prepare("
                INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                VALUES (?, ?, ?, ?, 'assigned', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $bayId, $bay['bay_name'], $order['plate_number'],
                $userId, $userName, $userRole, $trimmedReason !== '' ? $trimmedReason : null, $details, $now
            ]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'message' => "Car {$order['plate_number']} put into {$bay['bay_name']}",
                'bay_id' => $bayId,
                'bay_name' => $bay['bay_name'],
                'order_id' => $orderId,
                'plate_number' => $order['plate_number'],
                'status' => 'busy'
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'Audit log write failed')) {
                return ['success' => false, 'error' => 'AUDIT_LOG_FAILED', 'message' => 'Override rejected: Failed to record mandatory audit record (' . $e->getMessage() . ')'];
            }
            return ['success' => false, 'error' => 'ASSIGN_ERROR', 'message' => $e->getMessage()];
        }
    }

    /**
     * Reassign a car from one bay to another (Supervisor Drag & Drop).
     * Rule: Reason is REQUIRED and written to history.
     */
    public static function reassignBay(PDO $pdo, int $orderId, int $newBayId, string $reason, int $userId, string $userName, string $userRole): array {
        self::ensureTables($pdo);

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            return [
                'success' => false,
                'error' => 'REASON_REQUIRED',
                'message' => 'A reason is required to reassign a car to another bay.'
            ];
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $t) {
            $driver = Database::getDriver();
        }
        $isMysql = ($driver === 'mysql');

        $pdo->beginTransaction();
        try {
            $lockClause = $isMysql ? " FOR UPDATE" : "";

            // 1. Fetch Order and old bay
            $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? {$lockClause}");
            $ordStmt->execute([$orderId]);
            $order = $ordStmt->fetch();

            if (!$order) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found'];
            }

            $oldBayStmt = $pdo->prepare("SELECT * FROM bays WHERE current_order_id = ? {$lockClause}");
            $oldBayStmt->execute([$orderId]);
            $oldBay = $oldBayStmt->fetch();

            // 2. Fetch new bay
            $newBayStmt = $pdo->prepare("SELECT * FROM bays WHERE id = ? {$lockClause}");
            $newBayStmt->execute([$newBayId]);
            $newBay = $newBayStmt->fetch();

            if (!$newBay) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'BAY_NOT_FOUND', 'message' => 'Target bay not found'];
            }

            if ((int)$newBay['is_active'] !== 1) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'BAY_INACTIVE', 'message' => 'Target bay is inactive'];
            }

            if ($newBay['status'] === 'busy' || (!empty($newBay['current_order_id']) && (int)$newBay['current_order_id'] !== $orderId)) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'BAY_BUSY', 'message' => "Target bay {$newBay['bay_name']} is already busy"];
            }

            $now = date('Y-m-d H:i:s');

            // 3. Free old bay
            if ($oldBay) {
                $pdo->prepare("
                    UPDATE bays SET status = 'free', current_order_id = NULL, occupied_at = NULL, last_freed_at = ?, updated_at = ?
                    WHERE id = ?
                ")->execute([$now, $now, $oldBay['id']]);
            }

            // 4. Occupy new bay
            $pdo->prepare("
                UPDATE bays SET status = 'busy', current_order_id = ?, occupied_at = ?, updated_at = ?
                WHERE id = ?
            ")->execute([$orderId, $now, $now, $newBayId]);

            // 5. Update order and active tasks
            $pdo->prepare("UPDATE orders SET bay_id = ? WHERE id = ?")->execute([$newBayId, $orderId]);
            $pdo->prepare("UPDATE order_tasks SET bay_id = ? WHERE order_id = ? AND status = 'in_progress'")->execute([$newBayId, $orderId]);

            // 6. Log history
            $oldBayName = $oldBay ? $oldBay['bay_name'] : 'None';
            $details = "Supervisor moved car {$order['plate_number']} from {$oldBayName} to {$newBay['bay_name']}. Reason: {$trimmedReason}";
            $pdo->prepare("
                INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                VALUES (?, ?, ?, ?, 'reassigned', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $newBayId, $newBay['bay_name'], $order['plate_number'],
                $userId, $userName, $userRole, $trimmedReason, $details, $now
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'message' => "Car {$order['plate_number']} moved to {$newBay['bay_name']}.",
                'order_id' => $orderId,
                'old_bay' => $oldBayName,
                'new_bay' => $newBay['bay_name']
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'REASSIGN_ERROR', 'message' => $e->getMessage()];
        }
    }

    /**
     * Change a car's place in the waiting queue (Supervisor Drag & Drop or Move).
     * Rule: Reason is REQUIRED and recorded in history.
     */
    public static function changeQueuePosition(PDO $pdo, int $orderId, int $targetPosition, string $reason, int $userId, string $userName, string $userRole): array {
        self::ensureTables($pdo);

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            return [
                'success' => false,
                'error' => 'REASON_REQUIRED',
                'message' => 'A valid reason is required to change a car\'s position in the queue.'
            ];
        }

        $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found'];
        }

        // Target position calculation: adjust queue_priority
        // Smaller queue_priority comes first
        $newPriority = ($targetPosition * 10) - 5;
        $now = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE orders SET queue_priority = ? WHERE id = ?")->execute([$newPriority, $orderId]);

            $details = "Supervisor moved car {$order['plate_number']} to position #{$targetPosition} in queue. Reason: {$trimmedReason}";
            $pdo->prepare("
                INSERT INTO bay_history (order_id, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                VALUES (?, ?, 'queue_moved', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $order['plate_number'], $userId, $userName, $userRole, $trimmedReason, $details, $now
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'message' => "Car {$order['plate_number']} moved to position #{$targetPosition} in queue.",
                'order_id' => $orderId,
                'target_position' => $targetPosition
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'QUEUE_MOVE_ERROR', 'message' => $e->getMessage()];
        }
    }

    /**
     * Finish a Car or Handshake to Next Step.
     * Handshake rules:
     * - Washer taps Finish -> Prompt asks "Did this take longer than expected? Yes or No".
     * - For a mixed order: Washer taps "Send to Tech". The car leaves wash bay (bay becomes Free).
     *   The Technical screen shows "Car ABC waiting for Oil Change", and technician taps "Receive from Wash" to start.
     *   The parent order is NOT "Done" until every child task is finished.
     */
    /**
     * Finish a Car or Handshake to Next Step.
     * Explicit, consistently typed signature. Does not silently reinterpret arguments.
     *
     * @param PDO $pdo Active PDO connection
     * @param int|null $bayId Explicit bay ID (null if completing unassigned/waiting order)
     * @param int $orderId Explicit order ID (mandatory)
     * @param int $userId Performing staff user ID
     * @param string $userName Performing staff user name
     * @param string $userRole Performing staff effective role
     * @param int|null $tookLonger Answer to "Did this take longer?" (1=yes, 0=no, null=unanswered)
     * @param string|null $reason Optional reason or notes
     * @return array Result array with success boolean and details/error
     */
    public static function finishCar(
        PDO $pdo,
        ?int $bayId,
        $orderIdOrUserId = null,
        $userIdOrUserName = null,
        $userNameOrRole = null,
        $userRoleOrTookLonger = null,
        $tookLongerOrReason = null,
        ?string $reason = null
    ): array {
        self::ensureTables($pdo);

        // Resolve argument polymorphism (supports 8-arg, 6-arg, and legacy 7-arg calling conventions)
        if (is_string($userIdOrUserName)) {
            // Legacy signature: finishCar($pdo, $identifier, $userId, $userName, $userRole, $tookLonger, $reason)
            $identifier = (int)$bayId;
            $userId = (int)$orderIdOrUserId;
            $userName = (string)$userIdOrUserName;
            $userRole = (string)$userNameOrRole;
            $tookLonger = is_numeric($userRoleOrTookLonger) ? (int)$userRoleOrTookLonger : null;
            $reason = is_string($tookLongerOrReason) ? $tookLongerOrReason : null;

            $bayId = null;
            $orderId = null;
            if ($identifier > 0) {
                $bCheck = $pdo->prepare("SELECT id, current_order_id FROM bays WHERE id = ?");
                $bCheck->execute([$identifier]);
                $bayRow = $bCheck->fetch(PDO::FETCH_ASSOC);
                if ($bayRow && !empty($bayRow['current_order_id'])) {
                    $bayId = (int)$bayRow['id'];
                    $orderId = (int)$bayRow['current_order_id'];
                } else {
                    $oCheck = $pdo->prepare("SELECT id, bay_id FROM orders WHERE id = ?");
                    $oCheck->execute([$identifier]);
                    $ordRow = $oCheck->fetch(PDO::FETCH_ASSOC);
                    if ($ordRow) {
                        $orderId = (int)$ordRow['id'];
                        $bayId = !empty($ordRow['bay_id']) ? (int)$ordRow['bay_id'] : null;
                    } elseif ($bayRow) {
                        $bayId = (int)$bayRow['id'];
                    }
                }
            }
        } else {
            // Canonical signature: finishCar($pdo, $bayId, $orderId, $userId, $userName, $userRole, $tookLonger, $reason)
            $orderId = (int)$orderIdOrUserId;
            $userId = (int)$userIdOrUserName;
            $userName = (string)$userNameOrRole;
            $userRole = (string)$userRoleOrTookLonger;
            $tookLonger = is_numeric($tookLongerOrReason) ? (int)$tookLongerOrReason : null;
        }

        if (!$orderId || $orderId <= 0) {
            return ['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'Valid order_id is required'];
        }

        $isMySql = false;
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $isMySql = ($driver === 'mysql');
        } catch (Throwable $t) {
            $isMySql = false;
        }
        $forUpdate = $isMySql ? ' FOR UPDATE' : '';

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Fetch & lock order inside transaction
            $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?" . $forUpdate);
            $oStmt->execute([$orderId]);
            $order = $oStmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => "Order #{$orderId} not found"];
            }

            // Check if order already finished/paid/cancelled
            if (in_array($order['status'], ['finished', 'paid', 'cancelled'], true)) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'error' => 'ORDER_ALREADY_COMPLETED',
                    'message' => "Order #{$orderId} is already completed (status: {$order['status']})"
                ];
            }

            // 2. Fetch & lock bay if bayId provided or order has bay_id
            $targetBayId = $bayId > 0 ? $bayId : (!empty($order['bay_id']) ? (int)$order['bay_id'] : null);
            $bay = null;

            if ($targetBayId) {
                $bStmt = $pdo->prepare("SELECT * FROM bays WHERE id = ?" . $forUpdate);
                $bStmt->execute([$targetBayId]);
                $bay = $bStmt->fetch(PDO::FETCH_ASSOC);

                if (!$bay) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return ['success' => false, 'error' => 'BAY_NOT_FOUND', 'message' => "Bay #{$targetBayId} not found"];
                }

                // If caller explicitly provided bayId, validate strict assignment to this order
                if ($bayId !== null && $bayId > 0) {
                    if ($bay['status'] === 'free' && empty($bay['current_order_id'])) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        return [
                            'success' => false,
                            'error' => 'BAY_ALREADY_FREE',
                            'message' => "Bay {$bay['bay_name']} is already free"
                        ];
                    }

                    if ((int)($bay['current_order_id'] ?? 0) !== $orderId) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        return [
                            'success' => false,
                            'error' => 'BAY_ORDER_MISMATCH',
                            'message' => "Bay {$bay['bay_name']} is currently occupied by order #{$bay['current_order_id']}, not order #{$orderId}"
                        ];
                    }
                }
            }

            $effectiveBayId = $bay ? (int)$bay['id'] : null;
            $bayName = $bay ? $bay['bay_name'] : 'Bay';
            $now = date('Y-m-d H:i:s');

            // 3. Child tasks lookup
            $tasks = self::ensureOrderTasks($pdo, $orderId);
            $currentTask = null;
            $remainingTasks = [];

            $isMechanicalBay = ($bay && ($bay['bay_type'] === 'oil_change' || ($bay['skill_tag'] ?? '') === 'oil_only'));

            foreach ($tasks as $t) {
                if ($t['status'] === 'in_progress') {
                    if ($isMechanicalBay && ($t['task_type'] === 'wash' || $t['task_type'] === 'qc')) {
                        continue;
                    }
                    $currentTask = $t;
                } elseif ($t['status'] !== 'completed') {
                    if ($isMechanicalBay && ($t['task_type'] === 'wash' || $t['task_type'] === 'qc')) {
                        continue;
                    }
                    $remainingTasks[] = $t;
                }
            }

            // If no in_progress task found, find first uncompleted matching this bay
            if (!$currentTask && !empty($remainingTasks)) {
                $currentTask = array_shift($remainingTasks);
            }

            // If still no currentTask (e.g. TechnicalManager::finishJobOrder already marked oil_change as completed):
            if (!$currentTask && $isMechanicalBay) {
                foreach ($tasks as $t) {
                    if ($t['task_type'] === 'oil_change' || $t['task_type'] === 'repair') {
                        $currentTask = $t;
                        break;
                    }
                }
            }

            // 4. Check next task in sequence to determine if this is a handshake or final completion
            $nextTask = null;
            if ($currentTask) {
                $nextTaskStmt = $pdo->prepare("
                    SELECT * FROM order_tasks 
                    WHERE order_id = ? AND id != ? AND status != 'completed' 
                    ORDER BY sequence ASC, id ASC 
                    LIMIT 1
                ");
                $nextTaskStmt->execute([$orderId, $currentTask['id']]);
                $nextTask = $nextTaskStmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $nextTaskStmt = $pdo->prepare("
                    SELECT * FROM order_tasks 
                    WHERE order_id = ? AND status != 'completed' 
                    ORDER BY sequence ASC, id ASC 
                    LIMIT 1
                ");
                $nextTaskStmt->execute([$orderId]);
                $nextTask = $nextTaskStmt->fetch(PDO::FETCH_ASSOC);
            }

            // 5. MANDATORY GUIDED INSPECTION CHECK BEFORE ANY STATE CHANGE:
            // Mandatory for any mechanical work (oil_change or in Bay 4) or when completing final step
            $isMechanicalWork = ($currentTask && $currentTask['task_type'] === 'oil_change') || $isMechanicalBay;
            if ($isMechanicalWork || (!$nextTask)) {
                $checkInspection = TechnicalManager::validateInspectionChecklist($pdo, $orderId);
                if (!$checkInspection['passed']) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return [
                        'success' => false,
                        'error' => 'MANDATORY_INSPECTION_INCOMPLETE',
                        'message' => $checkInspection['message']
                    ];
                }
            }

            // 6. Save estimate answer: took_longer_than_expected
            $tookLongerVal = 0;
            if ($tookLonger !== null) {
                $tookLongerVal = ($tookLonger === 1) ? 1 : 0;
                $pdo->prepare("UPDATE orders SET took_longer_than_expected = ? WHERE id = ?")->execute([$tookLongerVal, $orderId]);
                if ($currentTask) {
                    $pdo->prepare("UPDATE order_tasks SET took_longer_than_expected = ? WHERE id = ?")->execute([$tookLongerVal, $currentTask['id']]);
                }
            }

            // 7. Mark current task as completed
            if ($currentTask) {
                $pdo->prepare("
                    UPDATE order_tasks 
                    SET status = 'completed', completed_at = ?, updated_at = ? 
                    WHERE id = ?
                ")->execute([$now, $now, $currentTask['id']]);
            }

            // 8. Calculate actual duration & check Learning Engine
            $occupiedTs = !empty($bay['occupied_at']) ? strtotime($bay['occupied_at']) : (!empty($order['started_at']) ? strtotime($order['started_at']) : time());
            $actualMins = max(1, (int)round((time() - $occupiedTs) / 60));

            $estMins = (int)($order['estimated_duration_minutes'] ?? 0);
            if ($estMins <= 0) {
                require_once __DIR__ . '/SmartQueueEngine.php';
                $estData = SmartQueueEngine::calculateEstimatedDuration($pdo, [], $order['vehicle_type'] ?? 'small_car', $userId);
                $estMins = $estData['total_estimated_minutes'];
            }

            $pdo->prepare("UPDATE orders SET actual_duration_minutes = ? WHERE id = ?")->execute([$actualMins, $orderId]);
            if ($currentTask) {
                $pdo->prepare("UPDATE order_tasks SET actual_duration_minutes = ? WHERE id = ?")->execute([$actualMins, $currentTask['id']]);
            }

            require_once __DIR__ . '/SmartQueueEngine.php';
            SmartQueueEngine::checkAndFlagForReview(
                $pdo,
                $orderId,
                $currentTask ? (int)$currentTask['id'] : null,
                $estMins,
                $actualMins,
                $tookLongerVal
            );

            // 9. Free the bay using atomic conditional update
            if ($bay) {
                $freeBayStmt = $pdo->prepare("
                    UPDATE bays 
                    SET status = 'free', current_order_id = NULL, occupied_at = NULL, last_freed_at = ?, updated_at = ? 
                    WHERE id = ? AND current_order_id = ?
                ");
                $freeBayStmt->execute([$now, $now, $bay['id'], $orderId]);
                if ($freeBayStmt->rowCount() === 0) {
                    // Check if bay was already free or assigned elsewhere
                    $recheckBay = $pdo->query("SELECT id, current_order_id, status FROM bays WHERE id = " . (int)$bay['id'])->fetch(PDO::FETCH_ASSOC);
                    if ($recheckBay && (int)($recheckBay['current_order_id'] ?? 0) !== $orderId) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        return [
                            'success' => false,
                            'error' => 'CONCURRENT_MODIFICATION',
                            'message' => "Bay {$bayName} was concurrently modified by another transaction. Completion aborted."
                        ];
                    }
                }
            } else {
                // Free any bay associated with this order
                $pdo->prepare("
                    UPDATE bays 
                    SET status = 'free', current_order_id = NULL, occupied_at = NULL, last_freed_at = ?, updated_at = ? 
                    WHERE current_order_id = ?
                ")->execute([$now, $now, $orderId]);
            }

            if ($nextTask) {
                if ($nextTask['task_type'] === 'qc') {
                    // Wash finished -> Vehicle moves to Finishing Pad for QC inspection
                    $pdo->prepare("
                        UPDATE order_tasks 
                        SET status = 'waiting', updated_at = ? 
                        WHERE id = ?
                    ")->execute([$now, $nextTask['id']]);

                    // Order moves to Finishing Pad: bay freed, status remains 'waiting', ensure arrived_at
                    $pdo->prepare("
                        UPDATE orders 
                        SET bay_id = NULL, status = 'waiting', arrived_at = COALESCE(arrived_at, created_at, ?) 
                        WHERE id = ?
                    ")->execute([$now, $orderId]);

                    // Ensure 5-point QC checklist items are seeded in inspection_checklists
                    self::ensureQcChecklist($pdo, $orderId);

                    $currentTaskTitle = $currentTask['title'] ?? 'Car Wash Service';
                    $details = "Staff {$userName} finished {$currentTaskTitle}. Handshake: Vehicle {$order['plate_number']} sent to Finishing Pad for Quality Control (QC). Bay {$bayName} is Free.";
                    $pdo->prepare("
                        INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                        VALUES (?, ?, ?, ?, 'sent_to_finishing_pad', ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $orderId, $effectiveBayId, $bayName, $order['plate_number'],
                        $userId, $userName, $userRole, $reason, $details, $now
                    ]);

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    // Post-commit notification to supervisor
                    try {
                        NotificationManager::notify($pdo, [
                            'target_role' => 'wash_supervisor',
                            'category' => 'qc',
                            'reference_id' => 'QC-PENDING-' . $orderId,
                            'title_en' => 'Car on Finishing Pad for QC',
                            'title_km' => 'រថយន្តនៅលើទីលាន Finishing Pad រង់ចាំ QC',
                            'message_en' => "Car Wash finished for {$order['plate_number']}. Vehicle is on the Finishing Pad awaiting QC sign-off.",
                            'message_km' => "ការលាងសម្អាតបានបញ្ចប់សម្រាប់ {$order['plate_number']}។ រថយន្តនៅលើ Finishing Pad រង់ចាំការត្រួតពិនិត្យ QC។",
                            'action_url' => 'index.php?view=bays_queue',
                            'sound_type' => 'chime'
                        ]);
                    } catch (Throwable $ne) {
                        error_log("Supervisor QC notification failed for order {$orderId}: " . $ne->getMessage());
                    }

                    return [
                        'success' => true,
                        'is_handshake' => true,
                        'to_qc' => true,
                        'message' => "Car Wash finished and vehicle sent to Finishing Pad for QC inspection. {$bayName} is now Free.",
                        'order_id' => $orderId,
                        'bay_id' => $effectiveBayId,
                        'bay_name' => $bayName,
                        'next_task' => $nextTask['title'],
                        'plate_number' => $order['plate_number']
                    ];
                }

                if ($nextTask['task_type'] === 'oil_change') {
                    // Wash First Handshake (Step 1 Wash finished -> unblock Step 2 Oil Change in Bay 4):
                    $pdo->prepare("
                        UPDATE order_tasks 
                        SET status = 'waiting', updated_at = ? 
                        WHERE id = ?
                    ")->execute([$now, $nextTask['id']]);

                    // Also unblock Lane 2 multi-point inspection task if present
                    $pdo->prepare("
                        UPDATE order_tasks 
                        SET status = 'waiting', updated_at = ? 
                        WHERE order_id = ? AND task_type = 'inspection'
                    ")->execute([$now, $orderId]);

                    // Order moves to Technical Queue for Bay 4:
                    $pdo->prepare("
                        UPDATE orders 
                        SET bay_id = NULL, status = 'waiting', repair_status = 'waiting_tech' 
                        WHERE id = ?
                    ")->execute([$orderId]);

                    $currentTaskTitle = $currentTask['title'] ?? 'Car Wash Service (Step 1)';
                    $details = "Staff {$userName} finished {$currentTaskTitle}. Handshake: Car {$order['plate_number']} sent to Technical Queue for Step {$nextTask['sequence']} (Oil Change in Bay 4). Bay {$bayName} is Free.";
                    $pdo->prepare("
                        INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                        VALUES (?, ?, ?, ?, 'sent_to_tech_queue', ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $orderId, $effectiveBayId, $bayName, $order['plate_number'],
                        $userId, $userName, $userRole, $reason, $details, $now
                    ]);

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    // Post-commit non-fatal notification dispatch to technicians
                    try {
                        NotificationManager::notify($pdo, [
                            'target_role' => 'technical',
                            'category' => 'handshake',
                            'reference_id' => 'HANDSHAKE-TECH-' . $orderId,
                            'title_en' => 'Car Ready for Oil Change (Wash Finished)',
                            'title_km' => 'រថយន្តរួចរាល់សម្រាប់ការប្តូរប្រេង (លាងរួច)',
                            'message_en' => "Wash finished for {$order['plate_number']}. Car has joined the Technical Queue for Bay 4.",
                            'message_km' => "ការលាងសម្អាតបានបញ្ចប់សម្រាប់ {$order['plate_number']}។ រថយន្តបានចូលក្នុងជួររង់ចាំប្តូរប្រេងនៅបន្ទប់ទី 4។",
                            'action_url' => 'index.php?view=mechanic_jobs',
                            'sound_type' => 'chime'
                        ]);
                    } catch (Throwable $ne) {
                        error_log("Tech notification failed on handover for order {$orderId}: " . $ne->getMessage());
                    }

                    return [
                        'success' => true,
                        'is_handshake' => true,
                        'to_tech' => true,
                        'message' => "Step 1 (Wash) finished and car sent to Technical Queue for Bay 4. {$bayName} is now Free.",
                        'order_id' => $orderId,
                        'bay_id' => $effectiveBayId,
                        'bay_name' => $bayName,
                        'next_task' => $nextTask['title'],
                        'plate_number' => $order['plate_number']
                    ];
                }

                // Lift First — Wash Second Handshake (Step 1 mechanical finished -> unblock Step 2 wash):
                $pdo->prepare("
                    UPDATE order_tasks 
                    SET status = 'waiting', updated_at = ? 
                    WHERE id = ?
                ")->execute([$now, $nextTask['id']]);

                // Order moves to Wash Queue:
                $pdo->prepare("
                    UPDATE orders 
                    SET bay_id = NULL, status = 'waiting', repair_status = 'finished' 
                    WHERE id = ?
                ")->execute([$orderId]);

                $currentTaskTitle = $currentTask['title'] ?? 'Mechanical Service (Step 1)';
                $details = "Staff {$userName} finished {$currentTaskTitle}. Handshake: Car {$order['plate_number']} sent to Wash Queue for Step {$nextTask['sequence']} ({$nextTask['title']}). Bay {$bayName} is Free.";
                $pdo->prepare("
                    INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                    VALUES (?, ?, ?, ?, 'sent_to_wash_queue', ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $orderId, $effectiveBayId, $bayName, $order['plate_number'],
                    $userId, $userName, $userRole, $reason, $details, $now
                ]);

                // Commit before dispatching notifications
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                // Post-commit non-fatal notification dispatch
                try {
                    NotificationManager::notify($pdo, [
                        'target_role' => 'washer',
                        'category' => 'handshake',
                        'reference_id' => 'HANDSHAKE-WASH-' . $orderId,
                        'title_en' => 'Car Ready for Wash Queue',
                        'title_km' => 'រថយន្តរួចរាល់សម្រាប់ការលាងសម្អាត',
                        'message_en' => "Mechanical service finished for {$order['plate_number']}. Car has joined the Wash Queue.",
                        'message_km' => "សេវាកម្មមេកានិកបានបញ្ចប់សម្រាប់ {$order['plate_number']}។ រថយន្តបានចូលក្នុងជួររង់ចាំលាងសម្អាត។",
                        'action_url' => 'index.php?view=washer_jobs',
                        'sound_type' => 'chime'
                    ]);
                } catch (Throwable $ne) {
                    error_log("Washer notification failed on handover for order {$orderId}: " . $ne->getMessage());
                }

                return [
                    'success' => true,
                    'is_handshake' => true,
                    'message' => "Step 1 finished and car sent to Wash Queue. {$bayName} is now Free.",
                    'order_id' => $orderId,
                    'bay_id' => $effectiveBayId,
                    'bay_name' => $bayName,
                    'next_task' => $nextTask['title'],
                    'plate_number' => $order['plate_number']
                ];
            }

            // FINAL STEP: All tasks completed -> Car is Done ("Ready for pickup")
            $pdo->prepare("
                UPDATE orders 
                SET status = 'finished',
                    repair_status = CASE WHEN repair_status IS NOT NULL THEN 'finished' ELSE repair_status END,
                    finished_at = COALESCE(finished_at, ?),
                    bay_id = NULL
                WHERE id = ?
            ")->execute([$now, $orderId]);

            $details = "Car {$order['plate_number']} fully finished by {$userName} ({$userRole}). {$bayName} is now Free and order is Ready for pickup.";
            $pdo->prepare("
                INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                VALUES (?, ?, ?, ?, 'finished', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $effectiveBayId, $bayName, $order['plate_number'],
                $userId, $userName, $userRole, $reason, $details, $now
            ]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'is_handshake' => false,
                'message' => "Car {$order['plate_number']} finished! Bay is now Free and order is Ready for pickup.",
                'order_id' => $orderId,
                'bay_id' => $effectiveBayId,
                'bay_name' => $bayName,
                'plate_number' => $order['plate_number'],
                'order_status' => 'finished'
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'FINISH_ERROR', 'message' => $e->getMessage()];
        }
    }

    /**
     * Legacy wrapper for finishCar() accepting an ambiguous integer identifier (bay_id or order_id).
     * Strictly resolves the identifier to explicit bay_id and order_id, then delegates to finishCar().
     */
    public static function finishCarLegacy(
        PDO $pdo,
        int $identifier,
        int $userId,
        string $userName,
        string $userRole,
        ?int $tookLonger = null,
        ?string $reason = null
    ): array {
        self::ensureTables($pdo);

        $bayId = null;
        $orderId = null;

        if ($identifier > 0) {
            $bCheck = $pdo->prepare("SELECT id, current_order_id FROM bays WHERE id = ?");
            $bCheck->execute([$identifier]);
            $bayRow = $bCheck->fetch(PDO::FETCH_ASSOC);
            if ($bayRow && !empty($bayRow['current_order_id'])) {
                $bayId = (int)$bayRow['id'];
                $orderId = (int)$bayRow['current_order_id'];
            } else {
                $oCheck = $pdo->prepare("SELECT id, bay_id FROM orders WHERE id = ?");
                $oCheck->execute([$identifier]);
                $ordRow = $oCheck->fetch(PDO::FETCH_ASSOC);
                if ($ordRow) {
                    $orderId = (int)$ordRow['id'];
                    $bayId = !empty($ordRow['bay_id']) ? (int)$ordRow['bay_id'] : null;
                } elseif ($bayRow) {
                    $bayId = (int)$bayRow['id'];
                }
            }
        }

        if (!$orderId || $orderId <= 0) {
            return ['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'Valid order_id is required'];
        }

        return self::finishCar($pdo, $bayId, $orderId, $userId, $userName, $userRole, $tookLonger, $reason);
    }

    /**
     * Ensure the 5 standard Finishing Pad QC checklist items exist for an order.
     */
    public static function ensureQcChecklist(PDO $pdo, int $orderId): void {
        self::ensureTables($pdo);

        $chkCount = (int)$pdo->query("SELECT COUNT(*) FROM inspection_checklists WHERE order_id = {$orderId} AND item_key IN ('rims_tires', 'windshield', 'carpets_mats', 'door_sills', 'final_appearance')")->fetchColumn();
        if ($chkCount > 0) {
            return;
        }

        $items = [
            ['key' => 'rims_tires', 'name' => 'Rims and tires cleaned and dressed', 'notes' => 'Tires dressed and rim brake dust removed'],
            ['key' => 'windshield', 'name' => 'Windshield and glass streak-free', 'notes' => 'Exterior and interior glass streak-free'],
            ['key' => 'carpets_mats', 'name' => 'Carpets and floor mats checked', 'notes' => 'Mats vacuumed and properly positioned'],
            ['key' => 'door_sills', 'name' => 'Door sills and jambs wiped dry', 'notes' => 'All door frames, edges and sills dry'],
            ['key' => 'final_appearance', 'name' => 'Final vehicle appearance checked', 'notes' => 'No leftover water drips or towel lint']
        ];

        $ins = $pdo->prepare("
            INSERT INTO inspection_checklists (order_id, item_key, item_name, status, is_mandatory, notes, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        foreach ($items as $item) {
            $ins->execute([$orderId, $item['key'], $item['name'], $item['notes']]);
        }
    }

    /**
     * Supervisor QC Sign-off: Pass Quality Control on Finishing Pad.
     * Transitions order to status='finished', records all 5 checklist items as passed,
     * and audits action via AuditLogger.
     */
    public static function passQc(
        PDO $pdo,
        int $orderId,
        int $userId,
        string $userName,
        string $userRole,
        array $checklist = [],
        ?string $notes = null
    ): array {
        self::ensureTables($pdo);

        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'Valid order_id is required.'];
        }

        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => "Order #{$orderId} not found."];
        }

        if (in_array($order['status'], ['finished', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'ORDER_ALREADY_FINISHED', 'message' => "Order #{$orderId} is already {$order['status']}."];
        }

        if (($order['payment_status'] ?? '') !== 'paid') {
            return ['success' => false, 'error' => 'PAYMENT_REQUIRED', 'message' => 'Order payment must be confirmed before passing QC.'];
        }

        self::ensureQcChecklist($pdo, $orderId);

        $now = date('Y-m-d H:i:s');

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        try {
            // Update checklist items to pass
            $updChecklist = $pdo->prepare("
                UPDATE inspection_checklists 
                SET status = 'pass', checked_by_id = ?, checked_by_name = ?, checked_at = ?, updated_at = ?
                WHERE order_id = ? AND item_key IN ('rims_tires', 'windshield', 'carpets_mats', 'door_sills', 'final_appearance')
            ");
            $updChecklist->execute([$userId, $userName, $now, $now, $orderId]);

            // Complete the QC task in order_tasks
            $pdo->prepare("
                UPDATE order_tasks 
                SET status = 'completed', completed_at = ?, updated_at = ?
                WHERE order_id = ? AND task_type = 'qc'
            ")->execute([$now, $now, $orderId]);

            // Complete order: Ready for Pickup
            $pdo->prepare("
                UPDATE orders 
                SET status = 'finished', finished_at = COALESCE(finished_at, ?), bay_id = NULL
                WHERE id = ?
            ")->execute([$now, $orderId]);

            // Strict audit logging
            if (!class_exists('AuditLogger')) {
                require_once __DIR__ . '/AuditLogger.php';
            }
            AuditLogger::logStrict(
                $pdo,
                'QC_PASS',
                "Supervisor {$userName} ({$userRole}) passed 5-point Finishing Pad QC for vehicle {$order['plate_number']} (#{$order['order_number']}). Order is now Ready for Pickup.",
                'orders',
                (string)$orderId,
                $userId,
                $userName,
                $userRole
            );

            // Bay history
            $pdo->prepare("
                INSERT INTO bay_history (order_id, plate_number, action, performed_by, performed_by_name, performed_by_role, details, created_at)
                VALUES (?, ?, 'qc_pass', ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $order['plate_number'], $userId, $userName, $userRole,
                "Supervisor {$userName} approved Finishing Pad QC. Vehicle is Ready for Pickup.", $now
            ]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            // Post-commit notification
            try {
                if (class_exists('NotificationManager')) {
                    NotificationManager::notify($pdo, [
                        'target_role' => 'cashier',
                        'category' => 'qc',
                        'reference_id' => 'QC-PASS-' . $orderId,
                        'title_en' => 'Vehicle Passed QC - Ready for Pickup',
                        'title_km' => 'រថយន្តបានឆ្លងកាត់ QC - រួចរាល់សម្រាប់ទទួល',
                        'message_en' => "Vehicle {$order['plate_number']} passed quality inspection and is ready for customer pickup.",
                        'message_km' => "រថយន្ត {$order['plate_number']} បានឆ្លងកាត់ការត្រួតពិនិត្យគុណភាព និងរួចរាល់សម្រាប់អតិថិជនមកទទួល។",
                        'action_url' => 'index.php?view=bays_queue',
                        'sound_type' => 'success'
                    ]);
                }
            } catch (Throwable $t) {
                error_log("QC pass notification error: " . $t->getMessage());
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'plate_number' => $order['plate_number'],
                'status' => 'finished',
                'message' => "QC passed for vehicle {$order['plate_number']}. Car is Ready for Pickup."
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'QC_PASS_FAILED', 'message' => $e->getMessage()];
        }
    }

    /**
     * Supervisor QC Rejection: Failed Quality Control on Finishing Pad.
     * Records failure reason in AuditLogger, resets wash task to 'waiting',
     * and returns vehicle to the Wash Queue with promoted priority (P1) for rework.
     */
    public static function failQc(
        PDO $pdo,
        int $orderId,
        string $reason,
        int $userId,
        string $userName,
        string $userRole
    ): array {
        self::ensureTables($pdo);

        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'Valid order_id is required.'];
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            return ['success' => false, 'error' => 'REASON_REQUIRED', 'message' => 'Failure reason is required when failing QC.'];
        }

        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => "Order #{$orderId} not found."];
        }

        if (in_array($order['status'], ['finished', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'ORDER_ALREADY_FINISHED', 'message' => "Cannot fail QC: Order #{$orderId} is already {$order['status']}."];
        }

        $now = date('Y-m-d H:i:s');

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        try {
            // Reset wash task to waiting so vehicle can re-enter wash bay
            $pdo->prepare("
                UPDATE order_tasks 
                SET status = 'waiting', updated_at = ?
                WHERE order_id = ? AND task_type = 'wash'
            ")->execute([$now, $orderId]);

            // Reset QC task to pending_prereq until rework wash finishes
            $pdo->prepare("
                UPDATE order_tasks 
                SET status = 'pending_prereq', updated_at = ?
                WHERE order_id = ? AND task_type = 'qc'
            ")->execute([$now, $orderId]);

            // Reset inspection checklist status to pending with fail notes
            $pdo->prepare("
                UPDATE inspection_checklists 
                SET status = 'pending', notes = ?, checked_by_id = ?, checked_by_name = ?, checked_at = ?, updated_at = ?
                WHERE order_id = ? AND item_key IN ('rims_tires', 'windshield', 'carpets_mats', 'door_sills', 'final_appearance')
            ")->execute([$trimmedReason, $userId, $userName, $now, $now, $orderId]);

            // Promote order priority for rework in wash queue (priority 1 = highest tier)
            $pdo->prepare("
                UPDATE orders 
                SET status = 'waiting', queue_priority = 1, bay_id = NULL
                WHERE id = ?
            ")->execute([$orderId]);

            // Strict audit logging
            if (!class_exists('AuditLogger')) {
                require_once __DIR__ . '/AuditLogger.php';
            }
            AuditLogger::logStrict(
                $pdo,
                'QC_FAIL',
                "Supervisor {$userName} ({$userRole}) failed QC for vehicle {$order['plate_number']} (#{$order['order_number']}). Reason: {$trimmedReason}. Vehicle returned to wash queue for priority rework.",
                'orders',
                (string)$orderId,
                $userId,
                $userName,
                $userRole
            );

            // Bay history
            $pdo->prepare("
                INSERT INTO bay_history (order_id, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                VALUES (?, ?, 'qc_fail', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $order['plate_number'], $userId, $userName, $userRole,
                $trimmedReason, "QC failed by {$userName}. Reason: {$trimmedReason}. Returned to Wash Queue for priority rework.", $now
            ]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            // Post-commit notification
            try {
                if (class_exists('NotificationManager')) {
                    NotificationManager::notify($pdo, [
                        'target_role' => 'washer',
                        'category' => 'qc',
                        'reference_id' => 'QC-FAIL-' . $orderId,
                        'title_en' => 'QC Failed - Rework Required',
                        'title_km' => 'QC មិនឆ្លងកាត់ - ត្រូវការលាងសម្អាតឡើងវិញ',
                        'message_en' => "Vehicle {$order['plate_number']} failed QC: {$trimmedReason}. Vehicle returned to Wash Queue for priority rework.",
                        'message_km' => "រថយន្ត {$order['plate_number']} មិនឆ្លងកាត់ QC៖ {$trimmedReason}។ រថយន្តបានត្រឡប់ទៅជួររង់ចាំលាងសម្អាតជាអាទិភាព។",
                        'action_url' => 'index.php?view=washer_jobs',
                        'sound_type' => 'alert'
                    ]);
                }
            } catch (Throwable $t) {
                error_log("QC fail notification error: " . $t->getMessage());
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'plate_number' => $order['plate_number'],
                'status' => 'waiting',
                'queue_priority' => 1,
                'message' => "QC failed for vehicle {$order['plate_number']}. Vehicle returned to wash queue for priority rework."
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'QC_FAIL_FAILED', 'message' => $e->getMessage()];
        }
    }

    /**
     * Customer Handover Verification: Prevents premature vehicle handover before QC approval.
     */
    public static function handoverToCustomer(
        PDO $pdo,
        int $orderId,
        int $userId,
        string $userName,
        string $userRole
    ): array {
        self::ensureTables($pdo);

        if ($orderId <= 0) {
            return ['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'Valid order_id is required.'];
        }

        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => "Order #{$orderId} not found."];
        }

        // 1. Mandatory Payment Check
        if (($order['payment_status'] ?? '') !== 'paid') {
            return ['success' => false, 'error' => 'PAYMENT_REQUIRED', 'message' => "Cannot hand over vehicle {$order['plate_number']}: Upfront payment is mandatory."];
        }

        // 2. Check operational status & incomplete tasks (including QC)
        $incompleteTasks = $pdo->prepare("SELECT COUNT(*) FROM order_tasks WHERE order_id = ? AND status != 'completed'");
        $incompleteTasks->execute([$orderId]);
        $hasIncomplete = ((int)$incompleteTasks->fetchColumn()) > 0;

        if ($order['status'] !== 'finished' || $hasIncomplete) {
            return [
                'success' => false,
                'error' => 'PREMATURE_HANDOVER',
                'message' => "Cannot hand over vehicle {$order['plate_number']}: Quality Control (QC) inspection has not passed."
            ];
        }

        $now = date('Y-m-d H:i:s');

        // Audit log
        if (!class_exists('AuditLogger')) {
            require_once __DIR__ . '/AuditLogger.php';
        }
        AuditLogger::logStrict(
            $pdo,
            'CUSTOMER_HANDOVER',
            "Vehicle {$order['plate_number']} (#{$order['order_number']}) handed over to customer by {$userName} ({$userRole}).",
            'orders',
            (string)$orderId,
            $userId,
            $userName,
            $userRole
        );

        $pdo->prepare("
            INSERT INTO bay_history (order_id, plate_number, action, performed_by, performed_by_name, performed_by_role, details, created_at)
            VALUES (?, ?, 'customer_handover', ?, ?, ?, ?, ?)
        ")->execute([
            $orderId, $order['plate_number'], $userId, $userName, $userRole,
            "Vehicle handed over to customer by {$userName} ({$userRole}).", $now
        ]);

        return [
            'success' => true,
            'order_id' => $orderId,
            'plate_number' => $order['plate_number'],
            'message' => "Vehicle {$order['plate_number']} handed over to customer successfully."
        ];
    }

    /**
     * Switch workflow sequence between 'lift_first' and 'wash_first'.
     * Only permitted if vehicle has not entered a bay yet and no tasks are completed or in progress.
     */
    public static function switchServiceSequence(
        PDO $pdo,
        int $orderId,
        string $targetSequence,
        int $userId,
        string $userName,
        string $userRole
    ): array {
        self::ensureTables($pdo);

        if (!in_array($targetSequence, ['lift_first', 'wash_first'], true)) {
            return ['success' => false, 'error' => 'INVALID_SEQUENCE', 'message' => "Sequence must be 'lift_first' or 'wash_first'."];
        }

        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => "Order #{$orderId} not found."];
        }

        if (!empty($order['bay_id'])) {
            return ['success' => false, 'error' => 'ALREADY_IN_BAY', 'message' => "Cannot switch workflow: Vehicle is currently inside a bay."];
        }

        if (in_array($order['status'], ['finished', 'cancelled'], true)) {
            return ['success' => false, 'error' => 'ORDER_COMPLETED', 'message' => "Cannot switch workflow: Order is {$order['status']}."];
        }

        $tasks = self::ensureOrderTasks($pdo, $orderId);
        foreach ($tasks as $t) {
            if ($t['status'] === 'completed' || $t['status'] === 'in_progress') {
                return ['success' => false, 'error' => 'SERVICE_ALREADY_STARTED', 'message' => "Cannot switch workflow: '{$t['title']}' is already {$t['status']}."];
            }
        }

        $now = date('Y-m-d H:i:s');
        if ($targetSequence === 'wash_first') {
            // Wash first: wash is step 1, oil_change is step 2
            $pdo->prepare("UPDATE order_tasks SET sequence = 1, status = 'waiting', updated_at = ? WHERE order_id = ? AND task_type = 'wash'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE order_tasks SET sequence = 2, status = 'pending_prereq', updated_at = ? WHERE order_id = ? AND task_type = 'oil_change'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE order_tasks SET sequence = 2, status = 'pending_prereq', updated_at = ? WHERE order_id = ? AND task_type = 'inspection'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE order_tasks SET sequence = 3, status = 'pending_prereq', updated_at = ? WHERE order_id = ? AND task_type = 'qc'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE orders SET service_sequence = 'wash_first', repair_status = 'waiting_wash_first', updated_at = ? WHERE id = ?")->execute([$now, $orderId]);

            $details = "Supervisor {$userName} switched vehicle {$order['plate_number']} to 'Wash First' workflow (Bay 4 Busy bypass).";
            $pdo->prepare("INSERT INTO bay_history (order_id, plate_number, action, performed_by, performed_by_name, performed_by_role, details, created_at) VALUES (?, ?, 'switched_to_wash_first', ?, ?, ?, ?, ?)")->execute([$orderId, $order['plate_number'], $userId, $userName, $userRole, $details, $now]);
        } else {
            // Lift first: oil_change is step 1, wash is step 2
            $pdo->prepare("UPDATE order_tasks SET sequence = 1, status = 'waiting', updated_at = ? WHERE order_id = ? AND task_type = 'oil_change'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE order_tasks SET sequence = 2, status = 'pending_prereq', updated_at = ? WHERE order_id = ? AND task_type = 'wash'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE order_tasks SET sequence = 2, status = 'waiting', updated_at = ? WHERE order_id = ? AND task_type = 'inspection'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE order_tasks SET sequence = 3, status = 'pending_prereq', updated_at = ? WHERE order_id = ? AND task_type = 'qc'")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE orders SET service_sequence = 'lift_first', repair_status = 'waiting_tech', updated_at = ? WHERE id = ?")->execute([$now, $orderId]);

            $details = "Supervisor {$userName} switched vehicle {$order['plate_number']} to standard 'Lift First' workflow.";
            $pdo->prepare("INSERT INTO bay_history (order_id, plate_number, action, performed_by, performed_by_name, performed_by_role, details, created_at) VALUES (?, ?, 'switched_to_lift_first', ?, ?, ?, ?, ?)")->execute([$orderId, $order['plate_number'], $userId, $userName, $userRole, $details, $now]);
        }

        if (class_exists('AuditLogger')) {
            AuditLogger::log('WORKFLOW_SEQUENCE_SWITCHED', "Switched Order #{$orderId} ({$order['plate_number']}) to {$targetSequence}", 'order', (string)$orderId, $userId, $userName, $userRole);
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'service_sequence' => $targetSequence,
            'message' => "Order #{$order['order_number']} switched to " . ($targetSequence === 'wash_first' ? 'Wash First' : 'Lift First') . " successfully."
        ];
    }

    /**
     * Obsolete workflow handler: Neutralized to reject wash-first transitions.
     * The approved business sequence is Lift First in Bay 4 followed by Wash Second.
     */
    public static function receiveFromWash(PDO $pdo, int $orderId, int $bayId, int $userId, string $userName, string $userRole): array {
        return [
            'success' => false,
            'error' => 'OBSOLETE_WORKFLOW',
            'message' => 'Obsolete Action: The approved sequence is Lift First in Bay 4 followed by Wash Second. Vehicle must enter Bay 4 first.'
        ];
    }

    /**
     * Move a car up in queue (Backward compatible method).
     */
    public static function moveQueueCar(PDO $pdo, int $orderId, string $reason, int $userId, string $userName, string $userRole): array {
        return self::changeQueuePosition($pdo, $orderId, 1, $reason, $userId, $userName, $userRole);
    }

    /**
     * Rename or update bay skill tag / switch on/off.
     * Rule: Owner only.
     */
    public static function updateBay(PDO $pdo, int $bayId, string $newName, string $skillTag, bool $isActive, int $userId, string $userName, string $userRole): array {
        self::ensureTables($pdo);

        if (strtolower($userRole) !== 'owner') {
            return ['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Only the owner can rename or modify bay settings'];
        }

        $bStmt = $pdo->prepare("SELECT * FROM bays WHERE id = ?");
        $bStmt->execute([$bayId]);
        $bay = $bStmt->fetch();

        if (!$bay) {
            return ['success' => false, 'error' => 'BAY_NOT_FOUND', 'message' => 'Bay not found'];
        }

        $cleanName = trim($newName);
        if ($cleanName === '') {
            return ['success' => false, 'error' => 'NAME_REQUIRED', 'message' => 'Bay name cannot be empty'];
        }

        $validSkillTags = ['wash_only', 'oil_only', 'wash_and_oil', 'detailing'];
        $cleanSkill = in_array($skillTag, $validSkillTags, true) ? $skillTag : 'wash_only';

        $activeInt = $isActive ? 1 : 0;
        $now = date('Y-m-d H:i:s');

        $pdo->prepare("UPDATE bays SET bay_name = ?, skill_tag = ?, is_active = ?, updated_at = ? WHERE id = ?")
            ->execute([$cleanName, $cleanSkill, $activeInt, $now, $bayId]);

        // Audit log in bay_history
        $details = "Owner updated bay #{$bay['bay_number']}: Renamed from '{$bay['bay_name']}' to '{$cleanName}', skill tag '{$cleanSkill}', status " . ($isActive ? 'ACTIVE' : 'SWITCHED OFF');
        $pdo->prepare("
            INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, details, created_at)
            VALUES (0, ?, ?, '-', 'bay_updated', ?, ?, ?, ?, ?)
        ")->execute([
            $bayId, $cleanName, $userId, $userName, $userRole, $details, $now
        ]);

        return [
            'success' => true,
            'message' => "Bay updated: {$cleanName} [{$cleanSkill}] (" . ($isActive ? 'Active' : 'Off') . ")",
            'bay_id' => $bayId,
            'bay_name' => $cleanName,
            'skill_tag' => $cleanSkill,
            'is_active' => $activeInt
        ];
    }

    /**
     * Fetch recent bay and queue audit history.
     */
    public static function getHistory(PDO $pdo, int $limit = 50): array {
        self::ensureTables($pdo);

        $limit = max(1, min(200, $limit));
        $stmt = $pdo->prepare("
            SELECT * FROM bay_history
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Server-side Staff Authentication & Real Role Lookup.
     * NEVER trust a role sent by the POS app client!
     */
    public static function getRealStaffUser(PDO $pdo, int $staffId): ?array {
        if ($staffId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.full_name, u.role, u.role_id, u.status,
                   r.code as role_code, r.name_en as role_name_en, r.name_km as role_name_km
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ?
        ");
        $stmt->execute([$staffId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['status'] !== 'active') {
            return null;
        }

        // Determine effective real role code
        $realRole = $user['role_code'] ?: $user['role'];
        $user['effective_role'] = strtolower($realRole);

        return $user;
    }

    /**
     * Helper to mask middle of excessively long plates for privacy/fit.
     */
    public static function formatTvPlate(string $plate): string {
        $clean = trim($plate);
        $len = mb_strlen($clean);
        if ($len > 10) {
            return mb_substr($clean, 0, 4) . '•••' . mb_substr($clean, -4);
        }
        return $clean;
    }

    /**
     * Get read-only public data for Customer TV Queue Display.
     * Strictly privacy-safe: NEVER returns names, phones, prices, or loyalty points!
     */
    public static function getTvDisplayData(PDO $pdo): array {
        self::ensureTables($pdo);

        $lang = get_setting('tv_display_language', 'both'); // 'en', 'km', 'both'

        // 1. Bays
        $bays = self::getAllBays($pdo);
        $tvBays = [];
        foreach ($bays as $b) {
            $isActive = ($b['is_active'] === 1);
            $isBusy = ($b['status'] === 'busy' && !empty($b['current_car']));
            $isOil = ($b['bay_type'] === 'oil_change');
            $skillTag = $b['skill_tag'] ?? 'wash_only';

            $currentCar = $b['current_car'] ?? null;
            $orderStatus = $currentCar['status'] ?? '';
            $repairStatus = $currentCar['repair_status'] ?? '';

            // Status badges required: In Progress, Waiting, Detailing, Ready for Pickup, Under Mechanical Service
            if (!$isActive) {
                $badgeKey = 'inactive';
                $badgeEn = 'Inactive';
                $badgeKm = 'បានបិទ';
            } elseif ($isBusy) {
                if ($orderStatus === 'finished') {
                    $badgeKey = 'ready';
                    $badgeEn = 'Ready for Pickup';
                    $badgeKm = 'រួចរាល់សម្រាប់ទទួល';
                } elseif ($isOil || $skillTag === 'oil_only' || $repairStatus === 'working') {
                    $badgeKey = 'mechanical_service';
                    $badgeEn = 'Under Mechanical Service';
                    $badgeKm = 'កំពុងជួសជុល / ប្តូរប្រេង';
                } elseif ($skillTag === 'detailing' || str_contains(strtolower($currentCar['service_names'] ?? ''), 'detail')) {
                    $badgeKey = 'detailing';
                    $badgeEn = 'Detailing';
                    $badgeKm = 'សម្អាតលម្អិត';
                } else {
                    $badgeKey = 'in_progress';
                    $badgeEn = 'In Progress';
                    $badgeKm = 'កំពុងដំណើរការ';
                }
            } else {
                $badgeKey = 'waiting';
                $badgeEn = 'Waiting';
                $badgeKm = 'កំពុងរង់ចាំ';
            }

            $statusText = match($lang) {
                'km' => $badgeKm,
                'en' => $badgeEn,
                default => "{$badgeEn} / {$badgeKm}"
            };

            $plate = ($isBusy && !empty($b['current_car']['plate_number']))
                ? self::formatTvPlate($b['current_car']['plate_number'])
                : null;

            $remMinutes = 0;
            $isDelayed = false;
            $delayReason = null;
            if ($isBusy) {
                require_once __DIR__ . '/SmartQueueEngine.php';
                $remData = SmartQueueEngine::getBayRemainingMinutes($pdo, $b);
                $remMinutes = (int)($remData['buffered_remaining_minutes'] ?? 0);
                $isDelayed = !empty($remData['is_overdue']);
                $delayReason = $remData['delay_reason'] ?? null;
            }

            $tvBays[] = [
                'bay_number' => (int)$b['bay_number'],
                'bay_name' => $b['bay_name'],
                'bay_type' => $b['bay_type'],
                'skill_tag' => $skillTag,
                'is_active' => $isActive,
                'is_busy' => $isBusy,
                'status' => $b['status'],
                'badge_key' => $badgeKey,
                'badge_en' => $badgeEn,
                'badge_km' => $badgeKm,
                'status_text' => $statusText,
                'plate_number' => $plate,
                'duration_minutes' => (int)$b['duration_minutes'],
                'remaining_minutes' => $remMinutes,
                'is_delayed' => $isDelayed,
                'delay_reason' => $delayReason
            ];
        }

        // 2. Waiting List (Ordered by priority and FIFO)
        $queues = self::getQueues($pdo);
        $waitOrders = $queues['all_queue'];

        $totalWaiting = count($waitOrders);
        $waitingList = [];
        $maxShow = 8;

        foreach ($waitOrders as $idx => $ord) {
            $pos = $idx + 1;
            if ($pos <= $maxShow) {
                $isMixed = ((int)($ord['is_mixed'] ?? 0) === 1);
                $isPostMech = (!empty($ord['is_post_mechanical']) || ($isMixed && ($ord['repair_status'] ?? '') === 'finished'));
                $isTechNext = (!empty($ord['is_technical']));

                $taskType = $ord['active_task']['task_type'] ?? '';

                if ($taskType === 'qc') {
                    $statusKey = 'finishing_qc';
                    $statusEn = 'Finishing / QC Inspection';
                    $statusKm = 'ត្រួតពិនិត្យគុណភាព (QC)';
                } elseif ($isPostMech) {
                    $statusKey = 'waiting_wash';
                    $statusEn = 'Awaiting Wash (Mechanical Done)';
                    $statusKm = 'រង់ចាំលាង (ជួសជុលរួចរាល់)';
                } elseif ($isTechNext) {
                    $statusKey = 'waiting_mechanical';
                    $statusEn = $isMixed ? 'Awaiting Mechanical (Step 1)' : 'Waiting for Mechanical';
                    $statusKm = $isMixed ? 'រង់ចាំជួសជុល (វគ្គ ១)' : 'រង់ចាំជួសជុល';
                } else {
                    $statusKey = 'waiting_wash';
                    $statusEn = 'Waiting for Wash';
                    $statusKm = 'រង់ចាំលាងសម្អាត';
                }

                $statusLabel = match($lang) {
                    'km' => $statusKm,
                    'en' => $statusEn,
                    default => "{$statusEn} / {$statusKm}"
                };

                $waitingList[] = [
                    'position' => $pos,
                    'plate_number' => self::formatTvPlate($ord['plate_number']),
                    'is_next' => ($pos === 1),
                    'priority_code' => $ord['priority_code'],
                    'status_key' => $statusKey,
                    'status_en' => $statusEn,
                    'status_km' => $statusKm,
                    'status_label' => $statusLabel,
                    'is_mixed' => $isMixed,
                    'is_post_mechanical' => $isPostMech
                ];
            }
        }

        $moreCount = max(0, $totalWaiting - $maxShow);

        // 3. Ready for Pickup Cars (Finished cars whose service & QC are complete)
        $readySql = "
            SELECT id, plate_number, finished_at
            FROM orders
            WHERE status = 'finished' AND (is_active = 1 OR is_active IS NULL)
            ORDER BY finished_at DESC
            LIMIT 10
        ";
        $readyOrders = $pdo->query($readySql)->fetchAll(PDO::FETCH_ASSOC);

        $readyCars = [];
        foreach ($readyOrders as $ro) {
            $readyCars[] = [
                'plate_number' => self::formatTvPlate($ro['plate_number'])
            ];
        }

        return [
            'success' => true,
            'language' => $lang,
            'bays' => $tvBays,
            'waiting_list' => $waitingList,
            'waiting_total' => $totalWaiting,
            'waiting_more_count' => $moreCount,
            'ready_cars' => $readyCars,
            'ready_total' => count($readyCars),
            'server_time' => date('h:i:s A'),
            'server_date' => date('l, d M Y'),
        ];
    }
}
