<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('dashboard.view');
$pageTitle = __('nav_dashboard');
$pdo = Database::getInstance();

// Stats queries
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$totalVehicles = (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
$activeServices = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE is_active = 1")->fetchColumn();

// Today's orders & revenue (Net Settled Revenue: Confirmed Gross Payments - Settled Refunds)
$todayOrdersStmt = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_usd), 0) as revenue_usd, COALESCE(SUM(total_khr), 0) as revenue_khr FROM orders WHERE DATE(COALESCE(paid_at, created_at)) = CURDATE() AND (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled'");
$todayStats = $todayOrdersStmt->fetch() ?: ['count' => 0, 'revenue_usd' => 0.00, 'revenue_khr' => 0];

$hasRefundCols = false;
try {
    $pdo->query("SELECT refund_amount_usd FROM orders LIMIT 0");
    $hasRefundCols = true;
} catch (Throwable $e) {}

$todayRefundUsd = 0.00;
if ($hasRefundCols) {
    try {
        $todayRefundUsd = (float)$pdo->query("
            SELECT COALESCE(SUM(
                CASE 
                    WHEN refund_amount_usd > 0 THEN refund_amount_usd
                    WHEN refund_status = 'refunded' OR payment_status = 'refunded' THEN total_usd
                    ELSE 0
                END
            ), 0)
            FROM orders
            WHERE DATE(COALESCE(refunded_at, paid_at, created_at)) = CURDATE() AND status != 'cancelled'
        ")->fetchColumn();
    } catch (Throwable $e) {}
}
$todayGrossUsd = (float)$todayStats['revenue_usd'];
$todayNetUsd = max(0.0, $todayGrossUsd - $todayRefundUsd);
$todayNetKhr = (int)round($todayNetUsd * get_exchange_rate());

// Order status breakdown
$statusCountsStmt = $pdo->query("SELECT status, COUNT(*) as count FROM orders WHERE DATE(created_at) = CURDATE() GROUP BY status");
$statusCounts = ['waiting' => 0, 'washing' => 0, 'finished' => 0, 'paid' => 0, 'cancelled' => 0];
while ($row = $statusCountsStmt->fetch()) {
    $statusCounts[$row['status']] = (int)$row['count'];
}

// Recent orders
$recentOrders = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5")->fetchAll();

// Today's Sales by POS Device & Web
$todayDeviceStmt = $pdo->query("
    SELECT 
        CASE 
            WHEN o.pos_device_id IS NULL OR o.pos_device_id = 0 OR o.source = 'web' THEN 'web'
            ELSE 'pos'
        END as device_type,
        COALESCE(o.pos_device_id, 0) as pos_device_id,
        COALESCE(pd.device_name, o.pos_device_name, 'Back Office (Web POS)') as device_name,
        COALESCE(pd.location, 'Front Desk') as location,
        COALESCE(pd.device_uid, 'WEB') as device_uid,
        COUNT(CASE WHEN (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' THEN 1 END) as paid_orders_count,
        COALESCE(SUM(CASE WHEN (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' THEN o.total_usd ELSE 0 END), 0) as total_sales_usd,
        COALESCE(SUM(CASE WHEN (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' THEN o.total_khr ELSE 0 END), 0) as total_sales_khr
    FROM orders o
    LEFT JOIN pos_devices pd ON o.pos_device_id = pd.id
    WHERE DATE(COALESCE(o.paid_at, o.created_at)) = CURDATE()
    GROUP BY 
        CASE 
            WHEN o.pos_device_id IS NULL OR o.pos_device_id = 0 OR o.source = 'web' THEN 0 
            ELSE o.pos_device_id 
        END,
        pd.device_name, o.pos_device_name, pd.location, pd.device_uid
    ORDER BY total_sales_usd DESC
");
$todayDeviceSales = $todayDeviceStmt->fetchAll(PDO::FETCH_ASSOC);

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; width: 100%;">
    <div style="min-width: 0;">
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars(__('welcome_back')) ?>, <?= htmlspecialchars(Auth::getFullName() ?? 'Owner') ?></h1>
        <p style="font-size: 14px; color: var(--text-muted);"><?= date('l, d F Y') ?> • Overview of Car Wash operations and shop performance.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; max-width: 100%;">
        <a href="<?= get_pos_url() ?>" class="btn btn-primary" style="flex-shrink: 0;">
            <i class="fa-solid fa-cash-register mr-1"></i> <?= htmlspecialchars(__('nav_pos')) ?>
        </a>
        <a href="<?= APP_URL ?>/index.php?action=download_backup" class="btn btn-secondary" title="Download complete database backup SQL" style="flex-shrink: 0;">
            <i class="fa-solid fa-database mr-1" style="color: var(--primary);"></i> Backup Data
        </a>
        <a href="<?= APP_URL ?>/index.php?view=reports" class="btn btn-secondary" style="flex-shrink: 0;">
            <i class="fa-solid fa-chart-line mr-1"></i> Reports
        </a>
        <a href="<?= APP_URL ?>/index.php?view=staff" class="btn btn-secondary" style="flex-shrink: 0;">
            <i class="fa-solid fa-users-gear mr-1"></i> Staff
        </a>
    </div>
</div>

<?php
$dashSettings = [];
try {
    $dsStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($dr = $dsStmt->fetch()) {
        $dashSettings[$dr['setting_key']] = $dr['setting_value'];
    }
} catch (Exception $e) {}
$dashDismissed = ($dashSettings['default_pw_dismissed'] ?? '0') === '1';

// Check if default password for owner/cashier is still active
$hasDefaultPw = false;
$uCheck = $pdo->query("SELECT username, password_hash FROM users WHERE username IN ('owner', 'cashier') AND status = 'active'")->fetchAll();
foreach ($uCheck as $uc) {
    if (($uc['username'] === 'owner' && password_verify('admin123', $uc['password_hash'])) ||
        ($uc['username'] === 'cashier' && password_verify('cashier123', $uc['password_hash']))) {
        $hasDefaultPw = true;
        break;
    }
}
?>

<?php if ($hasDefaultPw && !$dashDismissed): ?>
    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 14px 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fa-solid fa-triangle-exclamation" style="color: #d97706; font-size: 18px;"></i>
            <span style="font-size: 13.5px; color: #92400e; font-weight: 600;">
                Security Notice: Default sample passwords ('admin123', 'cashier123') are still in use. Please change them before operating with real money.
            </span>
        </div>
        <a href="<?= APP_URL ?>/index.php?view=staff" class="btn btn-primary btn-sm" style="font-size: 12px; background: #d97706; border-color: #d97706;">
            <i class="fa-solid fa-key mr-1"></i> Manage Staff &amp; Passwords
        </a>
    </div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="grid grid-cols-4" style="margin-bottom: 28px;">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fa-solid fa-sack-dollar"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatUSD($todayNetUsd) ?></div>
            <div class="stat-label">
                <?= htmlspecialchars(__('today_income')) ?> <span class="text-xs text-muted">(<?= formatKHR($todayNetKhr) ?>)</span>
                <?php if ($todayRefundUsd > 0): ?>
                    <span class="badge badge-warning" style="font-size: 10px; margin-left: 4px;" title="Gross: <?= formatUSD($todayGrossUsd) ?>, Refunds: -<?= formatUSD($todayRefundUsd) ?>">Net</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fa-solid fa-car-on"></i>
        </div>
        <div>
            <div class="stat-value"><?= (int)$todayStats['count'] ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('today_orders')) ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-users"></i>
        </div>
        <div>
            <div class="stat-value"><?= $totalCustomers ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('total_customers')) ?> (<?= $totalVehicles ?> cars/bikes)</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fa-solid fa-spray-can-sparkles"></i>
        </div>
        <div>
            <div class="stat-value"><?= $activeServices ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('active_services')) ?></div>
        </div>
    </div>
</div>

<?php
$userRole = Auth::getRole();
$isManagement = in_array($userRole, ['owner', 'manager'], true);

if ($isManagement):
    require_once dirname(__DIR__, 2) . '/core/PerformanceAnalytics.php';
    $todayDate = date('Y-m-d');
    $kpiCarsPerHour = PerformanceAnalytics::getCarsPerLaborHour($pdo, $todayDate, $todayDate);
    $kpiBayUtil = PerformanceAnalytics::getBayUtilization($pdo, $todayDate, $todayDate);
    $kpiWaitByService = PerformanceAnalytics::getAverageWaitByService($pdo, $todayDate, $todayDate);
    $kpiTechEff = PerformanceAnalytics::getTechnicianEfficiency7Days($pdo);
    $leaderboardData = PerformanceAnalytics::getStaffLeaderboard($pdo, date('Y-m-01'), $todayDate);
?>
<!-- Live Performance & Productivity KPIs (Restricted to Manager and Owner) -->
<div class="card" style="margin-bottom: 28px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 class="card-title" style="margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-gauge-high" style="color: var(--primary);"></i>
                Live Shop Productivity &amp; Bay KPIs
            </h2>
            <div class="text-xs text-muted" style="margin-top: 2px;">
                Real-time operational efficiency metrics for today (<?= date('d M Y') ?>) • Restricted to Management
            </div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <span class="badge badge-success" style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px;">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #ffffff;"></span>
                Live Sync
            </span>
            <a href="<?= APP_URL ?>/index.php?view=reports&period=today" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-chart-line mr-1"></i> Performance Reports &rarr;
            </a>
        </div>
    </div>

    <div class="grid grid-cols-4" style="gap: 16px; margin-top: 14px;">
        <!-- KPI 1: Cars per Labor Hour -->
        <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Cars / Labor Hr</div>
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(16, 185, 129, 0.12); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                        <i class="fa-solid fa-users-viewfinder"></i>
                    </div>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: var(--text-main); font-family: var(--font-mono);">
                    <?= number_format($kpiCarsPerHour['cars_per_labor_hour'], 2) ?>
                    <span style="font-size: 13px; font-weight: 500; color: var(--text-muted);">cars/hr</span>
                </div>
            </div>
            <div style="margin-top: 14px; padding-top: 8px; border-top: 1px dashed var(--border); font-size: 12px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span class="text-muted">Target: <strong><?= number_format($kpiCarsPerHour['target'], 1) ?>/hr</strong></span>
                    <?php if ($kpiCarsPerHour['variance'] >= 0): ?>
                        <span class="badge badge-success" style="font-size: 10px;">+<?= $kpiCarsPerHour['variance'] ?> vs target</span>
                    <?php else: ?>
                        <span class="badge badge-warning" style="font-size: 10px;"><?= $kpiCarsPerHour['variance'] ?> vs target</span>
                    <?php endif; ?>
                </div>
                <div class="text-xs text-muted">
                    <?= $kpiCarsPerHour['total_cars'] ?> cars served / <?= $kpiCarsPerHour['labor_hours'] ?> labor hrs
                </div>
            </div>
        </div>

        <!-- KPI 2: Bay Utilization % -->
        <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Bay Utilization</div>
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(2, 132, 199, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 14px;">
                        <i class="fa-solid fa-warehouse"></i>
                    </div>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: var(--primary); font-family: var(--font-mono);">
                    <?= $kpiBayUtil['overall_utilization_percent'] ?>%
                </div>
            </div>
            <div style="margin-top: 14px; padding-top: 8px; border-top: 1px dashed var(--border); font-size: 12px;">
                <div style="width: 100%; height: 6px; background: var(--bg-subtle); border-radius: 99px; overflow: hidden; margin-bottom: 6px;">
                    <div style="height: 100%; width: <?= min(100, $kpiBayUtil['overall_utilization_percent']) ?>%; background: var(--primary);"></div>
                </div>
                <div class="text-xs text-muted" style="display: flex; justify-content: space-between;">
                    <span><?= $kpiBayUtil['total_busy_minutes'] ?>m busy</span>
                    <span><?= $kpiBayUtil['total_available_minutes'] ?>m capacity</span>
                </div>
            </div>
        </div>

        <!-- KPI 3: Average Wait Time by Service Type -->
        <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Avg Wait Time</div>
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(245, 158, 11, 0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                        <i class="fa-solid fa-stopwatch"></i>
                    </div>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: var(--text-main); font-family: var(--font-mono);">
                    <?= $kpiWaitByService['overall_avg_wait'] ?>
                    <span style="font-size: 13px; font-weight: 500; color: var(--text-muted);">mins</span>
                </div>
            </div>
            <div style="margin-top: 14px; padding-top: 8px; border-top: 1px dashed var(--border); font-size: 11px;">
                <?php if (!empty($kpiWaitByService['services'])): ?>
                    <?php 
                    $top2Svc = array_slice($kpiWaitByService['services'], 0, 2);
                    foreach ($top2Svc as $sItem): 
                    ?>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                            <span class="text-muted" style="max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($sItem['name_en']) ?>:</span>
                            <strong><?= $sItem['avg_wait_minutes'] ?>m</strong>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted">Arrival to bay queue wait</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI 4: Technician Efficiency (Rolling 7 Days) -->
        <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Tech Efficiency</div>
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(147, 51, 234, 0.12); color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                        <i class="fa-solid fa-bolt"></i>
                    </div>
                </div>
                <div style="font-size: 26px; font-weight: 800; color: #9333ea; font-family: var(--font-mono);">
                    <?= $kpiTechEff['overall_efficiency_percent'] ?>%
                </div>
            </div>
            <div style="margin-top: 14px; padding-top: 8px; border-top: 1px dashed var(--border); font-size: 12px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span class="text-muted">Rolling 7-day factor:</span>
                    <span class="badge badge-purple" style="font-size: 10px;"><?= $kpiTechEff['overall_efficiency_percent'] >= 100 ? 'On Standard' : 'Extended' ?></span>
                </div>
                <div class="text-xs text-muted">
                    <?= $kpiTechEff['total_standard_hours'] ?>h standard vs <?= $kpiTechEff['total_actual_hours'] ?>h actual
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Optional Staff Leaderboard Widget (if enabled and role is permitted) -->
<?php if (!empty($leaderboardData['enabled']) && in_array($userRole, $leaderboardData['visible_roles'] ?? [], true)): ?>
<div class="card" style="margin-bottom: 28px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i>
            Staff Performance Leaderboard
        </h2>
        <a href="<?= APP_URL ?>/index.php?view=settings&tab=performance" class="btn btn-secondary btn-sm" title="Configure visible metrics and visibility">
            <i class="fa-solid fa-gear mr-1"></i> Leaderboard Settings
        </a>
    </div>

    <?php if (empty($leaderboardData['leaderboard'])): ?>
        <div style="text-align: center; color: var(--text-muted); padding: 24px;">
            <i class="fa-solid fa-medal fa-2x" style="opacity: 0.4; margin-bottom: 8px; display: block;"></i>
            No staff shift activity recorded yet for this ranking period.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 70px;">Rank</th>
                        <th>Staff Member</th>
                        <th>Role</th>
                        <?php if (in_array('jobs_completed', $leaderboardData['metrics'], true)): ?>
                            <th class="table-num" style="min-width: 140px;">Completed Jobs</th>
                        <?php endif; ?>
                        <?php if (in_array('cars_per_hour', $leaderboardData['metrics'], true)): ?>
                            <th class="table-num" style="min-width: 130px;">Labor Hours</th>
                            <th class="table-num" style="min-width: 150px;">Cars / Labor Hr</th>
                        <?php endif; ?>
                        <?php if (in_array('efficiency_factor', $leaderboardData['metrics'], true)): ?>
                            <th class="table-num" style="min-width: 120px;">Efficiency %</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaderboardData['leaderboard'] as $lb): ?>
                        <tr>
                            <td>
                                <?php if ($lb['rank'] === 1): ?>
                                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #fef3c7; color: #d97706; font-weight: 800; font-size: 14px;">🥇</span>
                                <?php elseif ($lb['rank'] === 2): ?>
                                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #f1f5f9; color: #64748b; font-weight: 800; font-size: 14px;">🥈</span>
                                <?php elseif ($lb['rank'] === 3): ?>
                                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #ffedd5; color: #c2410c; font-weight: 800; font-size: 14px;">🥉</span>
                                <?php else: ?>
                                    <span style="font-weight: 700; color: var(--text-muted); font-size: 13px; margin-left: 8px;">#<?= $lb['rank'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: var(--text-main); font-size: 14px;"><?= htmlspecialchars($lb['full_name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-slate" style="font-size: 11px; text-transform: capitalize;"><?= htmlspecialchars($lb['role']) ?></span>
                            </td>
                            <?php if (in_array('jobs_completed', $leaderboardData['metrics'], true)): ?>
                                <td class="table-num" style="font-weight: 700;"><?= $lb['cars_completed'] ?></td>
                            <?php endif; ?>
                            <?php if (in_array('cars_per_hour', $leaderboardData['metrics'], true)): ?>
                                <td class="table-num text-muted"><?= number_format($lb['labor_hours'], 2) ?>h</td>
                                <td class="table-num" style="font-weight: 800; color: #10b981; font-family: var(--font-mono); font-size: 15px;">
                                    <?= number_format($lb['cars_per_hour'], 2) ?>
                                </td>
                            <?php endif; ?>
                            <?php if (in_array('efficiency_factor', $leaderboardData['metrics'], true)): ?>
                                <td class="table-num" style="font-weight: 700; color: #9333ea;">
                                    <?= number_format($lb['efficiency_percent'], 1) ?>%
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Live Wash Queue Status -->
<div class="card" style="margin-bottom: 28px;">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-arrows-spin mr-1" style="color: var(--primary);"></i> Current Wash Bay Status</h2>
        <a href="<?= APP_URL ?>/index.php?view=orders" class="btn btn-secondary btn-sm">View Full Queue</a>
    </div>

    <div class="grid grid-cols-4" style="gap: 16px;">
        <div style="background: var(--warning-bg); border: 1px solid var(--border); padding: 20px; border-radius: var(--radius-md); text-align: center;">
            <div style="font-size: 28px; font-weight: 800; color: var(--warning);"><?= $statusCounts['waiting'] ?></div>
            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-top: 4px;"><?= htmlspecialchars(__('status_waiting')) ?></div>
            <div class="text-xs text-muted">Awaiting Bay Assignment</div>
        </div>

        <div style="background: var(--info-bg); border: 1px solid var(--border); padding: 20px; border-radius: var(--radius-md); text-align: center;">
            <div style="font-size: 28px; font-weight: 800; color: var(--info);"><?= $statusCounts['washing'] ?></div>
            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-top: 4px;"><?= htmlspecialchars(__('status_washing')) ?></div>
            <div class="text-xs text-muted">Currently In Progress</div>
        </div>

        <div style="background: var(--success-bg); border: 1px solid var(--border); padding: 20px; border-radius: var(--radius-md); text-align: center;">
            <div style="font-size: 28px; font-weight: 800; color: var(--success);"><?= $statusCounts['finished'] ?></div>
            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-top: 4px;"><?= htmlspecialchars(__('status_finished')) ?></div>
            <div class="text-xs text-muted">Ready for Customer Pickup</div>
        </div>

        <div style="background: var(--bg-subtle); border: 1px solid var(--border); padding: 20px; border-radius: var(--radius-md); text-align: center;">
            <div style="font-size: 28px; font-weight: 800; color: var(--primary);"><?= $statusCounts['paid'] ?></div>
            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-top: 4px;"><?= htmlspecialchars(__('status_paid')) ?></div>
            <div class="text-xs text-muted">Completed & Settled</div>
        </div>
    </div>
</div>

<!-- Today by POS Device -->
<div class="card" style="margin-bottom: 28px;">
    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
        <h2 class="card-title" style="margin: 0;">
            <i class="fa-solid fa-cash-register mr-1" style="color: var(--primary);"></i> 
            <?= htmlspecialchars(__('today_by_device')) ?>
        </h2>
        <a href="<?= APP_URL ?>/index.php?view=reports&period=today" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-chart-pie mr-1"></i> <?= htmlspecialchars(__('sales_by_device')) ?> &rarr;
        </a>
    </div>

    <?php if (empty($todayDeviceSales)): ?>
        <div style="text-align: center; color: var(--text-muted); padding: 24px;">
            <i class="fa-solid fa-inbox fa-2x" style="opacity: 0.5; margin-bottom: 8px; display: block;"></i>
            <?= htmlspecialchars(__('no_data')) ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-3" style="gap: 16px;">
            <?php foreach ($todayDeviceSales as $ds): ?>
                <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                            <span class="badge <?= $ds['device_type'] === 'web' ? 'badge-info' : 'badge-primary' ?>" style="font-size: 11px;">
                                <i class="fa-solid <?= $ds['device_type'] === 'web' ? 'fa-globe' : 'fa-tablet-screen-button' ?> mr-1"></i>
                                <?= htmlspecialchars($ds['device_uid']) ?>
                            </span>
                            <span class="text-xs text-muted">
                                <?= htmlspecialchars($ds['location']) ?>
                            </span>
                        </div>
                        <div style="font-weight: 700; font-size: 15px; color: var(--text-main); margin-bottom: 4px;">
                            <?= htmlspecialchars($ds['device_name']) ?>
                        </div>
                    </div>

                    <div style="margin-top: 16px; pt-3; border-top: 1px dashed var(--border); display: flex; justify-content: space-between; align-items: flex-end;">
                        <div>
                            <div class="text-xs text-muted"><?= htmlspecialchars(__('orders')) ?></div>
                            <div style="font-size: 16px; font-weight: 700; color: var(--text-main);">
                                <?= (int)$ds['paid_orders_count'] ?>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div class="text-xs text-muted"><?= htmlspecialchars(__('revenue')) ?></div>
                            <div style="font-size: 18px; font-weight: 800; color: var(--success);">
                                <?= formatUSD((float)$ds['total_sales_usd']) ?>
                            </div>
                            <div class="text-xs text-muted">
                                <?= formatKHR((float)$ds['total_sales_khr']) ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 12px; text-align: right;">
                        <a href="<?= APP_URL ?>/index.php?view=reports&period=today&device=<?= urlencode((string)($ds['device_type'] === 'web' ? 'web' : $ds['pos_device_id'])) ?>" 
                           class="text-xs font-semibold" style="color: var(--primary); text-decoration: none;">
                            <?= htmlspecialchars(__('details')) ?> &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Recent Orders Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-clock-rotate-left mr-1" style="color: var(--primary);"></i> Recent Washing Orders</h2>
        <a href="<?= APP_URL ?>/index.php?view=orders" class="text-sm" style="color: var(--primary); font-weight: 600;">View All Orders &rarr;</a>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Vehicle Plate</th>
                    <th>Vehicle Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentOrders)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No orders today yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentOrders as $ro): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ro['order_number']) ?></strong></td>
                        <td><?= htmlspecialchars((string)($ro['customer_name'] ?? 'Walk-in')) ?></td>
                        <td><span style="font-family: var(--font-mono); font-weight: 700;"><?= htmlspecialchars($ro['plate_number']) ?></span></td>
                        <td><?= vehicleTypeBadge($ro['vehicle_type']) ?></td>
                        <td><strong><?= formatUSD((float)$ro['total_usd']) ?></strong></td>
                        <td><?= statusBadge($ro['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
