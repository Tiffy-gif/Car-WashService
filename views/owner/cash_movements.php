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

<!-- Detailed Drawer Events Table -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 class="card-title" style="font-size: 16px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-list-ol" style="color: var(--primary);"></i>
                Detailed Drawer Events Log
            </h2>
            <p class="text-xs text-muted" style="margin: 2px 0 0 0;">
                Showing <?= count($allEvents) ?> events matching selected filters.
            </p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="min-width: 140px;">Date &amp; Time</th>
                    <th style="min-width: 130px;">Event UID</th>
                    <th style="min-width: 150px;"><?= htmlspecialchars(__('filter_type', 'Event Type')) ?></th>
                    <th class="table-num" style="min-width: 140px;">Amount</th>
                    <th style="min-width: 160px;"><?= htmlspecialchars(__('filter_cashier', 'Cashier')) ?></th>
                    <th style="min-width: 160px;"><?= htmlspecialchars(__('authorized_manager', 'Authorized Manager')) ?></th>
                    <th style="min-width: 200px;">Reason</th>
                    <th style="min-width: 120px;"><?= htmlspecialchars(__('shift_uid', 'Shift')) ?></th>
                    <th style="min-width: 140px;"><?= htmlspecialchars(__('filter_device', 'POS Device')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allEvents)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 32px;">
                            <i class="fa-solid fa-inbox" style="font-size: 24px; color: var(--border); display: block; margin-bottom: 8px;"></i>
                            No drawer events match the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($allEvents as $ev): 
                        $type = $ev['event_type'];
                        $isCashIn = ($type === 'cash_in');
                        $isCashOut = ($type === 'cash_out');
                        $isNoSale = ($type === 'no_sale' || $type === 'no_sale_open');
                        $devStr = $ev['device_name'] ? "{$ev['device_name']}" : 'Back Office Web';
                    ?>
                        <tr>
                            <td class="text-xs" style="font-family: var(--font-mono); white-space: nowrap;">
                                <?= date('d M Y, h:i A', strtotime($ev['created_at'])) ?>
                            </td>
                            <td>
                                <span style="font-family: var(--font-mono); font-weight: 700; font-size: 12px; background: var(--bg-subtle); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border);">
                                    <?= htmlspecialchars($ev['event_uid']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isCashIn): ?>
                                    <span class="badge badge-success" style="background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-arrow-down-long"></i> <?= htmlspecialchars(__('event_type_cash_in', 'Cash In')) ?>
                                    </span>
                                <?php elseif ($isCashOut): ?>
                                    <span class="badge badge-warning" style="background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-arrow-up-long"></i> <?= htmlspecialchars(__('event_type_cash_out', 'Cash Out')) ?>
                                    </span>
                                <?php elseif ($isNoSale): ?>
                                    <span class="badge badge-danger" style="background: #fdf4ff; color: #9333ea; border: 1px solid #f0abfc; display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-unlock-keyhole"></i> <?= htmlspecialchars(__('event_type_no_sale', 'No-Sale Open')) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?= htmlspecialchars(ucfirst($type)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="table-num">
                                <?php if ($isNoSale): ?>
                                    <span class="text-muted text-xs">- (No Sale)</span>
                                <?php else: ?>
                                    <strong style="color: <?= $isCashIn ? '#16a34a' : '#ea580c' ?>;">
                                        <?= ($isCashIn ? '+' : '-') . formatUSD((float)$ev['amount_usd']) ?>
                                    </strong>
                                    <div class="text-xs text-muted"><?= formatKHR((int)$ev['amount_khr']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($ev['cashier_name']) ?></strong>
                                <?php if ($ev['cashier_username']): ?>
                                    <div class="text-xs text-muted">@<?= htmlspecialchars($ev['cashier_username']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ev['manager_name']): ?>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-user-check text-xs" style="color: #2563eb;"></i>
                                        <strong><?= htmlspecialchars($ev['manager_name']) ?></strong>
                                    </div>
                                    <?php if ($ev['manager_username']): ?>
                                        <div class="text-xs text-muted">@<?= htmlspecialchars($ev['manager_username']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12.5px; line-height: 1.4;">
                                <?= htmlspecialchars($ev['reason'] ?: '-') ?>
                            </td>
                            <td>
                                <?php if ($ev['shift_uid']): ?>
                                    <a href="?view=shift#shift_<?= (int)$ev['shift_id'] ?>" style="font-family: var(--font-mono); font-weight: 700; font-size: 12px; color: var(--primary);">
                                        <?= htmlspecialchars($ev['shift_uid']) ?>
                                    </a>
                                <?php elseif ($ev['shift_id']): ?>
                                    <span style="font-family: var(--font-mono); font-size: 12px;">#<?= (int)$ev['shift_id'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs text-muted">
                                <i class="fa-solid fa-tablet-screen-button mr-1"></i>
                                <?= htmlspecialchars($devStr) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
