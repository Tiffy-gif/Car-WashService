<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/Loyalty.php';
require_once dirname(__DIR__, 2) . '/core/CashierControl.php';
require_once dirname(__DIR__, 2) . '/core/StockControl.php';
require_once dirname(__DIR__, 2) . '/core/MembershipManager.php';

Auth::requirePermission('pos.access');
$pageTitle = __('nav_pos');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';

$dayLock = CashierControl::isDayLocked();
$currentShift = CashierControl::getActiveShift((int)Auth::getUserId());

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Open Drawer (No Sale) with Manager PIN
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'open_drawer_no_sale' && !isset($csrfError)) {
    try {
        $reason = trim($_POST['reason'] ?? '');
        $mgrUser = trim($_POST['manager_username'] ?? '');
        $mgrPin = (string)($_POST['manager_pin'] ?? '');
        $res = CashierControl::openDrawerNoSale($reason, $mgrUser, $mgrPin, (int)Auth::getUserId());
        $message = "Cash drawer opened without sale. Event logged: {$res['event_uid']}.";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Handle New Order Submission
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order' && !isset($csrfError)) {
    if (!empty($dayLock['locked'])) {
        $message = 'Cannot create order: ' . $dayLock['message'];
        $messageType = 'danger';
    } elseif (!Auth::can('pos.create_order')) {
        $message = 'Permission denied: Cannot create orders.';
        $messageType = 'danger';
    } else {
    $isWalkIn = !empty($_POST['is_walkin']);
    $customerId = (!$isWalkIn && !empty($_POST['customer_id'])) ? (int)$_POST['customer_id'] : null;
    $vehicleId = (!$isWalkIn && !empty($_POST['vehicle_id'])) ? (int)$_POST['vehicle_id'] : null;
    
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $plateNumber = strtoupper(trim($_POST['plate_number'] ?? ''));
    $vehicleType = trim($_POST['vehicle_type'] ?? 'small_car');
    $notes = trim($_POST['notes'] ?? '');
    $initialStatus = in_array($_POST['initial_status'] ?? '', ['waiting', 'washing'], true) ? $_POST['initial_status'] : 'waiting';
    $selectedServices = $_POST['services'] ?? []; // array of service_ids
    $selectedProducts = $_POST['products'] ?? []; // array of product_ids
    $assignedWashers = $_POST['washers'] ?? []; // array of washer user_ids
    $assignedMechanics = $_POST['mechanics'] ?? []; // array of mechanic user_ids
    $repairNotes = trim($_POST['repair_notes'] ?? '');

    // Mileage tracking
    $defaultOilKm = (int)get_setting('default_oil_mileage', '10000');
    $defaultOilMo = (int)get_setting('default_oil_months', '3');
    $currentMileage = !empty($_POST['current_mileage']) ? (int)$_POST['current_mileage'] : null;
    $mileageInterval = (int)($_POST['mileage_interval'] ?? $defaultOilKm);
    $monthInterval = (int)($_POST['month_interval'] ?? $defaultOilMo);
    
    $nextOilMileage = ($currentMileage !== null && $currentMileage > 0) ? ($currentMileage + $mileageInterval) : null;
    $nextOilDate = ($currentMileage !== null && $currentMileage > 0) ? date('Y-m-d', strtotime("+{$monthInterval} months")) : null;

    if ($plateNumber === '') {
        $message = 'Vehicle Plate Number is required.';
        $messageType = 'danger';
    } elseif (empty($selectedServices) && empty($selectedProducts)) {
        $message = 'Please select at least one wash service, repair job, or product.';
        $messageType = 'danger';
    } else {
        if ($customerName === '') {
            $customerName = $isWalkIn ? 'Walk-in Guest' : 'Customer';
        }

        $subtotalUsd = 0.00;
        $orderItemsToInsert = [];
        $repairServicesNames = [];
        $repairLaborTotal = 0.00;
        $coveredMembershipUsages = [];

        // 1. Calculate Services (Wash & Repair)
        if (!empty($selectedServices)) {
            $inClause = implode(',', array_fill(0, count($selectedServices), '?'));
            $priceParams = array_merge([$vehicleType], array_map('intval', $selectedServices));
            $priceQuery = "
                SELECT s.id, s.name_en, s.name_km, s.category, sp.price_usd, sp.price_khr
                FROM services s
                JOIN service_prices sp ON s.id = sp.service_id
                WHERE sp.vehicle_type = ? AND s.id IN ($inClause)
            ";
            $pStmt = $pdo->prepare($priceQuery);
            $pStmt->execute($priceParams);
            $servicesData = $pStmt->fetchAll();

            foreach ($servicesData as $item) {
                $rawPriceUsd = (float)$item['price_usd'];
                $rawPriceKhr = (int)$item['price_khr'];
                $sName = (Language::current() === 'km') ? $item['name_km'] : $item['name_en'];
                $isRepair = (($item['category'] ?? 'wash') === 'repair');

                // Check Membership Included Services & Limits
                if ($customerId) {
                    $elig = MembershipManager::checkIncludedServiceEligibility($pdo, (int)$customerId, $vehicleId, (int)$item['id']);
                    if ($elig['is_free']) {
                        $rawPriceUsd = 0.00;
                        $rawPriceKhr = 0;
                        $sName .= " (" . ($elig['message'] ?? 'Included in Membership') . ")";
                        $coveredMembershipUsages[] = [
                            'service_id' => (int)$item['id'],
                            'tier_code' => $elig['tier_code']
                        ];
                    } elseif ($elig['is_limit_reached']) {
                        $sName .= " [Limit Reached: " . ($elig['used_this_month']) . "/" . ($elig['limit_qty']) . " Used]";
                    }
                }

                $subtotalUsd += $rawPriceUsd;
                if ($isRepair) {
                    $repairServicesNames[] = $item['name_en'];
                    $repairLaborTotal += $rawPriceUsd;
                }
                $orderItemsToInsert[] = [
                    'type' => $isRepair ? 'repair' : 'service',
                    'service_id' => $item['id'],
                    'product_id' => null,
                    'name' => $sName,
                    'price_usd' => $rawPriceUsd,
                    'price_khr' => $rawPriceKhr,
                    'qty' => 1
                ];
            }
        }

        // 2. Calculate Products & Spare Parts & Check Stock
        $oilProductName = null;
        $sparePartsNames = [];
        $sparePartsTotal = 0.00;

        $stockCheckFailed = false;
        $hasManagerStockOverride = false;
        $stockOverrideMgrUser = trim($_POST['stock_override_mgr_user'] ?? '');
        $stockOverrideMgrPin = (string)($_POST['stock_override_mgr_pin'] ?? '');

        if (!empty($selectedProducts)) {
            $prodInClause = implode(',', array_fill(0, count($selectedProducts), '?'));
            $prodStmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($prodInClause)");
            $prodStmt->execute(array_map('intval', $selectedProducts));
            $productsData = $prodStmt->fetchAll();

            foreach ($productsData as $prod) {
                // Stock check: when product has zero stock, sale is blocked. Manager PIN can override.
                $avail = StockControl::checkAvailability((int)$prod['id'], 1);
                if (!$avail['available']) {
                    if ($stockOverrideMgrUser !== '' && $stockOverrideMgrPin !== '') {
                        try {
                            $mgr = CashierControl::verifyManagerPin($stockOverrideMgrUser, $stockOverrideMgrPin);
                            $hasManagerStockOverride = true;
                        } catch (Exception $e) {
                            $message = "Cannot create order: Product '{$prod['name_en']}' is out of stock. Manager override failed: " . $e->getMessage();
                            $messageType = 'danger';
                            $stockCheckFailed = true;
                            break;
                        }
                    } else {
                        $message = "Cannot create order: Product '{$prod['name_en']}' has zero available stock (Stock: {$prod['stock_qty']}, Reserved: {$prod['reserved_qty']}). Sale is blocked unless overridden by Manager PIN.";
                        $messageType = 'danger';
                        $stockCheckFailed = true;
                        break;
                    }
                }

                $subtotalUsd += (float)$prod['price_usd'];
                $pName = (Language::current() === 'km') ? $prod['name_km'] : $prod['name_en'];
                $isSparePart = ($prod['category'] === 'spare_part');
                if ($isSparePart) {
                    $sparePartsNames[] = $prod['name_en'];
                    $sparePartsTotal += (float)$prod['price_usd'];
                }
                $orderItemsToInsert[] = [
                    'type' => $isSparePart ? 'spare_part' : 'product',
                    'service_id' => null,
                    'product_id' => $prod['id'],
                    'name' => $pName,
                    'price_usd' => (float)$prod['price_usd'],
                    'price_khr' => (int)$prod['price_khr'],
                    'qty' => 1
                ];

                if (in_array($prod['category'], ['engine_oil', 'oil_filter'], true)) {
                    $oilProductName = ($oilProductName ? ($oilProductName . ' + ') : '') . $prod['name_en'];
                }
            }
        }

        if (!$stockCheckFailed) {
        $rawDiscountUsd = (float)($_POST['discount_usd'] ?? 0.00);
        if ($rawDiscountUsd < 0 || $rawDiscountUsd > $subtotalUsd) {
            $message = "Discount ($" . number_format($rawDiscountUsd, 2) . ") cannot exceed order total ($" . number_format($subtotalUsd, 2) . "). Total cannot be below zero.";
            $messageType = 'danger';
        } else {
        $discountUsd = $rawDiscountUsd;
        $totalUsd = max(0.00, round($subtotalUsd - $discountUsd, 2));
        $totalKhr = (int)round($totalUsd * get_exchange_rate());

        // Check if order has repair / maintenance work
        $hasRepair = (!empty($repairServicesNames) || !empty($sparePartsNames) || !empty($assignedMechanics) || !empty($repairNotes));
        $initialRepairStatus = $hasRepair ? (($initialStatus === 'washing') ? 'working' : 'waiting') : null;

        // Generate sequential Order Number and non-repeating unique order UID
        $todayPrefix = 'CW-' . date('Ymd') . '-';
        $seqStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number LIKE ?");
        $seqStmt->execute([$todayPrefix . '%']);
        $seqCount = (int)$seqStmt->fetchColumn() + 1;
        $orderNumber = $todayPrefix . str_pad((string)$seqCount, 3, '0', STR_PAD_LEFT);
        $orderUid = generate_unique_id('ORD');

        $cashierId = Auth::getUserId();
        $startedAt = ($initialStatus === 'washing') ? date('Y-m-d H:i:s') : null;

        // Insert Order with Mileage and Repair Info
        $ordStmt = $pdo->prepare("
            INSERT INTO orders (
                order_number, order_uid, customer_id, vehicle_id, customer_name, customer_phone,
                plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr,
                status, repair_status, repair_notes, payment_method, payment_status, cashier_id, notes, started_at,
                current_mileage, next_oil_mileage, next_oil_date, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', 'unpaid', ?, ?, ?, ?, ?, ?, 1)
        ");
        $ordStmt->execute([
            $orderNumber, $orderUid, $customerId, $vehicleId, $customerName, $customerPhone !== '' ? $customerPhone : null,
            $plateNumber, $vehicleType, $subtotalUsd, $discountUsd, $totalUsd, $totalKhr,
            $initialStatus, $initialRepairStatus, $repairNotes, $cashierId, $notes, $startedAt,
            $currentMileage, $nextOilMileage, $nextOilDate
        ]);
        $orderId = (int)$pdo->lastInsertId();

        // Insert Order Items and Reserve Product/Spare Part Stock
        $itemStmt = $pdo->prepare("
            INSERT INTO order_items (order_id, service_id, product_id, item_type, service_name, price_usd, price_khr, qty)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($orderItemsToInsert as $it) {
            $itemStmt->execute([
                $orderId, $it['service_id'], $it['product_id'], $it['type'],
                $it['name'], $it['price_usd'], $it['price_khr'], $it['qty']
            ]);

            // Reserve stock immediately so it cannot be sold to another customer
            if (in_array($it['type'], ['product', 'spare_part'], true) && !empty($it['product_id'])) {
                StockControl::reserveProduct(
                    (int)$it['product_id'],
                    (int)$it['qty'],
                    $orderNumber,
                    (int)Auth::getUserId(),
                    $hasManagerStockOverride,
                    $stockOverrideMgrUser ?: null,
                    $stockOverrideMgrPin ?: null
                );
            }
        }
        }

        // Record Membership Service Usage
        if ($customerId && !empty($coveredMembershipUsages)) {
            foreach ($coveredMembershipUsages as $cmu) {
                MembershipManager::recordServiceUsage(
                    $pdo,
                    (int)$customerId,
                    $vehicleId,
                    $plateNumber,
                    $cmu['tier_code'],
                    (int)$cmu['service_id'],
                    $orderId,
                    true
                );
            }
        }

        // Insert Oil Change Log if mileage entered or oil selected
        if ($currentMileage !== null && $currentMileage > 0) {
            $oilLogStmt = $pdo->prepare("
                INSERT INTO oil_change_logs (
                    order_id, vehicle_id, customer_id, plate_number, customer_phone,
                    customer_name, oil_product_name, current_mileage, next_due_mileage,
                    oil_change_date, next_due_date, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
            ");
            $oilLogStmt->execute([
                $orderId, $vehicleId, $customerId, $plateNumber, $customerPhone,
                $customerName, ($oilProductName ?: 'Oil Service'),
                $currentMileage, $nextOilMileage, $nextOilDate, $notes
            ]);

            // Update Vehicle record if registered
            if ($vehicleId) {
                $pdo->prepare("UPDATE vehicles SET last_mileage = ?, next_oil_mileage = ?, next_oil_date = ? WHERE id = ?")
                    ->execute([$currentMileage, $nextOilMileage, $nextOilDate, $vehicleId]);
            }
        }

        // Insert Assigned Washers & Calculate Commission
        if (!empty($assignedWashers)) {
            $wInfoStmt = $pdo->prepare("SELECT id, commission_type, commission_rate FROM users WHERE id = ?");
            $wInsStmt = $pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd) VALUES (?, ?, ?)");

            $numWashers = count($assignedWashers);
            foreach ($assignedWashers as $wId) {
                $wId = (int)$wId;
                $wInfoStmt->execute([$wId]);
                $washer = $wInfoStmt->fetch();

                $commUsd = 0.00;
                if ($washer) {
                    if ($washer['commission_type'] === 'percentage') {
                        $commUsd = round(($totalUsd * ((float)$washer['commission_rate'] / 100)) / $numWashers, 2);
                    } else {
                        $commUsd = round((float)$washer['commission_rate'] / $numWashers, 2);
                    }
                }
                $wInsStmt->execute([$orderId, $wId, $commUsd]);
            }
        }

        // Insert Assigned Mechanics & Calculate Commission
        if (!empty($assignedMechanics)) {
            $mInfoStmt = $pdo->prepare("SELECT id, commission_type, commission_rate FROM users WHERE id = ?");
            $mInsStmt = $pdo->prepare("INSERT INTO order_mechanics (order_id, mechanic_id, commission_usd) VALUES (?, ?, ?)");
            $numM = count($assignedMechanics);
            $mechBasis = ($repairLaborTotal + $sparePartsTotal > 0) ? ($repairLaborTotal + $sparePartsTotal) : $totalUsd;

            foreach ($assignedMechanics as $mId) {
                $mId = (int)$mId;
                $mInfoStmt->execute([$mId]);
                $mech = $mInfoStmt->fetch();
                $mCommUsd = 0.00;
                if ($mech) {
                    if ($mech['commission_type'] === 'percentage') {
                        $mCommUsd = round(($mechBasis * ((float)$mech['commission_rate'] / 100)) / $numM, 2);
                    } else {
                        $mCommUsd = round((float)$mech['commission_rate'] / $numM, 2);
                    }
                }
                $mInsStmt->execute([$orderId, $mId, $mCommUsd]);
            }
        }

        // Save into Vehicle Repair History if there's any repair work
        if ($hasRepair) {
            $mechNames = [];
            if (!empty($assignedMechanics)) {
                $mNamesStmt = $pdo->query("SELECT full_name FROM users WHERE id IN (" . implode(',', array_map('intval', $assignedMechanics)) . ")");
                $mechNames = $mNamesStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            $histStmt = $pdo->prepare("
                INSERT INTO vehicle_repair_history (
                    order_id, vehicle_id, plate_number, customer_name, mechanic_name,
                    services_performed, parts_replaced, labor_cost_usd, parts_cost_usd,
                    total_cost_usd, mileage, repair_notes, repair_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $histStmt->execute([
                $orderId, $vehicleId, $plateNumber, $customerName,
                (!empty($mechNames) ? implode(', ', $mechNames) : 'Assigned Mechanic'),
                (!empty($repairServicesNames) ? implode(', ', $repairServicesNames) : 'Minor Repair'),
                (!empty($sparePartsNames) ? implode(', ', $sparePartsNames) : null),
                $repairLaborTotal, $sparePartsTotal,
                ($repairLaborTotal + $sparePartsTotal),
                $currentMileage, $repairNotes
            ]);
        }

        // Smart Queue Engine: compute duration, child tasks, priority, and wait alert
        require_once dirname(__DIR__, 2) . '/core/SmartQueueEngine.php';
        require_once dirname(__DIR__, 2) . '/core/BayManager.php';
        $estDurData = SmartQueueEngine::calculateEstimatedDuration($pdo, array_map('intval', $selectedServices), $vehicleType);
        $estDurMinutes = (int)($estDurData['total_estimated_minutes'] ?? 20);
        $priorityData = BayManager::determinePriority($pdo, [
            'id' => $orderId,
            'customer_id' => $customerId,
            'plate_number' => $plateNumber,
            'vehicle_type' => $vehicleType,
            'estimated_duration_minutes' => $estDurMinutes,
            'arrived_at' => date('Y-m-d H:i:s')
        ]);
        $pdo->prepare("UPDATE orders SET estimated_duration_minutes = ?, priority = ? WHERE id = ?")
            ->execute([$estDurMinutes, $priorityData['code'], $orderId]);
        BayManager::ensureOrderTasks($pdo, $orderId);
        SmartQueueEngine::calculateEstimatedWaitTime($pdo, !empty($selectedServices) ? (int)$selectedServices[0] : 0, $vehicleType);

        // Redirect to Orders queue
        header('Location: ' . APP_URL . '/index.php?view=orders&new_order=' . urlencode($orderNumber));
        exit;
    }
    }
    }
}

// Fetch dynamic categories
$serviceCategories = $pdo->query("SELECT * FROM categories WHERE type = 'service' AND is_active = 1 ORDER BY sort_order ASC, name_en ASC")->fetchAll();
$productCategories = $pdo->query("SELECT * FROM categories WHERE type = 'product' AND is_active = 1 ORDER BY sort_order ASC, name_en ASC")->fetchAll();

// Fetch all services with pricing and category details
$allServices = $pdo->query("
    SELECT s.*, 
           c.id AS cat_id, c.code AS cat_code, c.name_en AS cat_name_en, c.name_km AS cat_name_km, c.icon AS cat_icon, c.color AS cat_color
    FROM services s
    LEFT JOIN categories c ON (s.category_id = c.id OR (s.category_id IS NULL AND s.category = c.code AND c.type = 'service'))
    WHERE s.is_active = 1 
    ORDER BY COALESCE(c.sort_order, 999) ASC, s.id ASC
")->fetchAll();
$allPrices = $pdo->query("SELECT * FROM service_prices")->fetchAll();

$pricingMatrix = [];
foreach ($allPrices as $p) {
    $pricingMatrix[$p['service_id']][$p['vehicle_type']] = [
        'usd' => (float)$p['price_usd'],
        'khr' => (int)$p['price_khr'],
        'min' => (int)$p['estimated_minutes'],
    ];
}

// Group services dynamically by category code
$servicesByCategory = [];
foreach ($serviceCategories as $sc) {
    $servicesByCategory[$sc['code']] = [];
}
$otherServices = [];
foreach ($allServices as $s) {
    $code = $s['cat_code'] ?: ($s['category'] ?? '');
    if (isset($servicesByCategory[$code])) {
        $servicesByCategory[$code][] = $s;
    } else {
        $otherServices[] = $s;
    }
}

// Fetch all products with category details
$allProducts = $pdo->query("
    SELECT p.*, 
           c.id AS cat_id, c.code AS cat_code, c.name_en AS cat_name_en, c.name_km AS cat_name_km, c.icon AS cat_icon, c.color AS cat_color
    FROM products p
    LEFT JOIN categories c ON (p.category_id = c.id OR (p.category_id IS NULL AND p.category = c.code AND c.type = 'product'))
    WHERE p.is_active = 1 
    ORDER BY COALESCE(c.sort_order, 999) ASC, p.id ASC
")->fetchAll();

// Fetch active customers and vehicles
$customersList = $pdo->query("SELECT id, name, phone, loyalty_points, lifetime_points, membership_level FROM customers WHERE (status = 'active' OR status IS NULL) ORDER BY name ASC")->fetchAll();
$vehiclesList = $pdo->query("SELECT id, customer_id, plate_number, vehicle_type, make_model, color, last_mileage FROM vehicles ORDER BY plate_number ASC")->fetchAll();

$vehiclesByCustId = [];
foreach ($vehiclesList as $v) {
    $vehiclesByCustId[$v['customer_id']][] = $v;
}

// Fetch active washers & mechanics (check capabilities or legacy role)
$washersList = $pdo->query("SELECT u.id, u.full_name, u.commission_type, u.commission_rate FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE (r.can_assign_washer = 1 OR u.role = 'washer') AND u.status = 'active' ORDER BY u.full_name ASC")->fetchAll();
$mechanicsList = $pdo->query("SELECT u.id, u.full_name, u.commission_type, u.commission_rate FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE (r.can_assign_mechanic = 1 OR u.role = 'mechanic') AND u.status = 'active' ORDER BY u.full_name ASC")->fetchAll();

// Fetch plates with past repair history
$historyPlates = $pdo->query("SELECT DISTINCT plate_number FROM vehicle_repair_history")->fetchAll(PDO::FETCH_COLUMN);

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-cash-register mr-1" style="color: var(--primary);"></i> <?= htmlspecialchars(__('nav_pos')) ?>
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">Intake vehicle, select wash packages, engine oils, car care products, and track mileage.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="<?= APP_URL ?>/index.php?view=shift" class="btn btn-secondary">
            <i class="fa-solid fa-hand-holding-dollar mr-1" style="color: var(--primary);"></i> Shifts &amp; Handover
        </a>
        <button type="button" class="btn btn-secondary" onclick="openModal('posDrawerNoSaleModal')">
            <i class="fa-solid fa-unlock-keyhole mr-1" style="color: #ea580c;"></i> Drawer (No Sale)
        </button>
        <a href="<?= APP_URL ?>/index.php?view=oil_care" class="btn btn-secondary">
            <i class="fa-solid fa-oil-can mr-1" style="color: #ea580c;"></i> Oil Stock
        </a>
        <a href="<?= APP_URL ?>/index.php?view=orders" class="btn btn-secondary">
            <i class="fa-solid fa-list-ol mr-1"></i> <?= htmlspecialchars(__('nav_orders')) ?>
        </a>
    </div>
</div>

<?php if (!empty($dayLock['locked'])): ?>
    <div style="background: #fef2f2; border: 2px solid #ef4444; border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 14px;">
        <i class="fa-solid fa-lock" style="color: #dc2626; font-size: 22px;"></i>
        <div style="flex: 1;">
            <strong style="color: #991b1b; display: block; font-size: 15px;">SALES ARE CURRENTLY LOCKED</strong>
            <span style="color: #7f1d1d; font-size: 13.5px;"><?= htmlspecialchars($dayLock['message']) ?></span>
        </div>
        <a href="<?= APP_URL ?>/index.php?view=shift" class="btn btn-sm btn-danger">Go to Shifts / Close Day</a>
    </div>
<?php elseif (!$currentShift && Auth::getRole() === 'cashier'): ?>
    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 14px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fa-solid fa-triangle-exclamation" style="color: #d97706; font-size: 20px;"></i>
            <div>
                <strong style="color: #92400e; font-size: 14px;">No Active Shift Open</strong>
                <span class="text-xs text-muted" style="display: block;">Please open your shift with starting change fund before processing cash sales.</span>
            </div>
        </div>
        <a href="<?= APP_URL ?>/index.php?view=shift" class="btn btn-sm btn-primary">
            <i class="fa-solid fa-play mr-1"></i> Start Shift
        </a>
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<form action="" method="POST" id="orderForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_order">

    <div class="grid" style="grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
        
        <!-- Left Column: Customer, Vehicle, Services, Products, Oil Tracking, Washers -->
        <div style="display: flex; flex-direction: column; gap: 24px;">

            <!-- 1. Customer & Vehicle Intake Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fa-solid fa-id-card mr-1" style="color: var(--primary);"></i> Customer &amp; Vehicle Details
                    </h2>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-sm btn-primary" id="btnModeWalkIn" onclick="setCustomerMode('walkin')">
                            <i class="fa-solid fa-person-walking mr-1"></i> Walk-in Guest
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" id="btnModeExisting" onclick="setCustomerMode('existing')">
                            <i class="fa-solid fa-address-book mr-1"></i> Saved Customer
                        </button>
                    </div>
                </div>

                <input type="hidden" name="is_walkin" id="is_walkin" value="1">
                <input type="hidden" name="customer_id" id="customer_id" value="">
                <input type="hidden" name="vehicle_id" id="vehicle_id" value="">

                <!-- Existing Customer Picker -->
                <div id="existingCustomerSection" style="display: none; margin-bottom: 18px; padding: 16px; background: var(--bg-subtle); border-radius: var(--radius-md); border: 1px solid var(--border);">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label class="form-label">Search / Select Saved Customer</label>
                        <select id="existingCustomerSelect" class="form-select" onchange="onExistingCustomerSelected()">
                            <option value="">-- Choose a registered customer --</option>
                            <?php foreach ($customersList as $cust): ?>
                                <option value="<?= $cust['id'] ?>" 
                                        data-name="<?= htmlspecialchars($cust['name']) ?>" 
                                        data-phone="<?= htmlspecialchars((string)($cust['phone'] ?? '')) ?>"
                                        data-points="<?= (int)$cust['loyalty_points'] ?>"
                                        data-lifetime="<?= (int)($cust['lifetime_points'] ?? $cust['loyalty_points']) ?>"
                                        data-tier="<?= htmlspecialchars(strtolower($cust['membership_level'] ?? 'standard')) ?>">
                                    <?= htmlspecialchars($cust['name']) ?> (<?= !empty($cust['phone']) ? htmlspecialchars($cust['phone']) : '-' ?>) &bull; <?= (int)$cust['loyalty_points'] ?> pts (<?= ucfirst(htmlspecialchars($cust['membership_level'] ?? 'Standard')) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Customer Loyalty Info Banner -->
                    <div id="posCustLoyaltyBanner" style="display: none; margin-bottom: 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-md); padding: 10px 14px; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span id="posCustTierBadge" class="badge badge-slate" style="font-size: 11px;">STANDARD</span>
                                <span style="font-weight: 700; color: #1e40af; margin-left: 6px;">
                                    Balance: <span id="posCustPointsText">0</span> pts
                                </span>
                            </div>
                            <span style="font-size: 11px; color: #2563eb; font-weight: 600;">
                                Value: $<span id="posCustCreditText">0.00</span> credit
                            </span>
                        </div>
                    </div>

                    <div id="customerVehiclesPicker" style="display: none;">
                        <label class="form-label">Select Registered Vehicle</label>
                        <select id="existingVehicleSelect" class="form-select" onchange="onExistingVehicleSelected()">
                            <!-- Populated dynamically via JS -->
                        </select>
                    </div>
                </div>

                <!-- Customer Fields -->
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label class="form-label" style="margin-bottom: 0;"><?= htmlspecialchars(__('plate_number')) ?> *</label>
                            <a id="btnCheckHistory" href="#" target="_blank" class="btn btn-sm" style="display: none; padding: 2px 8px; font-size: 11px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; border-radius: 4px;">
                                <i class="fa-solid fa-clock-rotate-left mr-1"></i> Past Repair Log
                            </a>
                        </div>
                        <input type="text" name="plate_number" id="plate_number" class="form-input" placeholder="e.g. 2BQ-8899" style="font-family: var(--font-mono); font-weight: 700; text-transform: uppercase;" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('phone_number')) ?></label>
                        <input type="text" name="customer_phone" id="customer_phone" class="form-input" placeholder="e.g. 012 345 678">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label"><?= htmlspecialchars(__('customer_name')) ?></label>
                    <input type="text" name="customer_name" id="customer_name" class="form-input" placeholder="e.g. Walk-in Guest or Customer Name">
                </div>
            </div>

            <!-- 2. Vehicle Size Selection (Cards with icons) -->
            <div class="card">
                <div class="card-header" style="margin-bottom: 12px;">
                    <h2 class="card-title">
                        <i class="fa-solid fa-car mr-1" style="color: var(--primary);"></i> <?= htmlspecialchars(__('vehicle_type')) ?>
                    </h2>
                    <span class="text-xs text-muted">Wash prices adjust dynamically by vehicle category</span>
                </div>

                <input type="hidden" name="vehicle_type" id="selected_vehicle_type" value="small_car">

                <div class="grid grid-cols-4" style="gap: 12px;" id="vehicleTypeCards">
                    <div class="vtype-card" data-type="motorbike" onclick="selectVehicleType('motorbike')">
                        <div class="vtype-icon" style="color: #9333ea; background: #f3e8ff;">
                            <i class="fa-solid fa-motorcycle"></i>
                        </div>
                        <div class="vtype-label"><?= htmlspecialchars(__('motorbike')) ?></div>
                        <div class="vtype-sub">Moto / Scooter</div>
                    </div>

                    <div class="vtype-card active" data-type="small_car" onclick="selectVehicleType('small_car')">
                        <div class="vtype-icon" style="color: #0284c7; background: #e0f2fe;">
                            <i class="fa-solid fa-car-side"></i>
                        </div>
                        <div class="vtype-label"><?= htmlspecialchars(__('small_car')) ?></div>
                        <div class="vtype-sub">Sedan / Hatchback</div>
                    </div>

                    <div class="vtype-card" data-type="suv_pickup" onclick="selectVehicleType('suv_pickup')">
                        <div class="vtype-icon" style="color: #d97706; background: #fef3c7;">
                            <i class="fa-solid fa-truck-pickup"></i>
                        </div>
                        <div class="vtype-label"><?= htmlspecialchars(__('suv_pickup')) ?></div>
                        <div class="vtype-sub">SUV / Pickup</div>
                    </div>

                    <div class="vtype-card" data-type="van_truck" onclick="selectVehicleType('van_truck')">
                        <div class="vtype-icon" style="color: #475569; background: var(--bg-subtle);">
                            <i class="fa-solid fa-van-shuttle"></i>
                        </div>
                        <div class="vtype-label"><?= htmlspecialchars(__('van_truck')) ?></div>
                        <div class="vtype-sub">Van / Truck / Heavy</div>
                    </div>
                </div>
            </div>

            <!-- 3. Services & Products Selection Tabs -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;" id="catalogTabsBar">
                        <?php 
                        $firstTabKey = !empty($serviceCategories) ? $serviceCategories[0]['code'] : 'services';
                        foreach ($serviceCategories as $scIndex => $sc): 
                            $scCode = $sc['code'];
                            $scName = (Language::current() === 'km') ? $sc['name_km'] : $sc['name_en'];
                            $scIcon = !empty($sc['icon']) ? $sc['icon'] : 'fa-shower';
                            $scColor = !empty($sc['color']) ? $sc['color'] : 'var(--primary)';
                            $catSvcs = $servicesByCategory[$scCode] ?? [];
                        ?>
                            <button type="button" class="btn btn-sm catalog-tab-btn <?= ($scIndex === 0) ? 'btn-primary' : 'btn-secondary' ?>" id="btnTab_<?= htmlspecialchars($scCode) ?>" data-tab="<?= htmlspecialchars($scCode) ?>" onclick="switchCatalogTab('<?= htmlspecialchars($scCode) ?>')">
                                <i class="fa-solid <?= htmlspecialchars($scIcon) ?> mr-1" style="color: <?= htmlspecialchars($scColor) ?>;"></i> <?= htmlspecialchars($scName) ?> (<?= count($catSvcs) ?>)
                            </button>
                        <?php endforeach; ?>

                        <?php if (!empty($otherServices)): ?>
                            <button type="button" class="btn btn-sm catalog-tab-btn btn-secondary" id="btnTab_other" data-tab="other" onclick="switchCatalogTab('other')">
                                <i class="fa-solid fa-layer-group mr-1"></i> Other (<?= count($otherServices) ?>)
                            </button>
                        <?php endif; ?>

                        <button type="button" class="btn btn-sm catalog-tab-btn btn-secondary" id="btnTabProducts" data-tab="products" onclick="switchCatalogTab('products')">
                            <i class="fa-solid fa-box-open mr-1" style="color: #9333ea;"></i> <?= htmlspecialchars(__('products')) ?> (<?= count($allProducts) ?>)
                        </button>
                    </div>
                    <span class="text-xs text-muted" id="catalogHint">Tap to select wash packages</span>
                </div>

                <!-- 3A. Service Category Grids -->
                <?php foreach ($serviceCategories as $scIndex => $sc): 
                    $scCode = $sc['code'];
                    $catSvcs = $servicesByCategory[$scCode] ?? [];
                    $scColor = !empty($sc['color']) ? $sc['color'] : 'var(--primary)';
                ?>
                    <div id="catalog_<?= htmlspecialchars($scCode) ?>_view" class="catalog-tab-view" style="<?= ($scIndex === 0) ? '' : 'display: none;' ?>">
                        <?php if (empty($catSvcs)): ?>
                            <div class="text-muted text-xs" style="padding: 24px; text-align: center; background: var(--bg-subtle); border-radius: var(--radius-md);">
                                No services found in this category.
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-2" style="gap: 14px;">
                                <?php foreach ($catSvcs as $s): 
                                    $isRepair = (($s['category'] ?? '') === 'repair');
                                    $sColor = !empty($s['cat_color']) ? $s['cat_color'] : ($isRepair ? '#ea580c' : 'var(--primary)');
                                    $sIcon = !empty($s['cat_icon']) ? $s['cat_icon'] : (!empty($s['icon']) ? $s['icon'] : ($isRepair ? 'fa-wrench' : 'fa-shower'));
                                ?>
                                    <div class="service-select-card <?= $isRepair ? 'repair-card' : '' ?>" data-service-id="<?= $s['id'] ?>" onclick="toggleService(<?= $s['id'] ?>)">
                                        <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" id="chk_service_<?= $s['id'] ?>" style="display: none;">
                                        <div style="display: flex; gap: 14px; align-items: center; width: 100%;">
                                            <?php 
                                            $hasSvcImg = !empty($s['thumb_path']) && file_exists(dirname(__DIR__, 2) . '/' . $s['thumb_path']);
                                            if ($hasSvcImg): ?>
                                                <img src="<?= APP_URL ?>/<?= htmlspecialchars($s['thumb_path']) ?>?v=<?= strtotime($s['image_updated_at'] ?? 'now') ?>" alt="Service" style="width: 44px; height: 44px; object-fit: contain; border-radius: var(--radius-md); background: #ffffff; border: 1px solid var(--border); padding: 2px; flex-shrink: 0;">
                                            <?php else: ?>
                                                <div class="stat-icon" style="width: 44px; height: 44px; font-size: 18px; flex-shrink: 0; background: <?= htmlspecialchars($sColor) ?>18; color: <?= htmlspecialchars($sColor) ?>;">
                                                    <i class="fa-solid <?= htmlspecialchars($sIcon) ?>"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div style="flex: 1; min-width: 0;">
                                                <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">
                                                    <?= htmlspecialchars((Language::current() === 'km') ? $s['name_km'] : $s['name_en']) ?>
                                                </div>
                                                <div class="text-xs text-muted" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    <?= htmlspecialchars($s['description'] ?? '') ?>
                                                </div>
                                            </div>
                                            <div style="text-align: right; flex-shrink: 0;">
                                                <div class="service-price-usd" id="price_usd_<?= $s['id'] ?>" style="font-weight: 800; font-size: 16px; color: <?= htmlspecialchars($sColor) ?>;">$0.00</div>
                                                <div class="service-price-khr text-xs text-muted" id="price_khr_<?= $s['id'] ?>">0 ៛</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($otherServices)): ?>
                    <div id="catalog_other_view" class="catalog-tab-view" style="display: none;">
                        <div class="grid grid-cols-2" style="gap: 14px;">
                            <?php foreach ($otherServices as $s): ?>
                                <div class="service-select-card" data-service-id="<?= $s['id'] ?>" onclick="toggleService(<?= $s['id'] ?>)">
                                    <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" id="chk_service_<?= $s['id'] ?>" style="display: none;">
                                    <div style="display: flex; gap: 14px; align-items: center; width: 100%;">
                                        <div class="stat-icon blue" style="width: 44px; height: 44px; font-size: 18px; flex-shrink: 0;">
                                            <i class="fa-solid <?= htmlspecialchars($s['icon'] ?? 'fa-shower') ?>"></i>
                                        </div>
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">
                                                <?= htmlspecialchars((Language::current() === 'km') ? $s['name_km'] : $s['name_en']) ?>
                                            </div>
                                            <div class="text-xs text-muted" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?= htmlspecialchars($s['description'] ?? '') ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right; flex-shrink: 0;">
                                            <div class="service-price-usd" id="price_usd_<?= $s['id'] ?>" style="font-weight: 800; font-size: 16px; color: var(--primary);">$0.00</div>
                                            <div class="service-price-khr text-xs text-muted" id="price_khr_<?= $s['id'] ?>">0 ៛</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 3B. Products & Spare Parts Grid with Dynamic Category Filter Pills -->
                <div id="catalog_products_view" class="catalog-tab-view" style="display: none;">
                    <!-- Product Category Pills -->
                    <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-xs btn-primary prod-cat-pill active" data-cat="all" onclick="filterProductCategory('all')">
                            All (<?= count($allProducts) ?>)
                        </button>
                        <?php foreach ($productCategories as $pc): 
                            $pcCode = $pc['code'];
                            $pcCount = count(array_filter($allProducts, function($p) use ($pc, $pcCode) {
                                return ($p['category_id'] && (int)$p['category_id'] === (int)$pc['id']) || ($p['category'] === $pcCode);
                            }));
                            if ($pcCount === 0) continue;
                            $pcName = (Language::current() === 'km') ? $pc['name_km'] : $pc['name_en'];
                            $pcIcon = !empty($pc['icon']) ? $pc['icon'] : 'fa-box';
                            $pcColor = !empty($pc['color']) ? $pc['color'] : '#ea580c';
                        ?>
                            <button type="button" class="btn btn-xs btn-secondary prod-cat-pill" data-cat="<?= htmlspecialchars($pcCode) ?>" onclick="filterProductCategory('<?= htmlspecialchars($pcCode) ?>')">
                                <i class="fa-solid <?= htmlspecialchars($pcIcon) ?> mr-1" style="color: <?= htmlspecialchars($pcColor) ?>;"></i>
                                <?= htmlspecialchars($pcName) ?> (<?= $pcCount ?>)
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-cols-2" style="gap: 14px;" id="productsGridContainer">
                        <?php foreach ($allProducts as $p): 
                            $isOutOfStock = ((int)$p['stock_qty'] <= 0);
                            $pCatCode = $p['cat_code'] ?: $p['category'];
                            $pCatName = (Language::current() === 'km') ? ($p['cat_name_km'] ?: $p['cat_name_en']) : ($p['cat_name_en'] ?: ucfirst(str_replace('_', ' ', $p['category'])));
                            $pCatIcon = !empty($p['cat_icon']) ? $p['cat_icon'] : 'fa-spray-can';
                            $pCatColor = !empty($p['cat_color']) ? $p['cat_color'] : '#ea580c';
                        ?>
                            <div class="product-select-card <?= $isOutOfStock ? 'disabled' : '' ?>" data-product-id="<?= $p['id'] ?>" data-category="<?= htmlspecialchars($pCatCode) ?>" data-price="<?= (float)$p['price_usd'] ?>" data-name="<?= htmlspecialchars($p['name_en'], ENT_QUOTES, 'UTF-8') ?>" onclick="toggleProduct(<?= $p['id'] ?>, <?= $isOutOfStock ? 'true' : 'false' ?>)">
                                <input type="checkbox" name="products[]" value="<?= $p['id'] ?>" id="chk_product_<?= $p['id'] ?>" style="display: none;" <?= $isOutOfStock ? 'disabled' : '' ?>>
                                <div style="display: flex; gap: 14px; align-items: center; width: 100%;">
                                    <?php 
                                    $hasProdImg = !empty($p['thumb_path']) && file_exists(dirname(__DIR__, 2) . '/' . $p['thumb_path']);
                                    if ($hasProdImg): ?>
                                        <img src="<?= APP_URL ?>/<?= htmlspecialchars($p['thumb_path']) ?>?v=<?= strtotime($p['image_updated_at'] ?? 'now') ?>" alt="Product" style="width: 44px; height: 44px; object-fit: contain; border-radius: var(--radius-md); background: #ffffff; border: 1px solid var(--border); padding: 2px; flex-shrink: 0;">
                                    <?php else: ?>
                                        <div class="stat-icon" style="width: 44px; height: 44px; font-size: 18px; flex-shrink: 0; background: <?= htmlspecialchars($pCatColor) ?>18; color: <?= htmlspecialchars($pCatColor) ?>;">
                                            <i class="fa-solid <?= htmlspecialchars($pCatIcon) ?>"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 700; font-size: 14.5px; color: var(--text-main);">
                                            <?= htmlspecialchars($p['name_en']) ?>
                                        </div>
                                        <div style="display: flex; gap: 6px; align-items: center; margin-top: 2px; flex-wrap: wrap;">
                                            <span class="badge badge-slate text-xs"><?= htmlspecialchars($p['unit']) ?></span>
                                            <span class="badge text-xs" style="background: <?= htmlspecialchars($pCatColor) ?>18; color: <?= htmlspecialchars($pCatColor) ?>; font-size: 10px;">
                                                <i class="fa-solid <?= htmlspecialchars($pCatIcon) ?> mr-1"></i><?= htmlspecialchars($pCatName) ?>
                                            </span>
                                            <?php if ($isOutOfStock): ?>
                                                <span class="badge badge-danger text-xs">Out of Stock</span>
                                            <?php else: ?>
                                                <span class="text-xs text-muted"><?= (int)$p['stock_qty'] ?> in stock</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right; flex-shrink: 0;">
                                        <div style="font-weight: 800; font-size: 16px; color: #ea580c;"><?= formatUSD((float)$p['price_usd']) ?></div>
                                        <div class="text-xs text-muted"><?= formatKHR((int)$p['price_khr']) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 4. Oil Change & Mileage Tracker Box -->
            <div class="card" id="mileageTrackerCard" style="border-left: 6px solid #ea580c;">
                <div class="card-header" style="margin-bottom: 12px;">
                    <h2 class="card-title">
                        <i class="fa-solid fa-gauge-high mr-1" style="color: #ea580c;"></i> Oil Change Mileage &amp; Next Service Reminder
                    </h2>
                    <span class="text-xs text-muted">Automatically calculates next interval &amp; prints on receipt</span>
                </div>

                <div class="grid grid-cols-3" style="gap: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Current Vehicle Mileage (KM)</label>
                        <input type="number" name="current_mileage" id="current_mileage" class="form-input" placeholder="e.g. 85000" style="font-size: 16px; font-weight: 700;" oninput="recalcOilInterval()">
                    </div>

                    <?php
                    $defOilKm = (int)get_setting('default_oil_mileage', '10000');
                    $defOilMo = (int)get_setting('default_oil_months', '3');
                    ?>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Distance Interval</label>
                        <select name="mileage_interval" id="mileage_interval" class="form-select" onchange="recalcOilInterval()">
                            <?php 
                            $kmOptions = [5000 => '+5,000 KM (Semi-Synthetic)', 8000 => '+8,000 KM (Standard)', 10000 => '+10,000 KM (Full Synthetic)', 15000 => '+15,000 KM (Long Life)'];
                            if (!isset($kmOptions[$defOilKm])) {
                                $kmOptions[$defOilKm] = '+' . number_format($defOilKm) . ' KM (Shop Default)';
                            }
                            foreach ($kmOptions as $kmVal => $kmLabel): 
                            ?>
                                <option value="<?= $kmVal ?>" <?= ($kmVal === $defOilKm) ? 'selected' : '' ?>><?= $kmLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Time Interval</label>
                        <select name="month_interval" id="month_interval" class="form-select" onchange="recalcOilInterval()">
                            <?php 
                            $moOptions = [3 => '+3 Months', 6 => '+6 Months', 12 => '+1 Year'];
                            if (!isset($moOptions[$defOilMo])) {
                                $moOptions[$defOilMo] = '+' . $defOilMo . ' Months (Shop Default)';
                            }
                            foreach ($moOptions as $moVal => $moLabel): 
                            ?>
                                <option value="<?= $moVal ?>" <?= ($moVal === $defOilMo) ? 'selected' : '' ?>><?= $moLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Live Calculated Target Reminder Preview Box -->
                <div style="background: #fff7ed; border: 1px solid #ffedd5; border-radius: var(--radius-md); padding: 14px 18px; margin-top: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="stat-icon" style="width: 42px; height: 42px; font-size: 18px; background: #ffedd5; color: #ea580c;">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                        <div>
                            <div class="text-xs" style="color: #9a3412; font-weight: 700; text-transform: uppercase;">Next Oil Change Target:</div>
                            <div style="font-size: 16px; font-weight: 800; color: #c2410c;">
                                At <span id="previewNextMileage">---</span> KM &bull; By <span id="previewNextDate">---</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-xs text-muted">
                        <i class="fa-solid fa-print mr-1"></i> Will be printed directly on customer's 80mm receipt
                    </div>
                </div>
            </div>

            <!-- 5. Washer & Mechanic Staff Crew Assignment -->
            <div class="card">
                <div class="card-header" style="margin-bottom: 14px;">
                    <h2 class="card-title">
                        <i class="fa-solid fa-user-group mr-1" style="color: var(--primary);"></i> Assign Staff Crew (Washers &amp; Mechanics)
                    </h2>
                    <span class="text-xs text-muted">Select technicians on duty for this job</span>
                </div>

                <!-- Washers List -->
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px;">
                        <i class="fa-solid fa-soap mr-1" style="color: var(--primary);"></i> Wash Crew:
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if (empty($washersList)): ?>
                            <span class="text-xs text-muted">No active washers found.</span>
                        <?php else: ?>
                            <?php foreach ($washersList as $w): ?>
                                <label class="washer-pill" id="washer_pill_<?= $w['id'] ?>">
                                    <input type="checkbox" name="washers[]" value="<?= $w['id'] ?>" onchange="toggleWasherPill(<?= $w['id'] ?>)">
                                    <i class="fa-solid fa-soap mr-1"></i>
                                    <span><?= htmlspecialchars($w['full_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Mechanics List -->
                <div style="margin-bottom: 20px; padding-top: 14px; border-top: 1px dashed var(--border);">
                    <div style="font-size: 13px; font-weight: 700; color: #ea580c; text-transform: uppercase; margin-bottom: 8px;">
                        <i class="fa-solid fa-wrench mr-1"></i> Mechanic Crew (Minor Repair &amp; Maintenance):
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if (empty($mechanicsList)): ?>
                            <span class="text-xs text-muted">No active mechanics found.</span>
                        <?php else: ?>
                            <?php foreach ($mechanicsList as $m): ?>
                                <label class="washer-pill mechanic-pill" id="mechanic_pill_<?= $m['id'] ?>">
                                    <input type="checkbox" name="mechanics[]" value="<?= $m['id'] ?>" onchange="toggleMechanicPill(<?= $m['id'] ?>)">
                                    <i class="fa-solid fa-wrench mr-1"></i>
                                    <span><?= htmlspecialchars($m['full_name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status and Notes -->
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Initial Wash Status</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 12px; pointer-events: none; color: var(--primary); font-size: 14px; z-index: 2;">
                                <i id="pos_status_icon" class="fa-solid fa-hourglass-half"></i>
                            </span>
                            <select name="initial_status" id="pos_initial_status" class="form-select" style="padding-left: 36px; width: 100%;" onchange="updatePosStatusIcon(this.value)">
                                <option value="waiting">Waiting in Queue (រង់ចាំ)</option>
                                <option value="washing">Start Working Now (កំពុងលាង/ប្តូរប្រេង)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Special Request / Wash Note</label>
                        <input type="text" name="notes" class="form-input" placeholder="e.g. Clean engine bay, wipe windshield clean">
                    </div>
                </div>

                <!-- Mechanic Short Job Note -->
                <div class="form-group" style="margin-top: 14px; margin-bottom: 0;">
                    <label class="form-label" style="color: #ea580c;">
                        <i class="fa-solid fa-clipboard-check mr-1"></i> Mechanic Repair Note (Saved to vehicle repair history)
                    </label>
                    <input type="text" name="repair_notes" id="repair_notes" class="form-input" placeholder='e.g. "front left tire is worn out", "replaced front brake pads", "battery terminal cleaned"'>
                </div>
            </div>

        </div>

        <!-- Right Column: Order Summary / Checkout Sticky Card -->
        <div style="position: sticky; top: 94px;">
            <div class="card" style="box-shadow: var(--shadow-md); border-top: 4px solid var(--primary);">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fa-solid fa-receipt mr-1" style="color: var(--primary);"></i> Order Summary
                    </h2>
                </div>

                <!-- Selected Vehicle Plate Box -->
                <div style="background: var(--bg-subtle); padding: 14px 18px; border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 20px;">
                    <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 600;">Vehicle &amp; Plate</div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                        <span id="summaryPlate" style="font-family: var(--font-mono); font-size: 20px; font-weight: 800; color: var(--text-main);">
                            --- ----
                        </span>
                        <span id="summaryVTypeBadge" class="badge badge-blue">Small Car</span>
                    </div>
                </div>

                <!-- Selected Items Breakdown List -->
                <div style="margin-bottom: 20px;">
                    <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 600; margin-bottom: 10px;">Selected Services &amp; Products</div>
                    <div id="summaryServicesList" style="display: flex; flex-direction: column; gap: 8px;">
                        <div class="text-xs text-muted" id="noServicesNotice" style="padding: 12px; background: var(--bg-subtle); border-radius: var(--radius-sm); text-align: center;">
                            No services or products selected yet.
                        </div>
                    </div>
                </div>

                <!-- Membership Tier Benefits & Limit Notice -->
                <div id="posMembershipNoticeBox" style="display: none; margin-bottom: 14px; padding: 10px 12px; border-radius: var(--radius-md); font-size: 12.5px;"></div>

                <!-- Loyalty Reward Milestone Alert -->
                <div id="posLoyaltyRewardAlert" style="display: none; margin-bottom: 14px; padding: 10px 12px; border-radius: var(--radius-md); background: #fef3c7; border: 1px solid #fde68a; color: #92400e; font-size: 12.5px;"></div>

                <!-- Dynamic Upsell Recommendation Card -->
                <div id="posUpsellContainer" style="display: none; margin-bottom: 16px;"></div>

                <!-- Subtotal, Discount & Total -->
                <div style="border-top: 1px solid var(--border); padding-top: 16px; margin-bottom: 20px;">
                    <div class="flex-between" style="margin-bottom: 8px;">
                        <span class="text-muted">Subtotal:</span>
                        <strong id="summarySubtotalUsd" style="font-size: 16px;">$0.00</strong>
                    </div>

                    <div class="flex-between" style="margin-bottom: 14px; align-items: center;">
                        <span class="text-muted">Discount ($):</span>
                        <input type="number" step="0.25" min="0" name="discount_usd" id="discount_usd" class="form-input" value="0.00" style="width: 100px; text-align: right; min-height: 38px; padding: 4px 10px;" oninput="recalcTotals()">
                    </div>

                    <div style="border-top: 2px dashed var(--border); padding-top: 14px;">
                        <div class="flex-between">
                            <div>
                                <div style="font-size: 16px; font-weight: 700; color: var(--text-main);">Total Amount:</div>
                                <div id="summaryTotalKhr" style="font-size: 14px; font-weight: 600; color: var(--primary);">0 ៛</div>
                            </div>
                            <div style="text-align: right;">
                                <div id="summaryTotalUsd" style="font-size: 26px; font-weight: 800; color: var(--primary);">$0.00</div>
                                <div class="text-xs text-muted">Rate: $1 = 4,100 ៛</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manager Override for Out-of-Stock Items -->
                <div style="margin-bottom: 14px;">
                    <details style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 8px 12px; font-size: 13px;">
                        <summary style="cursor: pointer; font-weight: 600; color: #ea580c;">
                            <i class="fa-solid fa-key mr-1"></i> Manager Override for Out-of-Stock Items
                        </summary>
                        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed var(--border);">
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label text-xs">Manager Username</label>
                                <input type="text" name="stock_override_mgr_user" class="form-input" placeholder="e.g. manager, owner">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label text-xs">Manager 6-digit PIN</label>
                                <input type="password" maxlength="6" name="stock_override_mgr_pin" class="form-input" placeholder="6-digit PIN" style="font-family: var(--font-mono); letter-spacing: 4px;">
                            </div>
                        </div>
                    </details>
                </div>

                <!-- Big Create Order Button -->
                <?php if (!empty($dayLock['locked'])): ?>
                    <button type="button" class="btn btn-secondary btn-lg" style="width: 100%; font-size: 15px; opacity: 0.7; cursor: not-allowed; background: #fee2e2; color: #991b1b; border-color: #fca5a5;" disabled>
                        <i class="fa-solid fa-lock mr-1"></i> Sales Locked (Manager Action Required)
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; font-size: 16px;" id="btnCreateOrder">
                        <i class="fa-solid fa-circle-check mr-1"></i> Create Wash &amp; Service Order
                    </button>
                <?php endif; ?>
            </div>
        </div>

    </div>
</form>

<style>
/* Vehicle Type Big Cards */
.vtype-card {
    background: var(--bg-surface);
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px 12px;
    text-align: center;
    cursor: pointer;
    transition: all 0.15s ease;
}
.vtype-card:hover { border-color: var(--primary); transform: translateY(-2px); }
.vtype-card.active { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 4px 12px rgba(2, 132, 199, 0.15); }
.vtype-icon { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 0 auto 10px; }
.vtype-label { font-weight: 700; font-size: 14px; color: var(--text-main); }
.vtype-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Service Selectable Card */
.service-select-card, .product-select-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 14px 16px;
    cursor: pointer;
    transition: all 0.15s ease;
    display: flex;
    user-select: none;
}
.service-select-card:hover, .product-select-card:hover { border-color: var(--primary); }
.service-select-card.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 2px 8px rgba(2, 132, 199, 0.12); }
.product-select-card.selected { border-color: #ea580c; background: #fff7ed; box-shadow: 0 2px 8px rgba(234, 88, 12, 0.15); }
.product-select-card.disabled { opacity: 0.5; cursor: not-allowed; }

/* Washer & Mechanic Pills */
.washer-pill {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    background: var(--bg-subtle);
    border: 1px solid var(--border);
    border-radius: var(--radius-full);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    cursor: pointer;
    transition: all 0.15s ease;
}
.washer-pill input { display: none; }
.washer-pill.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.washer-pill.mechanic-pill.active { background: #ea580c; color: #fff; border-color: #ea580c; }
/* Product Category Filter Pills */
.prod-cat-pill {
    transition: all 0.15s ease;
    font-weight: 600;
}
.prod-cat-pill.active {
    background: var(--primary) !important;
    color: #fff !important;
    border-color: var(--primary) !important;
}

@media (max-width: 1024px) {
    .grid { grid-template-columns: 1fr !important; }
}
</style>

<script>
const PRICING = <?= json_encode($pricingMatrix) ?>;
const PRODUCTS_DATA = <?= json_encode($allProducts) ?>;
const CUSTOMER_VEHICLES = <?= json_encode($vehiclesByCustId) ?>;
const HISTORY_PLATES = <?= json_encode($historyPlates) ?>;
const EXCHANGE_RATE = <?= get_exchange_rate() ?>;
const LOYALTY_REDEEM_RATE = <?= Loyalty::getRedeemRate() ?>;

let currentVehicleType = 'small_car';
let selectedServices = new Set();
let selectedProducts = new Set();

// Catalog Tabs (Dynamic Category Tabs & Products)
function switchCatalogTab(tab) {
    if (tab === 'services') tab = 'wash';
    if (tab === 'repairs') tab = 'repair';

    document.querySelectorAll('.catalog-tab-view').forEach(view => {
        view.style.display = (view.id === 'catalog_' + tab + '_view') ? 'block' : 'none';
    });

    document.querySelectorAll('.catalog-tab-btn').forEach(btn => {
        const isCurrent = (btn.getAttribute('data-tab') === tab);
        btn.classList.toggle('btn-primary', isCurrent);
        btn.classList.toggle('btn-secondary', !isCurrent);
    });

    const hint = document.getElementById('catalogHint');
    if (hint) {
        if (tab === 'products') {
            hint.innerText = 'Tap to add spare parts, oils or car care products';
        } else if (tab === 'repair') {
            hint.innerText = 'Tap to add minor repair & maintenance jobs';
        } else {
            hint.innerText = 'Tap to select service packages';
        }
    }
}

// Product Category Filter Pills
function filterProductCategory(catCode) {
    document.querySelectorAll('.prod-cat-pill').forEach(pill => {
        const isCurrent = (pill.getAttribute('data-cat') === catCode);
        pill.classList.toggle('active', isCurrent);
        pill.classList.toggle('btn-primary', isCurrent);
        pill.classList.toggle('btn-secondary', !isCurrent);
    });

    document.querySelectorAll('#productsGridContainer .product-select-card').forEach(card => {
        const cardCat = card.getAttribute('data-category');
        if (catCode === 'all' || cardCat === catCode) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

// 1. Vehicle Type Selection
function selectVehicleType(type) {
    currentVehicleType = type;
    document.getElementById('selected_vehicle_type').value = type;

    document.querySelectorAll('.vtype-card').forEach(card => {
        card.classList.toggle('active', card.getAttribute('data-type') === type);
    });

    updateServicePrices();
    recalcTotals();
}

function updateServicePrices() {
    for (const sId in PRICING) {
        const p = PRICING[sId][currentVehicleType] || { usd: 0, khr: 0 };
        const usdEl = document.getElementById('price_usd_' + sId);
        const khrEl = document.getElementById('price_khr_' + sId);
        if (usdEl) usdEl.innerText = '$' + p.usd.toFixed(2);
        if (khrEl) khrEl.innerText = p.khr.toLocaleString() + ' ៛';
    }

    const badgeEl = document.getElementById('summaryVTypeBadge');
    const badgeLabels = {
        'motorbike': 'Motorbike (ម៉ូតូ)',
        'small_car': 'Small Car (ឡានតូច)',
        'suv_pickup': 'SUV / Pickup',
        'van_truck': 'Big Van / Truck'
    };
    if (badgeEl) badgeEl.innerText = badgeLabels[currentVehicleType] || currentVehicleType;
    if (typeof checkMatchingUpsells === 'function') checkMatchingUpsells();
}

// Live Cloud Sync Price & Catalog Update Without Reloading Page
window.onCriticalSystemUpdate = function(data) {
    if (data && data.pricing) {
        for (const sId in data.pricing) {
            PRICING[sId] = data.pricing[sId];
        }
        updateServicePrices();
        recalcTotals();
    }
};

// 2. Service Toggle
function toggleService(sId) {
    const card = document.querySelector(`.service-select-card[data-service-id="${sId}"]`);
    const chk = document.getElementById('chk_service_' + sId);

    if (selectedServices.has(sId)) {
        selectedServices.delete(sId);
        if (card) card.classList.remove('selected');
        if (chk) chk.checked = false;
    } else {
        selectedServices.add(sId);
        if (card) card.classList.add('selected');
        if (chk) chk.checked = true;
    }

    recalcTotals();
}

// 3. Product Toggle
function toggleProduct(pId, isOutOfStock) {
    if (isOutOfStock) {
        alert('This product is currently out of stock!');
        return;
    }

    const card = document.querySelector(`.product-select-card[data-product-id="${pId}"]`);
    const chk = document.getElementById('chk_product_' + pId);

    if (selectedProducts.has(pId)) {
        selectedProducts.delete(pId);
        if (card) card.classList.remove('selected');
        if (chk) chk.checked = false;
    } else {
        selectedProducts.add(pId);
        if (card) card.classList.add('selected');
        if (chk) chk.checked = true;

        // Auto highlight mileage tracker if oil product selected
        const cat = card.getAttribute('data-category');
        if (cat === 'engine_oil' || cat === 'oil_filter') {
            document.getElementById('mileageTrackerCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    recalcTotals();
}

// 4. Recalculate Totals & Summary
function recalcTotals() {
    let subtotalUsd = 0.00;
    const summaryList = document.getElementById('summaryServicesList');
    summaryList.innerHTML = '';

    const hasItems = (selectedServices.size > 0 || selectedProducts.size > 0);

    if (!hasItems) {
        summaryList.innerHTML = '<div class="text-xs text-muted" style="padding: 12px; background: var(--bg-subtle); border-radius: var(--radius-sm); text-align: center;">No services or products selected yet.</div>';
    } else {
        // Services
        selectedServices.forEach(sId => {
            const card = document.querySelector(`.service-select-card[data-service-id="${sId}"]`);
            const title = card ? card.querySelector('.service-select-card div div div').innerText : 'Service';
            const price = (PRICING[sId] && PRICING[sId][currentVehicleType]) ? PRICING[sId][currentVehicleType].usd : 0.00;
            subtotalUsd += price;

            const row = document.createElement('div');
            row.className = 'flex-between';
            row.style.fontSize = '13px';
            row.innerHTML = `<span><i class="fa-solid fa-shower mr-1" style="color: var(--primary);"></i> ${title}</span><strong>$${price.toFixed(2)}</strong>`;
            summaryList.appendChild(row);
        });

        // Products
        selectedProducts.forEach(pId => {
            const prod = PRODUCTS_DATA.find(x => parseInt(x.id) === pId);
            if (prod) {
                const price = parseFloat(prod.price_usd);
                subtotalUsd += price;

                const row = document.createElement('div');
                row.className = 'flex-between';
                row.style.fontSize = '13px';
                row.innerHTML = `<span><i class="fa-solid fa-oil-can mr-1" style="color: #ea580c;"></i> ${prod.name_en}</span><strong>$${price.toFixed(2)}</strong>`;
                summaryList.appendChild(row);
            }
        });
    }

    const discountInput = document.getElementById('discount_usd');
    const discountUsd = Math.max(0, parseFloat(discountInput.value) || 0);
    const totalUsd = Math.max(0, subtotalUsd - discountUsd);
    const totalKhr = Math.round(totalUsd * EXCHANGE_RATE);

    document.getElementById('summarySubtotalUsd').innerText = '$' + subtotalUsd.toFixed(2);
    document.getElementById('summaryTotalUsd').innerText = '$' + totalUsd.toFixed(2);
    document.getElementById('summaryTotalKhr').innerText = totalKhr.toLocaleString() + ' ៛';

    if (typeof checkCustomerMembershipAndRewards === 'function') {
        checkCustomerMembershipAndRewards();
    }
    if (typeof checkMatchingUpsells === 'function') {
        checkMatchingUpsells();
    }
}

// 5. Mileage & Oil Interval Auto Calculation
function recalcOilInterval() {
    const kmInput = document.getElementById('current_mileage');
    const km = parseInt(kmInput.value) || 0;
    const intervalKm = parseInt(document.getElementById('mileage_interval').value) || 10000;
    const intervalMonths = parseInt(document.getElementById('month_interval').value) || 3;

    if (km > 0) {
        const nextKm = km + intervalKm;
        document.getElementById('previewNextMileage').innerText = nextKm.toLocaleString();

        const d = new Date();
        d.setMonth(d.getMonth() + intervalMonths);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        document.getElementById('previewNextDate').innerText = `${day}/${m}/${y}`;
    } else {
        document.getElementById('previewNextMileage').innerText = '---';
        document.getElementById('previewNextDate').innerText = '---';
    }

    if (typeof checkMatchingUpsells === 'function') {
        checkMatchingUpsells();
    }
}

// 6. Customer Mode (Walk-in vs Existing)
function setCustomerMode(mode) {
    const isWalkIn = (mode === 'walkin');
    document.getElementById('is_walkin').value = isWalkIn ? '1' : '0';
    document.getElementById('btnModeWalkIn').className = isWalkIn ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-secondary';
    document.getElementById('btnModeExisting').className = isWalkIn ? 'btn btn-sm btn-secondary' : 'btn btn-sm btn-primary';

    const existingSection = document.getElementById('existingCustomerSection');
    existingSection.style.display = isWalkIn ? 'none' : 'block';

    if (isWalkIn) {
        document.getElementById('customer_id').value = '';
        document.getElementById('vehicle_id').value = '';
        document.getElementById('customer_name').value = 'Walk-in Guest';
        document.getElementById('customer_phone').value = '';
        document.getElementById('plate_number').value = '';
        document.getElementById('summaryPlate').innerText = '--- ----';
        document.getElementById('current_mileage').value = '';
        recalcOilInterval();
    }
}

function onExistingCustomerSelected() {
    const select = document.getElementById('existingCustomerSelect');
    const custId = select.value;
    const opt = select.options[select.selectedIndex];

    if (!custId) {
        document.getElementById('posCustLoyaltyBanner').style.display = 'none';
        return;
    }

    document.getElementById('customer_id').value = custId;
    document.getElementById('customer_name').value = opt.getAttribute('data-name');
    document.getElementById('customer_phone').value = opt.getAttribute('data-phone') || '';

    // Update loyalty banner
    const pts = parseInt(opt.getAttribute('data-points') || 0);
    const tier = (opt.getAttribute('data-tier') || 'standard').toUpperCase();
    const credit = (pts / Math.max(1, LOYALTY_REDEEM_RATE)).toFixed(2);

    const banner = document.getElementById('posCustLoyaltyBanner');
    const badge = document.getElementById('posCustTierBadge');
    badge.innerText = tier;
    badge.className = 'badge ' + (tier === 'PLATINUM' ? 'badge-primary' : (tier === 'GOLD' ? 'badge-amber' : 'badge-slate'));
    document.getElementById('posCustPointsText').innerText = pts.toLocaleString();
    document.getElementById('posCustCreditText').innerText = credit;
    banner.style.display = 'block';

    const vehicles = CUSTOMER_VEHICLES[custId] || [];
    const vPicker = document.getElementById('customerVehiclesPicker');
    const vSelect = document.getElementById('existingVehicleSelect');
    vSelect.innerHTML = '<option value="">-- Choose one of customer\'s vehicles --</option>';

    if (vehicles.length > 0) {
        vehicles.forEach(v => {
            const desc = `${v.plate_number} (${v.vehicle_type} - ${v.make_model || 'N/A'})`;
            vSelect.innerHTML += `<option value="${v.id}" data-plate="${v.plate_number}" data-type="${v.vehicle_type}" data-mileage="${v.last_mileage || ''}">${desc}</option>`;
        });
        vPicker.style.display = 'block';

        vSelect.selectedIndex = 1;
        onExistingVehicleSelected();
    } else {
        vPicker.style.display = 'none';
    }
}

function onExistingVehicleSelected() {
    const vSelect = document.getElementById('existingVehicleSelect');
    const opt = vSelect.options[vSelect.selectedIndex];
    if (opt && opt.value) {
        const plate = opt.getAttribute('data-plate');
        const vType = opt.getAttribute('data-type');
        const lastMileage = opt.getAttribute('data-mileage');

        document.getElementById('vehicle_id').value = opt.value;
        document.getElementById('plate_number').value = plate;
        document.getElementById('summaryPlate').innerText = plate;
        if (vType) selectVehicleType(vType);
        if (lastMileage) {
            document.getElementById('current_mileage').value = lastMileage;
            recalcOilInterval();
        }
    }
}

// 7. Washer & Mechanic Pills
function toggleWasherPill(wId) {
    const pill = document.getElementById('washer_pill_' + wId);
    if (!pill) return;
    const chk = pill.querySelector('input');
    pill.classList.toggle('active', chk.checked);
}

function toggleMechanicPill(mId) {
    const pill = document.getElementById('mechanic_pill_' + mId);
    if (!pill) return;
    const chk = pill.querySelector('input');
    pill.classList.toggle('active', chk.checked);
}

function checkPlateHistory(plate) {
    const btn = document.getElementById('btnCheckHistory');
    if (!btn) return;
    if (plate && HISTORY_PLATES.includes(plate)) {
        btn.href = `<?= APP_URL ?>/index.php?view=vehicle_history&plate=${encodeURIComponent(plate)}`;
        btn.style.display = 'inline-flex';
    } else {
        btn.style.display = 'none';
    }
}

// CRM Membership, Loyalty Rewards & Dynamic Upsell Engine
let currentMembershipData = null;
let currentUpsellOffers = [];
let shownUpsellRuleIds = new Set();

function checkCustomerMembershipAndRewards() {
    const custId = document.getElementById('customer_id').value;
    const vId = document.getElementById('vehicle_id').value;
    const sIds = Array.from(selectedServices).join(',');

    const noticeBox = document.getElementById('posMembershipNoticeBox');
    const rewardAlert = document.getElementById('posLoyaltyRewardAlert');

    if (!custId) {
        if (noticeBox) noticeBox.style.display = 'none';
        if (rewardAlert) rewardAlert.style.display = 'none';
        currentMembershipData = null;
        return;
    }

    fetch(`index.php?action=check_customer_membership&customer_id=${encodeURIComponent(custId)}&vehicle_id=${encodeURIComponent(vId)}&services=${encodeURIComponent(sIds)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            currentMembershipData = data;

            // Update Tier Badge in Loyalty Banner
            const tierBadge = document.getElementById('posCustTierBadge');
            if (tierBadge && data.tier_name) {
                tierBadge.innerText = data.tier_name.toUpperCase();
                tierBadge.className = 'badge ' + (data.tier_code === 'platinum' ? 'badge-primary' : (data.tier_code === 'gold' ? 'badge-amber' : (data.tier_code === 'silver' ? 'badge-success' : 'badge-slate')));
            }

            // Membership Limit & Benefit Notices
            if (noticeBox) {
                let noticesHtml = '';
                if (data.is_member) {
                    for (const sId in data.services) {
                        const sInfo = data.services[sId];
                        if (sInfo.is_limit_reached) {
                            noticesHtml += `<div style="background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 6px 10px; border-radius: 4px; margin-bottom: 4px;">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i> <strong>Limit Used Up:</strong> ${sInfo.message}
                            </div>`;
                        } else if (sInfo.is_free) {
                            noticesHtml += `<div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 6px 10px; border-radius: 4px; margin-bottom: 4px;">
                                <i class="fa-solid fa-check mr-1"></i> <strong>Membership Included:</strong> ${sInfo.message} ($0.00)
                            </div>`;
                        }
                    }
                }
                if (noticesHtml) {
                    noticeBox.innerHTML = noticesHtml;
                    noticeBox.style.display = 'block';
                } else {
                    noticeBox.style.display = 'none';
                }
            }

            // Loyalty Rewards (e.g. 5th visit this month: free air freshener)
            if (rewardAlert) {
                if (data.rewards && data.rewards.has_rewards) {
                    let rewHtml = '';
                    data.rewards.rewards.forEach(r => {
                        rewHtml += `<div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                            <div>
                                <i class="fa-solid fa-gift mr-1" style="color: #d97706;"></i>
                                <strong>Reward Unlocked:</strong> ${escapeHtml(r.name_en)} (${r.current_visits} visits this month)
                            </div>
                            ${r.reward_item_id ? `<button type="button" class="btn btn-sm" style="background: #d97706; color: #fff; border: none; padding: 2px 8px; font-size: 11px; border-radius: 4px;" onclick="claimFreeMilestoneReward(${r.reward_item_id})">+ Add Free Product</button>` : ''}
                        </div>`;
                    });
                    rewardAlert.innerHTML = rewHtml;
                    rewardAlert.style.display = 'block';
                } else {
                    rewardAlert.style.display = 'none';
                }
            }
        })
        .catch(() => {});
}

function claimFreeMilestoneReward(prodId) {
    if (!selectedProducts.has(prodId)) {
        selectedProducts.add(prodId);
        const card = document.querySelector(`.product-select-card[data-product-id="${prodId}"]`);
        const chk = document.getElementById('chk_product_' + prodId);
        if (card) card.classList.add('selected');
        if (chk) chk.checked = true;
        recalcTotals();
        alert('Free reward product added to ticket at $0.00!');
    }
}

function checkMatchingUpsells() {
    const sIds = Array.from(selectedServices).join(',');
    const pIds = Array.from(selectedProducts).join(',');
    const mileage = document.getElementById('current_mileage').value;
    const vType = currentVehicleType;
    const upContainer = document.getElementById('posUpsellContainer');
    if (!upContainer) return;

    fetch(`index.php?action=get_matching_upsells&services=${encodeURIComponent(sIds)}&products=${encodeURIComponent(pIds)}&vehicle_type=${encodeURIComponent(vType)}&mileage=${encodeURIComponent(mileage)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.offers || data.offers.length === 0) {
                upContainer.style.display = 'none';
                upContainer.innerHTML = '';
                currentUpsellOffers = [];
                return;
            }

            currentUpsellOffers = data.offers;
            let html = '';

            data.offers.forEach(o => {
                if (!shownUpsellRuleIds.has(o.rule_id)) {
                    shownUpsellRuleIds.add(o.rule_id);
                    logUpsellAction(o.rule_id, 'shown');
                }

                html += `
                    <div class="card" style="margin-bottom: 8px; border: 2px solid #6366f1; background: #f5f3ff; padding: 12px 14px; border-radius: var(--radius-md); box-shadow: 0 4px 12px rgba(99,102,241,0.15);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                            <div style="font-weight: 800; font-size: 13px; color: #4338ca; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-wand-magic-sparkles" style="color: #6366f1;"></i>
                                <span>${escapeHtml(o.headline_en)}</span>
                            </div>
                            ${o.offer_price_usd !== null ? `<span class="badge" style="background: #4338ca; color: #fff; font-size: 11px;">$${o.offer_price_usd.toFixed(2)}</span>` : ''}
                        </div>
                        <div class="text-xs text-muted" style="margin: 6px 0 10px; color: #4b5563; line-height: 1.4;">
                            ${escapeHtml(o.description_en || '')}
                        </div>
                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <button type="button" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 3px 8px;" onclick="declineUpsellOffer(${o.rule_id})">
                                Decline
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" style="font-size: 11px; padding: 3px 10px; background: #6366f1; border-color: #6366f1;" onclick="acceptUpsellOffer(${o.rule_id}, ${o.offer_service_id || 'null'}, ${o.offer_product_id || 'null'}, ${o.offer_price_usd !== null ? o.offer_price_usd : 'null'})">
                                <i class="fa-solid fa-check mr-1"></i> Accept &amp; Upgrade
                            </button>
                        </div>
                    </div>
                `;
            });

            upContainer.innerHTML = html;
            upContainer.style.display = 'block';
        })
        .catch(() => {});
}

function acceptUpsellOffer(ruleId, offerServiceId, offerProductId, offerPrice) {
    logUpsellAction(ruleId, 'accepted');

    // If offer is a service upgrade (e.g. Premium Wash #3)
    if (offerServiceId) {
        if (selectedServices.has(1) && offerServiceId === 3) {
            selectedServices.delete(1);
            const card1 = document.querySelector(`.service-select-card[data-service-id="1"]`);
            const chk1 = document.getElementById('chk_service_1');
            if (card1) card1.classList.remove('selected');
            if (chk1) chk1.checked = false;
        }

        if (!selectedServices.has(offerServiceId)) {
            selectedServices.add(offerServiceId);
            const cardOffer = document.querySelector(`.service-select-card[data-service-id="${offerServiceId}"]`);
            const chkOffer = document.getElementById('chk_service_' + offerServiceId);
            if (cardOffer) cardOffer.classList.add('selected');
            if (chkOffer) chkOffer.checked = true;
        }
    }

    // If offer is a product (e.g. Synthetic Oil #2)
    if (offerProductId) {
        if (!selectedProducts.has(offerProductId)) {
            selectedProducts.add(offerProductId);
            const pCard = document.querySelector(`.product-select-card[data-product-id="${offerProductId}"]`);
            const pChk = document.getElementById('chk_product_' + offerProductId);
            if (pCard) pCard.classList.add('selected');
            if (pChk) pChk.checked = true;
        }
    }

    const upContainer = document.getElementById('posUpsellContainer');
    if (upContainer) upContainer.style.display = 'none';

    recalcTotals();
}

function declineUpsellOffer(ruleId) {
    logUpsellAction(ruleId, 'declined');
    const upContainer = document.getElementById('posUpsellContainer');
    if (upContainer) upContainer.style.display = 'none';
}

function logUpsellAction(ruleId, status, reason = null) {
    const custId = document.getElementById('customer_id').value;
    const vId = document.getElementById('vehicle_id').value;
    const plate = document.getElementById('plate_number').value;

    const fd = new FormData();
    fd.append('rule_id', ruleId);
    fd.append('status', status);
    if (custId) fd.append('customer_id', custId);
    if (vId) fd.append('vehicle_id', vId);
    if (plate) fd.append('plate_number', plate);
    if (reason) fd.append('reason_declined', reason);

    fetch('index.php?action=log_upsell_action', {
        method: 'POST',
        body: fd
    }).catch(() => {});
}

// Sync Plate
document.addEventListener('DOMContentLoaded', () => {
    updateServicePrices();
    recalcTotals();

    const plateInput = document.getElementById('plate_number');
    if (plateInput) {
        plateInput.addEventListener('input', (e) => {
            const val = e.target.value.trim().toUpperCase();
            document.getElementById('summaryPlate').innerText = val || '--- ----';
            checkPlateHistory(val);
        });
        checkPlateHistory(plateInput.value.trim().toUpperCase());
    }

    // Default select Full Wash
    const fullWashCard = document.querySelector('.service-select-card[data-service-id="3"]');
    if (fullWashCard) toggleService(3);

    const posStatusSelect = document.getElementById('pos_initial_status');
    if (posStatusSelect) updatePosStatusIcon(posStatusSelect.value);
});

function updatePosStatusIcon(val) {
    const icon = (val === 'washing') ? 'fa-soap' : 'fa-hourglass-half';
    const el = document.getElementById('pos_status_icon');
    if (el) el.className = 'fa-solid ' + icon;
}
</script>

<!-- Modal: Open Drawer (No Sale) -->
<div class="modal-backdrop" id="posDrawerNoSaleModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #ea580c;"><i class="fa-solid fa-unlock-keyhole mr-1"></i> Open Drawer (No Sale)</h3>
            <button type="button" class="modal-close" onclick="closeModal('posDrawerNoSaleModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="open_drawer_no_sale">

            <div class="modal-body">
                <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: var(--radius-md); padding: 12px; margin-bottom: 14px; font-size: 13px; color: #9a3412;">
                    Opening the cash drawer without a sale requires Manager PIN authorization. Both the cashier and manager will be recorded in the audit log.
                </div>

                <div class="form-group">
                    <label class="form-label">Reason for opening drawer *</label>
                    <input type="text" name="reason" class="form-input" placeholder="e.g. Change breakdown, count verification" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Manager / Owner Username *</label>
                    <input type="text" name="manager_username" class="form-input" placeholder="e.g. manager, owner" required>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Manager 6-digit PIN *</label>
                    <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('posDrawerNoSaleModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #ea580c; border-color: #ea580c;">Authorize &amp; Open Drawer</button>
            </div>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
