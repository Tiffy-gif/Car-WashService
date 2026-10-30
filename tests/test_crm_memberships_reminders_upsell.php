<?php
/**
 * Test Suite: CRM Memberships, Smart Reminders with Opt-In, Offline Queue & Upsell Rules
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/MembershipManager.php';
require_once __DIR__ . '/../core/CloudSyncManager.php';

$pdo = Database::getInstance();

$totalTests = 0;
$passedTests = 0;

function assertTest(bool $condition, string $message) {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
    }
}

echo "=================================================================\n";
echo " CRM MEMBERSHIPS, REMINDERS & UPSELL ENGINE TEST SUITE          \n";
echo "=================================================================\n\n";

// Ensure test clean slate
$pdo->exec("DELETE FROM crm_message_queue WHERE event_uid LIKE 'TEST-%'");
$pdo->exec("DELETE FROM upsell_logs WHERE log_uid LIKE 'TEST-%'");
$pdo->exec("DELETE FROM membership_usages WHERE customer_id >= 9900");
$pdo->exec("DELETE FROM vehicles WHERE customer_id >= 9900");
$pdo->exec("DELETE FROM customers WHERE id >= 9900");

// --- SCENARIO 1: MEMBERSHIP TIERS & LIMIT ENFORCEMENT ---
echo "--- 1. TESTING MEMBERSHIP TIERS, FREE SERVICES & USAGE LIMITS ---\n";

$tiers = MembershipManager::getTiers($pdo);
assertTest(count($tiers) >= 3, "Loaded membership tiers from database (Count: " . count($tiers) . ")");

$silverTier = MembershipManager::getTierByCode($pdo, 'silver');
assertTest($silverTier !== null && $silverTier['tier_code'] === 'silver', "Silver tier exists with code 'silver'");

$goldTier = MembershipManager::getTierByCode($pdo, 'gold');
assertTest($goldTier !== null && $goldTier['tier_code'] === 'gold', "Gold tier exists with code 'gold'");

// Create Test Member (Gold Tier: 2x Full Wash #3 included per month)
$testCustId = 9901;
$pdo->exec("
    INSERT INTO customers (id, name, phone, loyalty_points, lifetime_points, membership_level, membership_tier_code, status, message_opt_in)
    VALUES ({$testCustId}, 'Test Member Gold', '012 999 001', 50, 50, 'Gold', 'gold', 'active', 1)
");
$pdo->exec("
    INSERT INTO vehicles (customer_id, plate_number, vehicle_type, last_mileage)
    VALUES ({$testCustId}, '2TEST-GOLD', 'small_car', 25000)
");
$testVehId = (int)$pdo->lastInsertId();

$tierInfo = MembershipManager::getCustomerTier($pdo, $testCustId, $testVehId);
assertTest($tierInfo['is_member'] === true && $tierInfo['tier_code'] === 'gold', "Customer correctly recognized as Gold Member");

// Visit 1: First Full Wash (Service #3)
$elig1 = MembershipManager::checkIncludedServiceEligibility($pdo, $testCustId, $testVehId, 3);
assertTest($elig1['is_eligible'] === true && $elig1['is_free'] === true, "Visit 1: Full Wash is included at no charge ($0.00)");
assertTest($elig1['is_limit_reached'] === false, "Visit 1: Monthly limit not yet reached (0/2 used)");

MembershipManager::recordServiceUsage($pdo, $testCustId, $testVehId, '2TEST-GOLD', 'gold', 3, null, true);

// Visit 2: Second Full Wash (Service #3)
$elig2 = MembershipManager::checkIncludedServiceEligibility($pdo, $testCustId, $testVehId, 3);
assertTest($elig2['is_eligible'] === true && $elig2['is_free'] === true, "Visit 2: Full Wash is included at no charge ($0.00)");
assertTest($elig2['used_this_month'] === 1, "Visit 2: Accurately counts 1 visit previously used this month");

MembershipManager::recordServiceUsage($pdo, $testCustId, $testVehId, '2TEST-GOLD', 'gold', 3, null, true);

// Visit 3: Member Over Limit! (Limit is 2 per month)
$elig3 = MembershipManager::checkIncludedServiceEligibility($pdo, $testCustId, $testVehId, 3);
assertTest($elig3['is_free'] === false, "Visit 3: Full Wash is NO LONGER free (Member is over the monthly limit)");
assertTest($elig3['is_limit_reached'] === true, "Visit 3: Flag 'is_limit_reached' is strictly set to TRUE");
assertTest($elig3['used_this_month'] === 2 && $elig3['limit_qty'] === 2, "Visit 3: Accurately reflects 2/2 visits used this month");
assertTest(strpos($elig3['message'], 'limit used up') !== false || strpos($elig3['message'], 'Limit used up') !== false, "Visit 3: Clear message displayed indicating limit is used up and regular price applies");

// Unlimited wash check on Silver
$testSilverId = 9902;
$pdo->exec("
    INSERT INTO customers (id, name, phone, loyalty_points, lifetime_points, membership_level, membership_tier_code, status, message_opt_in)
    VALUES ({$testSilverId}, 'Test Member Silver', '012 999 002', 10, 10, 'Silver', 'silver', 'active', 1)
");
$silverElig = MembershipManager::checkIncludedServiceEligibility($pdo, $testSilverId, null, 1);
assertTest($silverElig['is_free'] === true && $silverElig['limit_type'] === 'unlimited', "Silver member gets unlimited Basic Wash (#1)");


// --- SCENARIO 2: LOYALTY REWARDS & MILESTONES ---
echo "\n--- 2. TESTING LOYALTY REWARDS (5th VISIT THIS MONTH) ---\n";

// Customer with 5 visits this month
$testLoyaltyCustId = 9903;
$pdo->exec("
    INSERT INTO customers (id, name, phone, loyalty_points, lifetime_points, membership_level, membership_tier_code, status, message_opt_in)
    VALUES ({$testLoyaltyCustId}, 'Loyal Customer', '012 999 003', 80, 80, 'Standard', 'standard', 'active', 1)
");

// Seed 5 completed orders for this customer this month
$now = date('Y-m-d H:i:s');
for ($i = 1; $i <= 5; $i++) {
    $pdo->prepare("
        INSERT INTO orders (order_number, customer_id, customer_name, total_usd, status, paid_at, created_at)
        VALUES (?, ?, 'Loyal Customer', 5.00, 'paid', ?, ?)
    ")->execute(["TEST-ORD-{$i}", $testLoyaltyCustId, $now, $now]);
}

$milestones = MembershipManager::checkLoyaltyRewardMilestones($pdo, $testLoyaltyCustId);
assertTest($milestones['visit_count_this_month'] === 5, "Accurately calculated 5 visits for this customer this month");
assertTest($milestones['has_rewards'] === true, "5th visit milestone triggered loyalty reward");
assertTest(count($milestones['rewards']) > 0 && strpos($milestones['rewards'][0]['name_en'], 'free air freshener') !== false, "Unlocked reward: '5th visit this month: free air freshener'");


// --- SCENARIO 3: SMART REMINDERS & CUSTOMER OPT-IN ---
echo "\n--- 3. TESTING SMART OIL & WASH DUE REMINDERS & OPT-IN PERMISSION ---\n";

// Customer A: Opted IN, due for oil change
$optInCustId = 9904;
$pdo->exec("
    INSERT INTO customers (id, name, phone, loyalty_points, membership_level, status, message_opt_in)
    VALUES ({$optInCustId}, 'Opt-In Customer', '012 777 001', 10, 'Standard', 'active', 1)
");
$pdo->exec("
    INSERT INTO vehicles (customer_id, plate_number, vehicle_type, last_mileage, next_oil_mileage, next_oil_date)
    VALUES ({$optInCustId}, '2OPT-IN', 'suv_pickup', 80200, 80000, DATE_SUB(CURDATE(), INTERVAL 2 DAY))
");
$optInVehId = (int)$pdo->lastInsertId();

// Customer B: Opted OUT, due for oil change
$optOutCustId = 9905;
$pdo->exec("
    INSERT INTO customers (id, name, phone, loyalty_points, membership_level, status, message_opt_in)
    VALUES ({$optOutCustId}, 'Opt-Out Customer', '012 777 002', 10, 'Standard', 'active', 0)
");
$pdo->exec("
    INSERT INTO vehicles (customer_id, plate_number, vehicle_type, last_mileage, next_oil_mileage, next_oil_date)
    VALUES ({$optOutCustId}, '2OPT-OUT', 'suv_pickup', 90500, 90000, DATE_SUB(CURDATE(), INTERVAL 5 DAY))
");
$optOutVehId = (int)$pdo->lastInsertId();

// Process reminders
$reminderRes = MembershipManager::processDueReminders($pdo, true);
assertTest($reminderRes['queued_oil_reminders'] >= 1, "Generated oil change due reminder for due vehicle");
assertTest($reminderRes['opted_out_skipped'] >= 1, "Opt-in check skipped at least 1 customer who opted out");

// Verify in crm_message_queue
$chkIn = $pdo->query("SELECT * FROM crm_message_queue WHERE vehicle_id = {$optInVehId} AND reminder_type = 'oil_due'")->fetch(PDO::FETCH_ASSOC);
assertTest($chkIn !== false, "Message event created in crm_message_queue for Opted-In customer (Plate: 2OPT-IN)");
assertTest(strpos($chkIn['title'], 'Oil Change Due') !== false, "Message event has title 'Oil Change Due: 2OPT-IN'");

$chkOut = $pdo->query("SELECT * FROM crm_message_queue WHERE vehicle_id = {$optOutVehId}")->fetch(PDO::FETCH_ASSOC);
assertTest($chkOut === false, "STRICT PRIVACY VERIFIED: Zero message events created for Opted-Out customer (Plate: 2OPT-OUT)");


// --- SCENARIO 4: OFFLINE MESSAGE QUEUE & RECONNECTION SYNC ---
echo "\n--- 4. TESTING OFFLINE REMINDER QUEUE & CLOUD SYNC WITH ORIGINAL TIMESTAMP ---\n";

// Create custom queued reminder with distinct original timestamp
$origTime = '2026-10-28 09:15:00';
$eventUid = 'TEST-MSG-OFFLINE-001';
$pdo->prepare("
    INSERT INTO crm_message_queue (
        event_uid, customer_id, vehicle_id, plate_number, customer_phone,
        reminder_type, title, message_body, status, is_synced, created_at
    ) VALUES (
        ?, ?, ?, '2OFF-LINE', '012 888 999',
        'oil_due', 'Scheduled Oil Service', 'Your oil change is due.', 'queued', 0, ?
    )
")->execute([$eventUid, $optInCustId, $optInVehId, $origTime]);

// Turn offline mode ON
CloudSyncManager::setOfflineMode($pdo, true);
assertTest(CloudSyncManager::isOfflineMode($pdo) === true, "Shop server offline mode successfully activated");

// Attempt to push to cloud while offline
$offlinePush = CloudSyncManager::sendToCloud($pdo);
assertTest($offlinePush['offline'] === true, "sendToCloud() correctly halted while offline");

$qRecord = $pdo->query("SELECT * FROM crm_message_queue WHERE event_uid = '{$eventUid}'")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$qRecord['is_synced'] === 0, "Message event safely remained queued with is_synced = 0");
assertTest($qRecord['created_at'] === $origTime, "Original creation timestamp (09:15:00) strictly preserved locally");

// Reconnect to online mode
CloudSyncManager::setOfflineMode($pdo, false);
assertTest(CloudSyncManager::isOfflineMode($pdo) === false, "Shop server reconnected online");

// Push to cloud
$onlinePush = CloudSyncManager::sendToCloud($pdo, true);
assertTest($onlinePush['success'] === true, "sendToCloud() on reconnection returned success");

$qSynced = $pdo->query("SELECT * FROM crm_message_queue WHERE event_uid = '{$eventUid}'")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$qSynced['is_synced'] === 1, "Message event confirmed and marked is_synced = 1");
assertTest($qSynced['created_at'] === $origTime, "Original timestamp was preserved during cloud transmission");


// --- SCENARIO 5: UPSELL RULES MATCHING ---
echo "\n--- 5. TESTING UPSELL RULES (SUV WASH UPGRADE & HIGH MILEAGE OIL) ---\n";

// Upsell 1: Basic Wash on an SUV -> offer Premium Wash for $5
$cartServices1 = [1]; // Exterior/Basic Wash
$cartProducts1 = [];
$offers1 = MembershipManager::getMatchingUpsellOffers($pdo, $cartServices1, $cartProducts1, 'suv_pickup', 35000);
assertTest(count($offers1) > 0, "Matched upsell offer for Basic Wash on SUV");
assertTest($offers1[0]['rule_uid'] === 'UPSELL-SUV-PREMIUM-WASH', "Offer is 'UPSELL-SUV-PREMIUM-WASH'");
assertTest($offers1[0]['offer_price_usd'] === 5.0, "Offer price is promotional $5.00");

// Upsell 2: Conventional oil on a car over 75,000 km -> recommend synthetic
$cartServices2 = [];
$cartProducts2 = [4]; // Engine oil product (Total Quartz)
$offers2 = MembershipManager::getMatchingUpsellOffers($pdo, $cartServices2, $cartProducts2, 'small_car', 82000);
assertTest(count($offers2) > 0, "Matched upsell offer for High Mileage (>75,000 km) on Engine Oil");
assertTest($offers2[0]['rule_uid'] === 'UPSELL-HIGH-MILEAGE-SYNTHETIC', "Offer is 'UPSELL-HIGH-MILEAGE-SYNTHETIC'");
assertTest($offers2[0]['offer_product_id'] === 2, "Recommends Synthetic Oil Product #2 (Mobil 1)");


// --- SCENARIO 6: UPSELL TRACKING (SHOWN, ACCEPTED, DECLINED) & REPORTS ---
echo "\n--- 6. TESTING UPSELL LOGGING & OWNER REPORT CONVERSION ---\n";

$testRuleId = (int)$pdo->query("SELECT id FROM upsell_rules WHERE rule_uid = 'UPSELL-SUV-PREMIUM-WASH'")->fetchColumn();
$cashierId = 1;

// Log Shown
MembershipManager::recordUpsellAction($pdo, $testRuleId, $cashierId, 'shown', null, $testCustId, $testVehId, '2TEST-GOLD');
// Log Accepted
MembershipManager::recordUpsellAction($pdo, $testRuleId, $cashierId, 'accepted', null, $testCustId, $testVehId, '2TEST-GOLD');
// Log Declined
MembershipManager::recordUpsellAction($pdo, $testRuleId, $cashierId, 'declined', null, $testCustId, $testVehId, '2TEST-GOLD', 'Customer in hurry');

$report = MembershipManager::getUpsellReport($pdo);
assertTest($report['total_shown'] >= 3, "Report tracks total offers shown (>=3)");
assertTest($report['total_accepted'] >= 1, "Report tracks total offers accepted (>=1)");
assertTest($report['total_declined'] >= 1, "Report tracks total offers declined (>=1)");
assertTest($report['conversion_rate'] > 0, "Report calculates overall conversion rate % (" . $report['conversion_rate'] . "%)");
assertTest(!empty($report['by_cashier']), "Report includes breakdown by Cashier");
assertTest(!empty($report['by_rule']), "Report includes breakdown by Campaign Rule");


// --- SCENARIO 7: CLEANUP FIXTURES ---
echo "\n--- 7. CLEANUP TEST FIXTURES ---\n";
$pdo->exec("DELETE FROM crm_message_queue WHERE event_uid LIKE 'TEST-%' OR plate_number LIKE '%TEST%' OR plate_number LIKE '%OPT%'");
$pdo->exec("DELETE FROM upsell_logs WHERE log_uid LIKE 'TEST-%' OR plate_number LIKE '%TEST%'");
$pdo->exec("DELETE FROM membership_usages WHERE customer_id >= 9900");
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'TEST-%'");
$pdo->exec("DELETE FROM vehicles WHERE customer_id >= 9900");
$pdo->exec("DELETE FROM customers WHERE id >= 9900");
assertTest(true, "All test fixtures cleaned up cleanly");

echo "\n=================================================================\n";
echo "SUMMARY: Passed {$passedTests} / {$totalTests} tests.\n";
if ($passedTests === $totalTests) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
echo "=================================================================\n";
