<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Loyalty.php';

$pdo = Database::getInstance();
echo "=== Running Loyalty & Membership Test Suite ===\n\n";

$allPassed = true;

function testAssert($description, $condition) {
    global $allPassed;
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        $allPassed = false;
    }
}

// 1. Settings & Tiers verification
$earnRate = Loyalty::getEarnRate();
$redeemRate = Loyalty::getRedeemRate();
$tiers = Loyalty::getTiersConfig();

testAssert("Earn rate default is 1 pt per $1", $earnRate === 1);
testAssert("Redeem rate default is 10 pts per $1 credit", $redeemRate === 10);
testAssert("Standard tier min points is 0 and multiplier is 1.0", $tiers['standard']['min_points'] === 0 && (float)$tiers['standard']['multiplier'] === 1.0);
testAssert("Gold tier min points is 200, multiplier is 1.5, wax discount is 10%", $tiers['gold']['min_points'] === 200 && (float)$tiers['gold']['multiplier'] === 1.5 && (float)$tiers['gold']['wax_discount'] === 10.0);
testAssert("Platinum tier min points is 500, multiplier is 2.0, wax discount is 15%", $tiers['platinum']['min_points'] === 500 && (float)$tiers['platinum']['multiplier'] === 2.0 && (float)$tiers['platinum']['wax_discount'] === 15.0);

// 2. Test Points Calculation & Multipliers
// Standard customer ($25 order) => floor(25 * 1.0) = 25 pts
testAssert("Standard $25 order earns 25 pts", Loyalty::calculatePointsToAward(25.75, 'standard') === 25);
// Gold customer ($25 order) => floor(25 * 1.5) = 37 pts
testAssert("Gold $25 order earns 37 pts", Loyalty::calculatePointsToAward(25.00, 'gold') === 37);
// Platinum customer ($25 order) => floor(25 * 2.0) = 50 pts
testAssert("Platinum $25 order earns 50 pts", Loyalty::calculatePointsToAward(25.00, 'platinum') === 50);

// 3. Test Customer Auto Tier & Points Lifecycle
// Create a temporary test customer
$testName = "Loyalty Test Customer " . time();
$pdo->prepare("INSERT INTO customers (name, phone, loyalty_points, lifetime_points, membership_level, status) VALUES (?, '099000111', 0, 0, 'standard', 'active')")->execute([$testName]);
$testCustId = (int)$pdo->lastInsertId();

testAssert("Created test customer with ID #$testCustId", $testCustId > 0);

// Create a test order 1 ($150 total, paid)
$ordNum1 = "TEST-LOY-1-" . time();
$pdo->prepare("
    INSERT INTO orders (order_number, customer_id, customer_name, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, status, payment_status, payment_method)
    VALUES (?, ?, ?, '2TEST-1111', 'small_car', 150.00, 0.00, 150.00, 615000, 'paid', 'paid', 'cash')
")->execute([$ordNum1, $testCustId, $testName]);
$testOrderId1 = (int)$pdo->lastInsertId();

// Award points for Order 1
$ptsAwarded1 = Loyalty::awardEarnedPoints($testOrderId1, 1);
testAssert("Order 1 ($150) awarded 150 pts", $ptsAwarded1 === 150);

// Test idempotency: awarding again should award 0
$ptsAwardedDup = Loyalty::awardEarnedPoints($testOrderId1, 1);
testAssert("Duplicate award on Order 1 returns 0 (idempotent)", $ptsAwardedDup === 0);

// Check customer tier: 150 lifetime points is still Standard
$tierCheck1 = $pdo->query("SELECT loyalty_points, lifetime_points, membership_level FROM customers WHERE id = $testCustId")->fetch();
testAssert("Customer balance is 150 pts and tier is Standard", (int)$tierCheck1['loyalty_points'] === 150 && (int)$tierCheck1['lifetime_points'] === 150 && $tierCheck1['membership_level'] === 'standard');

// Create Order 2 ($100 total, paid) -> Lifetime will cross 200 threshold!
$ordNum2 = "TEST-LOY-2-" . time();
$pdo->prepare("
    INSERT INTO orders (order_number, customer_id, customer_name, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, status, payment_status, payment_method)
    VALUES (?, ?, ?, '2TEST-1111', 'small_car', 100.00, 0.00, 100.00, 410000, 'paid', 'paid', 'cash')
")->execute([$ordNum2, $testCustId, $testName]);
$testOrderId2 = (int)$pdo->lastInsertId();

$ptsAwarded2 = Loyalty::awardEarnedPoints($testOrderId2, 1);
testAssert("Order 2 ($100) awarded 100 pts", $ptsAwarded2 === 100);

// Check auto-promotion to Gold!
$tierCheck2 = $pdo->query("SELECT loyalty_points, lifetime_points, membership_level FROM customers WHERE id = $testCustId")->fetch();
testAssert("Customer promoted to Gold (250 lifetime pts)", (int)$tierCheck2['loyalty_points'] === 250 && (int)$tierCheck2['lifetime_points'] === 250 && $tierCheck2['membership_level'] === 'gold');

// Create Order 3 ($50) as Gold member -> multiplier is 1.5x => floor(50 * 1.5) = 75 pts!
$ordNum3 = "TEST-LOY-3-" . time();
$pdo->prepare("
    INSERT INTO orders (order_number, customer_id, customer_name, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, status, payment_status, payment_method)
    VALUES (?, ?, ?, '2TEST-1111', 'small_car', 50.00, 0.00, 50.00, 205000, 'paid', 'paid', 'cash')
")->execute([$ordNum3, $testCustId, $testName]);
$testOrderId3 = (int)$pdo->lastInsertId();

$ptsAwarded3 = Loyalty::awardEarnedPoints($testOrderId3, 1);
testAssert("Order 3 ($50) with Gold 1.5x multiplier awarded 75 pts", $ptsAwarded3 === 75);

// 4. Test Redemption
// Customer currently has 325 points. Redeem 50 points ($5 credit) on a new order
$ordNum4 = "TEST-LOY-4-" . time();
$pdo->prepare("
    INSERT INTO orders (order_number, customer_id, customer_name, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, status, payment_status, payment_method)
    VALUES (?, ?, ?, '2TEST-1111', 'small_car', 30.00, 0.00, 30.00, 123000, 'unpaid', 'unpaid', 'cash')
")->execute([$ordNum4, $testCustId, $testName]);
$testOrderId4 = (int)$pdo->lastInsertId();

$redResult = Loyalty::redeemPoints($testCustId, $testOrderId4, 50, 30.00, 1);
testAssert("Redeemed 50 pts for $5.00 discount", $redResult['points_redeemed'] === 50 && (float)$redResult['discount_usd'] === 5.00);

// Check balance after redeem: 325 - 50 = 275 pts, but lifetime_points remains 325 (tier preserved!)
$tierCheck4 = $pdo->query("SELECT loyalty_points, lifetime_points, membership_level FROM customers WHERE id = $testCustId")->fetch();
testAssert("Redeem reduced balance to 275 pts while lifetime remains 325 pts", (int)$tierCheck4['loyalty_points'] === 275 && (int)$tierCheck4['lifetime_points'] === 325 && $tierCheck4['membership_level'] === 'gold');

// 5. Test Cancellation Reversal
// Reverse Order 3 (Order 3 earned 75 points)
Loyalty::reversePointsForOrder($testOrderId3, 1, "Order cancelled test");
$tierCheckRev = $pdo->query("SELECT loyalty_points, lifetime_points FROM customers WHERE id = $testCustId")->fetch();
testAssert("Cancelled Order 3 clawed back 75 earned points (balance: 200 pts, lifetime: 250 pts)", (int)$tierCheckRev['loyalty_points'] === 200 && (int)$tierCheckRev['lifetime_points'] === 250);

// Reverse Order 4 (Order 4 had 50 redeemed points refunded)
Loyalty::reversePointsForOrder($testOrderId4, 1, "Order cancelled test");
$tierCheckRev2 = $pdo->query("SELECT loyalty_points, lifetime_points FROM customers WHERE id = $testCustId")->fetch();
testAssert("Cancelled Order 4 refunded 50 redeemed points back to customer (balance: 250 pts)", (int)$tierCheckRev2['loyalty_points'] === 250 && (int)$tierCheckRev2['lifetime_points'] === 250);

// 6. Test Manual Adjustment
$adjResult = Loyalty::manualAdjust($testCustId, 300, "Loyalty bonus promotion", 1);
testAssert("Manual adjust +300 pts promoted customer to Platinum (lifetime 550 pts)", $adjResult['membership_level'] === 'platinum' && $adjResult['balance_after'] === 550);

// Test Manual Adjustment Overdraft Rejection
$badAdj = Loyalty::manualAdjust($testCustId, -999, "Attempted overdraft", 1);
testAssert("Manual adjust overdraft rejected (customer only has 550 pts)", $badAdj['success'] === false && strpos($badAdj['error'], 'Cannot deduct') !== false);

// 7. Test Capping Points Redemption to Max Order Amount
$ordNum5 = "TEST-LOY-5-" . time();
$pdo->prepare("
    INSERT INTO orders (order_number, customer_id, customer_name, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, status, payment_status, payment_method)
    VALUES (?, ?, ?, '2TEST-1111', 'small_car', 5.00, 0.00, 5.00, 20500, 'unpaid', 'unpaid', 'cash')
")->execute([$ordNum5, $testCustId, $testName]);
$testOrderId5 = (int)$pdo->lastInsertId();

// Customer wants to redeem 100 points ($10.00 credit) on a $5.00 order
$cappedRedeem = Loyalty::redeemPoints($testCustId, $testOrderId5, 100, 5.00, 1);
testAssert("Redemption capped to subtotal ($5.00 discount, 50 points redeemed)", $cappedRedeem['success'] === true && $cappedRedeem['points_redeemed'] === 50 && (float)$cappedRedeem['discount_usd'] === 5.00);

// Test cancellation clears points_earned on orders table
$ordEarnCheck = $pdo->prepare("SELECT points_earned FROM orders WHERE id = ?");
$ordEarnCheck->execute([$testOrderId3]);
testAssert("Cancelled order points_earned set to 0", (int)$ordEarnCheck->fetchColumn() === 0);

// 8. Verify Transaction History
$history = Loyalty::getCustomerHistory($testCustId);
echo "History count: " . count($history) . "\n";
foreach ($history as $h) {
    echo "  - Type: {$h['type']}, Points: {$h['points']}, Note: {$h['note']}\n";
}
testAssert("Customer history has 8 transaction rows logged", count($history) === 8);
testAssert("History row has order_number and staff_name aliases", isset($history[0]['order_number']) && isset($history[0]['order_code']) && isset($history[0]['staff_name']));

// Clean up test data
$pdo->prepare("DELETE FROM loyalty_transactions WHERE customer_id = ?")->execute([$testCustId]);
$pdo->prepare("DELETE FROM orders WHERE id IN (?, ?, ?, ?, ?)")->execute([$testOrderId1, $testOrderId2, $testOrderId3, $testOrderId4, $testOrderId5]);
$pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$testCustId]);

echo "\nTest cleanup complete.\n";

if ($allPassed) {
    echo "===========================================\n";
    echo " ALL LOYALTY & MEMBERSHIP TESTS PASSED! \n";
    echo "===========================================\n";
} else {
    echo "===========================================\n";
    echo " SOME TESTS FAILED! \n";
    echo "===========================================\n";
}
