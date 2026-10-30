<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Helpers.php';

class MembershipManager {

    /**
     * Get all active membership tiers.
     */
    public static function getTiers(PDO $pdo): array {
        $stmt = $pdo->query("SELECT * FROM membership_tiers WHERE is_active = 1 ORDER BY monthly_fee_usd ASC");
        $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tiers as &$t) {
            $t['included_services_data'] = !empty($t['included_services']) ? json_decode($t['included_services'], true) : [];
        }
        return $tiers;
    }

    /**
     * Get a specific tier by code (e.g. 'silver', 'gold', 'platinum', 'standard').
     */
    public static function getTierByCode(PDO $pdo, string $tierCode): ?array {
        $stmt = $pdo->prepare("SELECT * FROM membership_tiers WHERE LOWER(tier_code) = LOWER(?) AND is_active = 1");
        $stmt->execute([$tierCode]);
        $tier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tier) {
            $tier['included_services_data'] = !empty($tier['included_services']) ? json_decode($tier['included_services'], true) : [];
            return $tier;
        }
        return null;
    }

    /**
     * Link a customer to a membership tier.
     */
    public static function linkCustomerToTier(PDO $pdo, int $customerId, string $tierCode, ?string $startedAt = null, ?string $expiresAt = null): bool {
        $tier = self::getTierByCode($pdo, $tierCode);
        $tierId = $tier ? (int)$tier['id'] : null;
        $startedAt = $startedAt ?: date('Y-m-d');

        $stmt = $pdo->prepare("
            UPDATE customers
            SET membership_tier_id = ?,
                membership_tier_code = ?,
                membership_level = ?,
                membership_started_at = ?,
                membership_expires_at = ?
            WHERE id = ?
        ");
        $res = $stmt->execute([$tierId, $tierCode, ucfirst($tierCode), $startedAt, $expiresAt, $customerId]);

        AuditLogger::log('MEMBERSHIP_TIER_LINKED', "Customer #{$customerId} linked to tier '{$tierCode}' (Expires: " . ($expiresAt ?: 'Never') . ")", 'customers', (string)$customerId);
        return $res;
    }

    /**
     * Link a vehicle to a tier.
     */
    public static function linkVehicleToTier(PDO $pdo, int $vehicleId, string $tierCode): bool {
        $tier = self::getTierByCode($pdo, $tierCode);
        $tierId = $tier ? (int)$tier['id'] : null;

        $stmt = $pdo->prepare("
            UPDATE vehicles
            SET membership_tier_id = ?,
                membership_tier_code = ?
            WHERE id = ?
        ");
        return $stmt->execute([$tierId, $tierCode, $vehicleId]);
    }

    /**
     * Get active tier information for a customer / vehicle.
     */
    public static function getCustomerTier(PDO $pdo, int $customerId, ?int $vehicleId = null): array {
        $tierCode = 'standard';
        $expiresAt = null;

        // Check vehicle specific tier first if provided
        if ($vehicleId) {
            $vStmt = $pdo->prepare("SELECT membership_tier_code FROM vehicles WHERE id = ?");
            $vStmt->execute([$vehicleId]);
            $vCode = $vStmt->fetchColumn();
            if (!empty($vCode) && strtolower($vCode) !== 'standard') {
                $tierCode = strtolower((string)$vCode);
            }
        }

        // If not set on vehicle, check customer tier
        if ($tierCode === 'standard') {
            $cStmt = $pdo->prepare("SELECT membership_tier_code, membership_level, membership_expires_at FROM customers WHERE id = ?");
            $cStmt->execute([$customerId]);
            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
            if ($cRow) {
                $cCode = !empty($cRow['membership_tier_code']) ? $cRow['membership_tier_code'] : ($cRow['membership_level'] ?? 'standard');
                $expiresAt = $cRow['membership_expires_at'] ?? null;
                // Check if membership is expired
                if ($expiresAt && strtotime($expiresAt) < time()) {
                    $tierCode = 'standard'; // Expired
                } else {
                    $tierCode = strtolower((string)$cCode);
                }
            }
        }

        $tier = self::getTierByCode($pdo, $tierCode) ?: [
            'id' => 0,
            'tier_code' => 'standard',
            'name_en' => 'Standard',
            'name_km' => 'កម្រិតស្តង់ដារ',
            'monthly_fee_usd' => 0.00,
            'included_services_data' => [],
            'discount_percent' => 0,
            'priority_level' => 'P3'
        ];

        return [
            'tier_code' => $tierCode,
            'tier_name' => $tier['name_en'],
            'tier_name_km' => $tier['name_km'],
            'tier' => $tier,
            'expires_at' => $expiresAt,
            'is_member' => ($tierCode !== 'standard')
        ];
    }

    /**
     * Check if a service is included at no charge under customer's membership tier and limits.
     * Evaluates monthly limits and shows clearly when a limit is used up.
     */
    public static function checkIncludedServiceEligibility(PDO $pdo, int $customerId, ?int $vehicleId, int $serviceId, ?string $visitDate = null): array {
        $visitDate = $visitDate ?: date('Y-m-d');
        $usageMonth = substr($visitDate, 0, 7); // e.g. "2026-10"

        $tierInfo = self::getCustomerTier($pdo, $customerId, $vehicleId);
        $tier = $tierInfo['tier'];
        $tierCode = $tierInfo['tier_code'];
        $includedServices = $tier['included_services_data'] ?? [];

        // Check if serviceId matches any included service rule
        $matchedRule = null;
        foreach ($includedServices as $rule) {
            if ((int)$rule['service_id'] === $serviceId) {
                $matchedRule = $rule;
                break;
            }
        }

        if (!$matchedRule) {
            return [
                'is_eligible' => false,
                'is_free' => false,
                'limit_type' => 'none',
                'limit_qty' => 0,
                'used_this_month' => 0,
                'is_limit_reached' => false,
                'tier_code' => $tierCode,
                'message' => null
            ];
        }

        $limitType = $matchedRule['limit_type'] ?? 'unlimited';
        $limitQty = (int)($matchedRule['limit_qty'] ?? 0);

        // Count how many times this included service was redeemed this month
        $uStmt = $pdo->prepare("
            SELECT COUNT(*) FROM membership_usages
            WHERE customer_id = ? AND service_id = ? AND usage_month = ? AND is_free = 1
        ");
        $uStmt->execute([$customerId, $serviceId, $usageMonth]);
        $usedThisMonth = (int)$uStmt->fetchColumn();

        if ($limitType === 'unlimited') {
            return [
                'is_eligible' => true,
                'is_free' => true,
                'limit_type' => 'unlimited',
                'limit_qty' => 0,
                'used_this_month' => $usedThisMonth,
                'is_limit_reached' => false,
                'tier_code' => $tierCode,
                'message' => "Included with {$tier['name_en']} (Visit #" . ($usedThisMonth + 1) . " - Unlimited)"
            ];
        }

        // Monthly limit check
        if ($usedThisMonth < $limitQty) {
            $nextVisitNum = $usedThisMonth + 1;
            return [
                'is_eligible' => true,
                'is_free' => true,
                'limit_type' => 'monthly',
                'limit_qty' => $limitQty,
                'used_this_month' => $usedThisMonth,
                'is_limit_reached' => false,
                'tier_code' => $tierCode,
                'message' => "Included with {$tier['name_en']} ({$nextVisitNum}/{$limitQty} used this month)"
            ];
        }

        // Limit used up! Regular price applies.
        return [
            'is_eligible' => false,
            'is_free' => false,
            'limit_type' => 'monthly',
            'limit_qty' => $limitQty,
            'used_this_month' => $usedThisMonth,
            'is_limit_reached' => true,
            'tier_code' => $tierCode,
            'message' => "Monthly limit used up ({$usedThisMonth}/{$limitQty} used this month). Regular price applies."
        ];
    }

    /**
     * Record a membership service visit usage.
     */
    public static function recordServiceUsage(PDO $pdo, int $customerId, ?int $vehicleId, ?string $plateNumber, string $tierCode, int $serviceId, ?int $orderId = null, bool $isFree = true, ?string $visitDate = null): int {
        $visitDate = $visitDate ?: date('Y-m-d');
        $usageMonth = substr($visitDate, 0, 7);

        $stmt = $pdo->prepare("
            INSERT INTO membership_usages (
                customer_id, vehicle_id, plate_number, tier_code,
                service_id, order_id, usage_month, visit_date, is_free
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ");
        $stmt->execute([
            $customerId, $vehicleId, $plateNumber, $tierCode,
            $serviceId, $orderId, $usageMonth, $visitDate, $isFree ? 1 : 0
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Check loyalty reward milestones (e.g. 5th visit this month: free air freshener).
     */
    public static function checkLoyaltyRewardMilestones(PDO $pdo, int $customerId, ?string $targetDate = null): array {
        $targetDate = $targetDate ?: date('Y-m-d');
        $month = substr($targetDate, 0, 7);

        // Count customer's visits this month from orders
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM orders
            WHERE customer_id = ? AND DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') = ?
              AND status IN ('paid', 'completed')
        ");
        $stmt->execute([$customerId, $month]);
        $visitCount = (int)$stmt->fetchColumn();

        // Also check active loyalty reward rules
        $rulesStmt = $pdo->query("SELECT * FROM loyalty_reward_rules WHERE is_active = 1 ORDER BY trigger_threshold ASC");
        $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

        $unlockedRewards = [];
        foreach ($rules as $r) {
            $threshold = (int)$r['trigger_threshold'];
            // If milestone matches (e.g. 5th visit this month)
            if ($visitCount >= $threshold) {
                $unlockedRewards[] = [
                    'rule_id' => (int)$r['id'],
                    'rule_uid' => $r['rule_uid'],
                    'name_en' => $r['name_en'],
                    'name_km' => $r['name_km'],
                    'trigger_threshold' => $threshold,
                    'reward_type' => $r['reward_type'],
                    'reward_item_id' => $r['reward_item_id'] ? (int)$r['reward_item_id'] : null,
                    'reward_item_name' => $r['reward_item_name'],
                    'reward_value_usd' => (float)$r['reward_value_usd'],
                    'current_visits' => $visitCount,
                    'message' => $r['name_en']
                ];
            }
        }

        return [
            'visit_count_this_month' => $visitCount,
            'has_rewards' => !empty($unlockedRewards),
            'rewards' => $unlockedRewards
        ];
    }

    /**
     * Calculate average kilometers driven per day by a vehicle based on past visit records.
     */
    public static function calculateVehicleAverageKmPerDay(PDO $pdo, int $vehicleId): float {
        // Query distinct mileage entries sorted by date
        $stmt = $pdo->prepare("
            SELECT current_mileage, DATE(COALESCE(arrived_at, created_at)) as vdate
            FROM orders
            WHERE vehicle_id = ? AND current_mileage > 0
            ORDER BY COALESCE(arrived_at, created_at) ASC
        ");
        $stmt->execute([$vehicleId]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($records) < 2) {
            return 35.0; // Default Phnom Penh daily average km
        }

        $first = $records[0];
        $last = $records[count($records) - 1];

        $kmDiff = (int)$last['current_mileage'] - (int)$first['current_mileage'];
        $daysDiff = max(1, (strtotime($last['vdate']) - strtotime($first['vdate'])) / 86400);

        if ($kmDiff <= 0 || $daysDiff <= 0) {
            return 35.0;
        }

        $avgKm = round($kmDiff / $daysDiff, 1);
        return max(5.0, min(200.0, $avgKm)); // Bound between 5km/day and 200km/day
    }

    /**
     * Calculate whether oil change or wash reminder is due and queue message events for the cloud.
     * STRICTLY respects customer opt-in: Never creates an event if customer opted out!
     */
    public static function processDueReminders(PDO $pdo, bool $createQueueEvents = true): array {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $queuedOilCount = 0;
        $queuedWashCount = 0;
        $optedOutSkipped = 0;

        // Fetch vehicles with customer details
        $vSql = "
            SELECT v.id as vehicle_id, v.customer_id, v.plate_number, v.vehicle_type,
                   v.last_mileage, v.next_oil_mileage, v.next_oil_date,
                   c.name as customer_name, c.phone as customer_phone,
                   c.message_opt_in
            FROM vehicles v
            JOIN customers c ON v.customer_id = c.id
            WHERE c.status = 'active' OR c.status IS NULL
        ";
        $vehicles = $pdo->query($vSql)->fetchAll(PDO::FETCH_ASSOC);

        $eventsCreated = [];

        foreach ($vehicles as $v) {
            $custId = (int)$v['customer_id'];
            $vId = (int)$v['vehicle_id'];
            $plate = $v['plate_number'];
            $phone = $v['customer_phone'];
            $optIn = (int)($v['message_opt_in'] ?? 1);

            // 1. Opt-In Enforcement Check: Never message opted-out customer
            if ($optIn === 0) {
                $optedOutSkipped++;
                continue;
            }

            // 2. Oil Due Calculation
            $avgKmPerDay = self::calculateVehicleAverageKmPerDay($pdo, $vId);
            $lastMileage = (int)($v['last_mileage'] ?? 0);
            $nextOilKm = (int)($v['next_oil_mileage'] ?? 0);
            $nextOilDate = $v['next_oil_date'];

            $isOilDue = false;
            $oilReason = '';

            if ($nextOilKm > 0 && $lastMileage > 0) {
                $kmRemaining = $nextOilKm - $lastMileage;
                if ($kmRemaining <= 500) {
                    $isOilDue = true;
                    $oilReason = "Mileage due: {$lastMileage} km reached ({$kmRemaining} km remaining until {$nextOilKm} km target)";
                }
            }

            if (!$isOilDue && !empty($nextOilDate)) {
                $daysRemaining = (int)round((strtotime($nextOilDate) - strtotime($today)) / 86400);
                if ($daysRemaining <= 7) {
                    $isOilDue = true;
                    $oilReason = "Calendar date due: {$nextOilDate} (in {$daysRemaining} days)";
                }
            }

            if ($isOilDue && $createQueueEvents) {
                // Check if already queued recently (within 14 days) to prevent spamming
                $recentCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM crm_message_queue
                    WHERE vehicle_id = ? AND reminder_type = 'oil_due' AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ");
                $recentCheck->execute([$vId]);
                if ((int)$recentCheck->fetchColumn() === 0) {
                    $eventUid = 'MSG-OIL-' . $vId . '-' . time() . '-' . bin2hex(random_bytes(3));
                    $title = "Oil Change Due: {$plate}";
                    $body = "Dear {$v['customer_name']}, your vehicle ({$plate}) is due for scheduled engine oil service. {$oilReason}. Visit AquaGleam for fast premium service!";
                    $meta = json_encode([
                        'vehicle_id' => $vId,
                        'plate_number' => $plate,
                        'last_mileage' => $lastMileage,
                        'next_oil_mileage' => $nextOilKm,
                        'next_oil_date' => $nextOilDate,
                        'avg_km_per_day' => $avgKmPerDay
                    ]);

                    $insStmt = $pdo->prepare("
                        INSERT INTO crm_message_queue (
                            event_uid, customer_id, vehicle_id, plate_number, customer_phone,
                            reminder_type, title, message_body, metadata_json, status, is_synced, created_at
                        ) VALUES (?, ?, ?, ?, ?, 'oil_due', ?, ?, ?, 'queued', 0, ?)
                    ");
                    $insStmt->execute([$eventUid, $custId, $vId, $plate, $phone, $title, $body, $meta, $now]);
                    $queuedOilCount++;
                    $eventsCreated[] = ['type' => 'oil_due', 'uid' => $eventUid, 'plate' => $plate];
                }
            }

            // 3. Wash Due Calculation (e.g. Last wash > 14 days ago)
            $lastWashStmt = $pdo->prepare("
                SELECT DATE(COALESCE(paid_at, created_at)) as last_wash
                FROM orders
                WHERE vehicle_id = ? AND status IN ('paid', 'completed')
                ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 1
            ");
            $lastWashStmt->execute([$vId]);
            $lastWashDate = $lastWashStmt->fetchColumn();

            $isWashDue = false;
            if ($lastWashDate) {
                $daysSinceWash = (int)round((strtotime($today) - strtotime((string)$lastWashDate)) / 86400);
                if ($daysSinceWash >= 14) {
                    $isWashDue = true;
                }
            }

            if ($isWashDue && $createQueueEvents) {
                $recentWashCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM crm_message_queue
                    WHERE vehicle_id = ? AND reminder_type = 'wash_due' AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ");
                $recentWashCheck->execute([$vId]);
                if ((int)$recentWashCheck->fetchColumn() === 0) {
                    $eventUid = 'MSG-WASH-' . $vId . '-' . time() . '-' . bin2hex(random_bytes(3));
                    $title = "Time for a Wash: {$plate}";
                    $body = "Hi {$v['customer_name']}, it has been {$daysSinceWash} days since your last wash for {$plate}. Bring your car to AquaGleam to keep it shining!";
                    $meta = json_encode([
                        'vehicle_id' => $vId,
                        'plate_number' => $plate,
                        'last_wash_date' => $lastWashDate,
                        'days_since_wash' => $daysSinceWash
                    ]);

                    $insStmt = $pdo->prepare("
                        INSERT INTO crm_message_queue (
                            event_uid, customer_id, vehicle_id, plate_number, customer_phone,
                            reminder_type, title, message_body, metadata_json, status, is_synced, created_at
                        ) VALUES (?, ?, ?, ?, ?, 'wash_due', ?, ?, ?, 'queued', 0, ?)
                    ");
                    $insStmt->execute([$eventUid, $custId, $vId, $plate, $phone, $title, $body, $meta, $now]);
                    $queuedWashCount++;
                    $eventsCreated[] = ['type' => 'wash_due', 'uid' => $eventUid, 'plate' => $plate];
                }
            }
        }

        return [
            'queued_oil_reminders' => $queuedOilCount,
            'queued_wash_reminders' => $queuedWashCount,
            'opted_out_skipped' => $optedOutSkipped,
            'events_created' => $eventsCreated
        ];
    }

    /**
     * Provide matching upsell offers for the current POS cart, vehicle type, and mileage.
     */
    public static function getMatchingUpsellOffers(PDO $pdo, array $cartServiceIds, array $cartProductIds, ?string $vehicleType = null, ?int $mileage = null): array {
        $rulesStmt = $pdo->query("SELECT * FROM upsell_rules WHERE is_active = 1");
        $allRules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

        $matchedOffers = [];

        foreach ($allRules as $rule) {
            $matched = false;

            // Condition A: Basic Wash on an SUV -> offer Premium Wash for $5
            if (!empty($rule['trigger_service_id']) && in_array((int)$rule['trigger_service_id'], $cartServiceIds, true)) {
                if (empty($rule['trigger_vehicle_type']) || $rule['trigger_vehicle_type'] === $vehicleType) {
                    $matched = true;
                }
            }

            // Condition B: Conventional oil on a car over 75,000 km -> recommend synthetic
            if (!empty($rule['trigger_min_mileage']) && $mileage !== null && $mileage >= (int)$rule['trigger_min_mileage']) {
                // If cart contains oil service or engine oil product
                if ($rule['trigger_category'] === 'engine_oil') {
                    // Check if cart has any product or service in engine_oil category
                    $hasOil = false;
                    if (!empty($cartProductIds)) {
                        $pIn = implode(',', array_map('intval', $cartProductIds));
                        $oilCnt = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE id IN ({$pIn}) AND category = 'engine_oil'")->fetchColumn();
                        if ($oilCnt > 0) $hasOil = true;
                    }
                    if (!$hasOil && in_array(4, $cartServiceIds, true)) { // Engine bay / oil service
                        $hasOil = true;
                    }
                    if ($hasOil) {
                        $matched = true;
                    }
                }
            }

            if ($matched) {
                $matchedOffers[] = [
                    'rule_id' => (int)$rule['id'],
                    'rule_uid' => $rule['rule_uid'],
                    'name' => $rule['name'],
                    'offer_service_id' => $rule['offer_service_id'] ? (int)$rule['offer_service_id'] : null,
                    'offer_product_id' => $rule['offer_product_id'] ? (int)$rule['offer_product_id'] : null,
                    'offer_price_usd' => $rule['offer_price_usd'] !== null ? (float)$rule['offer_price_usd'] : null,
                    'headline_en' => $rule['offer_headline_en'],
                    'headline_km' => $rule['offer_headline_km'],
                    'description_en' => $rule['offer_description_en'],
                    'description_km' => $rule['offer_description_km']
                ];
            }
        }

        return $matchedOffers;
    }

    /**
     * Track upsell conversions (shown, accepted, declined) with cashier details for reports.
     */
    public static function recordUpsellAction(
        PDO $pdo,
        int $ruleId,
        int $cashierId,
        string $status,
        ?int $orderId = null,
        ?int $customerId = null,
        ?int $vehicleId = null,
        ?string $plateNumber = null,
        ?string $reasonDeclined = null
    ): int {
        $ruleStmt = $pdo->prepare("SELECT name FROM upsell_rules WHERE id = ?");
        $ruleStmt->execute([$ruleId]);
        $ruleName = (string)$ruleStmt->fetchColumn() ?: "Upsell Rule #{$ruleId}";

        $cashierStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $cashierStmt->execute([$cashierId]);
        $cashierName = (string)$cashierStmt->fetchColumn() ?: "Cashier #{$cashierId}";

        $logUid = 'UPS-' . time() . '-' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO upsell_logs (
                log_uid, rule_id, rule_name, cashier_id, cashier_name,
                order_id, customer_id, vehicle_id, plate_number,
                status, reason_declined, offered_at, action_at, is_synced, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, 0, ?
            )
        ");
        $stmt->execute([
            $logUid, $ruleId, $ruleName, $cashierId, $cashierName,
            $orderId, $customerId, $vehicleId, $plateNumber,
            $status, $reasonDeclined, $now, $now, $now
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Get aggregated upsell conversion report for Owner and Manager.
     */
    public static function getUpsellReport(PDO $pdo, ?string $startDate = null, ?string $endDate = null): array {
        $where = "WHERE 1=1";
        $params = [];
        if ($startDate && $endDate) {
            $where .= " AND DATE(created_at) BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
        }

        // Summary counts
        $totalStmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_logged,
                SUM(CASE WHEN status = 'shown' THEN 1 ELSE 0 END) as total_shown,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as total_accepted,
                SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as total_declined
            FROM upsell_logs {$where}
        ");
        $totalStmt->execute($params);
        $summary = $totalStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_logged' => 0, 'total_shown' => 0, 'total_accepted' => 0, 'total_declined' => 0
        ];

        $shown = (int)$summary['total_shown'] + (int)$summary['total_accepted'] + (int)$summary['total_declined'];
        $accepted = (int)$summary['total_accepted'];
        $declined = (int)$summary['total_declined'];
        $conversionRate = ($shown > 0) ? round(($accepted / $shown) * 100, 1) : 0.0;

        // Breakdown by cashier
        $cashierStmt = $pdo->prepare("
            SELECT
                cashier_id, cashier_name,
                COUNT(*) as total_offers,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined_count,
                SUM(CASE WHEN status = 'shown' THEN 1 ELSE 0 END) as pending_count
            FROM upsell_logs {$where}
            GROUP BY cashier_id, cashier_name
            ORDER BY accepted_count DESC
        ");
        $cashierStmt->execute($params);
        $byCashier = $cashierStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($byCashier as &$c) {
            $cTotal = (int)$c['total_offers'];
            $cAcc = (int)$c['accepted_count'];
            $c['conversion_rate'] = ($cTotal > 0) ? round(($cAcc / $cTotal) * 100, 1) : 0.0;
        }

        // Breakdown by rule
        $ruleStmt = $pdo->prepare("
            SELECT
                rule_id, rule_name,
                COUNT(*) as total_offers,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined_count
            FROM upsell_logs {$where}
            GROUP BY rule_id, rule_name
            ORDER BY total_offers DESC
        ");
        $ruleStmt->execute($params);
        $byRule = $ruleStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($byRule as &$r) {
            $rTotal = (int)$r['total_offers'];
            $rAcc = (int)$r['accepted_count'];
            $r['conversion_rate'] = ($rTotal > 0) ? round(($rAcc / $rTotal) * 100, 1) : 0.0;
        }

        return [
            'total_shown' => $shown,
            'total_accepted' => $accepted,
            'total_declined' => $declined,
            'conversion_rate' => $conversionRate,
            'by_cashier' => $byCashier,
            'by_rule' => $byRule
        ];
    }
}
