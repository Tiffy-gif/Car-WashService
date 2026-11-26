<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

// Strict server-side permission & role check: Owner & Manager Only
Auth::requirePermission('reports.view');
if (!in_array(Auth::getRole(), ['owner', 'manager'], true)) {
    http_response_code(403);
    die("Permission denied: Cash Movements report is visible only to Owner and Manager.");
}

$pageTitle = __('nav_cash_movements', 'Cash Movements & Drawer Events');
$pdo = Database::getInstance();
$curRate = get_exchange_rate();

// 1. Resolve Filter Parameters
$period = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedType = trim((string)($_GET['type'] ?? 'all'));
$selectedCashier = trim((string)($_GET['cashier_id'] ?? 'all'));
$selectedDevice = trim((string)($_GET['device'] ?? 'all'));

// Date where clause
$dateWhere = "";
$dateParams = [];

if ($period === 'today') {
    $dateWhere = " AND DATE(de.created_at) = CURDATE()";
} elseif ($period === 'this_week') {
    $dateWhere = " AND YEARWEEK(de.created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period === 'this_month') {
    $dateWhere = " AND MONTH(de.created_at) = MONTH(CURDATE()) AND YEAR(de.created_at) = YEAR(CURDATE())";
} elseif ($period === 'this_year') {
    $dateWhere = " AND YEAR(de.created_at) = YEAR(CURDATE())";
} elseif ($period === 'custom' && $startDate && $endDate) {
    $dateWhere = " AND DATE(de.created_at) BETWEEN ? AND ?";
    $dateParams = [$startDate, $endDate];
} else {
    $period = 'this_month';
    $dateWhere = " AND MONTH(de.created_at) = MONTH(CURDATE()) AND YEAR(de.created_at) = YEAR(CURDATE())";
}

// Event Type filter
$typeWhere = "";
if ($selectedType === 'cash_in') {
    $typeWhere = " AND de.event_type = 'cash_in'";
} elseif ($selectedType === 'cash_out') {
    $typeWhere = " AND de.event_type = 'cash_out'";
} elseif ($selectedType === 'no_sale') {
    $typeWhere = " AND de.event_type IN ('no_sale', 'no_sale_open')";
} else {
    $selectedType = 'all';
}

// Cashier filter
$cashierWhere = "";
$cashierParams = [];
if ($selectedCashier !== 'all' && is_numeric($selectedCashier) && (int)$selectedCashier > 0) {
    $cashierWhere = " AND de.cashier_id = ?";
    $cashierParams[] = (int)$selectedCashier;
} else {
    $selectedCashier = 'all';
}

// Device filter
$deviceWhere = "";
$selectedDeviceName = __('all_devices', 'All Devices');
if ($selectedDevice === 'web') {
    $deviceWhere = " AND (de.pos_device_id IS NULL OR de.pos_device_id = 0)";
    $selectedDeviceName = __('back_office_web', 'Back Office Web');
} elseif (is_numeric($selectedDevice) && (int)$selectedDevice > 0) {
    $devId = (int)$selectedDevice;
    $deviceWhere = " AND de.pos_device_id = {$devId}";
    $devRow = $pdo->query("SELECT device_name, device_uid FROM pos_devices WHERE id = {$devId}")->fetch(PDO::FETCH_ASSOC);
    if ($devRow) {
        $selectedDeviceName = $devRow['device_name'] . ' (' . $devRow['device_uid'] . ')';
    }
} else {
    $selectedDevice = 'all';
}

// Combine parameters
$queryParams = array_merge($dateParams, $cashierParams);

// 2. Fetch Active Staff/Cashiers & Devices for Filter Dropdowns
$allCashiers = $pdo->query("
    SELECT id, username, full_name, role 
    FROM users 
    WHERE role IN ('cashier', 'manager', 'owner') 
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$allRegisteredDevices = $pdo->query("
    SELECT id, device_uid, device_name 
    FROM pos_devices 
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Query Detailed Events
$eventsSql = "
    SELECT 
        de.id,
        de.event_uid,
        de.event_type,
        de.pos_device_id,
        de.shift_id,
        de.cashier_id,
        de.cashier_name,
        COALESCE(de.amount_usd, 0) as amount_usd,
        COALESCE(de.amount_khr, 0) as amount_khr,
        de.manager_id,
        de.manager_name,
        de.reason,
        de.created_at,
        cs.shift_uid,
        dev.device_name,
        dev.device_uid,
        u_cashier.username as cashier_username,
        u_mgr.username as manager_username
    FROM drawer_events de
    LEFT JOIN cashier_shifts cs ON de.shift_id = cs.id
    LEFT JOIN pos_devices dev ON de.pos_device_id = dev.id
    LEFT JOIN users u_cashier ON de.cashier_id = u_cashier.id
    LEFT JOIN users u_mgr ON de.manager_id = u_mgr.id
    WHERE de.is_active = 1
      {$dateWhere}
      {$typeWhere}
      {$cashierWhere}
      {$deviceWhere}
    ORDER BY de.created_at DESC, de.id DESC
";
$stmt = $pdo->prepare($eventsSql);
$stmt->execute($queryParams);
$allEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Calculate Summary Totals & Per-Cashier Aggregation
$totalEventsCount = count($allEvents);
$totalCashInUsd = 0.00;
$totalCashInKhr = 0;
$totalCashOutUsd = 0.00;
$totalCashOutKhr = 0;
$totalNoSaleCount = 0;

$cashierSummary = [];
$shiftGroups = [];

foreach ($allEvents as $ev) {
    $cid = (int)$ev['cashier_id'];
    $cname = $ev['cashier_name'] ?: 'Cashier #' . $cid;
    $cuser = $ev['cashier_username'] ?? '';
    $type = $ev['event_type'];
    $usd = (float)$ev['amount_usd'];
    $khr = (int)$ev['amount_khr'];
    if ($khr <= 0 && $usd > 0) {
        $khr = (int)round($usd * $curRate);
    }

    // Build Shift-Grouped Accordion Data
    $rawKey = $ev['shift_uid'] ?: ($ev['shift_id'] ? 'SHIFT_' . $ev['shift_id'] : 'UNASSIGNED');
    $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawKey);
    if (!isset($shiftGroups[$cleanKey])) {
        $shiftGroups[$cleanKey] = [
            'key'          => $cleanKey,
            'shift_uid'    => $ev['shift_uid'] ?: ($ev['shift_id'] ? '#' . $ev['shift_id'] : 'Unassigned / Direct'),
            'shift_id'     => $ev['shift_id'],
            'date'         => date('d M Y, h:i A', strtotime($ev['created_at'])),
            'cashier'      => $cname,
            'username'     => $cuser,
            'device'       => $ev['device_name'] ? "{$ev['device_name']} ({$ev['device_uid']})" : 'Back Office Web',
            'events'       => [],
            'net_usd'      => 0.00,
            'net_khr'      => 0,
            'no_sale_count'=> 0,
            'cash_in_usd'  => 0.00,
            'cash_out_usd' => 0.00,
        ];
    }

    if ($type === 'cash_in') {
        $shiftGroups[$cleanKey]['net_usd'] += $usd;
        $shiftGroups[$cleanKey]['net_khr'] += $khr;
        $shiftGroups[$cleanKey]['cash_in_usd'] += $usd;
    } elseif ($type === 'cash_out') {
        $shiftGroups[$cleanKey]['net_usd'] -= $usd;
        $shiftGroups[$cleanKey]['net_khr'] -= $khr;
        $shiftGroups[$cleanKey]['cash_out_usd'] += $usd;
    } elseif ($type === 'no_sale' || $type === 'no_sale_open') {
        $shiftGroups[$cleanKey]['no_sale_count']++;
    }
    $shiftGroups[$cleanKey]['events'][] = $ev;

    if (!isset($cashierSummary[$cid])) {
        $cashierSummary[$cid] = [
            'cashier_id'   => $cid,
            'cashier_name' => $cname,
            'username'     => $cuser,
            'no_sale_count'=> 0,
            'cash_out_usd' => 0.00,
            'cash_out_khr' => 0,
            'cash_in_usd'  => 0.00,
            'cash_in_khr'  => 0,
            'total_events' => 0,
        ];
    }

    $cashierSummary[$cid]['total_events']++;

    if ($type === 'cash_in') {
        $totalCashInUsd += $usd;
        $totalCashInKhr += $khr;
        $cashierSummary[$cid]['cash_in_usd'] += $usd;
        $cashierSummary[$cid]['cash_in_khr'] += $khr;
    } elseif ($type === 'cash_out') {
        $totalCashOutUsd += $usd;
        $totalCashOutKhr += $khr;
        $cashierSummary[$cid]['cash_out_usd'] += $usd;
        $cashierSummary[$cid]['cash_out_khr'] += $khr;
    } elseif ($type === 'no_sale' || $type === 'no_sale_open') {
        $totalNoSaleCount++;
        $cashierSummary[$cid]['no_sale_count']++;
    }
}

// 5. High No-Sale Anomaly Detection
$activeCashierCount = count($cashierSummary);
$avgNoSale = $activeCashierCount > 0 ? ($totalNoSaleCount / $activeCashierCount) : 0;

foreach ($cashierSummary as $cid => &$cs) {
    // Flag cashier if no_sale_count is unusually high:
    // Either >= 5 absolute opens, or >= 3 opens and at least 50% above average
    $cs['is_high_no_sale'] = ($cs['no_sale_count'] >= 5) || ($cs['no_sale_count'] >= 3 && $cs['no_sale_count'] > ($avgNoSale * 1.5));
}
unset($cs);

// Sort cashiers by no-sale opens descending, then cash out descending
uasort($cashierSummary, function($a, $b) {
    if ($b['no_sale_count'] !== $a['no_sale_count']) {
        return $b['no_sale_count'] <=> $a['no_sale_count'];
    }
    return $b['cash_out_usd'] <=> $a['cash_out_usd'];
});

// 6. CSV Export Handling
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    Auth::requirePermission('reports.export');
    $filename = "CarWash_Cash_Movements_" . $period . "_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Khmer text in Excel

    fputcsv($out, ['AQUAGLEAM CAR WASH - CASH MOVEMENTS & DRAWER ACTIVITY REPORT']);
    fputcsv($out, [
        'Period:', strtoupper(str_replace('_', ' ', $period)),
        'Start Date:', $startDate,
        'End Date:', $endDate,
        'Type Filter:', strtoupper(str_replace('_', ' ', $selectedType)),
        'Device Filter:', $selectedDeviceName,
        'Timezone:', 'Asia/Phnom_Penh (UTC+7)',
        'Generated At:', date('Y-m-d H:i:s'),
        'Generated By:', (Auth::getUserName() ?: Auth::getUsername()) . ' (' . ucfirst(Auth::getRole()) . ')'
    ]);
    fputcsv($out, []);

    // Summary Totals
    fputcsv($out, ['--- OVERALL ACTIVITY SUMMARY ---']);
    fputcsv($out, ['Total Drawer Events', $totalEventsCount]);
    fputcsv($out, ['Total Cash In (USD)', number_format($totalCashInUsd, 2)]);
    fputcsv($out, ['Total Cash In (KHR)', number_format($totalCashInKhr)]);
    fputcsv($out, ['Total Cash Out (USD)', number_format($totalCashOutUsd, 2)]);
    fputcsv($out, ['Total Cash Out (KHR)', number_format($totalCashOutKhr)]);
    fputcsv($out, ['Total No-Sale Drawer Opens', $totalNoSaleCount]);
    fputcsv($out, []);

    // Summary Per Cashier
    fputcsv($out, ['--- SUMMARY PER CASHIER ---']);
    fputcsv($out, [
        'Cashier Name',
        'Username',
        'No-Sale Opens',
        'High No-Sale Alert',
        'Total Cash Out (USD)',
        'Total Cash Out (KHR)',
        'Total Cash In (USD)',
        'Total Cash In (KHR)',
        'Total Events'
    ]);
    foreach ($cashierSummary as $cs) {
        fputcsv($out, [
            $cs['cashier_name'],
            $cs['username'] ? '@' . $cs['username'] : '-',
            $cs['no_sale_count'],
            $cs['is_high_no_sale'] ? 'HIGH RISK ALERT' : 'Normal',
            number_format($cs['cash_out_usd'], 2),
            number_format($cs['cash_out_khr']),
            number_format($cs['cash_in_usd'], 2),
            number_format($cs['cash_in_khr']),
            $cs['total_events']
        ]);
    }
    fputcsv($out, []);

    // Detailed Drawer Events
    fputcsv($out, ['--- DETAILED DRAWER EVENTS ---']);
    fputcsv($out, [
        'Event UID',
        'Date Time',
        'Event Type',
        'Amount (USD)',
        'Amount (KHR)',
        'Cashier',
        'Authorized Manager',
        'Reason',
        'Shift UID',
        'POS Device'
    ]);

    foreach ($allEvents as $ev) {
        $typeLabel = match($ev['event_type']) {
            'cash_in' => 'Cash In',
            'cash_out' => 'Cash Out',
            'no_sale', 'no_sale_open' => 'No-Sale Drawer Open',
            default => ucfirst(str_replace('_', ' ', $ev['event_type']))
        };
        $devStr = $ev['device_name'] ? "{$ev['device_name']} ({$ev['device_uid']})" : 'Back Office Web';
        $shiftStr = $ev['shift_uid'] ?: ($ev['shift_id'] ? '#' . $ev['shift_id'] : '-');
        $cashierStr = $ev['cashier_name'] . ($ev['cashier_username'] ? ' (@' . $ev['cashier_username'] . ')' : '');
        $mgrStr = $ev['manager_name'] ?: '-';

        fputcsv($out, [
            $ev['event_uid'],
            $ev['created_at'],
            $typeLabel,
            number_format((float)$ev['amount_usd'], 2),
            number_format((int)$ev['amount_khr']),
            $cashierStr,
            $mgrStr,
            $ev['reason'] ?: '-',
            $shiftStr,
            $devStr
        ]);
    }

    fclose($out);
    exit;
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 10px; margin: 0;">
            <i class="fa-solid fa-money-bill-transfer" style="color: #ea580c;"></i>
            <?= htmlspecialchars(__('cash_movements_title', 'Cash Movements & Drawer Activity Report')) ?>
        </h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
            <?= htmlspecialchars(__('cash_movements_desc', 'Audit trail of all cash in, cash out and no-sale drawer openings across shifts and registers.')) ?>
        </p>
    </div>

    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <a href="?view=reports" class="btn btn-secondary">
            <i class="fa-solid fa-chart-line mr-1"></i> <?= htmlspecialchars(__('nav_reports', 'Financial Reports')) ?>
        </a>
        <a href="?view=shift" class="btn btn-secondary">
            <i class="fa-solid fa-hand-holding-dollar mr-1"></i> <?= htmlspecialchars(__('nav_shifts', 'Shift Control')) ?>
        </a>
        <?php if (Auth::can('reports.export')): ?>
        <a href="?view=cash_movements&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&type=<?= urlencode($selectedType) ?>&cashier_id=<?= urlencode((string)$selectedCashier) ?>&device=<?= urlencode($selectedDevice) ?>&export=csv" class="btn btn-primary" style="background: #ea580c; border-color: #ea580c;">
            <i class="fa-solid fa-file-csv mr-1"></i> <?= htmlspecialchars(__('export_cash_movements_csv', 'Export CSV')) ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Unified Filter Toolbar -->
<div class="card" style="margin-bottom: 24px; padding: 16px;">
    <form action="" method="GET" style="display: flex; flex-direction: column; gap: 14px;">
        <input type="hidden" name="view" value="cash_movements">

        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <!-- Period Pills -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="?view=cash_movements&period=today&type=<?= urlencode($selectedType) ?>&cashier_id=<?= urlencode((string)$selectedCashier) ?>&device=<?= urlencode($selectedDevice) ?>" class="btn btn-sm <?= ($period === 'today') ? 'btn-primary' : 'btn-secondary' ?>">
                    <i class="fa-solid fa-sun mr-1"></i> Today
                </a>
                <a href="?view=cash_movements&period=this_week&type=<?= urlencode($selectedType) ?>&cashier_id=<?= urlencode((string)$selectedCashier) ?>&device=<?= urlencode($selectedDevice) ?>" class="btn btn-sm <?= ($period === 'this_week') ? 'btn-primary' : 'btn-secondary' ?>">
                    <i class="fa-solid fa-calendar-week mr-1"></i> This Week
                </a>
                <a href="?view=cash_movements&period=this_month&type=<?= urlencode($selectedType) ?>&cashier_id=<?= urlencode((string)$selectedCashier) ?>&device=<?= urlencode($selectedDevice) ?>" class="btn btn-sm <?= ($period === 'this_month') ? 'btn-primary' : 'btn-secondary' ?>">
                    <i class="fa-solid fa-calendar-days mr-1"></i> This Month
                </a>
                <a href="?view=cash_movements&period=this_year&type=<?= urlencode($selectedType) ?>&cashier_id=<?= urlencode((string)$selectedCashier) ?>&device=<?= urlencode($selectedDevice) ?>" class="btn btn-sm <?= ($period === 'this_year') ? 'btn-primary' : 'btn-secondary' ?>">
                    <i class="fa-solid fa-calendar mr-1"></i> This Year
                </a>
            </div>

            <!-- Custom Date Range -->
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <input type="hidden" name="period" value="custom">
                <div style="display: flex; align-items: center; gap: 6px; background: var(--bg-subtle); padding: 4px 10px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                    <span class="text-xs text-muted">From:</span>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input" style="padding: 2px 6px; font-size: 12px; height: 28px; border: none; background: transparent;">
                    <span class="text-xs text-muted">To:</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-input" style="padding: 2px 6px; font-size: 12px; height: 28px; border: none; background: transparent;">
                </div>
            </div>
        </div>

        <!-- Dropdown Filters Row -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) 120px; gap: 12px; align-items: flex-end; padding-top: 12px; border-top: 1px solid var(--border);">
            <!-- Type Filter -->
            <div>
                <label class="form-label text-xs" style="margin-bottom: 4px;"><?= htmlspecialchars(__('filter_type', 'Event Type')) ?></label>
                <select name="type" class="form-input" style="padding: 6px 10px; font-size: 13px;">
                    <option value="all" <?= ($selectedType === 'all') ? 'selected' : '' ?>><?= htmlspecialchars(__('event_type_all', 'All Event Types')) ?></option>
                    <option value="cash_in" <?= ($selectedType === 'cash_in') ? 'selected' : '' ?>><?= htmlspecialchars(__('event_type_cash_in', 'Cash In')) ?></option>
                    <option value="cash_out" <?= ($selectedType === 'cash_out') ? 'selected' : '' ?>><?= htmlspecialchars(__('event_type_cash_out', 'Cash Out')) ?></option>
                    <option value="no_sale" <?= ($selectedType === 'no_sale') ? 'selected' : '' ?>><?= htmlspecialchars(__('event_type_no_sale', 'No-Sale Drawer Open')) ?></option>
                </select>
            </div>

            <!-- Cashier Filter -->
            <div>
                <label class="form-label text-xs" style="margin-bottom: 4px;"><?= htmlspecialchars(__('filter_cashier', 'Cashier')) ?></label>
                <select name="cashier_id" class="form-input" style="padding: 6px 10px; font-size: 13px;">
                    <option value="all" <?= ($selectedCashier === 'all') ? 'selected' : '' ?>>-- <?= htmlspecialchars(__('all_cashiers', 'All Cashiers')) ?> --</option>
                    <?php foreach ($allCashiers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($selectedCashier === (string)$c['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['full_name']) ?> (@<?= htmlspecialchars($c['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Device Filter -->
            <div>
                <label class="form-label text-xs" style="margin-bottom: 4px;"><?= htmlspecialchars(__('filter_device', 'POS Device')) ?></label>
                <select name="device" class="form-input" style="padding: 6px 10px; font-size: 13px;">
                    <option value="all" <?= ($selectedDevice === 'all') ? 'selected' : '' ?>><?= htmlspecialchars(__('all_devices', 'All Devices')) ?></option>
                    <option value="web" <?= ($selectedDevice === 'web') ? 'selected' : '' ?>><?= htmlspecialchars(__('back_office_web', 'Back Office Web')) ?></option>
                    <?php foreach ($allRegisteredDevices as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($selectedDevice === (string)$d['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['device_name']) ?> (<?= htmlspecialchars($d['device_uid']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Submit Button -->
            <div>
                <button type="submit" class="btn btn-secondary" style="width: 100%; height: 38px; font-weight: 700;">
                    <i class="fa-solid fa-filter mr-1"></i> Apply
                </button>
            </div>
        </div>
    </form>
</div>

<!-- 4 Overall KPI Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <!-- Cash In -->
    <div class="card" style="padding: 18px 20px; border-left: 4px solid #16a34a;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Total Cash In</span>
            <div style="width: 36px; height: 36px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                <i class="fa-solid fa-arrow-down-long"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #16a34a; margin-top: 6px;">
            <?= formatUSD($totalCashInUsd) ?>
        </div>
        <div class="text-xs text-muted" style="margin-top: 2px;">
            <?= formatKHR($totalCashInKhr) ?>
        </div>
    </div>

    <!-- Cash Out -->
    <div class="card" style="padding: 18px 20px; border-left: 4px solid #ea580c;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Total Cash Out</span>
            <div style="width: 36px; height: 36px; border-radius: 50%; background: #ffedd5; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                <i class="fa-solid fa-arrow-up-long"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #ea580c; margin-top: 6px;">
            <?= formatUSD($totalCashOutUsd) ?>
        </div>
        <div class="text-xs text-muted" style="margin-top: 2px;">
            <?= formatKHR($totalCashOutKhr) ?>
        </div>
    </div>

    <!-- Net Cash Drawer Shift -->
    <?php $netDrawerMovement = $totalCashInUsd - $totalCashOutUsd; ?>
    <div class="card" style="padding: 18px 20px; border-left: 4px solid <?= ($netDrawerMovement >= 0) ? '#2563eb' : '#dc2626' ?>;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Net Drawer Movement</span>
            <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--bg-subtle); color: var(--text-main); display: flex; align-items: center; justify-content: center; font-size: 16px;">
                <i class="fa-solid fa-scale-balanced"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: <?= ($netDrawerMovement >= 0) ? '#2563eb' : '#dc2626' ?>; margin-top: 6px;">
            <?= ($netDrawerMovement > 0 ? '+' : '') . formatUSD($netDrawerMovement) ?>
        </div>
        <div class="text-xs text-muted" style="margin-top: 2px;">
            Formula: Cash In minus Cash Out
        </div>
    </div>

    <!-- No-Sale Opens -->
    <div class="card" style="padding: 18px 20px; border-left: 4px solid #9333ea;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">No-Sale Drawer Opens</span>
            <div style="width: 36px; height: 36px; border-radius: 50%; background: #f3e8ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                <i class="fa-solid fa-unlock-keyhole"></i>
            </div>
        </div>
        <div style="font-size: 24px; font-weight: 800; color: #9333ea; margin-top: 6px;">
            <?= $totalNoSaleCount ?>
        </div>
        <div class="text-xs text-muted" style="margin-top: 2px;">
            Audit trail of non-sale drawer pop events
        </div>
    </div>
</div>

<!-- Cashier Summary & Anomaly Highlighting -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 class="card-title" style="font-size: 16px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-user-shield" style="color: #2563eb;"></i>
                <?= htmlspecialchars(__('cashier_summary', 'Summary per Cashier')) ?>
            </h2>
            <p class="text-xs text-muted" style="margin: 2px 0 0 0;">
                Breakdown of drawer openings and payouts per cashier. Cashiers with unusually high non-sale drawer openings are flagged below.
            </p>
        </div>
        <?php if ($avgNoSale > 0): ?>
            <span class="text-xs text-muted" style="background: var(--bg-subtle); padding: 4px 10px; border-radius: 99px;">
                Shop Average: <strong><?= number_format($avgNoSale, 1) ?></strong> no-sales/cashier
            </span>
        <?php endif; ?>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="min-width: 200px;">Cashier</th>
                    <th class="table-num" style="min-width: 140px;"><?= htmlspecialchars(__('total_no_sale_opens', 'No-Sale Opens')) ?></th>
                    <th style="min-width: 220px;">Risk / Anomaly Status</th>
                    <th class="table-num" style="min-width: 150px;"><?= htmlspecialchars(__('total_cash_out', 'Total Cash Out')) ?></th>
                    <th class="table-num" style="min-width: 150px;"><?= htmlspecialchars(__('total_cash_in', 'Total Cash In')) ?></th>
                    <th class="table-num" style="min-width: 120px;">Total Events</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cashierSummary)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">
                            No cashier drawer activity recorded in this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cashierSummary as $cs): 
                        $isHigh = !empty($cs['is_high_no_sale']);
                    ?>
                        <tr style="<?= $isHigh ? 'background: rgba(220, 38, 38, 0.04);' : '' ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background: <?= $isHigh ? '#fee2e2' : 'var(--bg-subtle)' ?>; color: <?= $isHigh ? '#dc2626' : 'var(--text-main)' ?>; display: flex; align-items: center; justify-content: center; font-size: 13px;">
                                        <i class="fa-solid <?= $isHigh ? 'fa-user-ninja' : 'fa-user' ?>"></i>
                                    </div>
                                    <div>
                                        <strong><?= htmlspecialchars($cs['cashier_name']) ?></strong>
                                        <?php if ($cs['username']): ?>
                                            <div class="text-xs text-muted">@<?= htmlspecialchars($cs['username']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="table-num">
                                <strong style="font-size: 15px; color: <?= $isHigh ? '#dc2626' : 'inherit' ?>;"><?= $cs['no_sale_count'] ?></strong>
                            </td>
                            <td>
                                <?php if ($isHigh): ?>
                                    <span class="badge badge-danger" style="background: #fef2f2; color: #dc2626; border: 1px solid #f87171; font-weight: 700; padding: 4px 10px; display: inline-flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        <?= htmlspecialchars(__('high_no_sale_alert', 'High No-Sale Alert (Unusual Activity)')) ?>
                                    </span>
                                <?php elseif ($cs['no_sale_count'] > 0): ?>
                                    <span class="badge badge-secondary" style="font-size: 11px;">Normal Activity</span>
                                <?php else: ?>
                                    <span class="text-xs text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="table-num">
                                <strong style="color: #ea580c;"><?= formatUSD($cs['cash_out_usd']) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR($cs['cash_out_khr']) ?></div>
                            </td>
                            <td class="table-num">
                                <strong style="color: #16a34a;"><?= formatUSD($cs['cash_in_usd']) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR($cs['cash_in_khr']) ?></div>
                            </td>
                            <td class="table-num">
                                <strong><?= $cs['total_events'] ?></strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================================================================= -->
<!-- CONCEPT 2: SHIFT-GROUPED ACCORDION DRAWER ACTIVITY                -->
<!-- ================================================================= -->
<div class="card" style="margin-bottom: 16px; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg);">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; font-weight: 700; padding: 5px 12px; border-radius: 9999px; font-size: 12px; border: 1px solid rgba(37, 99, 235, 0.2);">
            <i class="fa-solid fa-layer-group mr-1"></i> <?= count($shiftGroups) ?> Shift<?= count($shiftGroups) !== 1 ? 's' : '' ?> • <?= count($allEvents) ?> Event<?= count($allEvents) !== 1 ? 's' : '' ?>
        </span>
        <h2 class="card-title" style="font-size: 17px; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <?= htmlspecialchars(__('shift_grouped_drawer_activity', 'Shift-Grouped Drawer Activity')) ?>
        </h2>
    </div>
    <div style="display: flex; gap: 8px; align-items: center;">
        <button type="button" onclick="expandAllShifts(true)" class="btn btn-secondary btn-sm" style="font-size: 12px; font-weight: 600;">
            <i class="fa-solid fa-angles-down mr-1"></i> Expand All Shifts
        </button>
        <button type="button" onclick="expandAllShifts(false)" class="btn btn-secondary btn-sm" style="font-size: 12px; font-weight: 600;">
            <i class="fa-solid fa-angles-up mr-1"></i> Collapse All
        </button>
    </div>
</div>

<?php if (empty($shiftGroups)): ?>
    <div class="card" style="padding: 48px; text-align: center; color: var(--text-muted); background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg);">
        <i class="fa-solid fa-inbox" style="font-size: 32px; opacity: 0.3; margin-bottom: 12px; display: block;"></i>
        <div style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">No matching drawer events</div>
        <p style="font-size: 13px; margin: 0;">Try adjusting your period, event type, or cashier filters above.</p>
    </div>
<?php else: ?>
    <div id="shiftAccordionContainer" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
        <?php 
        $shiftIdx = 0;
        foreach ($shiftGroups as $sKey => $grp): 
            $shiftIdx++;
            $isDefaultOpen = ($shiftIdx <= 2); // First 2 shifts open by default
            $net = $grp['net_usd'];
            $netColor = ($net > 0) ? '#16a34a' : (($net < 0) ? '#ea580c' : 'var(--text-muted)');
            $netFormatted = ($net === 0.0) ? '$0.00' : (($net > 0 ? '+' : '-') . '$' . number_format(abs($net), 2));
            $netKhrFormatted = ($net === 0.0) ? '0 ៛' : (($net > 0 ? '+' : '-') . number_format(abs($grp['net_khr'])) . ' ៛');
        ?>
            <div class="shift-accordion-card card" style="padding: 0; overflow: hidden; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); background: var(--bg-card);">
                <!-- Collapsible Shift Header -->
                <button type="button" class="shift-accordion-header" onclick="toggleShiftAccordion('<?= htmlspecialchars($sKey) ?>')" style="width: 100%; padding: 14px 18px; background: var(--bg-surface); border: none; cursor: pointer; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; text-align: left; transition: background 0.15s ease;" onmouseover="this.style.background='var(--bg-subtle)';" onmouseout="this.style.background='var(--bg-surface)';">
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <i id="chevron_<?= htmlspecialchars($sKey) ?>" class="fa-solid fa-chevron-right shift-chevron" style="color: var(--text-muted); font-size: 12px; transition: transform 0.2s ease; transform: <?= $isDefaultOpen ? 'rotate(90deg)' : 'rotate(0deg)' ?>;"></i>
                        
                        <span style="font-family: var(--font-mono); font-size: 12.5px; font-weight: 800; background: rgba(2, 132, 199, 0.08); color: var(--primary); padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(2, 132, 199, 0.25);">
                            <?= htmlspecialchars($grp['shift_uid']) ?>
                        </span>

                        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">
                            <?= htmlspecialchars($grp['date']) ?>
                        </span>

                        <span class="badge badge-secondary" style="font-size: 11px; padding: 3px 8px; font-weight: 600;">
                            <?= count($grp['events']) ?> Event<?= count($grp['events']) > 1 ? 's' : '' ?>
                        </span>

                        <?php if ($grp['no_sale_count'] > 0): ?>
                            <span class="badge" style="background: #fdf4ff; color: #9333ea; border: 1px solid #f0abfc; font-size: 11px; padding: 2px 8px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="fa-solid fa-unlock-keyhole" style="font-size: 10px;"></i>
                                <?= $grp['no_sale_count'] ?> No-Sale
                            </span>
                        <?php endif; ?>

                        <span style="font-size: 12.5px; color: var(--text-muted);">
                            Cashier: <strong style="color: var(--text-main);"><?= htmlspecialchars($grp['cashier']) ?></strong>
                            <?php if (!empty($grp['username'])): ?>
                                <span style="color: var(--text-muted); font-weight: normal;">(@<?= htmlspecialchars($grp['username']) ?>)</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Right side: Shift Net Movement -->
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <div style="text-align: right;">
                            <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Shift Net Movement</div>
                            <div style="font-size: 14px; font-weight: 800; color: <?= $netColor ?>; font-family: var(--font-mono);">
                                <?= $netFormatted ?>
                                <span style="font-size: 11px; font-weight: 500; color: var(--text-muted);">(<?= $netKhrFormatted ?>)</span>
                            </div>
                        </div>
                    </div>
                </button>

                <!-- Expanded Shift Events Table -->
                <div id="body_<?= htmlspecialchars($sKey) ?>" class="shift-accordion-body" style="display: <?= $isDefaultOpen ? 'block' : 'none' ?>; border-top: 1px solid var(--border); background: var(--bg-surface);">
                    <div class="table-responsive">
                        <table class="table" style="margin: 0; font-size: 12.5px;">
                            <thead style="background: var(--bg-subtle);">
                                <tr>
                                    <th style="min-width: 170px;">Event UID</th>
                                    <th style="min-width: 140px;">Event Type</th>
                                    <th>Reason / Memo</th>
                                    <th style="min-width: 160px;">Authorized Manager</th>
                                    <th style="min-width: 140px;">POS Device</th>
                                    <th class="table-num" style="min-width: 140px; text-align: right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($grp['events'] as $ev): 
                                    $type = $ev['event_type'];
                                    $isCashIn = ($type === 'cash_in');
                                    $isCashOut = ($type === 'cash_out');
                                    $isNoSale = ($type === 'no_sale' || $type === 'no_sale_open');
                                    $devStr = $ev['device_name'] ? "{$ev['device_name']}" : 'Back Office Web';
                                ?>
                                    <tr style="transition: background 0.15s ease;">
                                        <!-- Event UID + Copy Button -->
                                        <td style="white-space: nowrap;">
                                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                <span style="font-family: var(--font-mono); font-weight: 700; font-size: 11.5px; background: var(--bg-subtle); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border); color: var(--text-main);">
                                                    <?= htmlspecialchars($ev['event_uid']) ?>
                                                </span>
                                                <button type="button" onclick="copyToClipboard('<?= htmlspecialchars($ev['event_uid']) ?>', 'Event UID')" style="background: transparent; border: none; cursor: pointer; color: var(--text-muted); padding: 2px 4px; font-size: 11px;" title="Copy Event UID" onmouseover="this.style.color='var(--primary)';" onmouseout="this.style.color='var(--text-muted)';">
                                                    <i class="fa-solid fa-copy"></i>
                                                </button>
                                            </div>
                                            <div class="text-xs text-muted" style="margin-top: 2px; font-family: var(--font-mono); font-size: 11px;">
                                                <?= date('h:i A', strtotime($ev['created_at'])) ?>
                                            </div>
                                        </td>

                                        <!-- Event Type -->
                                        <td style="white-space: nowrap;">
                                            <?php if ($isCashIn): ?>
                                                <span class="badge badge-success" style="background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; display: inline-flex; align-items: center; gap: 5px; font-weight: 700; font-size: 11px;">
                                                    <i class="fa-solid fa-arrow-down-long"></i> <?= htmlspecialchars(__('event_type_cash_in', 'Cash In')) ?>
                                                </span>
                                            <?php elseif ($isCashOut): ?>
                                                <span class="badge badge-warning" style="background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; display: inline-flex; align-items: center; gap: 5px; font-weight: 700; font-size: 11px;">
                                                    <i class="fa-solid fa-arrow-up-long"></i> <?= htmlspecialchars(__('event_type_cash_out', 'Cash Out')) ?>
                                                </span>
                                            <?php elseif ($isNoSale): ?>
                                                <span class="badge badge-danger" style="background: #fdf4ff; color: #9333ea; border: 1px solid #f0abfc; display: inline-flex; align-items: center; gap: 5px; font-weight: 700; font-size: 11px;">
                                                    <i class="fa-solid fa-unlock-keyhole"></i> <?= htmlspecialchars(__('event_type_no_sale', 'No-Sale Open')) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary"><?= htmlspecialchars(ucfirst($type)) ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Reason / Memo -->
                                        <td style="font-weight: 600; color: var(--text-main); line-height: 1.4;">
                                            <?= htmlspecialchars($ev['reason'] ?: '-') ?>
                                        </td>

                                        <!-- Authorized Manager -->
                                        <td>
                                            <?php if ($ev['manager_name']): ?>
                                                <div style="display: flex; align-items: center; gap: 5px; font-weight: 600; color: #1d4ed8;">
                                                    <i class="fa-solid fa-shield-check text-xs"></i>
                                                    <?= htmlspecialchars($ev['manager_name']) ?>
                                                </div>
                                                <?php if ($ev['manager_username']): ?>
                                                    <div class="text-xs text-muted">@<?= htmlspecialchars($ev['manager_username']) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-style: italic; font-size: 12px;">Not required</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- POS Device -->
                                        <td class="text-xs text-muted" style="white-space: nowrap;">
                                            <i class="fa-solid fa-desktop mr-1" style="color: var(--text-muted);"></i>
                                            <?= htmlspecialchars($devStr) ?>
                                        </td>

                                        <!-- Amount -->
                                        <td class="table-num" style="text-align: right; white-space: nowrap;">
                                            <?php if ($isNoSale): ?>
                                                <span class="text-muted text-xs font-semibold">— (No Sale)</span>
                                            <?php else: ?>
                                                <strong style="color: <?= $isCashIn ? '#16a34a' : '#ea580c' ?>; font-size: 13.5px; font-family: var(--font-mono);">
                                                    <?= ($isCashIn ? '+' : '-') . formatUSD((float)$ev['amount_usd']) ?>
                                                </strong>
                                                <div class="text-xs text-muted" style="font-weight: 500; font-family: var(--font-mono);"><?= formatKHR((int)$ev['amount_khr']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function toggleShiftAccordion(key) {
    const body = document.getElementById('body_' + key);
    const chevron = document.getElementById('chevron_' + key);
    if (!body || !chevron) return;
    
    if (body.style.display === 'none') {
        body.style.display = 'block';
        chevron.style.transform = 'rotate(90deg)';
    } else {
        body.style.display = 'none';
        chevron.style.transform = 'rotate(0deg)';
    }
}

function expandAllShifts(expand) {
    document.querySelectorAll('.shift-accordion-body').forEach(el => {
        el.style.display = expand ? 'block' : 'none';
    });
    document.querySelectorAll('.shift-chevron').forEach(el => {
        el.style.transform = expand ? 'rotate(90deg)' : 'rotate(0deg)';
    });
}

function copyToClipboard(text, label) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('Copied ' + label + ': ' + text);
        }).catch(() => {
            copyFallback(text, label);
        });
    } else {
        copyFallback(text, label);
    }
}

function copyFallback(text, label) {
    const temp = document.createElement('input');
    temp.value = text;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand('copy');
    document.body.removeChild(temp);
    showToast('Copied ' + label + ': ' + text);
}

function showToast(msg) {
    let toast = document.getElementById('cmToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'cmToast';
        toast.style.position = 'fixed';
        toast.style.bottom = '24px';
        toast.style.right = '24px';
        toast.style.zIndex = '99999';
        toast.style.background = '#0f172a';
        toast.style.color = '#ffffff';
        toast.style.padding = '10px 18px';
        toast.style.borderRadius = '8px';
        toast.style.fontSize = '13px';
        toast.style.fontWeight = '600';
        toast.style.boxShadow = '0 10px 25px rgba(0,0,0,0.2)';
        toast.style.transition = 'all 0.2s ease';
        toast.style.pointerEvents = 'none';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
    }, 2200);
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
