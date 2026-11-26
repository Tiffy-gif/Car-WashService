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
$allowedLimits = [8, 15, 25, 50];
$limit = (int)($_GET['limit'] ?? 8);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 8;
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
        COALESCE(u.full_name, cs.cashier_name, CONCAT('Cashier #', cs.cashier_id)) AS cashier_name,
        u.username AS cashier_username,
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
    LEFT JOIN users u ON cs.cashier_id = u.id
    {$baseWhere}
    ORDER BY cs.started_at DESC, cs.id DESC
    LIMIT {$limit} OFFSET {$offset}
";
$shiftStmt = $pdo->prepare($shiftsSql);
$shiftStmt->execute($baseParams);
$shifts = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Compute Summary per Cashier across Current Filter Scope
$cashierSummarySql = "
    SELECT 
        cs.cashier_id,
        COALESCE(u.full_name, cs.cashier_name, CONCAT('Cashier #', cs.cashier_id)) AS cashier_name,
        u.username,
        COUNT(cs.id) AS total_shifts,
        SUM(CASE WHEN cs.total_difference_usd < 0 THEN ABS(cs.total_difference_usd) ELSE 0 END) AS total_shortage_usd,
        SUM(CASE WHEN cs.total_difference_usd > 0 THEN cs.total_difference_usd ELSE 0 END) AS total_surplus_usd,
        SUM(CASE WHEN {$needsReviewCondition} THEN 1 ELSE 0 END) AS needs_review_count,
        SUM(CASE WHEN cs.status = 'open' THEN 1 ELSE 0 END) AS active_open_shifts
    FROM cashier_shifts cs
    LEFT JOIN users u ON cs.cashier_id = u.id
    {$baseWhere}
    GROUP BY cs.cashier_id, cashier_name, u.username
    ORDER BY total_shortage_usd DESC, total_shifts DESC
";
$csStmt = $pdo->prepare($cashierSummarySql);
$csStmt->execute($baseParams);
$cashierSummary = $csStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 10px; margin: 0;">
                <i class="fa-solid fa-user-clock" style="color: #2563eb;"></i>
                <?= htmlspecialchars(__('cashier_sessions_title', 'Cashier Sessions & Shift Audits')) ?>
            </h1>
            <p style="font-size: 13.5px; color: var(--text-muted); margin: 4px 0 0 0;">
                <?= htmlspecialchars(__('cashier_sessions_desc', 'Audit trail of cashier shifts, opening floats, counted cash, over/short balances and review flags.')) ?>
            </p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="?view=shift" class="btn btn-secondary">
                <i class="fa-solid fa-cash-register mr-1"></i> <?= htmlspecialchars(__('nav_shifts', 'Active Shifts')) ?>
            </a>
            <a href="?view=cash_movements" class="btn btn-secondary">
                <i class="fa-solid fa-money-bill-transfer mr-1" style="color: #ea580c;"></i> <?= htmlspecialchars(__('nav_cash_movements', 'Cash Movements')) ?>
            </a>
        </div>
    </div>

    <!-- 4 OVERALL KPI CARDS (AT TOP) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <!-- Total Shifts -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid #2563eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Total Shifts</span>
                <div style="width: 36px; height: 36px; border-radius: 50%; background: #dbeafe; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
            </div>
            <div style="font-size: 24px; font-weight: 800; color: #2563eb; margin-top: 6px;">
                <?= number_format($totalShifts) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 2px;">
                Across filter scope
            </div>
        </div>

        <!-- Total Shortage -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid #dc2626;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Total Shortage</span>
                <div style="width: 36px; height: 36px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-arrow-down-long"></i>
                </div>
            </div>
            <div style="font-size: 24px; font-weight: 800; color: #dc2626; margin-top: 6px;">
                $<?= number_format($totalShortageUsd, 2) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 2px;">
                Shortage recorded / owed
            </div>
        </div>

        <!-- Total Surplus -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid #16a34a;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Total Surplus</span>
                <div style="width: 36px; height: 36px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-arrow-up-long"></i>
                </div>
            </div>
            <div style="font-size: 24px; font-weight: 800; color: #16a34a; margin-top: 6px;">
                +$<?= number_format($totalSurplusUsd, 2) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 2px;">
                Over counted in drawer
            </div>
        </div>

        <!-- Shifts Needing Review -->
        <div class="card" style="padding: 18px 20px; border-left: 4px solid <?= $totalNeedsReview > 0 ? '#9333ea' : '#64748b' ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Shifts Needing Review</span>
                <div style="width: 36px; height: 36px; border-radius: 50%; background: <?= $totalNeedsReview > 0 ? '#f3e8ff' : '#f1f5f9' ?>; color: <?= $totalNeedsReview > 0 ? '#9333ea' : '#64748b' ?>; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
            </div>
            <div style="font-size: 24px; font-weight: 800; color: <?= $totalNeedsReview > 0 ? '#9333ea' : 'var(--text-main)' ?>; margin-top: 6px;">
                <?= number_format($totalNeedsReview) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 2px;">
                Forced, uncounted, overnight
            </div>
        </div>
    </div>

    <!-- UNIFIED FILTER TOOLBAR -->
    <div class="card" style="margin-bottom: 24px; padding: 16px;">
        <form action="" method="GET" style="display: flex; flex-direction: column; gap: 14px;">
            <input type="hidden" name="view" value="cashier_sessions">

            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <!-- Period Custom Date Range (From / To) -->
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <input type="hidden" name="period" value="custom">
                    <div style="display: flex; align-items: center; gap: 6px; background: var(--bg-subtle); padding: 4px 10px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                        <span class="text-xs text-muted">From:</span>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input" style="padding: 2px 6px; font-size: 12px; height: 28px; border: none; background: transparent;">
                        <span class="text-xs text-muted">To:</span>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-input" style="padding: 2px 6px; font-size: 12px; height: 28px; border: none; background: transparent;">
                    </div>
                </div>

                <!-- CSV Export Button on the Right (Orange #ea580c like screenshot) -->
                <?php if (Auth::can('reports.export')): ?>
                <a href="<?= htmlspecialchars(buildSessionsUrl(['export' => 'csv'])) ?>" class="btn btn-primary" style="background: #ea580c; border-color: #ea580c; font-weight: 700; height: 36px; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-file-csv"></i> Cashier Sessions (CSV)
                </a>
                <?php endif; ?>
            </div>

            <!-- Dropdown Filters Row -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) 120px; gap: 12px; align-items: flex-end; padding-top: 12px; border-top: 1px solid var(--border);">
                <!-- Cashier Filter -->
                <div>
                    <label class="form-label text-xs" style="margin-bottom: 4px;"><?= htmlspecialchars(__('filter_cashier', 'Cashier')) ?></label>
                    <select name="cashier_id" class="form-input" style="padding: 6px 10px; font-size: 13px;">
                        <option value="all" <?= ($selectedCashier === 'all') ? 'selected' : '' ?>>-- <?= htmlspecialchars(__('all_cashiers', 'All Cashiers')) ?> --</option>
                        <?php foreach ($allCashiers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ((string)$selectedCashier === (string)$c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['full_name'] ?: $c['username']) ?> (@<?= htmlspecialchars($c['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Shift Status Filter -->
                <div>
                    <label class="form-label text-xs" style="margin-bottom: 4px;"><?= htmlspecialchars(__('filter_status', 'Shift Status')) ?></label>
                    <select name="status" class="form-input" style="padding: 6px 10px; font-size: 13px;">
                        <option value="all" <?= ($selectedStatus === 'all') ? 'selected' : '' ?>><?= htmlspecialchars(__('all_statuses', 'All Statuses')) ?></option>
                        <option value="open" <?= ($selectedStatus === 'open') ? 'selected' : '' ?>><?= htmlspecialchars(__('status_open', 'Active Open')) ?></option>
                        <option value="closed" <?= ($selectedStatus === 'closed') ? 'selected' : '' ?>><?= htmlspecialchars(__('status_closed', 'Closed')) ?></option>
                        <option value="force_closed" <?= ($selectedStatus === 'force_closed') ? 'selected' : '' ?>><?= htmlspecialchars(__('status_force_closed', 'Force Ended')) ?></option>
                    </select>
                </div>

                <!-- Review Filter -->
                <div>
                    <label class="form-label text-xs" style="margin-bottom: 4px;">Audit Filter</label>
                    <select name="needs_review" class="form-input" style="padding: 6px 10px; font-size: 13px;">
                        <option value="0" <?= !$needsReviewOnly ? 'selected' : '' ?>>All Shifts</option>
                        <option value="1" <?= $needsReviewOnly ? 'selected' : '' ?>>Needs Review Only (Flagged)</option>
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

    <!-- CASHIER SUMMARY & ANOMALY HIGHLIGHTING -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 class="card-title" style="font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-user-shield" style="color: #2563eb;"></i>
                    <?= htmlspecialchars(__('cashier_summary', 'Summary per Cashier')) ?>
                </h2>
                <p class="text-xs text-muted" style="margin: 2px 0 0 0;">
                    Breakdown of shift counts, shortages, surplus, and review flags per cashier across the selected period.
                </p>
            </div>
            <?php if ($totalShifts > 0): ?>
                <span class="text-xs text-muted" style="background: var(--bg-subtle); padding: 4px 10px; border-radius: 99px;">
                    Total Shifts Audited: <strong><?= number_format($totalShifts) ?></strong>
                </span>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table js-server-paginated">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Cashier</th>
                        <th class="table-num" style="min-width: 140px;">Total Shifts</th>
                        <th style="min-width: 220px;">Risk / Anomaly Status</th>
                        <th class="table-num" style="min-width: 150px;">Total Shortage</th>
                        <th class="table-num" style="min-width: 150px;">Total Surplus</th>
                        <th class="table-num" style="min-width: 120px;">Active Shifts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cashierSummary)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                No cashier shift activity recorded in this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cashierSummary as $cs): 
                            $hasReview = (int)$cs['needs_review_count'] > 0;
                            $shortUsd = (float)$cs['total_shortage_usd'];
                            $surpUsd = (float)$cs['total_surplus_usd'];
                        ?>
                            <tr style="<?= ($hasReview || $shortUsd > 10.00) ? 'background: rgba(220, 38, 38, 0.04);' : '' ?>">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: <?= ($hasReview || $shortUsd > 0) ? '#fee2e2' : 'var(--bg-subtle)' ?>; color: <?= ($hasReview || $shortUsd > 0) ? '#dc2626' : 'var(--text-main)' ?>; display: flex; align-items: center; justify-content: center; font-size: 13px;">
                                            <i class="fa-solid <?= ($hasReview || $shortUsd > 0) ? 'fa-user-ninja' : 'fa-user' ?>"></i>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($cs['cashier_name']) ?></strong>
                                            <?php if (!empty($cs['username'])): ?>
                                                <div class="text-xs text-muted">@<?= htmlspecialchars($cs['username']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="table-num">
                                    <strong style="font-size: 15px;"><?= (int)$cs['total_shifts'] ?></strong>
                                </td>
                                <td>
                                    <?php if ($hasReview): ?>
                                        <span class="badge badge-danger" style="background: #fef2f2; color: #dc2626; border: 1px solid #f87171; font-weight: 700; padding: 4px 10px; display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            <?= (int)$cs['needs_review_count'] ?> Shift(s) Needing Review
                                        </span>
                                    <?php elseif ($shortUsd > 0.01): ?>
                                        <span class="badge" style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-weight: 700; padding: 4px 10px;">
                                            Shortage Recorded
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="font-size: 11px;">Normal Activity</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-num">
                                    <strong style="color: #dc2626;"><?= $shortUsd > 0 ? ('-$' . number_format($shortUsd, 2)) : '$0.00' ?></strong>
                                </td>
                                <td class="table-num">
                                    <strong style="color: #16a34a;"><?= $surpUsd > 0 ? ('+$' . number_format($surpUsd, 2)) : '$0.00' ?></strong>
                                </td>
                                <td class="table-num">
                                    <strong><?= (int)$cs['active_open_shifts'] ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- SHIFT-GROUPED ACCORDION CASHIER SESSIONS                           -->
    <!-- ================================================================= -->
    <div class="card" style="margin-bottom: 16px; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg);">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span class="badge" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; font-weight: 700; padding: 5px 12px; border-radius: 9999px; font-size: 12px; border: 1px solid rgba(37, 99, 235, 0.2);">
                <i class="fa-solid fa-layer-group mr-1"></i> <?= count($shifts) ?> Shift<?= count($shifts) !== 1 ? 's' : '' ?> on this page • <?= number_format($totalShifts) ?> total
            </span>
            <h2 class="card-title" style="font-size: 17px; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-table-list text-primary"></i>
                <?= htmlspecialchars(__('shift_grouped_cashier_sessions', 'Shift-Grouped Cashier Sessions')) ?>
            </h2>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <button type="button" onclick="expandAllShifts(true)" class="btn btn-secondary btn-sm" style="font-size: 12px; font-weight: 600;">
                <i class="fa-solid fa-angles-down mr-1"></i> <?= htmlspecialchars(__('expand_all', 'Expand All Shifts')) ?>
            </button>
            <button type="button" onclick="expandAllShifts(false)" class="btn btn-secondary btn-sm" style="font-size: 12px; font-weight: 600;">
                <i class="fa-solid fa-angles-up mr-1"></i> <?= htmlspecialchars(__('collapse_all', 'Collapse All')) ?>
            </button>

            <!-- Page Selector Jump -->
            <?php if ($totalPages > 1): ?>
            <div style="display: flex; gap: 6px; align-items: center; margin-left: 8px;">
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
    </div>

    <?php if (empty($shifts)): ?>
    <div class="card" style="padding: 48px; text-align: center; color: var(--text-muted); background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); margin-bottom: 24px;">
        <i class="fa-solid fa-inbox" style="font-size: 32px; opacity: 0.3; margin-bottom: 12px; display: block;"></i>
        <div style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
            <?= htmlspecialchars(__('no_shifts_found', 'No cashier shifts found matching the selected filters.')) ?>
        </div>
        <p style="font-size: 13px; margin: 0;">Try adjusting your period, cashier, or status filters above.</p>
    </div>
    <?php else: ?>
    <div id="sessionsAccordionContainer" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
        <?php 
        $shiftIndex = 0;
        foreach ($shifts as $s): 
            $shiftIndex++;
            $isDefaultOpen = ($shiftIndex <= 2); // First 2 shifts open by default
            $sKey = 'shift_' . ($s['shift_uid'] ? preg_replace('/[^a-zA-Z0-9_-]/', '', $s['shift_uid']) : (string)$s['id']);
            $isReview = (int)$s['computed_needs_review'] === 1;
            $status = $s['status'] ?? 'closed';
            $isOpen = ($status === 'open');
            $isForce = ($status === 'force_closed' || stripos($s['notes'] ?? '', 'force') !== false);
            
            // Difference calculations
            $diffUsd = (float)($s['total_difference_usd'] ?? $s['difference_usd'] ?? 0.00);
            $diffKhr = (int)($s['difference_khr'] ?? 0);
            $shortageOwed = (float)($s['shortage_owed_usd'] ?? 0.00);

            // Duration calculation
            $durationStr = '';
            if (!empty($s['started_at'])) {
                $endTs = !empty($s['ended_at']) ? strtotime($s['ended_at']) : time();
                $startTs = strtotime($s['started_at']);
                $diffHours = round(($endTs - $startTs) / 3600, 1);
                $durationStr = $diffHours . ' hrs';
            }

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

            // Net Difference color & display
            $diffColor = 'var(--text-muted)';
            $diffText = '$0.00 Balanced';
            $diffSubText = '';
            if ($isOpen) {
                $diffColor = '#2563eb';
                $diffText = 'In Progress';
                $diffSubText = 'Shift is active';
            } elseif ($s['counted_cash_usd'] === null) {
                $diffColor = '#dc2626';
                $diffText = 'Not Counted';
                $diffSubText = 'Drawer uncounted';
            } elseif ($diffUsd < -0.01) {
                $diffColor = '#dc2626';
                $diffText = '-$' . number_format(abs($diffUsd), 2) . ' Short';
                if ($diffKhr !== 0) {
                    $diffSubText = ($diffKhr < 0 ? '-' : '+') . number_format(abs($diffKhr)) . ' ៛';
                }
            } elseif ($diffUsd > 0.01) {
                $diffColor = '#16a34a';
                $diffText = '+$' . number_format($diffUsd, 2) . ' Over';
                if ($diffKhr !== 0) {
                    $diffSubText = ($diffKhr > 0 ? '+' : '-') . number_format(abs($diffKhr)) . ' ៛';
                }
            } else {
                $diffColor = 'var(--text-muted)';
                $diffText = '$0.00 Balanced';
                $diffSubText = 'Exact match';
            }

            $shiftUidDisplay = $s['shift_uid'] ?: 'CS-' . str_pad((string)$s['id'], 5, '0', STR_PAD_LEFT);
        ?>
        <div class="shift-accordion-card card" style="padding: 0; overflow: hidden; border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); background: var(--bg-card);">
            <!-- Collapsible Shift Header -->
            <button type="button" class="shift-accordion-header" onclick="toggleShiftAccordion('<?= htmlspecialchars($sKey) ?>')" style="width: 100%; padding: 14px 18px; background: var(--bg-surface); border: none; cursor: pointer; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; text-align: left; transition: background 0.15s ease;" onmouseover="this.style.background='var(--bg-subtle)';" onmouseout="this.style.background='var(--bg-surface)';">
                
                <!-- Left Details -->
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <i id="chevron_<?= htmlspecialchars($sKey) ?>" class="fa-solid fa-chevron-right shift-chevron" style="color: var(--text-muted); font-size: 12px; transition: transform 0.2s ease; transform: <?= $isDefaultOpen ? 'rotate(90deg)' : 'rotate(0deg)' ?>;"></i>
                    
                    <!-- Shift UID Badge + 1-Click Copy -->
                    <span style="font-family: var(--font-mono); font-size: 12.5px; font-weight: 800; background: rgba(2, 132, 199, 0.08); color: var(--primary); padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(2, 132, 199, 0.25); display: inline-flex; align-items: center; gap: 6px;">
                        <?= htmlspecialchars($shiftUidDisplay) ?>
                        <span onclick="event.stopPropagation(); copyToClipboard('<?= htmlspecialchars(addslashes($shiftUidDisplay)) ?>', 'Shift ID')" title="Copy Shift ID" style="cursor: pointer; opacity: 0.7;">
                            <i class="fa-regular fa-copy" style="font-size: 10px;"></i>
                        </span>
                    </span>

                    <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">
                        <?= date('d M Y, h:i A', strtotime($s['started_at'])) ?>
                    </span>

                    <span class="badge badge-secondary" style="font-size: 11px; padding: 3px 8px; font-weight: 600;">
                        <?= $durationStr ?: 'Active' ?>
                    </span>

                    <!-- Status Badge -->
                    <?php if ($isOpen): ?>
                        <span class="badge badge-success" style="font-size: 11px; padding: 3px 8px; font-weight: 700;">
                            <i class="fa-solid fa-spinner fa-spin mr-1"></i> Active Open
                        </span>
                    <?php elseif ($isForce): ?>
                        <span class="badge" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; font-size: 11px; padding: 3px 8px; font-weight: 700;">
                            <i class="fa-solid fa-gavel mr-1"></i> Force Ended
                        </span>
                    <?php endif; ?>

                    <!-- Audit Review Flag Badge -->
                    <?php if ($isReview): ?>
                        <span class="badge" style="background: #fdf4ff; color: #9333ea; border: 1px solid #f0abfc; font-size: 11px; padding: 2px 8px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;" title="<?= htmlspecialchars(implode(', ', $reviewReasons)) ?>">
                            <i class="fa-solid fa-triangle-exclamation" style="font-size: 10px;"></i>
                            <?= htmlspecialchars($reviewReasons[0] ?? 'Needs Review') ?>
                        </span>
                    <?php elseif (!$isOpen && abs($diffUsd) <= 0.01): ?>
                        <span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; padding: 3px 8px; font-weight: 700;">
                            <i class="fa-solid fa-check-double mr-1"></i> Balanced
                        </span>
                    <?php endif; ?>

                    <span style="font-size: 12.5px; color: var(--text-muted);">
                        Cashier: <strong style="color: var(--text-main);"><?= htmlspecialchars($s['cashier_name']) ?></strong>
                        <?php if (!empty($s['cashier_username'])): ?>
                            <span style="color: var(--text-muted); font-weight: normal;">(@<?= htmlspecialchars($s['cashier_username']) ?>)</span>
                        <?php endif; ?>
                    </span>

                    <span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 11px; padding: 2px 7px;">
                        <i class="fa-solid fa-desktop mr-1"></i> <?= htmlspecialchars($s['pos_device_name'] ?: 'POS Terminal') ?>
                    </span>
                </div>

                <!-- Right side: Shift Over / Short -->
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="text-align: right;">
                        <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Shift Over / Short</div>
                        <div style="font-size: 14px; font-weight: 800; color: <?= $diffColor ?>; font-family: var(--font-mono);">
                            <?= $diffText ?>
                            <?php if ($diffSubText): ?>
                                <span style="font-size: 11px; font-weight: 500; color: var(--text-muted);">(<?= $diffSubText ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($shortageOwed > 0.00): ?>
                    <div style="background: #fee2e2; border: 1px solid #fca5a5; padding: 4px 10px; border-radius: var(--radius-sm); text-align: center;">
                        <div style="font-size: 9px; font-weight: 800; text-transform: uppercase; color: #991b1b;">Owed</div>
                        <div style="font-size: 12px; font-weight: 800; color: #dc2626; font-family: var(--font-mono);">$<?= number_format($shortageOwed, 2) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </button>

            <!-- Collapsible Shift Body: 3-Column Clean Audit Dossier -->
            <div id="body_<?= htmlspecialchars($sKey) ?>" class="shift-accordion-body" style="display: <?= $isDefaultOpen ? 'block' : 'none' ?>; border-top: 1px solid var(--border); padding: 18px 20px; background: var(--bg-card);">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px;">
                    
                    <!-- BOX 1: Cash in Drawer (Opening Float vs Counted Cash) -->
                    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px;">
                        <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-wallet text-primary"></i>
                            1. Drawer Cash Balances
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 8px; border-bottom: 1px dashed var(--border); margin-bottom: 8px;">
                            <span style="font-size: 13px; color: var(--text-muted);">Opening Float:</span>
                            <div style="text-align: right; font-family: var(--font-mono);">
                                <strong style="font-size: 14px; color: var(--text-main);">$<?= number_format((float)$s['opening_cash_usd'], 2) ?></strong>
                                <?php if ((int)$s['opening_cash_khr'] > 0): ?>
                                <span style="font-size: 12px; color: var(--text-muted); margin-left: 4px;">(<?= number_format((int)$s['opening_cash_khr']) ?> ៛)</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 8px; border-bottom: 1px dashed var(--border); margin-bottom: 8px;">
                            <span style="font-size: 13px; color: var(--text-muted);">Final Counted Cash:</span>
                            <div style="text-align: right; font-family: var(--font-mono);">
                                <?php if ($s['counted_cash_usd'] !== null): ?>
                                    <strong style="font-size: 14px; color: #16a34a;">$<?= number_format((float)$s['counted_cash_usd'], 2) ?></strong>
                                    <?php if ((int)$s['counted_cash_khr'] > 0): ?>
                                    <span style="font-size: 12px; color: var(--text-muted); margin-left: 4px;">(<?= number_format((int)$s['counted_cash_khr']) ?> ៛)</span>
                                    <?php endif; ?>
                                <?php elseif ($isOpen): ?>
                                    <span style="color: var(--text-muted); font-size: 12px;">(Shift active &bull; in progress)</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #fee2e2; color: #dc2626; font-size: 11px; font-weight: 700;">Uncounted</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-muted);">
                            <span>Exchange Rate:</span>
                            <span style="font-family: var(--font-mono);">1 USD = <?= number_format((float)($s['exchange_rate'] ?: $curRate)) ?> ៛</span>
                        </div>
                    </div>

                    <!-- BOX 2: System Reconciliation (Expected vs Actual) -->
                    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px;">
                        <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-calculator text-primary"></i>
                            2. Reconciliation &amp; Variance
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 8px; border-bottom: 1px dashed var(--border); margin-bottom: 8px;">
                            <span style="font-size: 13px; color: var(--text-muted);">System Expected Cash:</span>
                            <div style="text-align: right; font-family: var(--font-mono);">
                                <?php if ($s['expected_cash_usd'] !== null): ?>
                                    <strong style="font-size: 14px; color: var(--text-main);">$<?= number_format((float)$s['expected_cash_usd'], 2) ?></strong>
                                    <?php if ((int)$s['expected_cash_khr'] > 0): ?>
                                    <span style="font-size: 12px; color: var(--text-muted); margin-left: 4px;">(<?= number_format((int)$s['expected_cash_khr']) ?> ៛)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">&mdash;</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 8px; border-bottom: 1px dashed var(--border); margin-bottom: 8px;">
                            <span style="font-size: 13px; color: var(--text-muted);">Net Difference:</span>
                            <div style="text-align: right; font-family: var(--font-mono);">
                                <strong style="font-size: 14px; color: <?= $diffColor ?>;">
                                    <?= $diffText ?>
                                </strong>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
                            <span style="color: var(--text-muted);">Shortage Liability:</span>
                            <span style="font-weight: 700; color: <?= $shortageOwed > 0 ? '#dc2626' : 'var(--text-muted)' ?>; font-family: var(--font-mono);">
                                $<?= number_format($shortageOwed, 2) ?> <?= $shortageOwed > 0 ? 'Owed' : '(Clear)' ?>
                            </span>
                        </div>
                    </div>

                    <!-- BOX 3: Handover, Audit Notes & One-Click Actions -->
                    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-clipboard-check text-primary"></i>
                                3. Handover &amp; Audit Actions
                            </div>

                            <?php if (!empty($s['handover_to_cashier_name'])): ?>
                            <div style="font-size: 12.5px; margin-bottom: 6px; color: var(--text-main);">
                                <i class="fa-solid fa-arrow-right mr-1" style="color: #2563eb;"></i>
                                Handed over to: <strong><?= htmlspecialchars($s['handover_to_cashier_name']) ?></strong>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($s['notes']) || !empty($s['review_reason'])): ?>
                            <div style="font-size: 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 10px; margin-bottom: 10px; color: var(--text-muted); line-height: 1.4;">
                                <?php if (!empty($s['review_reason'])): ?>
                                    <div style="color: #b45309; font-weight: 700; margin-bottom: 2px;">
                                        <i class="fa-solid fa-triangle-exclamation mr-1"></i> Review: <?= htmlspecialchars($s['review_reason']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($s['notes'])): ?>
                                    <div><?= htmlspecialchars($s['notes']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding-top: 8px; border-top: 1px solid var(--border);">
                            <!-- Open Float Slip Button -->
                            <button type="button" 
                                    class="btn btn-sm btn-secondary" 
                                    style="padding: 5px 12px; font-size: 12px; font-weight: 600;"
                                    onclick="openSlipModal('open', <?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($shiftUidDisplay)) ?>')">
                                <i class="fa-solid fa-receipt mr-1 text-primary"></i> <?= htmlspecialchars(__('view_open_slip', 'Open Slip')) ?>
                            </button>

                            <!-- End Shift Slip Button -->
                            <?php if (!$isOpen): ?>
                            <button type="button" 
                                    class="btn btn-sm btn-primary" 
                                    style="padding: 5px 12px; font-size: 12px; font-weight: 600; background: #2563eb; border-color: #2563eb;"
                                    onclick="openSlipModal('end', <?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($shiftUidDisplay)) ?>')">
                                <i class="fa-solid fa-file-invoice-dollar mr-1"></i> <?= htmlspecialchars(__('view_end_slip', 'End Slip')) ?>
                            </button>
                            <?php endif; ?>

                            <!-- Cash Movements Jump -->
                            <a href="?view=cash_movements" class="btn btn-sm btn-secondary" style="padding: 5px 12px; font-size: 12px; font-weight: 600; color: #ea580c;" title="View all cash drawer movements">
                                <i class="fa-solid fa-money-bill-transfer mr-1"></i> Drawer Events
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- PAGINATION CONTROLS -->
    <?php if ($totalPages > 1): ?>
    <div class="card" style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); margin-bottom: 24px;">
        <div style="font-size: 13px; color: var(--text-muted);">
            <?= sprintf(htmlspecialchars(__('showing_shifts_paged', 'Showing %d to %d of %d shifts')), ($totalShifts > 0 ? $offset + 1 : 0), min($offset + $limit, $totalShifts), $totalShifts) ?>
        </div>

        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <label style="font-size:13px;color:var(--text-muted);">Rows
                <select aria-label="Rows per page" class="form-input" style="width:auto;min-height:34px;padding:5px 28px 5px 10px;" onchange="window.location.href=this.value">
                    <?php foreach ($allowedLimits as $pageLimit): ?>
                    <option value="<?= htmlspecialchars(buildSessionsUrl(['page' => 1, 'limit' => $pageLimit])) ?>" <?= $limit === $pageLimit ? 'selected' : '' ?>><?= $pageLimit ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
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

<!-- TOAST NOTIFICATION CONTAINER -->
<div id="toastNotification" style="position: fixed; bottom: 24px; right: 24px; background: #0f172a; color: #ffffff; padding: 12px 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-xl); font-size: 13px; font-weight: 600; display: none; align-items: center; gap: 10px; z-index: 99999; border: 1px solid #334155; transition: opacity 0.2s ease;">
    <i class="fa-solid fa-circle-check text-success" style="font-size: 16px; color: #22c55e;"></i>
    <span id="toastMessage">Notification</span>
</div>

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
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        showToast('Copied ' + label + ': ' + text);
    } catch (err) {
        alert('Could not copy to clipboard');
    }
    document.body.removeChild(ta);
}

function showToast(msg) {
    const toast = document.getElementById('toastNotification');
    const toastMsg = document.getElementById('toastMessage');
    if (!toast || !toastMsg) return;
    toastMsg.textContent = msg;
    toast.style.display = 'flex';
    toast.style.opacity = '1';
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => { toast.style.display = 'none'; }, 200);
    }, 2500);
}

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
