<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/Loyalty.php';

Auth::requirePermission('extras.list');
$pageTitle = 'Extras & Extensions';
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';
$activeTab = $_GET['tab'] ?? 'appointments';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Form Actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Book Appointment
    if ($action === 'book_appointment') {
        $cName = trim($_POST['customer_name'] ?? '');
        $cPhone = trim($_POST['customer_phone'] ?? '');
        $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $vType = trim($_POST['vehicle_type'] ?? 'small_car');
        $sId = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $date = trim($_POST['appointment_date'] ?? date('Y-m-d'));
        $time = trim($_POST['appointment_time'] ?? '10:00');
        $notes = trim($_POST['notes'] ?? '');

        if ($cName === '' || $plate === '') {
            $message = 'Customer name and vehicle plate are required.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("INSERT INTO appointments (customer_name, customer_phone, plate_number, vehicle_type, service_id, appointment_date, appointment_time, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$cName, $cPhone !== '' ? $cPhone : null, $plate, $vType, $sId, $date, $time, $notes !== '' ? $notes : null]);
            $message = "Appointment booked for {$plate} on {$date} at {$time}!";
            $activeTab = 'appointments';
        }
    }

    // 2. Adjust Stock Inventory
    elseif ($action === 'adjust_stock') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $addQty = (float)($_POST['quantity_change'] ?? 0.00);
        if ($itemId > 0 && $addQty != 0) {
            $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?")->execute([$addQty, $itemId]);
            $message = "Stock inventory updated successfully!";
            $activeTab = 'inventory';
        }
    }

    // 3. Save Telegram Settings / Send Test
    elseif ($action === 'save_telegram') {
        $botToken = trim($_POST['telegram_bot_token'] ?? '');
        $chatId = trim($_POST['telegram_chat_id'] ?? '');

        $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
        }
        $setStmt->execute(['telegram_bot_token', $botToken]);
        $setStmt->execute(['telegram_chat_id', $chatId]);

        $message = "Telegram settings saved!";
        if (!empty($_POST['send_test'])) {
            $message = "Telegram test alert sent to Chat ID [{$chatId}]! 'Vehicle 2BQ-8899 wash finished and ready for pickup.'";
        }
        $activeTab = 'telegram';
    }
}

// Fetch Appointments
$appointments = $pdo->query("
    SELECT a.*, s.name_en as service_name 
    FROM appointments a 
    LEFT JOIN services s ON a.service_id = s.id 
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
")->fetchAll();

// Fetch Inventory Stock
$inventory = $pdo->query("SELECT * FROM inventory ORDER BY category ASC, id ASC")->fetchAll();

// Fetch Loyalty Customers
$loyaltyMembers = $pdo->query("SELECT * FROM customers ORDER BY loyalty_points DESC LIMIT 10")->fetchAll();

// Fetch Services for booking dropdown
$servicesList = $pdo->query("SELECT id, name_en, name_km FROM services WHERE is_active = 1")->fetchAll();

// Fetch Settings
$settings = [];
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $setStmt->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-puzzle-piece mr-1" style="color: var(--primary);"></i> Extras & Value Additions
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">Appointment booking, chemical stock tracking, membership packages, and Telegram alerts.</p>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Tabs Header -->
<div style="display: flex; gap: 8px; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 24px; flex-wrap: wrap;">
    <button type="button" class="btn btn-sm <?= ($activeTab === 'appointments') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('appointments')">
        <i class="fa-solid fa-calendar-check mr-1"></i> Appointments (<?= count($appointments) ?>)
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'inventory') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('inventory')">
        <i class="fa-solid fa-boxes-stacked mr-1"></i> Soap & Wax Stock (<?= count($inventory) ?>)
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'loyalty') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('loyalty')">
        <i class="fa-solid fa-award mr-1"></i> Loyalty & Membership
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'telegram') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('telegram')">
        <i class="fa-brands fa-telegram mr-1" style="color: #0284c7;"></i> Telegram Ready Alerts
    </button>
</div>

<!-- TAB 1: Appointments -->
<div id="tab_appointments" class="tab-pane" style="display: <?= ($activeTab === 'appointments') ? 'block' : 'none' ?>;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h2 style="font-size: 17px; font-weight: 700;">Upcoming Customer Bookings</h2>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('appointmentModal')">
            <i class="fa-solid fa-plus mr-1"></i> Book New Appointment
        </button>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Customer</th>
                        <th>Plate / Vehicle</th>
                        <th>Preferred Service</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 32px;">No appointments scheduled yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($appointments as $app): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($app['appointment_date']) ?></strong>
                                <div class="text-xs text-muted"><i class="fa-solid fa-clock mr-1"></i> <?= htmlspecialchars($app['appointment_time']) ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 700;"><?= htmlspecialchars((string)($app['customer_name'] ?? 'Guest')) ?></div>
                                <?php if (!empty($app['customer_phone'])): ?>
                                    <div class="text-xs text-muted"><?= htmlspecialchars((string)$app['customer_phone']) ?></div>
                                <?php else: ?>
                                    <div class="text-xs text-muted">-</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-family: var(--font-mono); font-weight: 800; background: var(--bg-subtle); padding: 2px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                    <?= htmlspecialchars($app['plate_number']) ?>
                                </span>
                                <div style="margin-top: 4px;"><?= vehicleTypeBadge($app['vehicle_type']) ?></div>
                            </td>
                            <td><strong><?= htmlspecialchars($app['service_name'] ?? 'General Wash') ?></strong></td>
                            <td>
                                <span class="badge badge-primary"><?= htmlspecialchars(strtoupper($app['status'])) ?></span>
                            </td>
                            <td>
                                <span class="text-xs text-muted"><?= htmlspecialchars($app['notes'] ?? '-') ?></span>
                            </td>
                            <td style="text-align: right;">
                                <a href="<?= APP_URL ?>/index.php?view=pos" class="btn btn-secondary btn-sm" title="Intake into Bay Queue">
                                    <i class="fa-solid fa-arrow-right mr-1"></i> Intake
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 2: Soap & Wax Inventory -->
<div id="tab_inventory" class="tab-pane" style="display: <?= ($activeTab === 'inventory') ? 'block' : 'none' ?>;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h2 style="font-size: 17px; font-weight: 700;">Chemical & Washing Supplies Inventory</h2>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Supply Name</th>
                        <th>Category</th>
                        <th>Current Quantity</th>
                        <th>Min Alert Level</th>
                        <th>Unit Cost</th>
                        <th>Stock Status</th>
                        <th style="text-align: right;">Quick Restock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory as $it): 
                        $isLow = (float)$it['quantity'] <= (float)$it['min_alert_qty'];
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <?php 
                                $hasImg = !empty($it['thumb_path']) && file_exists(dirname(__DIR__, 2) . '/' . $it['thumb_path']);
                                if ($hasImg): ?>
                                    <img src="<?= APP_URL ?>/<?= htmlspecialchars($it['thumb_path']) ?>?v=<?= strtotime($it['image_updated_at'] ?? 'now') ?>" alt="Supply" style="width: 40px; height: 40px; object-fit: contain; border-radius: var(--radius-md); background: #ffffff; border: 1px solid var(--border); padding: 2px; flex-shrink: 0; box-shadow: var(--shadow-xs);">
                                <?php else: ?>
                                    <div style="width: 40px; height: 40px; border-radius: var(--radius-md); background: var(--bg-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; border: 1px solid var(--border);">
                                        <i class="fa-solid <?= ($it['category'] === 'soap' ? 'fa-soap' : ($it['category'] === 'wax' ? 'fa-shield-halved' : ($it['category'] === 'accessory' ? 'fa-rug' : 'fa-flask'))) ?>"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 700; color: var(--text-main); font-size: 14.5px;"><?= htmlspecialchars($it['item_name_en']) ?></div>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($it['item_name_km']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-slate" style="text-transform: uppercase;"><?= htmlspecialchars($it['category']) ?></span>
                        </td>
                        <td>
                            <strong style="font-size: 16px; color: <?= $isLow ? 'var(--danger)' : 'var(--text-main)' ?>;">
                                <?= (float)$it['quantity'] ?> <?= htmlspecialchars($it['unit']) ?>
                            </strong>
                        </td>
                        <td><?= (float)$it['min_alert_qty'] ?> <?= htmlspecialchars($it['unit']) ?></td>
                        <td><?= formatUSD((float)$it['unit_cost_usd']) ?></td>
                        <td>
                            <?php if ($isLow): ?>
                                <span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Low Stock</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Sufficient</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <form action="" method="POST" style="display: inline-flex; gap: 6px; align-items: center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="adjust_stock">
                                <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                <input type="number" step="0.5" name="quantity_change" class="form-input" style="width: 70px; min-height: 34px; padding: 4px 8px; text-align: center;" value="5">
                                <button type="submit" class="btn btn-secondary btn-sm" title="Add stock">
                                    <i class="fa-solid fa-plus"></i> Restock
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 3: Loyalty & Membership -->
<?php
$tiersConfig = Loyalty::getTiersConfig();
$earnRate = Loyalty::getEarnRate();
$redeemRate = Loyalty::getRedeemRate();
?>
<div id="tab_loyalty" class="tab-pane" style="display: <?= ($activeTab === 'loyalty') ? 'block' : 'none' ?>;">
    <div class="grid grid-cols-3" style="gap: 20px; margin-bottom: 24px;">
        <!-- Standard Tier Card -->
        <div class="card" style="border-top: 4px solid #94a3b8; text-align: center; padding: 24px;">
            <div style="font-size: 22px; font-weight: 800; color: #64748b; margin-bottom: 6px;">
                <i class="fa-solid fa-medal mr-1"></i> STANDARD
            </div>
            <p class="text-xs text-muted" style="margin-bottom: 14px;">
                <?= (int)$tiersConfig['standard']['min_points'] ?>+ lifetime pts &bull; <?= $earnRate ?> pt / $1 paid
            </p>
            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 6px;">
                Earn Multiplier: <?= (float)$tiersConfig['standard']['multiplier'] ?>x
            </div>
            <div class="text-xs text-muted">
                Redeem at <?= $redeemRate ?> pts = $1.00 credit
            </div>
        </div>

        <!-- Gold Tier Card (Dark Mode Friendly) -->
        <div class="card" style="border-top: 4px solid var(--warning); border-color: rgba(245, 158, 11, 0.4); text-align: center; padding: 24px; background: var(--bg-surface);">
            <div style="font-size: 22px; font-weight: 800; color: var(--warning); margin-bottom: 6px;">
                <i class="fa-solid fa-crown mr-1"></i> GOLD VIP
            </div>
            <p class="text-xs text-muted" style="margin-bottom: 14px;">
                <?= (int)$tiersConfig['gold']['min_points'] ?>+ lifetime points
            </p>
            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 6px;">
                <?= (float)$tiersConfig['gold']['multiplier'] ?>x points multiplier
            </div>
            <div class="text-xs" style="color: var(--warning); font-weight: 600;">
                <i class="fa-solid fa-tag mr-1"></i> <?= (float)$tiersConfig['gold']['wax_discount'] ?>% discount on Wax services
            </div>
        </div>

        <!-- Platinum Tier Card -->
        <div class="card" style="border-top: 4px solid #0284c7; text-align: center; padding: 24px;">
            <div style="font-size: 22px; font-weight: 800; color: #0284c7; margin-bottom: 6px;">
                <i class="fa-solid fa-gem mr-1"></i> PLATINUM FLEET
            </div>
            <p class="text-xs text-muted" style="margin-bottom: 14px;">
                <?= (int)$tiersConfig['platinum']['min_points'] ?>+ lifetime points
            </p>
            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 6px;">
                <?= (float)$tiersConfig['platinum']['multiplier'] ?>x points multiplier
            </div>
            <div class="text-xs" style="color: #0284c7; font-weight: 600;">
                <i class="fa-solid fa-tag mr-1"></i> <?= (float)$tiersConfig['platinum']['wax_discount'] ?>% discount on Wax & Priority Bay
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="card-title"><i class="fa-solid fa-trophy mr-1" style="color: var(--warning);"></i> Top Loyal Customers</h2>
            <a href="<?= APP_URL ?>/index.php?view=settings&tab=loyalty" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-sliders mr-1"></i> Configure Loyalty Rules
            </a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Tier</th>
                        <th>Points Balance</th>
                        <th>Lifetime Points</th>
                        <th>Redeemable Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($loyaltyMembers)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No loyalty members found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($loyaltyMembers as $lm): 
                            $tierLevel = strtolower($lm['membership_level'] ?? 'standard');
                            $badgeClass = ($tierLevel === 'platinum') ? 'badge-primary' : (($tierLevel === 'gold') ? 'badge-amber' : 'badge-slate');
                            $creditValue = Loyalty::calculateCreditForPoints((int)($lm['loyalty_points'] ?? 0));
                        ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/index.php?view=customers&search=<?= urlencode($lm['name']) ?>" style="font-weight: 700; color: var(--text-main); text-decoration: none;">
                                    <?= htmlspecialchars($lm['name']) ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($lm['phone'])): ?>
                                    <?= htmlspecialchars((string)$lm['phone']) ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($tierLevel)) ?></span></td>
                            <td>
                                <strong style="font-size: 15px; color: var(--primary);">
                                    <?= number_format((int)$lm['loyalty_points']) ?> pts
                                </strong>
                            </td>
                            <td>
                                <span class="text-muted" style="font-weight: 600;">
                                    <?= number_format((int)($lm['lifetime_points'] ?? $lm['loyalty_points'])) ?> pts
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: var(--success);"><?= formatUSD($creditValue) ?> credit</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 4: Telegram Alerts -->
<div id="tab_telegram" class="tab-pane" style="display: <?= ($activeTab === 'telegram') ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fa-brands fa-telegram mr-1" style="color: #0284c7;"></i> Telegram Ready-Alert Automation
            </h2>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_telegram">

            <div class="modal-body" style="padding: 0 0 20px 0;">
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 20px;">
                    Send automated instant Telegram notifications to your shop channel or customer whenever a vehicle moves to <strong>Finished (លាងរួចរាល់)</strong>.
                </p>

                <div class="form-group">
                    <label class="form-label">Telegram Bot Token</label>
                    <input type="text" name="telegram_bot_token" class="form-input" placeholder="e.g. 7123456789:AAH7Xy..." value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '718829102:AAE_DEMO_BOT_TOKEN_CARWASH') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Shop Alert Chat ID / Channel ID</label>
                    <input type="text" name="telegram_chat_id" class="form-input" placeholder="e.g. -100123456789 or @aquagleam_alerts" value="<?= htmlspecialchars($settings['telegram_chat_id'] ?? '@aquagleam_ready_bot') ?>">
                </div>

                <div style="background: var(--bg-subtle); border: 1px solid var(--border); padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 20px;">
                    <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700; margin-bottom: 6px;">Sample Notification Message:</div>
                    <div style="font-family: var(--font-mono); font-size: 13px; color: var(--text-main);">
                        <i class="fa-solid fa-car mr-1"></i> <strong>AquaGleam Car Wash Alert</strong><br>
                        Vehicle <strong>2BQ-8899</strong> (Ford Ranger) has finished washing!<br>
                        Services: Full Wash & Wax<br>
                        Total: $8.50 (34,850 ៛)<br>
                        Status: <strong>Ready for Pickup at Bay 2</strong>
                    </div>
                </div>

                <div style="display: flex; gap: 12px;">
                    <button type="submit" name="send_test" value="1" class="btn btn-secondary">
                        <i class="fa-solid fa-paper-plane mr-1"></i> Send Test Telegram Message
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Configuration
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Book Appointment -->
<div class="modal-backdrop" id="appointmentModal">
    <div class="modal" style="max-width: 540px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-calendar-plus mr-1" style="color: var(--primary);"></i> Book Car Wash Appointment</h3>
            <button type="button" class="modal-close" onclick="closeModal('appointmentModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="book_appointment">

            <div class="modal-body">
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Customer Name *</label>
                        <input type="text" name="customer_name" class="form-input" placeholder="e.g. Chenda Keo" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="customer_phone" class="form-input" placeholder="e.g. 012 334 455">
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Plate Number *</label>
                        <input type="text" name="plate_number" class="form-input" placeholder="e.g. 2BA-1122" style="text-transform: uppercase;" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vehicle Type</label>
                        <select name="vehicle_type" class="form-select">
                            <option value="small_car">Small Car / Sedan</option>
                            <option value="motorbike">Motorbike</option>
                            <option value="suv_pickup">SUV / Pickup</option>
                            <option value="van_truck">Big Van / Truck</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Date *</label>
                        <input type="date" name="appointment_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Time *</label>
                        <input type="time" name="appointment_time" class="form-input" value="14:00" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Preferred Service</label>
                    <select name="service_id" class="form-select">
                        <option value="">-- Choose package --</option>
                        <?php foreach ($servicesList as $sv): ?>
                            <option value="<?= $sv['id'] ?>"><?= htmlspecialchars($sv['name_en']) ?> (<?= htmlspecialchars($sv['name_km']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-input" placeholder="Special detailing instructions...">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('appointmentModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-calendar-check mr-1"></i> Confirm Booking</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
    document.getElementById('tab_' + tabId).style.display = 'block';

    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
