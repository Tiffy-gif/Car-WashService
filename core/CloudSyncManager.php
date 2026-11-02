<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/PerformanceAnalytics.php';

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
        $perfData = PerformanceAnalytics::getDailyPerformanceMetrics($pdo, 7);
        $perfCount = count($perfData);

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
            'performance_metrics' => $perfCount,
            'total' => $total
        ];
    }

    /**
     * Send unsynced records to Cloud.
     * Compresses with gzip, signs with HMAC-SHA256, and retries up to 3 times on failure.
     */
    public static function sendToCloud(PDO $pdo, bool $force = false, int $retryDelaySeconds = 10, int $maxRetries = 3): array {
        $startTime = microtime(true);

        $cloudUrl = get_setting('cloud_api_url', '');
        $cloudToken = get_setting('cloud_api_token', '');
        $cloudSecret = get_setting('cloud_api_secret', '');
        $shopId = get_setting('cloud_shop_id', '');
        $shopCode = get_setting('shop_code', $shopId);

        if (empty($cloudUrl) || empty($cloudToken) || empty($cloudSecret)) {
            return [
                'success' => false,
                'error' => 'not_configured',
                'message' => 'Cloud Sync API URL, Token, or Secret is not configured in Settings.'
            ];
        }

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

        // 1. Gather unsynced records
        // Sales
        $salesStmt = $pdo->query("SELECT * FROM orders WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch line items for these sales
        $salesData = [];
        foreach ($salesRows as $sr) {
            $ordId = (int)$sr['id'];
            $items = $pdo->query("SELECT * FROM order_items WHERE order_id = {$ordId}")->fetchAll(PDO::FETCH_ASSOC);
            $cleanItems = [];
            foreach ($items as $it) {
                $cleanItems[] = [
                    'item_id' => (int)($it['service_id'] ?? $it['product_id'] ?? $it['id'] ?? 0),
                    'item_name' => $it['service_name'] ?? $it['item_name'] ?? 'Item',
                    'item_type' => $it['item_type'] ?? 'service',
                    'quantity' => (float)($it['qty'] ?? $it['quantity'] ?? 1),
                    'unit_price_usd' => (float)($it['price_usd'] ?? $it['unit_price_usd'] ?? 0),
                    'total_price_usd' => (float)($it['total_usd'] ?? $it['total_price_usd'] ?? 0),
                ];
            }

            $salesData[] = [
                'client_id' => $sr['order_uid'] ?: ('sale-' . $sr['id']),
                'invoice_number' => $sr['order_number'] ?: ('INV-' . $sr['id']),
                'customer_phone' => $sr['customer_phone'] ?? '',
                'cashier_name' => $sr['cashier_name'] ?? 'Staff',
                'total_amount_usd' => (float)($sr['total_usd'] ?? 0),
                'total_amount_khr' => (float)($sr['total_khr'] ?? 0),
                'tax_amount_usd' => (float)($sr['tax_usd'] ?? 0),
                'discount_amount_usd' => (float)($sr['discount_usd'] ?? 0),
                'payment_method' => $sr['payment_method'] ?? 'cash_usd',
                'khqr_reference' => $sr['khqr_ref'] ?? $sr['payment_ref'] ?? null,
                'khqr_status' => $sr['khqr_status'] ?? (($sr['payment_status'] ?? '') === 'paid' ? 'verified' : 'na'),
                'status' => $sr['status'] ?? 'completed',
                'local_created_at' => $sr['created_at'] ?? date('Y-m-d H:i:s'),
                'items' => $cleanItems
            ];
        }

        // Job orders
        $jobsStmt = $pdo->query("
            SELECT ot.*, 
                   o.order_number, 
                   o.customer_name, 
                   o.plate_number AS vehicle_plate, 
                   o.vehicle_model, 
                   b.bay_number, 
                   u.full_name AS technician_name
            FROM order_tasks ot
            LEFT JOIN orders o ON ot.order_id = o.id
            LEFT JOIN bays b ON ot.bay_id = b.id
            LEFT JOIN users u ON ot.assigned_user_id = u.id
            WHERE ot.is_synced = 0 OR ot.is_synced IS NULL
            ORDER BY ot.id ASC
            LIMIT 100
        ");
        $jobsRows = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);
        $jobsData = [];
        foreach ($jobsRows as $jr) {
            $dur = (int)($jr['actual_duration_minutes'] ?: $jr['base_duration_minutes'] ?: 30);
            $eff = !empty($jr['took_longer_than_expected']) ? 'Delayed' : 'On Time';
            $jobsData[] = [
                'client_id' => 'job-' . $jr['id'],
                'order_number' => $jr['order_number'] ?: ('JOB-' . $jr['id']),
                'customer_name' => $jr['customer_name'] ?? 'Walk-in',
                'vehicle_plate' => $jr['vehicle_plate'] ?? 'N/A',
                'vehicle_model' => $jr['vehicle_model'] ?? 'N/A',
                'bay_number' => $jr['bay_number'] ? ('Bay ' . $jr['bay_number']) : 'Bay 1',
                'technician_name' => $jr['technician_name'] ?? 'Technician',
                'service_name' => $jr['title'] ?? 'Service',
                'status' => $jr['status'] ?? 'completed',
                'start_time' => $jr['started_at'],
                'end_time' => $jr['completed_at'],
                'duration_minutes' => $dur,
                'efficiency_rating' => $eff,
                'notes' => $jr['notes'],
                'local_created_at' => $jr['created_at'] ?? date('Y-m-d H:i:s')
            ];
        }

        // Stock movements
        $stockStmt = $pdo->query("SELECT * FROM stock_movements WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $stockRows = $stockStmt->fetchAll(PDO::FETCH_ASSOC);
        $stockData = [];
        foreach ($stockRows as $sr) {
            $stockData[] = [
                'client_id' => $sr['movement_uid'] ?: ('sm-' . $sr['id']),
                'item_id' => (int)($sr['product_id'] ?? 0),
                'movement_type' => $sr['movement_type'] ?? 'sale_deduction',
                'quantity' => (float)($sr['quantity'] ?? 0),
                'balance_after' => (float)($sr['new_qty'] ?? 0),
                'unit_cost' => 0.00,
                'reference_type' => 'sale',
                'reference_id' => (string)($sr['reference_id'] ?? ''),
                'notes' => $sr['notes'] ?? 'Local stock movement',
                'created_by' => $sr['performer_name'] ?? 'local_pos'
            ];
        }

        // Shifts & Z-reports (from both pos_closings and pos_daily_closes)
        $shiftData = [];
        $posClosingsStmt = $pdo->query("SELECT * FROM pos_closings WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 50");
        $posClosings = $posClosingsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($posClosings as $sc) {
            $shiftData[] = [
                'client_id' => 'shift-' . $sc['id'],
                'z_report_number' => $sc['z_report_number'] ?: ('Z-' . $sc['id']),
                'register_name' => $sc['device_name'] ?: ('POS-' . ($sc['pos_device_id'] ?? 1)),
                'cashier_name' => $sc['cashier_name'] ?? 'Cashier',
                'shift_start' => ($sc['close_date'] ?? date('Y-m-d')) . ' 08:00:00',
                'shift_end' => $sc['closed_at'] ?? $sc['created_at'] ?? date('Y-m-d H:i:s'),
                'opening_cash_usd' => (float)($sc['opening_cash_usd'] ?? 0),
                'opening_cash_khr' => 0.00,
                'expected_cash_usd' => (float)($sc['expected_cash_usd'] ?? 0),
                'expected_cash_khr' => 0.00,
                'counted_cash_usd' => (float)($sc['counted_cash_usd'] ?? 0),
                'counted_cash_khr' => (float)($sc['counted_cash_khr'] ?? 0),
                'variance_usd' => (float)($sc['difference_usd'] ?? 0),
                'variance_khr' => 0.00,
                'status' => $sc['status'] ?? 'balanced',
                'notes' => $sc['notes'] ?? 'Shift close'
            ];
        }

        // Daily closes
        try {
            $dailyStmt = $pdo->query("SELECT * FROM pos_daily_closes WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 50");
            $dailyClosings = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dailyClosings as $dc) {
                $shiftData[] = [
                    'client_id' => 'shift-dc-' . $dc['id'],
                    'z_report_number' => $dc['z_report_number'] ?: ('Z-DC-' . $dc['id']),
                    'register_name' => $dc['device_name'] ?: ('POS-' . ($dc['pos_device_id'] ?? 1)),
                    'cashier_name' => $dc['closed_by_name'] ?? 'Cashier',
                    'shift_start' => ($dc['close_date'] ?? date('Y-m-d')) . ' ' . (!empty($dc['opening_time']) ? $dc['opening_time'] : '08:00:00'),
                    'shift_end' => $dc['closed_at'] ?? $dc['created_at'] ?? date('Y-m-d H:i:s'),
                    'opening_cash_usd' => 0.00,
                    'opening_cash_khr' => 0.00,
                    'expected_cash_usd' => (float)($dc['expected_cash_usd'] ?? 0),
                    'expected_cash_khr' => 0.00,
                    'counted_cash_usd' => (float)($dc['counted_cash_usd'] ?? 0),
                    'counted_cash_khr' => (float)($dc['counted_cash_khr'] ?? 0),
                    'variance_usd' => (float)($dc['difference_usd'] ?? 0),
                    'variance_khr' => 0.00,
                    'status' => $dc['status'] ?? 'balanced',
                    'notes' => $dc['notes'] ?? 'Daily close'
                ];
            }
        } catch (Exception $e) {}

        // Timecards
        $tcStmt = $pdo->query("SELECT * FROM staff_timecards WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $tcRows = $tcStmt->fetchAll(PDO::FETCH_ASSOC);
        $tcData = [];
        foreach ($tcRows as $tc) {
            $tcData[] = [
                'client_id' => $tc['timecard_uid'] ?: ('tc-' . $tc['id']),
                'user_id' => (int)$tc['user_id'],
                'clock_in' => $tc['clock_in'],
                'clock_out' => $tc['clock_out'],
                'total_hours' => (float)$tc['total_hours'],
                'status' => $tc['status'],
                'notes' => $tc['notes']
            ];
        }

        // Audit logs
        $alStmt = $pdo->query("SELECT * FROM audit_logs WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $alRows = $alStmt->fetchAll(PDO::FETCH_ASSOC);
        $alData = [];
        foreach ($alRows as $al) {
            $alData[] = [
                'client_id' => $al['log_uid'] ?: ('aud-' . $al['id']),
                'username' => $al['username'] ?? 'staff',
                'role' => $al['role'] ?? 'Staff',
                'action' => $al['action'] ?? 'ACTION',
                'entity_type' => $al['entity_type'] ?? 'system',
                'entity_id' => (string)($al['entity_id'] ?? ''),
                'details' => $al['details'],
                'created_at' => $al['created_at']
            ];
        }

        // CRM events (loyalty transactions + offline-queued reminder events)
        $crmStmt = $pdo->query("
            SELECT lt.*, c.phone AS customer_phone, c.full_name AS customer_name, c.plate_number AS vehicle_plate
            FROM loyalty_transactions lt
            LEFT JOIN customers c ON lt.customer_id = c.id
            WHERE lt.is_synced = 0 OR lt.is_synced IS NULL 
            ORDER BY lt.id ASC 
            LIMIT 100
        ");
        $crmRows = $crmStmt->fetchAll(PDO::FETCH_ASSOC);
        $crmData = [];
        foreach ($crmRows as $crm) {
            $crmData[] = [
                'client_id' => 'crm-' . $crm['id'],
                'phone' => $crm['customer_phone'] ?? '',
                'full_name' => $crm['customer_name'] ?? 'Customer',
                'vehicle_plate' => $crm['vehicle_plate'] ?? '',
                'points_added' => (int)($crm['points'] ?? 0),
                'spend_added' => 0.00,
                'visit_date' => date('Y-m-d', strtotime($crm['created_at']))
            ];
        }

        $msgStmt = $pdo->query("SELECT * FROM crm_message_queue WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $msgRows = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($msgRows as $md) {
            $meta = !empty($md['metadata_json']) ? json_decode($md['metadata_json'], true) : [];
            $crmData[] = [
                'event_type' => $md['reminder_type'] ?? 'reminder_sms',
                'event_uid' => $md['event_uid'] ?: ('msg-' . $md['id']),
                'customer_phone' => $md['customer_phone'] ?? '',
                'customer_name' => $meta['customer_name'] ?? 'Customer',
                'token_hash' => $meta['token_hash'] ?? '',
                'raw_token' => $meta['raw_token'] ?? '',
                'token_expires_at' => $meta['token_expires_at'] ?? '',
                'attempt_count' => (int)($meta['attempt_count'] ?? 1),
                'event_timestamp' => $md['created_at'],
                'notes' => $md['message_body'] ?? ''
            ];
        }

        // Upsell logs
        $upsellStmt = $pdo->query("SELECT * FROM upsell_logs WHERE is_synced = 0 OR is_synced IS NULL ORDER BY id ASC LIMIT 100");
        $upsellRows = $upsellStmt->fetchAll(PDO::FETCH_ASSOC);
        $upsellData = [];
        foreach ($upsellRows as $ul) {
            $upsellData[] = [
                'client_id' => $ul['log_uid'] ?: ('up-' . $ul['id']),
                'rule_name' => $ul['rule_name'] ?? '',
                'cashier_name' => $ul['cashier_name'] ?? 'Cashier',
                'order_id' => (int)$ul['order_id'],
                'plate_number' => $ul['plate_number'] ?? '',
                'status' => $ul['status'] ?? 'offered',
                'reason_declined' => $ul['reason_declined'],
                'offered_at' => $ul['offered_at'],
                'action_at' => $ul['action_at']
            ];
        }

        // Performance metrics (rolling 7-day snapshot)
        $perfData = PerformanceAnalytics::getDailyPerformanceMetrics($pdo, 7);

        $totalRecordsToSend = count($salesData) + count($jobsData) + count($stockData) + count($shiftData) + count($tcData) + count($alData) + count($crmData) + count($upsellData) + count($perfData);

        if ($totalRecordsToSend === 0 && !$force) {
            return [
                'success' => true,
                'records_sent' => 0,
                'message' => 'All local records are up to date with cloud.'
            ];
        }

        // 2. Pack contract payload {shop_code, push_data:{sales, job_orders, stock_movements, shifts_and_zreports, audit_logs, crm_events, upsell_logs, timecards, performance_metrics}}
        $payload = [
            'shop_code' => $shopCode,
            'push_data' => [
                'sales' => $salesData,
                'job_orders' => $jobsData,
                'stock_movements' => $stockData,
                'shifts_and_zreports' => $shiftData,
                'audit_logs' => $alData,
                'crm_events' => $crmData,
                'upsell_logs' => $upsellData,
                'timecards' => $tcData,
                'performance_metrics' => $perfData
            ]
        ];

        // 3. Send using private request helper (with retry loop, gzip, and HMAC signature)
        $reqRes = self::request($payload, $maxRetries, $retryDelaySeconds);
        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        if (!$reqRes['success']) {
            $err = $reqRes['message'] ?? ($reqRes['error'] ?? 'Sync failed');
            self::logSync($pdo, 'push', 'failed', $totalRecordsToSend, 0, 0, $durationMs, $err);
            return [
                'success' => false,
                'error' => $reqRes['error'] ?? 'push_failed',
                'attempts' => $reqRes['attempts'] ?? 1,
                'message' => $err
            ];
        }

        $resJson = $reqRes['data'] ?? [];
        $isCloudSuccess = (
            (!isset($resJson['status']) && !isset($resJson['success'])) ||
            (isset($resJson['status']) && $resJson['status'] === 'success') ||
            (!empty($resJson['success']))
        );

        if (!$isCloudSuccess) {
            $err = $resJson['message'] ?? 'Cloud reported failure or rejected push payload.';
            self::logSync($pdo, 'push', 'failed', $totalRecordsToSend, 0, 0, $durationMs, $err);
            return [
                'success' => false,
                'error' => 'cloud_rejected',
                'message' => $err
            ];
        }

        // 4. Mark rows is_synced = 1 ONLY for the ids in confirmed_ids of that entity
        $confirmed = $resJson['confirmed_ids'] ?? $resJson['stats']['confirmed_ids'] ?? [];
        $now = date('Y-m-d H:i:s');
        $confirmedTotal = 0;

        // Confirm sales
        if (!empty($confirmed['sales']) && is_array($confirmed['sales'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['sales']));
            $pdo->exec("UPDATE orders SET is_synced = 1, synced_at = '{$now}' WHERE order_uid IN ({$inList}) OR order_number IN ({$inList}) OR CONCAT('sale-', id) IN ({$inList})");
            $confirmedTotal += count($confirmed['sales']);
        }

        // Confirm job orders
        if (!empty($confirmed['job_orders']) && is_array($confirmed['job_orders'])) {
            foreach ($confirmed['job_orders'] as $jUid) {
                $id = (int)str_replace('job-', '', str_ireplace('JOB-', '', (string)$jUid));
                if ($id > 0) {
                    $pdo->exec("UPDATE order_tasks SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
                    $confirmedTotal++;
                }
            }
        }

        // Confirm stock movements
        if (!empty($confirmed['stock_movements']) && is_array($confirmed['stock_movements'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['stock_movements']));
            $pdo->exec("UPDATE stock_movements SET is_synced = 1, synced_at = '{$now}' WHERE movement_uid IN ({$inList}) OR CONCAT('sm-', id) IN ({$inList})");
            $confirmedTotal += count($confirmed['stock_movements']);
        }

        // Confirm shifts and zreports
        $confirmedShifts = $confirmed['shifts_and_zreports'] ?? $confirmed['shifts'] ?? [];
        if (!empty($confirmedShifts) && is_array($confirmedShifts)) {
            foreach ($confirmedShifts as $shUid) {
                $uidStr = (string)$shUid;
                if (str_starts_with($uidStr, 'shift-dc-')) {
                    $dcId = (int)substr($uidStr, 9);
                    if ($dcId > 0) {
                        try { $pdo->exec("UPDATE pos_daily_closes SET is_synced = 1, synced_at = '{$now}' WHERE id = {$dcId}"); } catch (Exception $e) {}
                        $confirmedTotal++;
                    }
                } else {
                    $id = (int)str_replace('shift-', '', str_ireplace('SHIFT-', '', $uidStr));
                    if ($id > 0) {
                        $pdo->exec("UPDATE pos_closings SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
                        $confirmedTotal++;
                    }
                }
            }
        }

        // Confirm timecards
        if (!empty($confirmed['timecards']) && is_array($confirmed['timecards'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['timecards']));
            $pdo->exec("UPDATE staff_timecards SET is_synced = 1, synced_at = '{$now}' WHERE timecard_uid IN ({$inList}) OR CONCAT('tc-', id) IN ({$inList})");
            $confirmedTotal += count($confirmed['timecards']);
        }

        // Confirm audit logs
        if (!empty($confirmed['audit_logs']) && is_array($confirmed['audit_logs'])) {
            foreach ($confirmed['audit_logs'] as $alUid) {
                $id = (int)str_replace('aud-', '', str_ireplace('AUD-', '', (string)$alUid));
                if ($id > 0) {
                    $pdo->exec("UPDATE audit_logs SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
                    $confirmedTotal++;
                }
            }
        }

        // Confirm CRM events
        if (!empty($confirmed['crm_events']) && is_array($confirmed['crm_events'])) {
            foreach ($confirmed['crm_events'] as $crmUid) {
                $uidStr = (string)$crmUid;
                if (str_starts_with($uidStr, 'crm-') || is_numeric($uidStr)) {
                    $id = (int)str_replace('crm-', '', $uidStr);
                    if ($id > 0) {
                        $pdo->exec("UPDATE loyalty_transactions SET is_synced = 1, synced_at = '{$now}' WHERE id = {$id}");
                        $confirmedTotal++;
                    }
                } else {
                    $pdo->prepare("UPDATE crm_message_queue SET is_synced = 1, synced_at = '{$now}', status = 'sent', sent_at = '{$now}' WHERE event_uid = ?")->execute([$uidStr]);
                    $confirmedTotal++;
                }
            }
        }

        // Confirm upsell logs
        if (!empty($confirmed['upsell_logs']) && is_array($confirmed['upsell_logs'])) {
            $inList = implode(',', array_map([$pdo, 'quote'], $confirmed['upsell_logs']));
            $pdo->exec("UPDATE upsell_logs SET is_synced = 1, synced_at = '{$now}' WHERE log_uid IN ({$inList}) OR CONCAT('up-', id) IN ({$inList})");
            $confirmedTotal += count($confirmed['upsell_logs']);
        }

        // Record last sync timestamp
        update_setting($pdo, 'cloud_last_sync_at', $now, 0, 'CloudSync');

        self::logSync($pdo, 'push', 'success', $totalRecordsToSend, 0, 0, $durationMs, null);

        return [
            'success' => true,
            'sent_count' => $totalRecordsToSend,
            'records_sent' => $totalRecordsToSend,
            'confirmed_count' => $confirmedTotal,
            'duration_ms' => $durationMs,
            'last_sync_at' => $now,
            'confirmed_ids' => $confirmed,
            'cloud_response' => $resJson
        ];
    }

    /**
     * Pull updates from Cloud (users, products & prices, settings, queue rules, membership tiers).
     * Detects conflicts and stores both versions for manager resolution.
     */
    public static function receiveFromCloud(PDO $pdo): array {
        $startTime = microtime(true);

        $cloudUrl = get_setting('cloud_api_url', '');
        $cloudToken = get_setting('cloud_api_token', '');
        $cloudSecret = get_setting('cloud_api_secret', '');

        if (empty($cloudUrl) || empty($cloudToken) || empty($cloudSecret)) {
            return [
                'success' => false,
                'error' => 'not_configured',
                'message' => 'Cloud Sync API URL, Token, or Secret is not configured in Settings.'
            ];
        }

        if (self::isOfflineMode($pdo)) {
            return ['success' => false, 'offline' => true, 'message' => 'Offline mode active.'];
        }

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

        // Process Staff Users from Cloud (PIN hash handling)
        $cloudUsers = $cloudData['users'] ?? ($cloudData['master_data']['users'] ?? ($cloudData['data']['users'] ?? []));
        if (!empty($cloudUsers) && is_array($cloudUsers)) {
            $hasLocalPinHash = false;
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                $hasLocalPinHash = in_array('local_pin_hash', $cols, true);
            } catch (Throwable $t) {
                try {
                    $tInfo = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
                    $hasLocalPinHash = in_array('local_pin_hash', array_column($tInfo, 'name'), true);
                } catch (Throwable $t2) {}
            }

            foreach ($cloudUsers as $cu) {
                $username = trim((string)($cu['username'] ?? ''));
                if ($username === '') continue;

                $fullName = trim((string)($cu['full_name'] ?? $username));
                $role = strtolower(trim((string)($cu['role'] ?? 'cashier')));
                $isActive = isset($cu['is_active']) ? (int)$cu['is_active'] : 1;
                $status = ($isActive === 1) ? 'active' : 'inactive';

                // If the pulled row contains local_pin_hash, store/use that bcrypt hash and ignore local_pin;
                // fall back to local_pin only when the hash is absent (legacy). Never log or display a PIN.
                $pinHash = null;
                if (!empty($cu['local_pin_hash'])) {
                    $pinHash = (string)$cu['local_pin_hash'];
                } elseif (isset($cu['local_pin']) && $cu['local_pin'] !== null && trim((string)$cu['local_pin']) !== '') {
                    $pinHash = password_hash(trim((string)$cu['local_pin']), PASSWORD_BCRYPT);
                }

                $existStmt = $pdo->prepare("SELECT id, password_hash, role, status FROM users WHERE username = ? LIMIT 1");
                $existStmt->execute([$username]);
                $existingUser = $existStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingUser) {
                    $updateSql = "UPDATE users SET full_name = ?, role = ?, status = ?";
                    $params = [$fullName, $role, $status];
                    if ($pinHash !== null) {
                        $updateSql .= ", password_hash = ?";
                        $params[] = $pinHash;
                        if ($hasLocalPinHash) {
                            $updateSql .= ", local_pin_hash = ?";
                            $params[] = $pinHash;
                        }
                    }
                    $updateSql .= " WHERE id = ?";
                    $params[] = $existingUser['id'];
                    $pdo->prepare($updateSql)->execute($params);
                } else {
                    $finalHash = $pinHash ?? password_hash('123456', PASSWORD_BCRYPT);
                    if ($hasLocalPinHash) {
                        $ins = $pdo->prepare("INSERT INTO users (username, password_hash, local_pin_hash, full_name, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->execute([$username, $finalHash, $finalHash, $fullName, $role, $status]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
                        $ins->execute([$username, $finalHash, $fullName, $role, $status]);
                    }
                }
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
        $cloudUrl = get_setting('cloud_api_url', '');
        $cloudToken = get_setting('cloud_api_token', '');
        $cloudSecret = get_setting('cloud_api_secret', '');

        if (empty($cloudUrl) || empty($cloudToken) || empty($cloudSecret)) {
            return ['success' => false, 'error' => 'not_configured', 'has_update' => false, 'has_critical_update' => false];
        }

        if (self::isOfflineMode($pdo)) {
            return ['success' => true, 'has_update' => false, 'has_critical_update' => false, 'offline' => true];
        }

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
     * Private helper to perform signed, gzipped HTTP POST requests to the Cloud Master.
     * Retries up to 3 times, 10s apart, with fresh timestamp, nonce, and signature per attempt.
     * TLS verification is ON by default; setting cloud_allow_insecure_tls is honoured ONLY when APP_ENV === 'development'.
     */
    private static function request(array $body, int $maxRetries = 3, int $retryDelaySeconds = 10): array {
        $cloudUrl = get_setting('cloud_api_url', '');
        $cloudToken = get_setting('cloud_api_token', '');
        $cloudSecret = get_setting('cloud_api_secret', '');

        if (empty($cloudUrl) || empty($cloudToken) || empty($cloudSecret)) {
            return [
                'success' => false,
                'error' => 'not_configured',
                'message' => 'Cloud Sync API URL, Token, or Secret is not configured in Settings.'
            ];
        }

        $jsonPayload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return [
                'success' => false,
                'error' => 'json_encode_failed',
                'message' => 'Failed to JSON encode request payload.'
            ];
        }

        $compressedPayload = gzencode($jsonPayload, 9);
        if ($compressedPayload === false) {
            return [
                'success' => false,
                'error' => 'gzip_failed',
                'message' => 'Failed to compress request payload with gzip.'
            ];
        }

        // TLS verification: ON by default, allow setting cloud_allow_insecure_tls = 1 ONLY in development
        $allowInsecureTls = (defined('APP_ENV') && APP_ENV === 'development' && get_setting('cloud_allow_insecure_tls', '0') === '1');
        $sslVerifyPeer = !$allowInsecureTls;
        $sslVerifyHost = $allowInsecureTls ? 0 : 2;

        $attempt = 0;
        $response = null;
        $httpCode = 0;
        $lastError = '';

        while ($attempt < $maxRetries) {
            $attempt++;

            // Fresh timestamp and nonce for every attempt (prevents replay detection on delayed retries)
            $timestamp = (string)time();
            $nonce = bin2hex(random_bytes(16));
            $messageToSign = $timestamp . '.' . $nonce . '.' . $compressedPayload;
            $signature = hash_hmac('sha256', $messageToSign, $cloudSecret);

            $headers = [
                'Content-Type: application/json',
                'Content-Encoding: gzip',
                'X-Shop-Token: ' . $cloudToken,
                'X-Shop-Timestamp: ' . $timestamp,
                'X-Shop-Nonce: ' . $nonce,
                'X-Shop-Signature: ' . $signature
            ];

            $ch = curl_init($cloudUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $compressedPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerifyPeer);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerifyHost);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
                break;
            }

            $lastError = $curlError ?: ("HTTP " . $httpCode . ($response ? (': ' . substr((string)$response, 0, 200)) : ''));
            if ($attempt < $maxRetries && $retryDelaySeconds > 0) {
                sleep($retryDelaySeconds);
            }
        }

        if ($httpCode < 200 || $httpCode >= 300 || !$response) {
            return [
                'success' => false,
                'error' => 'cloud_communication_failed',
                'http_code' => $httpCode,
                'attempts' => $attempt,
                'message' => "Failed to reach cloud server after {$attempt} attempts: {$lastError}"
            ];
        }

        // Decompress response if gzip encoded
        $bodyStr = (string)$response;
        if (substr($bodyStr, 0, 2) === "\x1f\x8b") {
            $decompressed = @gzdecode($bodyStr);
            if ($decompressed !== false) {
                $bodyStr = $decompressed;
            }
        }

        $decodedJson = json_decode($bodyStr, true);
        if (!is_array($decodedJson)) {
            return [
                'success' => false,
                'error' => 'invalid_cloud_response',
                'http_code' => $httpCode,
                'message' => 'Malformed or non-JSON response from cloud server.',
                'raw_body' => substr($bodyStr, 0, 300)
            ];
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'attempts' => $attempt,
            'data' => $decodedJson
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
