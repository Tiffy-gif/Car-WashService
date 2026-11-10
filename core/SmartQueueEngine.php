<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/NotificationManager.php';

/**
 * SmartQueueEngine - Intelligent Car Wash Queue & Efficiency System
 * 
 * Implements:
 * 1. Base duration & vehicle size multipliers (Sedan 1.0, SUV/Pickup 1.25, Van/Truck 1.4)
 * 2. Rolling technician & washer efficiency factor (actual / estimated)
 * 3. Learning review engine for jobs exceeding 20% overrun or took-longer answers
 * 4. Automatic bay scoring & recommendation (skill match / finish time) with logged overrides
 * 5. Remaining time calculation with configurable estimate buffer
 * 6. Delay reasons picker with auto "Waiting for part approval" detection
 * 7. Appointment VIP promotion on-time vs late walk-in demotion & free slots calculation
 * 8. POS estimated wait time calculation & supervisor > 45m wait alerts
 */
class SmartQueueEngine {

    /**
     * Vehicle size multiplier lookup.
     */
    public static function getVehicleMultiplier(PDO $pdo, string $vehicleType): float {
        $cleanType = strtolower(trim($vehicleType));

        if (in_array($cleanType, ['sedan', 'small_car', 'compact', 'saloon', 'car'], true)) {
            return (float)get_setting('multiplier_sedan', '1.0');
        } elseif (in_array($cleanType, ['suv_pickup', 'suv', 'pickup', 'truck_small'], true)) {
            return (float)get_setting('multiplier_suv_pickup', '1.25');
        } elseif (in_array($cleanType, ['van_truck', 'truck', 'van', 'commercial', 'large'], true)) {
            return (float)get_setting('multiplier_van_truck', '1.4');
        } elseif (in_array($cleanType, ['motorbike', 'motorcycle', 'moto'], true)) {
            return (float)get_setting('multiplier_motorbike', '0.6');
        }

        return 1.0;
    }

    /**
     * Get base duration in minutes for a service.
     */
    public static function getServiceBaseDuration(PDO $pdo, int $serviceId): int {
        if ($serviceId <= 0) return 15;
        $stmt = $pdo->prepare("SELECT base_duration_minutes FROM services WHERE id = ?");
        $stmt->execute([$serviceId]);
        $val = $stmt->fetchColumn();
        return ($val !== false && (int)$val > 0) ? (int)$val : 15;
    }

    /**
     * Compute total estimated duration for an order based on service(s), vehicle type, and optional staff efficiency.
     */
    public static function calculateEstimatedDuration(PDO $pdo, array $serviceIds, string $vehicleType, ?int $staffId = null): array {
        $multiplier = self::getVehicleMultiplier($pdo, $vehicleType);
        $baseTotal = 0;

        if (!empty($serviceIds)) {
            $inClause = implode(',', array_map('intval', $serviceIds));
            $rows = $pdo->query("SELECT id, base_duration_minutes FROM services WHERE id IN ($inClause)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $baseTotal += max(1, (int)($r['base_duration_minutes'] ?? 15));
            }
        }
        if ($baseTotal <= 0) {
            $baseTotal = 15; // default fallback
        }

        $sizeAdjusted = (int)round($baseTotal * $multiplier);

        $staffFactor = 1.0;
        if ($staffId !== null && $staffId > 0) {
            $staffStats = self::getStaffEfficiency($pdo, $staffId);
            $staffFactor = $staffStats['efficiency_factor'];
        }

        $finalEstimated = (int)round($sizeAdjusted * $staffFactor);

        return [
            'base_minutes' => $baseTotal,
            'vehicle_multiplier' => $multiplier,
            'size_adjusted_minutes' => $sizeAdjusted,
            'staff_efficiency' => $staffFactor,
            'total_estimated_minutes' => max(1, $finalEstimated)
        ];
    }

    /**
     * Technician & Washer Efficiency Factor:
     * Calculates rolling average of actual time divided by base time.
     * Higher factor (> 1.0) = slower person needing longer estimates.
     * Lower factor (< 1.0) = faster person.
     */
    public static function getStaffEfficiency(PDO $pdo, int $staffId): array {
        if ($staffId <= 0) {
            return [
                'staff_id' => $staffId,
                'staff_name' => 'Staff',
                'efficiency_factor' => 1.0,
                'avg_actual_minutes' => 15.0,
                'avg_base_minutes' => 15.0,
                'completed_jobs_count' => 0,
                'rating_label' => 'Baseline'
            ];
        }

        $nameStmt = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ?");
        $nameStmt->execute([$staffId]);
        $userRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $staffName = $userRow['full_name'] ?? ('Staff #' . $staffId);

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $t) {
            $driver = 'mysql';
        }
        $isSqlite = ($driver === 'sqlite');

        $elapsedExpr = $isSqlite
            ? "CAST(ROUND((julianday(ot.completed_at) - julianday(ot.created_at)) * 1440) AS INTEGER) as elapsed_minutes"
            : "TIMESTAMPDIFF(MINUTE, ot.created_at, ot.completed_at) as elapsed_minutes";

        // Fetch up to 30 completed tasks or orders by this staff member
        $sql = "
            SELECT 
                ot.id,
                ot.base_duration_minutes,
                ot.estimated_duration_minutes,
                ot.actual_duration_minutes,
                {$elapsedExpr}
            FROM order_tasks ot
            WHERE ot.assigned_user_id = ? AND ot.status = 'completed' AND ot.completed_at IS NOT NULL
            ORDER BY ot.completed_at DESC
            LIMIT 30
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$staffId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also check order_washers if task history is light
        if (count($tasks) < 5) {
            $orderElapsedExpr = $isSqlite
                ? "COALESCE(o.actual_duration_minutes, CAST(ROUND((julianday(o.finished_at) - julianday(o.arrived_at)) * 1440) AS INTEGER)) as actual_duration_minutes"
                : "COALESCE(o.actual_duration_minutes, TIMESTAMPDIFF(MINUTE, o.arrived_at, o.finished_at)) as actual_duration_minutes";

            $washSql = "
                SELECT 
                    o.id,
                    COALESCE(o.estimated_duration_minutes, 15) as estimated_duration_minutes,
                    {$orderElapsedExpr}
                FROM order_washers ow
                JOIN orders o ON ow.order_id = o.id
                WHERE ow.washer_id = ? AND o.status IN ('finished', 'completed', 'paid') AND o.finished_at IS NOT NULL
                ORDER BY o.finished_at DESC
                LIMIT 30
            ";
            $washStmt = $pdo->prepare($washSql);
            $washStmt->execute([$staffId]);
            $washOrders = $washStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($washOrders as $wo) {
                $tasks[] = [
                    'id' => $wo['id'],
                    'estimated_duration_minutes' => (int)$wo['estimated_duration_minutes'],
                    'actual_duration_minutes' => (int)$wo['actual_duration_minutes'],
                    'elapsed_minutes' => (int)$wo['actual_duration_minutes']
                ];
            }
        }

        $count = count($tasks);
        if ($count === 0) {
            return [
                'staff_id' => $staffId,
                'staff_name' => $staffName,
                'efficiency_factor' => 1.0,
                'avg_actual_minutes' => 15.0,
                'avg_base_minutes' => 15.0,
                'completed_jobs_count' => 0,
                'rating_label' => 'Baseline'
            ];
        }

        $totalRatio = 0.0;
        $totalActual = 0.0;
        $totalBase = 0.0;

        foreach ($tasks as $t) {
            $actual = max(1, (int)($t['actual_duration_minutes'] ?? $t['elapsed_minutes'] ?? 15));
            $base = max(1, (int)($t['estimated_duration_minutes'] ?? $t['base_duration_minutes'] ?? 15));

            $totalActual += $actual;
            $totalBase += $base;
            $totalRatio += ($actual / $base);
        }

        $avgRatio = $totalRatio / $count;
        // Clamp to realistic bounds [0.6 to 2.2] to avoid extreme outliers
        $factor = round(max(0.6, min(2.2, $avgRatio)), 2);

        $label = 'Normal';
        if ($factor <= 0.90) {
            $label = 'Fast';
        } elseif ($factor >= 1.15) {
            $label = 'Slower (Paces for safety)';
        }

        return [
            'staff_id' => $staffId,
            'staff_name' => $staffName,
            'efficiency_factor' => $factor,
            'avg_actual_minutes' => round($totalActual / $count, 1),
            'avg_base_minutes' => round($totalBase / $count, 1),
            'completed_jobs_count' => $count,
            'rating_label' => $label
        ];
    }

    /**
     * Get efficiency report for all active washers & technicians.
     */
    public static function getAllStaffEfficiency(PDO $pdo): array {
        $stmt = $pdo->query("
            SELECT id, full_name, role 
            FROM users 
            WHERE role IN ('washer', 'mechanic', 'technical') AND status = 'active'
            ORDER BY role ASC, full_name ASC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($users as $u) {
            $eff = self::getStaffEfficiency($pdo, (int)$u['id']);
            $eff['role'] = $u['role'];
            $results[] = $eff;
        }

        return $results;
    }

    /**
     * Learning Engine:
     * When a job finishes and actual time > estimate * 1.20 OR washer answered took_longer,
     * flags combination in service_duration_reviews for Manager & Owner decision.
     */
    public static function checkAndFlagForReview(
        PDO $pdo,
        int $orderId,
        ?int $taskId,
        int $estimatedMinutes,
        int $actualMinutes,
        int $washerAnsweredLonger = 0
    ): ?int {
        if ($estimatedMinutes <= 0) $estimatedMinutes = 15;
        if ($actualMinutes <= 0) $actualMinutes = $estimatedMinutes;

        $overPercentage = round((($actualMinutes - $estimatedMinutes) / $estimatedMinutes) * 100, 2);
        $isOver20 = ($actualMinutes > ($estimatedMinutes * 1.20));

        if (!$isOver20 && $washerAnsweredLonger !== 1) {
            return null; // Within normal tolerance
        }

        // Fetch service and vehicle details
        $ordStmt = $pdo->prepare("SELECT plate_number, vehicle_type FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
        $vehicleType = $order['vehicle_type'] ?? 'small_car';

        // Resolve service name
        $serviceId = null;
        $serviceName = 'Wash Service';

        if ($taskId !== null && $taskId > 0) {
            $tStmt = $pdo->prepare("SELECT title, task_type FROM order_tasks WHERE id = ?");
            $tStmt->execute([$taskId]);
            if ($tRow = $tStmt->fetch(PDO::FETCH_ASSOC)) {
                $serviceName = $tRow['title'];
            }
        }

        if ($serviceName === 'Wash Service') {
            $itemStmt = $pdo->prepare("SELECT service_id, service_name FROM order_items WHERE order_id = ? AND item_type = 'service' LIMIT 1");
            $itemStmt->execute([$orderId]);
            if ($item = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
                $serviceId = !empty($item['service_id']) ? (int)$item['service_id'] : null;
                $serviceName = $item['service_name'] ?: $serviceName;
            }
        }

        // Check if duplicate pending review already exists for this order
        $dupStmt = $pdo->prepare("SELECT id FROM service_duration_reviews WHERE order_id = ? AND status = 'pending'");
        $dupStmt->execute([$orderId]);
        if ($existId = $dupStmt->fetchColumn()) {
            return (int)$existId;
        }

        $ins = $pdo->prepare("
            INSERT INTO service_duration_reviews (
                service_id, service_name, vehicle_type, order_id, task_id,
                estimated_minutes, actual_minutes, over_percentage, washer_answered_longer,
                status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            $serviceId,
            $serviceName,
            $vehicleType,
            $orderId,
            $taskId,
            $estimatedMinutes,
            $actualMinutes,
            $overPercentage,
            $washerAnsweredLonger ? 1 : 0
        ]);

        $reviewId = (int)$pdo->lastInsertId();

        // Notify Manager and Owner
        NotificationManager::notify($pdo, [
            'target_role' => 'manager',
            'category' => 'learning_review',
            'reference_id' => 'REVIEW-' . $reviewId,
            'title_en' => "Duration Review Flagged: {$serviceName}",
            'title_km' => "ពិនិត្យមើលរយៈពេលសេវា៖ {$serviceName}",
            'message_en' => "Job took {$actualMinutes}m vs estimated {$estimatedMinutes}m (+{$overPercentage}%). Flagged for review.",
            'message_km' => "ការងារចំណាយពេល {$actualMinutes} នាទីធៀបនឹងការប៉ាន់ស្មាន {$estimatedMinutes} នាទី (+{$overPercentage}%)។",
            'link_url' => 'owner/bays_queue.php?tab=learning',
            'sound_type' => 'alert'
        ]);

        return $reviewId;
    }

    /**
     * Get pending reviews list for Manager/Owner.
     */
    public static function getPendingReviews(PDO $pdo): array {
        $stmt = $pdo->query("
            SELECT r.*, o.plate_number, o.order_number
            FROM service_duration_reviews r
            LEFT JOIN orders o ON r.order_id = o.id
            WHERE r.status = 'pending'
            ORDER BY r.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resolve a review decision (Change base time or dismiss).
     * Server never changes base duration autonomously!
     */
    public static function resolveReview(
        PDO $pdo,
        int $reviewId,
        string $decision, // 'adjust', 'update', or 'dismiss'
        ?int $newBaseMinutes,
        int $userId,
        string $userName,
        string $notes = ''
    ): array {
        $revStmt = $pdo->prepare("SELECT * FROM service_duration_reviews WHERE id = ?");
        $revStmt->execute([$reviewId]);
        $rev = $revStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rev) {
            return ['success' => false, 'message' => 'Review entry not found.'];
        }

        $pdo->beginTransaction();
        try {
            $isAdjust = ($decision === 'adjust' || $decision === 'update');
            if ($isAdjust && $newBaseMinutes !== null && $newBaseMinutes > 0 && !empty($rev['service_id'])) {
                // Update catalog base duration
                $pdo->prepare("UPDATE services SET base_duration_minutes = ? WHERE id = ?")
                    ->execute([$newBaseMinutes, $rev['service_id']]);

                $logMsg = "Manager/Owner {$userName} accepted review #{$reviewId} and adjusted service #{$rev['service_id']} ({$rev['service_name']}) base duration to {$newBaseMinutes}m. Notes: {$notes}";
                $status = 'adjusted';
            } else {
                $status = 'dismissed';
                $logMsg = "Manager/Owner {$userName} dismissed duration review #{$reviewId}. Base duration kept unchanged. Notes: {$notes}";
            }

            $pdo->prepare("
                UPDATE service_duration_reviews 
                SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, resolution_notes = ? 
                WHERE id = ?
            ")->execute([$status, $userId, $notes ?: $logMsg, $reviewId]);

            // Audit log entry
            require_once __DIR__ . '/AuditLogger.php';
            AuditLogger::log(
                'SERVICE_DURATION_REVIEW',
                $logMsg,
                'service_duration_reviews',
                (string)$reviewId,
                $userId,
                $userName,
                'manager'
            );

            $pdo->commit();
            return ['success' => true, 'message' => $logMsg, 'status' => $status];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Automatic Bay Choice:
     * Scores each free or soon-to-be-free bay for a car/task.
     * Score = Skill Match Score / Estimated Finish Time (in minutes).
     * Highest score is recommended first.
     */
    public static function scoreBaysForCar(PDO $pdo, int|array $order): array {
        require_once __DIR__ . '/BayManager.php';
        BayManager::ensureTables($pdo);

        if (is_int($order)) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$order]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $order];
        }

        $bays = BayManager::getAllBays($pdo);
        $orderId = (int)($order['id'] ?? 0);
        $tasks = BayManager::ensureOrderTasks($pdo, $orderId);

        // Find active/pending child task
        $activeTask = null;
        foreach ($tasks as $t) {
            if (in_array($t['status'], ['waiting', 'in_progress', 'ready_for_tech'], true)) {
                $activeTask = $t;
                break;
            }
        }
        $taskType = $activeTask ? $activeTask['task_type'] : 'wash';

        $scoredBays = [];

        foreach ($bays as $b) {
            if (empty($b['is_active'])) continue;

            $skillTag = $b['skill_tag'] ?? 'wash_only';
            $isBusy = ($b['status'] === 'busy' && !empty($b['current_order_id']));

            // 1. Skill Match Weight
            $skillScore = 0;
            if ($taskType === 'wash') {
                if ($skillTag === 'wash_only') $skillScore = 100;
                elseif ($skillTag === 'wash_and_oil') $skillScore = 75;
                elseif ($skillTag === 'detailing') $skillScore = 60;
            } elseif ($taskType === 'oil_change') {
                if ($skillTag === 'oil_only') $skillScore = 100;
                elseif ($skillTag === 'wash_and_oil') $skillScore = 75;
            } elseif ($taskType === 'detailing') {
                if ($skillTag === 'detailing') $skillScore = 100;
                elseif ($skillTag === 'wash_only' || $skillTag === 'wash_and_oil') $skillScore = 50;
            }

            if ($skillScore === 0) {
                // Incompatible skill
                continue;
            }

            // 2. Finish Time / Remaining Time of bay
            $finishMinutes = 1.0; // Free bay ready immediately
            if ($isBusy) {
                $remData = self::getBayRemainingMinutes($pdo, $b);
                $finishMinutes = max(1.0, (float)$remData['buffered_remaining_minutes']);
            }

            // Score formula: Skill match divided by finish time
            $finalScore = round($skillScore / $finishMinutes, 2);

            $scoredBays[] = [
                'bay_id' => (int)$b['id'],
                'bay_number' => (int)$b['bay_number'],
                'bay_name' => $b['bay_name'],
                'skill_tag' => $skillTag,
                'is_free' => !$isBusy,
                'skill_score' => $skillScore,
                'finish_minutes' => (int)round($finishMinutes),
                'score' => $finalScore,
                'reason' => !$isBusy ? "Free now (100% match)" : "Busy (~{$finishMinutes}m remaining)"
            ];
        }

        // Sort by score DESC
        usort($scoredBays, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scoredBays;
    }

    /**
     * Remaining Time & Buffer:
     * Calculates remaining time for a car in a bay, incorporating the owner's estimate buffer.
     */
    public static function getBayRemainingMinutes(PDO $pdo, int|array $bay): array {
        $bufferPercent = (int)get_setting('estimate_buffer_percent', '10');

        if (is_int($bay)) {
            $bStmt = $pdo->prepare("SELECT * FROM bays WHERE id = ?");
            $bStmt->execute([$bay]);
            $bay = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        if (empty($bay['occupied_at']) || empty($bay['current_order_id'])) {
            return [
                'expected_minutes' => 0,
                'elapsed_minutes' => 0,
                'raw_remaining_minutes' => 0,
                'buffered_remaining_minutes' => 0,
                'remaining_minutes' => 0,
                'buffer_percent' => $bufferPercent,
                'is_overdue' => false,
                'is_delayed' => false,
                'delay_minutes' => 0,
                'delay_reason' => null
            ];
        }

        $orderId = (int)$bay['current_order_id'];
        $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        $occupiedTs = strtotime($bay['occupied_at']);
        $elapsedMinutes = max(0, (int)floor((time() - $occupiedTs) / 60));

        // Total expected duration
        $expectedMinutes = (int)($order['estimated_duration_minutes'] ?? 0);
        if ($expectedMinutes <= 0) {
            // Calculate on the fly
            $calc = self::calculateEstimatedDuration($pdo, [], $order['vehicle_type'] ?? 'small_car');
            $expectedMinutes = $calc['total_estimated_minutes'];
        }

        $rawRemaining = max(0, $expectedMinutes - $elapsedMinutes);
        $bufferedRemaining = (int)ceil($rawRemaining * (1 + ($bufferPercent / 100)));

        $isOverdue = ($elapsedMinutes > ($expectedMinutes + 5));
        $delayMinutes = max(0, $elapsedMinutes - $expectedMinutes);

        // Check for delay reason (manual or auto-parts)
        $delayReason = $order['delay_reason'] ?? null;
        if (empty($delayReason)) {
            $delayReason = self::autoCheckPartsDelay($pdo, $orderId);
        }

        return [
            'expected_minutes' => $expectedMinutes,
            'elapsed_minutes' => $elapsedMinutes,
            'raw_remaining_minutes' => $rawRemaining,
            'buffered_remaining_minutes' => $bufferedRemaining,
            'remaining_minutes' => $bufferedRemaining,
            'buffer_percent' => $bufferPercent,
            'is_overdue' => $isOverdue,
            'is_delayed' => $isOverdue,
            'delay_minutes' => $delayMinutes,
            'delay_reason' => $delayReason
        ];
    }

    /**
     * Delay reasons:
     * Reads editable delay reasons list from settings.
     */
    public static function getAvailableDelayReasons(PDO $pdo): array {
        $raw = get_setting('delay_reasons_list', '');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
        return [
            'Heavy soil - extra rinse',
            'Customer requested extra interior vacuum',
            'Stuck oil filter bolt',
            'Technical complication',
            'Extra drying & tire dressing'
        ];
    }

    /**
     * Check if a parts request is pending and auto-assigns "Waiting for part approval".
     */
    public static function autoCheckPartsDelay(PDO $pdo, int $orderId): ?string {
        if ($orderId <= 0) return null;
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM parts_requests 
            WHERE order_id = ? AND status = 'pending'
        ");
        $stmt->execute([$orderId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return 'Waiting for part approval';
        }
        return null;
    }

    /**
     * Set a delay reason on an order.
     */
    public static function setOrderDelayReason(PDO $pdo, int $orderId, string $reason, int $userId, string $userName): bool {
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET delay_reason = ?, delay_logged_at = NOW(), delay_logged_by = ? 
            WHERE id = ?
        ");
        $ok = $stmt->execute([$reason, $userId, $orderId]);
        if ($ok) {
            require_once __DIR__ . '/AuditLogger.php';
            AuditLogger::log(
                'ORDER_DELAY_REASON',
                "Staff {$userName} set delay reason for order #{$orderId}: '{$reason}'",
                'orders',
                (string)$orderId,
                $userId,
                $userName,
                'staff'
            );
        }
        return $ok;
    }

    /**
     * Appointment Priority & VIP Logic:
     * If customer arrives within 10 minutes of the time slot -> P0 VIP priority!
     * If customer is late (> 10 mins) -> normal walk-in P3 priority.
     */
    public static function evaluateAppointmentPriority(array $order): array {
        $priorityLevel = strtoupper(trim((string)($order['priority_level'] ?? 'P3')));
        $memLevel = strtolower(trim((string)($order['membership_level'] ?? '')));
        $loyaltyPoints = (int)($order['loyalty_points'] ?? 0);

        // Pre-existing VIP
        if (in_array($memLevel, ['vip', 'gold', 'platinum', 'diamond'], true) || $loyaltyPoints >= 500 || $priorityLevel === 'P0') {
            return [
                'code' => 'P0',
                'label' => 'P0 VIP / Member',
                'weight' => 0,
                'is_appointment' => false,
                'badge_class' => 'badge-purple'
            ];
        }

        if (!empty($order['appointment_time'])) {
            $apptTs = strtotime($order['appointment_time']);
            $arrivedTs = !empty($order['arrived_at']) ? strtotime($order['arrived_at']) : time();

            // Difference in minutes
            $diffMins = (int)round(($arrivedTs - $apptTs) / 60);

            // Within 10 minutes before or after the time slot -> VIP Priority!
            if (abs($diffMins) <= 10) {
                return [
                    'code' => 'P0',
                    'label' => 'P0 VIP / Appointment (On Time)',
                    'weight' => 0,
                    'is_appointment' => true,
                    'badge_class' => 'badge-success'
                ];
            } else {
                // Arrived late (> 10 mins after slot) -> Treated as normal walk-in P3
                return [
                    'code' => 'P3',
                    'label' => 'P3 Walk-in (Late Appt +' . $diffMins . 'm)',
                    'weight' => 3,
                    'is_appointment' => true,
                    'badge_class' => 'badge-secondary'
                ];
            }
        }

        return [
            'code' => $priorityLevel ?: 'P3',
            'label' => $priorityLevel === 'P2' ? 'P2 Express' : 'P3 Walk-in',
            'weight' => $priorityLevel === 'P2' ? 2 : 3,
            'is_appointment' => false,
            'badge_class' => 'badge-secondary'
        ];
    }

    /**
     * Show free slots by hour and by service length.
     * Shop hours: 08:00 to 18:00.
     */
    public static function getFreeAppointmentSlots(PDO $pdo, string $date, int $serviceLengthMinutes = 15): array {
        $cleanDate = date('Y-m-d', strtotime($date));
        $baysCount = (int)$pdo->query("SELECT COUNT(*) FROM bays WHERE is_active = 1")->fetchColumn();
        if ($baysCount <= 0) $baysCount = 4;

        // Fetch booked appointments on that date
        $appStmt = $pdo->prepare("
            SELECT appointment_time, status 
            FROM appointments 
            WHERE appointment_date = ? AND status NOT IN ('cancelled', 'completed')
        ");
        $appStmt->execute([$cleanDate]);
        $booked = $appStmt->fetchAll(PDO::FETCH_ASSOC);

        $slots = [];
        // Hours from 08:00 to 17:00 (last slot finishes by 18:00)
        for ($hour = 8; $hour < 18; $hour++) {
            $timeStr = sprintf('%02d:00', $hour);
            $bookedCount = 0;
            foreach ($booked as $b) {
                if (str_starts_with($b['appointment_time'], sprintf('%02d:', $hour))) {
                    $bookedCount++;
                }
            }

            $availableCapacity = max(0, $baysCount - $bookedCount);
            $isFree = ($availableCapacity > 0);

            $slots[] = [
                'date' => $cleanDate,
                'time' => $timeStr,
                'label' => date('h:i A', strtotime("2000-01-01 $timeStr")),
                'booked_count' => $bookedCount,
                'capacity' => $baysCount,
                'available_capacity' => $availableCapacity,
                'is_free' => $isFree,
                'service_length_fits' => ($serviceLengthMinutes <= 60)
            ];
        }

        return $slots;
    }

    /**
     * Estimated Wait Time for POS:
     * Calculates wait time for a new car and sends notification if > 45 minutes.
     */
    public static function calculateEstimatedWaitTime(PDO $pdo, int $serviceId = 0, string $vehicleType = 'small_car'): array {
        require_once __DIR__ . '/BayManager.php';
        BayManager::ensureTables($pdo);

        // 1. New job's duration
        $newJob = self::calculateEstimatedDuration($pdo, $serviceId > 0 ? [$serviceId] : [], $vehicleType);
        $newJobDuration = $newJob['total_estimated_minutes'];

        // 2. Sum work remaining of active bays
        $bays = BayManager::getAllBays($pdo);
        $activeBayCount = 0;
        $totalBayRemaining = 0;

        foreach ($bays as $b) {
            if (empty($b['is_active'])) continue;
            $activeBayCount++;
            if ($b['status'] === 'busy') {
                $rem = self::getBayRemainingMinutes($pdo, $b);
                $totalBayRemaining += $rem['buffered_remaining_minutes'];
            }
        }
        if ($activeBayCount <= 0) $activeBayCount = 1;

        // 3. Waiting cars in queue
        $queues = BayManager::getQueues($pdo);
        $waitingCars = $queues['all_queue'];
        $waitingCount = count($waitingCars);

        $totalQueueMinutes = 0;
        foreach ($waitingCars as $wc) {
            $dur = (int)($wc['estimated_duration_minutes'] ?? 0);
            if ($dur <= 0) {
                $calc = self::calculateEstimatedDuration($pdo, [], $wc['vehicle_type'] ?? 'small_car');
                $dur = $calc['total_estimated_minutes'];
            }
            $totalQueueMinutes += $dur;
        }

        // Wait time = (total work ahead) / active bays
        $totalWorkAhead = $totalBayRemaining + $totalQueueMinutes;
        $estimatedWait = (int)ceil($totalWorkAhead / $activeBayCount);

        // Buffer percent
        $buffer = (int)get_setting('estimate_buffer_percent', '10');
        $bufferedWait = (int)ceil($estimatedWait * (1 + ($buffer / 100)));

        // If wait over 45 minutes, notify Supervisor
        if ($bufferedWait > 45) {
            require_once __DIR__ . '/NotificationManager.php';
            NotificationManager::ensureTables($pdo);
            NotificationManager::notify($pdo, [
                'target_role' => 'wash_supervisor',
                'category' => 'long_wait',
                'reference_id' => 'WAIT-45-' . date('Ymd-H'),
                'title_en' => 'Wait time over 45 minutes',
                'title_km' => 'រយៈពេលរង់ចាំលើសពី ៤៥ នាទី',
                'message_en' => "Current estimated customer wait is {$bufferedWait} minutes ({$waitingCount} cars in queue).",
                'message_km' => "ការប៉ាន់ស្មានការរង់ចាំបច្ចុប្បន្នគឺ {$bufferedWait} នាទី ({$waitingCount} គ្រឿងក្នុងជួរ)។",
                'action_url' => 'index.php?view=bays_queue',
                'sound_type' => 'alert'
            ]);
        }

        return [
            'estimated_wait_minutes' => $bufferedWait,
            'waiting_cars_count' => $waitingCount,
            'active_bays_count' => $activeBayCount,
            'new_service_duration_minutes' => $newJobDuration,
            'is_over_45' => ($bufferedWait > 45)
        ];
    }
}
