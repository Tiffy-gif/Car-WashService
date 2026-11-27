<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/OrderCancellation.php';

Auth::requirePermission('reports.view');
$pageTitle = __('nav_reports', 'Reports');
$pdo = Database::getInstance();
$canViewProfit = Auth::canViewProfit();
$legacySection = strtolower(trim((string)($_GET['section'] ?? '')));
$legacyReportMap = ['financial' => 'financial_summary', 'operations' => 'financial_summary', 'staff_cash' => 'cancelled_orders'];
$reportAliases = ['cashier_shift' => 'cancelled_orders'];
$requestedReport = strtolower(trim((string)($_GET['report'] ?? $legacyReportMap[$legacySection] ?? 'financial_summary')));
$reportKey = $reportAliases[$requestedReport] ?? $requestedReport;
$availableReports = ['financial_summary', 'service_revenue', 'product_sales', 'cancelled_orders'];
if (!in_array($reportKey, $availableReports, true)) {
    $reportKey = 'financial_summary';
}
$excludedExports = ['bay_utilization', 'tech_efficiency', 'bottlenecks', 'timecards'];
if (isset($_GET['export']) && in_array((string)$_GET['export'], $excludedExports, true)) {
    http_response_code(404);
    exit('This report is no longer available.');
}
$reportTitles = [
    'financial_summary' => 'Financial Summary', 'service_revenue' => 'Service Revenue',
    'product_sales' => 'Product Sales', 'cancelled_orders' => 'Cancelled Orders',
];
$reportTitle = $reportTitles[$reportKey];
$reportDescriptions = [
    'financial_summary' => 'Review revenue, payments, costs, and profit for the selected period.',
    'service_revenue' => 'Compare wash packages, vehicle types, and maintenance service revenue.',
    'product_sales' => 'Review products and parts sold during the selected period.',
    'cancelled_orders' => 'Review cancelled transactions and the revenue they affected.',
];
$pageTitle = $reportTitle;

// Period Filter
$period = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Reports are site-wide because this business currently has one checkout station.

// Build inclusive calendar periods as half-open ranges. Comparing ISO date strings
// works on both MySQL and SQLite and keeps timestamps on the final day included.
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$today = new DateTimeImmutable(date('Y-m-d'));
$periodStart = $today->format('Y-m-d');
$periodEnd = $today->modify('+1 day')->format('Y-m-d');

if (!in_array($period, ['today', 'this_week', 'this_month', 'this_year', 'custom'], true)) {
    $period = 'this_month';
}
if ($period === 'this_week') {
    $periodStart = $today->modify('monday this week')->format('Y-m-d');
    $periodEnd = $today->modify('+1 day')->format('Y-m-d');
} elseif ($period === 'this_month') {
    $periodStart = $today->modify('first day of this month')->format('Y-m-d');
    $periodEnd = $today->modify('+1 day')->format('Y-m-d');
} elseif ($period === 'this_year') {
    $periodStart = $today->format('Y-01-01');
    $periodEnd = $today->modify('+1 day')->format('Y-m-d');
} elseif ($period === 'custom') {
    if (!$validDate((string)$startDate) || !$validDate((string)$endDate)) {
        $period = 'this_month';
        $periodStart = $today->modify('first day of this month')->format('Y-m-d');
        $periodEnd = $today->modify('first day of next month')->format('Y-m-d');
        $startDate = $periodStart;
        $endDate = $today->format('Y-m-d');
    } else {
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        $periodStart = $startDate;
        $periodEnd = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d');
    }
}

if ($period !== 'custom') {
    $startDate = $periodStart;
    $endDate = $today->format('Y-m-d');
}

$orderDateWhere = "AND COALESCE(o.paid_at, o.created_at) >= ? AND COALESCE(o.paid_at, o.created_at) < ?";
$expenseWhere = "AND expense_date >= ? AND expense_date < ?";
$dateParams = [$periodStart, $periodEnd];

$orderWhere = $orderDateWhere;

// Check if refund columns exist (backward compatibility across SQLite and MySQL)
$hasRefundCols = false;
try {
    $pdo->query("SELECT refund_amount_usd, refund_status FROM orders LIMIT 0");
    $hasRefundCols = true;
} catch (Throwable $e) {
    $hasRefundCols = false;
}

// 1. Confirmed Gross Revenue, Subtotal, Discount & Order Count
$revSql = "
    SELECT 
        COUNT(*) as total_vehicles, 
        COALESCE(SUM(o.subtotal_usd), 0) as total_subtotal,
        COALESCE(SUM(o.discount_usd), 0) as total_discount,
        COALESCE(SUM(o.total_usd), 0) as gross_revenue, 
        COALESCE(SUM(o.total_khr), 0) as gross_revenue_khr 
    FROM orders o 
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$orderWhere}
";
$revStmt = $pdo->prepare($revSql);
$revStmt->execute($dateParams);
$revData = $revStmt->fetch() ?: [
    'total_vehicles' => 0, 
    'total_subtotal' => 0.00,
    'total_discount' => 0.00,
    'gross_revenue' => 0.00, 
    'gross_revenue_khr' => 0
];

$grossRev = (float)$revData['gross_revenue'];
$totalSubtotal = (float)$revData['total_subtotal'];
$totalDiscount = (float)$revData['total_discount'];
$totalVehicles = (int)$revData['total_vehicles'];
$avgTicket = ($totalVehicles > 0) ? ($grossRev / $totalVehicles) : 0.00;

// 1b. Settled Refunds Calculation
if ($hasRefundCols) {
    $refSql = "
        SELECT 
            COALESCE(SUM(
                CASE 
                    WHEN o.refund_amount_usd > 0 THEN o.refund_amount_usd
                    WHEN o.refund_status = 'refunded' OR o.payment_status = 'refunded' THEN o.total_usd
                    ELSE 0 
                END
            ), 0) as total_refunds_usd,
            COALESCE(SUM(
                CASE 
                    WHEN o.refund_amount_usd > 0 THEN ROUND(o.refund_amount_usd * 4100)
                    WHEN o.refund_status = 'refunded' OR o.payment_status = 'refunded' THEN o.total_khr
                    ELSE 0 
                END
            ), 0) as total_refunds_khr,
            COUNT(CASE WHEN o.refund_amount_usd > 0 OR o.refund_status = 'refunded' OR o.payment_status = 'refunded' THEN 1 END) as refund_count
        FROM orders o 
        WHERE (o.refund_amount_usd > 0 OR o.refund_status = 'refunded' OR o.payment_status = 'refunded')
          AND o.status != 'cancelled'
          {$orderWhere}
    ";
} else {
    $refSql = "
        SELECT 
            COALESCE(SUM(CASE WHEN o.payment_status = 'refunded' THEN o.total_usd ELSE 0 END), 0) as total_refunds_usd,
            COALESCE(SUM(CASE WHEN o.payment_status = 'refunded' THEN o.total_khr ELSE 0 END), 0) as total_refunds_khr,
            COUNT(CASE WHEN o.payment_status = 'refunded' THEN 1 END) as refund_count
        FROM orders o 
        WHERE o.payment_status = 'refunded'
          AND o.status != 'cancelled'
          {$orderWhere}
    ";
}
$refStmt = $pdo->prepare($refSql);
$refStmt->execute($dateParams);
$refData = $refStmt->fetch() ?: [
    'total_refunds_usd' => 0.00,
    'total_refunds_khr' => 0,
    'refund_count' => 0
];
$settledRefunds = (float)$refData['total_refunds_usd'];
$settledRefundsKhr = (int)$refData['total_refunds_khr'];
$refundCount = (int)$refData['refund_count'];

// Net Settled Revenue = Confirmed Gross Payments - Settled Refunds
$netSettledRevenue = max(0.0, $grossRev - $settledRefunds);
$netSettledRevenueKhr = (int)round($netSettledRevenue * get_exchange_rate());

$washerComm = 0.00;
$mechanicComm = 0.00;
$totalComm = 0.00;

// 3. Operating Expenses (shop-wide; exclude cancelled entries)
$expSql = "SELECT COALESCE(SUM(amount_usd), 0) as total_expenses FROM expenses WHERE (is_active IS NULL OR is_active = 1) {$expenseWhere}";
$expStmt = $pdo->prepare($expSql);
$expStmt->execute($dateParams);
$totalExp = (float)$expStmt->fetchColumn();

// 4. Revenue Split & Cost of Goods Sold (COGS)
$splitSql = "
    SELECT 
        COALESCE(SUM(CASE WHEN oi.item_type = 'service' AND (s.category = 'wash' OR s.category IS NULL) THEN oi.price_usd ELSE 0 END), 0) as wash_revenue,
        COALESCE(SUM(CASE WHEN oi.item_type = 'repair' OR (oi.item_type = 'service' AND s.category = 'repair') THEN oi.price_usd ELSE 0 END), 0) as repair_revenue,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN oi.price_usd ELSE 0 END), 0) as product_revenue,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN (COALESCE(oi.qty, 1) * COALESCE(p.cost_price_usd, 0)) ELSE 0 END), 0) as product_cogs,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN COALESCE(oi.qty, 1) ELSE 0 END), 0) as product_units,
        COUNT(CASE WHEN oi.item_type IN ('product', 'spare_part') AND (p.cost_price_usd IS NULL OR p.cost_price_usd <= 0) THEN 1 END) as missing_cogs_count,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') AND (p.cost_price_usd IS NULL OR p.cost_price_usd <= 0) THEN oi.price_usd ELSE 0 END), 0) as missing_cogs_sales
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN services s ON oi.service_id = s.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$orderWhere}
";
$splitStmt = $pdo->prepare($splitSql);
$splitStmt->execute($dateParams);
$splitData = $splitStmt->fetch() ?: [
    'wash_revenue' => 0, 
    'repair_revenue' => 0, 
    'product_revenue' => 0, 
    'product_cogs' => 0, 
    'product_units' => 0,
    'missing_cogs_count' => 0,
    'missing_cogs_sales' => 0
];

$washRevenue = (float)$splitData['wash_revenue'];
$repairRevenue = (float)$splitData['repair_revenue'];
$productRevenue = (float)$splitData['product_revenue'];
$productCogs = (float)$splitData['product_cogs'];
$productUnits = (int)$splitData['product_units'];
$missingCogsCount = (int)$splitData['missing_cogs_count'];
$missingCogsSales = (float)$splitData['missing_cogs_sales'];
$hasMissingCogs = $missingCogsCount > 0;
$productProfit = $productRevenue - $productCogs;

// 5. Net Operating Profit Formula: Net Settled Revenue - COGS - Operating Expenses
$netProfit = $netSettledRevenue - $productCogs - $totalExp;
$netProfitKhr = (int)round($netProfit * get_exchange_rate());
$netOperatingProfit = $netProfit;
$netOperatingProfitKhr = $netProfitKhr;

// 4c. Top Selling Products & Spare Parts (Joined with categories table for proper names & icons)
$topProdSql = "
    SELECT 
        oi.service_name as name,
        COALESCE(c.code, p.category, 'spare_part') as category_code,
        COALESCE(c.name_en, 'Spare Part') as category_name_en,
        COALESCE(c.name_km, 'គ្រឿងបន្លាស់') as category_name_km,
        COALESCE(c.icon, 'fa-box-open') as category_icon,
        COALESCE(c.color, '#64748b') as category_color,
        COALESCE(p.brand, '-') as brand,
        SUM(COALESCE(oi.qty, 1)) as total_qty,
        SUM(oi.price_usd) as total_sales,
        SUM(COALESCE(oi.qty, 1) * COALESCE(p.cost_price_usd, 0)) as total_cost,
        SUM(oi.price_usd - (COALESCE(oi.qty, 1) * COALESCE(p.cost_price_usd, 0))) as gross_profit
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN products p ON oi.product_id = p.id
    LEFT JOIN categories c ON (p.category_id = c.id OR (c.type = 'product' AND p.category = c.code))
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' AND oi.item_type IN ('product', 'spare_part') {$orderWhere}
    GROUP BY oi.service_name, c.code, c.name_en, c.name_km, c.icon, c.color, p.category, p.brand
    ORDER BY total_sales DESC
";
$topProdStmt = $pdo->prepare($topProdSql);
$topProdStmt->execute($dateParams);
$topProducts = $topProdStmt->fetchAll();

// 4d. Repair Services Breakdown
$repairServSql = "
    SELECT oi.service_name, COUNT(*) as qty, COALESCE(SUM(oi.price_usd), 0) as service_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN services s ON oi.service_id = s.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' AND (oi.item_type = 'repair' OR (oi.item_type = 'service' AND s.category = 'repair')) {$orderWhere}
    GROUP BY oi.service_name
    ORDER BY service_revenue DESC
";
$repairServStmt = $pdo->prepare($repairServSql);
$repairServStmt->execute($dateParams);
$repairBreakdown = $repairServStmt->fetchAll();

// 5. Income by Wash Service Package
$servSql = "
    SELECT oi.service_name, COUNT(*) as qty, COALESCE(SUM(oi.price_usd), 0) as service_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN services s ON oi.service_id = s.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' AND (oi.item_type = 'service' AND (s.category = 'wash' OR s.category IS NULL)) {$orderWhere}
    GROUP BY oi.service_name
    ORDER BY service_revenue DESC
";
$servStmt = $pdo->prepare($servSql);
$servStmt->execute($dateParams);
$servicesBreakdown = $servStmt->fetchAll();

// 5b. Income by Dynamic Category
$dynamicCatSql = "
    SELECT 
        c.id as category_id,
        c.type,
        c.code,
        c.name_en,
        c.name_km,
        c.color,
        c.icon,
        COUNT(oi.id) as item_count,
        COALESCE(SUM(oi.price_usd), 0) as total_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN services s ON oi.service_id = s.id
    LEFT JOIN products p ON oi.product_id = p.id
    JOIN categories c ON (
        (oi.item_type IN ('service', 'repair') AND (s.category_id = c.id OR s.category = c.code))
        OR (oi.item_type IN ('product', 'spare_part') AND (p.category_id = c.id OR p.category = c.code))
    )
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$orderWhere}
    GROUP BY c.id, c.type, c.code, c.name_en, c.name_km, c.color, c.icon
    ORDER BY total_revenue DESC
";
$dynamicCatStmt = $pdo->prepare($dynamicCatSql);
$dynamicCatStmt->execute($dateParams);
$categoryRevenueBreakdown = $dynamicCatStmt->fetchAll();

// 6. Income by Vehicle Size
$vehSql = "
    SELECT o.vehicle_type, COUNT(*) as count, COALESCE(SUM(o.total_usd), 0) as v_revenue
    FROM orders o
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$orderWhere}
    GROUP BY o.vehicle_type
    ORDER BY v_revenue DESC
";
$vehStmt = $pdo->prepare($vehSql);
$vehStmt->execute($dateParams);
$vehicleBreakdown = $vehStmt->fetchAll();

// 8. Payment Method Split
$paySql = "
    SELECT o.payment_method, COUNT(*) as count, COALESCE(SUM(o.total_usd), 0) as method_total
    FROM orders o
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$orderWhere}
    GROUP BY o.payment_method
    ORDER BY method_total DESC
";
$payStmt = $pdo->prepare($paySql);
$payStmt->execute($dateParams);
$payBreakdown = $payStmt->fetchAll();

// 9. Cancelled Orders & Lost Revenue Analytics (Visible to Owner & Manager)
$canViewCancelReport = in_array(Auth::getRole(), ['owner', 'manager'], true);
$allCancelledOrders = [];
$totalCancelledCount = 0;
$totalLostRevenueUsd = 0.00;
$totalLostRevenueKhr = 0;
$cancelledByReason = [];

if ($canViewCancelReport) {
    $cancelDateWhere = "";
    $cancelParams = [];

    $cancelDateWhere = "AND COALESCE(o.cancelled_at, o.created_at) >= ? AND COALESCE(o.cancelled_at, o.created_at) < ?";
    $cancelParams = [$periodStart, $periodEnd];

    $cancelWhere = $cancelDateWhere;

    $hasCancelCodeCol = false;
    try {
        $pdo->query("SELECT cancel_reason_code FROM orders LIMIT 0");
        $hasCancelCodeCol = true;
    } catch (Throwable $e) {
        $hasCancelCodeCol = false;
    }

    $cancelCodeSelect = $hasCancelCodeCol ? "o.cancel_reason_code" : "NULL as cancel_reason_code";

    $cancelsSql = "
        SELECT 
            o.id,
            o.order_number,
            o.total_usd,
            o.total_khr,
            o.cancel_reason,
            {$cancelCodeSelect},
            o.cancelled_by_user_id,
            o.cancel_approved_by_user_id,
            o.cancelled_at,
            o.created_at,
            o.plate_number,
            c.name as customer_name,
            u1.full_name as canceller_name,
            u1.username as canceller_username,
            u1.role as canceller_role,
            u2.full_name as approver_name,
            u2.username as approver_username,
            u2.role as approver_role,
            dev.device_name,
            dev.device_uid
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u1 ON o.cancelled_by_user_id = u1.id
        LEFT JOIN users u2 ON o.cancel_approved_by_user_id = u2.id
        LEFT JOIN pos_devices dev ON o.pos_device_id = dev.id
        WHERE o.status = 'cancelled' {$cancelWhere}
        ORDER BY COALESCE(o.cancelled_at, o.created_at) DESC, o.id DESC
    ";
    $cancelStmt = $pdo->prepare($cancelsSql);
    $cancelStmt->execute($cancelParams);
    $allCancelledOrders = $cancelStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCancelledCount = count($allCancelledOrders);
    $curRate = get_exchange_rate();

    foreach ($allCancelledOrders as &$cord) {
        $amtUsd = (float)($cord['total_usd'] ?? 0);
        $amtKhr = (int)($cord['total_khr'] ?? round($amtUsd * $curRate));
        $totalLostRevenueUsd += $amtUsd;
        $totalLostRevenueKhr += $amtKhr;

        $cat = OrderCancellation::categorizeForReport($cord['cancel_reason_code'] ?? null, $cord['cancel_reason'] ?? '');
        $cord['report_cat_key'] = $cat['group_key'];
        $cord['report_cat_label'] = $cat['group_label'];

        if (!isset($cancelledByReason[$cat['group_key']])) {
            $cancelledByReason[$cat['group_key']] = [
                'key' => $cat['group_key'],
                'label' => $cat['group_label'],
                'count' => 0,
                'lost_usd' => 0.00,
                'lost_khr' => 0
            ];
        }
        $cancelledByReason[$cat['group_key']]['count']++;
        $cancelledByReason[$cat['group_key']]['lost_usd'] += $amtUsd;
        $cancelledByReason[$cat['group_key']]['lost_khr'] += $amtKhr;
    }
    unset($cord);
}

// CSV Export for Cash Movements (Redirect to dedicated view exporter)
if (isset($_GET['export']) && $_GET['export'] === 'cash_movements') {
    if (!in_array(Auth::getRole(), ['owner', 'manager'], true)) {
        http_response_code(403);
        die("Permission denied: Cash Movements export is restricted to Owner and Manager.");
    }
    $expParams = $_GET;
    $expParams['view'] = 'cash_movements';
    $expParams['export'] = 'csv';
    header('Location: ?' . http_build_query($expParams));
    exit;
}

// CSV Export for Cashier Sessions (Redirect to dedicated view exporter)
if (isset($_GET['export']) && $_GET['export'] === 'cashier_sessions') {
    if (!in_array(Auth::getRole(), ['owner', 'manager'], true)) {
        http_response_code(403);
        die("Permission denied: Cashier Sessions export is restricted to Owner and Manager.");
    }
    $expParams = $_GET;
    $expParams['view'] = 'cashier_sessions';
    $expParams['export'] = 'csv';
    header('Location: ?' . http_build_query($expParams));
    exit;
}

// CSV Export for Cancelled Orders & Lost Revenue
if (isset($_GET['export']) && $_GET['export'] === 'cancellations') {
    if (!in_array(Auth::getRole(), ['owner', 'manager'], true)) {
        http_response_code(403);
        die("Permission denied: Cancellation report is restricted to Owner and Manager.");
    }
    Auth::requirePermission('reports.export');
    $filename = "CarWash_Cancellations_" . $period . "_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['AQUAGLEAM CAR WASH - CANCELLED ORDERS & LOST REVENUE REPORT']);
    fputcsv($out, [
        'Period:', strtoupper(str_replace('_', ' ', $period)),
        'Start Date:', $startDate,
        'End Date:', $endDate,
        'Scope:', 'Whole site',
        'Timezone:', 'Asia/Phnom_Penh (UTC+7)',
        'Generated At:', date('Y-m-d H:i:s'),
        'Generated By:', (Auth::getUserName() ?: Auth::getUsername()) . ' (' . ucfirst(Auth::getRole()) . ')'
    ]);
    fputcsv($out, []);

    // Summary
    fputcsv($out, ['--- CANCELLATION SUMMARY ---']);
    fputcsv($out, ['Total Cancellations', $totalCancelledCount]);
    fputcsv($out, ['Total Lost Revenue (USD)', number_format($totalLostRevenueUsd, 2)]);
    fputcsv($out, ['Total Lost Revenue (KHR)', number_format($totalLostRevenueKhr)]);
    fputcsv($out, []);

    // Breakdown by Reason
    fputcsv($out, ['--- BREAKDOWN BY REASON ---']);
    fputcsv($out, ['Reason Category', 'Count', '% of Cancellations', 'Lost Revenue (USD)', 'Lost Revenue (KHR)']);
    foreach ($cancelledByReason as $rGrp) {
        $pct = ($totalCancelledCount > 0) ? round(($rGrp['count'] / $totalCancelledCount) * 100, 1) : 0;
        fputcsv($out, [
            $rGrp['label'],
            $rGrp['count'],
            $pct . '%',
            number_format($rGrp['lost_usd'], 2),
            number_format($rGrp['lost_khr'])
        ]);
    }
    fputcsv($out, []);

    // Detailed List
    fputcsv($out, ['--- DETAILED CANCELLED ORDERS ---']);
    fputcsv($out, [
        'Order #',
        'Date Time',
        'Plate Number',
        'Customer Name',
        'Lost Revenue (USD)',
        'Lost Revenue (KHR)',
        'Reason Category',
        'Reason Details / Note',
        'Cancelled By',
        'Approved By',
        'Device'
    ]);

    foreach ($allCancelledOrders as $co) {
        $cancellerStr = $co['canceller_name'] ? "{$co['canceller_name']} (@{$co['canceller_username']})" : 'Staff';
        $approverStr = $co['approver_name'] ? "{$co['approver_name']} (@{$co['approver_username']})" : '-';
        $devStr = $co['device_name'] ? "{$co['device_name']} ({$co['device_uid']})" : 'Back Office Web';
        $cancelTime = $co['cancelled_at'] ?: $co['created_at'];

        fputcsv($out, [
            $co['order_number'],
            $cancelTime,
            $co['plate_number'] ?: 'N/A',
            $co['customer_name'] ?: 'General Customer',
            number_format((float)($co['total_usd'] ?? 0), 2),
            number_format((int)($co['total_khr'] ?? 0)),
            $co['report_cat_label'],
            $co['cancel_reason'] ?: '-',
            $cancellerStr,
            $approverStr,
            $devStr
        ]);
    }
    fclose($out);
    exit;
}

// CSV Export for Excel
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    Auth::requirePermission('reports.export');
    $filename = "CarWash_Report_" . $period . "_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens Khmer and unicode characters correctly
    fwrite($out, "\xEF\xBB\xBF");

    // Title & Meta with Device and Timezone
    fputcsv($out, ['AQUAGLEAM CAR WASH - FINANCIAL & OPERATIONS REPORT']);
    fputcsv($out, [
        'Period:', strtoupper(str_replace('_', ' ', $period)),
        'Start Date:', $startDate,
        'End Date:', $endDate,
        'Scope:', 'Whole site',
        'Timezone:', 'Asia/Phnom_Penh (UTC+7)',
        'Generated At:', date('Y-m-d H:i:s')
    ]);
    fputcsv($out, []);

    // Financial Summary
    fputcsv($out, ['--- FINANCIAL SUMMARY ---']);
    fputcsv($out, ['Metric', 'Amount (USD)', 'Amount (KHR)']);
    fputcsv($out, ['Total Vehicles Serviced', $totalVehicles, '-']);
    $curRate = get_exchange_rate();
    fputcsv($out, ['Total Subtotal (Catalog)', number_format($totalSubtotal, 2), number_format((int)round($totalSubtotal * $curRate))]);
    fputcsv($out, ['Total Discounts Applied', '-' . number_format($totalDiscount, 2), '-' . number_format((int)round($totalDiscount * $curRate))]);
    fputcsv($out, ['Confirmed Gross Payments', number_format($grossRev, 2), number_format((int)round($grossRev * $curRate))]);
    fputcsv($out, ['  - Settled Refunds', '-' . number_format($settledRefunds, 2), '-' . number_format($settledRefundsKhr)]);
    fputcsv($out, ['Net Settled Revenue', number_format($netSettledRevenue, 2), number_format($netSettledRevenueKhr)]);
    fputcsv($out, ['  - Car Wash Services', number_format($washRevenue, 2), number_format((int)round($washRevenue * $curRate))]);
    fputcsv($out, ['  - Repair & Maintenance Labor', number_format($repairRevenue, 2), number_format((int)round($repairRevenue * $curRate))]);
    fputcsv($out, ['  - Products & Spare Parts Sales', number_format($productRevenue, 2), number_format((int)round($productRevenue * $curRate))]);
    if ($canViewProfit) {
        fputcsv($out, ['Cost of Goods Sold (COGS)', '-' . number_format($productCogs, 2), '-' . number_format((int)round($productCogs * $curRate))]);
        fputcsv($out, ['Operating Expenses', '-' . number_format($totalExp, 2), '-' . number_format((int)round($totalExp * $curRate))]);
        fputcsv($out, ['NET OPERATING PROFIT', number_format($netOperatingProfit, 2), number_format($netOperatingProfitKhr)]);
        if ($hasMissingCogs) {
            fputcsv($out, ['WARNING: Missing COGS Data', "{$missingCogsCount} sold items lack cost price (affecting $" . number_format($missingCogsSales, 2) . " in sales)", 'Profit may be overstated']);
        }
    }
    fputcsv($out, []);

    // Revenue by Category
    fputcsv($out, ['--- REVENUE BY DYNAMIC CATEGORY ---']);
    fputcsv($out, ['Category Type', 'Category Name', 'Items Count', 'Revenue (USD)', 'Revenue (KHR)']);
    foreach ($categoryRevenueBreakdown as $cr) {
        $cName = (Language::current() === 'km') ? $cr['name_km'] : $cr['name_en'];
        $usd = (float)$cr['total_revenue'];
        $khr = (int)round($usd * $curRate);
        fputcsv($out, [
            strtoupper($cr['type']),
            $cName . ' (' . $cr['name_en'] . ')',
            $cr['item_count'],
            number_format($usd, 2),
            number_format($khr)
        ]);
    }
    fputcsv($out, []);

    // Products & Spare Parts
    fputcsv($out, ['--- PRODUCTS & SPARE PARTS SALES ---']);
    if ($canViewProfit) {
        fputcsv($out, ['Item Name', 'Category', 'Brand', 'Units Sold', 'Total Sales (USD)', 'COGS Cost (USD)', 'Gross Profit (USD)']);
        foreach ($topProducts as $tp) {
            $catLabel = (Language::current() === 'km') ? $tp['category_name_km'] : $tp['category_name_en'];
            fputcsv($out, [
                $tp['name'],
                $catLabel,
                $tp['brand'],
                $tp['total_qty'],
                number_format((float)$tp['total_sales'], 2),
                number_format((float)$tp['total_cost'], 2),
                number_format((float)$tp['gross_profit'], 2)
            ]);
        }
    } else {
        fputcsv($out, ['Item Name', 'Category', 'Brand', 'Units Sold', 'Total Sales (USD)']);
        foreach ($topProducts as $tp) {
            $catLabel = (Language::current() === 'km') ? $tp['category_name_km'] : $tp['category_name_en'];
            fputcsv($out, [
                $tp['name'],
                $catLabel,
                $tp['brand'],
                $tp['total_qty'],
                number_format((float)$tp['total_sales'], 2)
            ]);
        }
    }
    fputcsv($out, []);

    // Car Wash Services
    fputcsv($out, ['--- CAR WASH SERVICES BREAKDOWN ---']);
    fputcsv($out, ['Service Name', 'Count', 'Revenue (USD)']);
    foreach ($servicesBreakdown as $sb) {
        fputcsv($out, [
            $sb['service_name'],
            $sb['qty'],
            number_format((float)$sb['service_revenue'], 2)
        ]);
    }
    fputcsv($out, []);

    // Repair Services
    fputcsv($out, ['--- REPAIR & MAINTENANCE SERVICES ---']);
    fputcsv($out, ['Repair Job', 'Count', 'Labor Revenue (USD)']);
    foreach ($repairBreakdown as $rb) {
        fputcsv($out, [
            $rb['service_name'],
            $rb['qty'],
            number_format((float)$rb['service_revenue'], 2)
        ]);
    }
    fputcsv($out, []);

    // Payment Methods
    fputcsv($out, ['--- PAYMENT METHODS BREAKDOWN ---']);
    fputcsv($out, ['Payment Method', 'Orders Count', 'Total Collected (USD)']);
    foreach ($payBreakdown as $pb) {
        fputcsv($out, [
            strtoupper($pb['payment_method']),
            $pb['count'],
            number_format((float)$pb['method_total'], 2)
        ]);
    }
    fputcsv($out, []);

    fclose($out);
    exit;
}

$curRate = get_exchange_rate();
$currentLang = Language::current();

include dirname(__DIR__) . '/layouts/header.php';
?>

<style>
/* ==========================================================================
   REPORTS & PROFITS REDESIGNED STYLES (Light & Dark Theme Resilient)
   ========================================================================== */
.reports-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding-bottom: 30px;
}

/* Header & Controls Bar */
.reports-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 18px 24px;
    box-shadow: var(--shadow-sm);
}

.reports-header-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.reports-header-sub {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* Unified Filter & Actions Toolbar */
.reports-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 14px 16px;
    box-shadow: var(--shadow-sm);
}

.filter-period-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-pill {
    padding: 8px 16px;
    border-radius: var(--radius-full);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid var(--border);
    background: var(--bg-subtle);
    color: var(--text-muted);
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.filter-pill:hover {
    color: var(--text-main);
    border-color: var(--primary);
    background: var(--primary-light);
}

.filter-pill.active {
    background: var(--primary);
    color: #ffffff;
    border-color: var(--primary);
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
}

.custom-range-form {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: 6px;
    flex-wrap: wrap;
}

.date-input-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 4px 10px;
    font-size: 13px;
    color: var(--text-main);
}

.date-input-wrap input[type="date"] {
    border: none;
    background: transparent;
    color: inherit;
    font-family: inherit;
    font-size: 13px;
    outline: none;
    cursor: pointer;
}

/* Actions Menu Dropdown */
.actions-group {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.actions-menu-btn {
    min-height: 40px;
    padding: 8px 16px;
    font-size: 13.5px;
}

.actions-dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
    min-width: 220px;
    z-index: 50;
    display: none;
    overflow: hidden;
}

.actions-dropdown.show {
    display: block;
}

.actions-dropdown a, 
.actions-dropdown button {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 16px;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-main);
    background: none;
    border: none;
    text-decoration: none;
    text-align: left;
    cursor: pointer;
    transition: background 0.15s ease;
}

.actions-dropdown a:hover,
.actions-dropdown button:hover {
    background: var(--bg-subtle);
    color: var(--primary);
}

.actions-dropdown-divider {
    height: 1px;
    background: var(--border);
    margin: 4px 0;
}

/* KPI Summary Cards Grid: Balanced 2 Columns with Equal Height */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 18px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: var(--shadow-sm);
    min-height: 148px;
    position: relative;
    overflow: hidden;
}

.kpi-card.profit-positive {
    border-color: #16a34a;
    background: linear-gradient(180deg, rgba(22, 163, 74, 0.05) 0%, var(--bg-card) 100%);
}

.kpi-card.profit-negative {
    border-color: #dc2626;
    background: linear-gradient(180deg, rgba(220, 38, 38, 0.06) 0%, var(--bg-card) 100%);
}

.kpi-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.kpi-card-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    line-height: 1.3;
}

.kpi-card-value {
    font-size: 32px;
    font-weight: 800;
    line-height: 1.15;
    margin-top: 6px;
    letter-spacing: -0.02em;
}

.kpi-card-khr {
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-muted);
    margin-top: 4px;
}

.kpi-card-footer {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
    font-size: 12.5px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}

.kpi-icon-box {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

/* Visual Interactive Charts Section */
.charts-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 22px;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-sm);
    min-height: 310px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.chart-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.chart-subtitle {
    font-size: 12px;
    color: var(--text-muted);
}

.chart-bars-wrap {
    display: flex;
    flex-direction: column;
    gap: 14px;
    flex: 1;
    justify-content: center;
}

.chart-bar-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.chart-bar-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
}

.chart-bar-label {
    font-weight: 600;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 6px;
}

.chart-bar-track {
    height: 9px;
    background: var(--bg-subtle);
    border-radius: var(--radius-full);
    overflow: hidden;
    position: relative;
}

.chart-bar-fill {
    height: 100%;
    border-radius: var(--radius-full);
    transition: width 0.3s ease;
}

/* Secondary Metrics Row: 2 balanced cards */
.secondary-metrics-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.metric-pill-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: var(--shadow-sm);
}

/* Full Width Table Card Container */
.report-table-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.report-table-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: var(--bg-card);
}

.report-table-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.table-responsive-clean {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.table-clean {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    text-align: left;
}

.table-clean th {
    padding: 14px 20px;
    background: var(--bg-subtle);
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

.table-clean td {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
    color: var(--text-main);
    vertical-align: middle;
}

.table-clean tbody tr:last-child td {
    border-bottom: none;
}

.table-clean tbody tr:hover {
    background: var(--bg-subtle);
}

.table-num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.empty-state-box {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-muted);
}

.empty-state-icon {
    font-size: 36px;
    color: var(--border);
    margin-bottom: 12px;
}

.empty-state-text {
    font-size: 14px;
    font-weight: 500;
}

/* Two-Column Table Grid for Service Packages & Vehicle Sizes */
.tables-dual-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

/* Responsive adjustments */
@media (max-width: 1024px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
    .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .tables-dual-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .kpi-grid, 
    .secondary-metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .reports-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .filter-period-group {
        justify-content: flex-start;
    }
    .custom-range-form {
        margin-left: 0;
        margin-top: 6px;
        width: 100%;
    }
    .custom-range-form .date-input-wrap {
        flex: 1;
    }
    .actions-group {
        width: 100%;
    }
    .actions-menu-btn {
        width: 100%;
    }
    .actions-dropdown {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .kpi-grid, .secondary-metrics-grid {
        grid-template-columns: 1fr;
    }
}

/* Print Optimization */
@media print {
    .sidebar, .top-header, .no-print, .reports-toolbar, .actions-group, nav, header, footer {
        display: none !important;
    }
    .main-wrapper, .main-content, .page-container {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
        font-size: 10pt !important;
    }
    .reports-container {
        gap: 16px !important;
    }
    .reports-header-bar {
        border: none !important;
        box-shadow: none !important;
        padding: 0 0 10px 0 !important;
    }
    .kpi-grid, .secondary-metrics-grid, .charts-grid, .tables-dual-grid {
        display: block !important;
    }
    .kpi-card, .chart-card, .metric-pill-card, .report-table-card {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
        break-inside: avoid;
        margin-bottom: 12px !important;
    }
    .table-clean th {
        background: #f1f5f9 !important;
        color: #000 !important;
    }
    .table-clean td {
        color: #000 !important;
    }
    .print-only {
        display: block !important;
    }
}   
</style>

<div class="reports-container" data-report-page="<?= htmlspecialchars($reportKey) ?>">
    <div class="reports-header-bar">
        <div>
            <h1 class="reports-header-title"><?= htmlspecialchars($reportTitle) ?></h1>
            <p class="reports-header-sub"><?= htmlspecialchars($reportDescriptions[$reportKey]) ?></p>
        </div>
    </div>
    <!-- Print Header Only (Shows when printing) -->
    <div class="print-only" style="display: none; padding: 12px 0 16px 0; border-bottom: 2px solid #333; margin-bottom: 20px;">
        <h2 style="font-size: 20px; font-weight: 800; margin: 0 0 6px 0;">AquaGleam Car Wash - Business &amp; Operations Report</h2>
        <div style="font-size: 12px; color: #555; display: flex; flex-wrap: wrap; gap: 16px;">
            <span><strong>Period:</strong> <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $period))) ?> (<?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?>)</span>
            <span><strong>Scope:</strong> Whole site</span>
            <span><strong>Report:</strong> <?= htmlspecialchars($reportTitle) ?></span>
            <span><strong>Shop Timezone:</strong> Asia/Phnom_Penh (UTC+7)</span>
            <span><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></span>
        </div>
    </div>

    <!-- 2. Report Toolbar: Period Filters, Custom Date Range, and Export Actions -->
    <div class="reports-toolbar no-print">
        <form action="" method="GET" class="filter-period-group" style="margin: 0;">
            <input type="hidden" name="view" value="reports">
            <input type="hidden" name="report" value="<?= htmlspecialchars($reportKey) ?>">
            <label for="reportPeriodSelect" class="text-xs text-muted" style="font-weight: 700;">Date range</label>
            <select id="reportPeriodSelect" name="period" class="form-input" style="width: 190px; min-height: 42px;" onchange="handleReportPeriodChange(this)">
                <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="this_week" <?= $period === 'this_week' ? 'selected' : '' ?>>This week</option>
                <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>This month</option>
                <option value="this_year" <?= $period === 'this_year' ? 'selected' : '' ?>>This year</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom range</option>
            </select>
            <?php if ($period !== 'custom'): ?>
                <button type="submit" class="btn btn-secondary" style="min-height: 42px;">Apply</button>
            <?php endif; ?>
        </form>

        <form action="" method="GET" id="customReportRange" class="custom-range-form" style="<?= $period === 'custom' ? 'display: inline-flex;' : 'display: none;' ?>">
            <input type="hidden" name="view" value="reports">
            <input type="hidden" name="report" value="<?= htmlspecialchars($reportKey) ?>">
            <input type="hidden" name="period" value="custom">
            <div class="date-input-wrap" title="Start Date">
                <i class="fa-solid fa-calendar-day text-muted"></i>
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" required aria-label="Start date">
            </div>
            <span class="text-muted" style="font-size: 13px;">to</span>
            <div class="date-input-wrap" title="End Date">
                <i class="fa-solid fa-calendar-day text-muted"></i>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" required aria-label="End date">
            </div>
            <button type="submit" class="btn btn-primary" style="min-height: 42px;"><i class="fa-solid fa-filter mr-1"></i> Apply</button>
        </form>

        <!-- Separate Actions Menu Group (Export, Print, Backup) -->
        <div class="actions-group">
            <button type="button" class="btn btn-secondary actions-menu-btn" id="reportsActionsBtn" onclick="toggleReportsActions(event)">
                <i class="fa-solid fa-ellipsis-vertical mr-1"></i> Actions <i class="fa-solid fa-chevron-down ml-auto" style="font-size: 11px; margin-left: 6px;"></i>
            </button>
            <div class="actions-dropdown" id="reportsActionsDropdown">
                <?php if (Auth::can('reports.export')): ?>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=csv">
                    <i class="fa-solid fa-file-excel" style="color: #16a34a; width: 16px;"></i> Export Full Report (CSV)
                </a>
                <?php if (in_array(Auth::getRole(), ['owner', 'manager'], true)): ?>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=cancellations">
                    <i class="fa-solid fa-ban" style="color: #dc2626; width: 16px;"></i> <?= htmlspecialchars(__('export_cancellations_csv', 'Cancelled Orders (CSV)')) ?>
                </a>
                <a href="?view=cash_movements&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=csv">
                    <i class="fa-solid fa-money-bill-transfer" style="color: #ea580c; width: 16px;"></i> <?= htmlspecialchars(__('export_cash_movements_csv', 'Cash Movements (CSV)')) ?>
                </a>
                <a href="?view=cashier_sessions&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=csv">
                    <i class="fa-solid fa-user-clock" style="color: #2563eb; width: 16px;"></i> <?= htmlspecialchars(__('export_shifts_csv', 'Cashier Sessions (CSV)')) ?>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                <button type="button" onclick="window.print(); toggleReportsActions();">
                    <i class="fa-solid fa-print" style="color: var(--primary); width: 16px;"></i> Print Report
                </button>
                <?php if (Auth::check() && Auth::getRole() === 'owner'): ?>
                <div class="actions-dropdown-divider"></div>
                <a href="<?= APP_URL ?>/index.php?action=download_backup">
                    <i class="fa-solid fa-database" style="color: #ea580c; width: 16px;"></i> Full Database Backup (.sql)
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/reports/financial-summary.php'; ?>
    <?php require __DIR__ . '/reports/service-revenue.php'; ?>
    <?php require __DIR__ . '/reports/product-sales.php'; ?>
    <?php require __DIR__ . '/reports/cashier-shift-audit.php'; ?>
</div>

<script>
function handleReportPeriodChange(select) {
    const customRange = document.getElementById('customReportRange');
    const applyButton = select.form.querySelector('button[type="submit"]');
    if (select.value === 'custom') {
        customRange.style.display = 'inline-flex';
        if (applyButton) applyButton.style.display = 'none';
        return;
    }
    customRange.style.display = 'none';
    select.form.submit();
}

function toggleReportsActions(e) {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById('reportsActionsDropdown');
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('reportsActionsDropdown');
    const btn = document.getElementById('reportsActionsBtn');
    if (dropdown && dropdown.classList.contains('show')) {
        if (!dropdown.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
