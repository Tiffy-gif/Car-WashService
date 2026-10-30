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

        $driver = Database::getDriver();
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

        // 4. Ensure columns on bays table
        if (!$isSqlite) {
            $bayCols = $pdo->query("SHOW COLUMNS FROM bays")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('skill_tag', $bayCols, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN skill_tag VARCHAR(50) NOT NULL DEFAULT 'wash_only' AFTER bay_type");
            }
            if (!in_array('last_freed_at', $bayCols, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN last_freed_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER occupied_at");
            }
        } else {
            $tInfo = $pdo->query("PRAGMA table_info(bays)")->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_column($tInfo, 'name');
            if (!in_array('skill_tag', $existing, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN skill_tag VARCHAR(50) NOT NULL DEFAULT 'wash_only'");
            }
            if (!in_array('last_freed_at', $existing, true)) {
                $pdo->exec("ALTER TABLE bays ADD COLUMN last_freed_at DATETIME DEFAULT CURRENT_TIMESTAMP");
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
            if (!in_array('took_longer_than_expected', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN took_longer_than_expected TINYINT(1) DEFAULT NULL AFTER is_mixed");
            }
            if (!in_array('receipt_printed_at', $cols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_printed_at DATETIME DEFAULT NULL AFTER paid_at");
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
            if (!in_array('took_longer_than_expected', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN took_longer_than_expected TINYINT(1) DEFAULT NULL");
            }
            if (!in_array('receipt_printed_at', $existingCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_printed_at DATETIME DEFAULT NULL");
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

        $sql = "
            SELECT b.*,
                   o.id as order_id, o.order_number, o.plate_number, o.vehicle_type,
                   o.customer_name, o.customer_phone, o.status as order_status,
                   o.started_at, o.repair_status, o.is_mixed,
                   GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ') as service_names
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
                'current_order_id' => $r['current_order_id'] ? (int)$r['current_order_id'] : null,
                'occupied_at' => $r['occupied_at'],
                'last_freed_at' => $r['last_freed_at'],
                'sort_order' => (int)$r['sort_order'],
                'duration_minutes' => $durationMinutes,
                'in_bay_minutes' => $durationMinutes,
                'idle_minutes' => $idleMinutes,
                'plate_number' => $currentCar ? $currentCar['plate_number'] : ($r['plate_number'] ?? null),
                'service_names' => $currentCar ? $currentCar['service_names'] : ($r['service_names'] ?? null),
                'is_mixed' => $currentCar ? (int)$currentCar['is_mixed'] : (int)($r['is_mixed'] ?? 0),
                'current_car' => $currentCar
            ];
        }

        return $results;
    }

    /**
     * Check if an order is technical / oil change vs car wash.
     */
    public static function isTechnicalOrder(array $order, PDO $pdo): bool {
        $orderId = (int)($order['id'] ?? 0);
        if (!empty($order['repair_status'])) {
            return true;
        }
        if (!empty($order['next_oil_mileage']) || !empty($order['next_oil_date'])) {
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
                WHERE plate_number = ? AND appointment_date = CURRENT_DATE() AND status NOT IN ('cancelled', 'completed')
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
     * Enforces system decision for task sequence: Wash (Step 1) -> Oil Change (Step 2).
     */
    public static function ensureOrderTasks(PDO $pdo, int $orderId): array {
        $existing = $pdo->prepare("SELECT * FROM order_tasks WHERE order_id = ? ORDER BY sequence ASC, id ASC");
        $existing->execute([$orderId]);
        $tasks = $existing->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($tasks)) {
            return $tasks;
        }

        // Inspect order and items to determine tasks
        $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

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

        // Also check order notes / repair status
        if (!empty($order['repair_status']) || !empty($order['next_oil_mileage']) || !empty($order['repair_notes'])) {
            $hasOil = true;
        }

        // If neither detected, default to car wash
        if (!$hasWash && !$hasOil) {
            $hasWash = true;
        }

        $isMixed = ($hasWash && $hasOil) ? 1 : 0;
        $pdo->prepare("UPDATE orders SET is_mixed = ? WHERE id = ?")->execute([$isMixed, $orderId]);

        $insTask = $pdo->prepare("
            INSERT INTO order_tasks (order_id, task_type, title, sequence, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $tasksCreated = [];
        if ($isMixed) {
            // Task 1: Wash (Active / Waiting)
            $insTask->execute([$orderId, 'wash', 'Exterior & Interior Wash (Step 1)', 1, 'waiting']);
            $tasksCreated[] = ['order_id' => $orderId, 'task_type' => 'wash', 'sequence' => 1, 'status' => 'waiting'];

            // Task 2: Oil Change (Pending prerequisite)
            $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Inspection (Step 2)', 2, 'pending_prereq']);
            $tasksCreated[] = ['order_id' => $orderId, 'task_type' => 'oil_change', 'sequence' => 2, 'status' => 'pending_prereq'];
        } elseif ($hasOil) {
            $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Technical Service', 1, 'waiting']);
            $tasksCreated[] = ['order_id' => $orderId, 'task_type' => 'oil_change', 'sequence' => 1, 'status' => 'waiting'];
        } else {
            $insTask->execute([$orderId, 'wash', 'Car Wash Service', 1, 'waiting']);
            $tasksCreated[] = ['order_id' => $orderId, 'task_type' => 'wash', 'sequence' => 1, 'status' => 'waiting'];
        }

        $existing->execute([$orderId]);
        return $existing->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get waiting queue for wash and technical orders, ordered strictly by priority and FIFO.
     */
    public static function getQueues(PDO $pdo): array {
        self::ensureTables($pdo);

        // Fetch all waiting orders joined with customer membership
        $sql = "
            SELECT o.*,
                   c.membership_level, c.loyalty_points,
                   GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ') as service_names,
                   (SELECT COUNT(*) FROM order_mechanics om WHERE om.order_id = o.id) as mechanic_count
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.status IN ('waiting', 'washing') AND (o.bay_id IS NULL OR o.bay_id = 0) AND (o.is_active = 1 OR o.is_active IS NULL)
            GROUP BY o.id
            ORDER BY o.queue_priority ASC, COALESCE(o.arrived_at, o.created_at) ASC, o.id ASC
        ";

        $orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $processedOrders = [];

        foreach ($orders as $ord) {
            $orderId = (int)$ord['id'];
            $tasks = self::ensureOrderTasks($pdo, $orderId);

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
                'arrived_at' => $arrivedTimeStr,
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
                'active_task' => $activeTask,
                'is_waiting_tech_handover' => $isWaitingTechHandover
            ];
        }

        // Sort queues: First by supervisor manual queue_priority (if explicitly set > 0 or negative),
        // otherwise by priority_weight ASC (P0=0, P1=1, P2=2, P3=3), then wait_minutes DESC (FIFO)
        usort($processedOrders, function($a, $b) {
            if ($a['queue_priority'] !== $b['queue_priority'] && ($a['queue_priority'] != 0 || $b['queue_priority'] != 0)) {
                return $a['queue_priority'] <=> $b['queue_priority'];
            }
            if ($a['priority_weight'] !== $b['priority_weight']) {
                return $a['priority_weight'] <=> $b['priority_weight'];
            }
            return $b['wait_minutes'] <=> $a['wait_minutes'];
        });

        $washQueue = [];
        $techQueue = [];
        $washPos = 1;
        $techPos = 1;

        foreach ($processedOrders as $item) {
            if ($item['is_technical']) {
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
            return in_array($skillTag, ['wash_only', 'wash_and_oil', 'detailing'], true);
        }
        if ($taskType === 'oil_change') {
            return in_array($skillTag, ['oil_only', 'wash_and_oil'], true);
        }
        if ($taskType === 'detailing') {
            return in_array($skillTag, ['detailing', 'wash_only', 'wash_and_oil'], true);
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
        ?string $managerUsername = null
    ): array {
        self::ensureTables($pdo);

        // "No receipt, no wash" rule enforcement
        try {
            OrderGate::assertReceiptPrinted($pdo, $orderId, $managerPin, $reason, $managerUsername, $userId, $userName, $userRole);
        } catch (ReceiptRequiredException $re) {
            return [
                'success' => false,
                'error' => 'RECEIPT_REQUIRED',
                'message' => $re->getMessage()
            ];
        } catch (Throwable $e) {
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

        if (in_array($order['status'], ['paid', 'cancelled'], true)) {
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
                'message' => 'Sequence Rule: Step 1 (Car Wash) must be finished before Step 2 (Oil Change) can begin. Nobody skips a step.'
            ];
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

        if ($isOverride && (empty($reason) || trim((string)$reason) === '')) {
            return [
                'success' => false,
                'error' => 'OVERRIDE_REASON_REQUIRED',
                'message' => "Bay override: Bay #{$suggestedBay['bay_number']} ({$suggestedBay['bay_name']}) was recommended as the optimal choice. To assign to {$bay['bay_name']} instead, an override reason is required."
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
                WHERE id = ? AND status = 'free' AND is_active = 1
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

            // 6. Update order status: 'washing' (or repair_status = 'working')
            $newStatus = 'washing';
            $repairSql = ($bay['bay_type'] === 'oil_change' || $taskType === 'oil_change') ? ", repair_status = 'working'" : "";
            $suggestedId = $suggestedBay ? (int)$suggestedBay['bay_id'] : $bayId;

            $pdo->prepare("
                UPDATE orders 
                SET bay_id = ?, status = ?, started_at = COALESCE(started_at, ?), suggested_bay_id = ?, bay_override_reason = ? {$repairSql}
                WHERE id = ?
            ")->execute([$bayId, $newStatus, $now, $suggestedId, $isOverride ? $reason : null, $orderId]);

            // If override, log to audit_logs
            if ($isOverride) {
                audit_log(
                    'BAY_OVERRIDE',
                    "Supervisor/Staff {$userName} overrode recommended Bay #{$suggestedBay['bay_number']} for Bay #{$bay['bay_number']}. Reason: {$reason}",
                    'orders',
                    (string)$orderId,
                    $userId,
                    $userName,
                    $userRole
                );
            }

            // 7. History audit
            $reasonText = !empty($reason) ? " Reason: {$reason}" : "";
            $details = "Staff {$userName} ({$userRole}) assigned car {$order['plate_number']} to {$bay['bay_name']} [{$skillTag}].{$reasonText}";
            $pdo->prepare("
                INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                VALUES (?, ?, ?, ?, 'assigned', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $bayId, $bay['bay_name'], $order['plate_number'],
                $userId, $userName, $userRole, $reason, $details, $now
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

        $driver = Database::getDriver();
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
    public static function finishCar(PDO $pdo, int $identifier, int $userId, string $userName, string $userRole, ?int $tookLonger = null, ?string $reason = null): array {
        self::ensureTables($pdo);

        // Identifier can be bay_id or order_id
        $bay = null;
        $order = null;

        // Check if identifier is bay_id
        $bStmt = $pdo->prepare("SELECT * FROM bays WHERE id = ?");
        $bStmt->execute([$identifier]);
        $bay = $bStmt->fetch();

        if ($bay && !empty($bay['current_order_id'])) {
            $orderId = (int)$bay['current_order_id'];
            $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $oStmt->execute([$orderId]);
            $order = $oStmt->fetch();
        } else {
            // Check if identifier is order_id
            $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $oStmt->execute([$identifier]);
            $order = $oStmt->fetch();

            if ($order) {
                $orderId = (int)$order['id'];
                $bStmt = $pdo->prepare("SELECT * FROM bays WHERE current_order_id = ?");
                $bStmt->execute([$orderId]);
                $bay = $bStmt->fetch();
            }
        }

        if (!$order) {
            return ['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found'];
        }

        $orderId = (int)$order['id'];
        $now = date('Y-m-d H:i:s');
        $bayName = $bay ? $bay['bay_name'] : 'Bay';
        $bayId = $bay ? (int)$bay['id'] : null;

        // 1. Child tasks lookup
        $tasks = self::ensureOrderTasks($pdo, $orderId);
        $currentTask = null;
        $remainingTasks = [];

        foreach ($tasks as $t) {
            if ($t['status'] === 'in_progress') {
                $currentTask = $t;
            } elseif ($t['status'] !== 'completed') {
                $remainingTasks[] = $t;
            }
        }

        // If no in_progress task found, find first uncompleted
        if (!$currentTask && !empty($remainingTasks)) {
            $currentTask = array_shift($remainingTasks);
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        try {
            // Save estimate answer: took_longer_than_expected
            if ($tookLonger !== null) {
                $tookLongerVal = ($tookLonger === 1) ? 1 : 0;
                $pdo->prepare("UPDATE orders SET took_longer_than_expected = ? WHERE id = ?")->execute([$tookLongerVal, $orderId]);
                if ($currentTask) {
                    $pdo->prepare("UPDATE order_tasks SET took_longer_than_expected = ? WHERE id = ?")->execute([$tookLongerVal, $currentTask['id']]);
                }
            }

            // Mark current task as completed
            if ($currentTask) {
                $pdo->prepare("
                    UPDATE order_tasks 
                    SET status = 'completed', completed_at = ?, updated_at = ? 
                    WHERE id = ?
                ")->execute([$now, $now, $currentTask['id']]);
            }

            // Calculate actual duration & check Learning Engine
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
                $tookLongerVal ?? 0
            );

            // Free the bay immediately
            if ($bay) {
                $pdo->prepare("
                    UPDATE bays 
                    SET status = 'free', current_order_id = NULL, occupied_at = NULL, last_freed_at = ?, updated_at = ? 
                    WHERE id = ?
                ")->execute([$now, $now, $bay['id']]);
            } else {
                $pdo->prepare("
                    UPDATE bays 
                    SET status = 'free', current_order_id = NULL, occupied_at = NULL, last_freed_at = ?, updated_at = ? 
                    WHERE current_order_id = ?
                ")->execute([$now, $now, $orderId]);
            }

            // Check if there is a next task in sequence (Handshake / Mixed Order)
            $nextTaskStmt = $pdo->prepare("
                SELECT * FROM order_tasks 
                WHERE order_id = ? AND status != 'completed' 
                ORDER BY sequence ASC, id ASC 
                LIMIT 1
            ");
            $nextTaskStmt->execute([$orderId]);
            $nextTask = $nextTaskStmt->fetch(PDO::FETCH_ASSOC);

            if ($nextTask) {
                // MIXED ORDER HANDSHAKE: Transition next task to ready_for_tech
                $pdo->prepare("
                    UPDATE order_tasks 
                    SET status = 'ready_for_tech', updated_at = ? 
                    WHERE id = ?
                ")->execute([$now, $nextTask['id']]);

                // Order is NOT Done! Keep status as washing/transferred, reset bay_id
                $pdo->prepare("
                    UPDATE orders 
                    SET bay_id = NULL, repair_status = 'waiting_tech' 
                    WHERE id = ?
                ")->execute([$orderId]);

                $details = "Staff {$userName} finished {$currentTask['title']}. Handshake: Car {$order['plate_number']} sent to Technical screen for Step {$nextTask['sequence']} ({$nextTask['title']}). Bay {$bayName} is Free.";
                $pdo->prepare("
                    INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                    VALUES (?, ?, ?, ?, 'sent_to_tech', ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $orderId, $bayId, $bayName, $order['plate_number'],
                    $userId, $userName, $userRole, $reason, $details, $now
                ]);

                NotificationManager::notify($pdo, [
                    'target_role' => 'technical',
                    'category' => 'handshake',
                    'reference_id' => 'HANDSHAKE-TECH-' . $orderId,
                    'title_en' => 'Car Ready for Technical',
                    'title_km' => 'រថយន្តរួចរាល់សម្រាប់ផ្នែកបច្ចេកទេស',
                    'message_en' => "Wash finished for {$order['plate_number']}. Car waiting for Oil Change / Technical service.",
                    'message_km' => "ការលាងសម្អាតបានបញ្ចប់សម្រាប់ {$order['plate_number']}។ រង់ចាំការផ្លាស់ប្តូរប្រេង/សេវាបច្ចេកទេស។",
                    'action_url' => 'index.php?view=mechanic_jobs',
                    'sound_type' => 'chime'
                ]);

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                return [
                    'success' => true,
                    'is_handshake' => true,
                    'message' => "Step 1 finished and car sent to Technical screen. {$bayName} is now Free.",
                    'order_id' => $orderId,
                    'next_task' => $nextTask['title'],
                    'plate_number' => $order['plate_number']
                ];
            }

            // FINAL STEP: All tasks completed -> Car is Done ("Ready to pay")
            // MANDATORY GUIDED INSPECTION CHECK:
            // The technician cannot finish the job until every mandatory checklist item is passed.
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

            $pdo->prepare("
                UPDATE orders 
                SET status = 'finished',
                    repair_status = CASE WHEN repair_status IS NOT NULL THEN 'finished' ELSE repair_status END,
                    finished_at = COALESCE(finished_at, ?),
                    bay_id = NULL
                WHERE id = ?
            ")->execute([$now, $orderId]);

            $details = "Car {$order['plate_number']} fully finished by {$userName} ({$userRole}). {$bayName} is now Free and order is Ready to pay.";
            $pdo->prepare("
                INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, reason, details, created_at)
                VALUES (?, ?, ?, ?, 'finished', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $bayId, $bayName, $order['plate_number'],
                $userId, $userName, $userRole, $reason, $details, $now
            ]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'is_handshake' => false,
                'message' => "Car {$order['plate_number']} finished! Bay is now Free and order is Ready to pay.",
                'order_id' => $orderId,
                'bay_id' => $bayId,
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
     * Technician receives car transferred from wash: "Receive from Wash".
     * Assigns to technical bay (Bay 4) or selected oil bay.
     */
    public static function receiveFromWash(PDO $pdo, int $orderId, int $bayId, int $userId, string $userName, string $userRole): array {
        self::ensureTables($pdo);

        // Find the pending technical task
        $tStmt = $pdo->prepare("
            SELECT * FROM order_tasks 
            WHERE order_id = ? AND task_type = 'oil_change' AND status IN ('ready_for_tech', 'waiting', 'pending_prereq')
            LIMIT 1
        ");
        $tStmt->execute([$orderId]);
        $task = $tStmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return ['success' => false, 'error' => 'TASK_NOT_FOUND', 'message' => 'No technical task waiting for receive on this order.'];
        }

        // Verify that prerequisite wash task is completed
        $washCheck = $pdo->prepare("
            SELECT COUNT(*) FROM order_tasks 
            WHERE order_id = ? AND sequence < ? AND status != 'completed'
        ");
        $washCheck->execute([$orderId, $task['sequence']]);
        if (((int)$washCheck->fetchColumn()) > 0) {
            return [
                'success' => false,
                'error' => 'PREREQUISITE_INCOMPLETE',
                'message' => 'Sequence Rule: Car Wash step is not finished yet. Technician cannot receive this car.'
            ];
        }

        // Assign car to the selected bay
        $assignRes = self::assignCarToBay($pdo, $bayId, $orderId, $userId, $userName, $userRole, "Technician received car from wash");
        if (!$assignRes['success']) {
            return $assignRes;
        }

        // Initialize Technical Job Order and simultaneous lanes
        TechnicalManager::ensureJobOrder($pdo, $orderId, $userId, $userName);

        // Update task status to in_progress
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("
            UPDATE order_tasks 
            SET status = 'in_progress', bay_id = ?, assigned_user_id = ?, started_at = COALESCE(started_at, ?), updated_at = ?
            WHERE id = ?
        ")->execute([$bayId, $userId, $now, $now, $task['id']]);

        // Audit log
        $pdo->prepare("
            INSERT INTO bay_history (order_id, bay_id, bay_name, plate_number, action, performed_by, performed_by_name, performed_by_role, details, created_at)
            VALUES (?, ?, ?, ?, 'received_by_tech', ?, ?, ?, ?, ?)
        ")->execute([
            $orderId, $bayId, $assignRes['bay_name'], $assignRes['plate_number'],
            $userId, $userName, $userRole,
            "Technician {$userName} received car {$assignRes['plate_number']} from wash into {$assignRes['bay_name']}.",
            $now
        ]);

        return [
            'success' => true,
            'message' => "Car {$assignRes['plate_number']} received into {$assignRes['bay_name']}. Technical job started.",
            'order_id' => $orderId,
            'bay_id' => $bayId,
            'bay_name' => $assignRes['bay_name']
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

            // Status badges required: In Progress, Waiting, Detailing, Ready for Pickup
            if (!$isActive) {
                $badgeKey = 'inactive';
                $badgeEn = 'Inactive';
                $badgeKm = 'បានបិទ';
            } elseif ($isBusy) {
                if ($orderStatus === 'finished') {
                    $badgeKey = 'ready';
                    $badgeEn = 'Ready for Pickup';
                    $badgeKm = 'រួចរាល់សម្រាប់ទទួល';
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
                $waitingList[] = [
                    'position' => $pos,
                    'plate_number' => self::formatTvPlate($ord['plate_number']),
                    'is_next' => ($pos === 1),
                    'priority_code' => $ord['priority_code']
                ];
            }
        }

        $moreCount = max(0, $totalWaiting - $maxShow);

        // 3. Ready for Payment Cars (Finished cars whose payment_status != 'paid')
        $readySql = "
            SELECT id, plate_number, finished_at
            FROM orders
            WHERE status = 'finished' AND (payment_status != 'paid' OR payment_status IS NULL) AND (is_active = 1 OR is_active IS NULL)
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
