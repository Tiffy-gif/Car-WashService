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
                   o.vehicle_type AS vehicle_model, 
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
            SELECT lt.*, c.phone AS customer_phone, c.name AS customer_name
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
     * Pull updates from Cloud (users, catalog items, categories, settings, queue rules, membership tiers, vehicle specs, reminder rules).
     * Replaces legacy GET with signed POST request using self::request().
     * Entire ingestion runs within a database transaction.
     * Rules:
     * - Never delete (deactivate only)
     * - Never overwrite a local record that changed after the last pull; flag a sync_conflicts row instead
     * - Only sync whitelisted settings keys and NEVER overwrite cloud_api_*, telegram, device key, demo_mode or other secrets
     * - Users: store local_pin_hash, never a plain PIN, and map role string to local role_id
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

        $shopId = get_setting('cloud_shop_id', '');
        $shopCode = get_setting('shop_code', $shopId);

        $payload = [
            'shop_code' => $shopCode,
            'pull_request' => [
                'entities' => [
                    'users',
                    'catalog_items',
                    'categories',
                    'settings',
                    'queue_rules',
                    'membership_tiers',
                    'vehicle_specs',
                    'reminder_rules'
                ],
                'local_versions' => [
                    'catalog_version' => (int)get_setting('catalog_price_version', '0'),
                    'settings_version' => (int)get_setting('settings_version', '1')
                ]
            ]
        ];

        // Send signed POST request using request() helper
        $reqRes = self::request($payload);
        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        if (!$reqRes['success']) {
            $err = $reqRes['message'] ?? ($reqRes['error'] ?? 'Failed to connect to cloud during pull.');
            self::logSync($pdo, 'pull', 'failed', 0, 0, 0, $durationMs, $err);
            return ['success' => false, 'error' => $reqRes['error'] ?? 'pull_failed', 'message' => $err];
        }

        $cloudData = $reqRes['data'] ?? [];
        $isCloudSuccess = (
            (!isset($cloudData['status']) && !isset($cloudData['success'])) ||
            (isset($cloudData['status']) && $cloudData['status'] === 'success') ||
            (!empty($cloudData['success']))
        );

        if (!$isCloudSuccess) {
            $err = $cloudData['message'] ?? 'Cloud returned invalid pull response.';
            self::logSync($pdo, 'pull', 'failed', 0, 0, 0, $durationMs, $err);
            return ['success' => false, 'error' => 'invalid_cloud_response', 'message' => $err];
        }

        $masterData = $cloudData['master_data'] ?? $cloudData;

        $recordsReceived = 0;
        $conflictsCount = 0;
        $priceChanged = false;

        $lastPullAt = get_setting('cloud_last_pull_at', '2000-01-01 00:00:00');
        $now = date('Y-m-d H:i:s');

        // Execute all updates inside an ACID database transaction
        $pdo->beginTransaction();
        try {
            // -------------------------------------------------------------
            // 1. Ingest Categories
            // -------------------------------------------------------------
            $catList = $masterData['categories'] ?? [];
            if (!empty($catList) && is_array($catList)) {
                $checkCat = $pdo->prepare("SELECT id, name_en, is_active, updated_at FROM categories WHERE code = ? OR name_en = ? LIMIT 1");
                $updCat = $pdo->prepare("
                    UPDATE categories SET 
                        name_en = ?, name_km = ?, icon = COALESCE(?, icon), color = COALESCE(?, color),
                        sort_order = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $insCat = $pdo->prepare("
                    INSERT INTO categories (type, code, name_en, name_km, icon, color, sort_order, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                foreach ($catList as $c) {
                    $cCode = trim((string)($c['code'] ?? ($c['sku'] ?? '')));
                    $cNameEn = trim((string)($c['name_en'] ?? ''));
                    if ($cNameEn === '') continue;
                    if ($cCode === '') $cCode = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $cNameEn));

                    $cNameKm = trim((string)($c['name_km'] ?? $cNameEn));
                    $cIcon = !empty($c['icon']) ? (string)$c['icon'] : null;
                    $cColor = !empty($c['color']) ? (string)$c['color'] : null;
                    $cSort = (int)($c['sort_order'] ?? 0);
                    $cActive = isset($c['is_active']) ? (int)$c['is_active'] : 1;
                    $cType = $c['type'] ?? 'service';

                    $checkCat->execute([$cCode, $cNameEn]);
                    $existingCat = $checkCat->fetch(PDO::FETCH_ASSOC);

                    if ($existingCat) {
                        if ((int)$existingCat['is_active'] === 1 && $cActive === 0) {
                            AuditLogger::log('CATEGORY_DEACTIVATED', "Category '{$cNameEn}' (ID #{$existingCat['id']}) deactivated via Cloud Sync", 'categories', (string)$existingCat['id'], 0, 'cloud_sync', 'system');
                        }
                        $updCat->execute([$cNameEn, $cNameKm, $cIcon, $cColor, $cSort, $cActive, $existingCat['id']]);
                    } else {
                        $insCat->execute([$cType, $cCode, $cNameEn, $cNameKm, $cIcon, $cColor, $cSort, $cActive]);
                    }
                    $recordsReceived++;
                }
            }

            // -------------------------------------------------------------
            // 2. Ingest Catalog Items (Services, Products, Prices)
            // -------------------------------------------------------------
            $catalogItems = $masterData['catalog_items'] ?? [];
            if (!empty($catalogItems) && is_array($catalogItems)) {
                $svcCheck = $pdo->prepare("SELECT * FROM services WHERE name_en = ? LIMIT 1");
                $svcIns = $pdo->prepare("
                    INSERT INTO services (name_en, name_km, description, category, category_id, base_duration_minutes, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $svcUpd = $pdo->prepare("
                    UPDATE services SET 
                        name_km = ?, category = ?, category_id = ?, base_duration_minutes = ?, is_active = ?
                    WHERE id = ?
                ");

                $prodCheck = $pdo->prepare("SELECT * FROM products WHERE name_en = ? LIMIT 1");
                $prodIns = $pdo->prepare("
                    INSERT INTO products (name_en, name_km, category, category_id, brand, cost_price_usd, price_usd, price_khr, stock_qty, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())
                ");
                $prodUpd = $pdo->prepare("
                    UPDATE products SET 
                        name_km = ?, category = ?, category_id = ?, cost_price_usd = ?, price_usd = ?, price_khr = ?, is_active = ?
                    WHERE id = ?
                ");

                $findCat = $pdo->prepare("SELECT id FROM categories WHERE name_en = ? OR code = ? LIMIT 1");

                foreach ($catalogItems as $item) {
                    $nameEn = trim((string)($item['name_en'] ?? ''));
                    if ($nameEn === '') continue;
                    $nameKm = trim((string)($item['name_km'] ?? $nameEn));
                    $itemType = trim((string)($item['item_type'] ?? 'service'));
                    $categoryName = trim((string)($item['category_name'] ?? 'wash'));
                    $isActive = isset($item['is_active']) ? (int)$item['is_active'] : 1;
                    $priceUsd = (float)($item['price_usd'] ?? 0);
                    $priceKhr = (int)($item['price_khr'] ?? round($priceUsd * 4100));
                    $costUsd = (float)($item['cost_usd'] ?? 0);
                    $durationMin = (int)($item['service_duration_min'] ?? 25);

                    // Resolve category_id
                    $catId = null;
                    if (!empty($item['category_id']) && is_numeric($item['category_id'])) {
                        $catId = (int)$item['category_id'];
                    } elseif ($categoryName !== '') {
                        $findCat->execute([$categoryName, strtolower($categoryName)]);
                        $cRow = $findCat->fetch(PDO::FETCH_ASSOC);
                        if ($cRow) $catId = (int)$cRow['id'];
                    }

                    if ($itemType === 'service') {
                        $svcCheck->execute([$nameEn]);
                        $localSvc = $svcCheck->fetch(PDO::FETCH_ASSOC);

                        if ($localSvc) {
                            $svcId = (int)$localSvc['id'];
                            // Conflict detection: If local service was modified after last pull and price differs
                            $curPrice = (float)$pdo->query("SELECT price_usd FROM service_prices WHERE service_id = {$svcId} AND vehicle_type = 'small_car'")->fetchColumn();
                            $localChanged = !empty($localSvc['updated_at']) && ($localSvc['updated_at'] > $lastPullAt);

                            if ($localChanged && abs($priceUsd - $curPrice) > 0.01) {
                                $confUid = 'CONF-SVC-' . $svcId . '-' . time();
                                $insConf = $pdo->prepare("INSERT INTO sync_conflicts (conflict_uid, record_type, record_uid, local_data, cloud_data, local_updated_at, cloud_updated_at, status, created_at) VALUES (?, 'service', ?, ?, ?, NOW(), NOW(), 'pending', NOW())");
                                $insConf->execute([
                                    $confUid,
                                    (string)$svcId,
                                    json_encode(['id' => $svcId, 'name_en' => $nameEn, 'price_usd' => $curPrice]),
                                    json_encode($item)
                                ]);
                                $conflictsCount++;
                                continue; // Never overwrite local edit
                            }

                            if ((int)$localSvc['is_active'] === 1 && $isActive === 0) {
                                AuditLogger::log('SERVICE_DEACTIVATED', "Service '{$nameEn}' (ID #{$svcId}) deactivated via Cloud Sync", 'services', (string)$svcId, 0, 'cloud_sync', 'system');
                            }
                            $svcUpd->execute([$nameKm, $categoryName, $catId, $durationMin, $isActive, $svcId]);
                            // Synchronize tiered pricing across all vehicle types (preserves distinct local vehicle prices)
                            self::syncServicePricing($pdo, $svcId, $priceUsd, $durationMin, $item);
                            $priceChanged = true;
                        } else {
                            $svcIns->execute([$nameEn, $nameKm, $item['description'] ?? '', $categoryName, $catId, $durationMin, $isActive]);
                            $newSvcId = (int)$pdo->lastInsertId();
                            // Derive and insert tiered pricing across all vehicle types using multipliers
                            self::syncServicePricing($pdo, $newSvcId, $priceUsd, $durationMin, $item);
                            $priceChanged = true;
                        }
                    } else {
                        // Product or spare part
                        $prodCheck->execute([$nameEn]);
                        $localProd = $prodCheck->fetch(PDO::FETCH_ASSOC);

                        if ($localProd) {
                            $prodId = (int)$localProd['id'];
                            $localChanged = !empty($localProd['updated_at']) && ($localProd['updated_at'] > $lastPullAt);

                            if ($localChanged && abs($priceUsd - (float)$localProd['price_usd']) > 0.01) {
                                $confUid = 'CONF-PROD-' . $prodId . '-' . time();
                                $insConf = $pdo->prepare("INSERT INTO sync_conflicts (conflict_uid, record_type, record_uid, local_data, cloud_data, local_updated_at, cloud_updated_at, status, created_at) VALUES (?, 'product', ?, ?, ?, NOW(), NOW(), 'pending', NOW())");
                                $insConf->execute([$confUid, (string)$prodId, json_encode($localProd), json_encode($item)]);
                                $conflictsCount++;
                                continue;
                            }

                            if ((int)$localProd['is_active'] === 1 && $isActive === 0) {
                                AuditLogger::log('PRODUCT_DEACTIVATED', "Product '{$nameEn}' (ID #{$prodId}) deactivated via Cloud Sync", 'products', (string)$prodId, 0, 'cloud_sync', 'system');
                            }
                            // Update price and details without overwriting local stock_qty
                            $prodUpd->execute([$nameKm, $categoryName, $catId, $costUsd, $priceUsd, $priceKhr, $isActive, $prodId]);
                            $priceChanged = true;
                        } else {
                            $prodIns->execute([$nameEn, $nameKm, $categoryName, $catId, $item['sku'] ?? '', $costUsd, $priceUsd, $priceKhr, $isActive]);
                            $priceChanged = true;
                        }
                    }
                    $recordsReceived++;
                }
            }

            // Also support legacy top-level services & products if cloud sent them
            if (empty($catalogItems) && (!empty($masterData['services']) || !empty($masterData['products']))) {
                if (!empty($masterData['services']) && is_array($masterData['services'])) {
                    foreach ($masterData['services'] as $cs) {
                        $svcId = (int)($cs['id'] ?? 0);
                        if ($svcId <= 0) continue;
                        $cloudPrice = isset($cs['price_usd']) ? (float)$cs['price_usd'] : null;
                        if ($cloudPrice !== null) {
                            self::syncServicePricing($pdo, $svcId, $cloudPrice, 30, $cs);
                            $recordsReceived++;
                        }
                    }
                }
                if (!empty($masterData['products']) && is_array($masterData['products'])) {
                    foreach ($masterData['products'] as $cp) {
                        $pId = (int)($cp['id'] ?? 0);
                        if ($pId <= 0) continue;
                        $cloudPPrice = isset($cp['price_usd']) ? (float)$cp['price_usd'] : null;
                        if ($cloudPPrice !== null) {
                            $pdo->prepare("UPDATE products SET price_usd = ?, price_khr = ? WHERE id = ?")
                                ->execute([$cloudPPrice, (int)round($cloudPPrice * 4100), $pId]);
                            $recordsReceived++;
                        }
                    }
                }
            }

            // -------------------------------------------------------------
            // 3. Ingest Users (with role mapping and local_pin_hash only)
            // -------------------------------------------------------------
            $usersList = $masterData['users'] ?? [];
            if (!empty($usersList) && is_array($usersList)) {
                $hasLocalPinHash = false;
                try {
                    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                    $hasLocalPinHash = in_array('local_pin_hash', $cols, true);
                } catch (Throwable $t) {}

                $roleStmt = $pdo->prepare("SELECT id, code FROM roles WHERE LOWER(code) = LOWER(?) OR LOWER(name_en) = LOWER(?) LIMIT 1");
                $userCheck = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                // Cloud-managed staff roles that can be synchronized/deactivated by cloud
                $cloudManagedRoles = ['cashier', 'washer', 'mechanic', 'manager', 'admin', 'system_admin'];

                foreach ($usersList as $u) {
                    $uname = trim((string)($u['username'] ?? ''));
                    if ($uname === '') continue;

                    $fullName = trim((string)($u['full_name'] ?? $uname));
                    $rawRole = trim((string)($u['role'] ?? 'cashier'));
                    $isActive = isset($u['is_active']) ? (int)$u['is_active'] : 1;
                    $status = ($isActive === 1) ? 'active' : 'inactive'; // Deactivate only, never delete

                    // Resolve role to local role_id and code
                    $roleStmt->execute([$rawRole, $rawRole]);
                    $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$roleRow) {
                        // Standard alias fallbacks
                        $mappedCode = match (strtolower($rawRole)) {
                            'system admin', 'sysadmin' => 'admin',
                            'manager'                  => 'manager',
                            'cashier'                  => 'cashier',
                            'technician', 'lead tech'  => 'mechanic',
                            'washer'                   => 'washer',
                            default                    => 'cashier'
                        };
                        $roleStmt->execute([$mappedCode, $mappedCode]);
                        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
                    }
                    $roleCode = $roleRow['code'] ?? 'cashier';
                    $roleId = $roleRow ? (int)$roleRow['id'] : null;

                    // PIN hash: store local_pin_hash, NEVER a plain PIN
                    $pinHash = null;
                    if (!empty($u['local_pin_hash'])) {
                        $pinHash = (string)$u['local_pin_hash'];
                    }

                    $userCheck->execute([$uname]);
                    $localUser = $userCheck->fetch(PDO::FETCH_ASSOC);

                    if ($localUser) {
                        $uId = (int)$localUser['id'];
                        $localRole = strtolower(trim((string)($localUser['role'] ?? '')));
                        $isCurrentlyActive = ($localUser['status'] === 'active');

                        // Safety checks on user deactivation
                        if ($status === 'inactive' && $isCurrentlyActive) {
                            // 1. Never deactivate users the cloud does not manage
                            if (!in_array($localRole, $cloudManagedRoles, true)) {
                                $status = 'active'; // retain local active status
                                AuditLogger::log('USER_DEACTIVATE_BLOCKED', "Blocked deactivation of unmanaged role user '{$uname}' (ID #{$uId}, Role: {$localRole}) via Cloud Sync", 'users', (string)$uId, 0, 'cloud_sync', 'system');
                            } elseif ($localRole === 'owner') {
                                // 2. Never deactivate the last active owner
                                $activeOwners = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active'")->fetchColumn();
                                if ($activeOwners <= 1) {
                                    $status = 'active'; // retain active status
                                    AuditLogger::log('USER_DEACTIVATE_BLOCKED', "Blocked deactivation of last active owner '{$uname}' (ID #{$uId}) via Cloud Sync", 'users', (string)$uId, 0, 'cloud_sync', 'system');
                                }
                            }
                        }

                        $localChanged = !empty($localUser['updated_at']) && ($localUser['updated_at'] > $lastPullAt);

                        if ($localChanged && ($localUser['role'] !== $roleCode || $localUser['status'] !== $status)) {
                            $confUid = 'CONF-USR-' . $uId . '-' . time();
                            $pdo->prepare("INSERT INTO sync_conflicts (conflict_uid, record_type, record_uid, local_data, cloud_data, local_updated_at, cloud_updated_at, status, created_at) VALUES (?, 'user', ?, ?, ?, NOW(), NOW(), 'pending', NOW())")
                                ->execute([$confUid, (string)$uId, json_encode($localUser), json_encode($u)]);
                            $conflictsCount++;
                            continue;
                        }

                        $wasDeactivated = ($isCurrentlyActive && $status === 'inactive');

                        $updSql = "UPDATE users SET full_name = ?, role = ?, role_id = ?, status = ?";
                        $params = [$fullName, $roleCode, $roleId, $status];
                        if ($pinHash !== null) {
                            $updSql .= ", password_hash = ?";
                            $params[] = $pinHash;
                            if ($hasLocalPinHash) {
                                $updSql .= ", local_pin_hash = ?";
                                $params[] = $pinHash;
                            }
                        }
                        $updSql .= " WHERE id = ?";
                        $params[] = $uId;
                        $pdo->prepare($updSql)->execute($params);

                        // If user was deactivated: write audit log and terminate active POS sessions
                        if ($wasDeactivated) {
                            AuditLogger::log('USER_DEACTIVATED', "Staff user '{$uname}' (ID #{$uId}, Role: {$localRole}) deactivated via Cloud Sync", 'users', (string)$uId, 0, 'cloud_sync', 'system');
                            $pdo->prepare("UPDATE pos_sessions SET expires_at = NOW() WHERE user_id = ? AND expires_at > NOW()")
                                ->execute([$uId]);
                        }
                    } else {
                        // New user insert: NEVER create a user with a default PIN
                        if ($pinHash === null || trim($pinHash) === '') {
                            // If no local_pin_hash arrives, insert user with status 'inactive', unusable random password_hash and local_pin_hash NULL
                            $unusableHash = '$2y$10$' . bin2hex(random_bytes(26));
                            $insertStatus = 'inactive';
                            $insertLocalPin = null;

                            if ($hasLocalPinHash) {
                                $pdo->prepare("INSERT INTO users (username, password_hash, local_pin_hash, full_name, role, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                                    ->execute([$uname, $unusableHash, $insertLocalPin, $fullName, $roleCode, $roleId, $insertStatus]);
                            } else {
                                $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                                    ->execute([$uname, $unusableHash, $fullName, $roleCode, $roleId, $insertStatus]);
                            }
                            $newUserId = (int)$pdo->lastInsertId();
                            AuditLogger::log('USER_CREATED_PENDING_PIN', "New staff user '{$uname}' (ID #{$newUserId}) inserted with status 'inactive' awaiting local_pin_hash from cloud", 'users', (string)$newUserId, 0, 'cloud_sync', 'system');
                        } else {
                            if ($hasLocalPinHash) {
                                $pdo->prepare("INSERT INTO users (username, password_hash, local_pin_hash, full_name, role, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                                    ->execute([$uname, $pinHash, $pinHash, $fullName, $roleCode, $roleId, $status]);
                            } else {
                                $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                                    ->execute([$uname, $pinHash, $fullName, $roleCode, $roleId, $status]);
                            }
                            $newUserId = (int)$pdo->lastInsertId();
                            AuditLogger::log('USER_CREATED', "New staff user '{$uname}' (ID #{$newUserId}, Role: {$roleCode}) created from cloud master", 'users', (string)$newUserId, 0, 'cloud_sync', 'system');
                        }
                    }
                    $recordsReceived++;
                }
            }

            // -------------------------------------------------------------
            // 4. Ingest Whitelisted Settings & Queue Rules (NEVER secrets)
            // -------------------------------------------------------------
            $settingsMap = $masterData['settings'] ?? [];
            if (!empty($settingsMap) && is_array($settingsMap)) {
                $whitelistedSettings = [
                    'shop_name_en'            => 'shop_name_en',
                    'shop_name_km'            => 'shop_name_km',
                    'shop_name'               => 'shop_name_en',
                    'shop_phone'              => 'shop_phone',
                    'shop_address'            => 'shop_address',
                    'shop_email'              => 'shop_email',
                    'tax_pct'                 => 'tax_pct',
                    'tax_rate_percent'        => 'tax_pct',
                    'exchange_rate'           => 'exchange_rate',
                    'exchange_rate_usd_khr'   => 'exchange_rate',
                    'receipt_header_en'       => 'receipt_header_en',
                    'receipt_header_km'       => 'receipt_header_km',
                    'receipt_footer_en'       => 'receipt_footer_en',
                    'receipt_footer_km'       => 'receipt_footer_km',
                    'default_oil_mileage'     => 'default_oil_mileage',
                    'default_oil_months'      => 'default_oil_months',
                    'labor_hourly_rate_usd'   => 'labor_hourly_rate_usd',
                    'queue_rules'             => 'queue_rules',
                    'multiplier_small_car'    => 'multiplier_small_car',
                    'multiplier_suv_pickup'   => 'multiplier_suv_pickup',
                    'multiplier_van_truck'    => 'multiplier_van_truck',
                    'multiplier_motorbike'    => 'multiplier_motorbike'
                ];

                foreach ($settingsMap as $sKey => $sData) {
                    $keyStr = (string)$sKey;
                    // Security guard: NEVER overwrite cloud_api_*, telegram, device key, demo_mode or other secrets
                    if (
                        str_starts_with($keyStr, 'cloud_api_') ||
                        str_starts_with($keyStr, 'telegram_') ||
                        str_starts_with($keyStr, 'pos_device_') ||
                        in_array($keyStr, ['cloud_shop_id', 'cloud_allow_insecure_tls', 'cloud_offline_mode', 'cloud_sync_enabled', 'device_key', 'tv_display_code', 'demo_mode', 'jwt_secret'], true)
                    ) {
                        continue;
                    }

                    if (!isset($whitelistedSettings[$keyStr])) {
                        continue; // Skip non-whitelisted settings
                    }

                    $targetKey = $whitelistedSettings[$keyStr];
                    $val = is_array($sData) ? ($sData['value'] ?? '') : (string)$sData;
                    if (is_array($val)) $val = json_encode($val);

                    update_setting($pdo, $targetKey, (string)$val, 0, 'CloudSyncPull');
                    $recordsReceived++;
                }
            }

            // Direct queue_rules entity if provided
            if (!empty($masterData['queue_rules'])) {
                $qrVal = is_array($masterData['queue_rules']) ? json_encode($masterData['queue_rules']) : (string)$masterData['queue_rules'];
                update_setting($pdo, 'queue_rules', $qrVal, 0, 'CloudSyncPull');
                $recordsReceived++;
            }

            // -------------------------------------------------------------
            // 5. Ingest Membership Tiers
            // -------------------------------------------------------------
            $tiers = $masterData['membership_tiers'] ?? [];
            if (!empty($tiers) && is_array($tiers)) {
                $tStmt = $pdo->prepare("
                    INSERT INTO membership_tiers (tier_code, name_en, name_km, monthly_fee_usd, included_services, discount_percent, priority_level, is_active, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        name_en = VALUES(name_en),
                        monthly_fee_usd = VALUES(monthly_fee_usd),
                        discount_percent = VALUES(discount_percent),
                        is_active = VALUES(is_active),
                        updated_at = NOW()
                ");

                foreach ($tiers as $t) {
                    $code = !empty($t['tier_code']) ? $t['tier_code'] : ('tier_' . ($t['id'] ?? uniqid()));
                    $nameEn = $t['name_en'] ?? 'Tier';
                    $nameKm = $t['name_km'] ?? $nameEn;
                    $fee = (float)($t['monthly_fee_usd'] ?? 0.0);
                    $disc = (int)($t['discount_pct'] ?? $t['discount_percent'] ?? 0);
                    $inc = is_array($t['included_wash_package'] ?? null) ? json_encode($t['included_wash_package']) : ($t['included_wash_package'] ?? json_encode([]));
                    $prio = $t['priority_level'] ?? 'P0';
                    $active = isset($t['is_active']) ? (int)$t['is_active'] : 1;

                    // Audit log if tier is deactivated
                    $checkTier = $pdo->prepare("SELECT id, is_active FROM membership_tiers WHERE tier_code = ? LIMIT 1");
                    $checkTier->execute([$code]);
                    $existTier = $checkTier->fetch(PDO::FETCH_ASSOC);
                    if ($existTier && (int)$existTier['is_active'] === 1 && $active === 0) {
                        AuditLogger::log('TIER_DEACTIVATED', "Membership tier '{$code}' deactivated via Cloud Sync", 'membership_tiers', $code, 0, 'cloud_sync', 'system');
                    }

                    $tStmt->execute([$code, $nameEn, $nameKm, $fee, $inc, $disc, $prio, $active]);
                    $recordsReceived++;
                }
            }

            // -------------------------------------------------------------
            // 6. Ingest Vehicle Technical Specs
            // -------------------------------------------------------------
            $vSpecs = $masterData['vehicle_specs'] ?? [];
            if (!empty($vSpecs) && is_array($vSpecs)) {
                $vsStmt = $pdo->prepare("
                    INSERT INTO vehicle_specs (make, model, engine_type, oil_viscosity, oil_type, oil_capacity_liters, oil_filter_code, other_fluids, is_active, version, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        engine_type = VALUES(engine_type),
                        oil_viscosity = VALUES(oil_viscosity),
                        oil_type = VALUES(oil_type),
                        oil_capacity_liters = VALUES(oil_capacity_liters),
                        oil_filter_code = VALUES(oil_filter_code),
                        other_fluids = VALUES(other_fluids),
                        is_active = VALUES(is_active),
                        version = VALUES(version),
                        updated_at = NOW()
                ");

                foreach ($vSpecs as $vs) {
                    $make = trim((string)($vs['make'] ?? ''));
                    $model = trim((string)($vs['model'] ?? ''));
                    if ($make === '' || $model === '') continue;

                    $vsActive = isset($vs['is_active']) ? (int)$vs['is_active'] : 1;

                    // Audit log if vehicle spec is deactivated
                    $checkVs = $pdo->prepare("SELECT id, is_active FROM vehicle_specs WHERE make = ? AND model = ? LIMIT 1");
                    $checkVs->execute([$make, $model]);
                    $existVs = $checkVs->fetch(PDO::FETCH_ASSOC);
                    if ($existVs && (int)$existVs['is_active'] === 1 && $vsActive === 0) {
                        AuditLogger::log('SPEC_DEACTIVATED', "Vehicle spec '{$make} {$model}' deactivated via Cloud Sync", 'vehicle_specs', "{$make} {$model}", 0, 'cloud_sync', 'system');
                    }

                    $vsStmt->execute([
                        $make,
                        $model,
                        $vs['engine_type'] ?? '',
                        $vs['oil_viscosity'] ?? '5W-30',
                        $vs['oil_type'] ?? 'Synthetic',
                        (float)($vs['oil_capacity_liters'] ?? 4.0),
                        $vs['oil_filter_code'] ?? 'OEM',
                        $vs['other_fluids'] ?? null,
                        $vsActive,
                        (int)($vs['version'] ?? 1)
                    ]);
                    $recordsReceived++;
                }
            }

            // -------------------------------------------------------------
            // 7. Ingest Reminder Rules
            // -------------------------------------------------------------
            $reminderRules = $masterData['reminder_rules'] ?? [];
            if (!empty($reminderRules) && is_array($reminderRules)) {
                $remCheck = $pdo->prepare("SELECT id FROM reminder_rules WHERE rule_type = ? LIMIT 1");
                $remIns = $pdo->prepare("
                    INSERT INTO reminder_rules (rule_type, trigger_mileage_before, trigger_days_before, trigger_days_inactive, message_template_en, message_template_km, is_active, version, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $remUpd = $pdo->prepare("
                    UPDATE reminder_rules SET
                        trigger_mileage_before = ?, trigger_days_before = ?, trigger_days_inactive = ?,
                        message_template_en = ?, message_template_km = ?, is_active = ?, version = ?, updated_at = NOW()
                    WHERE id = ?
                ");

                foreach ($reminderRules as $rr) {
                    $rType = trim((string)($rr['rule_type'] ?? ''));
                    if ($rType === '') continue;

                    $tMil = (int)($rr['trigger_mileage_before'] ?? 0);
                    $tDays = (int)($rr['trigger_days_before'] ?? 0);
                    $tInact = (int)($rr['trigger_days_inactive'] ?? 0);
                    $mEn = $rr['message_template_en'] ?? null;
                    $mKm = $rr['message_template_km'] ?? null;
                    $rActive = (int)($rr['is_active'] ?? 1);
                    $rVer = (int)($rr['version'] ?? 1);

                    $remCheck = $pdo->prepare("SELECT id, is_active FROM reminder_rules WHERE rule_type = ? LIMIT 1");
                    $remCheck->execute([$rType]);
                    $existRem = $remCheck->fetch(PDO::FETCH_ASSOC);

                    if ($existRem) {
                        if ((int)$existRem['is_active'] === 1 && $rActive === 0) {
                            AuditLogger::log('REMINDER_RULE_DEACTIVATED', "Reminder rule '{$rType}' (ID #{$existRem['id']}) deactivated via Cloud Sync", 'reminder_rules', (string)$existRem['id'], 0, 'cloud_sync', 'system');
                        }
                        $remUpd->execute([$tMil, $tDays, $tInact, $mEn, $mKm, $rActive, $rVer, $existRem['id']]);
                    } else {
                        $remIns->execute([$rType, $tMil, $tDays, $tInact, $mEn, $mKm, $rActive, $rVer]);
                    }
                    $recordsReceived++;
                }
            }

            // Record pull completion and version timestamp
            $newVer = (string)($cloudData['catalog_version'] ?? time());
            update_setting($pdo, 'catalog_price_version', $newVer, 0, 'CloudSync');
            update_setting($pdo, 'cloud_last_pull_at', $now, 0, 'CloudSync');

            // Commit transaction
            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::logSync($pdo, 'pull', 'failed', 0, 0, 0, $durationMs, 'Database transaction failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'pull_processing_failed', 'message' => $e->getMessage()];
        }

        self::logSync($pdo, 'pull', 'success', 0, $recordsReceived, $conflictsCount, $durationMs, null);

        return [
            'success' => true,
            'records_received' => $recordsReceived,
            'conflicts_count' => $conflictsCount,
            'conflicts' => $conflictsCount,
            'price_changed' => $priceChanged,
            'catalog_version' => $newVer,
            'last_pull_at' => $now
        ];
    }

    /**
     * Fast 30-second critical settings check (prices, user roles, active/inactive).
     * Uses the signed request helper with entities ['settings', 'queue_rules'].
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

        $shopId = get_setting('cloud_shop_id', '');
        $shopCode = get_setting('shop_code', $shopId);

        $payload = [
            'shop_code' => $shopCode,
            'pull_request' => [
                'entities' => ['settings', 'queue_rules'],
                'local_versions' => [
                    'settings_version' => (int)get_setting('settings_version', '1'),
                    'catalog_version' => (int)get_setting('catalog_price_version', '0')
                ]
            ]
        ];

        // Send signed POST request via helper
        $reqRes = self::request($payload, 1, 0);

        if (!$reqRes['success']) {
            return [
                'success' => true,
                'has_update' => false,
                'has_critical_update' => false,
                'error' => $reqRes['error'] ?? 'server_unreachable'
            ];
        }

        $cloudData = $reqRes['data'] ?? [];
        $masterData = $cloudData['master_data'] ?? $cloudData;

        $hasUpdate = !empty($cloudData['has_update']);

        if (!$hasUpdate && !empty($masterData['settings']) && is_array($masterData['settings'])) {
            foreach ($masterData['settings'] as $k => $info) {
                $val = is_array($info) ? ($info['value'] ?? '') : (string)$info;
                if ($k === 'force_sync_requested' && $val === '1') {
                    $hasUpdate = true;
                    break;
                }
                $localVal = get_setting($k, null);
                if ($localVal !== null && (string)$localVal !== (string)$val) {
                    $hasUpdate = true;
                    break;
                }
            }
        }

        if (!$hasUpdate && !empty($cloudData['catalog_version'])) {
            $localVersion = (int)get_setting('catalog_price_version', '0');
            if ((int)$cloudData['catalog_version'] > $localVersion) {
                $hasUpdate = true;
            }
        }

        if (!$hasUpdate) {
            return [
                'success' => true,
                'has_update' => false,
                'has_critical_update' => false
            ];
        }

        // Pull fresh catalog and apply price update immediately
        $pullRes = self::receiveFromCloud($pdo);

        return [
            'success' => true,
            'has_update' => true,
            'has_critical_update' => true,
            'details' => $cloudData,
            'catalog_version' => $pullRes['catalog_version'] ?? time(),
            'message' => 'System update: prices or permissions have been updated'
        ];
    }

    /**
     * Synchronize tiered service prices for all 4 vehicle types.
     * Guarantees that local prices per vehicle type are never overwritten by a single cloud price,
     * and ensures new services derive tiered pricing rather than receiving a flat single price.
     */
    private static function syncServicePricing(PDO $pdo, int $serviceId, float $basePriceUsd, int $durationMin, array $cloudItem = []): void {
        $vTypes = ['motorbike', 'small_car', 'suv_pickup', 'van_truck'];
        $defaultMults = [
            'motorbike'  => 0.6,
            'small_car'  => 1.0,
            'suv_pickup' => 1.25,
            'van_truck'  => 1.4
        ];
        $durMults = [
            'motorbike'  => 0.8,
            'small_car'  => 1.0,
            'suv_pickup' => 1.2,
            'van_truck'  => 1.4
        ];

        $rate = (float)get_setting('exchange_rate', '4100');
        if ($rate <= 0) $rate = 4100.0;

        // Fetch existing local prices for this service
        $stmt = $pdo->prepare("SELECT vehicle_type, price_usd, price_khr, estimated_minutes FROM service_prices WHERE service_id = ?");
        $stmt->execute([$serviceId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['vehicle_type']] = $row;
        }

        $priceStmt = $pdo->prepare("
            INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                price_usd = VALUES(price_usd),
                price_khr = VALUES(price_khr),
                estimated_minutes = VALUES(estimated_minutes)
        ");

        foreach ($vTypes as $vt) {
            // Check if cloud explicitly provided a vehicle-specific price
            $explicitPrice = null;
            if (!empty($cloudItem['prices'][$vt]) && is_numeric($cloudItem['prices'][$vt])) {
                $explicitPrice = (float)$cloudItem['prices'][$vt];
            } elseif (!empty($cloudItem['price_matrix'][$vt]['usd']) && is_numeric($cloudItem['price_matrix'][$vt]['usd'])) {
                $explicitPrice = (float)$cloudItem['price_matrix'][$vt]['usd'];
            } elseif (!empty($cloudItem['price_' . $vt]) && is_numeric($cloudItem['price_' . $vt])) {
                $explicitPrice = (float)$cloudItem['price_' . $vt];
            }

            if ($vt === 'small_car') {
                // small_car is the base vehicle type corresponding to catalog item price
                $usd = ($explicitPrice !== null) ? $explicitPrice : $basePriceUsd;
                $khr = (int)round($usd * $rate);
                $min = $durationMin > 0 ? $durationMin : 25;
                $priceStmt->execute([$serviceId, $vt, $usd, $khr, $min]);
            } else {
                if ($explicitPrice !== null) {
                    // Cloud explicitly provided a price for this vehicle type
                    $usd = $explicitPrice;
                    $khr = (int)round($usd * $rate);
                    $min = max(10, (int)round($durationMin * $durMults[$vt]));
                    $priceStmt->execute([$serviceId, $vt, $usd, $khr, $min]);
                } elseif (isset($existing[$vt])) {
                    // SAFETY RULE: Local price for this vehicle type already exists.
                    // NEVER overwrite local price per vehicle type with a single cloud price!
                    continue;
                } else {
                    // Row does not exist yet locally: derive tiered price using multiplier
                    $multiplier = (float)get_setting('multiplier_' . $vt, (string)$defaultMults[$vt]);
                    if ($multiplier <= 0) $multiplier = $defaultMults[$vt];
                    $usd = round($basePriceUsd * $multiplier, 2);
                    $khr = (int)round($usd * $rate);
                    $min = max(10, (int)round($durationMin * $durMults[$vt]));
                    $priceStmt->execute([$serviceId, $vt, $usd, $khr, $min]);
                }
            }
        }
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
                    self::syncServicePricing($pdo, $svcId, $price, 30, $cloudData);
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
     * Get the absolute path to the sync lock file.
     */
    public static function getLockFile(): string {
        return dirname(__DIR__) . '/storage/sync.lock';
    }

    /**
     * Check if a sync runner process currently holds the lock.
     */
    public static function isSyncLocked(): bool {
        $lockFile = self::getLockFile();
        if (!file_exists($lockFile)) {
            return false;
        }
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return true; // Lock is currently held
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
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
