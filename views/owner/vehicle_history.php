<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('vehicle_history.list');
$pageTitle = __('nav_vehicle_history');
$pdo = Database::getInstance();

$searchPlate = strtoupper(trim($_GET['plate'] ?? ''));

// Fetch history records
$sql = "SELECT * FROM vehicle_repair_history WHERE 1=1";
$params = [];

if ($searchPlate !== '') {
    $sql .= " AND (plate_number LIKE ? OR customer_name LIKE ?)";
    $params = ['%' . $searchPlate . '%', '%' . $searchPlate . '%'];
}

$sql .= " ORDER BY repair_date DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$historyRecords = $stmt->fetchAll();

// Overall aggregate stats
$statsSql = "
    SELECT 
        COUNT(*) as total_events,
        COALESCE(SUM(labor_cost_usd), 0) as total_labor,
        COALESCE(SUM(parts_cost_usd), 0) as total_parts,
        COALESCE(SUM(total_cost_usd), 0) as total_cost
    FROM vehicle_repair_history
";
$stats = $pdo->query($statsSql)->fetch();

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-clock-rotate-left mr-1" style="color: var(--primary);"></i> <?= htmlspecialchars(__('nav_vehicle_history')) ?>
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">
            Logbook of vehicle repairs, spare parts installed, odometer readings, and mechanic inspection notes.
        </p>
    </div>

    <!-- Search Form -->
    <form action="" method="GET" style="display: flex; gap: 8px; align-items: center;">
        <input type="hidden" name="view" value="vehicle_history">
        <input type="text" name="plate" class="form-input" style="width: 260px; min-height: 40px;" placeholder="Search plate (e.g. 2BQ-8899)..." value="<?= htmlspecialchars($searchPlate) ?>">
        <button type="submit" class="btn btn-primary" style="min-height: 40px;">
            <i class="fa-solid fa-magnifying-glass"></i> Search
        </button>
        <?php if ($searchPlate !== ''): ?>
            <a href="?view=vehicle_history" class="btn btn-secondary" style="min-height: 40px;" title="Clear filter">
                Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-4" style="gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fa-solid fa-wrench"></i>
        </div>
        <div>
            <div class="stat-value"><?= (int)$stats['total_events'] ?> Jobs</div>
            <div class="stat-label">Total Repair Events</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fa-solid fa-user-gear"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatUSD((float)$stats['total_labor']) ?></div>
            <div class="stat-label">Total Labor Invoiced</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-gears"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatUSD((float)$stats['total_parts']) ?></div>
            <div class="stat-label">Spare Parts Invoiced</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fa-solid fa-sack-dollar"></i>
        </div>
        <div>
            <div class="stat-value" style="color: #16a34a;"><?= formatUSD((float)$stats['total_cost']) ?></div>
            <div class="stat-label">Gross Maintenance Revenue</div>
        </div>
    </div>
</div>

<!-- History Records Table -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title">
            <i class="fa-solid fa-list-check mr-1" style="color: var(--primary);"></i>
            <?= $searchPlate ? "Repair Records for '{$searchPlate}'" : 'All Vehicle Service Histories' ?>
            (<?= count($historyRecords) ?>)
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table" style="font-size: 13.5px;">
            <thead>
                <tr>
                    <th style="width: 130px;">Date</th>
                    <th style="width: 140px;">Vehicle Plate</th>
                    <th style="width: 160px;">Customer</th>
                    <th>Services Done &amp; Parts Replaced</th>
                    <th style="width: 130px;">Mileage</th>
                    <th style="width: 160px;">Assigned Mechanic</th>
                    <th style="width: 150px; text-align: right;">Cost Breakdown</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historyRecords)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 36px;">
                            <i class="fa-solid fa-wrench mr-1" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>
                            No repair history found<?= $searchPlate ? " for '{$searchPlate}'" : '' ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historyRecords as $rec): ?>
                    <tr>
                        <td>
                            <strong><?= date('d/m/Y', strtotime($rec['repair_date'])) ?></strong>
                            <div class="text-xs text-muted"><?= date('H:i', strtotime($rec['repair_date'])) ?></div>
                        </td>
                        <td>
                            <span style="font-family: var(--font-mono); font-weight: 800; font-size: 14px; background: var(--bg-subtle); padding: 3px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                <?= htmlspecialchars($rec['plate_number']) ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars((string)($rec['customer_name'] ?? 'Customer')) ?></div>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main); margin-bottom: 2px;">
                                <i class="fa-solid fa-wrench mr-1" style="color: var(--primary);"></i>
                                <?= htmlspecialchars((string)($rec['services_performed'] ?? '-')) ?>
                            </div>
                            <?php if (!empty($rec['parts_replaced'])): ?>
                                <div class="text-xs" style="color: #ea580c; margin-bottom: 4px;">
                                    <i class="fa-solid fa-gears mr-1"></i> Parts: <strong><?= htmlspecialchars((string)$rec['parts_replaced']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($rec['repair_notes'])): ?>
                                <div style="font-size: 12px; background: #fffbeb; border-left: 3px solid #f59e0b; padding: 4px 8px; border-radius: var(--radius-sm); color: #92400e; margin-top: 4px;">
                                    <strong>Note:</strong> <?= htmlspecialchars((string)$rec['repair_notes']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($rec['mileage'])): ?>
                                <strong><?= number_format((int)$rec['mileage']) ?> km</strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-amber" style="font-size: 11px;">
                                <i class="fa-solid fa-wrench mr-1"></i> <?= htmlspecialchars((string)($rec['mechanic_name'] ?? 'Shop Mechanic')) ?>
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <div style="font-size: 11.5px; color: var(--text-muted);">
                                Labor: <?= formatUSD((float)$rec['labor_cost_usd']) ?>
                            </div>
                            <?php if ((float)$rec['parts_cost_usd'] > 0): ?>
                                <div style="font-size: 11.5px; color: var(--text-muted);">
                                    Parts: <?= formatUSD((float)$rec['parts_cost_usd']) ?>
                                </div>
                            <?php endif; ?>
                            <div style="font-size: 14px; font-weight: 800; color: #16a34a; margin-top: 2px;">
                                <?= formatUSD((float)$rec['total_cost_usd']) ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
