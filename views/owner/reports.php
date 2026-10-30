<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('reports.view');
$pageTitle = __('nav_reports');
$pdo = Database::getInstance();

// Period Filter
$period = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Device Filter (all, web, or POS device ID)
$selectedDevice = $_GET['device'] ?? 'all';
$deviceWhere = "";
$selectedDeviceName = __('all_devices');

if ($selectedDevice === 'web') {
    $deviceWhere = " AND (o.pos_device_id IS NULL OR o.pos_device_id = 0 OR o.source = 'web')";
    $selectedDeviceName = __('back_office_web');
} elseif (is_numeric($selectedDevice) && (int)$selectedDevice > 0) {
    $devId = (int)$selectedDevice;
    $deviceWhere = " AND o.pos_device_id = {$devId}";
    $devRow = $pdo->query("SELECT device_name, device_uid FROM pos_devices WHERE id = {$devId}")->fetch(PDO::FETCH_ASSOC);
    if ($devRow) {
        $selectedDeviceName = $devRow['device_name'] . ' (' . $devRow['device_uid'] . ')';
    }
} else {
    $selectedDevice = 'all';
}

// Registered devices list
$allRegisteredDevices = $pdo->query("SELECT id, device_uid, device_name, location, status FROM pos_devices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$orderDateWhere = "";
$expenseWhere = "";
$dateParams = [];

// Use paid_at (or created_at if paid_at is null) in shop timezone for all order reporting
if ($period === 'today') {
    $orderDateWhere = "AND DATE(COALESCE(o.paid_at, o.created_at)) = CURDATE()";
    $expenseWhere = "AND expense_date = CURDATE()";
} elseif ($period === 'this_week') {
    $orderDateWhere = "AND YEARWEEK(COALESCE(o.paid_at, o.created_at), 1) = YEARWEEK(CURDATE(), 1)";
    $expenseWhere = "AND YEARWEEK(expense_date, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period === 'this_month') {
    $orderDateWhere = "AND MONTH(COALESCE(o.paid_at, o.created_at)) = MONTH(CURDATE()) AND YEAR(COALESCE(o.paid_at, o.created_at)) = YEAR(CURDATE())";
    $expenseWhere = "AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())";
} elseif ($period === 'this_year') {
    $orderDateWhere = "AND YEAR(COALESCE(o.paid_at, o.created_at)) = YEAR(CURDATE())";
    $expenseWhere = "AND YEAR(expense_date) = YEAR(CURDATE())";
} elseif ($period === 'custom' && $startDate && $endDate) {
    $orderDateWhere = "AND DATE(COALESCE(o.paid_at, o.created_at)) BETWEEN ? AND ?";
    $expenseWhere = "AND expense_date BETWEEN ? AND ?";
    $dateParams = [$startDate, $endDate];
}

$orderWhere = $orderDateWhere . $deviceWhere;

// 1. Gross Wash Revenue, Subtotal, Discount & Order Count
$revSql = "
    SELECT 
        COUNT(*) as total_vehicles, 
        COALESCE(SUM(o.subtotal_usd), 0) as total_subtotal,
        COALESCE(SUM(o.discount_usd), 0) as total_discount,
        COALESCE(SUM(o.total_usd), 0) as gross_revenue, 
        COALESCE(SUM(o.total_khr), 0) as gross_revenue_khr 
    FROM orders o 
    WHERE o.status = 'paid' {$orderWhere}
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

// 2. Total Staff Commissions Paid (Washers + Mechanics)
$wCommSql = "
    SELECT COALESCE(SUM(ow.commission_usd), 0)
    FROM orders o
    JOIN order_washers ow ON o.id = ow.order_id
    WHERE o.status = 'paid' {$orderWhere}
";
$wCommStmt = $pdo->prepare($wCommSql);
$wCommStmt->execute($dateParams);
$washerComm = (float)$wCommStmt->fetchColumn();

$mCommSql = "
    SELECT COALESCE(SUM(om.commission_usd), 0)
    FROM orders o
    JOIN order_mechanics om ON o.id = om.order_id
    WHERE o.status = 'paid' {$orderWhere}
";
$mCommStmt = $pdo->prepare($mCommSql);
$mCommStmt->execute($dateParams);
$mechanicComm = (float)$mCommStmt->fetchColumn();

$totalComm = $washerComm + $mechanicComm;

// 3. Operating Expenses
if ($selectedDevice === 'all') {
    $expSql = "SELECT COALESCE(SUM(amount_usd), 0) as total_expenses FROM expenses WHERE 1=1 {$expenseWhere}";
    $expStmt = $pdo->prepare($expSql);
    $expStmt->execute($dateParams);
    $totalExp = (float)$expStmt->fetchColumn();
} else {
    // Single device or web selected; shop-level operating expenses do not apply to an individual POS terminal
    $totalExp = 0.00;
}

// 4. Net Profit Calculation: Gross Revenue - Expenses - All Commissions
$netProfit = $grossRev - $totalExp - $totalComm;
$netProfitKhr = (int)round($netProfit * get_exchange_rate());

// 4b. Revenue Split: Wash Services vs. Repair Labor vs. Products & Spare Parts
$splitSql = "
    SELECT 
        COALESCE(SUM(CASE WHEN oi.item_type = 'service' AND (s.category = 'wash' OR s.category IS NULL) THEN oi.price_usd ELSE 0 END), 0) as wash_revenue,
        COALESCE(SUM(CASE WHEN oi.item_type = 'repair' OR (oi.item_type = 'service' AND s.category = 'repair') THEN oi.price_usd ELSE 0 END), 0) as repair_revenue,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN oi.price_usd ELSE 0 END), 0) as product_revenue,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN (COALESCE(oi.qty, 1) * COALESCE(p.cost_price_usd, 0)) ELSE 0 END), 0) as product_cogs,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN COALESCE(oi.qty, 1) ELSE 0 END), 0) as product_units
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN services s ON oi.service_id = s.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.status = 'paid' {$orderWhere}
";
$splitStmt = $pdo->prepare($splitSql);
$splitStmt->execute($dateParams);
$splitData = $splitStmt->fetch() ?: ['wash_revenue' => 0, 'repair_revenue' => 0, 'product_revenue' => 0, 'product_cogs' => 0, 'product_units' => 0];

$washRevenue = (float)$splitData['wash_revenue'];
$repairRevenue = (float)$splitData['repair_revenue'];
$productRevenue = (float)$splitData['product_revenue'];
$productCogs = (float)$splitData['product_cogs'];
$productUnits = (int)$splitData['product_units'];
$productProfit = $productRevenue - $productCogs;

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
    WHERE o.status = 'paid' AND oi.item_type IN ('product', 'spare_part') {$orderWhere}
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
    WHERE o.status = 'paid' AND (oi.item_type = 'repair' OR (oi.item_type = 'service' AND s.category = 'repair')) {$orderWhere}
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
    WHERE o.status = 'paid' AND (oi.item_type = 'service' AND (s.category = 'wash' OR s.category IS NULL)) {$orderWhere}
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
    WHERE o.status = 'paid' {$orderWhere}
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
    WHERE o.status = 'paid' {$orderWhere}
    GROUP BY o.vehicle_type
    ORDER BY v_revenue DESC
";
$vehStmt = $pdo->prepare($vehSql);
$vehStmt->execute($dateParams);
$vehicleBreakdown = $vehStmt->fetchAll();

// 7. Income by Washer (Filter strictly by role = 'washer')
$wSql = "
    SELECT u.full_name, COUNT(DISTINCT o.id) as jobs_count,
           COALESCE(SUM(o.total_usd), 0) as revenue_handled,
           COALESCE(SUM(ow.commission_usd), 0) as commission_earned
    FROM order_washers ow
    JOIN orders o ON ow.order_id = o.id
    JOIN users u ON ow.washer_id = u.id
    WHERE o.status = 'paid' AND u.role = 'washer' {$orderWhere}
    GROUP BY u.id, u.full_name
    ORDER BY revenue_handled DESC
";
$wStmt = $pdo->prepare($wSql);
$wStmt->execute($dateParams);
$washerBreakdown = $wStmt->fetchAll();

// 7b. Waiting & Washing Duration Breakdown per Vehicle
$timeSql = "
    SELECT o.id, o.order_number, o.plate_number, o.vehicle_type, o.customer_name,
           o.status, o.created_at, o.arrived_at, o.started_at, o.finished_at, o.paid_at,
           b.bay_name,
           GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ') as service_names
    FROM orders o
    LEFT JOIN bays b ON o.bay_id = b.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.status != 'cancelled' {$orderWhere}
    GROUP BY o.id
    ORDER BY COALESCE(o.finished_at, o.started_at, o.created_at) DESC
    LIMIT 100
";
$timeStmt = $pdo->prepare($timeSql);
$timeStmt->execute($dateParams);
$serviceTimes = $timeStmt->fetchAll(PDO::FETCH_ASSOC);

$totalWaitMins = 0;
$countWait = 0;
$totalWashMins = 0;
$countWash = 0;
foreach ($serviceTimes as &$st) {
    $arrivedTs = strtotime($st['arrived_at'] ?: $st['created_at']);
    $startedTs = !empty($st['started_at']) ? strtotime($st['started_at']) : null;
    $finishedTs = !empty($st['finished_at']) ? strtotime($st['finished_at']) : (!empty($st['paid_at']) ? strtotime($st['paid_at']) : null);

    $waitMins = null;
    if ($arrivedTs && $startedTs && $startedTs >= $arrivedTs) {
        $waitMins = max(0, (int)round(($startedTs - $arrivedTs) / 60));
        $totalWaitMins += $waitMins;
        $countWait++;
    }

    $washMins = null;
    if ($startedTs && $finishedTs && $finishedTs >= $startedTs) {
        $washMins = max(0, (int)round(($finishedTs - $startedTs) / 60));
        $totalWashMins += $washMins;
        $countWash++;
    }

    $totalDuration = ($waitMins !== null && $washMins !== null) ? ($waitMins + $washMins) : ($finishedTs && $arrivedTs && $finishedTs >= $arrivedTs ? (int)round(($finishedTs - $arrivedTs) / 60) : null);

    $st['wait_minutes'] = $waitMins;
    $st['wash_minutes'] = $washMins;
    $st['total_minutes'] = $totalDuration;
}
unset($st);

$avgWaitMins = $countWait > 0 ? (int)round($totalWaitMins / $countWait) : 0;
$avgWashMins = $countWash > 0 ? (int)round($totalWashMins / $countWash) : 0;
$avgTotalMins = $avgWaitMins + $avgWashMins;

// 8. Payment Method Split
$paySql = "
    SELECT o.payment_method, COUNT(*) as count, COALESCE(SUM(o.total_usd), 0) as method_total
    FROM orders o
    WHERE o.status = 'paid' {$orderWhere}
    GROUP BY o.payment_method
    ORDER BY method_total DESC
";
$payStmt = $pdo->prepare($paySql);
$payStmt->execute($dateParams);
$payBreakdown = $payStmt->fetchAll();

// 9. Sales by POS Device Breakdown (All Registered Devices + Web POS)
$devTotalPaidOrders = 0;
$devTotalGross = 0.00;
$devTotalDiscount = 0.00;
$devTotalNet = 0.00;
$devTotalCash = 0.00;
$devTotalAba = 0.00;
$devTotalKhqr = 0.00;
$devTotalUnpaid = 0.00;
$devTotalCancelled = 0;

$devQuerySql = "
    SELECT 
        pd.id,
        pd.device_name,
        pd.location,
        pd.device_uid,
        pd.status,
        COUNT(CASE WHEN o.status = 'paid' THEN 1 END) as paid_orders_count,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.subtotal_usd ELSE 0 END), 0) as gross_sales,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.discount_usd ELSE 0 END), 0) as total_discount,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_usd ELSE 0 END), 0) as net_sales,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'cash' THEN o.total_usd ELSE 0 END), 0) as cash_total,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'aba' THEN o.total_usd ELSE 0 END), 0) as aba_total,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'khqr' THEN o.total_usd ELSE 0 END), 0) as khqr_total,
        COALESCE(SUM(CASE WHEN o.status != 'cancelled' AND o.payment_status = 'unpaid' THEN o.total_usd ELSE 0 END), 0) as unpaid_total,
        COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) as cancelled_count
    FROM pos_devices pd
    LEFT JOIN orders o ON o.pos_device_id = pd.id {$orderDateWhere}
    GROUP BY pd.id, pd.device_name, pd.location, pd.device_uid, pd.status
    ORDER BY net_sales DESC, pd.id ASC
";
$devQueryStmt = $pdo->prepare($devQuerySql);
$devQueryStmt->execute($dateParams);
$registeredDevRows = $devQueryStmt->fetchAll(PDO::FETCH_ASSOC);

$webQuerySql = "
    SELECT 
        0 as id,
        'Back Office (Web POS)' as device_name,
        'Admin Desk' as location,
        'WEB' as device_uid,
        'active' as status,
        COUNT(CASE WHEN o.status = 'paid' THEN 1 END) as paid_orders_count,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.subtotal_usd ELSE 0 END), 0) as gross_sales,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.discount_usd ELSE 0 END), 0) as total_discount,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_usd ELSE 0 END), 0) as net_sales,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'cash' THEN o.total_usd ELSE 0 END), 0) as cash_total,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'aba' THEN o.total_usd ELSE 0 END), 0) as aba_total,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'khqr' THEN o.total_usd ELSE 0 END), 0) as khqr_total,
        COALESCE(SUM(CASE WHEN o.status != 'cancelled' AND o.payment_status = 'unpaid' THEN o.total_usd ELSE 0 END), 0) as unpaid_total,
        COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) as cancelled_count
    FROM orders o
    WHERE (o.pos_device_id IS NULL OR o.pos_device_id = 0 OR o.source = 'web') {$orderDateWhere}
";
$webQueryStmt = $pdo->prepare($webQuerySql);
$webQueryStmt->execute($dateParams);
$webRow = $webQueryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'id' => 0,
    'device_name' => 'Back Office (Web POS)',
    'location' => 'Admin Desk',
    'device_uid' => 'WEB',
    'status' => 'active',
    'paid_orders_count' => 0,
    'gross_sales' => 0.00,
    'total_discount' => 0.00,
    'net_sales' => 0.00,
    'cash_total' => 0.00,
    'aba_total' => 0.00,
    'khqr_total' => 0.00,
    'unpaid_total' => 0.00,
    'cancelled_count' => 0
];

$allDeviceReportRows = array_merge($registeredDevRows, [$webRow]);

foreach ($allDeviceReportRows as &$dr) {
    $dr['paid_orders_count'] = (int)$dr['paid_orders_count'];
    $dr['gross_sales'] = (float)$dr['gross_sales'];
    $dr['total_discount'] = (float)$dr['total_discount'];
    $dr['net_sales'] = (float)$dr['net_sales'];
    $dr['cash_total'] = (float)$dr['cash_total'];
    $dr['aba_total'] = (float)$dr['aba_total'];
    $dr['khqr_total'] = (float)$dr['khqr_total'];
    $dr['unpaid_total'] = (float)$dr['unpaid_total'];
    $dr['cancelled_count'] = (int)$dr['cancelled_count'];

    $devTotalPaidOrders += $dr['paid_orders_count'];
    $devTotalGross += $dr['gross_sales'];
    $devTotalDiscount += $dr['total_discount'];
    $devTotalNet += $dr['net_sales'];
    $devTotalCash += $dr['cash_total'];
    $devTotalAba += $dr['aba_total'];
    $devTotalKhqr += $dr['khqr_total'];
    $devTotalUnpaid += $dr['unpaid_total'];
    $devTotalCancelled += $dr['cancelled_count'];
}
unset($dr);

// 10. Sales by Cashier Breakdown (Respects current device filter: shop-wide or Device x Cashier)
$cashierQuerySql = "
    SELECT 
        COALESCE(u.id, 0) as cashier_id,
        COALESCE(u.full_name, 'Unassigned / System') as cashier_name,
        COALESCE(u.role, 'staff') as cashier_role,
        COUNT(CASE WHEN o.status = 'paid' THEN 1 END) as paid_orders_count,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.subtotal_usd ELSE 0 END), 0) as gross_sales,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.discount_usd ELSE 0 END), 0) as total_discount,
        COALESCE(SUM(CASE WHEN o.status = 'paid' THEN o.total_usd ELSE 0 END), 0) as net_sales,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'cash' THEN o.total_usd ELSE 0 END), 0) as cash_total,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'aba' THEN o.total_usd ELSE 0 END), 0) as aba_total,
        COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_method = 'khqr' THEN o.total_usd ELSE 0 END), 0) as khqr_total,
        COALESCE(SUM(CASE WHEN o.status != 'cancelled' AND o.payment_status = 'unpaid' THEN o.total_usd ELSE 0 END), 0) as unpaid_total,
        COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) as cancelled_count
    FROM orders o
    LEFT JOIN users u ON o.cashier_id = u.id
    WHERE 1=1 {$orderWhere}
    GROUP BY u.id, u.full_name, u.role
    ORDER BY net_sales DESC, paid_orders_count DESC
";
$cashierQueryStmt = $pdo->prepare($cashierQuerySql);
$cashierQueryStmt->execute($dateParams);
$cashierSalesRows = $cashierQueryStmt->fetchAll(PDO::FETCH_ASSOC);

$cashierTotalPaidOrders = 0;
$cashierTotalGross = 0.00;
$cashierTotalDiscount = 0.00;
$cashierTotalNet = 0.00;
$cashierTotalCash = 0.00;
$cashierTotalAba = 0.00;
$cashierTotalKhqr = 0.00;
$cashierTotalUnpaid = 0.00;
$cashierTotalCancelled = 0;

foreach ($cashierSalesRows as &$cr) {
    $cr['paid_orders_count'] = (int)$cr['paid_orders_count'];
    $cr['gross_sales'] = (float)$cr['gross_sales'];
    $cr['total_discount'] = (float)$cr['total_discount'];
    $cr['net_sales'] = (float)$cr['net_sales'];
    $cr['cash_total'] = (float)$cr['cash_total'];
    $cr['aba_total'] = (float)$cr['aba_total'];
    $cr['khqr_total'] = (float)$cr['khqr_total'];
    $cr['unpaid_total'] = (float)$cr['unpaid_total'];
    $cr['cancelled_count'] = (int)$cr['cancelled_count'];

    $cashierTotalPaidOrders += $cr['paid_orders_count'];
    $cashierTotalGross += $cr['gross_sales'];
    $cashierTotalDiscount += $cr['total_discount'];
    $cashierTotalNet += $cr['net_sales'];
    $cashierTotalCash += $cr['cash_total'];
    $cashierTotalAba += $cr['aba_total'];
    $cashierTotalKhqr += $cr['khqr_total'];
    $cashierTotalUnpaid += $cr['unpaid_total'];
    $cashierTotalCancelled += $cr['cancelled_count'];
}
unset($cr);

require_once dirname(__DIR__, 2) . '/core/PerformanceAnalytics.php';
require_once dirname(__DIR__, 2) . '/core/TimecardManager.php';

$perfStartDate = $startDate;
$perfEndDate = $endDate;
if ($period === 'today') {
    $perfStartDate = date('Y-m-d');
    $perfEndDate = date('Y-m-d');
} elseif ($period === 'this_week') {
    $perfStartDate = date('Y-m-d', strtotime('monday this week'));
    $perfEndDate = date('Y-m-d');
} elseif ($period === 'this_month') {
    $perfStartDate = date('Y-m-01');
    $perfEndDate = date('Y-m-d');
} elseif ($period === 'this_year') {
    $perfStartDate = date('Y-01-01');
    $perfEndDate = date('Y-m-d');
}

$cphReport = PerformanceAnalytics::getCarsPerLaborHour($pdo, $perfStartDate, $perfEndDate);
$bayReport = PerformanceAnalytics::getBayUtilization($pdo, $perfStartDate, $perfEndDate);
$waitReport = PerformanceAnalytics::getAverageWaitByService($pdo, $perfStartDate, $perfEndDate);
$techReport = PerformanceAnalytics::getTechnicianEfficiency7Days($pdo);
$patternReport = PerformanceAnalytics::getBottleneckAndIdlePatterns($pdo, $perfStartDate, $perfEndDate);
$timecardList = TimecardManager::getTimecards($pdo, $perfStartDate, $perfEndDate);

// CSV Export for Bay Utilization
if (isset($_GET['export']) && $_GET['export'] === 'bay_utilization') {
    Auth::requirePermission('reports.export');
    $filename = "CarWash_Bay_Utilization_" . $period . "_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['AQUAGLEAM CAR WASH - BAY UTILIZATION REPORT']);
    fputcsv($out, ['Period:', strtoupper(str_replace('_', ' ', $period)), 'Start Date:', $perfStartDate, 'End Date:', $perfEndDate, 'Days Measured:', $bayReport['days_count'], 'Shop Hours/Day:', $bayReport['daily_open_hours']]);
    fputcsv($out, ['Overall Shop Bay Utilization:', $bayReport['overall_utilization_percent'] . '%', 'Total Busy Mins:', $bayReport['total_busy_minutes'], 'Total Available Mins:', $bayReport['total_available_minutes']]);
    fputcsv($out, []);
    fputcsv($out, ['Bay Number', 'Bay Name (EN)', 'Bay Name (KM)', 'Skill Tag', 'Cars Handled', 'Busy Minutes', 'Available Minutes', 'Utilization %', 'Idle Minutes', 'Idle %', 'Revenue Generated (USD)']);
    foreach ($bayReport['bays'] as $b) {
        fputcsv($out, [
            $b['bay_number'],
            $b['name_en'],
            $b['name_km'],
            $b['skill_tag'],
            $b['orders_count'],
            $b['busy_minutes'],
            $b['available_minutes'],
            $b['utilization_percent'] . '%',
            $b['idle_minutes'],
            $b['idle_percent'] . '%',
            number_format($b['total_usd'], 2)
        ]);
    }
    fclose($out);
    exit;
}

// CSV Export for Technician & Washer Efficiency
if (isset($_GET['export']) && $_GET['export'] === 'tech_efficiency') {
    Auth::requirePermission('reports.export');
    $filename = "CarWash_Tech_Efficiency_" . $period . "_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['AQUAGLEAM CAR WASH - TECHNICIAN & WASHER EFFICIENCY REPORT']);
    fputcsv($out, ['Window:', 'Rolling 7 Days', 'Overall Efficiency:', $techReport['overall_efficiency_percent'] . '%', 'Standard Hours:', $techReport['total_standard_hours'], 'Actual Hours:', $techReport['total_actual_hours']]);
    fputcsv($out, []);
    fputcsv($out, ['Staff ID', 'Staff Member', 'Role', 'Completed Jobs', 'Standard Minutes', 'Actual Minutes', 'Efficiency %', 'Speed Factor']);
    foreach ($techReport['staff'] as $st) {
        fputcsv($out, [
            $st['staff_id'],
            $st['staff_name'],
            strtoupper($st['staff_role']),
            $st['completed_jobs'],
            $st['standard_minutes'],
            $st['actual_minutes'],
            $st['efficiency_percent'] . '%',
            $st['factor'] . 'x'
        ]);
    }
    fclose($out);
    exit;
}

// CSV Export for Bottlenecks & Idle Patterns
if (isset($_GET['export']) && $_GET['export'] === 'bottlenecks') {
    Auth::requirePermission('reports.export');
    $filename = "CarWash_Bottlenecks_Patterns_" . $period . "_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['AQUAGLEAM CAR WASH - BOTTLENECK & IDLE TIME PATTERN REPORT']);
    fputcsv($out, ['Period:', strtoupper(str_replace('_', ' ', $period)), 'Start Date:', $perfStartDate, 'End Date:', $perfEndDate]);
    fputcsv($out, []);
    fputcsv($out, ['--- OPERATIONAL INSIGHTS & BOTTLENECK HIGHLIGHTS ---']);
    foreach ($patternReport['insights'] as $ins) {
        fputcsv($out, [strtoupper($ins['type']), $ins['title'], $ins['text']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['--- PATTERNS BY DAY OF WEEK & HOUR OF DAY ---']);
    fputcsv($out, ['Day of Week', 'Hour (24h)', 'Vehicle Volume', 'Avg Wait (Minutes)', 'Avg Service Duration (Minutes)']);
    foreach ($patternReport['matrix'] as $pm) {
        fputcsv($out, [
            $pm['day_name'],
            sprintf('%02d:00 - %02d:00', $pm['hour_of_day'], $pm['hour_of_day'] + 1),
            $pm['volume'],
            $pm['avg_wait_mins'],
            $pm['avg_service_mins']
        ]);
    }
    fclose($out);
    exit;
}

// CSV Export for Staff Timecards
if (isset($_GET['export']) && $_GET['export'] === 'timecards') {
    Auth::requirePermission('reports.export');
    $filename = "CarWash_Staff_Timecards_" . $period . "_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['AQUAGLEAM CAR WASH - STAFF TIMECARDS & LABOR HOURS LOG']);
    fputcsv($out, ['Period:', strtoupper(str_replace('_', ' ', $period)), 'Start Date:', $perfStartDate, 'End Date:', $perfEndDate, 'Total Labor Hours:', $cphReport['labor_hours'], 'Cars/Labor Hr:', $cphReport['cars_per_labor_hour']]);
    fputcsv($out, []);
    fputcsv($out, ['Timecard UID', 'Staff Member', 'Role', 'Clock In', 'Clock Out', 'Total Hours', 'Status', 'Corrections', 'Latest Correction Reason', 'Corrected By']);
    foreach ($timecardList as $tc) {
        fputcsv($out, [
            $tc['timecard_uid'],
            $tc['staff_name'],
            strtoupper($tc['staff_role']),
            $tc['clock_in'],
            $tc['clock_out'] ?: 'Active / In Progress',
            $tc['total_hours'] !== null ? number_format((float)$tc['total_hours'], 2) : '-',
            strtoupper($tc['status']),
            (int)$tc['correction_count'],
            $tc['latest_correction_reason'] ?: '-',
            $tc['latest_corrected_by'] ?: '-'
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
        'Device Filter:', $selectedDeviceName,
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
    fputcsv($out, ['Total Gross Revenue (Actual Paid)', number_format($grossRev, 2), number_format((int)round($grossRev * $curRate))]);
    fputcsv($out, ['  - Car Wash Services', number_format($washRevenue, 2), number_format((int)round($washRevenue * $curRate))]);
    fputcsv($out, ['  - Repair & Maintenance Labor', number_format($repairRevenue, 2), number_format((int)round($repairRevenue * $curRate))]);
    fputcsv($out, ['  - Products & Spare Parts Sales', number_format($productRevenue, 2), number_format((int)round($productRevenue * $curRate))]);
    fputcsv($out, ['Operating Expenses', number_format($totalExp, 2), number_format((int)round($totalExp * $curRate))]);
    fputcsv($out, ['Staff Commissions Paid', number_format($totalComm, 2), number_format((int)round($totalComm * $curRate))]);
    fputcsv($out, ['  - Washer Commissions', number_format($washerComm, 2), number_format((int)round($washerComm * $curRate))]);
    fputcsv($out, ['  - Mechanic Commissions', number_format($mechanicComm, 2), number_format((int)round($mechanicComm * $curRate))]);
    fputcsv($out, ['NET PROFIT', number_format($netProfit, 2), number_format($netProfitKhr)]);
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

    // Washer Commissions
    fputcsv($out, ['--- WASHER COMMISSIONS ---']);
    fputcsv($out, ['Staff Name', 'Jobs Handled', 'Revenue Handled (USD)', 'Commission Earned (USD)']);
    foreach ($washerBreakdown as $wb) {
        fputcsv($out, [
            $wb['full_name'],
            $wb['jobs_count'],
            number_format((float)$wb['revenue_handled'], 2),
            number_format((float)$wb['commission_earned'], 2)
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

    // Sales by POS Device
    fputcsv($out, ['--- SALES BY POS DEVICE ---']);
    fputcsv($out, ['Device Name', 'Location', 'UID', 'Paid Orders', 'Gross Sales (USD)', 'Discounts (USD)', 'Net Sales (USD)', 'Cash (USD)', 'ABA (USD)', 'KHQR (USD)', 'Unpaid (USD)', 'Cancelled Orders', 'Share (%)']);
    foreach ($allDeviceReportRows as $dr) {
        $devShare = ($devTotalNet > 0) ? round(($dr['net_sales'] / $devTotalNet) * 100, 1) : 0.0;
        fputcsv($out, [
            $dr['device_name'],
            $dr['location'],
            $dr['device_uid'],
            $dr['paid_orders_count'],
            number_format($dr['gross_sales'], 2),
            number_format($dr['total_discount'], 2),
            number_format($dr['net_sales'], 2),
            number_format($dr['cash_total'], 2),
            number_format($dr['aba_total'], 2),
            number_format($dr['khqr_total'], 2),
            number_format($dr['unpaid_total'], 2),
            $dr['cancelled_count'],
            $devShare . '%'
        ]);
    }
    fputcsv($out, [
        'TOTAL',
        '-',
        '-',
        $devTotalPaidOrders,
        number_format($devTotalGross, 2),
        number_format($devTotalDiscount, 2),
        number_format($devTotalNet, 2),
        number_format($devTotalCash, 2),
        number_format($devTotalAba, 2),
        number_format($devTotalKhqr, 2),
        number_format($devTotalUnpaid, 2),
        $devTotalCancelled,
        '100.0%'
    ]);
    fputcsv($out, []);

    // Sales by Cashier / Device x Cashier
    $cashierSectionTitle = ($selectedDevice === 'all') ? '--- SALES BY CASHIER ---' : '--- DEVICE X CASHIER BREAKDOWN (' . $selectedDeviceName . ') ---';
    fputcsv($out, [$cashierSectionTitle]);
    fputcsv($out, ['Cashier Name', 'Role', 'Paid Orders', 'Gross Sales (USD)', 'Discounts (USD)', 'Net Sales (USD)', 'Cash (USD)', 'ABA (USD)', 'KHQR (USD)', 'Unpaid (USD)', 'Cancelled Orders']);
    foreach ($cashierSalesRows as $cr) {
        fputcsv($out, [
            $cr['cashier_name'],
            strtoupper($cr['cashier_role']),
            $cr['paid_orders_count'],
            number_format($cr['gross_sales'], 2),
            number_format($cr['total_discount'], 2),
            number_format($cr['net_sales'], 2),
            number_format($cr['cash_total'], 2),
            number_format($cr['aba_total'], 2),
            number_format($cr['khqr_total'], 2),
            number_format($cr['unpaid_total'], 2),
            $cr['cancelled_count']
        ]);
    }
    fputcsv($out, [
        'TOTAL',
        '-',
        $cashierTotalPaidOrders,
        number_format($cashierTotalGross, 2),
        number_format($cashierTotalDiscount, 2),
        number_format($cashierTotalNet, 2),
        number_format($cashierTotalCash, 2),
        number_format($cashierTotalAba, 2),
        number_format($cashierTotalKhqr, 2),
        number_format($cashierTotalUnpaid, 2),
        $cashierTotalCancelled
    ]);
    fputcsv($out, []);

    // --- Performance: Bay Utilization ---
    fputcsv($out, ['--- BAY UTILIZATION REPORT ---']);
    fputcsv($out, ['Days Measured:', $bayReport['days_count'], 'Daily Shop Hours:', $bayReport['daily_open_hours'], 'Shop Bay Utilization:', $bayReport['overall_utilization_percent'] . '%']);
    fputcsv($out, ['Bay Number', 'Bay Name', 'Skill Tag', 'Cars Handled', 'Busy Minutes', 'Available Minutes', 'Utilization %', 'Idle Minutes', 'Idle %', 'Revenue (USD)']);
    foreach ($bayReport['bays'] as $b) {
        fputcsv($out, [$b['bay_number'], $b['name_en'], $b['skill_tag'], $b['orders_count'], $b['busy_minutes'], $b['available_minutes'], $b['utilization_percent'] . '%', $b['idle_minutes'], $b['idle_percent'] . '%', number_format($b['total_usd'], 2)]);
    }
    fputcsv($out, []);

    // --- Performance: Technician & Washer Efficiency ---
    fputcsv($out, ['--- TECHNICIAN & WASHER EFFICIENCY (ROLLING 7 DAYS) ---']);
    fputcsv($out, ['Overall Efficiency:', $techReport['overall_efficiency_percent'] . '%', 'Total Standard Hours:', $techReport['total_standard_hours'], 'Total Actual Hours:', $techReport['total_actual_hours']]);
    fputcsv($out, ['Staff Name', 'Role', 'Completed Jobs', 'Standard Minutes', 'Actual Minutes', 'Efficiency %', 'Speed Factor']);
    foreach ($techReport['staff'] as $st) {
        fputcsv($out, [$st['staff_name'], strtoupper($st['staff_role']), $st['completed_jobs'], $st['standard_minutes'], $st['actual_minutes'], $st['efficiency_percent'] . '%', $st['factor'] . 'x']);
    }
    fputcsv($out, []);

    // --- Performance: Bottleneck and Idle Patterns ---
    fputcsv($out, ['--- BOTTLENECK & IDLE TIME PATTERN ANALYSIS ---']);
    foreach ($patternReport['insights'] as $ins) {
        fputcsv($out, [strtoupper($ins['type']), $ins['title'], $ins['text']]);
    }
    fputcsv($out, ['Day of Week', 'Hour (24h)', 'Vehicle Volume', 'Avg Wait (Minutes)', 'Avg Service Duration (Minutes)']);
    foreach ($patternReport['matrix'] as $pm) {
        fputcsv($out, [
            $pm['day_name'],
            sprintf('%02d:00 - %02d:00', $pm['hour_of_day'], $pm['hour_of_day'] + 1),
            $pm['volume'],
            $pm['avg_wait_mins'],
            $pm['avg_service_mins']
        ]);
    }
    fputcsv($out, []);

    // --- Performance: Staff Timecards & Labor Hours ---
    fputcsv($out, ['--- STAFF TIMECARDS & LABOR HOURS LOG ---']);
    fputcsv($out, ['Total Labor Hours:', $cphReport['labor_hours'], 'Cars per Labor Hr:', $cphReport['cars_per_labor_hour'], 'Target:', $cphReport['target']]);
    fputcsv($out, ['Timecard UID', 'Staff Member', 'Role', 'Clock In', 'Clock Out', 'Total Hours', 'Status', 'Corrections Count', 'Latest Reason', 'Corrected By']);
    foreach ($timecardList as $tc) {
        fputcsv($out, [
            $tc['timecard_uid'],
            $tc['staff_name'],
            strtoupper($tc['staff_role']),
            $tc['clock_in'],
            $tc['clock_out'] ?: 'Active / In Progress',
            $tc['total_hours'] !== null ? number_format((float)$tc['total_hours'], 2) : '-',
            strtoupper($tc['status']),
            (int)$tc['correction_count'],
            $tc['latest_correction_reason'] ?: '-',
            $tc['latest_corrected_by'] ?: '-'
        ]);
    }

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
    gap: 24px;
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
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 14px 20px;
    box-shadow: var(--shadow-sm);
}

.filter-period-group {
    display: flex;
    align-items: center;
    gap: 6px;
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
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: var(--shadow-sm);
    min-height: 190px;
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
    .tables-dual-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .kpi-grid, 
    .secondary-metrics-grid {
        grid-template-columns: 1fr;
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

<div class="reports-container">

    <!-- 1. Header Bar -->
    <div class="reports-header-bar">
        <div>
            <h1 class="reports-header-title">
                <i class="fa-solid fa-chart-line" style="color: var(--primary);"></i>
                <?= htmlspecialchars(__('nav_reports')) ?> &amp; Profit Control
            </h1>
            <p class="reports-header-sub">
                Complete financial analysis: Revenue, Catalog Subtotal, Discounts, Expenses, Commissions, and True Net Profit.
            </p>
        </div>
        <div class="no-print" style="display: flex; align-items: center; gap: 8px;">
            <span class="badge badge-slate" style="font-size: 12px; padding: 6px 12px;">
                <i class="fa-solid fa-calendar-day mr-1"></i>
                <?= date('d M Y') ?> &bull; 1 USD = <?= number_format($curRate) ?> KHR
            </span>
        </div>
    </div>

    <!-- Print Header Only (Shows when printing) -->
    <div class="print-only" style="display: none; padding: 12px 0 16px 0; border-bottom: 2px solid #333; margin-bottom: 20px;">
        <h2 style="font-size: 20px; font-weight: 800; margin: 0 0 6px 0;">AquaGleam Car Wash - Business &amp; Operations Report</h2>
        <div style="font-size: 12px; color: #555; display: flex; flex-wrap: wrap; gap: 16px;">
            <span><strong>Period:</strong> <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $period))) ?> (<?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?>)</span>
            <span><strong>Device Filter:</strong> <?= htmlspecialchars($selectedDeviceName) ?></span>
            <span><strong>Shop Timezone:</strong> Asia/Phnom_Penh (UTC+7)</span>
            <span><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></span>
        </div>
    </div>

    <!-- 2. Unified Toolbar: Period Filters + Device Filter + Custom Date Range + Separate Actions Menu -->
    <div class="reports-toolbar no-print">
        <div class="filter-period-group">
            <a href="?view=reports&period=today&device=<?= urlencode($selectedDevice) ?>" class="filter-pill <?= ($period === 'today') ? 'active' : '' ?>">
                <i class="fa-solid fa-sun"></i> Today
            </a>
            <a href="?view=reports&period=this_week&device=<?= urlencode($selectedDevice) ?>" class="filter-pill <?= ($period === 'this_week') ? 'active' : '' ?>">
                <i class="fa-solid fa-calendar-week"></i> This Week
            </a>
            <a href="?view=reports&period=this_month&device=<?= urlencode($selectedDevice) ?>" class="filter-pill <?= ($period === 'this_month') ? 'active' : '' ?>">
                <i class="fa-solid fa-calendar-days"></i> This Month
            </a>
            <a href="?view=reports&period=this_year&device=<?= urlencode($selectedDevice) ?>" class="filter-pill <?= ($period === 'this_year') ? 'active' : '' ?>">
                <i class="fa-solid fa-calendar"></i> This Year
            </a>

            <!-- Device Filter Dropdown -->
            <form action="" method="GET" id="deviceFilterForm" style="display: inline-flex; align-items: center; margin: 0 4px;">
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                <?php if ($period === 'custom'): ?>
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                <?php endif; ?>
                <div style="display: flex; align-items: center; gap: 6px; background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-full); padding: 5px 12px;">
                    <i class="fa-solid fa-tablet-screen-button text-muted" style="font-size: 12px;"></i>
                    <select name="device" onchange="document.getElementById('deviceFilterForm').submit()" style="border: none; background: transparent; font-size: 13px; font-weight: 600; color: var(--text-main); outline: none; cursor: pointer;">
                        <option value="all" <?= ($selectedDevice === 'all') ? 'selected' : '' ?>><?= htmlspecialchars(__('all_devices')) ?></option>
                        <option value="web" <?= ($selectedDevice === 'web') ? 'selected' : '' ?>><?= htmlspecialchars(__('back_office_web')) ?></option>
                        <?php foreach ($allRegisteredDevices as $ard): ?>
                            <option value="<?= $ard['id'] ?>" <?= ($selectedDevice == (string)$ard['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ard['device_name']) ?> (<?= htmlspecialchars($ard['device_uid']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <!-- Inline Custom Date Range Picker -->
            <form action="" method="GET" class="custom-range-form">
                <input type="hidden" name="view" value="reports">
                <input type="hidden" name="period" value="custom">
                <input type="hidden" name="device" value="<?= htmlspecialchars($selectedDevice) ?>">
                <div class="date-input-wrap" title="Start Date">
                    <i class="fa-solid fa-arrow-right-to-bracket text-muted"></i>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" required>
                </div>
                <span class="text-muted" style="font-size: 13px;">to</span>
                <div class="date-input-wrap" title="End Date">
                    <i class="fa-solid fa-arrow-right-from-bracket text-muted"></i>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" required>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm" style="padding: 6px 12px; min-height: 34px;">
                    <i class="fa-solid fa-filter mr-1"></i> Apply
                </button>
            </form>
        </div>

        <!-- Separate Actions Menu Group (Export, Print, Backup) -->
        <div class="actions-group">
            <button type="button" class="btn btn-secondary actions-menu-btn" id="reportsActionsBtn" onclick="toggleReportsActions(event)">
                <i class="fa-solid fa-ellipsis-vertical mr-1"></i> Actions <i class="fa-solid fa-chevron-down ml-auto" style="font-size: 11px; margin-left: 6px;"></i>
            </button>
            <div class="actions-dropdown" id="reportsActionsDropdown">
                <?php if (Auth::can('reports.export')): ?>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&device=<?= urlencode($selectedDevice) ?>&export=csv">
                    <i class="fa-solid fa-file-excel" style="color: #16a34a; width: 16px;"></i> Export Full Report (CSV)
                </a>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=bay_utilization">
                    <i class="fa-solid fa-warehouse" style="color: var(--primary); width: 16px;"></i> Bay Utilization (CSV)
                </a>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=tech_efficiency">
                    <i class="fa-solid fa-bolt" style="color: #9333ea; width: 16px;"></i> Technician Efficiency (CSV)
                </a>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=bottlenecks">
                    <i class="fa-solid fa-chart-simple" style="color: #f59e0b; width: 16px;"></i> Bottlenecks &amp; Patterns (CSV)
                </a>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=timecards">
                    <i class="fa-solid fa-clock" style="color: #10b981; width: 16px;"></i> Staff Timecards (CSV)
                </a>
                <?php endif; ?>
                <button type="button" onclick="window.print(); toggleReportsActions();">
                    <i class="fa-solid fa-print" style="color: var(--primary); width: 16px;"></i> Print Report
                </button>
                <?php if (Auth::can('reports.backup')): ?>
                <div class="actions-dropdown-divider"></div>
                <a href="<?= APP_URL ?>/index.php?action=download_backup">
                    <i class="fa-solid fa-database" style="color: #ea580c; width: 16px;"></i> Full Database Backup (.sql)
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 3. Primary KPI Summary Cards: 2 Cards per Row (Balanced, Equal Height) -->
    <div class="kpi-grid">
        <!-- Card 1: Gross Revenue -->
        <div class="kpi-card">
            <div class="kpi-card-top">
                <div>
                    <div class="kpi-card-title">Total Gross Revenue</div>
                    <div class="kpi-card-value" style="color: #16a34a;"><?= formatUSD($grossRev) ?></div>
                    <div class="kpi-card-khr">(<?= formatKHR((int)round($grossRev * $curRate)) ?>)</div>
                </div>
                <div class="kpi-icon-box" style="background: rgba(22, 163, 74, 0.12); color: #16a34a;">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
            </div>
            <div class="kpi-card-footer">
                <div>
                    <span>Orders: <strong><?= $totalVehicles ?> <?= ($totalVehicles === 1) ? 'order' : 'orders' ?></strong></span>
                    <span style="margin: 0 6px;">&bull;</span>
                    <span>Avg Ticket: <strong><?= formatUSD($avgTicket) ?></strong></span>
                </div>
                <?php if ($totalDiscount > 0): ?>
                    <span class="badge badge-warning" title="Catalog Subtotal: <?= formatUSD($totalSubtotal) ?>, Discounts: -<?= formatUSD($totalDiscount) ?>">
                        <i class="fa-solid fa-tag mr-1"></i> -<?= formatUSD($totalDiscount) ?> discounts
                    </span>
                <?php else: ?>
                    <span class="text-xs text-muted">0 discounts</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 2: Operating Expenses -->
        <div class="kpi-card">
            <div class="kpi-card-top">
                <div>
                    <div class="kpi-card-title">Operating Expenses</div>
                    <div class="kpi-card-value" style="color: #d97706;"><?= formatUSD($totalExp) ?></div>
                    <div class="kpi-card-khr">(<?= formatKHR((int)round($totalExp * $curRate)) ?>)</div>
                </div>
                <div class="kpi-icon-box" style="background: rgba(217, 119, 6, 0.12); color: #d97706;">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
            </div>
            <div class="kpi-card-footer">
                <span>Utilities, rent, soap, water &amp; maintenance costs</span>
                <?php $expShare = ($grossRev > 0) ? round(($totalExp / $grossRev) * 100) : 0; ?>
                <span class="text-muted"><strong><?= $expShare ?>%</strong> of revenue</span>
            </div>
        </div>

        <!-- Card 3: Staff Commissions Paid -->
        <div class="kpi-card">
            <div class="kpi-card-top">
                <div>
                    <div class="kpi-card-title">Staff Commissions Paid</div>
                    <div class="kpi-card-value" style="color: #9333ea;"><?= formatUSD($totalComm) ?></div>
                    <div class="kpi-card-khr">(<?= formatKHR((int)round($totalComm * $curRate)) ?>)</div>
                </div>
                <div class="kpi-icon-box" style="background: rgba(147, 51, 234, 0.12); color: #9333ea;">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
            </div>
            <div class="kpi-card-footer">
                <div>
                    <span>Washers: <strong><?= formatUSD($washerComm) ?></strong></span>
                    <span style="margin: 0 6px;">&bull;</span>
                    <span>Mechanics: <strong><?= formatUSD($mechanicComm) ?></strong></span>
                </div>
                <?php $commShare = ($grossRev > 0) ? round(($totalComm / $grossRev) * 100) : 0; ?>
                <span class="text-muted"><strong><?= $commShare ?>%</strong> of revenue</span>
            </div>
        </div>

        <!-- Card 4: Net Profit (True Formula: Gross Revenue - Expenses - Commissions) -->
        <div class="kpi-card <?= ($netProfit >= 0) ? 'profit-positive' : 'profit-negative' ?>">
            <div class="kpi-card-top">
                <div>
                    <div class="kpi-card-title" style="color: <?= ($netProfit >= 0) ? '#16a34a' : '#dc2626' ?>;">
                        True Net Profit
                    </div>
                    <div class="kpi-card-value" style="color: <?= ($netProfit >= 0) ? '#16a34a' : '#dc2626' ?>;">
                        <?= formatUSD($netProfit) ?>
                    </div>
                    <div class="kpi-card-khr" style="color: <?= ($netProfit >= 0) ? '#16a34a' : '#dc2626' ?>;">
                        (<?= formatKHR($netProfitKhr) ?>)
                    </div>
                </div>
                <div class="kpi-icon-box" style="background: <?= ($netProfit >= 0) ? 'rgba(22, 163, 74, 0.15)' : 'rgba(220, 38, 38, 0.15)' ?>; color: <?= ($netProfit >= 0) ? '#16a34a' : '#dc2626' ?>;">
                    <i class="fa-solid <?= ($netProfit >= 0) ? 'fa-trophy' : 'fa-circle-exclamation' ?>"></i>
                </div>
            </div>
            <div class="kpi-card-footer">
                <span>Formula: Revenue (<?= formatUSD($grossRev) ?>) - Exp - Comm</span>
                <?php $netMargin = ($grossRev > 0) ? round(($netProfit / $grossRev) * 100) : 0; ?>
                <span style="font-weight: 700; color: <?= ($netProfit >= 0) ? '#16a34a' : '#dc2626' ?>;">
                    <?= $netMargin ?>% Net Margin
                </span>
            </div>
        </div>
    </div>

    <!-- 4. Visual Comparison Charts: 3 Clean Cards (Responsive, Light & Dark Theme Resilient) -->
    <div class="charts-grid">
        <!-- Chart 1: Financial Balance (Revenue vs Expenses vs Commissions) -->
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3 class="chart-title">
                        <i class="fa-solid fa-scale-balanced" style="color: var(--primary);"></i> Revenue vs. Costs
                    </h3>
                    <div class="chart-subtitle">Outflow vs collected revenue</div>
                </div>
                <span class="badge badge-slate" style="font-size: 11px;">USD Total</span>
            </div>
            <?php 
                $maxBarRef = max(0.01, $grossRev, ($totalExp + $totalComm));
                $revPct = min(100, round(($grossRev / $maxBarRef) * 100));
                $expPct = min(100, round(($totalExp / $maxBarRef) * 100));
                $commPct = min(100, round(($totalComm / $maxBarRef) * 100));
            ?>
            <div class="chart-bars-wrap">
                <div class="chart-bar-item">
                    <div class="chart-bar-meta">
                        <span class="chart-bar-label"><i class="fa-solid fa-circle" style="color: #16a34a; font-size: 9px;"></i> Gross Revenue</span>
                        <strong><?= formatUSD($grossRev) ?></strong>
                    </div>
                    <div class="chart-bar-track">
                        <div class="chart-bar-fill" style="width: <?= $revPct ?>%; background: #16a34a;"></div>
                    </div>
                </div>

                <div class="chart-bar-item">
                    <div class="chart-bar-meta">
                        <span class="chart-bar-label"><i class="fa-solid fa-circle" style="color: #d97706; font-size: 9px;"></i> Operating Expenses</span>
                        <strong><?= formatUSD($totalExp) ?></strong>
                    </div>
                    <div class="chart-bar-track">
                        <div class="chart-bar-fill" style="width: <?= $expPct ?>%; background: #d97706;"></div>
                    </div>
                </div>

                <div class="chart-bar-item">
                    <div class="chart-bar-meta">
                        <span class="chart-bar-label"><i class="fa-solid fa-circle" style="color: #9333ea; font-size: 9px;"></i> Staff Commissions</span>
                        <strong><?= formatUSD($totalComm) ?></strong>
                    </div>
                    <div class="chart-bar-track">
                        <div class="chart-bar-fill" style="width: <?= $commPct ?>%; background: #9333ea;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart 2: Revenue Stream Breakdown (Wash vs Repair vs Parts) -->
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3 class="chart-title">
                        <i class="fa-solid fa-chart-pie" style="color: #0284c7;"></i> Revenue by Department
                    </h3>
                    <div class="chart-subtitle">Wash, Repair, &amp; Products</div>
                </div>
                <span class="badge badge-slate" style="font-size: 11px;">Share</span>
            </div>
            <?php 
                $denomDept = max(0.01, $totalSubtotal > 0 ? $totalSubtotal : $grossRev);
                $washShare = round(($washRevenue / $denomDept) * 100);
                $repairShare = round(($repairRevenue / $denomDept) * 100);
                $partShare = round(($productRevenue / $denomDept) * 100);
            ?>
            <div class="chart-bars-wrap">
                <div class="chart-bar-item">
                    <div class="chart-bar-meta">
                        <span class="chart-bar-label"><i class="fa-solid fa-shower" style="color: #0284c7;"></i> Car Wash</span>
                        <span><?= formatUSD($washRevenue) ?> <strong class="text-muted">(<?= $washShare ?>%)</strong></span>
                    </div>
                    <div class="chart-bar-track">
                        <div class="chart-bar-fill" style="width: <?= min(100, $washShare) ?>%; background: #0284c7;"></div>
                    </div>
                </div>

                <div class="chart-bar-item">
                    <div class="chart-bar-meta">
                        <span class="chart-bar-label"><i class="fa-solid fa-wrench" style="color: #ea580c;"></i> Repair &amp; Labor</span>
                        <span><?= formatUSD($repairRevenue) ?> <strong class="text-muted">(<?= $repairShare ?>%)</strong></span>
                    </div>
                    <div class="chart-bar-track">
                        <div class="chart-bar-fill" style="width: <?= min(100, $repairShare) ?>%; background: #ea580c;"></div>
                    </div>
                </div>

                <div class="chart-bar-item">
                    <div class="chart-bar-meta">
                        <span class="chart-bar-label"><i class="fa-solid fa-box-open" style="color: #9333ea;"></i> Oils &amp; Parts</span>
                        <span><?= formatUSD($productRevenue) ?> <strong class="text-muted">(<?= $partShare ?>%)</strong></span>
                    </div>
                    <div class="chart-bar-track">
                        <div class="chart-bar-fill" style="width: <?= min(100, $partShare) ?>%; background: #9333ea;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart 3: Payment Tender Split (Cash vs ABA vs KHQR) -->
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3 class="chart-title">
                        <i class="fa-solid fa-wallet" style="color: #16a34a;"></i> Payment Tender Split
                    </h3>
                    <div class="chart-subtitle">Breakdown by payment method</div>
                </div>
                <span class="badge badge-slate" style="font-size: 11px;">Tender</span>
            </div>
            <div class="chart-bars-wrap">
                <?php if (empty($payBreakdown)): ?>
                    <div class="empty-state-box" style="padding: 10px;">
                        <div class="empty-state-text">No payment records in this period.</div>
                    </div>
                <?php else: ?>
                    <?php 
                    $methodColors = [
                        'cash' => '#16a34a',
                        'aba'  => '#0284c7',
                        'khqr' => '#dc2626',
                        'unpaid' => '#64748b'
                    ];
                    $methodIcons = [
                        'cash' => 'fa-money-bill-wave',
                        'aba'  => 'fa-building-columns',
                        'khqr' => 'fa-qrcode',
                        'unpaid' => 'fa-clock'
                    ];
                    foreach ($payBreakdown as $pb):
                        $mKey = strtolower((string)($pb['payment_method'] ?? 'unpaid'));
                        $mColor = $methodColors[$mKey] ?? '#64748b';
                        $mIcon = $methodIcons[$mKey] ?? 'fa-wallet';
                        $mShare = ($grossRev > 0) ? round(((float)$pb['method_total'] / $grossRev) * 100) : 0;
                    ?>
                    <div class="chart-bar-item">
                        <div class="chart-bar-meta">
                            <span class="chart-bar-label">
                                <i class="fa-solid <?= $mIcon ?>" style="color: <?= $mColor ?>;"></i>
                                <?= strtoupper($mKey) ?> (<?= $pb['count'] ?> <?= ($pb['count'] === 1) ? 'order' : 'orders' ?>)
                            </span>
                            <span><?= formatUSD((float)$pb['method_total']) ?> <strong class="text-muted">(<?= $mShare ?>%)</strong></span>
                        </div>
                        <div class="chart-bar-track">
                            <div class="chart-bar-fill" style="width: <?= min(100, $mShare) ?>%; background: <?= $mColor ?>;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 5. Secondary Metrics: 2 Balanced Cards (Department Highlights) -->
    <div class="secondary-metrics-grid">
        <div class="metric-pill-card">
            <div>
                <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Minor Repair &amp; Maintenance</div>
                <div style="font-size: 22px; font-weight: 800; color: #ea580c; margin-top: 4px;"><?= formatUSD($repairRevenue) ?></div>
                <div class="text-xs text-muted" style="margin-top: 2px;">
                    <?= count($repairBreakdown) ?> distinct repair service types performed
                </div>
            </div>
            <div class="kpi-icon-box" style="background: rgba(234, 88, 12, 0.12); color: #ea580c;">
                <i class="fa-solid fa-screwdriver-wrench"></i>
            </div>
        </div>

        <div class="metric-pill-card">
            <div>
                <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">Parts &amp; Oil Net Margin</div>
                <div style="font-size: 22px; font-weight: 800; color: #16a34a; margin-top: 4px;"><?= formatUSD($productProfit) ?></div>
                <?php $pMargin = ($productRevenue > 0) ? round(($productProfit / $productRevenue) * 100) : 0; ?>
                <div class="text-xs text-muted" style="margin-top: 2px;">
                    <?= $productUnits ?> items sold &bull; <strong><?= $pMargin ?>%</strong> product profit margin
                </div>
            </div>
            <div class="kpi-icon-box" style="background: rgba(22, 163, 74, 0.12); color: #16a34a;">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
        </div>
    </div>

    <!-- 6. Table: Sales by POS Device Breakdown (All Registered Terminals + Web POS) -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-cash-register" style="color: var(--primary);"></i>
                    <?= htmlspecialchars(__('sales_by_device')) ?>
                </h2>
                <span class="text-xs text-muted">Performance and payment tender reconciliation per connected POS counter terminal and Back Office Web POS</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <?php if (abs($devTotalNet - $grossRev) < 0.01): ?>
                    <span class="badge badge-success" style="font-size: 11.5px; padding: 4px 10px;">
                        <i class="fa-solid fa-circle-check mr-1"></i> <?= htmlspecialchars(__('reconciled_note')) ?> (<?= formatUSD($devTotalNet) ?>)
                    </span>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size: 11.5px; padding: 4px 10px;">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i> Variance: <?= formatUSD(abs($devTotalNet - $grossRev)) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">POS Device / Station</th>
                        <th style="min-width: 110px;">UID</th>
                        <th class="table-num" style="min-width: 100px;">Paid Orders</th>
                        <th class="table-num" style="min-width: 110px;">Gross Sales</th>
                        <th class="table-num" style="min-width: 100px;">Discounts</th>
                        <th class="table-num" style="min-width: 120px;">Net Sales</th>
                        <th class="table-num" style="min-width: 90px;">Cash</th>
                        <th class="table-num" style="min-width: 90px;">ABA</th>
                        <th class="table-num" style="min-width: 90px;">KHQR</th>
                        <th class="table-num" style="min-width: 100px;">Pay Later</th>
                        <th class="table-num" style="min-width: 90px;">Cancelled</th>
                        <th style="min-width: 120px;">Share (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allDeviceReportRows)): ?>
                        <tr>
                            <td colspan="12">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-tablet"></i></div>
                                    <div class="empty-state-text">No device activity recorded in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allDeviceReportRows as $dr): 
                            $sharePct = ($devTotalNet > 0) ? round(($dr['net_sales'] / $devTotalNet) * 100, 1) : 0.0;
                            $isWeb = ($dr['id'] === 0 || $dr['device_uid'] === 'WEB');
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 32px; height: 32px; border-radius: var(--radius-md); background: <?= $isWeb ? '#0284c718' : '#16a34a18' ?>; color: <?= $isWeb ? '#0284c7' : '#16a34a' ?>; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                        <i class="fa-solid <?= $isWeb ? 'fa-globe' : 'fa-tablet-screen-button' ?>"></i>
                                    </div>
                                    <div>
                                        <div style="font-weight: 700; color: var(--text-main);">
                                            <?= htmlspecialchars($dr['device_name']) ?>
                                        </div>
                                        <div class="text-xs text-muted">
                                            <i class="fa-solid fa-location-dot mr-1"></i> <?= htmlspecialchars($dr['location']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $isWeb ? 'badge-info' : 'badge-slate' ?>" style="font-family: var(--font-mono); font-size: 11px;">
                                    <?= htmlspecialchars($dr['device_uid']) ?>
                                </span>
                            </td>
                            <td class="table-num">
                                <strong><?= $dr['paid_orders_count'] ?></strong>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($dr['gross_sales']) ?>
                            </td>
                            <td class="table-num" style="<?= ($dr['total_discount'] > 0) ? 'color: #d97706; font-weight: 600;' : 'color: var(--text-muted);' ?>">
                                <?= ($dr['total_discount'] > 0) ? ('-' . formatUSD($dr['total_discount'])) : '$0.00' ?>
                            </td>
                            <td class="table-num">
                                <strong style="color: #16a34a; font-size: 14px;"><?= formatUSD($dr['net_sales']) ?></strong>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($dr['cash_total']) ?>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($dr['aba_total']) ?>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($dr['khqr_total']) ?>
                            </td>
                            <td class="table-num" style="<?= ($dr['unpaid_total'] > 0) ? 'color: #ea580c; font-weight: 600;' : 'color: var(--text-muted);' ?>">
                                <?= formatUSD($dr['unpaid_total']) ?>
                            </td>
                            <td class="table-num" style="<?= ($dr['cancelled_count'] > 0) ? 'color: #dc2626; font-weight: 600;' : 'color: var(--text-muted);' ?>">
                                <?= $dr['cancelled_count'] ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 40px;">
                                        <div style="height: 100%; width: <?= min(100, $sharePct) ?>%; background: <?= $isWeb ? '#0284c7' : '#16a34a' ?>;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 38px; text-align: right; font-weight: 600;"><?= $sharePct ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Row -->
                        <tr style="background: var(--bg-subtle); font-weight: 700; border-top: 2px solid var(--border);">
                            <td><strong><?= htmlspecialchars(__('total')) ?></strong></td>
                            <td><span class="badge badge-slate" style="font-size: 10px;">ALL</span></td>
                            <td class="table-num"><strong><?= $devTotalPaidOrders ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($devTotalGross) ?></strong></td>
                            <td class="table-num" style="color: #d97706;"><strong>-<?= formatUSD($devTotalDiscount) ?></strong></td>
                            <td class="table-num" style="color: #16a34a; font-size: 14.5px;"><strong><?= formatUSD($devTotalNet) ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($devTotalCash) ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($devTotalAba) ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($devTotalKhqr) ?></strong></td>
                            <td class="table-num" style="color: #ea580c;"><strong><?= formatUSD($devTotalUnpaid) ?></strong></td>
                            <td class="table-num" style="color: #dc2626;"><strong><?= $devTotalCancelled ?></strong></td>
                            <td><strong>100.0%</strong></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 7. Table: Sales by Cashier (or Device x Cashier Breakdown) -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-user-tag" style="color: var(--primary);"></i>
                    <?= ($selectedDevice === 'all') ? htmlspecialchars(__('sales_by_cashier')) : (htmlspecialchars(__('device_cashier_breakdown')) . ': ' . htmlspecialchars($selectedDeviceName)) ?>
                </h2>
                <span class="text-xs text-muted">
                    <?= ($selectedDevice === 'all') ? 'Sales rung up per cashier/staff member across all stations' : ('Cashiers who logged orders specifically on ' . htmlspecialchars($selectedDeviceName)) ?>
                </span>
            </div>
            <div class="text-xs text-muted">
                <?= count($cashierSalesRows) ?> Cashiers on Record
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 200px;">Cashier / Staff Member</th>
                        <th style="min-width: 100px;">Role</th>
                        <th class="table-num" style="min-width: 100px;">Paid Orders</th>
                        <th class="table-num" style="min-width: 110px;">Gross Sales</th>
                        <th class="table-num" style="min-width: 100px;">Discounts</th>
                        <th class="table-num" style="min-width: 120px;">Net Sales</th>
                        <th class="table-num" style="min-width: 90px;">Cash</th>
                        <th class="table-num" style="min-width: 90px;">ABA</th>
                        <th class="table-num" style="min-width: 90px;">KHQR</th>
                        <th class="table-num" style="min-width: 100px;">Pay Later</th>
                        <th class="table-num" style="min-width: 90px;">Cancelled</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cashierSalesRows)): ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-users"></i></div>
                                    <div class="empty-state-text">No cashier sales recorded in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cashierSalesRows as $cr): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="stat-icon blue" style="width: 32px; height: 32px; font-size: 13px; border-radius: 50%;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                    <strong><?= htmlspecialchars($cr['cashier_name']) ?></strong>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-slate" style="text-transform: capitalize; font-size: 11px;">
                                    <?= htmlspecialchars($cr['cashier_role']) ?>
                                </span>
                            </td>
                            <td class="table-num">
                                <strong><?= $cr['paid_orders_count'] ?></strong>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($cr['gross_sales']) ?>
                            </td>
                            <td class="table-num" style="<?= ($cr['total_discount'] > 0) ? 'color: #d97706; font-weight: 600;' : 'color: var(--text-muted);' ?>">
                                <?= ($cr['total_discount'] > 0) ? ('-' . formatUSD($cr['total_discount'])) : '$0.00' ?>
                            </td>
                            <td class="table-num">
                                <strong style="color: #16a34a; font-size: 14px;"><?= formatUSD($cr['net_sales']) ?></strong>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($cr['cash_total']) ?>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($cr['aba_total']) ?>
                            </td>
                            <td class="table-num text-muted">
                                <?= formatUSD($cr['khqr_total']) ?>
                            </td>
                            <td class="table-num" style="<?= ($cr['unpaid_total'] > 0) ? 'color: #ea580c; font-weight: 600;' : 'color: var(--text-muted);' ?>">
                                <?= formatUSD($cr['unpaid_total']) ?>
                            </td>
                            <td class="table-num" style="<?= ($cr['cancelled_count'] > 0) ? 'color: #dc2626; font-weight: 600;' : 'color: var(--text-muted);' ?>">
                                <?= $cr['cancelled_count'] ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Grand Total Row -->
                        <tr style="background: var(--bg-subtle); font-weight: 700; border-top: 2px solid var(--border);">
                            <td><strong><?= htmlspecialchars(__('total')) ?></strong></td>
                            <td>-</td>
                            <td class="table-num"><strong><?= $cashierTotalPaidOrders ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($cashierTotalGross) ?></strong></td>
                            <td class="table-num" style="color: #d97706;"><strong>-<?= formatUSD($cashierTotalDiscount) ?></strong></td>
                            <td class="table-num" style="color: #16a34a; font-size: 14.5px;"><strong><?= formatUSD($cashierTotalNet) ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($cashierTotalCash) ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($cashierTotalAba) ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($cashierTotalKhqr) ?></strong></td>
                            <td class="table-num" style="color: #ea580c;"><strong><?= formatUSD($cashierTotalUnpaid) ?></strong></td>
                            <td class="table-num" style="color: #dc2626;"><strong><?= $cashierTotalCancelled ?></strong></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 8. Table: Revenue Breakdown by Dynamic Category (Proper Names & Icons) -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-tags" style="color: var(--primary);"></i>
                    Revenue Breakdown by Category
                </h2>
                <span class="text-xs text-muted">Aggregated across all service and product categories defined in Category Control</span>
            </div>
            <div class="text-xs text-muted">
                <?= count($categoryRevenueBreakdown) ?> Categories Active
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 240px;">Category</th>
                        <th style="min-width: 120px;">Type</th>
                        <th class="table-num" style="min-width: 140px;">Items Sold / Serviced</th>
                        <th class="table-num" style="min-width: 160px;">Total Revenue</th>
                        <th style="min-width: 180px;">Share of Gross Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categoryRevenueBreakdown)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-tags"></i></div>
                                    <div class="empty-state-text">No category sales recorded in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categoryRevenueBreakdown as $cr): 
                            $cShare = ($grossRev > 0) ? round(((float)$cr['total_revenue'] / $grossRev) * 100) : 0;
                            $cColor = !empty($cr['color']) ? $cr['color'] : '#0284c7';
                            $cIcon = !empty($cr['icon']) ? $cr['icon'] : 'fa-tag';
                            $cName = ($currentLang === 'km') ? $cr['name_km'] : $cr['name_en'];
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 34px; height: 34px; border-radius: var(--radius-md); background: <?= htmlspecialchars($cColor) ?>18; color: <?= htmlspecialchars($cColor) ?>; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; border: 1px solid <?= htmlspecialchars($cColor) ?>35;">
                                        <i class="fa-solid <?= htmlspecialchars($cIcon) ?>"></i>
                                    </div>
                                    <div>
                                        <strong style="color: var(--text-main); font-size: 14px;"><?= htmlspecialchars($cName) ?></strong>
                                        <div class="text-xs text-muted"><?= htmlspecialchars($cr['name_en']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-slate" style="text-transform: uppercase; font-size: 11px;">
                                    <?= htmlspecialchars($cr['type']) ?>
                                </span>
                            </td>
                            <td class="table-num">
                                <strong><?= number_format((int)$cr['item_count']) ?></strong> <?= ($cr['item_count'] === 1) ? 'item' : 'items' ?>
                            </td>
                            <td class="table-num">
                                <strong style="color: var(--text-main); font-size: 14.5px;"><?= formatUSD((float)$cr['total_revenue']) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR((int)round((float)$cr['total_revenue'] * $curRate)) ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; height: 7px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 80px;">
                                        <div style="height: 100%; width: <?= min(100, $cShare) ?>%; background: <?= htmlspecialchars($cColor) ?>;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 36px; text-align: right; font-weight: 600;"><?= $cShare ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 7. Tables Dual Grid: Service Packages & Vehicle Sizes (Side-by-Side on Desktop, Stack on Tablet/Mobile) -->
    <div class="tables-dual-grid">
        <!-- 7a. Wash Service Packages -->
        <div class="report-table-card">
            <div class="report-table-header">
                <div>
                    <h2 class="report-table-title" style="font-size: 15px;">
                        <i class="fa-solid fa-shower" style="color: var(--primary);"></i> Revenue by Wash Service
                    </h2>
                </div>
            </div>
            <div class="table-responsive-clean">
                <table class="table-clean">
                    <thead>
                        <tr>
                            <th>Service Package</th>
                            <th class="table-num">Jobs</th>
                            <th class="table-num">Revenue</th>
                            <th style="min-width: 120px;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($servicesBreakdown)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state-box">
                                        <div class="empty-state-icon"><i class="fa-solid fa-shower"></i></div>
                                        <div class="empty-state-text">No wash services recorded.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($servicesBreakdown as $sb): 
                                $share = ($grossRev > 0) ? round(((float)$sb['service_revenue'] / $grossRev) * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($sb['service_name']) ?></strong></td>
                                <td class="table-num"><?= $sb['qty'] ?></td>
                                <td class="table-num">
                                    <strong><?= formatUSD((float)$sb['service_revenue']) ?></strong>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= min(100, $share) ?>%; background: var(--primary);"></div>
                                        </div>
                                        <span class="text-xs text-muted" style="width: 32px; text-align: right;"><?= $share ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 7b. Income by Vehicle Size -->
        <div class="report-table-card">
            <div class="report-table-header">
                <div>
                    <h2 class="report-table-title" style="font-size: 15px;">
                        <i class="fa-solid fa-car-side" style="color: var(--primary);"></i> Revenue by Vehicle Size
                    </h2>
                </div>
            </div>
            <div class="table-responsive-clean">
                <table class="table-clean">
                    <thead>
                        <tr>
                            <th>Vehicle Size</th>
                            <th class="table-num">Vehicles</th>
                            <th class="table-num">Revenue</th>
                            <th style="min-width: 120px;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vehicleBreakdown)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state-box">
                                        <div class="empty-state-icon"><i class="fa-solid fa-car"></i></div>
                                        <div class="empty-state-text">No vehicle records available.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($vehicleBreakdown as $vb): 
                                $share = ($grossRev > 0) ? round(((float)$vb['v_revenue'] / $grossRev) * 100) : 0;
                            ?>
                            <tr>
                                <td><?= vehicleTypeBadge($vb['vehicle_type']) ?></td>
                                <td class="table-num"><?= $vb['count'] ?></td>
                                <td class="table-num">
                                    <strong><?= formatUSD((float)$vb['v_revenue']) ?></strong>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= min(100, $share) ?>%; background: #16a34a;"></div>
                                        </div>
                                        <span class="text-xs text-muted" style="width: 32px; text-align: right;"><?= $share ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 8. Table: Basic Maintenance & Minor Repair Services -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-screwdriver-wrench" style="color: #ea580c;"></i>
                    Basic Maintenance &amp; Minor Repair Labor
                </h2>
                <span class="text-xs text-muted">Mechanical inspection, tire changes, oil servicing labor, and repairs</span>
            </div>
            <div class="text-xs">
                Total Repair Labor: <strong style="color: #ea580c; font-size: 14px;"><?= formatUSD($repairRevenue) ?></strong>
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 260px;">Repair Service / Labor</th>
                        <th class="table-num" style="min-width: 140px;">Jobs Performed</th>
                        <th class="table-num" style="min-width: 160px;">Labor Revenue</th>
                        <th style="min-width: 180px;">Share of Repairs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($repairBreakdown)): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-wrench"></i></div>
                                    <div class="empty-state-text">No minor repair jobs completed in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($repairBreakdown as $rb): 
                            $rShare = ($repairRevenue > 0) ? round(((float)$rb['service_revenue'] / $repairRevenue) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><i class="fa-solid fa-wrench mr-1" style="color: #ea580c;"></i> <?= htmlspecialchars($rb['service_name']) ?></strong>
                            </td>
                            <td class="table-num">
                                <strong><?= $rb['qty'] ?></strong> <?= ($rb['qty'] === 1) ? 'job' : 'jobs' ?>
                            </td>
                            <td class="table-num">
                                <strong style="color: var(--text-main);"><?= formatUSD((float)$rb['service_revenue']) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR((int)round((float)$rb['service_revenue'] * $curRate)) ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                        <div style="height: 100%; width: <?= min(100, $rShare) ?>%; background: #ea580c;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 34px; text-align: right;"><?= $rShare ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 9. Table: Spare Parts & Car Care Products Sales Breakdown -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-box-open" style="color: #9333ea;"></i>
                    Spare Parts &amp; Car Care Product Sales
                </h2>
                <span class="text-xs text-muted">Stock turnover, acquisition cost (COGS), gross revenue, and gross profit</span>
            </div>
            <div style="display: flex; gap: 16px; font-size: 13px; flex-wrap: wrap;">
                <span>Revenue: <strong style="color: #9333ea;"><?= formatUSD($productRevenue) ?></strong></span>
                <span>COGS: <strong><?= formatUSD($productCogs) ?></strong></span>
                <span>Gross Profit: <strong style="color: #16a34a;"><?= formatUSD($productProfit) ?></strong></span>
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Product / Part</th>
                        <th style="min-width: 160px;">Category</th>
                        <th style="min-width: 120px;">Brand</th>
                        <th class="table-num" style="min-width: 100px;">Units Sold</th>
                        <th class="table-num" style="min-width: 140px;">Sales Revenue</th>
                        <th class="table-num" style="min-width: 120px;">COGS Cost</th>
                        <th class="table-num" style="min-width: 140px;">Gross Profit</th>
                        <th style="min-width: 130px;">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topProducts)): ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-oil-can"></i></div>
                                    <div class="empty-state-text">No engine oil or car care product sales recorded in this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topProducts as $tp): 
                            $sales = (float)$tp['total_sales'];
                            $cost = (float)$tp['total_cost'];
                            $profit = (float)$tp['gross_profit'];
                            $marginP = ($sales > 0) ? round(($profit / $sales) * 100) : 0;
                            $catLabel = ($currentLang === 'km') ? $tp['category_name_km'] : $tp['category_name_en'];
                            $catColor = !empty($tp['category_color']) ? $tp['category_color'] : '#64748b';
                            $catIcon = !empty($tp['category_icon']) ? $tp['category_icon'] : 'fa-box-open';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($tp['name']) ?></strong></td>
                            <td>
                                <span class="badge" style="background: <?= htmlspecialchars($catColor) ?>18; color: <?= htmlspecialchars($catColor) ?>; border: 1px solid <?= htmlspecialchars($catColor) ?>35; font-size: 11px;">
                                    <i class="fa-solid <?= htmlspecialchars($catIcon) ?> mr-1"></i>
                                    <?= htmlspecialchars($catLabel) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($tp['brand']) ?></td>
                            <td class="table-num"><strong><?= (int)$tp['total_qty'] ?></strong></td>
                            <td class="table-num"><strong><?= formatUSD($sales) ?></strong></td>
                            <td class="table-num text-muted"><?= formatUSD($cost) ?></td>
                            <td class="table-num" style="color: #16a34a; font-weight: 700;"><?= formatUSD($profit) ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 40px;">
                                        <div style="height: 100%; width: <?= max(0, min(100, $marginP)) ?>%; background: #0284c7;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 32px; text-align: right;"><?= $marginP ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 10. Table: Washer Production & Net Contribution (Strictly role = 'washer') -->
    <div class="report-table-card">
        <div class="report-table-header">
            <div>
                <h2 class="report-table-title">
                    <i class="fa-solid fa-users-gear" style="color: var(--primary);"></i>
                    Washer Production &amp; Net Contribution
                </h2>
                <span class="text-xs text-muted">Individual crew production, jobs cleaned, commission payout, and net contribution (washers only)</span>
            </div>
            <div class="text-xs text-muted">
                <?= count($washerBreakdown) ?> Washers on Record
            </div>
        </div>
        <div class="table-responsive-clean">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Washer Name</th>
                        <th class="table-num" style="min-width: 140px;">Jobs Cleaned</th>
                        <th class="table-num" style="min-width: 160px;">Revenue Handled</th>
                        <th class="table-num" style="min-width: 160px;">Commission Paid</th>
                        <th class="table-num" style="min-width: 180px;">Shop Net Contribution</th>
                        <th style="min-width: 160px;">Efficiency Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($washerBreakdown)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state-box">
                                    <div class="empty-state-icon"><i class="fa-solid fa-user-group"></i></div>
                                    <div class="empty-state-text">No washer production records found for this period.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($washerBreakdown as $wb): 
                            $revH = (float)$wb['revenue_handled'];
                            $commP = (float)$wb['commission_earned'];
                            $shopNet = $revH - $commP;
                            $eff = ($grossRev > 0) ? round(($revH / $grossRev) * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="stat-icon purple" style="width: 32px; height: 32px; font-size: 14px; border-radius: 50%;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                    <strong><?= htmlspecialchars($wb['full_name']) ?></strong>
                                </div>
                            </td>
                            <td class="table-num">
                                <strong><?= $wb['jobs_count'] ?></strong> <?= ($wb['jobs_count'] === 1) ? 'vehicle' : 'vehicles' ?>
                            </td>
                            <td class="table-num">
                                <strong><?= formatUSD($revH) ?></strong>
                                <div class="text-xs text-muted"><?= formatKHR((int)round($revH * $curRate)) ?></div>
                            </td>
                            <td class="table-num" style="color: #9333ea; font-weight: 700;">
                                <?= formatUSD($commP) ?>
                            </td>
                            <td class="table-num" style="color: #16a34a; font-weight: 700;">
                                <?= formatUSD($shopNet) ?>
                                <div class="text-xs text-muted"><?= formatKHR((int)round($shopNet * $curRate)) ?></div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                        <div style="height: 100%; width: <?= min(100, $eff) ?>%; background: #0284c7;"></div>
                                    </div>
                                    <span class="text-xs text-muted" style="width: 34px; text-align: right;"><?= $eff ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Vehicle Waiting & Washing Time Report -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-stopwatch" style="color: var(--primary);"></i>
                    Vehicle Waiting &amp; Washing Durations
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Tracks arrival-to-start waiting time, wash/service duration in bay, and total turnaround time per vehicle.
                </p>
            </div>
            <div style="display: flex; gap: 10px;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Avg Wait:</span> <strong><?= $avgWaitMins ?> mins</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Avg Service:</span> <strong><?= $avgWashMins ?> mins</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Avg Total:</span> <strong style="color: var(--primary);"><?= $avgTotalMins ?> mins</strong>
                </div>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Vehicle Plate</th>
                        <th style="min-width: 120px;">Customer</th>
                        <th style="min-width: 160px;">Service</th>
                        <th style="min-width: 120px;">Bay Used</th>
                        <th style="min-width: 110px;">Arrived</th>
                        <th style="min-width: 110px;">Started</th>
                        <th style="min-width: 110px;">Finished</th>
                        <th style="min-width: 110px;" class="table-num">Waiting Time</th>
                        <th style="min-width: 110px;" class="table-num">Washing Time</th>
                        <th style="min-width: 110px;" class="table-num">Total Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($serviceTimes)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                No vehicle service duration records found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($serviceTimes as $st): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span style="font-family: var(--font-mono); font-weight: 800; background: var(--bg-subtle); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border);">
                                            <?= htmlspecialchars($st['plate_number']) ?>
                                        </span>
                                        <?= vehicleTypeBadge($st['vehicle_type']) ?>
                                    </div>
                                    <div class="text-xs text-muted" style="margin-top: 2px;">#<?= htmlspecialchars($st['order_number']) ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($st['customer_name'] ?? 'Guest') ?></strong>
                                </td>
                                <td style="font-size: 13px;">
                                    <?= htmlspecialchars($st['service_names'] ?: 'Car Wash') ?>
                                </td>
                                <td>
                                    <?php if (!empty($st['bay_name'])): ?>
                                        <span class="badge badge-primary"><i class="fa-solid fa-warehouse mr-1"></i> <?= htmlspecialchars($st['bay_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-xs text-muted" style="font-family: var(--font-mono);">
                                    <?= !empty($st['arrived_at']) ? date('h:i A', strtotime($st['arrived_at'])) : date('h:i A', strtotime($st['created_at'])) ?>
                                </td>
                                <td class="text-xs text-muted" style="font-family: var(--font-mono);">
                                    <?= !empty($st['started_at']) ? date('h:i A', strtotime($st['started_at'])) : '-' ?>
                                </td>
                                <td class="text-xs text-muted" style="font-family: var(--font-mono);">
                                    <?= !empty($st['finished_at']) ? date('h:i A', strtotime($st['finished_at'])) : (!empty($st['paid_at']) ? date('h:i A', strtotime($st['paid_at'])) : '-') ?>
                                </td>
                                <td class="table-num">
                                    <?php if ($st['wait_minutes'] !== null): ?>
                                        <strong style="color: var(--warning);"><?= $st['wait_minutes'] ?>m</strong>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-num">
                                    <?php if ($st['wash_minutes'] !== null): ?>
                                        <strong style="color: var(--info);"><?= $st['wash_minutes'] ?>m</strong>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-num">
                                    <?php if ($st['total_minutes'] !== null): ?>
                                        <strong style="color: var(--success);"><?= $st['total_minutes'] ?>m</strong>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 1. BAY UTILIZATION REPORT -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-warehouse" style="color: var(--primary);"></i>
                    Bay Utilization Report
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Measures busy minutes vs available capacity across shop bays (<?= $bayReport['daily_open_hours'] ?> operating hrs/day).
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Shop Utilization:</span> <strong style="color: var(--primary); font-size: 14px;"><?= $bayReport['overall_utilization_percent'] ?>%</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Total Busy:</span> <strong><?= $bayReport['total_busy_minutes'] ?> mins</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Total Capacity:</span> <strong><?= $bayReport['total_available_minutes'] ?> mins</strong>
                </div>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=bay_utilization" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                    <i class="fa-solid fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 80px;">Bay #</th>
                        <th style="min-width: 150px;">Bay Name</th>
                        <th style="min-width: 120px;">Skill Tag</th>
                        <th style="min-width: 100px;" class="table-num">Cars Handled</th>
                        <th style="min-width: 110px;" class="table-num">Busy Time</th>
                        <th style="min-width: 110px;" class="table-num">Available Time</th>
                        <th style="min-width: 140px;">Utilization %</th>
                        <th style="min-width: 100px;" class="table-num">Idle Time</th>
                        <th style="min-width: 100px;" class="table-num">Idle %</th>
                        <th style="min-width: 120px;" class="table-num">Revenue (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bayReport['bays'])): ?>
                        <tr><td colspan="10" style="text-align: center; color: var(--text-muted); padding: 24px;">No bays configured.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bayReport['bays'] as $b): ?>
                            <tr>
                                <td><span class="badge badge-slate" style="font-family: var(--font-mono); font-weight: 700;">Bay <?= $b['bay_number'] ?></span></td>
                                <td>
                                    <strong><?= htmlspecialchars($b['name_en']) ?></strong>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($b['name_km']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-info" style="font-size: 10px; text-transform: capitalize;">
                                        <?= htmlspecialchars(str_replace('_', ' ', $b['skill_tag'])) ?>
                                    </span>
                                </td>
                                <td class="table-num" style="font-weight: 700;"><?= $b['orders_count'] ?></td>
                                <td class="table-num"><?= $b['busy_minutes'] ?>m</td>
                                <td class="table-num text-muted"><?= $b['available_minutes'] ?>m</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 8px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                            <div style="height: 100%; width: <?= min(100, $b['utilization_percent']) ?>%; background: <?= $b['utilization_percent'] > 75 ? '#10b981' : ($b['utilization_percent'] < 30 ? '#f59e0b' : 'var(--primary)') ?>;"></div>
                                        </div>
                                        <span style="font-size: 12px; font-weight: 700; font-family: var(--font-mono); width: 44px; text-align: right;">
                                            <?= $b['utilization_percent'] ?>%
                                        </span>
                                    </div>
                                </td>
                                <td class="table-num text-muted"><?= $b['idle_minutes'] ?>m</td>
                                <td class="table-num" style="color: #94a3b8;"><?= $b['idle_percent'] ?>%</td>
                                <td class="table-num" style="font-weight: 700; color: #16a34a;">
                                    <?= formatUSD((float)$b['total_usd']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 2. TECHNICIAN & WASHER EFFICIENCY REPORT -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-bolt" style="color: #9333ea;"></i>
                    Technician &amp; Washer Efficiency Report
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Rolling 7-day ratio of Standard catalog duration vs Actual duration (Standard / Actual). Higher % represents faster execution.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Overall Efficiency:</span> <strong style="color: #9333ea; font-size: 14px;"><?= $techReport['overall_efficiency_percent'] ?>%</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Standard Time:</span> <strong><?= $techReport['total_standard_hours'] ?>h</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Actual Time:</span> <strong><?= $techReport['total_actual_hours'] ?>h</strong>
                </div>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=tech_efficiency" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                    <i class="fa-solid fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 160px;">Staff Member</th>
                        <th style="min-width: 120px;">Role</th>
                        <th style="min-width: 100px;" class="table-num">Completed Jobs</th>
                        <th style="min-width: 120px;" class="table-num">Standard Time</th>
                        <th style="min-width: 120px;" class="table-num">Actual Time</th>
                        <th style="min-width: 140px;">Efficiency %</th>
                        <th style="min-width: 110px;" class="table-num">Speed Factor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($techReport['staff'])): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">No completed staff tasks found in the rolling 7-day window.</td></tr>
                    <?php else: ?>
                        <?php foreach ($techReport['staff'] as $st): 
                            $eff = $st['efficiency_percent'];
                            $badgeColor = $eff >= 105 ? 'badge-success' : ($eff < 90 ? 'badge-warning' : 'badge-info');
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($st['staff_name']) ?></strong>
                                    <div class="text-xs text-muted">@<?= htmlspecialchars($st['username'] ?? '') ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-slate" style="font-size: 10px; text-transform: capitalize;"><?= htmlspecialchars($st['staff_role']) ?></span>
                                </td>
                                <td class="table-num" style="font-weight: 700;"><?= $st['completed_jobs'] ?></td>
                                <td class="table-num text-muted"><?= $st['standard_minutes'] ?>m</td>
                                <td class="table-num" style="font-weight: 600;"><?= $st['actual_minutes'] ?>m</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 8px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; min-width: 60px;">
                                            <div style="height: 100%; width: <?= min(100, $eff) ?>%; background: #9333ea;"></div>
                                        </div>
                                        <span class="badge <?= $badgeColor ?>" style="font-family: var(--font-mono); font-size: 11px;">
                                            <?= $eff ?>%
                                        </span>
                                    </div>
                                </td>
                                <td class="table-num" style="font-family: var(--font-mono); font-weight: 700;">
                                    <?= $st['factor'] ?>x
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 3. BOTTLENECK & IDLE TIME PATTERN REPORT -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-chart-simple" style="color: #f59e0b;"></i>
                    Bottleneck &amp; Idle Time Pattern Report
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Analyzes vehicle volume and wait times by hour of the day and day of the week to reveal bottlenecks and idle bays.
                </p>
            </div>
            <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=bottlenecks" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                <i class="fa-solid fa-file-csv mr-1"></i> CSV
            </a>
        </div>

        <!-- Pattern Insights Alert Cards -->
        <?php if (!empty($patternReport['insights'])): ?>
            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                <?php foreach ($patternReport['insights'] as $ins): ?>
                    <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; border-radius: var(--radius-md); background: var(--bg-subtle); border-left: 4px solid <?= $ins['color'] ?>;">
                        <i class="fa-solid <?= $ins['icon'] ?>" style="color: <?= $ins['color'] ?>; font-size: 16px; margin-top: 2px;"></i>
                        <div>
                            <strong style="color: var(--text-main); font-size: 13.5px;"><?= htmlspecialchars($ins['title']) ?></strong>
                            <div class="text-xs text-muted" style="margin-top: 2px; line-height: 1.5;"><?= htmlspecialchars($ins['text']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Hourly & Day Matrix Table -->
        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Day of Week</th>
                        <th style="min-width: 140px;">Time Window</th>
                        <th style="min-width: 100px;" class="table-num">Vehicle Volume</th>
                        <th style="min-width: 120px;" class="table-num">Avg Customer Wait</th>
                        <th style="min-width: 120px;" class="table-num">Avg Service Duration</th>
                        <th style="min-width: 120px;">Queue Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($patternReport['matrix'])): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No hourly pattern data found for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($patternReport['matrix'] as $pm): 
                            $wait = (float)$pm['avg_wait_mins'];
                            $statusBadge = $wait > 20 ? 'badge-danger' : ($wait > 10 ? 'badge-warning' : 'badge-success');
                            $statusLabel = $wait > 20 ? 'Bottleneck' : ($wait > 10 ? 'Moderate' : 'Smooth Flow');
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($pm['day_name']) ?></strong></td>
                                <td style="font-family: var(--font-mono); font-size: 12.5px;">
                                    <?= sprintf('%02d:00 - %02d:00', $pm['hour_of_day'], $pm['hour_of_day'] + 1) ?>
                                </td>
                                <td class="table-num" style="font-weight: 700;"><?= $pm['volume'] ?> cars</td>
                                <td class="table-num" style="font-weight: 700; color: <?= $wait > 15 ? 'var(--danger)' : 'var(--text-main)' ?>;">
                                    <?= $wait ?>m
                                </td>
                                <td class="table-num text-muted"><?= $pm['avg_service_mins'] ?>m</td>
                                <td>
                                    <span class="badge <?= $statusBadge ?>" style="font-size: 10px;">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 4. STAFF TIMECARDS & LABOR HOURS LOG -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-clock" style="color: #10b981;"></i>
                    Staff Timecards &amp; Labor Hours Log
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Records staff clock-in and clock-out hours. Managers can correct mistakes with a mandatory audit reason.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Total Labor Hours:</span> <strong style="color: #10b981; font-size: 14px;"><?= number_format($cphReport['labor_hours'], 2) ?>h</strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Cars / Labor Hr:</span> <strong style="font-size: 14px;"><?= number_format($cphReport['cars_per_labor_hour'], 2) ?></strong>
                </div>
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Target:</span> <strong><?= number_format($cphReport['target'], 1) ?>/hr</strong>
                </div>
                <a href="?view=reports&period=<?= urlencode($period) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&export=timecards" class="btn btn-secondary btn-sm" title="Download spreadsheet">
                    <i class="fa-solid fa-file-csv mr-1"></i> CSV
                </a>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 120px;">Timecard UID</th>
                        <th style="min-width: 140px;">Staff Member</th>
                        <th style="min-width: 110px;">Role</th>
                        <th style="min-width: 140px;">Clock In</th>
                        <th style="min-width: 140px;">Clock Out</th>
                        <th style="min-width: 100px;" class="table-num">Hours Worked</th>
                        <th style="min-width: 100px;">Status</th>
                        <th style="min-width: 140px;">Notes / Correction</th>
                        <th style="min-width: 90px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($timecardList)): ?>
                        <tr><td colspan="9" style="text-align: center; color: var(--text-muted); padding: 24px;">No staff timecards recorded for this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($timecardList as $tc): 
                            $statusBadge = ($tc['status'] === 'active') ? 'badge-primary' : (($tc['status'] === 'corrected') ? 'badge-warning' : 'badge-success');
                        ?>
                            <tr>
                                <td>
                                    <span style="font-family: var(--font-mono); font-weight: 700; font-size: 12px;"><?= htmlspecialchars($tc['timecard_uid']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($tc['staff_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-slate" style="font-size: 10px; text-transform: capitalize;"><?= htmlspecialchars($tc['staff_role']) ?></span>
                                </td>
                                <td style="font-family: var(--font-mono); font-size: 12px;" class="text-muted">
                                    <?= date('d M, h:i A', strtotime($tc['clock_in'])) ?>
                                </td>
                                <td style="font-family: var(--font-mono); font-size: 12px;" class="text-muted">
                                    <?= $tc['clock_out'] ? date('d M, h:i A', strtotime($tc['clock_out'])) : '<span class="badge badge-info" style="font-size: 10px;">Active</span>' ?>
                                </td>
                                <td class="table-num" style="font-family: var(--font-mono); font-weight: 800; font-size: 14px; color: #10b981;">
                                    <?= $tc['total_hours'] !== null ? number_format((float)$tc['total_hours'], 2) . 'h' : '-' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $statusBadge ?>" style="font-size: 10px; text-transform: capitalize;">
                                        <?= htmlspecialchars($tc['status']) ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px;">
                                    <?php if (!empty($tc['latest_correction_reason'])): ?>
                                        <div style="color: #b45309; font-weight: 600;">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Corrected: <?= htmlspecialchars($tc['latest_correction_reason']) ?>
                                        </div>
                                        <div class="text-xs text-muted">By <?= htmlspecialchars($tc['latest_corrected_by'] ?? 'Manager') ?></div>
                                    <?php elseif (!empty($tc['notes'])): ?>
                                        <span class="text-muted"><?= htmlspecialchars($tc['notes']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="openTimecardCorrectionModal(<?= (int)$tc['id'] ?>, '<?= htmlspecialchars($tc['timecard_uid'], ENT_QUOTES) ?>', '<?= htmlspecialchars($tc['staff_name'], ENT_QUOTES) ?>', '<?= date('Y-m-d\TH:i', strtotime($tc['clock_in'])) ?>', '<?= $tc['clock_out'] ? date('Y-m-d\TH:i', strtotime($tc['clock_out'])) : date('Y-m-d\TH:i') ?>')" title="Correct Timecard (Manager)">
                                        <i class="fa-solid fa-pen-to-square mr-1"></i> Correct
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 5. UPSELL PERFORMANCE & CASHIER CONVERSION REPORT -->
    <?php
    require_once dirname(__DIR__, 2) . '/core/MembershipManager.php';
    $upsellReport = MembershipManager::getUpsellReport($pdo, $startDate, $endDate);
    ?>
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-arrow-trend-up" style="color: #6366f1;"></i>
                    Upsell Performance &amp; Cashier Conversion
                </h3>
                <p class="text-xs text-muted" style="margin-top: 2px;">
                    Tracks how many targeted upsell offers (SUV upgrade, synthetic oil) were presented, accepted, or declined by each cashier.
                </p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <div style="background: var(--bg-subtle); padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px;">
                    <span class="text-muted">Offers Shown:</span> <strong><?= $upsellReport['total_shown'] ?></strong>
                </div>
                <div style="background: #ecfdf5; border: 1px solid #a7f3d0; padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px; color: #065f46;">
                    <span>Accepted:</span> <strong><?= $upsellReport['total_accepted'] ?></strong>
                </div>
                <div style="background: #fff1f2; border: 1px solid #fecdd3; padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px; color: #9f1239;">
                    <span>Declined:</span> <strong><?= $upsellReport['total_declined'] ?></strong>
                </div>
                <div style="background: #eef2ff; border: 1px solid #c7d2fe; padding: 6px 14px; border-radius: var(--radius-sm); font-size: 13px; color: #4338ca;">
                    <span>Conversion Rate:</span> <strong style="font-size: 15px;"><?= $upsellReport['conversion_rate'] ?>%</strong>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 16px;">
            <!-- By Cashier -->
            <div style="border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden;">
                <div style="padding: 10px 14px; background: var(--bg-subtle); font-weight: 700; font-size: 13px; border-bottom: 1px solid var(--border);">
                    <i class="fa-solid fa-user-tag mr-1 text-primary"></i> Conversion Breakdown by Cashier
                </div>
                <table class="table-clean" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Cashier Name</th>
                            <th class="table-num">Shown</th>
                            <th class="table-num">Accepted</th>
                            <th class="table-num">Declined</th>
                            <th class="table-num">Win Rate %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($upsellReport['by_cashier'])): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 16px;">No cashier upsell logs recorded for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($upsellReport['by_cashier'] as $bc): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($bc['cashier_name']) ?></strong></td>
                                    <td class="table-num"><?= $bc['total_offers'] ?></td>
                                    <td class="table-num" style="color: var(--success); font-weight: 700;"><?= $bc['accepted_count'] ?></td>
                                    <td class="table-num text-muted"><?= $bc['declined_count'] ?></td>
                                    <td class="table-num">
                                        <span class="badge <?= $bc['conversion_rate'] >= 40 ? 'badge-success' : ($bc['conversion_rate'] >= 20 ? 'badge-primary' : 'badge-slate') ?>" style="font-size: 11px;">
                                            <?= $bc['conversion_rate'] ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- By Rule -->
            <div style="border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden;">
                <div style="padding: 10px 14px; background: var(--bg-subtle); font-weight: 700; font-size: 13px; border-bottom: 1px solid var(--border);">
                    <i class="fa-solid fa-bullseye mr-1" style="color: #6366f1;"></i> Conversion by Upsell Campaign Rule
                </div>
                <table class="table-clean" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Campaign Rule</th>
                            <th class="table-num">Shown</th>
                            <th class="table-num">Accepted</th>
                            <th class="table-num">Declined</th>
                            <th class="table-num">Success %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($upsellReport['by_rule'])): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 16px;">No rule logs recorded for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($upsellReport['by_rule'] as $br): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($br['rule_name']) ?></strong></td>
                                    <td class="table-num"><?= $br['total_offers'] ?></td>
                                    <td class="table-num" style="color: var(--success); font-weight: 700;"><?= $br['accepted_count'] ?></td>
                                    <td class="table-num text-muted"><?= $br['declined_count'] ?></td>
                                    <td class="table-num">
                                        <span class="badge <?= $br['conversion_rate'] >= 40 ? 'badge-success' : ($br['conversion_rate'] >= 20 ? 'badge-primary' : 'badge-slate') ?>" style="font-size: 11px;">
                                            <?= $br['conversion_rate'] ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Manager Timecard Correction Modal Dialog -->
    <div id="correctionModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); align-items: center; justify-content: center;">
        <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); overflow: hidden; margin: 16px;">
            <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-subtle);">
                <div style="font-weight: 700; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-to-square text-primary"></i>
                    <span>Manager Correction: Staff Timecard</span>
                </div>
                <button type="button" onclick="closeTimecardCorrectionModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);">&times;</button>
            </div>
            <div style="padding: 20px;">
                <div style="margin-bottom: 16px; padding: 12px; background: var(--bg-subtle); border-radius: var(--radius-md);">
                    <div>Staff Member: <strong id="corStaffName">-</strong></div>
                    <div class="text-xs text-muted" style="margin-top: 2px;">UID: <span id="corTimecardUid" style="font-family: var(--font-mono); font-weight: 700;">-</span></div>
                </div>

                <form id="corForm" onsubmit="submitTimecardCorrection(event)">
                    <input type="hidden" id="corTimecardId" name="timecard_id" value="">

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                        <div>
                            <label class="form-label" style="font-size: 12px;">Clock In Time</label>
                            <input type="datetime-local" id="corClockIn" name="clock_in" class="form-control form-control-sm" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 12px;">Clock Out Time</label>
                            <input type="datetime-local" id="corClockOut" name="clock_out" class="form-control form-control-sm" required>
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label class="form-label" style="font-size: 12px;">Reason for Correction <span class="text-danger">* (Mandatory)</span></label>
                        <textarea id="corReason" name="reason" class="form-control form-control-sm" rows="3" placeholder="e.g. Staff forgot to clock out before power outage; actual departure verified by supervisor..." required></textarea>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            This reason is permanently logged in the audit ledger and visible to the Owner.
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="closeTimecardCorrectionModal()">Cancel</button>
                        <button type="submit" id="corSubmitBtn" class="btn btn-primary" style="font-weight: 700;">
                            <i class="fa-solid fa-check mr-1"></i> Save Correction
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openTimecardCorrectionModal(id, uid, staffName, clockIn, clockOut) {
        document.getElementById('corTimecardId').value = id;
        document.getElementById('corTimecardUid').textContent = uid;
        document.getElementById('corStaffName').textContent = staffName;
        document.getElementById('corClockIn').value = clockIn;
        document.getElementById('corClockOut').value = clockOut;
        document.getElementById('corReason').value = '';
        var m = document.getElementById('correctionModal');
        if (m) m.style.display = 'flex';
    }

    function closeTimecardCorrectionModal() {
        var m = document.getElementById('correctionModal');
        if (m) m.style.display = 'none';
    }

    function submitTimecardCorrection(e) {
        e.preventDefault();
        var btn = document.getElementById('corSubmitBtn');
        var reason = document.getElementById('corReason').value.trim();
        if (!reason) {
            alert('A reason is required to correct staff timecards.');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...';

        var formData = new FormData(document.getElementById('corForm'));
        formData.append('csrf_token', '<?= csrf_token() ?>');

        fetch('<?= APP_URL ?>/index.php?action=correct_timecard', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            if (res.success) {
                alert('Timecard corrected successfully! Logged to audit ledger.');
                window.location.reload();
            } else {
                alert(res.message || 'Correction failed.');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            alert('Error: ' + err.message);
        });
    }
    </script>
</div>

<script>
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
