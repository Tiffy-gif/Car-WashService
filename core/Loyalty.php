<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Helpers.php';

/**
 * Loyalty
 * Centralized service for customer loyalty points earning, redemption,
 * membership tier progression, transaction history, and reversals.
 */
class Loyalty {
    public const DEFAULT_EARN_RATE = 1; // 1 point per $1 paid
    public const DEFAULT_REDEEM_RATE = 10; // 10 points = $1 credit
    public const DEFAULT_GOLD_MIN = 200; // 200+ lifetime points
    public const DEFAULT_PLATINUM_MIN = 500; // 500+ lifetime points
    public const DEFAULT_STANDARD_MULT = 1.0;
    public const DEFAULT_GOLD_MULT = 1.5;
    public const DEFAULT_PLATINUM_MULT = 2.0;
    public const DEFAULT_GOLD_WAX_DISCOUNT = 10; // 10%
    public const DEFAULT_PLATINUM_WAX_DISCOUNT = 15; // 15%

    public static function getEarnRate(): int {
        return (int)get_setting('loyalty_earn_rate', (string)self::DEFAULT_EARN_RATE);
    }

    public static function getRedeemRate(): int {
        return (int)get_setting('loyalty_redeem_rate', (string)self::DEFAULT_REDEEM_RATE);
    }

    /**
     * Get loyalty settings with defaults
     */
    public static function getSettings(): array {
        return [
            'earn_rate' => self::getEarnRate(),
            'redeem_rate' => self::getRedeemRate(),
            'gold_min' => (int)get_setting('loyalty_tier_gold_min', (string)self::DEFAULT_GOLD_MIN),
            'platinum_min' => (int)get_setting('loyalty_tier_platinum_min', (string)self::DEFAULT_PLATINUM_MIN),
            'standard_multiplier' => (float)get_setting('loyalty_multiplier_standard', (string)self::DEFAULT_STANDARD_MULT),
            'gold_multiplier' => (float)get_setting('loyalty_multiplier_gold', (string)self::DEFAULT_GOLD_MULT),
            'platinum_multiplier' => (float)get_setting('loyalty_multiplier_platinum', (string)self::DEFAULT_PLATINUM_MULT),
            'gold_wax_discount' => (int)get_setting('loyalty_wax_discount_gold', (string)self::DEFAULT_GOLD_WAX_DISCOUNT),
            'platinum_wax_discount' => (int)get_setting('loyalty_wax_discount_platinum', (string)self::DEFAULT_PLATINUM_WAX_DISCOUNT),
        ];
    }

    /**
     * Get tier definitions, thresholds, multipliers, and dynamic benefit descriptions
     */
    public static function getTiersConfig(): array {
        $s = self::getSettings();
        $config = [
            'standard' => [
                'name' => 'Standard',
                'name_km' => 'កម្រិតស្តង់ដារ',
                'min_points' => 0,
                'multiplier' => $s['standard_multiplier'],
                'wax_discount' => 0,
                'wax_discount_percent' => 0,
                'badge_class' => 'badge-slate',
                'badge_color' => '#64748b',
                'benefits_en' => [
                    "{$s['earn_rate']} point earned per $1 spent ({$s['standard_multiplier']}x)",
                    "Redeem {$s['redeem_rate']} points for $1 cash discount",
                    "Free tire shine reward every 50 points"
                ],
                'benefits_km' => [
                    "ទទួលបាន {$s['earn_rate']} ពិន្ទុរាល់ការចំណាយ $1 ({$s['standard_multiplier']}x)",
                    "ប្រើ {$s['redeem_rate']} ពិន្ទុ ដូរបានការបញ្ចុះតម្លៃ $1",
                    "កាដូថែមជូតកង់ឥតគិតថ្លៃរាល់ 50 ពិន្ទុ"
                ]
            ],
            'gold' => [
                'name' => 'Gold VIP',
                'name_km' => 'សមាជិកមាស (Gold)',
                'min_points' => $s['gold_min'],
                'multiplier' => $s['gold_multiplier'],
                'wax_discount' => $s['gold_wax_discount'],
                'wax_discount_percent' => $s['gold_wax_discount'],
                'badge_class' => 'badge-amber',
                'badge_color' => '#d97706',
                'benefits_en' => [
                    "{$s['gold_multiplier']}x points on all services, repairs & products",
                    "{$s['gold_wax_discount']}% instant discount on Hydrophobic Wax & Polish",
                    "Redeem {$s['redeem_rate']} points for $1 cash discount",
                    "Free Exterior Wash reward every 100 points"
                ],
                'benefits_km' => [
                    "ទទួលបានពិន្ទុគុណនឹង {$s['gold_multiplier']}x រាល់សេវាកម្ម និងទំនិញ",
                    "បញ្ចុះតម្លៃពិសេស {$s['gold_wax_discount']}% លើសេវាកម្មប៉ូលា Wax",
                    "ប្រើ {$s['redeem_rate']} ពិន្ទុ ដូរបានការបញ្ចុះតម្លៃ $1",
                    "កាដូលាងរថយន្តខាងក្រៅឥតគិតថ្លៃរាល់ 100 ពិន្ទុ"
                ]
            ],
            'platinum' => [
                'name' => 'Platinum Fleet',
                'name_km' => 'សមាជិកផ្លាទីន (Platinum)',
                'min_points' => $s['platinum_min'],
                'multiplier' => $s['platinum_multiplier'],
                'wax_discount' => $s['platinum_wax_discount'],
                'wax_discount_percent' => $s['platinum_wax_discount'],
                'badge_class' => 'badge-primary',
                'badge_color' => '#0284c7',
                'benefits_en' => [
                    "{$s['platinum_multiplier']}x points on all services, repairs & products",
                    "{$s['platinum_wax_discount']}% instant discount on Hydrophobic Wax & Polish",
                    "Priority Bay & VIP Express Service",
                    "Free Full Detailing reward every 200 points"
                ],
                'benefits_km' => [
                    "ទទួលបានពិន្ទុគុណនឹង {$s['platinum_multiplier']}x រាល់សេវាកម្ម និងទំនិញ",
                    "បញ្ចុះតម្លៃពិសេស {$s['platinum_wax_discount']}% លើសេវាកម្មប៉ូលា Wax",
                    "ទទួលបានអាទិភាពចូលលាងរហ័ស",
                    "កាដូកញ្ចប់លាងសម្អាតធំឥតគិតថ្លៃរាល់ 200 ពិន្ទុ"
                ]
            ]
        ];

        // Also add capitalized aliases for backward-compatibility
        $config['Standard'] = $config['standard'];
        $config['Gold'] = $config['gold'];
        $config['Platinum'] = $config['platinum'];

        return $config;
    }

    /**
     * Determine tier name given lifetime earned points
     */
    public static function determineTier(int $lifetimePoints): string {
        $s = self::getSettings();
        if ($lifetimePoints >= $s['platinum_min']) {
            return 'Platinum';
        }
        if ($lifetimePoints >= $s['gold_min']) {
            return 'Gold';
        }
        return 'Standard';
    }

    /**
     * Get multiplier and tier details for a customer
     */
    public static function getCustomerTierInfo(?PDO $pdo, int $customerId): array {
        if (!$pdo) $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT loyalty_points, lifetime_points, membership_level FROM customers WHERE id = ?");
        $stmt->execute([$customerId]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            return [
                'tier' => 'Standard',
                'multiplier' => 1.0,
                'wax_discount_percent' => 0,
                'current_points' => 0,
                'lifetime_points' => 0
            ];
        }

        $lifetime = (int)($cust['lifetime_points'] ?? $cust['loyalty_points'] ?? 0);
        $tier = self::determineTier($lifetime);
        $tiers = self::getTiersConfig();
        $tierConfig = $tiers[$tier] ?? $tiers['Standard'];

        return [
            'tier' => $tier,
            'multiplier' => (float)$tierConfig['multiplier'],
            'wax_discount_percent' => (int)$tierConfig['wax_discount_percent'],
            'current_points' => (int)($cust['loyalty_points'] ?? 0),
            'lifetime_points' => $lifetime
        ];
    }

    /**
     * Calculate points that would be earned for a given paid dollar amount and multiplier or tier string
     * Whole dollars paid only.
     */
    public static function calculatePointsToEarn(float $paidUsd, float $multiplier): int {
        if ($paidUsd <= 0 || $multiplier <= 0) {
            return 0;
        }
        $wholeDollars = (int)floor($paidUsd);
        return (int)floor($wholeDollars * $multiplier);
    }

    /**
     * Alias supporting tier string or numeric multiplier
     */
    public static function calculatePointsToAward(float $paidUsd, string|float $tierOrMultiplier): int {
        if (is_numeric($tierOrMultiplier)) {
            return self::calculatePointsToEarn($paidUsd, (float)$tierOrMultiplier);
        }
        $tiers = self::getTiersConfig();
        $key = strtolower(trim($tierOrMultiplier));
        $mult = (float)($tiers[$key]['multiplier'] ?? 1.0);
        return self::calculatePointsToEarn($paidUsd, $mult);
    }

    /**
     * Calculate cash credit discount for points (10 points = $1 credit)
     */
    public static function calculateCreditForPoints(int $points): float {
        if ($points <= 0) return 0.00;
        $s = self::getSettings();
        $rate = max(1, $s['redeem_rate']);
        return round($points / $rate, 2);
    }

    /**
     * Calculate points required for a cash credit amount
     */
    public static function calculatePointsForCredit(float $creditUsd): int {
        if ($creditUsd <= 0) return 0;
        $s = self::getSettings();
        $rate = max(1, $s['redeem_rate']);
        return (int)ceil($creditUsd * $rate);
    }

    /**
     * Award earned points when an order becomes paid.
     * Can be called as awardEarnedPoints(int $orderId, ?int $userId = null, ?PDO $pdo = null)
     * or awardEarnedPoints(PDO $pdo, int $orderId, ?int $userId = null)
     * Idempotent: Never awards points twice for the same order.
     * Walk-in customers with no customer record earn nothing.
     */
    public static function awardEarnedPoints(mixed $arg1, mixed $arg2 = null, mixed $arg3 = null): int {
        if ($arg1 instanceof PDO) {
            $pdo = $arg1;
            $orderId = (int)$arg2;
            $userId = !empty($arg3) ? (int)$arg3 : null;
        } else {
            $orderId = (int)$arg1;
            $userId = !empty($arg2) ? (int)$arg2 : null;
            $pdo = ($arg3 instanceof PDO) ? $arg3 : Database::getInstance();
        }

        $stmt = $pdo->prepare("SELECT id, order_number, customer_id, total_usd, status, payment_status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return 0;
        }

        $customerId = !empty($order['customer_id']) ? (int)$order['customer_id'] : 0;
        if ($customerId <= 0) {
            return 0; // Walk-in customer earns nothing
        }

        // Check if points already awarded for this order
        $chkStmt = $pdo->prepare("SELECT id, points FROM loyalty_transactions WHERE order_id = ? AND type = 'earn'");
        $chkStmt->execute([$orderId]);
        $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return 0; // Already awarded! Idempotent replay returns 0 newly awarded points.
        }

        // Get customer tier & multiplier
        $tierInfo = self::getCustomerTierInfo($pdo, $customerId);
        $points = self::calculatePointsToEarn((float)$order['total_usd'], (float)$tierInfo['multiplier']);

        if ($points <= 0) {
            return 0;
        }

        // Fetch customer current balance and lifetime points
        $cStmt = $pdo->prepare("SELECT loyalty_points, lifetime_points FROM customers WHERE id = ?");
        $cStmt->execute([$customerId]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            return 0;
        }

        $currentBal = (int)($cust['loyalty_points'] ?? 0);
        $newBal = $currentBal + $points;
        $currentLifetime = (int)($cust['lifetime_points'] ?? $currentBal);
        $newLifetime = $currentLifetime + $points;
        $newTier = self::determineTier($newLifetime);

        // Update customer balance and tier (store lowercase in DB for consistency)
        $updCust = $pdo->prepare("UPDATE customers SET loyalty_points = ?, lifetime_points = ?, membership_level = ? WHERE id = ?");
        $updCust->execute([$newBal, $newLifetime, strtolower($newTier), $customerId]);

        // Insert transaction log
        $note = "Earned {$points} pts from Order #{$order['order_number']} (" . formatUSD((float)$order['total_usd']) . " @ {$tierInfo['multiplier']}x)";
        $insTx = $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, order_id, type, points, balance_after, note, created_by, created_at) VALUES (?, ?, 'earn', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $insTx->execute([$customerId, $orderId, $points, $newBal, $note, $userId]);

        // Update order record
        $updOrd = $pdo->prepare("UPDATE orders SET points_earned = ? WHERE id = ?");
        $updOrd->execute([$points, $orderId]);

        return $points;
    }

    /**
     * Redeem customer points for an order.
     * Can be called as:
     *   redeemPoints(int $customerId, int $orderId, int $pointsToRedeem, ?float $maxAllowedUsd = null, ?int $userId = null)
     *   redeemPoints(PDO $pdo, int $customerId, int $orderId, int $pointsToRedeem, ?float $maxAllowedUsd = null, ?int $userId = null)
     */
    public static function redeemPoints(mixed ...$args): array {
        $pdo = null;
        $idx = 0;
        if (isset($args[0]) && $args[0] instanceof PDO) {
            $pdo = $args[0];
            $idx = 1;
        } else {
            $pdo = Database::getInstance();
        }

        $argA = isset($args[$idx]) ? (int)$args[$idx] : 0;
        $argB = isset($args[$idx + 1]) ? (int)$args[$idx + 1] : 0;
        $pointsToRedeem = isset($args[$idx + 2]) ? (int)$args[$idx + 2] : 0;
        $maxAllowedOrUserId = $args[$idx + 3] ?? null;
        $userId = $args[$idx + 4] ?? null;

        // Disambiguate customerId vs orderId
        $customerId = $argA;
        $orderId = $argB;

        $oStmt = $pdo->prepare("SELECT id, order_number, customer_id, subtotal_usd, discount_usd, total_usd FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            // Check if argA was orderId and argB was customerId
            $oStmt->execute([$argA]);
            $orderCandidate = $oStmt->fetch(PDO::FETCH_ASSOC);
            if ($orderCandidate) {
                $order = $orderCandidate;
                $orderId = $argA;
                $customerId = $argB;
            }
        }

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found', 'points_redeemed' => 0, 'discount_usd' => 0.0];
        }

        if ($customerId <= 0) {
            $customerId = !empty($order['customer_id']) ? (int)$order['customer_id'] : 0;
        }

        if ($customerId <= 0 || $pointsToRedeem <= 0) {
            return ['success' => false, 'error' => 'Invalid customer or points amount', 'points_redeemed' => 0, 'discount_usd' => 0.0];
        }

        $maxUsdAllowed = null;
        if (is_numeric($maxAllowedOrUserId) && $maxAllowedOrUserId !== null) {
            $maxUsdAllowed = (float)$maxAllowedOrUserId;
        } elseif ($userId === null && is_numeric($maxAllowedOrUserId)) {
            $userId = (int)$maxAllowedOrUserId;
        }

        // Check if points already redeemed on this order
        $chkStmt = $pdo->prepare("SELECT id, points FROM loyalty_transactions WHERE order_id = ? AND type = 'redeem'");
        $chkStmt->execute([$orderId]);
        if ($chkStmt->fetch()) {
            return ['success' => false, 'error' => 'Points have already been redeemed for this order', 'points_redeemed' => 0, 'discount_usd' => 0.0];
        }

        // Check customer balance with row lock
        $cStmt = $pdo->prepare("SELECT loyalty_points FROM customers WHERE id = ? FOR UPDATE");
        $cStmt->execute([$customerId]);
        $currentBal = (int)$cStmt->fetchColumn();

        if ($pointsToRedeem > $currentBal) {
            return [
                'success' => false,
                'error' => "Cannot redeem {$pointsToRedeem} points. Customer only has {$currentBal} points available.",
                'points_redeemed' => 0,
                'discount_usd' => 0.0
            ];
        }

        if ($pointsToRedeem <= 0) {
            return ['success' => false, 'error' => "Insufficient points balance (available: {$currentBal} pts)", 'points_redeemed' => 0, 'discount_usd' => 0.0];
        }

        $creditUsd = self::calculateCreditForPoints($pointsToRedeem);
        $orderSubtotalUsd = (float)($order['subtotal_usd'] ?? $order['total_usd']);
        $maxAllowed = ($maxUsdAllowed !== null && $maxUsdAllowed > 0) ? min($orderSubtotalUsd, $maxUsdAllowed) : $orderSubtotalUsd;

        if ($creditUsd > $maxAllowed) {
            $s = self::getSettings();
            $rate = max(1, $s['redeem_rate']);
            $pointsToRedeem = (int)floor($maxAllowed * $rate);
            if ($pointsToRedeem > $currentBal) {
                $pointsToRedeem = $currentBal;
            }
            $creditUsd = self::calculateCreditForPoints($pointsToRedeem);
        }

        if ($pointsToRedeem <= 0 || $creditUsd <= 0) {
            return ['success' => false, 'error' => 'Redeemable value is zero', 'points_redeemed' => 0, 'discount_usd' => 0.0];
        }

        $newBal = max(0, $currentBal - $pointsToRedeem);
        $updCust = $pdo->prepare("UPDATE customers SET loyalty_points = ? WHERE id = ?");
        $updCust->execute([$newBal, $customerId]);

        // Insert transaction log
        $note = "Redeemed {$pointsToRedeem} pts for " . formatUSD($creditUsd) . " discount on Order #{$order['order_number']}";
        $insTx = $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, order_id, type, points, balance_after, note, created_by, created_at) VALUES (?, ?, 'redeem', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $insTx->execute([$customerId, $orderId, -$pointsToRedeem, $newBal, $note, $userId]);

        // Update order record
        $updOrd = $pdo->prepare("UPDATE orders SET points_redeemed = ?, points_discount_usd = ? WHERE id = ?");
        $updOrd->execute([$pointsToRedeem, $creditUsd, $orderId]);

        return [
            'success' => true,
            'points_redeemed' => $pointsToRedeem,
            'discount_usd' => $creditUsd,
            'new_balance' => $newBal,
            'balance_after' => $newBal
        ];
    }

    /**
     * Reverse earned points and refund redeemed points if an order is cancelled/voided.
     * Can be called as:
     *   reversePointsForOrder(int $orderId, ?int $userId = null, string $reason = 'Order cancelled')
     *   reversePointsForOrder(PDO $pdo, int $orderId, ?int $userId = null, string $reason = 'Order cancelled')
     */
    public static function reversePointsForOrder(mixed ...$args): array {
        $pdo = null;
        $idx = 0;
        if (isset($args[0]) && $args[0] instanceof PDO) {
            $pdo = $args[0];
            $idx = 1;
        } else {
            $pdo = Database::getInstance();
        }

        $orderId = isset($args[$idx]) ? (int)$args[$idx] : 0;
        $argB = $args[$idx + 1] ?? null;
        $argC = $args[$idx + 2] ?? null;

        $userId = null;
        $reason = 'Order cancelled';

        if (is_string($argB)) {
            $reason = $argB;
            $userId = !empty($argC) ? (int)$argC : null;
        } else {
            $userId = !empty($argB) ? (int)$argB : null;
            $reason = is_string($argC) ? $argC : 'Order cancelled';
        }

        $oStmt = $pdo->prepare("SELECT id, order_number, customer_id FROM orders WHERE id = ?");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }

        $customerId = !empty($order['customer_id']) ? (int)$order['customer_id'] : 0;
        if ($customerId <= 0) {
            return ['success' => true, 'reversed_earn' => 0, 'refunded_redeem' => 0];
        }

        // Check if reversal already recorded
        $chkRev = $pdo->prepare("SELECT id FROM loyalty_transactions WHERE order_id = ? AND type = 'reversal'");
        $chkRev->execute([$orderId]);
        if ($chkRev->fetch()) {
            return ['success' => true, 'already_reversed' => true];
        }

        $cStmt = $pdo->prepare("SELECT loyalty_points, lifetime_points FROM customers WHERE id = ? FOR UPDATE");
        $cStmt->execute([$customerId]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            return ['success' => false, 'error' => 'Customer not found'];
        }

        $currentBal = (int)($cust['loyalty_points'] ?? 0);
        $currentLifetime = (int)($cust['lifetime_points'] ?? $currentBal);

        $reversedEarn = 0;
        $refundedRedeem = 0;

        // 1. Reverse earned points if awarded
        $earnStmt = $pdo->prepare("SELECT points FROM loyalty_transactions WHERE order_id = ? AND type = 'earn'");
        $earnStmt->execute([$orderId]);
        $earnedRow = $earnStmt->fetch(PDO::FETCH_ASSOC);
        if ($earnedRow) {
            $reversedEarn = (int)$earnedRow['points'];
        } else {
            // Fallback check order table points_earned
            $oPtsEarned = $pdo->prepare("SELECT points_earned FROM orders WHERE id = ?");
            $oPtsEarned->execute([$orderId]);
            $reversedEarn = (int)$oPtsEarned->fetchColumn();
        }

        if ($reversedEarn > 0) {
            $currentBal = max(0, $currentBal - $reversedEarn);
            $currentLifetime = max(0, $currentLifetime - $reversedEarn);

            $note = "Reversal of {$reversedEarn} earned pts from cancelled Order #{$order['order_number']}: {$reason}";
            $insRev = $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, order_id, type, points, balance_after, note, created_by, created_at) VALUES (?, ?, 'reversal', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $insRev->execute([$customerId, $orderId, -$reversedEarn, $currentBal, $note, $userId]);
        }

        // 2. Refund redeemed points if spent
        $redStmt = $pdo->prepare("SELECT points FROM loyalty_transactions WHERE order_id = ? AND type = 'redeem'");
        $redStmt->execute([$orderId]);
        $redeemRow = $redStmt->fetch(PDO::FETCH_ASSOC);
        if ($redeemRow) {
            $refundedRedeem = abs((int)$redeemRow['points']);
        } else {
            // Fallback check order table points_redeemed
            $oPtsRedeemed = $pdo->prepare("SELECT points_redeemed FROM orders WHERE id = ?");
            $oPtsRedeemed->execute([$orderId]);
            $refundedRedeem = (int)$oPtsRedeemed->fetchColumn();
        }

        if ($refundedRedeem > 0) {
            $currentBal = $currentBal + $refundedRedeem;

            $note = "Refund of {$refundedRedeem} redeemed pts from cancelled Order #{$order['order_number']}: {$reason}";
            $insRef = $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, order_id, type, points, balance_after, note, created_by, created_at) VALUES (?, ?, 'reversal', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $insRef->execute([$customerId, $orderId, $refundedRedeem, $currentBal, $note, $userId]);
        }

        if ($reversedEarn > 0 || $refundedRedeem > 0) {
            $newTier = self::determineTier($currentLifetime);
            $updCust = $pdo->prepare("UPDATE customers SET loyalty_points = ?, lifetime_points = ?, membership_level = ? WHERE id = ?");
            $updCust->execute([$currentBal, $currentLifetime, strtolower($newTier), $customerId]);
            $pdo->prepare("UPDATE orders SET points_earned = 0 WHERE id = ?")->execute([$orderId]);
        }

        return [
            'success' => true,
            'reversed_earn' => $reversedEarn,
            'refunded_redeem' => $refundedRedeem,
            'new_balance' => $currentBal,
            'balance_after' => $currentBal
        ];
    }

    /**
     * Manual points adjustment (Owner only, with reason)
     * Can be called as:
     *   manualAdjust(int $customerId, int $pointsDiff, string $reason, ?int $userId = null)
     *   manualAdjust(PDO $pdo, int $customerId, int $pointsDiff, string $reason, ?int $userId = null)
     */
    public static function manualAdjust(mixed ...$args): array {
        $pdo = null;
        $idx = 0;
        if (isset($args[0]) && $args[0] instanceof PDO) {
            $pdo = $args[0];
            $idx = 1;
        } else {
            $pdo = Database::getInstance();
        }

        $customerId = isset($args[$idx]) ? (int)$args[$idx] : 0;
        $pointsDiff = isset($args[$idx + 1]) ? (int)$args[$idx + 1] : 0;
        $reason = isset($args[$idx + 2]) ? (string)$args[$idx + 2] : '';
        $userId = isset($args[$idx + 3]) && !empty($args[$idx + 3]) ? (int)$args[$idx + 3] : null;

        if ($customerId <= 0 || $pointsDiff === 0 || trim($reason) === '') {
            return ['success' => false, 'error' => 'Customer ID, non-zero points difference, and adjustment reason are required'];
        }

        $cStmt = $pdo->prepare("SELECT loyalty_points, lifetime_points FROM customers WHERE id = ?");
        $cStmt->execute([$customerId]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            return ['success' => false, 'error' => 'Customer not found'];
        }

        $currentBal = (int)($cust['loyalty_points'] ?? 0);
        if ($pointsDiff < 0 && abs($pointsDiff) > $currentBal) {
            return [
                'success' => false,
                'error' => "Cannot deduct " . abs($pointsDiff) . " points. Customer only has {$currentBal} points available."
            ];
        }
        $newBal = max(0, $currentBal + $pointsDiff);
        $currentLifetime = (int)($cust['lifetime_points'] ?? $currentBal);
        $newLifetime = ($pointsDiff > 0) ? ($currentLifetime + $pointsDiff) : $currentLifetime;
        $newTier = self::determineTier($newLifetime);

        $updCust = $pdo->prepare("UPDATE customers SET loyalty_points = ?, lifetime_points = ?, membership_level = ? WHERE id = ?");
        $updCust->execute([$newBal, $newLifetime, strtolower($newTier), $customerId]);

        $sign = ($pointsDiff > 0) ? '+' : '';
        $note = "Manual adjustment ({$sign}{$pointsDiff} pts): {$reason}";
        $insTx = $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, order_id, type, points, balance_after, note, created_by, created_at) VALUES (?, NULL, 'adjust', ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $insTx->execute([$customerId, $pointsDiff, $newBal, $note, $userId]);

        return [
            'success' => true,
            'points_adjusted' => $pointsDiff,
            'new_balance' => $newBal,
            'balance_after' => $newBal,
            'new_tier' => $newTier,
            'tier' => $newTier,
            'membership_level' => strtolower($newTier)
        ];
    }

    /**
     * Get transaction history for a customer
     * Can be called as:
     *   getCustomerHistory(int $customerId, int $limit = 50)
     *   getCustomerHistory(PDO $pdo, int $customerId, int $limit = 50)
     */
    public static function getCustomerHistory(mixed ...$args): array {
        $pdo = null;
        $idx = 0;
        if (isset($args[0]) && $args[0] instanceof PDO) {
            $pdo = $args[0];
            $idx = 1;
        } else {
            $pdo = Database::getInstance();
        }

        $customerId = isset($args[$idx]) ? (int)$args[$idx] : 0;
        $limit = isset($args[$idx + 1]) && is_numeric($args[$idx + 1]) ? (int)$args[$idx + 1] : 50;

        $stmt = $pdo->prepare("
            SELECT lt.*, u.full_name as creator_name, u.full_name as staff_name, o.order_number, o.order_number as order_code
            FROM loyalty_transactions lt
            LEFT JOIN users u ON lt.created_by = u.id
            LEFT JOIN orders o ON lt.order_id = o.id
            WHERE lt.customer_id = ?
            ORDER BY lt.id DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
