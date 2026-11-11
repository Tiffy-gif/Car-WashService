<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('commissions.list');
$pageTitle = __('nav_commissions', 'Staff Commissions & Wages');
$pdo = Database::getInstance();

// Period Filter: today, this_week, this_month, all
$period = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

$whereClause = "";
$dateParams = [];

if ($period === 'today') {
    $whereClause = "AND DATE(o.created_at) = CURDATE()";
} elseif ($period === 'this_week') {
    $whereClause = "AND YEARWEEK(o.created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period === 'this_month') {
    $whereClause = "AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())";
} elseif ($period === 'custom' && $startDate && $endDate) {
    $whereClause = "AND DATE(o.created_at) BETWEEN ? AND ?";
    $dateParams = [$startDate, $endDate];
}

// 1. Overall stats for selected period (Washers + Mechanics)
$kpiSql = "
    SELECT 
        (COALESCE((SELECT SUM(commission_usd) FROM order_washers ow JOIN orders o ON ow.order_id = o.id WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$whereClause}), 0) +
         COALESCE((SELECT SUM(commission_usd) FROM order_mechanics om JOIN orders o ON om.order_id = o.id WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$whereClause}), 0)) as total_commission,
        COUNT(DISTINCT o.id) as total_jobs,
        COALESCE(SUM(o.total_usd), 0) as total_revenue
    FROM orders o
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$whereClause}
";
$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute($dateParams);
$kpi = $kpiStmt->fetch() ?: ['total_commission' => 0.00, 'total_jobs' => 0, 'total_revenue' => 0.00];

// 2. Fetch all staff (washers & mechanics) and their performance
$staffStmt = $pdo->query("SELECT * FROM users WHERE role IN ('washer', 'mechanic') ORDER BY role DESC, full_name ASC");
$allStaff = $staffStmt->fetchAll();

// Compile data per staff member
$staffStats = [];
foreach ($allStaff as $s) {
    $sId = $s['id'];
    $isMechanic = ($s['role'] === 'mechanic');
    $tbl = $isMechanic ? 'order_mechanics' : 'order_washers';
    $colId = $isMechanic ? 'mechanic_id' : 'washer_id';

    // Stats in selected period
    $periodSql = "
        SELECT 
            COUNT(DISTINCT o.id) as period_jobs,
            COALESCE(SUM(c.commission_usd), 0) as period_commission,
            COALESCE(SUM(o.total_usd), 0) as period_revenue
        FROM orders o
        JOIN {$tbl} c ON o.id = c.order_id
        WHERE c.{$colId} = ? AND (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' {$whereClause}
    ";
    $pStmt = $pdo->prepare($periodSql);
    $pStmt->execute(array_merge([$sId], $dateParams));
    $pData = $pStmt->fetch() ?: ['period_jobs' => 0, 'period_commission' => 0.00, 'period_revenue' => 0.00];

    // Today's earnings
    $todaySql = "
        SELECT COALESCE(SUM(c.commission_usd), 0) as today_earn, COUNT(DISTINCT o.id) as today_jobs
        FROM orders o
        JOIN {$tbl} c ON o.id = c.order_id
        WHERE c.{$colId} = ? AND (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' AND DATE(o.created_at) = CURDATE()
    ";
    $tStmt = $pdo->prepare($todaySql);
    $tStmt->execute([$sId]);
    $tData = $tStmt->fetch() ?: ['today_earn' => 0.00, 'today_jobs' => 0];

    // This month's earnings
    $monthSql = "
        SELECT COALESCE(SUM(c.commission_usd), 0) as month_earn, COUNT(DISTINCT o.id) as month_jobs
        FROM orders o
        JOIN {$tbl} c ON o.id = c.order_id
        WHERE c.{$colId} = ? AND (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' AND MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())
    ";
    $mStmt = $pdo->prepare($monthSql);
    $mStmt->execute([$sId]);
    $mData = $mStmt->fetch() ?: ['month_earn' => 0.00, 'month_jobs' => 0];

    $staffStats[] = [
        'staff' => $s,
        'period_jobs' => (int)$pData['period_jobs'],
        'period_commission' => (float)$pData['period_commission'],
        'period_revenue' => (float)$pData['period_revenue'],
        'today_earn' => (float)$tData['today_earn'],
        'today_jobs' => (int)$tData['today_jobs'],
        'month_earn' => (float)$mData['month_earn'],
        'month_jobs' => (int)$mData['month_jobs'],
    ];
}

// 3. Fetch detailed job logs for modal (washers and mechanics)
$logsSql = "
    SELECT ow.washer_id as staff_id, ow.commission_usd, o.order_number, o.plate_number, o.vehicle_type, o.total_usd, o.created_at, 'Washer' as duty_role
    FROM order_washers ow
    JOIN orders o ON ow.order_id = o.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
    UNION ALL
    SELECT om.mechanic_id as staff_id, om.commission_usd, o.order_number, o.plate_number, o.vehicle_type, o.total_usd, o.created_at, 'Mechanic' as duty_role
    FROM order_mechanics om
    JOIN orders o ON om.order_id = o.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
    ORDER BY created_at DESC
";
$logs = $pdo->query($logsSql)->fetchAll();
$logsByStaff = [];
foreach ($logs as $log) {
    $logsByStaff[$log['staff_id']][] = $log;
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <i class="fa-solid fa-hand-holding-dollar mr-1"></i> Labor &amp; Wage Payouts
        </span>
        <span style="font-size: 13px; color: var(--text-muted);">
            Washer &amp; Mechanic Commission Summary
        </span>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <a href="?view=commissions&period=today" class="btn btn-sm <?= ($period === 'today') ? 'btn-primary' : 'btn-secondary' ?>">Today</a>
        <a href="?view=commissions&period=this_week" class="btn btn-sm <?= ($period === 'this_week') ? 'btn-primary' : 'btn-secondary' ?>">This Week</a>
        <a href="?view=commissions&period=this_month" class="btn btn-sm <?= ($period === 'this_month') ? 'btn-primary' : 'btn-secondary' ?>">This Month</a>
    </div>
</div>

<!-- KPI Metric Cards -->
<div class="grid grid-cols-3" style="margin-bottom: 28px;">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fa-solid fa-coins"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatUSD((float)$kpi['total_commission']) ?></div>
            <div class="stat-label">Total Commission Paid <span class="text-xs text-muted">(<?= formatKHR((int)round((float)$kpi['total_commission'] * get_exchange_rate())) ?>)</span></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fa-solid fa-car-wash"></i>
        </div>
        <div>
            <div class="stat-value"><?= (int)$kpi['total_jobs'] ?></div>
            <div class="stat-label">Total Completed Jobs in Period</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-chart-pie"></i>
        </div>
        <div>
            <?php $avg = ($kpi['total_jobs'] > 0) ? ((float)$kpi['total_commission'] / (int)$kpi['total_jobs']) : 0.00; ?>
            <div class="stat-value"><?= formatUSD($avg) ?></div>
            <div class="stat-label">Avg Commission / Vehicle</div>
        </div>
    </div>
</div>

<!-- Staff Table (Washers & Mechanics) -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-user-group mr-1" style="color: var(--primary);"></i> Staff Commission Summary (Washers &amp; Mechanics)</h2>
        <span class="text-xs text-muted">Filter: <strong><?= strtoupper(str_replace('_', ' ', $period)) ?></strong></span>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Staff Name &amp; Role</th>
                    <th>Phone</th>
                    <th>Commission Rate</th>
                    <th>Jobs Done</th>
                    <th>Revenue Handled</th>
                    <th>Earned Today</th>
                    <th>Earned This Month</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffStats)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 32px; color: var(--text-muted);">No staff found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staffStats as $ss): 
                        $s = $ss['staff'];
                        $isMechanic = ($s['role'] === 'mechanic');
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="font-weight: 700; font-size: 15px; color: var(--text-main);"><?= htmlspecialchars($s['full_name']) ?></div>
                                <?php if ($isMechanic): ?>
                                    <span class="badge" style="background: #ffedd5; color: #ea580c; font-size: 11px;">
                                        <i class="fa-solid fa-wrench mr-1"></i> Mechanic
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-purple" style="font-size: 11px;">
                                        <i class="fa-solid fa-soap mr-1"></i> Washer
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-muted">ID: #<?= $s['id'] ?> &bull; @<?= htmlspecialchars($s['username']) ?></div>
                        </td>
                        <td><?= safeStr($s['phone']) ?></td>
                        <td>
                            <?php if ($s['commission_type'] === 'percentage'): ?>
                                <span class="badge badge-purple"><i class="fa-solid fa-percent mr-1"></i> <?= (float)$s['commission_rate'] ?>% per job</span>
                            <?php else: ?>
                                <span class="badge badge-blue"><i class="fa-solid fa-dollar-sign mr-1"></i> <?= formatUSD((float)$s['commission_rate']) ?> fixed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= $ss['period_jobs'] ?></strong> jobs
                        </td>
                        <td>
                            <strong><?= formatUSD($ss['period_revenue']) ?></strong>
                        </td>
                        <td>
                            <div style="font-weight: 700; color: #16a34a;"><?= formatUSD($ss['today_earn']) ?></div>
                            <div class="text-xs text-muted"><?= $ss['today_jobs'] ?> jobs</div>
                        </td>
                        <td>
                            <div style="font-weight: 800; color: var(--primary); font-size: 15px;"><?= formatUSD($ss['month_earn']) ?></div>
                            <div class="text-xs text-muted"><?= formatKHR((int)round($ss['month_earn'] * get_exchange_rate())) ?></div>
                        </td>
                        <td style="text-align: right;">
                            <button type="button" class="btn btn-secondary btn-sm"
                                    data-staff-name="<?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-logs="<?= htmlspecialchars(json_encode($logsByStaff[$s['id']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                                    onclick="viewJobHistory(this)">
                                <i class="fa-solid fa-clock-rotate-left mr-1"></i> Job History
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Washer Job History -->
<div class="modal-backdrop" id="historyModal">
    <div class="modal" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title" id="historyModalTitle"><i class="fa-solid fa-list mr-1" style="color: var(--primary);"></i> Washer Job History</h3>
            <button type="button" class="modal-close" onclick="closeModal('historyModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Plate</th>
                            <th>Vehicle</th>
                            <th>Order Total</th>
                            <th>Earned</th>
                            <th>Date/Time</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('historyModal')">Close</button>
        </div>
    </div>
</div>

<script>
function viewJobHistory(btnOrWasherName, logs) {
    let washerName = btnOrWasherName;
    if (btnOrWasherName && btnOrWasherName.getAttribute && btnOrWasherName.getAttribute('data-staff-name')) {
        washerName = btnOrWasherName.getAttribute('data-staff-name');
        try {
            logs = JSON.parse(btnOrWasherName.getAttribute('data-logs') || '[]');
        } catch (e) {
            logs = [];
        }
    }
    document.getElementById('historyModalTitle').innerText = 'Jobs by: ' + washerName;
    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '';

    if (!logs || logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No completed jobs recorded yet.</td></tr>';
    } else {
        logs.forEach(l => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${l.order_number}</strong></td>
                <td><span style="font-family: var(--font-mono); font-weight: 700;">${l.plate_number}</span></td>
                <td>${l.vehicle_type}</td>
                <td>$${parseFloat(l.total_usd).toFixed(2)}</td>
                <td><strong style="color: #16a34a;">$${parseFloat(l.commission_usd).toFixed(2)}</strong></td>
                <td class="text-xs text-muted">${l.created_at}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    openModal('historyModal');
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
