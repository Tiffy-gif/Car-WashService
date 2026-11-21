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
    die("Permission denied: Cashier Sessions report is visible only to Owner and Manager.");
}

$pageTitle = __('nav_cashier_sessions', 'Cashier Sessions & Shift Audits');
$pdo = Database::getInstance();
$curRate = get_exchange_rate();

// Defensive column existence check for backward compatibility
$hasNeedsReviewCol = false;
$hasReviewReasonCol = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cashier_shifts")->fetchAll(PDO::FETCH_COLUMN);
    $hasNeedsReviewCol = in_array('needs_review', $cols, true);
    $hasReviewReasonCol = in_array('review_reason', $cols, true);
} catch (Throwable $e) {
    $hasNeedsReviewCol = false;
    $hasReviewReasonCol = false;
}

// 1. Resolve Filter Parameters
$period = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedCashier = trim((string)($_GET['cashier_id'] ?? 'all'));
$selectedStatus = trim((string)($_GET['status'] ?? 'all'));
$needsReviewOnly = (isset($_GET['needs_review']) && ($_GET['needs_review'] === '1' || $_GET['needs_review'] === 'yes'));

// Pagination parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 25);
if ($limit <= 0 || $limit > 200) {
    $limit = 25;
}

// Date where clause
$dateWhere = "";
$dateParams = [];

if ($period === 'today') {
    $dateWhere = " AND DATE(cs.started_at) = CURDATE()";
} elseif ($period === 'yesterday') {
    $dateWhere = " AND DATE(cs.started_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
} elseif ($period === 'this_week') {
    $dateWhere = " AND YEARWEEK(cs.started_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period === 'this_month') {
    $dateWhere = " AND MONTH(cs.started_at) = MONTH(CURDATE()) AND YEAR(cs.started_at) = YEAR(CURDATE())";
} elseif ($period === 'this_year') {
    $dateWhere = " AND YEAR(cs.started_at) = YEAR(CURDATE())";
} elseif ($period === 'all') {
    $dateWhere = "";
} elseif ($period === 'custom' && $startDate && $endDate) {
    $dateWhere = " AND DATE(cs.started_at) BETWEEN ? AND ?";
    $dateParams = [$startDate, $endDate];
} else {
    $period = 'this_month';
    $dateWhere = " AND MONTH(cs.started_at) = MONTH(CURDATE()) AND YEAR(cs.started_at) = YEAR(CURDATE())";
}

// Cashier filter
$cashierWhere = "";
$cashierParams = [];
if ($selectedCashier !== 'all' && is_numeric($selectedCashier) && (int)$selectedCashier > 0) {
    $cashierWhere = " AND cs.cashier_id = ?";
    $cashierParams[] = (int)$selectedCashier;
} else {
    $selectedCashier = 'all';
}

// Status filter
$statusWhere = "";
if ($selectedStatus === 'open') {
    $statusWhere = " AND cs.status = 'open'";
} elseif ($selectedStatus === 'closed') {
    $statusWhere = " AND cs.status = 'closed'";
} elseif ($selectedStatus === 'force_closed') {
    $statusWhere = " AND (cs.status = 'force_closed' OR cs.notes LIKE '%force%' OR cs.notes LIKE '%manager%')";
} else {
    $selectedStatus = 'all';
}

// Needs review SQL expression
$needsReviewSqlFragment = $hasNeedsReviewCol ? "cs.needs_review = 1" : "0 = 1";
$needsReviewCondition = "(
    ({$needsReviewSqlFragment})
    OR cs.status = 'force_closed'
    OR (cs.status = 'closed' AND (cs.counted_cash_usd IS NULL OR cs.notes LIKE '%uncounted%' OR cs.notes LIKE '%not counted%'))
    OR (cs.ended_at IS NOT NULL AND DATE(cs.started_at) != DATE(cs.ended_at))
    OR (cs.status = 'open' AND DATE(cs.started_at) < CURDATE())
    OR TIMESTAMPDIFF(HOUR, cs.started_at, COALESCE(cs.ended_at, NOW())) >= 16
)";

$needsReviewWhere = "";
if ($needsReviewOnly) {
    $needsReviewWhere = " AND {$needsReviewCondition}";
}

// Common WHERE clause
$baseWhere = " WHERE 1=1 {$dateWhere} {$cashierWhere} {$statusWhere} {$needsReviewWhere}";
$baseParams = array_merge($dateParams, $cashierParams);

// 2. Fetch Active Staff / Cashiers for Filter Dropdown
$allCashiers = $pdo->query("
    SELECT id, username, full_name, role 
    FROM users 
    WHERE role IN ('cashier', 'manager', 'owner') 
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Compute Summary Statistics for Current Filter Scope (Without Paging)
$summarySql = "
    SELECT 
        COUNT(cs.id) AS total_shifts,
        SUM(CASE WHEN cs.total_difference_usd < 0 THEN ABS(cs.total_difference_usd) ELSE 0 END) AS total_shortage_diff_usd,
        SUM(COALESCE(cs.shortage_owed_usd, 0)) AS total_shortage_owed_usd,
        SUM(CASE WHEN cs.total_difference_usd > 0 THEN cs.total_difference_usd ELSE 0 END) AS total_surplus_usd,
        SUM(CASE WHEN {$needsReviewCondition} THEN 1 ELSE 0 END) AS total_needs_review
    FROM cashier_shifts cs
    {$baseWhere}
";
$sumStmt = $pdo->prepare($summarySql);
$sumStmt->execute($baseParams);
$summaryStats = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalShifts = (int)($summaryStats['total_shifts'] ?? 0);
$totalShortageUsd = (float)($summaryStats['total_shortage_owed_usd'] > 0 ? $summaryStats['total_shortage_owed_usd'] : $summaryStats['total_shortage_diff_usd'] ?? 0.00);
$totalSurplusUsd = (float)($summaryStats['total_surplus_usd'] ?? 0.00);
$totalNeedsReview = (int)($summaryStats['total_needs_review'] ?? 0);

// Calculate Pagination
$totalPages = max(1, (int)ceil($totalShifts / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $limit;

// 4. Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Clean output buffer to ensure pure CSV download
    if (ob_get_level()) {
        ob_end_clean();
    }

    $exportSql = "
        SELECT 
            cs.id,
            cs.shift_uid,
            cs.cashier_id,
            cs.cashier_name,
            cs.pos_device_name,
            cs.status,
            cs.started_at,
            cs.ended_at,
            cs.opening_cash_usd,
            cs.opening_cash_khr,
            cs.expected_cash_usd,
            cs.expected_cash_khr,
            cs.counted_cash_usd,
            cs.counted_cash_khr,
            cs.difference_usd,
            cs.difference_khr,
            cs.total_difference_usd,
            cs.shortage_owed_usd,
            cs.notes,
            " . ($hasNeedsReviewCol ? "cs.needs_review" : "0 AS needs_review") . ",
            " . ($hasReviewReasonCol ? "cs.review_reason" : "'' AS review_reason") . ",
            CASE WHEN {$needsReviewCondition} THEN 1 ELSE 0 END AS computed_needs_review
        FROM cashier_shifts cs
        {$baseWhere}
        ORDER BY cs.started_at DESC, cs.id DESC
    ";
    $expStmt = $pdo->prepare($exportSql);
    $expStmt->execute($baseParams);

    $filename = 'cashier_sessions_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Output UTF-8 BOM for proper Excel / Khmer rendering
    fputs($out, "\xEF\xBB\xBF");

    // Summary Header
    fputcsv($out, ['--- CASHIER SESSIONS AUDIT SUMMARY ---']);
    fputcsv($out, ['Filter Period', $period, 'Date Range', $startDate . ' to ' . $endDate]);
    fputcsv($out, ['Total Shifts', $totalShifts, 'Total Shortage (USD)', number_format($totalShortageUsd, 2), 'Total Surplus (USD)', number_format($totalSurplusUsd, 2), 'Needs Review Count', $totalNeedsReview]);
    fputcsv($out, []);

    // Column Headers
    fputcsv($out, [
        'Shift ID',
        'Cashier',
        'POS Device',
        'Status',
        'Needs Review',
        'Started At',
        'Ended At',
        'Duration (Hrs)',
        'Opening Cash USD',
        'Opening Cash KHR',
        'Expected Cash USD',
        'Expected Cash KHR',
        'Counted Cash USD',
        'Counted Cash KHR',
        'Difference USD',
        'Difference KHR',
        'Total Diff USD',
        'Shortage Owed USD',
        'Review Reason / Notes'
    ]);

    while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        $isReview = (int)$r['computed_needs_review'] === 1;
        $duration = '';
        if (!empty($r['started_at'])) {
            $endTimestamp = !empty($r['ended_at']) ? strtotime($r['ended_at']) : time();
            $startTimestamp = strtotime($r['started_at']);
            $hours = round(($endTimestamp - $startTimestamp) / 3600, 1);
            $duration = (string)$hours;
        }

        $countedUsdText = ($r['counted_cash_usd'] !== null) ? number_format((float)$r['counted_cash_usd'], 2) : 'Uncounted';
        $countedKhrText = ($r['counted_cash_khr'] !== null) ? number_format((float)$r['counted_cash_khr'], 0) : 'Uncounted';

        $notesCombined = trim(($r['review_reason'] ? "Review: " . $r['review_reason'] . " | " : "") . ($r['notes'] ?: ''));

        fputcsv($out, [
            $r['shift_uid'],
            $r['cashier_name'],
            $r['pos_device_name'] ?: 'POS Terminal',
            strtoupper($r['status']),
            $isReview ? 'YES' : 'NO',
            $r['started_at'],
            $r['ended_at'] ?: 'Active / Open',
            $duration,
            number_format((float)$r['opening_cash_usd'], 2),
            number_format((float)$r['opening_cash_khr'], 0),
            $r['expected_cash_usd'] !== null ? number_format((float)$r['expected_cash_usd'], 2) : '',
            $r['expected_cash_khr'] !== null ? number_format((float)$r['expected_cash_khr'], 0) : '',
            $countedUsdText,
            $countedKhrText,
            $r['difference_usd'] !== null ? number_format((float)$r['difference_usd'], 2) : '',
            $r['difference_khr'] !== null ? number_format((float)$r['difference_khr'], 0) : '',
            $r['total_difference_usd'] !== null ? number_format((float)$r['total_difference_usd'], 2) : '',
            number_format((float)$r['shortage_owed_usd'], 2),
            $notesCombined
        ]);
    }

    fclose($out);
    exit;
}

// 5. Query Detailed Shifts with Limit and Offset (Pagination)
$shiftsSql = "
    SELECT 
        cs.id,
        cs.shift_uid,
        cs.pos_device_id,
        cs.pos_device_name,
        cs.cashier_id,
        cs.cashier_name,
        cs.started_at,
        cs.ended_at,
        cs.opening_cash_usd,
        cs.opening_cash_khr,
        cs.exchange_rate,
        cs.expected_cash_usd,
        cs.expected_cash_khr,
        cs.counted_cash_usd,
        cs.counted_cash_khr,
        cs.difference_usd,
        cs.difference_khr,
        cs.total_difference_usd,
        cs.shortage_owed_usd,
        cs.handover_to_cashier_id,
        cs.handover_to_cashier_name,
        cs.status,
        cs.notes,
        " . ($hasNeedsReviewCol ? "cs.needs_review" : "0 AS needs_review") . ",
        " . ($hasReviewReasonCol ? "cs.review_reason" : "'' AS review_reason") . ",
        CASE WHEN {$needsReviewCondition} THEN 1 ELSE 0 END AS computed_needs_review
    FROM cashier_shifts cs
    {$baseWhere}
    ORDER BY cs.started_at DESC, cs.id DESC
    LIMIT {$limit} OFFSET {$offset}
";
$shiftStmt = $pdo->prepare($shiftsSql);
$shiftStmt->execute($baseParams);
$shifts = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for pagination links
function buildSessionsUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    unset($params['export']);
    return '?' . http_build_query($params);
}

require_once dirname(__DIR__, 2) . '/views/layouts/header.php';
?>

<div class="container-fluid" style="padding: 24px; max-width: 1600px; margin: 0 auto;">

    <!-- TOP HEADER & TITLE -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <h1 style="margin: 0; font-size: 26px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-user-clock" style="color: #2563eb;"></i>
                    <?= htmlspecialchars(__('cashier_sessions_title', 'Cashier Sessions & Shift Audits')) ?>
                </h1>
                <span class="badge" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 700; padding: 4px 10px; border-radius: 9999px;">
                    <?= number_format($totalShifts) ?> <?= htmlspecialchars(__('total_shifts', 'Shifts')) ?>
                </span>
            </div>
            <p style="margin: 6px 0 0 0; color: var(--text-muted); font-size: 14px;">
                <?= htmlspecialchars(__('cashier_sessions_desc', 'Audit cashier shifts, opening floats, counted cash, over/short balances, shortages, and review flags.')) ?>
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="?view=shift" class="btn btn-secondary">
                <i class="fa-solid fa-cash-register mr-1"></i> <?= htmlspecialchars(__('nav_shifts', 'Active Shifts')) ?>
            </a>
            <a href="?view=cash_movements" class="btn btn-secondary">
                <i class="fa-solid fa-money-bill-transfer mr-1" style="color: #ea580c;"></i> <?= htmlspecialchars(__('nav_cash_movements', 'Cash Movements')) ?>
            </a>
            <?php if (Auth::can('reports.export')): ?>
            <a href="<?= htmlspecialchars(buildSessionsUrl(['export' => 'csv'])) ?>" class="btn btn-primary" style="background: #2563eb; border-color: #2563eb;">
                <i class="fa-solid fa-file-csv mr-1"></i> <?= htmlspecialchars(__('export_shifts_csv', 'Export Shifts (CSV)')) ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4 KPI SUMMARY CARDS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <!-- Total Shifts -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid #2563eb; background: var(--bg-card); display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                    <?= htmlspecialchars(__('total_shifts', 'Total Shifts')) ?>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: var(--text-main); margin-top: 4px;">
                    <?= number_format($totalShifts) ?>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                    <?= htmlspecialchars(__('filter_status', 'Across filter scope')) ?>
                </div>
            </div>
            <div style="width: 46px; height: 46px; border-radius: var(--radius-md); background: #dbeafe; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-calendar-check"></i>
            </div>
        </div>

        <!-- Total Shortage -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid #dc2626; background: var(--bg-card); display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 0.5px;">
                    <?= htmlspecialchars(__('total_shortage', 'Total Shortage')) ?>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: #dc2626; margin-top: 4px;">
                    $<?= number_format($totalShortageUsd, 2) ?>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                    <?= htmlspecialchars(__('shortage_owed', 'Shortage recorded / owed')) ?>
                </div>
            </div>
            <div style="width: 46px; height: 46px; border-radius: var(--radius-md); background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-arrow-trend-down"></i>
            </div>
        </div>

        <!-- Total Surplus -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid #16a34a; background: var(--bg-card); display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: #16a34a; text-transform: uppercase; letter-spacing: 0.5px;">
                    <?= htmlspecialchars(__('total_surplus', 'Total Surplus')) ?>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: #16a34a; margin-top: 4px;">
                    +$<?= number_format($totalSurplusUsd, 2) ?>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                    <?= htmlspecialchars(__('over_short', 'Over counted in drawer')) ?>
                </div>
            </div>
            <div style="width: 46px; height: 46px; border-radius: var(--radius-md); background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-arrow-trend-up"></i>
            </div>
        </div>

        <!-- Shifts Needing Review -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid <?= $totalNeedsReview > 0 ? '#ea580c' : '#64748b' ?>; background: var(--bg-card); display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: <?= $totalNeedsReview > 0 ? '#ea580c' : 'var(--text-muted)' ?>; text-transform: uppercase; letter-spacing: 0.5px;">
                    <?= htmlspecialchars(__('needs_review_count', 'Needs Review')) ?>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: <?= $totalNeedsReview > 0 ? '#ea580c' : 'var(--text-main)' ?>; margin-top: 4px;">
                    <?= number_format($totalNeedsReview) ?>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                    <?= htmlspecialchars(__('needs_review_badge', 'Forced, uncounted, overnight')) ?>
                </div>
            </div>
            <div style="width: 46px; height: 46px; border-radius: var(--radius-md); background: <?= $totalNeedsReview > 0 ? '#ffedd5' : '#f1f5f9' ?>; color: <?= $totalNeedsReview > 0 ? '#ea580c' : '#64748b' ?>; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="card" style="padding: 20px; margin-bottom: 24px; background: var(--bg-card);">
        <form method="GET" action="" style="display: flex; flex-direction: column; gap: 16px;">
            <input type="hidden" name="view" value="cashier_sessions">

            <!-- Row 1: Period Pills -->
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                    <span style="font-size: 13px; font-weight: 700; color: var(--text-muted); margin-right: 4px;">
                        <i class="fa-regular fa-calendar-days mr-1"></i> Period:
                    </span>
                    <?php
                    $periods = [
                        'today'      => __('today', 'Today'),
                        'yesterday'  => __('yesterday', 'Yesterday'),
                        'this_week'  => __('this_week', 'This Week'),
                        'this_month' => __('this_month', 'This Month'),
                        'this_year'  => __('this_year', 'This Year'),
                        'all'        => __('all_time', 'All Time'),
                        'custom'     => __('custom_range', 'Custom Range')
                    ];
                    foreach ($periods as $pKey => $pLabel):
                        $active = ($period === $pKey);
                    ?>
                    <button type="submit" name="period" value="<?= $pKey ?>" 
                            class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-secondary' ?>"
                            style="border-radius: 9999px; padding: 4px 14px; font-weight: <?= $active ? '700' : '500' ?>;">
                        <?= htmlspecialchars($pLabel) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Needs Review Only Quick Toggle -->
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 700; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; padding: 6px 14px; border-radius: 9999px;">
                        <input type="checkbox" name="needs_review" value="1" <?= $needsReviewOnly ? 'checked' : '' ?> onchange="this.form.submit()" style="accent-color: #ea580c; width: 16px; height: 16px;">
                        <span><i class="fa-solid fa-triangle-exclamation mr-1"></i> <?= htmlspecialchars(__('needs_review_only', 'Needs Review Only')) ?></span>
                    </label>
                </div>
            </div>

            <!-- Row 2: Dropdowns & Custom Date Range -->
            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; padding-top: 12px; border-top: 1px solid var(--border);">
                <!-- Cashier Filter -->
                <div style="flex: 1; min-width: 180px;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 5px;">
                        <i class="fa-solid fa-user mr-1"></i> <?= htmlspecialchars(__('filter_cashier', 'Cashier')) ?>
                    </label>
                    <select name="cashier_id" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="all"><?= htmlspecialchars(__('all_cashiers', 'All Cashiers')) ?></option>
                        <?php foreach ($allCashiers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ((string)$selectedCashier === (string)$c['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['full_name'] ?: $c['username']) ?> (<?= htmlspecialchars(ucfirst($c['role'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Shift Status Filter -->
                <div style="flex: 1; min-width: 170px;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 5px;">
                        <i class="fa-solid fa-signal mr-1"></i> <?= htmlspecialchars(__('filter_status', 'Shift Status')) ?>
                    </label>
                    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="all" <?= ($selectedStatus === 'all') ? 'selected' : '' ?>><?= htmlspecialchars(__('all_statuses', 'All Statuses')) ?></option>
                        <option value="open" <?= ($selectedStatus === 'open') ? 'selected' : '' ?>><?= htmlspecialchars(__('status_open', 'Active Open')) ?></option>
                        <option value="closed" <?= ($selectedStatus === 'closed') ? 'selected' : '' ?>><?= htmlspecialchars(__('status_closed', 'Closed')) ?></option>
                        <option value="force_closed" <?= ($selectedStatus === 'force_closed') ? 'selected' : '' ?>><?= htmlspecialchars(__('status_force_closed', 'Force Ended')) ?></option>
                    </select>
                </div>

                <!-- Items Per Page -->
                <div style="width: 130px;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 5px;">
                        <i class="fa-solid fa-list-ol mr-1"></i> <?= htmlspecialchars(__('shifts_per_page', 'Per Page')) ?>
                    </label>
                    <select name="limit" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="20" <?= ($limit === 20) ? 'selected' : '' ?>>20 / page</option>
                        <option value="25" <?= ($limit === 25) ? 'selected' : '' ?>>25 / page</option>
                        <option value="50" <?= ($limit === 50) ? 'selected' : '' ?>>50 / page</option>
                        <option value="100" <?= ($limit === 100) ? 'selected' : '' ?>>100 / page</option>
                    </select>
                </div>

                <!-- Custom Dates -->
                <div style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 5px;">Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-control form-control-sm" style="width: 140px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 5px;">End Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-control form-control-sm" style="width: 140px;">
                    </div>
                    <button type="submit" name="period" value="custom" class="btn btn-primary btn-sm" style="height: 33px;">
                        <i class="fa-solid fa-filter mr-1"></i> Filter
                    </button>
                    <a href="?view=cashier_sessions" class="btn btn-secondary btn-sm" style="height: 33px;" title="Reset filters">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- MAIN SHIFTS TABLE -->
    <div class="card" style="background: var(--bg-card); overflow: hidden; margin-bottom: 24px;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="font-weight: 700; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-table-list text-primary"></i>
                <span>Cashier Shift Records</span>
                <span class="text-xs text-muted" style="font-weight: normal;">
                    (<?= sprintf(htmlspecialchars(__('showing_shifts_paged', 'Showing %d to %d of %d shifts')), ($totalShifts > 0 ? $offset + 1 : 0), min($offset + $limit, $totalShifts), $totalShifts) ?>)
                </span>
            </div>

            <!-- Page Selector Jump -->
            <?php if ($totalPages > 1): ?>
            <div style="display: flex; gap: 6px; align-items: center;">
                <span style="font-size: 12px; color: var(--text-muted);">Page:</span>
                <select onchange="window.location.href=this.value" class="form-control form-control-sm" style="width: auto; padding: 2px 8px; font-size: 12px;">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <option value="<?= htmlspecialchars(buildSessionsUrl(['page' => $p])) ?>" <?= ($p === $page) ? 'selected' : '' ?>>
                        <?= $p ?> / <?= $totalPages ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table" style="margin-bottom: 0; font-size: 13px;">
                <thead style="background: var(--bg-subtle);">
                    <tr>
                        <th style="width: 110px;">Shift ID</th>
                        <th>Cashier</th>
                        <th>Device</th>
                        <th>Started</th>
                        <th>Ended</th>
                        <th style="text-align: right;">Opening Float</th>
                        <th style="text-align: right;">Expected Cash</th>
                        <th style="text-align: right;">Counted Cash</th>
                        <th style="text-align: right;">Difference</th>
                        <th style="text-align: right;">Shortage Owed</th>
                        <th style="text-align: center;">Status</th>
                        <th style="text-align: center;">Needs Review</th>
                        <th style="text-align: center; width: 190px;">Slips &amp; Audit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($shifts)): ?>
                    <tr>
                        <td colspan="13" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                            <i class="fa-solid fa-inbox" style="font-size: 32px; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                            <?= htmlspecialchars(__('no_shifts_found', 'No cashier shifts found matching the selected filters.')) ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($shifts as $s): 
                            $isReview = (int)$s['computed_needs_review'] === 1;
                            $status = $s['status'] ?? 'closed';
                            $isOpen = ($status === 'open');
                            $isForce = ($status === 'force_closed' || stripos($s['notes'] ?? '', 'force') !== false);
                            
                            // Difference calculations
                            $diffUsd = (float)($s['total_difference_usd'] ?? $s['difference_usd'] ?? 0.00);
                            $diffKhr = (int)($s['difference_khr'] ?? 0);
                            $shortageOwed = (float)($s['shortage_owed_usd'] ?? 0.00);

                            // Detect reasons for Needs Review
                            $reviewReasons = [];
                            if ($isForce) $reviewReasons[] = 'Force Ended';
                            if ($status === 'closed' && ($s['counted_cash_usd'] === null || stripos($s['notes'] ?? '', 'uncounted') !== false)) {
                                $reviewReasons[] = 'Uncounted Cash';
                            }
                            if (!empty($s['started_at']) && !empty($s['ended_at']) && date('Y-m-d', strtotime($s['started_at'])) !== date('Y-m-d', strtotime($s['ended_at']))) {
                                $reviewReasons[] = 'Overnight Shift';
                            }
                            if ($isOpen && !empty($s['started_at']) && date('Y-m-d', strtotime($s['started_at'])) < date('Y-m-d')) {
                                $reviewReasons[] = 'Stale Open Shift';
                            }
                            if (!empty($s['review_reason'])) {
                                $reviewReasons[] = $s['review_reason'];
                            }
                        ?>
                        <tr style="<?= $isReview ? 'background: #fffbeb;' : '' ?>">
                            <!-- Shift UID -->
                            <td style="font-family: monospace; font-weight: 700;">
                                <span title="Shift #<?= (int)$s['id'] ?>">
                                    <?= htmlspecialchars($s['shift_uid'] ?: 'CS-' . str_pad((string)$s['id'], 5, '0', STR_PAD_LEFT)) ?>
                                </span>
                            </td>

                            <!-- Cashier -->
                            <td>
                                <div style="font-weight: 700; color: var(--text-main);">
                                    <?= htmlspecialchars($s['cashier_name'] ?: 'Cashier #' . $s['cashier_id']) ?>
                                </div>
                                <?php if (!empty($s['handover_to_cashier_name'])): ?>
                                <div style="font-size: 11px; color: var(--text-muted);">
                                    <i class="fa-solid fa-arrow-right mr-1"></i> Handover: <?= htmlspecialchars($s['handover_to_cashier_name']) ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Device -->
                            <td>
                                <span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 11px;">
                                    <i class="fa-solid fa-desktop mr-1"></i> <?= htmlspecialchars($s['pos_device_name'] ?: 'POS Terminal') ?>
                                </span>
                            </td>

                            <!-- Started -->
                            <td style="font-size: 12px; white-space: nowrap;">
                                <?= date('Y-m-d H:i', strtotime($s['started_at'])) ?>
                            </td>

                            <!-- Ended -->
                            <td style="font-size: 12px; white-space: nowrap;">
                                <?php if (!empty($s['ended_at'])): ?>
                                    <?= date('Y-m-d H:i', strtotime($s['ended_at'])) ?>
                                <?php else: ?>
                                    <span class="badge badge-success" style="font-size: 11px;">
                                        <i class="fa-solid fa-spinner fa-spin mr-1"></i> Active Open
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Opening Float -->
                            <td style="text-align: right; font-family: monospace;">
                                <div style="font-weight: 600;">$<?= number_format((float)$s['opening_cash_usd'], 2) ?></div>
                                <?php if ((int)$s['opening_cash_khr'] > 0): ?>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= number_format((int)$s['opening_cash_khr']) ?> R</div>
                                <?php endif; ?>
                            </td>

                            <!-- Expected Cash -->
                            <td style="text-align: right; font-family: monospace;">
                                <?php if ($s['expected_cash_usd'] !== null): ?>
                                    <div style="font-weight: 600;">$<?= number_format((float)$s['expected_cash_usd'], 2) ?></div>
                                    <?php if ((int)$s['expected_cash_khr'] > 0): ?>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= number_format((int)$s['expected_cash_khr']) ?> R</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Counted Cash -->
                            <td style="text-align: right; font-family: monospace;">
                                <?php if ($s['counted_cash_usd'] !== null): ?>
                                    <div style="font-weight: 700;">$<?= number_format((float)$s['counted_cash_usd'], 2) ?></div>
                                    <?php if ((int)$s['counted_cash_khr'] > 0): ?>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= number_format((int)$s['counted_cash_khr']) ?> R</div>
                                    <?php endif; ?>
                                <?php elseif ($isOpen): ?>
                                    <span style="color: var(--text-muted); font-size: 11px;">(In progress)</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #fee2e2; color: #dc2626; font-size: 11px; font-weight: 700;">
                                        <?= htmlspecialchars(__('uncounted', 'Not Counted')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Difference (Over / Short) -->
                            <td style="text-align: right; font-family: monospace;">
                                <?php if ($isOpen): ?>
                                    <span style="color: var(--text-muted);">&mdash;</span>
                                <?php elseif ($s['counted_cash_usd'] === null): ?>
                                    <span style="color: var(--text-muted);">&mdash;</span>
                                <?php elseif ($diffUsd < -0.01): ?>
                                    <span class="badge" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; font-size: 12px; font-weight: 800;">
                                        -$<?= number_format(abs($diffUsd), 2) ?>
                                    </span>
                                <?php elseif ($diffUsd > 0.01): ?>
                                    <span class="badge" style="background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; font-size: 12px; font-weight: 800;">
                                        +$<?= number_format($diffUsd, 2) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 12px;">
                                        $0.00
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Shortage Owed -->
                            <td style="text-align: right; font-family: monospace;">
                                <?php if ($shortageOwed > 0.00): ?>
                                    <span style="font-weight: 800; color: #dc2626;">
                                        $<?= number_format($shortageOwed, 2) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">$0.00</span>
                                <?php endif; ?>
                            </td>

                            <!-- Status Badge -->
                            <td style="text-align: center;">
                                <?php if ($isOpen): ?>
                                    <span class="badge badge-success" style="font-size: 11px; padding: 4px 8px;">
                                        <i class="fa-solid fa-lock-open mr-1"></i> Open
                                    </span>
                                <?php elseif ($isForce): ?>
                                    <span class="badge" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; font-size: 11px; padding: 4px 8px; font-weight: 700;">
                                        <i class="fa-solid fa-gavel mr-1"></i> Force Ended
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-secondary" style="font-size: 11px; padding: 4px 8px;">
                                        <i class="fa-solid fa-check mr-1"></i> Closed
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Needs Review Badge -->
                            <td style="text-align: center;">
                                <?php if ($isReview): ?>
                                    <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 800; font-size: 11px; padding: 4px 8px; display: inline-flex; align-items: center; gap: 4px;" title="<?= htmlspecialchars(implode(', ', $reviewReasons)) ?>">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        <?= htmlspecialchars(__('needs_review_badge', 'Needs Review')) ?>
                                    </span>
                                    <?php if (!empty($reviewReasons)): ?>
                                    <div style="font-size: 10px; color: #92400e; margin-top: 3px; max-width: 140px; margin-left: auto; margin-right: auto; line-height: 1.2;">
                                        <?= htmlspecialchars($reviewReasons[0]) ?>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #cbd5e1;">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Slips & Actions -->
                            <td style="text-align: center; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <!-- Open Float Slip Button -->
                                    <button type="button" 
                                            class="btn btn-xs btn-secondary" 
                                            style="padding: 3px 8px; font-size: 11px;"
                                            onclick="openSlipModal('open', <?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['shift_uid'])) ?>')">
                                        <i class="fa-solid fa-receipt mr-1 text-primary"></i> <?= htmlspecialchars(__('view_open_slip', 'Open Slip')) ?>
                                    </button>

                                    <!-- End Shift Slip Button -->
                                    <?php if (!$isOpen): ?>
                                    <button type="button" 
                                            class="btn btn-xs btn-primary" 
                                            style="padding: 3px 8px; font-size: 11px; background: #2563eb; border-color: #2563eb;"
                                            onclick="openSlipModal('end', <?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['shift_uid'])) ?>')">
                                        <i class="fa-solid fa-file-invoice-dollar mr-1"></i> <?= htmlspecialchars(__('view_end_slip', 'End Slip')) ?>
                                    </button>
                                    <?php else: ?>
                                    <button type="button" 
                                            class="btn btn-xs btn-secondary" 
                                            style="padding: 3px 8px; font-size: 11px; opacity: 0.5; cursor: not-allowed;" 
                                            title="Shift is still open" 
                                            disabled>
                                        <i class="fa-solid fa-file-invoice-dollar mr-1"></i> End Slip
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION CONTROLS -->
        <?php if ($totalPages > 1): ?>
        <div style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: var(--bg-subtle);">
            <div style="font-size: 13px; color: var(--text-muted);">
                <?= sprintf(htmlspecialchars(__('showing_shifts_paged', 'Showing %d to %d of %d shifts')), ($totalShifts > 0 ? $offset + 1 : 0), min($offset + $limit, $totalShifts), $totalShifts) ?>
            </div>

            <div style="display: flex; gap: 4px; align-items: center;">
                <!-- First Page -->
                <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(buildSessionsUrl(['page' => 1])) ?>" class="btn btn-xs btn-secondary" title="First Page">
                    <i class="fa-solid fa-angles-left"></i>
                </a>
                <a href="<?= htmlspecialchars(buildSessionsUrl(['page' => $page - 1])) ?>" class="btn btn-xs btn-secondary" title="Previous Page">
                    <i class="fa-solid fa-angle-left mr-1"></i> Prev
                </a>
                <?php endif; ?>

                <!-- Page Numbers Window -->
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($p = $startPage; $p <= $endPage; $p++):
                    $isCurrent = ($p === $page);
                ?>
                <a href="<?= htmlspecialchars(buildSessionsUrl(['page' => $p])) ?>" 
                   class="btn btn-xs <?= $isCurrent ? 'btn-primary' : 'btn-secondary' ?>" 
                   style="min-width: 32px; font-weight: <?= $isCurrent ? '700' : 'normal' ?>;">
                    <?= $p ?>
                </a>
                <?php endfor; ?>

                <!-- Next & Last Page -->
                <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(buildSessionsUrl(['page' => $page + 1])) ?>" class="btn btn-xs btn-secondary" title="Next Page">
                    Next <i class="fa-solid fa-angle-right ml-1"></i>
                </a>
                <a href="<?= htmlspecialchars(buildSessionsUrl(['page' => $totalPages])) ?>" class="btn btn-xs btn-secondary" title="Last Page">
                    <i class="fa-solid fa-angles-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: VIEW & PRINT SHIFT SLIP -->
<div id="slipModal" style="display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); align-items: center; justify-content: center; padding: 16px;">
    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); width: 100%; max-width: 480px; box-shadow: var(--shadow-xl); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
        <!-- Modal Header -->
        <div style="padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-subtle);">
            <div style="font-weight: 700; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-receipt text-primary"></i>
                <span id="slipModalTitle"><?= htmlspecialchars(__('slip_modal_title', 'Shift Receipt Slip')) ?></span>
            </div>
            <button type="button" onclick="closeSlipModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <!-- Modal Body: Iframe for Thermal Receipt Rendering -->
        <div style="flex: 1; padding: 12px; background: #e2e8f0; overflow: hidden; display: flex; justify-content: center;">
            <iframe id="slipFrame" src="about:blank" style="width: 100%; height: 500px; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); background: #ffffff;"></iframe>
        </div>

        <!-- Modal Footer -->
        <div style="padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-subtle);">
            <a id="slipOpenTabBtn" href="#" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> <?= htmlspecialchars(__('open_in_new_tab', 'Open Full Slip')) ?>
            </a>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeSlipModal()">
                    Close
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="printSlipIframe()">
                    <i class="fa-solid fa-print mr-1"></i> <?= htmlspecialchars(__('print_slip', 'Print Slip')) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openSlipModal(type, shiftId, shiftUid) {
    const modal = document.getElementById('slipModal');
    const iframe = document.getElementById('slipFrame');
    const title = document.getElementById('slipModalTitle');
    const openTabBtn = document.getElementById('slipOpenTabBtn');

    const slipUrl = `views/cashier/slip.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(shiftId)}`;
    
    if (type === 'open') {
        title.innerHTML = `<i class="fa-solid fa-receipt text-primary mr-1"></i> Open Float Slip &mdash; ${shiftUid || '#' + shiftId}`;
    } else {
        title.innerHTML = `<i class="fa-solid fa-file-invoice-dollar text-primary mr-1"></i> End Shift Slip &mdash; ${shiftUid || '#' + shiftId}`;
    }

    iframe.src = slipUrl;
    openTabBtn.href = slipUrl;
    modal.style.display = 'flex';
}

function closeSlipModal() {
    const modal = document.getElementById('slipModal');
    const iframe = document.getElementById('slipFrame');
    modal.style.display = 'none';
    iframe.src = 'about:blank';
}

function printSlipIframe() {
    const iframe = document.getElementById('slipFrame');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

// Close modal on Escape
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSlipModal();
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/views/layouts/footer.php'; ?>
