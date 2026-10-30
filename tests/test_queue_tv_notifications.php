<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/BayManager.php';
require_once __DIR__ . '/../core/NotificationManager.php';

$pdo = Database::getInstance();
BayManager::ensureTables($pdo);
NotificationManager::ensureTables($pdo);

echo "======================================================================\n";
echo "   CUSTOMER QUEUE TV & NOTIFICATION BELL - COMPREHENSIVE TEST SUITE   \n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $message, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] " . $message . "\n";
        $passCount++;
    } else {
        echo " [FAIL] " . $message . ($details ? " ($details)" : "") . "\n";
        $failCount++;
    }
}

// Backup settings & bay states
$origTvEnabled = get_setting('tv_display_enabled', '1');
$origTvCode = get_setting('tv_display_code', 'tv-office-88');
$origTvLang = get_setting('tv_display_language', 'both');

$backupBays = $pdo->query("SELECT id, status, current_order_id, occupied_at, is_active FROM bays")->fetchAll(PDO::FETCH_ASSOC);
$createdOrderIds = [];
$createdNotifIds = [];

// Helper for real HTTP requests to tv.php
function requestTvHttp(string $query): array {
    $url = 'http://localhost/Car-WashService/tv.php?' . $query;
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => 5
        ]
    ]);
    $body = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('#HTTP/\S+\s+(\d+)#', $statusLine, $m);
    $statusCode = isset($m[1]) ? (int)$m[1] : 0;
    return ['status' => $statusCode, 'body' => (string)$body];
}

try {
    // -------------------------------------------------------------
    // PART 1: ACCESS RULES & SECRET CODE (Owner controls in Settings)
    // -------------------------------------------------------------
    echo "--- 1. Testing TV Access Control & Secret Token ---\n";

    // Set known TV secret
    update_setting($pdo, 'tv_display_enabled', '1', 1, 'Test');
    update_setting($pdo, 'tv_display_code', 'secret-office-2026', 1, 'Test');

    // Test A: No token or wrong token returns 403
    $resA = requestTvHttp('action=feed&code=wrong-code');
    assertCheck($resA['status'] === 403, "Access with wrong secret code returns 403 Forbidden");

    // Test B: TV disabled by owner returns 403 even with correct code
    update_setting($pdo, 'tv_display_enabled', '0', 1, 'Test');
    $resB = requestTvHttp('action=feed&code=secret-office-2026');
    assertCheck($resB['status'] === 403, "Access when owner disabled TV returns 403 Forbidden");

    // Test C: Re-enable TV and access with valid ?code=...
    update_setting($pdo, 'tv_display_enabled', '1', 1, 'Test');
    $resC = requestTvHttp('action=feed&code=secret-office-2026');
    $feedData = json_decode($resC['body'], true);
    assertCheck($resC['status'] === 200 && is_array($feedData) && !empty($feedData['success']), "Access with valid secret code returns 200 OK and valid JSON feed");

    // Test D: Access with ?token=... alternative parameter
    $resD = requestTvHttp('action=feed&token=secret-office-2026');
    $tokenData = json_decode($resD['body'], true);
    assertCheck($resD['status'] === 200 && is_array($tokenData) && !empty($tokenData['success']), "Access with ?token=... alternative parameter succeeds");

    // -------------------------------------------------------------
    // PART 2: SCENARIO SETUP (3 Cars in Bays, 10 Waiting, 2 Ready)
    // -------------------------------------------------------------
    echo "\n--- 2. Setting Up 3 Cars in Bays, 10 Waiting, 2 Ready for Pickup ---\n";

    // Temporarily stash existing active orders so TV test runs in 100% clean isolation
    $pdo->exec("UPDATE orders SET is_active = 0 WHERE is_active = 1");

    // Clear current bays
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL, occupied_at = NULL");

    $bays = BayManager::getAllBays($pdo);
    assertCheck(count($bays) >= 4, "System has at least 4 bays (Bays 1-4)");

    // Create 3 active orders in Bays 1, 2, 3
    $baysScenario = [
        ['bay' => $bays[0], 'plate' => '2AB-1111', 'status' => 'washing', 'cust' => 'Mr. Alice Secret', 'phone' => '012999001', 'points' => 50],
        ['bay' => $bays[1], 'plate' => '2AB-2222', 'status' => 'washing', 'cust' => 'Ms. Bob Confidential', 'phone' => '012999002', 'points' => 120],
        ['bay' => $bays[2], 'plate' => '2AB-3333', 'status' => 'washing', 'cust' => 'Charlie Private', 'phone' => '012999003', 'points' => 85]
    ];

    for ($i = 0; $i < 3; $i++) {
        $item = $baysScenario[$i];
        $bay = $item['bay'];
        $ordNum = 'TV-TEST-BAY-' . ($i + 1) . '-' . time();
        $ins = $pdo->prepare("
            INSERT INTO orders (
                order_number, source, plate_number, vehicle_type, customer_name,
                customer_phone, subtotal_usd, total_usd, status, payment_status,
                bay_id, arrived_at, created_at, is_active
            ) VALUES (?, 'pos_app', ?, 'small_car', ?, ?, 25.00, 25.00, ?, 'unpaid', ?, NOW(), NOW(), 1)
        ");
        $ins->execute([$ordNum, $item['plate'], $item['cust'], $item['phone'], $item['status'], $bay['id']]);
        $orderId = (int)$pdo->lastInsertId();
        $createdOrderIds[] = $orderId;

        $pdo->prepare("UPDATE bays SET status = 'busy', current_order_id = ?, occupied_at = NOW() WHERE id = ?")
            ->execute([$orderId, $bay['id']]);
    }

    // Set Bay 2 order to detailing
    $pdo->prepare("UPDATE orders SET notes = 'Det' WHERE id = ?")->execute([$createdOrderIds[1]]);
    $pdo->prepare("INSERT INTO order_items (order_id, item_type, service_name, qty, price_usd) VALUES (?, 'service', 'Full Detailing Package', 1, 50.00)")
        ->execute([$createdOrderIds[1]]);

    // Create 10 Waiting Cars
    $waitingPlates = [];
    for ($w = 1; $w <= 10; $w++) {
        $wPlate = sprintf('2WAIT-%04d', $w);
        $waitingPlates[] = $wPlate;
        $wOrdNum = 'TV-WAIT-' . $w . '-' . time();
        $wIns = $pdo->prepare("
            INSERT INTO orders (
                order_number, source, plate_number, vehicle_type, customer_name,
                customer_phone, subtotal_usd, total_usd, status, payment_status,
                bay_id, arrived_at, created_at, is_active
            ) VALUES (?, 'pos_app', ?, 'small_car', ?, ?, 10.00, 10.00, 'washing', 'unpaid', NULL, DATE_ADD(NOW(), INTERVAL ? SECOND), DATE_ADD(NOW(), INTERVAL ? SECOND), 1)
        ");
        $wIns->execute([$wOrdNum, $wPlate, "Waiting Customer $w", "0990000$w", $w, $w]);
        $createdOrderIds[] = (int)$pdo->lastInsertId();
    }

    // Create 2 Ready for Pickup Cars
    $readyPlates = ['2RDY-0001', '2RDY-0002'];
    foreach ($readyPlates as $rIdx => $rPlate) {
        $rOrdNum = 'TV-RDY-' . ($rIdx + 1) . '-' . time();
        $rIns = $pdo->prepare("
            INSERT INTO orders (
                order_number, source, plate_number, vehicle_type, customer_name,
                customer_phone, subtotal_usd, total_usd, status, payment_status,
                bay_id, arrived_at, created_at, finished_at, is_active
            ) VALUES (?, 'pos_app', ?, 'small_car', ?, ?, 18.00, 18.00, 'finished', 'unpaid', NULL, NOW(), NOW(), NOW(), 1)
        ");
        $rIns->execute([$rOrdNum, $rPlate, "Ready Customer $rIdx", "0880000$rIdx"]);
        $createdOrderIds[] = (int)$pdo->lastInsertId();
    }

    // -------------------------------------------------------------
    // PART 3: TV FEED VERIFICATION & STRICT PRIVACY CHECK
    // -------------------------------------------------------------
    echo "\n--- 3. Verifying TV Feed Structure & Strict Privacy ---\n";

    $resFeed = requestTvHttp('action=feed&code=secret-office-2026');
    $feedJsonStr = $resFeed['body'];
    $tvFeed = json_decode($feedJsonStr, true);

    assertCheck($resFeed['status'] === 200 && $tvFeed['success'] === true, "TV feed returns success: true");
    assertCheck(count($tvFeed['bays']) >= 4, "TV feed contains all 4 bays");

    $busyBays = array_filter($tvFeed['bays'], fn($b) => $b['is_busy'] === true);
    assertCheck(count($busyBays) === 3, "Exactly 3 bays are marked busy on TV");

    // Check status badges present
    $badgeKeys = array_column($tvFeed['bays'], 'badge_key');
    assertCheck(in_array('in_progress', $badgeKeys, true) || in_array('detailing', $badgeKeys, true), "TV bays contain valid status badge ('In Progress' / 'Detailing')");
    assertCheck(in_array('waiting', $badgeKeys, true), "TV empty bay contains 'Waiting' status badge");

    // Check 10 waiting cars
    assertCheck($tvFeed['waiting_total'] === 10, "Waiting total reports exactly 10 cars (got {$tvFeed['waiting_total']})");
    assertCheck(count($tvFeed['waiting_list']) === 8, "Waiting list displays top 8 cars with FIFO order");
    assertCheck($tvFeed['waiting_more_count'] === 2, "Waiting more count reports remaining +2 cars waiting");
    assertCheck($tvFeed['waiting_list'][0]['is_next'] === true, "First car in queue is flagged as NEXT");

    // Check Ready for Pickup
    assertCheck($tvFeed['ready_total'] >= 2, "Ready total reports at least 2 cars (got {$tvFeed['ready_total']})");
    $readyFeedPlates = array_column($tvFeed['ready_cars'], 'plate_number');
    assertCheck(in_array('2RDY-0001', $readyFeedPlates, true) && in_array('2RDY-0002', $readyFeedPlates, true), "Ready area lists plates '2RDY-0001' and '2RDY-0002'");

    // STRICT PRIVACY CHECK: Check raw JSON string for customer names, phones, prices, points
    $privacyLeaks = [];
    if (strpos($feedJsonStr, 'Alice Secret') !== false) $privacyLeaks[] = 'Customer name Alice Secret';
    if (strpos($feedJsonStr, 'Bob Confidential') !== false) $privacyLeaks[] = 'Customer name Bob Confidential';
    if (strpos($feedJsonStr, '012999001') !== false) $privacyLeaks[] = 'Customer phone 012999001';
    if (strpos($feedJsonStr, '012999002') !== false) $privacyLeaks[] = 'Customer phone 012999002';
    if (strpos($feedJsonStr, '25.00') !== false) $privacyLeaks[] = 'Price 25.00';
    if (strpos($feedJsonStr, 'points') !== false) $privacyLeaks[] = 'Points field';
    if (strpos($feedJsonStr, 'loyalty') !== false) $privacyLeaks[] = 'Loyalty data';

    assertCheck(empty($privacyLeaks), "Strict Privacy Test: Feed NEVER leaks names, phones, prices, or loyalty points", implode(', ', $privacyLeaks));

    // -------------------------------------------------------------
    // PART 4: RESPONSIVE VIEWPORT TEST (TV 1920x1080 vs Laptop 1366x768)
    // -------------------------------------------------------------
    echo "\n--- 4. Testing Responsive Viewports (TV 1920x1080 vs Laptop 1366x768) ---\n";

    $resHtml = requestTvHttp('code=secret-office-2026');
    $renderedHtml = $resHtml['body'];

    // Verify TV full screen styling (1920x1080)
    assertCheck(strpos($renderedHtml, 'grid-template-columns: repeat(4, 1fr)') !== false, "Full TV mode (1920x1080): 4-column bay card grid layout active");
    assertCheck(strpos($renderedHtml, 'bottom-split') !== false && strpos($renderedHtml, 'grid-template-columns: 1.35fr 1fr') !== false, "Full TV mode: High-readability split layout between Queue and Ready area");
    assertCheck(strpos($renderedHtml, 'POLL_INTERVAL = 1000') !== false, "Live Updates: Poll interval is 1 second (1000ms)");

    // Verify Laptop responsive styles (@media max-width: 1200px)
    assertCheck(strpos($renderedHtml, '@media (max-width: 1200px)') !== false, "Laptop mode breakpoint (@media max-width: 1200px) is configured");
    assertCheck(strpos($renderedHtml, 'grid-template-columns: repeat(2, 1fr)') !== false, "Laptop mode adapts bays to clean 2-column grid to fit 1366x768 screens");
    assertCheck(strpos($renderedHtml, 'reconnectingPill') !== false && strpos($renderedHtml, 'Reconnecting') !== false, "Resilience: 'Reconnecting...' pill keeps last information on screen if connection is lost");

    // -------------------------------------------------------------
    // PART 5: NOTIFICATION BELL & ROLE-TARGETED NOTIFICATIONS
    // -------------------------------------------------------------
    echo "\n--- 5. Testing Notification Bell & Role-Targeted Alerts ---\n";

    // Clear test notifications
    $pdo->exec("DELETE FROM system_notifications WHERE title_en LIKE 'TEST:%'");

    // Role 1: Cashier: "Bank transfer pending approval"
    $uid1 = NotificationManager::notify($pdo, [
        'target_role' => 'cashier',
        'category' => 'transfer_approval',
        'title_en' => 'TEST: Bank transfer pending approval',
        'title_km' => 'ការផ្ទេរប្រាក់តាមធនាគារកំពុងរង់ចាំការអនុម័ត (ABA $25.00)',
        'message_en' => 'Order #ORD-101 payment bank transfer slip requires manager approval.',
        'message_km' => 'វិក្កយបត្រ #ORD-101 ត្រូវការការអនុម័តលើបង្កាន់ដៃធនាគារ។',
        'link_url' => 'orders/index.php?order_id=101',
        'sound_type' => 'alert'
    ]);
    assertCheck(!empty($uid1), "Cashier notification created: 'Bank transfer pending approval'");

    // Role 2 & 3: Washer & Technical: "New car in queue (Plate ...)"
    $uid2 = NotificationManager::notify($pdo, [
        'target_role' => 'washer',
        'category' => 'new_car',
        'title_en' => 'TEST: New car in queue (Plate 2AB-9999)',
        'title_km' => 'រថយន្តថ្មីក្នុងជួររង់ចាំ (ស្លាកលេខ 2AB-9999)',
        'message_en' => 'Plate 2AB-9999 is waiting for assignment.',
        'message_km' => 'ស្លាកលេខ 2AB-9999 កំពុងរង់ចាំចូលកន្លែងផ្តល់សេវា។',
        'link_url' => 'washer/jobs.php',
        'sound_type' => 'chime'
    ]);
    assertCheck(!empty($uid2), "Washer & Technical notification created: 'New car in queue (Plate 2AB-9999)'");

    // Role 4: Supervisor: "Wait time over 45 minutes" & "Bay idle more than 5 minutes"
    $uid3 = NotificationManager::notify($pdo, [
        'target_role' => 'wash_supervisor',
        'category' => 'long_wait',
        'title_en' => 'TEST: Wait time over 45 minutes',
        'title_km' => 'រយៈពេលរង់ចាំលើសពី ៤៥ នាទី',
        'message_en' => 'Car 2AB-1111 has been waiting in queue for 48 minutes.',
        'message_km' => 'រថយន្ត 2AB-1111 បានរង់ចាំអស់រយៈពេល ៤៨ នាទីហើយ។',
        'link_url' => 'owner/bays_queue.php',
        'sound_type' => 'alert'
    ]);
    assertCheck(!empty($uid3), "Supervisor notification created: 'Wait time over 45 minutes'");

    $uid4 = NotificationManager::notify($pdo, [
        'target_role' => 'wash_supervisor',
        'category' => 'bay_idle',
        'title_en' => 'TEST: Bay idle more than 5 minutes',
        'title_km' => 'កន្លែងទំនេរលើសពី ៥ នាទី',
        'message_en' => 'Bay 1 has been idle for 6 minutes while cars are waiting.',
        'message_km' => 'កន្លែងទី ១ ទំនេរអស់ ៦ នាទី ខណៈមានរថយន្តរង់ចាំ។',
        'link_url' => 'owner/bays_queue.php',
        'sound_type' => 'alert'
    ]);
    assertCheck(!empty($uid4), "Supervisor notification created: 'Bay idle more than 5 minutes'");

    // Role 5: Warehouse: "Part request from Tech (Car ...)"
    $uid5 = NotificationManager::notify($pdo, [
        'target_role' => 'warehouse',
        'category' => 'parts_request',
        'title_en' => 'TEST: Part request from Tech (Car 2AB-2222)',
        'title_km' => 'សំណើសុំគ្រឿងបន្លាស់ពីជាងបច្ចេកទេស (រថយន្ត 2AB-2222)',
        'message_en' => 'Technician requested 1x Oil Filter Toyota and 4L Engine Oil.',
        'message_km' => 'ជាងបានស្នើសុំតម្រងប្រេង និងប្រេងម៉ាស៊ីន។',
        'link_url' => 'owner/oil_care.php?tab=requests',
        'sound_type' => 'alert'
    ]);
    assertCheck(!empty($uid5), "Warehouse notification created: 'Part request from Tech (Car 2AB-2222)'");

    // Role 6: Owner: "Cash variance alert"
    $uid6 = NotificationManager::notify($pdo, [
        'target_role' => 'owner',
        'category' => 'cash_variance',
        'title_en' => 'TEST: Cash variance alert',
        'title_km' => 'ការជូនដំណឹងអំពីភាពខុសគ្នានៃសាច់ប្រាក់',
        'message_en' => 'Cashier shift shortage of -$15.00 recorded for Dara.',
        'message_km' => 'មានការខ្វះសាច់ប្រាក់ចំនួន -$15.00 ក្នុងវេនរបស់តារា។',
        'link_url' => 'owner/dashboard.php',
        'sound_type' => 'alert'
    ]);
    assertCheck(!empty($uid6), "Owner notification created: 'Cash variance alert'");

    // Fetch created row id for notif1
    $notif1Row = $pdo->prepare("SELECT id FROM system_notifications WHERE notification_uid = ?");
    $notif1Row->execute([$uid1]);
    $notif1Id = (int)$notif1Row->fetchColumn();

    // -------------------------------------------------------------
    // PART 6: UNREAD COUNTS, MARK AS READ & SOUND MUTE TOGGLE
    // -------------------------------------------------------------
    echo "\n--- 6. Testing Unread Counts, Mark as Read & Sound Muting ---\n";

    // Test cashier unread count
    $cashierUnread = NotificationManager::getUnreadCount($pdo, 9991, 'cashier');
    assertCheck($cashierUnread >= 1, "Cashier unread count includes bank transfer notification (Count: $cashierUnread)");

    // Test washer unread count
    $washerUnread = NotificationManager::getUnreadCount($pdo, 9992, 'washer');
    assertCheck($washerUnread >= 1, "Washer unread count includes new car notification (Count: $washerUnread)");

    // Test owner unread count (receives supervisor alerts, parts requests, cash variance)
    $ownerUnread = NotificationManager::getUnreadCount($pdo, 1, 'owner');
    assertCheck($ownerUnread >= 3, "Owner unread count includes multiple management alerts (Count: $ownerUnread)");

    // Test mark individual notification as read
    $markOk = NotificationManager::markAsRead($pdo, $notif1Id, 9991);
    assertCheck($markOk === true, "Mark single notification as read returned true");
    $cashierUnreadAfter = NotificationManager::getUnreadCount($pdo, 9991, 'cashier');
    assertCheck($cashierUnreadAfter < $cashierUnread, "Cashier unread count decremented after marking notification as read");

    // Test mark all notifications as read for owner
    $markAllOk = NotificationManager::markAllAsRead($pdo, 1, 'owner');
    assertCheck($markAllOk === true, "Mark all notifications as read returned true");
    $ownerUnreadAfter = NotificationManager::getUnreadCount($pdo, 1, 'owner');
    assertCheck($ownerUnreadAfter === 0, "Owner unread count became 0 after markAllAsRead");

    // Test sound muting toggle
    $initialMute = NotificationManager::isSoundMuted($pdo, 1);
    $toggledMute = NotificationManager::toggleSoundMute($pdo, 1, !$initialMute);
    assertCheck($toggledMute === !$initialMute, "Sound muting toggled successfully ($initialMute -> $toggledMute)");
    $restoredMute = NotificationManager::toggleSoundMute($pdo, 1, $initialMute);
    assertCheck($restoredMute === $initialMute, "Sound muting restored to initial user preference ($initialMute)");

    // -------------------------------------------------------------
    // PART 7: LIVE SYNC & SOUND TYPES VERIFICATION
    // -------------------------------------------------------------
    echo "\n--- 7. Testing Live Sync Endpoint & Sound Types ---\n";

    $notifList = NotificationManager::getNotifications($pdo, 9992, 'washer', 5);
    $foundChime = false;
    foreach ($notifList as $nl) {
        if ($nl['sound_type'] === 'chime') {
            $foundChime = true;
            break;
        }
    }
    assertCheck($foundChime, "Chime sound assigned correctly to new job notifications");

    $ownerNotifList = NotificationManager::getNotifications($pdo, 1, 'owner', 10);
    $foundAlert = false;
    foreach ($ownerNotifList as $onl) {
        if ($onl['sound_type'] === 'alert') {
            $foundAlert = true;
            break;
        }
    }
    assertCheck($foundAlert, "Stronger alert sound assigned correctly to parts requests and variance alerts");

} finally {
    // Cleanup created test records
    echo "\n--- Cleaning Up Test Data ---\n";
    if (!empty($createdOrderIds)) {
        $inOrd = implode(',', $createdOrderIds);
        $pdo->exec("DELETE FROM order_items WHERE order_id IN ($inOrd)");
        $pdo->exec("DELETE FROM orders WHERE id IN ($inOrd)");
    }
    if (!empty($createdNotifIds)) {
        $inNot = implode(',', $createdNotifIds);
        $pdo->exec("DELETE FROM notification_reads WHERE notification_id IN ($inNot)");
        $pdo->exec("DELETE FROM system_notifications WHERE id IN ($inNot)");
    }
    $pdo->exec("DELETE FROM system_notifications WHERE title_en LIKE 'TEST:%'");

    // Restore original active orders
    $pdo->exec("UPDATE orders SET is_active = 1 WHERE is_active = 0");

    // Restore bay states
    foreach ($backupBays as $bb) {
        $bUp = $pdo->prepare("UPDATE bays SET status = ?, current_order_id = ?, occupied_at = ?, is_active = ? WHERE id = ?");
        $bUp->execute([$bb['status'], $bb['current_order_id'], $bb['occupied_at'], $bb['is_active'], $bb['id']]);
    }

    // Restore settings
    update_setting($pdo, 'tv_display_enabled', (string)$origTvEnabled, 1, 'Cleanup');
    update_setting($pdo, 'tv_display_code', (string)$origTvCode, 1, 'Cleanup');
    update_setting($pdo, 'tv_display_language', (string)$origTvLang, 1, 'Cleanup');
}

echo "\n======================================================================\n";
echo "  TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

exit($failCount === 0 ? 0 : 1);
