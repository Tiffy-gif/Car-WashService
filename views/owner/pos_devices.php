<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('pos_devices.list');
$pageTitle = 'Connected POS Devices';
$pdo = Database::getInstance();

$message = '';
$messageType = 'success';
$newlyCreatedApiKey = '';
$newlyCreatedDeviceName = '';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Actions: Add Device, Block Device, Unblock Device, Regenerate Key, Delete Device
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Register New POS Device
    if ($action === 'register_device') {
        if (!Auth::can('pos_devices.add')) {
            $message = 'Permission denied: Cannot register new POS device.';
            $messageType = 'danger';
        } else {
            $name = trim($_POST['device_name'] ?? '');
            $uid = strtoupper(trim($_POST['device_uid'] ?? ''));
            $location = trim($_POST['location'] ?? 'Main Counter');

            if ($name === '' || $uid === '') {
                $message = 'Device Name and Device ID are required.';
                $messageType = 'danger';
            } else {
                // Check UID uniqueness
                $check = $pdo->prepare("SELECT COUNT(*) FROM pos_devices WHERE device_uid = ?");
                $check->execute([$uid]);
                if ((int)$check->fetchColumn() > 0) {
                    $message = "A device with ID '{$uid}' is already registered.";
                    $messageType = 'danger';
                } else {
                    $apiKey = 'pos_sec_' . bin2hex(random_bytes(16));
                    $keyHash = hash('sha256', $apiKey);
                    $ins = $pdo->prepare("INSERT INTO pos_devices (device_name, device_uid, api_key, api_key_hash, location, status) VALUES (?, ?, '', ?, ?, 'active')");
                    $ins->execute([$name, $uid, $keyHash, $location]);
                    $newlyCreatedApiKey = $apiKey;
                    $newlyCreatedDeviceName = $name;
                    $message = "New POS device '{$name}' approved successfully!";
                }
            }
        }
    }

    // 2. Toggle Status (Block / Unblock)
    elseif ($action === 'toggle_status') {
        if (!Auth::can('pos_devices.block')) {
            $message = 'Permission denied: Cannot block/unblock device.';
            $messageType = 'danger';
        } else {
            $id = (int)($_POST['device_id'] ?? 0);
            $newStatus = ($_POST['new_status'] === 'blocked') ? 'blocked' : 'active';
            $pdo->prepare("UPDATE pos_devices SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            $message = "Device status changed to: " . strtoupper($newStatus);
        }
    }

    // 3. Regenerate Key
    elseif ($action === 'regenerate_key') {
        if (!Auth::can('pos_devices.regenerate')) {
            $message = 'Permission denied: Cannot regenerate device key.';
            $messageType = 'danger';
        } else {
            $id = (int)($_POST['device_id'] ?? 0);
            $newKey = 'pos_sec_' . bin2hex(random_bytes(16));
            $newKeyHash = hash('sha256', $newKey);
            $pdo->prepare("UPDATE pos_devices SET api_key = '', api_key_hash = ? WHERE id = ?")->execute([$newKeyHash, $id]);
            $dStmt = $pdo->prepare("SELECT device_name FROM pos_devices WHERE id = ?");
            $dStmt->execute([$id]);
            $devName = $dStmt->fetchColumn();
            $newlyCreatedApiKey = $newKey;
            $newlyCreatedDeviceName = $devName ? $devName : "Device #{$id}";
            $message = "New API Key generated for " . ($devName ? "'{$devName}'" : "device #{$id}") . "!";
        }
    }

    // 4. Delete Device
    elseif ($action === 'delete_device') {
        if (!Auth::can('pos_devices.delete')) {
            $message = 'Permission denied: Cannot delete device.';
            $messageType = 'danger';
        } else {
            $id = (int)($_POST['device_id'] ?? 0);
            $dStmt = $pdo->prepare("SELECT device_name, device_uid FROM pos_devices WHERE id = ?");
            $dStmt->execute([$id]);
            $devRow = $dStmt->fetch();
            $dName = $devRow['device_name'] ?? (string)$id;
            // SOFT DELETE per requirement
            $pdo->prepare("UPDATE pos_devices SET status = 'inactive' WHERE id = ?")->execute([$id]);
            audit_log('DEVICE_DEACTIVATED', "Deactivated POS device '{$dName}' (soft delete)", 'device', (string)$id);
            $message = "Device '{$dName}' marked inactive (safe soft delete).";
        }
    }
}

// Check if viewing a specific device detail page
$detailDeviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : null;
$detailDevice = null;

if ($detailDeviceId) {
    $devStmt = $pdo->prepare("SELECT * FROM pos_devices WHERE id = ?");
    $devStmt->execute([$detailDeviceId]);
    $detailDevice = $devStmt->fetch(PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------
// DETAIL VIEW FOR A SINGLE POS DEVICE
// -------------------------------------------------------------
if ($detailDevice):
    $pageTitle = 'Device: ' . $detailDevice['device_name'];

    // Today's stats
    $dTodayStmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN 1 END) as paid_count,
            COALESCE(SUM(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN total_usd ELSE 0 END), 0) as sales_usd,
            COALESCE(SUM(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN total_khr ELSE 0 END), 0) as sales_khr
        FROM orders 
        WHERE pos_device_id = ? AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
    ");
    $dTodayStmt->execute([$detailDeviceId]);
    $dToday = $dTodayStmt->fetch(PDO::FETCH_ASSOC);

    // This month's stats
    $dMonthStmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN 1 END) as paid_count,
            COALESCE(SUM(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN total_usd ELSE 0 END), 0) as sales_usd
        FROM orders 
        WHERE pos_device_id = ? 
          AND MONTH(COALESCE(paid_at, created_at)) = MONTH(CURDATE()) 
          AND YEAR(COALESCE(paid_at, created_at)) = YEAR(CURDATE())
    ");
    $dMonthStmt->execute([$detailDeviceId]);
    $dMonth = $dMonthStmt->fetch(PDO::FETCH_ASSOC);

    // Lifetime stats
    $dLifeStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COUNT(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN 1 END) as paid_count,
            COALESCE(SUM(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN total_usd ELSE 0 END), 0) as sales_usd,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count,
            COALESCE(SUM(CASE WHEN status != 'cancelled' AND payment_status = 'unpaid' THEN total_usd ELSE 0 END), 0) as unpaid_usd
        FROM orders 
        WHERE pos_device_id = ?
    ");
    $dLifeStmt->execute([$detailDeviceId]);
    $dLife = $dLifeStmt->fetch(PDO::FETCH_ASSOC);

    // Closings history for this device
    $dClosingsStmt = $pdo->prepare("
        SELECT c.*, COALESCE(c.closed_by_name, u.full_name, 'Staff') as cashier_name
        FROM pos_daily_closes c
        LEFT JOIN users u ON c.closed_by_user_id = u.id
        WHERE c.pos_device_id = ?
        ORDER BY c.close_date DESC, c.id DESC
        LIMIT 30
    ");
    $dClosingsStmt->execute([$detailDeviceId]);
    $deviceClosings = $dClosingsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Filterable orders list for this device
    $orderFilterStatus = $_GET['order_status'] ?? 'all';
    $orderFilterPay = $_GET['order_pay'] ?? 'all';
    $orderFilterDate = $_GET['order_date'] ?? '';

    $dOrderWhere = "WHERE o.pos_device_id = ?";
    $dOrderParams = [$detailDeviceId];

    if ($orderFilterStatus !== 'all') {
        $dOrderWhere .= " AND o.status = ?";
        $dOrderParams[] = $orderFilterStatus;
    }
    if ($orderFilterPay !== 'all') {
        $dOrderWhere .= " AND o.payment_method = ?";
        $dOrderParams[] = $orderFilterPay;
    }
    if (!empty($orderFilterDate)) {
        $dOrderWhere .= " AND DATE(o.created_at) = ?";
        $dOrderParams[] = $orderFilterDate;
    }

    $dOrdersStmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name, v.plate_number, u.full_name as cashier_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN vehicles v ON o.vehicle_id = v.id
        LEFT JOIN users u ON o.cashier_id = u.id
        {$dOrderWhere}
        ORDER BY o.id DESC
        LIMIT 100
    ");
    $dOrdersStmt->execute($dOrderParams);
    $deviceOrders = $dOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

    include dirname(__DIR__) . '/layouts/header.php';
?>

<?php if (!empty($newlyCreatedApiKey)): ?>
    <div class="card" style="margin-bottom: 24px; background: #fffbeb; border: 2px solid #f59e0b; border-radius: var(--radius-md); padding: 18px 22px; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.12);">
        <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="background: #fef3c7; color: #d97706; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; margin-top: 2px;">
                <i class="fa-solid fa-key"></i>
            </div>
            <div style="flex: 1;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #92400e;">
                        <i class="fa-solid fa-circle-check mr-1" style="color: #16a34a;"></i>
                        New API Key: <?= htmlspecialchars($newlyCreatedDeviceName ?: $detailDevice['device_name'], ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 700; font-size: 11.5px;">
                        Shown Once Only
                    </span>
                </div>
                <p style="margin: 6px 0 12px 0; font-size: 13.5px; color: #78350f; line-height: 1.45;">
                    Copy and save this key now. It is stored securely as a SHA-256 hash and <strong>will not be displayed again</strong>. If lost, press Regenerate Key.
                </p>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <input type="text" id="newGeneratedApiKeyInput" readonly value="<?= htmlspecialchars($newlyCreatedApiKey, ENT_QUOTES, 'UTF-8') ?>" style="flex: 1; max-width: 440px; font-family: var(--font-mono); font-size: 13.5px; font-weight: 700; background: #ffffff; border: 1.5px solid #f59e0b; border-radius: 6px; padding: 8px 12px; color: #92400e;">
                    <button type="button" class="btn btn-warning" id="btnCopyNewApiKey" onclick="copyNewGeneratedKey()" style="background: #f59e0b; border-color: #d97706; color: #000; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-copy"></i>
                        <span>Copy Key</span>
                    </button>
                    <span id="copyNewApiKeyFeedback" style="display: none; font-size: 13px; font-weight: 700; color: #16a34a;">
                        <i class="fa-solid fa-check mr-1"></i> Copied to clipboard!
                    </span>
                </div>
            </div>
        </div>
    </div>
<?php elseif (!empty($message)): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
<?php endif; ?>

<!-- Device Detail Header -->
<div style="margin-bottom: 24px;">
    <a href="<?= APP_URL ?>/index.php?view=pos_devices" class="btn btn-secondary btn-sm" style="margin-bottom: 12px;">
        <i class="fa-solid fa-arrow-left mr-1"></i> Back to All POS Devices
    </a>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin: 0;">
                    <?= htmlspecialchars($detailDevice['device_name']) ?>
                </h1>
                <span class="badge badge-slate" style="font-family: var(--font-mono); font-weight: 700;">
                    <?= htmlspecialchars($detailDevice['device_uid']) ?>
                </span>
                <?php if ($detailDevice['status'] === 'active'): ?>
                    <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Active</span>
                <?php else: ?>
                    <span class="badge badge-danger"><i class="fa-solid fa-ban mr-1"></i> Blocked</span>
                <?php endif; ?>
            </div>
            <p style="font-size: 13.5px; color: var(--text-muted); margin-top: 4px;">
                <i class="fa-solid fa-location-dot mr-1"></i> <?= htmlspecialchars($detailDevice['location'] ?? 'Main Counter') ?> &bull; 
                Registered: <?= date('d M Y', strtotime($detailDevice['created_at'])) ?> &bull;
                Last Seen: <?= !empty($detailDevice['last_seen_at']) ? date('d M Y H:i', strtotime($detailDevice['last_seen_at'])) : 'Never' ?> 
                <?= !empty($detailDevice['last_ip']) ? ('(IP: ' . htmlspecialchars($detailDevice['last_ip']) . ')') : '' ?>
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="<?= APP_URL ?>/index.php?view=reports&device=<?= $detailDevice['id'] ?>" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-chart-line mr-1"></i> View Financial Report &rarr;
            </a>
        </div>
    </div>
</div>

<!-- API Secret Key Card -->
<div class="card" style="margin-bottom: 24px; background: var(--bg-card); padding: 18px 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700;">
                <i class="fa-solid fa-key mr-1" style="color: #ea580c;"></i> Device API Authentication Key
            </div>
            <?php $isKeyHashed = empty($detailDevice['api_key']) || !str_starts_with($detailDevice['api_key'], 'pos_sec_'); ?>
            <div style="display: flex; align-items: center; gap: 8px; margin-top: 6px;">
                <input type="password" value="<?= !empty($detailDevice['api_key']) ? htmlspecialchars($detailDevice['api_key']) : '[Secured as SHA-256 Hash]' ?>" readonly id="detailDevKey" style="font-family: var(--font-mono); font-size: 13px; background: var(--bg-subtle); border: 1px solid var(--border); border-radius: 6px; padding: 6px 12px; width: 280px; color: var(--text-main);" title="Stored as SHA-256 hash. Regenerate if lost.">
                <button type="button" class="btn btn-secondary btn-sm" onclick="copyApiKey('detailDevKey')" <?= $isKeyHashed ? 'disabled style="cursor: not-allowed; opacity: 0.5;" title="Cannot copy hashed key. If you lost it, press Regenerate Key."' : '' ?>>
                    <i class="fa-solid fa-copy mr-1"></i> Copy
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleKeyVisibility('detailDevKey')">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            <?php if ($isKeyHashed): ?>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 6px; max-width: 550px;">
                    <i class="fa-solid fa-circle-info mr-1"></i> The key is shown only once, right after creating the device or pressing Regenerate Key. If you lost it, press Regenerate Key.
                </div>
            <?php endif; ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <form action="" method="POST" onsubmit="return confirm('Regenerating will invalidate the existing key on this terminal. Continue?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="regenerate_key">
                <input type="hidden" name="device_id" value="<?= $detailDevice['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-rotate mr-1"></i> Regenerate Key
                </button>
            </form>
            <form action="" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="device_id" value="<?= $detailDevice['id'] ?>">
                <input type="hidden" name="new_status" value="<?= ($detailDevice['status'] === 'active') ? 'blocked' : 'active' ?>">
                <button type="submit" class="btn btn-sm <?= ($detailDevice['status'] === 'active') ? 'btn-secondary' : 'btn-primary' ?>">
                    <i class="fa-solid <?= ($detailDevice['status'] === 'active') ? 'fa-ban' : 'fa-check' ?> mr-1"></i> 
                    <?= ($detailDevice['status'] === 'active') ? 'Block Access' : 'Unblock Access' ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- KPI Cards for this device -->
<div class="grid grid-cols-4" style="gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon green"><i class="fa-solid fa-calendar-day"></i></div>
        <div>
            <div class="stat-value" style="color: #16a34a;"><?= formatUSD((float)$dToday['sales_usd']) ?></div>
            <div class="stat-label">Today's Sales (<?= (int)$dToday['paid_count'] ?> orders)</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue"><i class="fa-solid fa-calendar-days"></i></div>
        <div>
            <div class="stat-value" style="color: #0284c7;"><?= formatUSD((float)$dMonth['sales_usd']) ?></div>
            <div class="stat-label">This Month (<?= (int)$dMonth['paid_count'] ?> orders)</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon purple"><i class="fa-solid fa-vault"></i></div>
        <div>
            <div class="stat-value"><?= formatUSD((float)$dLife['sales_usd']) ?></div>
            <div class="stat-label">Lifetime Sales (<?= (int)$dLife['paid_count'] ?> orders)</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div>
            <div class="stat-value" style="color: #ea580c;"><?= formatUSD((float)$dLife['unpaid_usd']) ?></div>
            <div class="stat-label">Unpaid (Pay Later) Amount</div>
        </div>
    </div>
</div>

<!-- POS Daily Closings for this device -->
<div class="card" style="margin-bottom: 28px;">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-cash-register mr-1" style="color: var(--primary);"></i> POS Daily Closings History
        </h2>
        <span class="text-xs text-muted">Daily cash drawer counts and shift reconciliations for this station</span>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Close Date</th>
                    <th>Cashier</th>
                    <th>Opening Time</th>
                    <th style="text-align: right;">Cash Sales</th>
                    <th style="text-align: right;">Expected Cash</th>
                    <th style="text-align: right;">Counted Cash</th>
                    <th style="text-align: right;">Difference</th>
                    <th>Shift Notes</th>
                    <th>Closed At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deviceClosings)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 28px; color: var(--text-muted);">
                            No daily closings recorded yet for this terminal.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deviceClosings as $dc): 
                        $diff = (float)($dc['difference_usd'] ?? $dc['difference'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($dc['close_date']) ?></strong></td>
                        <td><?= htmlspecialchars($dc['cashier_name'] ?? $dc['closed_by_name'] ?? 'Staff') ?></td>
                        <td><?= !empty($dc['opening_time']) ? htmlspecialchars(date('H:i', strtotime($dc['opening_time']))) : '-' ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums;"><?= formatUSD((float)($dc['cash_sales_usd'] ?? $dc['cash_sales'] ?? 0)) ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums; font-weight: 600;"><?= formatUSD((float)($dc['expected_cash_usd'] ?? $dc['expected_cash'] ?? 0)) ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums; font-weight: 700;"><?= formatUSD((float)($dc['counted_cash_usd'] ?? $dc['counted_cash'] ?? 0)) ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums;">
                            <?php if (abs($diff) < 0.01): ?>
                                <span class="badge badge-success" style="font-weight: 700;">$0.00</span>
                            <?php elseif ($diff < 0): ?>
                                <span class="badge badge-danger" style="font-weight: 700;">-$<?= number_format(abs($diff), 2) ?> (Short)</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-weight: 700;">+$<?= number_format($diff, 2) ?> (Over)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs text-muted"><?= htmlspecialchars((string)($dc['notes'] ?? $dc['note'] ?? '-')) ?></td>
                        <td class="text-xs text-muted"><?= !empty($dc['closed_at']) ? date('d/m H:i', strtotime($dc['closed_at'])) : (!empty($dc['created_at']) ? date('d/m H:i', strtotime($dc['created_at'])) : '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Orders Generated by this Device -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 class="card-title">
                <i class="fa-solid fa-receipt mr-1" style="color: var(--primary);"></i> Orders Logged on This Terminal
            </h2>
            <span class="text-xs text-muted">Showing latest orders processed through this device</span>
        </div>

        <!-- Filter form -->
        <form action="" method="GET" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <input type="hidden" name="view" value="pos_devices">
            <input type="hidden" name="device_id" value="<?= $detailDevice['id'] ?>">

            <select name="order_status" class="form-input" style="padding: 5px 10px; font-size: 12px; width: 120px;" onchange="this.form.submit()">
                <option value="all" <?= ($orderFilterStatus === 'all') ? 'selected' : '' ?>>All Statuses</option>
                <option value="paid" <?= ($orderFilterStatus === 'paid') ? 'selected' : '' ?>>Paid</option>
                <option value="waiting" <?= ($orderFilterStatus === 'waiting') ? 'selected' : '' ?>>Waiting</option>
                <option value="washing" <?= ($orderFilterStatus === 'washing') ? 'selected' : '' ?>>Washing</option>
                <option value="finished" <?= ($orderFilterStatus === 'finished') ? 'selected' : '' ?>>Finished</option>
                <option value="cancelled" <?= ($orderFilterStatus === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
            </select>

            <select name="order_pay" class="form-input" style="padding: 5px 10px; font-size: 12px; width: 110px;" onchange="this.form.submit()">
                <option value="all" <?= ($orderFilterPay === 'all') ? 'selected' : '' ?>>All Methods</option>
                <option value="cash" <?= ($orderFilterPay === 'cash') ? 'selected' : '' ?>>Cash</option>
                <option value="aba" <?= ($orderFilterPay === 'aba') ? 'selected' : '' ?>>ABA</option>
                <option value="khqr" <?= ($orderFilterPay === 'khqr') ? 'selected' : '' ?>>KHQR</option>
            </select>

            <input type="date" name="order_date" value="<?= htmlspecialchars($orderFilterDate) ?>" class="form-input" style="padding: 4px 8px; font-size: 12px; width: 135px;" onchange="this.form.submit()">

            <?php if ($orderFilterStatus !== 'all' || $orderFilterPay !== 'all' || !empty($orderFilterDate)): ?>
                <a href="<?= APP_URL ?>/index.php?view=pos_devices&device_id=<?= $detailDevice['id'] ?>" class="btn btn-secondary btn-sm" style="padding: 4px 8px;">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Plate</th>
                    <th>Cashier</th>
                    <th style="text-align: right;">Subtotal</th>
                    <th style="text-align: right;">Discount</th>
                    <th style="text-align: right;">Total Paid</th>
                    <th>Tender</th>
                    <th>Status</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deviceOrders)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 32px; color: var(--text-muted);">
                            No orders match the selected filters for this terminal.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deviceOrders as $o): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                        <td><?= htmlspecialchars($o['customer_name'] ?? 'Walk-in') ?></td>
                        <td><span style="font-family: var(--font-mono); font-weight: 700;"><?= htmlspecialchars((string)($o['plate_number'] ?? '-')) ?></span></td>
                        <td><span class="text-xs text-muted"><?= htmlspecialchars($o['cashier_name'] ?? 'Cashier') ?></span></td>
                        <td style="text-align: right;"><?= formatUSD((float)$o['subtotal_usd']) ?></td>
                        <td style="text-align: right; color: #d97706;"><?= ((float)$o['discount_usd'] > 0) ? ('-' . formatUSD((float)$o['discount_usd'])) : '-' ?></td>
                        <td style="text-align: right; font-weight: 700; color: #16a34a;"><?= formatUSD((float)$o['total_usd']) ?></td>
                        <td>
                            <span class="badge badge-slate" style="text-transform: uppercase; font-size: 10.5px;">
                                <?= htmlspecialchars((string)($o['payment_method'] ?? 'unpaid')) ?>
                            </span>
                        </td>
                        <td><?= statusBadge($o['status']) ?></td>
                        <td class="text-xs text-muted"><?= date('d/m H:i', strtotime($o['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function copyNewGeneratedKey() {
    const input = document.getElementById('newGeneratedApiKeyInput');
    if (!input) return;
    const key = (input.value || '').trim();
    if (!key) return;

    const onSuccess = () => {
        const feedback = document.getElementById('copyNewApiKeyFeedback');
        const btn = document.getElementById('btnCopyNewApiKey');
        if (feedback) {
            feedback.style.display = 'inline-flex';
            setTimeout(() => { feedback.style.display = 'none'; }, 3000);
        }
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
            setTimeout(() => { btn.innerHTML = orig; }, 2000);
        }
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(key).then(onSuccess).catch(() => {
            input.focus();
            input.select();
            document.execCommand('copy');
            onSuccess();
        });
    } else {
        input.focus();
        input.select();
        document.execCommand('copy');
        onSuccess();
    }
}

function copyApiKey(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const val = (input.value || '').trim();
    if (!val || val === '[Secured as SHA-256 Hash]' || val === '[SHA-256 Hashed]' || !val.startsWith('pos_sec_')) {
        alert('The key is stored hashed and cannot be copied. If you lost it, press Regenerate Key.');
        return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(() => {
            alert('API key copied to clipboard!');
        }).catch(() => {
            input.type = 'text';
            input.select();
            document.execCommand('copy');
            input.type = 'password';
            alert('API key copied to clipboard!');
        });
    } else {
        input.type = 'text';
        input.select();
        document.execCommand('copy');
        input.type = 'password';
        alert('API key copied to clipboard!');
    }
}

function toggleKeyVisibility(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.type = (input.type === 'password') ? 'text' : 'password';
}
</script>

<?php 
    include dirname(__DIR__) . '/layouts/footer.php';
    return;
endif;

// -------------------------------------------------------------
// MAIN ALL DEVICES OVERVIEW
// -------------------------------------------------------------

// Fetch all devices
$devices = $pdo->query("SELECT * FROM pos_devices ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Warning 1: Check for Shared / Duplicate API Keys
$sharedKeysStmt = $pdo->query("
    SELECT api_key_hash, COUNT(*) as dev_count, GROUP_CONCAT(device_name SEPARATOR ' & ') as device_names
    FROM pos_devices
    WHERE api_key_hash IS NOT NULL AND api_key_hash != ''
    GROUP BY api_key_hash
    HAVING dev_count > 1
");
$sharedKeys = $sharedKeysStmt->fetchAll(PDO::FETCH_ASSOC);

// Warning 2: Devices that have NEVER sent an order
$neverOrderedStmt = $pdo->query("
    SELECT pd.id, pd.device_name, pd.device_uid
    FROM pos_devices pd
    LEFT JOIN orders o ON o.pos_device_id = pd.id
    WHERE o.id IS NULL
    ORDER BY pd.id ASC
");
$neverOrderedDevices = $neverOrderedStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch device stats
$stats = [
    'total' => count($devices),
    'active' => 0,
    'blocked' => 0
];
foreach ($devices as $d) {
    if ($d['status'] === 'active') $stats['active']++;
    if ($d['status'] === 'blocked') $stats['blocked']++;
}

// Fetch orders synced from POS app
$posOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE source = 'pos_app'")->fetchColumn();
$posOrdersTotal = (float)$pdo->query("SELECT COALESCE(SUM(total_usd), 0) FROM orders WHERE source = 'pos_app' AND (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled'")->fetchColumn();

// Query per-device metrics (Today, Month, Last Order, 7-day sales sparkline)
$deviceMetrics = [];
$todayDevRows = $pdo->query("
    SELECT 
        pos_device_id,
        COUNT(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN 1 END) as today_paid_orders,
        COALESCE(SUM(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN total_usd ELSE 0 END), 0) as today_sales_usd
    FROM orders
    WHERE pos_device_id IS NOT NULL AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
    GROUP BY pos_device_id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($todayDevRows as $tr) {
    $deviceMetrics[(int)$tr['pos_device_id']]['today_paid_orders'] = (int)$tr['today_paid_orders'];
    $deviceMetrics[(int)$tr['pos_device_id']]['today_sales_usd'] = (float)$tr['today_sales_usd'];
}

$monthDevRows = $pdo->query("
    SELECT 
        pos_device_id,
        COALESCE(SUM(CASE WHEN (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled' THEN total_usd ELSE 0 END), 0) as month_sales_usd
    FROM orders
    WHERE pos_device_id IS NOT NULL 
      AND MONTH(COALESCE(paid_at, created_at)) = MONTH(CURDATE()) 
      AND YEAR(COALESCE(paid_at, created_at)) = YEAR(CURDATE())
    GROUP BY pos_device_id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($monthDevRows as $mr) {
    $deviceMetrics[(int)$mr['pos_device_id']]['month_sales_usd'] = (float)$mr['month_sales_usd'];
}

$lastOrderDevRows = $pdo->query("
    SELECT pos_device_id, MAX(created_at) as last_order_time
    FROM orders
    WHERE pos_device_id IS NOT NULL
    GROUP BY pos_device_id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($lastOrderDevRows as $lr) {
    $deviceMetrics[(int)$lr['pos_device_id']]['last_order_time'] = $lr['last_order_time'];
}

// 7-day daily sales history per device
$sevenDaysSalesStmt = $pdo->query("
    SELECT 
        o.pos_device_id,
        DATE(COALESCE(o.paid_at, o.created_at)) as sale_date,
        COALESCE(SUM(o.total_usd), 0) as daily_usd
    FROM orders o
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
      AND o.pos_device_id IS NOT NULL
      AND DATE(COALESCE(o.paid_at, o.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY o.pos_device_id, DATE(COALESCE(o.paid_at, o.created_at))
");
$raw7Days = $sevenDaysSalesStmt->fetchAll(PDO::FETCH_ASSOC);

$device7Days = [];
foreach ($raw7Days as $row) {
    $dId = (int)$row['pos_device_id'];
    $device7Days[$dId][$row['sale_date']] = (float)$row['daily_usd'];
}

// Pre-fill last 7 dates
$last7DateKeys = [];
for ($i = 6; $i >= 0; $i--) {
    $last7DateKeys[] = date('Y-m-d', strtotime("-{$i} days"));
}

// Fetch recent POS Daily Closings across all devices
$allClosingsStmt = $pdo->query("
    SELECT c.*, 
           COALESCE(c.device_name, pd.device_name) AS device_name, 
           pd.device_uid, 
           COALESCE(c.closed_by_name, u.full_name, 'Staff') AS cashier_name
    FROM pos_daily_closes c
    LEFT JOIN pos_devices pd ON c.pos_device_id = pd.id
    LEFT JOIN users u ON c.closed_by_user_id = u.id
    ORDER BY c.close_date DESC, c.id DESC
    LIMIT 20
");
$allClosings = $allClosingsStmt->fetchAll(PDO::FETCH_ASSOC);

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-tablet-screen-button mr-1" style="color: var(--primary);"></i> Connected POS Devices &amp; API
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">Manage and authorize separate POS tablets, terminals, or counter apps connecting to this system.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="<?= APP_URL ?>/test_pos_client.php" target="_blank" class="btn btn-secondary">
            <i class="fa-solid fa-gamepad mr-1" style="color: #ea580c;"></i> Open POS App Simulator
        </a>
        <button type="button" class="btn btn-primary" onclick="openModal('addDeviceModal')">
            <i class="fa-solid fa-plus mr-1"></i> Authorize New POS Device
        </button>
    </div>
</div>

<?php if (!empty($newlyCreatedApiKey)): ?>
    <div class="card" style="margin-bottom: 24px; background: #fffbeb; border: 2px solid #f59e0b; border-radius: var(--radius-md); padding: 18px 22px; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.12);">
        <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="background: #fef3c7; color: #d97706; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; margin-top: 2px;">
                <i class="fa-solid fa-key"></i>
            </div>
            <div style="flex: 1;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #92400e;">
                        <i class="fa-solid fa-circle-check mr-1" style="color: #16a34a;"></i>
                        New API Key: <?= htmlspecialchars($newlyCreatedDeviceName ?: 'POS Device', ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 700; font-size: 11.5px;">
                        Shown Once Only
                    </span>
                </div>
                <p style="margin: 6px 0 12px 0; font-size: 13.5px; color: #78350f; line-height: 1.45;">
                    Copy and save this key now. It is stored securely as a SHA-256 hash and <strong>will not be displayed again</strong>. If lost, press Regenerate Key.
                </p>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <input type="text" id="newGeneratedApiKeyInput" readonly value="<?= htmlspecialchars($newlyCreatedApiKey, ENT_QUOTES, 'UTF-8') ?>" style="flex: 1; max-width: 440px; font-family: var(--font-mono); font-size: 13.5px; font-weight: 700; background: #ffffff; border: 1.5px solid #f59e0b; border-radius: 6px; padding: 8px 12px; color: #92400e;">
                    <button type="button" class="btn btn-warning" id="btnCopyNewApiKey" onclick="copyNewGeneratedKey()" style="background: #f59e0b; border-color: #d97706; color: #000; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-copy"></i>
                        <span>Copy Key</span>
                    </button>
                    <span id="copyNewApiKeyFeedback" style="display: none; font-size: 13px; font-weight: 700; color: #16a34a;">
                        <i class="fa-solid fa-check mr-1"></i> Copied to clipboard!
                    </span>
                </div>
            </div>
        </div>
    </div>
<?php elseif (!empty($message)): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
<?php endif; ?>

<!-- System Security & Integrity Warnings -->
<?php if (!empty($sharedKeys)): ?>
    <div style="background: #fef2f2; border: 1px solid #fecaca; border-left: 5px solid #dc2626; border-radius: var(--radius-md); padding: 16px 20px; margin-bottom: 20px; color: #991b1b;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <i class="fa-solid fa-triangle-exclamation fa-lg" style="margin-top: 3px; color: #dc2626;"></i>
            <div>
                <div style="font-weight: 700; font-size: 14.5px;">Security Alert: Shared API Key Detected</div>
                <div style="font-size: 13px; margin-top: 2px;">
                    Multiple devices share the same API secret key:
                    <?php foreach ($sharedKeys as $sk): ?>
                        <strong><?= htmlspecialchars($sk['device_names']) ?></strong>.
                    <?php endforeach; ?>
                    Each terminal must possess a unique API key for accurate order attribution and cash reconciliation. Click "Regenerate Key" below to assign independent keys.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($neverOrderedDevices)): ?>
    <div style="background: #fffbeb; border: 1px solid #fef3c7; border-left: 5px solid #d97706; border-radius: var(--radius-md); padding: 14px 20px; margin-bottom: 20px; color: #92400e;">
        <div style="display: flex; align-items: flex-start; gap: 12px;">
            <i class="fa-solid fa-circle-info fa-lg" style="margin-top: 3px; color: #d97706;"></i>
            <div>
                <div style="font-weight: 700; font-size: 14px;">Inactive Terminals Notice</div>
                <div style="font-size: 12.5px; margin-top: 2px;">
                    The following authorized devices have never processed an order:
                    <?php 
                    $nodNames = array_map(fn($d) => $d['device_name'] . ' (' . $d['device_uid'] . ')', $neverOrderedDevices);
                    echo htmlspecialchars(implode(', ', $nodNames));
                    ?>.
                    Ensure their terminals are powered on and configured with the endpoint below.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Cloud & Local Endpoint Address Box -->
<div class="card" style="margin-bottom: 24px; border-left: 6px solid var(--primary); background: var(--bg-card);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 12px; font-weight: 700; color: var(--primary); text-transform: uppercase;">
                <i class="fa-solid fa-cloud mr-1"></i> POS API Connection Endpoint
            </div>
            <div style="font-size: 17px; font-weight: 800; color: var(--text-main); font-family: var(--font-mono); margin-top: 4px;">
                <?= htmlspecialchars(API_BASE_URL) ?>/api/pos/
            </div>
            <div class="text-xs text-muted" style="margin-top: 6px;">
                Configure this URL on your separate POS devices. When moving to the cloud, simply change the domain in <code>config/app.php</code> in <strong>one single place</strong>.
            </div>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('<?= API_BASE_URL ?>/api/pos/'); alert('API endpoint copied to clipboard!')">
                <i class="fa-solid fa-copy mr-1"></i> Copy Endpoint URL
            </button>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-4" style="gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fa-solid fa-tablet"></i>
        </div>
        <div>
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-label">Registered POS Devices</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
            <div class="stat-value" style="color: #16a34a;"><?= $stats['active'] ?></div>
            <div class="stat-label">Active &amp; Approved</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div>
            <div class="stat-value"><?= $posOrdersCount ?></div>
            <div class="stat-label">Orders Received via POS</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-sack-dollar"></i>
        </div>
        <div>
            <div class="stat-value" style="color: #d97706;"><?= formatUSD($posOrdersTotal) ?></div>
            <div class="stat-label">Synced Revenue via POS</div>
        </div>
    </div>
</div>

<!-- Connected Terminals Performance Grid with 7-Day Sparkline -->
<div style="margin-bottom: 28px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <h2 style="font-size: 17px; font-weight: 700; color: var(--text-main); margin: 0;">
            <i class="fa-solid fa-chart-simple mr-1" style="color: var(--primary);"></i> Active Terminals &amp; 7-Day Performance
        </h2>
        <span class="text-xs text-muted">Click any terminal to inspect its full shift closings and order logs</span>
    </div>

    <div class="grid grid-cols-3" style="gap: 16px;">
        <?php foreach ($devices as $dev): 
            $dId = (int)$dev['id'];
            $isActive = ($dev['status'] === 'active');
            $mTodaySales = $deviceMetrics[$dId]['today_sales_usd'] ?? 0.00;
            $mTodayCount = $deviceMetrics[$dId]['today_paid_orders'] ?? 0;
            $mMonthSales = $deviceMetrics[$dId]['month_sales_usd'] ?? 0.00;
            $mLastOrder = $deviceMetrics[$dId]['last_order_time'] ?? null;

            // 7-day sparkline calculation
            $dayValues = [];
            $maxDayVal = 1.0;
            foreach ($last7DateKeys as $k) {
                $val = $device7Days[$dId][$k] ?? 0.0;
                $dayValues[$k] = $val;
                if ($val > $maxDayVal) $maxDayVal = $val;
            }
        ?>
        <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 18px; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                    <div>
                        <a href="<?= APP_URL ?>/index.php?view=pos_devices&device_id=<?= $dev['id'] ?>" style="font-size: 16px; font-weight: 800; color: var(--text-main); text-decoration: none; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-tablet-button" style="color: var(--primary);"></i>
                            <?= htmlspecialchars($dev['device_name']) ?>
                        </a>
                        <div class="text-xs text-muted" style="margin-top: 2px;">
                            <i class="fa-solid fa-location-dot mr-1"></i> <?= htmlspecialchars($dev['location'] ?? 'Main Counter') ?>
                        </div>
                    </div>
                    <?php if ($isActive): ?>
                        <span class="badge badge-success" style="font-size: 11px;">Active</span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size: 11px;">Blocked</span>
                    <?php endif; ?>
                </div>

                <!-- UID & IP -->
                <div style="margin-top: 12px; display: flex; align-items: center; justify-content: space-between; font-size: 12px;">
                    <span class="badge badge-slate" style="font-family: var(--font-mono); font-size: 11px;">
                        <?= htmlspecialchars($dev['device_uid']) ?>
                    </span>
                    <span class="text-xs text-muted">
                        <?= !empty($dev['last_ip']) ? ('IP: ' . htmlspecialchars($dev['last_ip'])) : 'Never connected' ?>
                    </span>
                </div>

                <!-- Sales Metrics -->
                <div style="margin-top: 14px; padding: 12px; background: var(--bg-subtle); border-radius: var(--radius-md); display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <div class="text-xs text-muted">Today's Sales</div>
                        <div style="font-size: 16px; font-weight: 800; color: #16a34a;">
                            <?= formatUSD((float)$mTodaySales) ?>
                        </div>
                        <div class="text-xs text-muted"><?= $mTodayCount ?> orders</div>
                    </div>
                    <div>
                        <div class="text-xs text-muted">Month to Date</div>
                        <div style="font-size: 16px; font-weight: 800; color: #0284c7;">
                            <?= formatUSD((float)$mMonthSales) ?>
                        </div>
                        <div class="text-xs text-muted">
                            Last: <?= $mLastOrder ? date('d/m H:i', strtotime($mLastOrder)) : 'No orders' ?>
                        </div>
                    </div>
                </div>

                <!-- 7-Day Mini Bar Chart (Sparkline) -->
                <div style="margin-top: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span class="text-xs text-muted" style="font-size: 11px; font-weight: 700; text-transform: uppercase;">7-Day Sales Trend</span>
                        <span class="text-xs text-muted" style="font-size: 10.5px;">Max: <?= formatUSD($maxDayVal) ?></span>
                    </div>
                    <div style="height: 44px; display: flex; align-items: flex-end; gap: 5px; background: var(--bg-subtle); border-radius: 6px; padding: 6px 8px;">
                        <?php foreach ($dayValues as $dDate => $dVal): 
                            $barHeight = max(8, round(($dVal / $maxDayVal) * 32));
                            $isToday = ($dDate === date('Y-m-d'));
                            $barColor = ($dVal > 0) ? ($isToday ? '#16a34a' : '#0284c7') : 'var(--border)';
                        ?>
                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end;" title="<?= date('D d M', strtotime($dDate)) ?>: <?= formatUSD($dVal) ?>">
                                <div style="width: 100%; height: <?= $barHeight ?>px; background: <?= $barColor ?>; border-radius: 2px; transition: height 0.2s ease;"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 9.5px; color: var(--text-muted); margin-top: 3px;">
                        <span>6d ago</span>
                        <span>Today</span>
                    </div>
                </div>
            </div>

            <!-- Footer link to detail page -->
            <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                <span class="text-xs text-muted">
                    Last Seen: <?= !empty($dev['last_seen_at']) ? date('d/m H:i', strtotime($dev['last_seen_at'])) : '-' ?>
                </span>
                <a href="<?= APP_URL ?>/index.php?view=pos_devices&device_id=<?= $dev['id'] ?>" class="text-xs font-semibold" style="color: var(--primary); text-decoration: none;">
                    Details &amp; Orders &rarr;
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Authorized Devices Whitelist Table -->
<div class="card" style="margin-bottom: 28px;">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-shield-halved mr-1" style="color: var(--primary);"></i> Device Access Control Whitelist
        </h2>
        <span class="text-xs text-muted">Only listed devices with an active status can communicate with this system</span>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Device Details</th>
                    <th>Device UID</th>
                    <th>Assigned Location</th>
                    <th>API Key (Secret)</th>
                    <th>Status</th>
                    <th>Last Activity</th>
                    <th style="text-align: right;">Access Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            No POS devices registered yet. Click "Authorize New POS Device" to connect your first tablet or counter app.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($devices as $d): 
                        $isActive = ($d['status'] === 'active');
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 700; font-size: 14.5px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid <?= $isActive ? 'fa-tablet-button text-success' : 'fa-ban text-danger' ?>" style="font-size: 16px;"></i>
                                <a href="<?= APP_URL ?>/index.php?view=pos_devices&device_id=<?= $d['id'] ?>" style="color: inherit; text-decoration: none;">
                                    <?= htmlspecialchars($d['device_name']) ?>
                                </a>
                            </div>
                            <div class="text-xs text-muted">Registered: <?= date('d/m/Y', strtotime($d['created_at'])) ?></div>
                        </td>
                        <td>
                            <span class="badge badge-slate" style="font-family: var(--font-mono); font-size: 12px; font-weight: 700;">
                                <?= htmlspecialchars($d['device_uid']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-xs text-muted"><i class="fa-solid fa-location-dot mr-1"></i> <?= htmlspecialchars($d['location'] ?? 'Counter') ?></span>
                        </td>
                        <td>
                            <?php $isRowHashed = empty($d['api_key']) || !str_starts_with($d['api_key'], 'pos_sec_'); ?>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <input type="password" value="<?= !empty($d['api_key']) ? htmlspecialchars($d['api_key']) : '[Secured as SHA-256 Hash]' ?>" readonly id="key_<?= (int)$d['id'] ?>" style="font-family: var(--font-mono); font-size: 12px; background: var(--bg-subtle); border: 1px solid var(--border); border-radius: 4px; padding: 4px 8px; width: 170px; color: var(--text-muted);" title="Stored as SHA-256 hash.">
                                <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px;<?= $isRowHashed ? ' cursor: not-allowed; opacity: 0.5;' : '' ?>" onclick="copyApiKey('key_<?= (int)$d['id'] ?>')" title="<?= $isRowHashed ? 'The key is stored hashed and cannot be copied.' : 'Copy Key' ?>" <?= $isRowHashed ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px;" onclick="toggleKeyVisibility('key_<?= (int)$d['id'] ?>')" title="Show/Hide">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Active / Allowed</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><i class="fa-solid fa-ban mr-1"></i> Blocked</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($d['last_seen_at'])): ?>
                                <div style="font-size: 12px; font-weight: 600;"><?= date('d/m/Y H:i', strtotime($d['last_seen_at'])) ?></div>
                                <div class="text-xs text-muted">IP: <?= htmlspecialchars($d['last_ip'] ?? '-') ?></div>
                            <?php else: ?>
                                <span class="text-xs text-muted">Never connected</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                <a href="<?= APP_URL ?>/index.php?view=pos_devices&device_id=<?= $d['id'] ?>" class="btn btn-secondary btn-sm" title="View Terminal Details">
                                    <i class="fa-solid fa-eye"></i>
                                </a>

                                <!-- Block / Unblock Button -->
                                <form action="" method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $isActive ? 'blocked' : 'active' ?>">
                                    <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-secondary' : 'btn-primary' ?>" style="<?= $isActive ? 'color: var(--danger);' : '' ?>" title="<?= $isActive ? 'Block device access' : 'Unblock device' ?>">
                                        <i class="fa-solid <?= $isActive ? 'fa-ban' : 'fa-check' ?>"></i>
                                    </button>
                                </form>

                                <!-- Regenerate Key Button -->
                                <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Regenerating will invalidate the old key on this device. Continue?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="regenerate_key">
                                    <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Regenerate API Key">
                                        <i class="fa-solid fa-rotate"></i>
                                    </button>
                                </form>

                                <!-- Delete Device Button -->
                                <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Remove device permanently?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_device">
                                    <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Delete Device">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- POS Daily Closings Section (All Devices History) -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-cash-register mr-1" style="color: var(--primary);"></i> POS Daily Closings History
        </h2>
        <span class="text-xs text-muted">Shift reconciliation log across all connected counter terminals</span>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Close Date</th>
                    <th>Terminal / Device</th>
                    <th>Cashier</th>
                    <th>Opening Time</th>
                    <th style="text-align: right;">Cash Sales</th>
                    <th style="text-align: right;">Expected Cash</th>
                    <th style="text-align: right;">Counted Cash</th>
                    <th style="text-align: right;">Difference</th>
                    <th>Notes</th>
                    <th>Closed At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allClosings)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 32px; color: var(--text-muted);">
                            No POS daily closings submitted yet. Cashiers can close their shifts directly on the POS terminals.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($allClosings as $ac): 
                        $diff = (float)($ac['difference_usd'] ?? $ac['difference'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ac['close_date']) ?></strong></td>
                        <td>
                            <strong><?= htmlspecialchars($ac['device_name'] ?? ('Device #' . $ac['pos_device_id'])) ?></strong>
                            <div class="text-xs text-muted"><?= htmlspecialchars($ac['device_uid'] ?? '') ?></div>
                        </td>
                        <td><?= htmlspecialchars($ac['cashier_name'] ?? $ac['closed_by_name'] ?? 'Staff') ?></td>
                        <td><?= !empty($ac['opening_time']) ? htmlspecialchars(date('H:i', strtotime($ac['opening_time']))) : '-' ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums;"><?= formatUSD((float)($ac['cash_sales_usd'] ?? $ac['cash_sales'] ?? 0)) ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums; font-weight: 600;"><?= formatUSD((float)($ac['expected_cash_usd'] ?? $ac['expected_cash'] ?? 0)) ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums; font-weight: 700;"><?= formatUSD((float)($ac['counted_cash_usd'] ?? $ac['counted_cash'] ?? 0)) ?></td>
                        <td style="text-align: right; font-variant-numeric: tabular-nums;">
                            <?php if (abs($diff) < 0.01): ?>
                                <span class="badge badge-success" style="font-weight: 700;">$0.00 (Balanced)</span>
                            <?php elseif ($diff < 0): ?>
                                <span class="badge badge-danger" style="font-weight: 700;">-$<?= number_format(abs($diff), 2) ?> (Short)</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-weight: 700;">+$<?= number_format($diff, 2) ?> (Over)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs text-muted"><?= htmlspecialchars((string)($ac['notes'] ?? $ac['note'] ?? '-')) ?></td>
                        <td class="text-xs text-muted"><?= !empty($ac['closed_at']) ? date('d/m/Y H:i', strtotime($ac['closed_at'])) : (!empty($ac['created_at']) ? date('d/m/Y H:i', strtotime($ac['created_at'])) : '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New POS Device -->
<div class="modal-backdrop" id="addDeviceModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fa-solid fa-plus-circle mr-1" style="color: var(--primary);"></i> Authorize New POS Device
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('addDeviceModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="register_device">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Device Name *</label>
                    <input type="text" name="device_name" class="form-input" placeholder="e.g. Counter Tablet #2 (iPad)" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Device Unique Identifier (UID) *</label>
                    <input type="text" name="device_uid" class="form-input" placeholder="e.g. POS-TABLET-02" style="font-family: var(--font-mono); text-transform: uppercase;" required>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Hardware ID, terminal serial, or custom identifier.</div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Counter / Lane Location</label>
                    <input type="text" name="location" class="form-input" placeholder="e.g. Lane 2 Cashier Station">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addDeviceModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check mr-1"></i> Authorize &amp; Generate Key
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function copyNewGeneratedKey() {
    const input = document.getElementById('newGeneratedApiKeyInput');
    if (!input) return;
    const key = (input.value || '').trim();
    if (!key) return;

    const onSuccess = () => {
        const feedback = document.getElementById('copyNewApiKeyFeedback');
        const btn = document.getElementById('btnCopyNewApiKey');
        if (feedback) {
            feedback.style.display = 'inline-flex';
            setTimeout(() => { feedback.style.display = 'none'; }, 3000);
        }
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
            setTimeout(() => { btn.innerHTML = orig; }, 2000);
        }
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(key).then(onSuccess).catch(() => {
            input.focus();
            input.select();
            document.execCommand('copy');
            onSuccess();
        });
    } else {
        input.focus();
        input.select();
        document.execCommand('copy');
        onSuccess();
    }
}

function copyApiKey(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const val = (input.value || '').trim();
    if (!val || val === '[Secured as SHA-256 Hash]' || val === '[SHA-256 Hashed]' || !val.startsWith('pos_sec_')) {
        alert('The key is stored hashed and cannot be copied. If you lost it, press Regenerate Key.');
        return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(() => {
            alert('API key copied to clipboard!');
        }).catch(() => {
            input.type = 'text';
            input.select();
            document.execCommand('copy');
            input.type = 'password';
            alert('API key copied to clipboard!');
        });
    } else {
        input.type = 'text';
        input.select();
        document.execCommand('copy');
        input.type = 'password';
        alert('API key copied to clipboard!');
    }
}

function toggleKeyVisibility(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.type = (input.type === 'password') ? 'text' : 'password';
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
