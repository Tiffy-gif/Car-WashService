<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/Loyalty.php';
require_once dirname(__DIR__, 2) . '/core/MembershipManager.php';

Auth::requirePermission('customers.list');
$pageTitle = __('nav_customers');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_customer') {
        $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        if ($customerId && !Auth::can('customers.edit')) {
            $message = 'Permission denied: Cannot edit customer.';
            $messageType = 'danger';
        } elseif (!$customerId && !Auth::can('customers.add')) {
            $message = 'Permission denied: Cannot add customer.';
            $messageType = 'danger';
        } else {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Vehicle info
        $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $province = trim($_POST['plate_province'] ?? 'Phnom Penh');
        $vType = trim($_POST['vehicle_type'] ?? 'small_car');
        $model = trim($_POST['make_model'] ?? '');
        $color = trim($_POST['color'] ?? '');

        if ($name === '') {
            $message = 'Customer name is required.';
            $messageType = 'danger';
        } else {
            $phoneVal = ($phone !== '') ? $phone : null;
            $notesVal = ($notes !== '') ? $notes : null;
            $tierCode = strtolower(trim($_POST['membership_tier_code'] ?? 'standard'));
            $expiresAt = !empty($_POST['membership_expires_at']) ? trim($_POST['membership_expires_at']) : null;
            $optIn = isset($_POST['message_opt_in']) ? (int)$_POST['message_opt_in'] : 1;

            if ($customerId) {
                // Update Customer
                $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, notes = ?, message_opt_in = ?, message_opt_in_updated_at = NOW() WHERE id = ?");
                $stmt->execute([$name, $phoneVal, $notesVal, $optIn, $customerId]);
                MembershipManager::linkCustomerToTier($pdo, $customerId, $tierCode, null, $expiresAt);
            } else {
                // Insert Customer
                $stmt = $pdo->prepare("INSERT INTO customers (name, phone, notes, status, message_opt_in, message_opt_in_updated_at) VALUES (?, ?, ?, 'active', ?, NOW())");
                $stmt->execute([$name, $phoneVal, $notesVal, $optIn]);
                $customerId = (int)$pdo->lastInsertId();
                MembershipManager::linkCustomerToTier($pdo, $customerId, $tierCode, null, $expiresAt);

                // If plate is provided, insert primary vehicle
                if ($plate !== '') {
                    $vStmt = $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number, plate_province, vehicle_type, make_model, color) VALUES (?, ?, ?, ?, ?, ?)");
                    $vStmt->execute([$customerId, $plate, $province, $vType, $model, $color]);
                }
            }
            $message = 'Customer saved successfully!';
        }
        }
    } elseif ($action === 'toggle_message_opt_in') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $newOptIn = (int)($_POST['message_opt_in'] ?? 1);
        if ($customerId > 0) {
            $pdo->prepare("UPDATE customers SET message_opt_in = ?, message_opt_in_updated_at = NOW() WHERE id = ?")->execute([$newOptIn, $customerId]);
            AuditLogger::log('CUSTOMER_OPT_IN_UPDATED', "Customer #{$customerId} message opt-in set to " . ($newOptIn ? 'Opted In' : 'Opted Out'), 'customers', (string)$customerId);
            $message = $newOptIn ? 'Customer opted in for service reminders!' : 'Customer opted out. System will strictly never send reminders to this customer.';
        }
    } elseif ($action === 'add_vehicle_to_customer') {
        if (!Auth::can('customers.edit')) {
            $message = 'Permission denied: Cannot add vehicle to customer.';
            $messageType = 'danger';
        } else {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
            $province = trim($_POST['plate_province'] ?? 'Phnom Penh');
            $vType = trim($_POST['vehicle_type'] ?? 'small_car');
            $model = trim($_POST['make_model'] ?? '');
            $color = trim($_POST['color'] ?? '');

            if ($customerId > 0 && $plate !== '') {
                $vStmt = $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number, plate_province, vehicle_type, make_model, color) VALUES (?, ?, ?, ?, ?, ?)");
                $vStmt->execute([$customerId, $plate, $province, $vType, $model, $color]);
                $message = 'New vehicle added to customer!';
            }
        }
    } elseif ($action === 'toggle_customer_status') {
        if (!Auth::can('customers.delete') && !Auth::can('customers.edit')) {
            $message = 'Permission denied: Cannot change customer status.';
            $messageType = 'danger';
        } else {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $newStatus = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
            if ($customerId > 0) {
                $pdo->prepare("UPDATE customers SET status = ? WHERE id = ?")->execute([$newStatus, $customerId]);
                $message = ($newStatus === 'active') ? 'Customer reactivated successfully!' : 'Customer deactivated successfully! Historical orders stay safe.';
            }
        }
    } elseif ($action === 'adjust_loyalty_points') {
        if (!Auth::can('customers.adjust_points') && !Auth::can('extras.loyalty')) {
            Auth::requirePermission('customers.adjust_points');
        }
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $pointsChange = (int)($_POST['points_change'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $userId = Auth::getId() ? (int)Auth::getId() : null;

        if ($customerId <= 0) {
            $message = 'Invalid customer specified.';
            $messageType = 'danger';
        } elseif ($pointsChange === 0) {
            $message = 'Points adjustment cannot be zero.';
            $messageType = 'danger';
        } elseif ($reason === '') {
            $message = 'A reason is mandatory for manual loyalty adjustments.';
            $messageType = 'danger';
        } else {
            try {
                $adjResult = Loyalty::manualAdjust($customerId, $pointsChange, $reason, $userId);
                if (!empty($adjResult['success'])) {
                    $message = "Loyalty points adjusted (" . ($pointsChange > 0 ? "+{$pointsChange}" : $pointsChange) . " pts). New balance: {$adjResult['balance_after']} pts (Tier: " . ucfirst($adjResult['membership_level']) . ").";
                    $messageType = 'success';
                } else {
                    $message = 'Error adjusting loyalty points: ' . ($adjResult['error'] ?? 'Adjustment failed');
                    $messageType = 'danger';
                }
            } catch (Exception $e) {
                $message = 'Error adjusting loyalty points: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Handle AJAX request for loyalty history
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'loyalty_history') {
    header('Content-Type: application/json; charset=utf-8');
    $cId = (int)($_GET['customer_id'] ?? 0);
    $history = Loyalty::getCustomerHistory($cId);
    $cStmt = $pdo->prepare("SELECT id, name, phone, loyalty_points, lifetime_points, membership_level FROM customers WHERE id = ?");
    $cStmt->execute([$cId]);
    $cust = $cStmt->fetch();
    echo json_encode([
        'success' => true,
        'customer' => $cust,
        'history' => $history
    ]);
    exit;
}

// Fetch all customers with their vehicles
$custStmt = $pdo->query("SELECT * FROM customers ORDER BY id DESC");
$customers = $custStmt->fetchAll();

// Fetch vehicles
$vehStmt = $pdo->query("SELECT * FROM vehicles ORDER BY id ASC");
$allVehicles = $vehStmt->fetchAll();
$vehiclesByCust = [];
foreach ($allVehicles as $v) {
    if ($v['customer_id']) {
        $vehiclesByCust[$v['customer_id']][] = $v;
    }
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars(__('nav_customers')) ?></h1>
        <p style="font-size: 14px; color: var(--text-muted);">Manage customer records, phone numbers, and registered vehicle plates.</p>
    </div>
    <div style="display: flex; gap: 12px; align-items: center;">
        <button type="button" class="btn btn-primary" onclick="openNewCustomerModal()">
            <i class="fa-solid fa-user-plus mr-1"></i> <?= htmlspecialchars(__('add_customer')) ?>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Instant Search Bar -->
<div class="card" style="margin-bottom: 20px; padding: 16px 20px;">
    <div style="position: relative;">
        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted); font-size: 16px;"></i>
        <input type="text" id="tableSearchInput" class="form-input" style="padding-left: 44px; font-size: 15px;" placeholder="<?= htmlspecialchars(__('search_placeholder')) ?>" autofocus>
    </div>
</div>

<!-- Customers & Vehicles Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table searchable-table">
            <thead>
                <tr>
                    <th style="width: 220px;"><?= htmlspecialchars(__('customer_name')) ?></th>
                    <th style="width: 150px;"><?= htmlspecialchars(__('phone_number')) ?></th>
                    <th><?= htmlspecialchars(__('vehicles')) ?></th>
                    <th style="width: 120px;"><?= htmlspecialchars(__('loyalty_points')) ?></th>
                    <th style="width: 180px;"><?= htmlspecialchars(__('notes')) ?></th>
                    <th style="text-align: right; width: 140px;"><?= htmlspecialchars(__('actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 32px; color: var(--text-muted);">
                            No customers found. Click "+ Add New Customer" to register one.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): 
                        $custVehicles = $vehiclesByCust[$c['id']] ?? [];
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 700; color: var(--text-main); font-size: 15px;">
                                <?= htmlspecialchars($c['name']) ?>
                            </div>
                            <div class="text-xs text-muted" style="display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                                <span>ID: #<?= (int)$c['id'] ?> • <?= htmlspecialchars($c['membership_level'] ?? 'Standard') ?></span>
                                <?php if (($c['status'] ?? 'active') === 'inactive'): ?>
                                    <span class="badge badge-danger" style="font-size: 10px; padding: 1px 6px;">Inactive</span>
                                <?php else: ?>
                                    <span class="badge badge-success" style="font-size: 10px; padding: 1px 6px;">Active</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($c['phone'])): ?>
                                <span style="font-weight: 600; color: var(--primary);">
                                    <i class="fa-solid fa-phone mr-1" style="font-size: 12px;"></i> <?= htmlspecialchars($c['phone']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 6px;">
                                <?php if (empty($custVehicles)): ?>
                                    <span class="text-xs text-muted">No vehicle registered</span>
                                <?php else: ?>
                                    <?php foreach ($custVehicles as $v): ?>
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span style="font-family: var(--font-mono); font-weight: 700; background: var(--bg-subtle); padding: 2px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border); font-size: 13px;">
                                                <?= htmlspecialchars($v['plate_number']) ?>
                                            </span>
                                            <?= vehicleTypeBadge($v['vehicle_type']) ?>
                                            <?php if (!empty($v['make_model'])): ?>
                                                <span class="text-xs text-muted"><?= htmlspecialchars((string)$v['make_model']) ?> (<?= htmlspecialchars((string)($v['color'] ?? '-')) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php 
                            $cTier = strtolower($c['membership_level'] ?? 'standard');
                            $cBadgeClass = ($cTier === 'platinum') ? 'badge-primary' : (($cTier === 'gold') ? 'badge-amber' : 'badge-slate');
                            ?>
                            <div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
                                <span class="badge <?= $cBadgeClass ?>" style="font-size: 11px; padding: 2px 7px;">
                                    <i class="fa-solid <?= $cTier === 'platinum' ? 'fa-gem' : ($cTier === 'gold' ? 'fa-crown' : 'fa-medal') ?> mr-1"></i> <?= htmlspecialchars(ucfirst($cTier)) ?>
                                </span>
                                <strong style="font-size: 14px; color: var(--primary);">
                                    <?= number_format((int)$c['loyalty_points']) ?> pts
                                </strong>
                                <span class="text-xs text-muted" title="Lifetime points (determines tier)">
                                    Lifetime: <?= number_format((int)($c['lifetime_points'] ?? $c['loyalty_points'])) ?>
                                </span>
                                <?php $isOptedIn = ((int)($c['message_opt_in'] ?? 1) === 1); ?>
                                <form action="" method="POST" style="margin-top: 4px; display: inline-block;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_message_opt_in">
                                    <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                    <input type="hidden" name="message_opt_in" value="<?= $isOptedIn ? 0 : 1 ?>">
                                    <button type="submit" class="badge <?= $isOptedIn ? 'badge-success' : 'badge-danger' ?>" style="cursor: pointer; border: none; font-size: 10px; padding: 2px 6px;" title="Click to <?= $isOptedIn ? 'Opt Out' : 'Opt In' ?>">
                                        <i class="fa-solid <?= $isOptedIn ? 'fa-bell' : 'fa-bell-slash' ?> mr-1"></i> <?= $isOptedIn ? 'Opted In' : 'Opted Out' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <td>
                            <div class="text-xs text-muted" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?= safeStr($c['notes']) ?>
                            </div>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <!-- Loyalty History Modal Trigger -->
                            <button type="button" class="btn btn-secondary btn-sm" title="Loyalty Points History" data-cust-id="<?= $c['id'] ?>" data-cust-name="<?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>" onclick="openLoyaltyHistoryModal(this)">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </button>
                            <?php if (Auth::getRole() === 'owner'): ?>
                            <!-- Adjust Points Modal Trigger (Owner Only) -->
                            <button type="button" class="btn btn-secondary btn-sm" title="Adjust Loyalty Points" data-cust-id="<?= $c['id'] ?>" data-cust-name="<?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>" data-cust-points="<?= (int)$c['loyalty_points'] ?>" onclick="openAdjustLoyaltyModal(this)">
                                <i class="fa-solid fa-sliders"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary btn-sm" title="Add Vehicle" data-cust-id="<?= $c['id'] ?>" data-cust-name="<?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>" onclick="openAddVehicleModal(this)">
                                <i class="fa-solid fa-car-side"></i> +
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" title="Edit Customer" data-customer="<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="editCustomer(this)">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <?php if (Auth::getRole() === 'owner'): ?>
                            <form action="" method="POST" style="display: inline-block;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_customer_status">
                                <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                <input type="hidden" name="status" value="<?= (($c['status'] ?? 'active') === 'active') ? 'inactive' : 'active' ?>">
                                <?php if (($c['status'] ?? 'active') === 'active'): ?>
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Deactivate Customer" onclick="return confirm('Deactivate this customer? Their previous orders and records remain safe.');">
                                        <i class="fa-solid fa-ban"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--success);" title="Reactivate Customer">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </button>
                                <?php endif; ?>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add / Edit Customer -->
<div class="modal-backdrop" id="customerModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="custModalTitle"><?= htmlspecialchars(__('add_customer')) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('customerModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_customer">
            <input type="hidden" name="customer_id" id="cust_id" value="">

            <div class="modal-body">
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('customer_name')) ?> *</label>
                        <input type="text" name="name" id="cust_name" class="form-input" placeholder="e.g. Sokha Chan" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('phone_number')) ?></label>
                        <input type="text" name="phone" id="cust_phone" class="form-input" placeholder="e.g. 012 889 900">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= htmlspecialchars(__('notes')) ?></label>
                    <textarea name="notes" id="cust_notes" class="form-textarea" rows="2" placeholder="Preferences or notes..."></textarea>
                </div>

                <!-- Membership Tier & CRM Opt-In -->
                <div style="background: var(--bg-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 16px;">
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 10px; color: var(--text-main);">
                        <i class="fa-solid fa-crown mr-1" style="color: #f59e0b;"></i> Membership Plan &amp; CRM Notifications
                    </h4>
                    <div class="grid grid-cols-2" style="gap: 14px;">
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label class="form-label text-xs">Membership Tier</label>
                            <select name="membership_tier_code" id="cust_tier" class="form-select">
                                <option value="standard">Standard (Pay per use)</option>
                                <option value="silver">Silver Member (Unlimited Basic Wash)</option>
                                <option value="gold">Gold VIP Member (Unlimited Basic + 2x Full Wash)</option>
                                <option value="platinum">Platinum Fleet Member (Unlimited Full Wash + Detailing)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label class="form-label text-xs">Membership Expiry (Optional)</label>
                            <input type="date" name="membership_expires_at" id="cust_expires_at" class="form-input">
                        </div>
                    </div>
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--border);">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text-main);">
                            <input type="checkbox" name="message_opt_in" id="cust_message_opt_in" value="1" checked style="width: 17px; height: 17px; accent-color: var(--primary);">
                            <span><i class="fa-solid fa-bell mr-1 text-primary"></i> Customer Opt-In: Send Service Reminders (Telegram / WhatsApp / SMS)</span>
                        </label>
                        <div class="text-xs text-muted" style="margin-left: 25px; margin-top: 2px;">
                            Uncheck if customer requests no automated messages. System strictly respects opt-out.
                        </div>
                    </div>
                </div>

                <!-- Vehicle fields (only shown for new customer) -->
                <div id="initialVehicleSection">
                    <h4 style="font-size: 14px; font-weight: 700; margin: 16px 0 10px; color: var(--primary);">
                        <i class="fa-solid fa-car mr-1"></i> Register Primary Vehicle (Optional)
                    </h4>
                    <div class="grid grid-cols-2" style="gap: 16px;">
                        <div class="form-group">
                            <label class="form-label"><?= htmlspecialchars(__('plate_number')) ?></label>
                            <input type="text" name="plate_number" id="v_plate" class="form-input" placeholder="e.g. 2BQ-8899" style="text-transform: uppercase;">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= htmlspecialchars(__('vehicle_type')) ?></label>
                            <select name="vehicle_type" id="v_type" class="form-select">
                                <option value="small_car"><?= htmlspecialchars(__('small_car')) ?></option>
                                <option value="motorbike"><?= htmlspecialchars(__('motorbike')) ?></option>
                                <option value="suv_pickup"><?= htmlspecialchars(__('suv_pickup')) ?></option>
                                <option value="van_truck"><?= htmlspecialchars(__('van_truck')) ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-3" style="gap: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?= htmlspecialchars(__('province')) ?></label>
                            <input type="text" name="plate_province" id="v_province" class="form-input" value="Phnom Penh">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= htmlspecialchars(__('make_model')) ?></label>
                            <input type="text" name="make_model" id="v_model" class="form-input" placeholder="e.g. Prius 2012">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= htmlspecialchars(__('color')) ?></label>
                            <input type="text" name="color" id="v_color" class="form-input" placeholder="e.g. White">
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('customerModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= htmlspecialchars(__('save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Additional Vehicle to Customer -->
<div class="modal-backdrop" id="addVehicleModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-car mr-1" style="color: var(--primary);"></i> <?= htmlspecialchars(__('add_vehicle')) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('addVehicleModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_vehicle_to_customer">
            <input type="hidden" name="customer_id" id="add_v_cust_id" value="">

            <div class="modal-body">
                <p id="add_v_cust_name" style="font-weight: 700; color: var(--text-main); margin-bottom: 16px;"></p>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('plate_number')) ?> *</label>
                        <input type="text" name="plate_number" class="form-input" placeholder="e.g. 1AC-5566" style="text-transform: uppercase;" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('vehicle_type')) ?> *</label>
                        <select name="vehicle_type" class="form-select">
                            <option value="motorbike"><?= htmlspecialchars(__('motorbike')) ?></option>
                            <option value="small_car" selected><?= htmlspecialchars(__('small_car')) ?></option>
                            <option value="suv_pickup"><?= htmlspecialchars(__('suv_pickup')) ?></option>
                            <option value="van_truck"><?= htmlspecialchars(__('van_truck')) ?></option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('make_model')) ?></label>
                        <input type="text" name="make_model" class="form-input" placeholder="e.g. Honda Dream">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('color')) ?></label>
                        <input type="text" name="color" class="form-input" placeholder="e.g. Black">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addVehicleModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus mr-1"></i> <?= htmlspecialchars(__('add_vehicle')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Loyalty Points History -->
<div class="modal-backdrop" id="loyaltyHistoryModal">
    <div class="modal" style="max-width: 760px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fa-solid fa-clock-rotate-left mr-1" style="color: var(--primary);"></i>
                <span id="historyCustTitle">Loyalty Points History</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('loyaltyHistoryModal')">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <div id="historyCustSummary" style="display: flex; gap: 16px; margin-bottom: 20px; padding: 14px 18px; background: var(--bg-subtle); border-radius: var(--radius-md); border: 1px solid var(--border);">
                <div>
                    <span class="text-xs text-muted" style="display: block;">Current Balance</span>
                    <strong id="historyCurrentBalance" style="font-size: 18px; color: var(--primary);">0 pts</strong>
                </div>
                <div style="border-left: 1px solid var(--border); padding-left: 16px;">
                    <span class="text-xs text-muted" style="display: block;">Lifetime Points</span>
                    <strong id="historyLifetimePoints" style="font-size: 18px; color: var(--text-main);">0 pts</strong>
                </div>
                <div style="border-left: 1px solid var(--border); padding-left: 16px;">
                    <span class="text-xs text-muted" style="display: block;">Tier Status</span>
                    <span id="historyTierBadge" class="badge badge-slate" style="margin-top: 2px;">Standard</span>
                </div>
                <div style="border-left: 1px solid var(--border); padding-left: 16px;">
                    <span class="text-xs text-muted" style="display: block;">Redeemable Credit</span>
                    <strong id="historyCreditValue" style="font-size: 18px; color: var(--success);">$0.00</strong>
                </div>
            </div>

            <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Type</th>
                            <th>Points</th>
                            <th>Balance After</th>
                            <th>Note / Order</th>
                            <th>Staff</th>
                        </tr>
                    </thead>
                    <tbody id="loyaltyHistoryTableBody">
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">Loading loyalty history...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('loyaltyHistoryModal')"><?= htmlspecialchars(__('close')) ?></button>
        </div>
    </div>
</div>

<!-- Modal: Adjust Loyalty Points (Owner Only) -->
<div class="modal-backdrop" id="adjustLoyaltyModal">
    <div class="modal" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fa-solid fa-sliders mr-1" style="color: var(--warning);"></i>
                <span id="adjustCustTitle">Adjust Loyalty Points</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('adjustLoyaltyModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust_loyalty_points">
            <input type="hidden" name="customer_id" id="adj_cust_id" value="">

            <div class="modal-body" style="padding: 20px;">
                <div style="padding: 12px 16px; background: var(--bg-subtle); border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 20px;">
                    <span class="text-xs text-muted" style="display: block;">Current Customer Balance</span>
                    <strong id="adjCurrentBalance" style="font-size: 18px; color: var(--primary);">0 pts</strong>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label">Points Change (+ or -) *</label>
                    <input type="number" step="1" name="points_change" id="adj_points_change" class="form-input" placeholder="e.g. 50 to add, or -20 to deduct" required oninput="previewBalanceChange()">
                    <span class="text-xs text-muted" style="margin-top: 4px; display: block;">
                        Positive value adds points. Negative value deducts points. Projected balance: <strong id="adjProjectedBalance">0 pts</strong>
                    </span>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason for Adjustment *</label>
                    <textarea name="reason" id="adj_reason" class="form-input" rows="3" placeholder="e.g. Courtesy points for service delay, or customer goodwill" required></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('adjustLoyaltyModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check mr-1"></i> Confirm Adjustment</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentAdjBasePoints = 0;

function openNewCustomerModal() {
    document.getElementById('custModalTitle').innerText = <?= json_encode(__('add_customer')) ?>;
    document.getElementById('cust_id').value = '';
    document.getElementById('cust_name').value = '';
    document.getElementById('cust_phone').value = '';
    document.getElementById('cust_notes').value = '';
    document.getElementById('initialVehicleSection').style.display = 'block';

    document.getElementById('v_plate').value = '';
    document.getElementById('v_model').value = '';
    document.getElementById('v_color').value = '';

    openModal('customerModal');
}

function editCustomer(btnOrObj) {
    let c = btnOrObj;
    if (btnOrObj && btnOrObj.getAttribute && btnOrObj.getAttribute('data-customer')) {
        try {
            c = JSON.parse(btnOrObj.getAttribute('data-customer'));
        } catch (e) {
            console.error('Error parsing customer data:', e);
            return;
        }
    }
    if (!c) return;

    document.getElementById('custModalTitle').innerText = <?= json_encode(__('edit_customer')) ?>;
    document.getElementById('cust_id').value = c.id || '';
    document.getElementById('cust_name').value = c.name || '';
    document.getElementById('cust_phone').value = c.phone || '';
    document.getElementById('cust_notes').value = c.notes || '';
    document.getElementById('cust_tier').value = (c.membership_tier_code || c.membership_level || 'standard').toLowerCase();
    document.getElementById('cust_expires_at').value = c.membership_expires_at || '';
    document.getElementById('cust_message_opt_in').checked = (c.message_opt_in === null || parseInt(c.message_opt_in) === 1);
    // Hide initial vehicle inputs on edit to keep it clean
    document.getElementById('initialVehicleSection').style.display = 'none';

    openModal('customerModal');
}

function openAddVehicleModal(btnOrId, cName) {
    let cId = btnOrId;
    let name = cName;
    if (btnOrId && btnOrId.getAttribute && btnOrId.getAttribute('data-cust-id')) {
        cId = btnOrId.getAttribute('data-cust-id');
        name = btnOrId.getAttribute('data-cust-name');
    }
    document.getElementById('add_v_cust_id').value = cId || '';
    document.getElementById('add_v_cust_name').innerText = 'Adding vehicle for: ' + (name || '');
    openModal('addVehicleModal');
}

function openLoyaltyHistoryModal(btn) {
    const cId = btn.getAttribute('data-cust-id');
    const cName = btn.getAttribute('data-cust-name') || 'Customer';
    document.getElementById('historyCustTitle').innerText = 'Loyalty Points History: ' + cName;
    document.getElementById('loyaltyHistoryTableBody').innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">Loading loyalty history...</td></tr>';
    
    openModal('loyaltyHistoryModal');

    fetch('index.php?view=customers&ajax_action=loyalty_history&customer_id=' + encodeURIComponent(cId))
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('loyaltyHistoryTableBody').innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--danger); padding: 24px;">Failed to load history.</td></tr>';
                return;
            }

            const c = data.customer || {};
            const curPts = parseInt(c.loyalty_points || 0);
            const lifePts = parseInt(c.lifetime_points || curPts);
            const tier = (c.membership_level || 'Standard').toUpperCase();
            const redeemRate = <?= Loyalty::getRedeemRate() ?>;
            const credit = (curPts / Math.max(1, redeemRate)).toFixed(2);

            document.getElementById('historyCurrentBalance').innerText = curPts.toLocaleString() + ' pts';
            document.getElementById('historyLifetimePoints').innerText = lifePts.toLocaleString() + ' pts';
            document.getElementById('historyCreditValue').innerText = '$' + credit;

            const badgeEl = document.getElementById('historyTierBadge');
            badgeEl.innerText = tier;
            badgeEl.className = 'badge ' + (tier === 'PLATINUM' ? 'badge-primary' : (tier === 'GOLD' ? 'badge-amber' : 'badge-slate'));

            const tbody = document.getElementById('loyaltyHistoryTableBody');
            tbody.innerHTML = '';
            if (!data.history || data.history.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No loyalty transactions recorded yet.</td></tr>';
                return;
            }

            data.history.forEach(tx => {
                const tr = document.createElement('tr');
                const pts = parseInt(tx.points);
                const ptsBadge = pts > 0 
                    ? `<span class="badge badge-success">+${pts} pts</span>` 
                    : `<span class="badge badge-danger">${pts} pts</span>`;
                
                let typeBadge = '<span class="badge badge-slate">' + tx.type.toUpperCase() + '</span>';
                if (tx.type === 'earn') typeBadge = '<span class="badge badge-success">EARN</span>';
                else if (tx.type === 'redeem') typeBadge = '<span class="badge badge-warning">REDEEM</span>';
                else if (tx.type === 'reversal') typeBadge = '<span class="badge badge-danger">REVERSAL</span>';
                else if (tx.type === 'adjust') typeBadge = '<span class="badge badge-info">ADJUST</span>';

                let orderInfo = '';
                const ordNum = tx.order_number || tx.order_code;
                if (ordNum) {
                    orderInfo = `<a href="index.php?view=orders&search=${encodeURIComponent(ordNum)}" target="_blank" style="font-weight: 700; color: var(--primary);">${ordNum}</a> `;
                }
                const staffName = tx.creator_name || tx.staff_name || 'System';

                tr.innerHTML = `
                    <td class="text-xs text-muted">${tx.created_at || '-'}</td>
                    <td>${typeBadge}</td>
                    <td>${ptsBadge}</td>
                    <td><strong>${parseInt(tx.balance_after).toLocaleString()} pts</strong></td>
                    <td>${orderInfo}<span class="text-xs">${tx.note || '-'}</span></td>
                    <td class="text-xs text-muted">${staffName}</td>
                `;
                tbody.appendChild(tr);
            });
        })
        .catch(err => {
            console.error(err);
            document.getElementById('loyaltyHistoryTableBody').innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--danger); padding: 24px;">Error connecting to server.</td></tr>';
        });
}

function openAdjustLoyaltyModal(btn) {
    const cId = btn.getAttribute('data-cust-id');
    const cName = btn.getAttribute('data-cust-name') || 'Customer';
    const curPts = parseInt(btn.getAttribute('data-cust-points') || 0);

    currentAdjBasePoints = curPts;
    document.getElementById('adjustCustTitle').innerText = 'Adjust Loyalty Points: ' + cName;
    document.getElementById('adj_cust_id').value = cId;
    document.getElementById('adjCurrentBalance').innerText = curPts.toLocaleString() + ' pts';
    document.getElementById('adj_points_change').value = '';
    document.getElementById('adj_reason').value = '';
    document.getElementById('adjProjectedBalance').innerText = curPts.toLocaleString() + ' pts';

    openModal('adjustLoyaltyModal');
}

function previewBalanceChange() {
    const inputVal = parseInt(document.getElementById('adj_points_change').value || 0);
    const projected = Math.max(0, currentAdjBasePoints + inputVal);
    document.getElementById('adjProjectedBalance').innerText = projected.toLocaleString() + ' pts';
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
