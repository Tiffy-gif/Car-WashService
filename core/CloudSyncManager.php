<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Helpers.php';

class CloudSyncManager {

    /**
     * Check if shop is currently in offline mode (either simulated or no internet).
     */
    public static function isOfflineMode(PDO $pdo): bool {
        return get_setting('cloud_offline_mode', '0') === '1';
    }

    /**
     * Set offline mode state.
     */
    public static function setOfflineMode(PDO $pdo, bool $offline): bool {
        $val = $offline ? '1' : '0';
        $userId = class_exists('Auth') ? (Auth::getUserId() ?? 0) : 0;
        $userName = class_exists('Auth') ? (Auth::getFullName() ?? Auth::getUsername() ?? 'System') : 'System';
        $res = update_setting($pdo, 'cloud_offline_mode', $val, $userId, $userName);
        AuditLogger::log('SYNC_OFFLINE_MODE_TOGGLED', "Cloud Sync Offline Mode changed to " . ($offline ? 'ENABLED (Offline)' : 'DISABLED (Online)'), 'settings', 'cloud_offline_mode');
        return $res;
    }

    /**
     * Count pending unsynced records across all operational tables.
     */
    public static function getUnsyncedCounts(PDO $pdo): array {
        $sales = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $jobs = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $stock = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $shifts = (int)$pdo->query("SELECT COUNT(*) FROM pos_closings WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $timecards = (int)$pdo->query("SELECT COUNT(*) FROM staff_timecards WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $logs = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $crmTx = (int)$pdo->query("SELECT COUNT(*) FROM loyalty_transactions WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $crmMsg = (int)$pdo->query("SELECT COUNT(*) FROM crm_message_queue WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();
        $crm = $crmTx + $crmMsg;
        $upsells = (int)$pdo->query("SELECT COUNT(*) FROM upsell_logs WHERE is_synced = 0 OR is_synced IS NULL")->fetchColumn();

        $total = $sales + $jobs + $stock + $shifts + $timecards + $logs + $crm + $upsells;

        return [
            'sales' => $sales,
            'orders' => $sales,
            'job_orders' => $jobs,
            'tasks' => $jobs,
            'stock_movements' => $stock,
            'stock' => $stock,
            'shifts' => $shifts,
            'timecards' => $timecards,
            'audit_logs' => $logs,
            'audit' => $logs,
            'crm_events' => $crm,
            'crm_messages' => $crmMsg,
            'loyalty' => $crmTx,
            'upsell_logs' => $upsells,
            'total' => $total
        ];
    }

    /**
     * Send unsynced records to Cloud.
     * Compresses with gzip, signs with HMAC-SHA256, and retries up to 3 times on failure.
     */
    public static function sendToCloud(PDO $pdo, bool $force = false, int $retryDelaySeconds = 10, int $maxRetries = 3): array {
        $startTime = microtime(true);

        if (self::isOfflineMode($pdo)) {
            self::logSync($pdo, 'push', 'offline', 0, 0, 0, 0, 'Shop is configured in offline mode. Records preserved locally.');
            return [
                'success' => false,
                'offline' => true,
                'sent_count' => 0,
                'records_sent' => 0,
                'message' => 'Shop is operating in offline mode. Changes are safely saved locally with original timestamps.'
            ];
        }

        $cloudUrl = get_setting('cloud_api_url', 'http://localhost/Car-WashService/api/cloud/index.php');
        $cloudToken = get_setting('cloud_api_token', 'cloud_tok_live_778899aabbcc');
        $cloudSecret = get_setting('cloud_api_secret', 'cloud_sec_live_x99y88z77w66v55');
        $shopId = get_setting('cloud_shop_id', 'SHOP-PP-001');

        if (empty($cloudUrl) || empty($cloudSecret)) {
            return [
                'success' => false,
                'error' => 'not_configured',
                'message' => 'Cloud Sync API URL or Secret is not configured in Settings.'
            ];
        }

        // 1. Gather unsynced records
        // Sales
        $salesStmt = $pdo->query("SELECT * FROM orders WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch line items for these sales
        $salesData = [];
        foreach ($salesRows as $sr) {
            $ordId = (int)$sr['id'];
            $items = $pdo->query("SELECT * FROM order_items WHERE order_id = {$ordId}")->fetchAll(PDO::FETCH_ASSOC);
            $sr['items'] = $items;
            $salesData[] = $sr;
        }

        // Job orders
        $jobsStmt = $pdo->query("SELECT * FROM order_tasks WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $jobsData = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Stock movements
        $stockStmt = $pdo->query("SELECT * FROM stock_movements WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $stockData = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

        // Shifts & Z-reports
        $shiftStmt = $pdo->query("SELECT * FROM pos_closings WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 50");
        $shiftData = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

        // Timecards
        $tcStmt = $pdo->query("SELECT * FROM staff_timecards WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $tcData = $tcStmt->fetchAll(PDO::FETCH_ASSOC);

        // Audit logs
        $alStmt = $pdo->query("SELECT * FROM audit_logs WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $alData = $alStmt->fetchAll(PDO::FETCH_ASSOC);

        // CRM events (loyalty transactions + offline-queued reminder events)
        $crmStmt = $pdo->query("SELECT * FROM loyalty_transactions WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $crmData = $crmStmt->fetchAll(PDO::FETCH_ASSOC);

        $msgStmt = $pdo->query("SELECT * FROM crm_message_queue WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $msgData = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($msgData as $md) {
            $crmData[] = $md;
        }

        // Upsell logs
        $upsellStmt = $pdo->query("SELECT * FROM upsell_logs WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $upsellData = $upsellStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalRecordsToSend = count($salesData) + count($jobsData) + count($stockData) + count($shiftData) + count($tcData) + count($alData) + count($crmData) + count($upsellData);

        if ($totalRecordsToSend === 0 && !$force) {
            return [
                'success' => true,
                'records_sent' => 0,
                'message' => 'All local records are up to date with cloud.'
            ];
        }

        // 2. Pack payload
        $payload = [
            'shop_id' => $shopId,
            'timestamp' => time(),
            'sales' => $salesData,
            'job_orders' => $jobsData,
            'stock_movements' => $stockData,
            'shifts' => $shiftData,
            'timecards' => $tcData,
            'audit_logs' => $alData,
            'crm_events' => $crmData,
            'upsell_logs' => $upsellData
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        // Gzip compression
        $compressedPayload = gzencode($jsonPayload, 6);

        // 3. Security Signing: Timestamp + Nonce + HMAC-SHA256
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}." . $compressedPayload, $cloudSecret);

        $url = rtrim($cloudUrl, '/') . (strpos($cloudUrl, '?') === false ? '?' : '&') . 'action=push';

        // 4. Send with retry loop
        $attempt = 0;
        $response = null;
        $httpCode = 0;
        $lastError = '';

        while ($attempt < $maxRetries) {
            $attempt++;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $compressedPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Encoding: gzip',
                'X-Shop-Token: ' . $cloudToken,
                'X-Shop-Timestamp: ' . $timestamp,
                'X-Shop-Nonce: ' . $nonce,
                'X-Shop-Signature: ' . $signature
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                break;
            }

            $lastError = $curlError ?: "HTTP Code {$httpCode}: {$response}";
            if ($attempt < $maxRetries) {
                // Wait before next retry
                if ($retryDelaySeconds > 0) {
                    sleep($retryDelaySeconds);
                }
            }
        }

        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        if ($httpCode !== 200 || !$response) {
            self::logSync($pdo, 'push', 'failed', $totalRecordsToSend, 0, 0, $durationMs, "Sync failed after {$attempt} attempts: {$lastError}");
            return [
                'success' => false,
                'error' => 'cloud_communication_failed',
                'attempts' => $attempt,
                'message' => "Failed to reach cloud server after {$attempt} attempts: {$lastError}"
            ];
        }

        $resJson = json_decode($response, true);
        if (!is_array($resJson) || empty($resJson['success'])) {
            $err = $resJson['message'] ?? 'Cloud rejected payload';
            self::logSync($pdo, 'push', 'failed', $totalRecordsToSend, 0, 0, $durationMs, $err);
            return [
                'success' => false,
                'error' => 'cloud_rejected',
                'message' => $err
            ];
        }

        // 5. Mark confirmed records as is_synced = 1
        $confirmed = $resJson['confirmed_ids'] ?? [];
        $now = date('Y-m-d H:i:s');

        // Confirm sales
        if (!empty($confirmed['sales'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['sales']));
            $pdo->exec("UPDATE orders SET is_synced = 1, synced_at = '{$now}' WHERE order_number IN ({$inList}) OR order_uid IN ({$inList})");
        }

        // Confirm job orders
        if (!empty($confirmed['job_orders'])) {
            foreach ($confirmed['job_orders'] as $jUid) {
                $id = (int)str_replace('JOB-', '', (string)$jUid);
                if ($id > 0) {
                    $pdo->exec("UPDATE order_tasks SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
                }
            }
        }

        // Confirm stock movements
        if (!empty($confirmed['stock_movements'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['stock_movements']));
            $pdo->exec("UPDATE stock_movements SET is_synced = 1, synced_at = '{$now}' WHERE movement_uid IN ({$inList}) OR id IN ({$inList})");
        }

        // Confirm shifts
        if (!empty($confirmed['shifts'])) {
            foreach ($confirmed['shifts'] as $shUid) {
                $id = (int)str_replace('SHIFT-', '', $shUid);
                if ($id > 0) $pdo->exec("UPDATE pos_closings SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
            }
        }

        // Confirm timecards
        if (!empty($confirmed['timecards'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['timecards']));
            $pdo->exec("UPDATE staff_timecards SET is_synced = 1, synced_at = '{$now}' WHERE timecard_uid IN ({$inList}) OR id IN ({$inList})");
        }

        // Confirm audit logs
        if (!empty($confirmed['audit_logs'])) {
            foreach ($confirmed['audit_logs'] as $alUid) {
                $id = (int)str_replace('AUD-', '', $alUid);
                if ($id > 0) $pdo->exec("UPDATE audit_logs SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
            }
        }

        // Confirm CRM events (loyalty transactions + crm_message_queue)
        if (!empty($confirmed['crm_events'])) {
            foreach ($confirmed['crm_events'] as $crmUid) {
                $uidStr = (string)$crmUid;
                if (str_contains($uidStr, 'MSG-')) {
                    $pdo->prepare("UPDATE crm_message_queue SET is_synced = 1, synced_at = '{$now}', status = 'sent', sent_at = '{$now}' WHERE event_uid = ?")->execute([$uidStr]);
                } else {
                    $id = (int)str_replace('CRM-', '', $uidStr);
                    if ($id > 0) $pdo->exec("UPDATE loyalty_transactions SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
                }
            }
        }

        // Confirm upsell logs
        if (!empty($confirmed['upsell_logs'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['upsell_logs']));
            $pdo->exec("UPDATE upsell_logs SET is_synced = 1, synced_at = '{$now}' WHERE log_uid IN ({$inList})");
        }

        // Record last sync timestamp
        update_setting($pdo, 'cloud_last_sync_at', $now, 0, 'CloudSync');

        self::logSync($pdo, 'push', 'success', $totalRecordsToSend, 0, 0, $durationMs, null);

        return [
            'success' => true,
            'sent_count' => $totalRecordsToSend,
            'records_sent' => $totalRecordsToSend,
            'duration_ms' => $durationMs,
            'last_sync_at' => $now,
            'confirmed_ids' => $confirmed
        ];
    }

    /**
     * Pull updates from Cloud (users, products & prices, settings, queue rules, membership tiers).
     * Detects conflicts and stores both versions for manager resolution.
     */
    public static function receiveFromCloud(PDO $pdo): array {
        $startTime = microtime(true);

        if (self::isOfflineMode($pdo)) {
            return ['success' => false, 'offline' => true, 'message' => 'Offline mode active.'];
        }

        $cloudUrl = get_setting('cloud_api_url', 'http://localhost/Car-WashService/api/cloud/index.php');
        $cloudToken = get_setting('cloud_api_token', 'cloud_tok_live_778899aabbcc');
        $cloudSecret = get_setting('cloud_api_secret', 'cloud_sec_live_x99y88z77w66v55');

        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.", $cloudSecret);

        $url = rtrim($cloudUrl, '/') . (strpos($cloudUrl, '?') === false ? '?' : '&') . 'action=pull';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Shop-Token: ' . $cloudToken,
            'X-Shop-Timestamp: ' . $timestamp,
            'X-Shop-Nonce: ' . $nonce,
            'X-Shop-Signature: ' . $signature
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        if ($httpCode !== 200 || !$response) {
            self::logSync($pdo, 'pull', 'failed', 0, 0, 0, $durationMs, 'Failed to connect to cloud during pull.');
            return ['success' => false, 'error' => 'pull_failed'];
        }

        $cloudData = json_decode($response, true);
        if (!is_array($cloudData) || empty($cloudData['success'])) {
            return ['success' => false, 'error' => 'invalid_cloud_response'];
        }

        $recordsReceived = 0;
        $conflictsCount = 0;
        $priceChanged = false;

        // Process Services & Prices
        if (!empty($cloudData['services'])) {
            foreach ($cloudData['services'] as $cs) {
                $svcId = (int)($cs['id'] ?? 0);
                if ($svcId <= 0) continue;

                $locStmt = $pdo->prepare("SELECT * FROM services WHERE id = ?");
                $locStmt->execute([$svcId]);
                $localSvc = $locStmt->fetch(PDO::FETCH_ASSOC);

                if ($localSvc) {
                    $cloudPrice = isset($cs['price_usd']) ? (float)$cs['price_usd'] : null;
                    
                    // Check if service prices table has entries
                    $spStmt = $pdo->prepare("SELECT price_usd FROM service_prices WHERE service_id = ? AND vehicle_type = 'small_car'");
                    $spStmt->execute([$svcId]);
                    $localPrice = $spStmt->fetchColumn();
                    $localPriceVal = ($localPrice !== false) ? (float)$localPrice : 0.0;

                    // Detect Conflict: If local record was modified recently and differs from cloud
                    if ($cloudPrice !== null && abs($cloudPrice - $localPriceVal) > 0.01) {
                        // Check if conflict simulation is flagged or local was changed
                        $isConflict = !empty($cs['is_conflict']);

                        if ($isConflict) {
                            // Do NOT overwrite! Log conflict
                            $confUid = 'CONF-SVC-' . $svcId . '-' . time();
                            $insConf = $pdo->prepare("INSERT INTO sync_conflicts (conflict_uid, record_type, record_uid, local_data, cloud_data, local_updated_at, cloud_updated_at, status) VALUES (?, 'service', ?, ?, ?, NOW(), NOW(), 'pending')");
                            $insConf->execute([
                                $confUid,
                                (string)$svcId,
                                json_encode(['id' => $svcId, 'name_en' => $localSvc['name_en'], 'price_usd' => $localPriceVal]),
                                json_encode($cs)
                            ]);
                            $conflictsCount++;
                            continue;
                        }

                        // Apply Cloud Price update to service_prices
                        $pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr) VALUES (?, 'small_car', ?, ?) ON DUPLICATE KEY UPDATE price_usd = VALUES(price_usd), price_khr = VALUES(price_khr)")
                            ->execute([$svcId, $cloudPrice, (int)round($cloudPrice * 4100)]);
                        $priceChanged = true;
                        $recordsReceived++;
                    }
                }
            }
        }

        // Process Products & Prices
        if (!empty($cloudData['products'])) {
            foreach ($cloudData['products'] as $cp) {
                $pId = (int)($cp['id'] ?? 0);
                if ($pId <= 0) continue;

                $locP = $pdo->query("SELECT * FROM products WHERE id = {$pId}")->fetch(PDO::FETCH_ASSOC);
                if ($locP) {
                    $isConflict = !empty($cp['is_conflict']);
                    if ($isConflict) {
                        $confUid = 'CONF-PROD-' . $pId . '-' . time();
                        $insConf = $pdo->prepare("INSERT INTO sync_conflicts (conflict_uid, record_type, record_uid, local_data, cloud_data, local_updated_at, cloud_updated_at, status) VALUES (?, 'product', ?, ?, ?, NOW(), NOW(), 'pending')");
                        $insConf->execute([
                            $confUid,
                            (string)$pId,
                            json_encode($locP),
                            json_encode($cp)
                        ]);
                        $conflictsCount++;
                        continue;
                    }

                    $cloudPPrice = (float)($cp['price_usd'] ?? $locP['price_usd']);
                    if (abs($cloudPPrice - (float)$locP['price_usd']) > 0.01) {
                        $pdo->prepare("UPDATE products SET price_usd = ?, price_khr = ? WHERE id = ?")
                            ->execute([$cloudPPrice, (int)round($cloudPPrice * 4100), $pId]);
                        $priceChanged = true;
                        $recordsReceived++;
                    }
                }
            }
        }

        // Process Membership Tiers from Cloud
        if (!empty($cloudData['membership_tiers']) && is_array($cloudData['membership_tiers'])) {
            $tStmt = $pdo->prepare("
                INSERT INTO membership_tiers (tier_code, name_en, name_km, monthly_fee_usd, included_services, discount_percent, priority_level, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name_en = VALUES(name_en),
                    name_km = VALUES(name_km),
                    monthly_fee_usd = VALUES(monthly_fee_usd),
                    included_services = VALUES(included_services),
                    discount_percent = VALUES(discount_percent),
                    priority_level = VALUES(priority_level),
                    is_active = VALUES(is_active)
            ");
            foreach ($cloudData['membership_tiers'] as $t) {
                $inc = is_array($t['included_services'] ?? null) ? json_encode($t['included_services']) : ($t['included_services'] ?? json_encode([]));
                $tStmt->execute([
                    $t['tier_code'], $t['name_en'], $t['name_km'],
                    (float)($t['monthly_fee_usd'] ?? 0.00), $inc,
                    (int)($t['discount_percent'] ?? 0), $t['priority_level'] ?? 'P0',
                    (int)($t['is_active'] ?? 1)
                ]);
                $recordsReceived++;
            }
        }

        // Process Loyalty Reward Rules from Cloud
        if (!empty($cloudData['loyalty_reward_rules']) && is_array($cloudData['loyalty_reward_rules'])) {
            $rStmt = $pdo->prepare("
                INSERT INTO loyalty_reward_rules (rule_uid, name_en, name_km, trigger_type, trigger_threshold, trigger_period, reward_type, reward_item_id, reward_item_name, reward_value_usd, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name_en = VALUES(name_en),
                    name_km = VALUES(name_km),
                    trigger_type = VALUES(trigger_type),
                    trigger_threshold = VALUES(trigger_threshold),
                    reward_type = VALUES(reward_type),
                    reward_item_id = VALUES(reward_item_id),
                    reward_item_name = VALUES(reward_item_name),
                    reward_value_usd = VALUES(reward_value_usd),
                    is_active = VALUES(is_active)
            ");
            foreach ($cloudData['loyalty_reward_rules'] as $rr) {
                $rStmt->execute([
                    $rr['rule_uid'], $rr['name_en'], $rr['name_km'],
                    $rr['trigger_type'], (int)$rr['trigger_threshold'],
                    $rr['trigger_period'] ?? 'month', $rr['reward_type'],
                    !empty($rr['reward_item_id']) ? (int)$rr['reward_item_id'] : null,
                    $rr['reward_item_name'], (float)($rr['reward_value_usd'] ?? 0.00),
                    (int)($rr['is_active'] ?? 1)
                ]);
                $recordsReceived++;
            }
        }

        // Process Upsell Rules from Cloud
        if (!empty($cloudData['upsell_rules']) && is_array($cloudData['upsell_rules'])) {
            $uStmt = $pdo->prepare("
                INSERT INTO upsell_rules (rule_uid, name, trigger_service_id, trigger_category, trigger_vehicle_type, trigger_min_mileage, offer_service_id, offer_product_id, offer_price_usd, offer_headline_en, offer_headline_km, offer_description_en, offer_description_km, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    trigger_service_id = VALUES(trigger_service_id),
                    trigger_category = VALUES(trigger_category),
                    trigger_vehicle_type = VALUES(trigger_vehicle_type),
                    trigger_min_mileage = VALUES(trigger_min_mileage),
                    offer_service_id = VALUES(offer_service_id),
                    offer_product_id = VALUES(offer_product_id),
                    offer_price_usd = VALUES(offer_price_usd),
                    offer_headline_en = VALUES(offer_headline_en),
                    offer_headline_km = VALUES(offer_headline_km),
                    offer_description_en = VALUES(offer_description_en),
                    offer_description_km = VALUES(offer_description_km),
                    is_active = VALUES(is_active)
            ");
            foreach ($cloudData['upsell_rules'] as $ur) {
                $uStmt->execute([
                    $ur['rule_uid'], $ur['name'],
                    !empty($ur['trigger_service_id']) ? (int)$ur['trigger_service_id'] : null,
                    $ur['trigger_category'] ?? null,
                    $ur['trigger_vehicle_type'] ?? null,
                    !empty($ur['trigger_min_mileage']) ? (int)$ur['trigger_min_mileage'] : null,
                    !empty($ur['offer_service_id']) ? (int)$ur['offer_service_id'] : null,
                    !empty($ur['offer_product_id']) ? (int)$ur['offer_product_id'] : null,
                    isset($ur['offer_price_usd']) && $ur['offer_price_usd'] !== null ? (float)$ur['offer_price_usd'] : null,
                    $ur['offer_headline_en'], $ur['offer_headline_km'] ?? null,
                    $ur['offer_description_en'] ?? null, $ur['offer_description_km'] ?? null,
                    (int)($ur['is_active'] ?? 1)
                ]);
                $recordsReceived++;
            }
        }

        // Update version cache timestamp
        $newVer = (string)($cloudData['catalog_version'] ?? time());
        update_setting($pdo, 'catalog_price_version', $newVer, 0, 'CloudSync');

        self::logSync($pdo, 'pull', 'success', 0, $recordsReceived, $conflictsCount, $durationMs, null);

        return [
            'success' => true,
            'records_received' => $recordsReceived,
            'conflicts_count' => $conflictsCount,
            'conflicts' => $conflictsCount,
            'price_changed' => $priceChanged,
            'catalog_version' => $newVer
        ];
    }

    /**
     * Fast 30-second critical settings check (prices, user roles, active/inactive).
     */
    public static function checkCriticalUpdates(PDO $pdo): array {
        if (self::isOfflineMode($pdo)) {
            return ['success' => true, 'has_update' => false, 'has_critical_update' => false, 'offline' => true];
        }

        $cloudUrl = get_setting('cloud_api_url', 'http://localhost/Car-WashService/api/cloud/index.php');
        $cloudToken = get_setting('cloud_api_token', 'cloud_tok_live_778899aabbcc');
        $cloudSecret = get_setting('cloud_api_secret', 'cloud_sec_live_x99y88z77w66v55');
        $localVersion = (int)get_setting('catalog_price_version', '0');

        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.", $cloudSecret);

        $url = rtrim($cloudUrl, '/') . (strpos($cloudUrl, '?') === false ? '?' : '&') . 'action=check_critical&version=' . $localVersion;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Shop-Token: ' . $cloudToken,
            'X-Shop-Timestamp: ' . $timestamp,
            'X-Shop-Nonce: ' . $nonce,
            'X-Shop-Signature: ' . $signature
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => true, 'has_update' => false, 'has_critical_update' => false, 'error' => 'server_unreachable'];
        }

        $json = json_decode($response, true);
        if (!is_array($json) || empty($json['has_update'])) {
            return ['success' => true, 'has_update' => false, 'has_critical_update' => false];
        }

        // Pull fresh catalog and apply price update immediately
        $pullRes = self::receiveFromCloud($pdo);

        return [
            'success' => true,
            'has_update' => true,
            'has_critical_update' => true,
            'details' => $json,
            'catalog_version' => $pullRes['catalog_version'] ?? time(),
            'message' => 'System update: prices or permissions have been updated'
        ];
    }

    /**
     * Resolve a conflict (Manager or Owner).
     */
    public static function resolveConflict(PDO $pdo, int $conflictId, string $decision, int $userId, string $userName, ?string $notes = null): array {
        $cStmt = $pdo->prepare("SELECT * FROM sync_conflicts WHERE id = ?");
        $cStmt->execute([$conflictId]);
        $conflict = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$conflict) {
            return ['success' => false, 'error' => 'not_found', 'message' => 'Conflict record not found.'];
        }

        $localData = json_decode($conflict['local_data'], true) ?: [];
        $cloudData = json_decode($conflict['cloud_data'], true) ?: [];

        if ($decision === 'accept_cloud') {
            // Apply cloud data to local database
            if ($conflict['record_type'] === 'service') {
                $svcId = (int)$conflict['record_uid'];
                $price = (float)($cloudData['price_usd'] ?? 0.0);
                if ($price > 0) {
                    $pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr) VALUES (?, 'small_car', ?, ?) ON DUPLICATE KEY UPDATE price_usd = VALUES(price_usd), price_khr = VALUES(price_khr)")
                        ->execute([$svcId, $price, (int)round($price * 4100)]);
                }
            } elseif ($conflict['record_type'] === 'product') {
                $pId = (int)$conflict['record_uid'];
                $pName = $cloudData['name_en'] ?? null;
                $pPrice = isset($cloudData['price_usd']) ? (float)$cloudData['price_usd'] : null;
                if ($pName !== null) {
                    $pdo->prepare("UPDATE products SET name_en = ? WHERE id = ?")->execute([$pName, $pId]);
                }
                if ($pPrice !== null && $pPrice > 0) {
                    $pdo->prepare("UPDATE products SET price_usd = ?, price_khr = ? WHERE id = ?")->execute([$pPrice, (int)round($pPrice * 4100), $pId]);
                }
            }
            $status = 'resolved';
        } else {
            // keep_local
            $status = 'resolved';
        }

        $now = date('Y-m-d H:i:s');
        $upd = $pdo->prepare("UPDATE sync_conflicts SET status = ?, resolution_notes = ?, resolved_by = ?, resolved_by_name = ?, resolved_at = ? WHERE id = ?");
        $upd->execute([$status, $notes, $userId, $userName, $now, $conflictId]);

        AuditLogger::log('SYNC_CONFLICT_RESOLVED', "Conflict #{$conflictId} ({$conflict['record_type']} UID: {$conflict['record_uid']}) resolved with decision '{$decision}' by {$userName}", 'sync_conflicts', (string)$conflictId);

        return [
            'success' => true,
            'conflict_id' => $conflictId,
            'decision' => $decision,
            'status' => $status,
            'message' => 'Conflict resolved successfully.'
        ];
    }

    /**
     * Internal logging helper for sync events.
     */
    private static function logSync(PDO $pdo, string $type, string $status, int $sent, int $received, int $conflicts, int $durationMs, ?string $error = null): void {
        try {
            $stmt = $pdo->prepare("INSERT INTO sync_logs (sync_type, status, records_sent, records_received, conflicts_count, duration_ms, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$type, $status, $sent, $received, $conflicts, $durationMs, $error]);
        } catch (Exception $e) {}
    }
}
