<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/Loyalty.php';

if (!Auth::can('pos.receipt') && !Auth::can('orders.list') && !Auth::can('pos.access')) {
    Auth::requirePermission('pos.receipt');
}
$pdo = Database::getInstance();

$isTest = (isset($_GET['test']) && $_GET['test'] == '1');

if ($isTest) {
    $order = [
        'id' => 0,
        'order_number' => 'TEST-' . date('ymd-Hi'),
        'customer_name' => 'Sokha Seng',
        'customer_phone' => '012 999 888',
        'plate_number' => '2BD-9999',
        'vehicle_type' => 'suv_pickup',
        'subtotal_usd' => 40.00,
        'discount_usd' => 2.00,
        'total_usd' => 38.00,
        'total_khr' => (int)round(38.00 * get_exchange_rate()),
        'payment_method' => 'cash',
        'amount_tendered_usd' => 40.00,
        'change_usd' => 2.00,
        'payment_ref' => null,
        'cashier_name' => Auth::getFullName() ?? 'Counter Cashier',
        'created_at' => date('Y-m-d H:i:s'),
        'current_mileage' => 45000,
        'next_oil_mileage' => 45000 + (int)get_setting('default_oil_mileage', '10000'),
        'next_oil_date' => date('Y-m-d', strtotime('+' . (int)get_setting('default_oil_months', '3') . ' months')),
        'repair_notes' => 'Tire pressure verified. Front brake pads in good condition.',
        'source' => 'pos_app',
        'pos_device_name' => 'Counter Terminal 1'
    ];
    $items = [
        ['service_name' => 'Premium Foam Wash & Wax', 'item_type' => 'service', 'qty' => 1, 'price_usd' => 10.00],
        ['service_name' => 'Brake Check & Adjustment', 'item_type' => 'repair', 'qty' => 1, 'price_usd' => 5.00],
        ['service_name' => 'Engine Oil 5W-30 Synthetic', 'item_type' => 'product', 'qty' => 1, 'price_usd' => 22.00],
        ['service_name' => 'High-Flow Oil Filter', 'item_type' => 'spare_part', 'qty' => 1, 'price_usd' => 3.00],
    ];
    $washers = ['Rathana (Washer 1)'];
    $mechanics = ['Kosal (Mechanic 1)'];
} else {
    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId <= 0) {
        die("Invalid Order ID");
    }

    // Fetch order with cashier and customer loyalty info
    $stmt = $pdo->prepare("
        SELECT o.*, u.full_name as cashier_name,
               c.loyalty_points as cust_loyalty_balance,
               c.membership_level as cust_membership_tier
        FROM orders o
        LEFT JOIN users u ON o.cashier_id = u.id
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        die("Order not found");
    }

    // Fetch items
    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll();

    // Fetch assigned washers
    $wStmt = $pdo->prepare("
        SELECT u.full_name 
        FROM order_washers ow 
        JOIN users u ON ow.washer_id = u.id 
        WHERE ow.order_id = ?
    ");
    $wStmt->execute([$orderId]);
    $washers = $wStmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch assigned mechanics
    $mStmt = $pdo->prepare("
        SELECT u.full_name 
        FROM order_mechanics om 
        JOIN users u ON om.mechanic_id = u.id 
        WHERE om.order_id = ?
    ");
    $mStmt->execute([$orderId]);
    $mechanics = $mStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch shop settings
$settings = [];
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $setStmt->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$shopNameEn  = $settings['shop_name_en'] ?? 'AquaGleam Car Wash & Detailing';
$shopNameKm  = $settings['shop_name_km'] ?? 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប';
$shopPhone   = $settings['shop_phone'] ?? '012 334 556 / 098 776 543';
$shopAddr    = $settings['shop_address'] ?? 'Phnom Penh, Cambodia';
$footerMsg   = $settings['receipt_footer'] ?? 'Thank you for choosing AquaGleam! / សូមអរគុណ!';
$showLogo    = ($settings['receipt_show_logo'] ?? '1') === '1';
$shopLogo    = $settings['shop_logo'] ?? '';
$receiptLang = $settings['receipt_language'] ?? 'both';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?= htmlspecialchars($order['order_number']) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'JetBrains Mono', 'Segoe UI Mono', 'Courier New', monospace, 'Kantumruy Pro';
            background: #f1f5f9;
            color: #000;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px;
            min-height: 100vh;
        }

        .screen-actions {
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
        }

        .btn {
            font-family: 'Kantumruy Pro', sans-serif;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-print { background: #0284c7; color: #fff; }
        .btn-close { background: #e2e8f0; color: #1e293b; }

        /* 80mm Receipt Container */
        .receipt-container {
            width: 80mm;
            background: #fff;
            padding: 5mm 4mm;
            border-radius: 4px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.1);
            font-size: 12px;
            line-height: 1.35;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .divider { border-top: 1px dashed #000; margin: 8px 0; }
        .divider-double { border-top: 2px solid #000; margin: 8px 0; }

        .receipt-header {
            text-align: center;
            margin-bottom: 8px;
        }

        .shop-title-km {
            font-family: 'Kantumruy Pro', sans-serif;
            font-size: 14px;
            font-weight: 700;
        }

        .shop-title-en {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .shop-sub {
            font-size: 10.5px;
            color: #333;
            margin-top: 2px;
        }

        .plate-box {
            border: 2px solid #000;
            border-radius: 4px;
            padding: 6px;
            text-align: center;
            margin: 8px 0;
            background: #fafafa;
        }

        .plate-num {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .vehicle-meta {
            font-size: 11px;
            margin-top: 2px;
            text-transform: uppercase;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            margin-bottom: 2px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            margin: 6px 0;
        }

        .items-table th {
            text-align: left;
            border-bottom: 1px solid #000;
            padding: 4px 0;
            font-size: 10.5px;
            text-transform: uppercase;
        }

        .items-table td {
            padding: 5px 0;
            vertical-align: top;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 11.5px;
            margin-bottom: 3px;
        }

        .grand-total {
            font-size: 15px;
            font-weight: 800;
        }

        .footer {
            text-align: center;
            font-size: 10.5px;
            margin-top: 10px;
        }

        /* Print Media Query */
        @media print {
            body {
                background: #fff;
                padding: 0;
                margin: 0;
            }
            .screen-actions {
                display: none !important;
            }
            .receipt-container {
                box-shadow: none;
                padding: 0;
                width: 100%;
                margin: 0;
            }
            @page {
                size: 80mm auto;
                margin: 2mm;
            }
        }
    </style>
</head>
<body>

<div class="screen-actions">
    <button type="button" class="btn btn-print" onclick="window.print()">
        <i class="fa-solid fa-print"></i> Print Receipt (80mm)
    </button>
    <?php if ($isTest): ?>
    <a href="<?= APP_URL ?>/index.php?view=settings&tab=receipt" class="btn btn-close">
        <i class="fa-solid fa-arrow-left"></i> Back to Settings
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/index.php?view=orders" class="btn btn-close">
        <i class="fa-solid fa-arrow-left"></i> Back to Orders
    </a>
    <?php endif; ?>
</div>

<div class="receipt-container" id="printableReceipt">
    <!-- Header -->
    <div class="receipt-header">
        <?php if ($showLogo && !empty($shopLogo)): ?>
        <div style="margin-bottom: 6px;">
            <img src="<?= APP_URL ?>/<?= htmlspecialchars($shopLogo) ?>" alt="Logo" style="max-height: 48px; max-width: 140px; object-fit: contain; filter: grayscale(100%) contrast(140%); -webkit-filter: grayscale(100%) contrast(140%);">
        </div>
        <?php endif; ?>

        <?php if ($receiptLang === 'km'): ?>
            <div class="shop-title-km"><?= htmlspecialchars($shopNameKm) ?></div>
        <?php elseif ($receiptLang === 'en'): ?>
            <div class="shop-title-en"><?= htmlspecialchars($shopNameEn) ?></div>
        <?php else: ?>
            <div class="shop-title-km"><?= htmlspecialchars($shopNameKm) ?></div>
            <div class="shop-title-en"><?= htmlspecialchars($shopNameEn) ?></div>
        <?php endif; ?>

        <div class="shop-sub"><?= htmlspecialchars($shopAddr) ?></div>
        <div class="shop-sub">Tel: <?= htmlspecialchars($shopPhone) ?></div>
        <div class="divider"></div>

        <?php if ($receiptLang === 'km'): ?>
            <div style="font-weight: 700; font-size: 12px; letter-spacing: 0.5px;">បង្កាន់ដៃទទួលប្រាក់ / សេវាកម្ម</div>
        <?php elseif ($receiptLang === 'en'): ?>
            <div style="font-weight: 700; font-size: 12px; letter-spacing: 0.5px;">WASH SERVICE RECEIPT</div>
        <?php else: ?>
            <div style="font-weight: 700; font-size: 12px; letter-spacing: 0.5px;">WASH SERVICE RECEIPT / បង្កាន់ដៃ</div>
        <?php endif; ?>
    </div>

    <!-- Order Metadata -->
    <div class="info-row">
        <span>Receipt #: <strong><?= htmlspecialchars($order['order_number']) ?></strong></span>
        <span><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></span>
    </div>
    <div class="info-row">
        <span>Cashier: <?= htmlspecialchars((string)($order['cashier_name'] ?? 'Counter')) ?></span>
        <span>Cust: <?= htmlspecialchars((string)($order['customer_name'] ?? 'Walk-in')) ?><?= !empty($order['customer_phone']) ? ' (' . htmlspecialchars((string)$order['customer_phone']) . ')' : '' ?></span>
    </div>
    <?php if (($order['source'] ?? '') === 'pos_app'): ?>
    <div class="info-row" style="font-size: 10px; color: #555;">
        <span>POS Terminal:</span>
        <strong><?= htmlspecialchars($order['pos_device_name'] ?? 'POS App') ?></strong>
    </div>
    <?php endif; ?>

    <!-- Vehicle Highlight Box -->
    <div class="plate-box">
        <div class="plate-num"><?= htmlspecialchars($order['plate_number']) ?></div>
        <div class="vehicle-meta">
            <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $order['vehicle_type']))) ?>
        </div>
    </div>

    <div class="divider"></div>

    <!-- Itemized Services & Spare Parts Breakdown -->
    <?php
    $laborItems = [];
    $partsItems = [];
    foreach ($items as $item) {
        $type = $item['item_type'] ?? 'service';
        if (in_array($type, ['product', 'spare_part'], true)) {
            $partsItems[] = $item;
        } else {
            $laborItems[] = $item;
        }
    }
    ?>

    <?php if (!empty($laborItems)): ?>
    <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; margin-top: 6px; color: #444; border-bottom: 1px dotted #888; padding-bottom: 2px;">
        [A] Labor &amp; Wash Services (ថ្លៃពលកម្ម/សេវា)
    </div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 60%;">Service / Labor</th>
                <th style="width: 40%; text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($laborItems as $item): 
                $itemQty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
                $unitPrice = ($itemQty > 1) ? ((float)$item['price_usd'] / $itemQty) : (float)$item['price_usd'];
            ?>
            <tr>
                <td>
                    <?= htmlspecialchars($item['service_name']) ?>
                    <?php if ($itemQty > 1): ?>
                        <span style="font-size: 10px; color: #444;"> (x<?= $itemQty ?> @ <?= formatUSD($unitPrice) ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="text-right"><?= formatUSD((float)$item['price_usd']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($partsItems)): ?>
    <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; margin-top: 8px; color: #444; border-bottom: 1px dotted #888; padding-bottom: 2px;">
        [B] Spare Parts &amp; Oils (គ្រឿងបន្លាស់/ប្រេង)
    </div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 60%;">Part / Item</th>
                <th style="width: 40%; text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($partsItems as $item): 
                $itemQty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
                $unitPrice = ($itemQty > 1) ? ((float)$item['price_usd'] / $itemQty) : (float)$item['price_usd'];
            ?>
            <tr>
                <td>
                    <?= htmlspecialchars($item['service_name']) ?>
                    <?php if ($itemQty > 1): ?>
                        <span style="font-size: 10px; color: #444;"> (x<?= $itemQty ?> @ <?= formatUSD($unitPrice) ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="text-right"><?= formatUSD((float)$item['price_usd']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($order['repair_notes'])): ?>
    <!-- Mechanic Work Notes -->
    <div style="border: 1px solid #000; border-radius: 4px; padding: 5px 7px; margin: 6px 0; background: #fafafa; font-size: 10.5px;">
        <div style="font-weight: 700; text-transform: uppercase; font-size: 9.5px; margin-bottom: 2px;">
            <i class="fa-solid fa-wrench"></i> Mechanic Inspection Note:
        </div>
        <div><?= htmlspecialchars($order['repair_notes']) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($order['current_mileage']) && (float)$order['current_mileage'] > 0): ?>
    <!-- Oil Change Reminder Box for Windshield / Glovebox -->
    <div style="border: 2px dashed #000; border-radius: 4px; padding: 6px 8px; margin: 8px 0; background: #fafafa;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; border-bottom: 1px dotted #000; padding-bottom: 3px;">
            <strong style="font-size: 11px; text-transform: uppercase;">
                <i class="fa-solid fa-oil-can"></i> Oil Service Reminder
            </strong>
            <span style="font-size: 9px; border: 1px solid #000; padding: 0 4px; border-radius: 2px; font-weight: bold;">KEEP IN CAR</span>
        </div>
        <div class="info-row" style="font-size: 11px;">
            <span>Current Mileage:</span>
            <strong><?= number_format((float)$order['current_mileage']) ?> km</strong>
        </div>
        <div class="info-row" style="font-size: 11px;">
            <span>Next Oil Change At:</span>
            <strong><?= number_format((float)$order['next_oil_mileage']) ?> km</strong>
        </div>
        <?php if (!empty($order['next_oil_date'])): ?>
        <div class="info-row" style="font-size: 11px;">
            <span>Or Due Date:</span>
            <strong><?= date('d/m/Y', strtotime($order['next_oil_date'])) ?></strong>
        </div>
        <?php endif; ?>
        <div style="font-size: 9px; text-align: center; color: #333; margin-top: 3px; font-family: 'Kantumruy Pro', sans-serif;">
            សូមប្តូរប្រេងម៉ាស៊ីនពេលដល់កាលកំណត់ ឬគីឡូម៉ែត្រកំណត់
        </div>
    </div>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Totals Summary -->
    <div class="summary-row">
        <span>Subtotal:</span>
        <strong><?= formatUSD((float)$order['subtotal_usd']) ?></strong>
    </div>
    <?php if (!empty($order['points_redeemed']) && (int)$order['points_redeemed'] > 0): ?>
    <div class="summary-row" style="color: #2563eb;">
        <span>Loyalty Points Redeemed (<?= (int)$order['points_redeemed'] ?> pts):</span>
        <span>-<?= formatUSD((float)($order['points_discount_usd'] ?? 0.00)) ?></span>
    </div>
    <?php endif; ?>
    <?php 
    $manualDisc = max(0.00, (float)$order['discount_usd'] - (float)($order['points_discount_usd'] ?? 0.00));
    if ($manualDisc > 0): 
    ?>
    <div class="summary-row" style="color: #c00;">
        <span>Discount:</span>
        <span>-<?= formatUSD($manualDisc) ?></span>
    </div>
    <?php endif; ?>

    <div class="divider-double"></div>

    <div class="summary-row grand-total">
        <span>TOTAL (USD):</span>
        <span><?= formatUSD((float)$order['total_usd']) ?></span>
    </div>
    <div class="summary-row" style="font-size: 13px; font-weight: 700;">
        <span>TOTAL (KHR):</span>
        <span><?= formatKHR((int)$order['total_khr']) ?></span>
    </div>

    <?php if (!empty($order['customer_id']) || !empty($order['points_earned']) || !empty($order['cust_loyalty_balance'])): ?>
    <div class="divider"></div>
    <!-- Loyalty & Rewards Box -->
    <div style="background: #f8fafc; border: 1px dashed #64748b; border-radius: 4px; padding: 6px 8px; margin: 6px 0; font-size: 11px;">
        <div style="font-weight: 700; text-transform: uppercase; font-size: 10px; color: #1e293b; margin-bottom: 4px; display: flex; justify-content: space-between;">
            <span><i class="fa-solid fa-star"></i> Loyalty Rewards</span>
            <span><?= htmlspecialchars(strtoupper((string)($order['cust_membership_tier'] ?? 'Standard'))) ?></span>
        </div>
        <?php if (!empty($order['points_earned']) && (int)$order['points_earned'] > 0): ?>
        <div class="info-row" style="font-size: 11px; color: #16a34a; font-weight: 700;">
            <span>Points Earned Today:</span>
            <span>+<?= (int)$order['points_earned'] ?> pts</span>
        </div>
        <?php endif; ?>
        <?php if (isset($order['cust_loyalty_balance'])): ?>
        <div class="info-row" style="font-size: 11px;">
            <span>Current Available Balance:</span>
            <strong><?= number_format((int)$order['cust_loyalty_balance']) ?> pts</strong>
        </div>
        <div class="info-row" style="font-size: 10px; color: #64748b;">
            <span>Redeemable Credit Value:</span>
            <span>$<?= number_format(Loyalty::calculateCreditForPoints((int)$order['cust_loyalty_balance']), 2) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Payment Details -->
    <div class="info-row">
        <span>Payment Method:</span>
        <strong style="text-transform: uppercase;"><?= htmlspecialchars((string)($order['payment_method'] ?? 'unpaid')) ?></strong>
    </div>

    <?php if ($order['payment_method'] === 'cash'): ?>
        <?php if (!empty($order['amount_tendered_usd']) && (float)$order['amount_tendered_usd'] > 0): ?>
            <div class="info-row">
                <span>Cash Tendered:</span>
                <span><?= formatUSD((float)$order['amount_tendered_usd']) ?></span>
            </div>
            <div class="info-row">
                <span>Change:</span>
                <strong><?= formatUSD((float)$order['change_usd']) ?> (<?= formatKHR((int)round((float)$order['change_usd'] * get_exchange_rate())) ?>)</strong>
            </div>
        <?php endif; ?>
    <?php elseif (in_array($order['payment_method'], ['aba', 'khqr'], true)): ?>
        <div class="info-row">
            <span>Payment Status:</span>
            <strong>SETTLED (PAID)</strong>
        </div>
        <?php if (!empty($order['payment_ref'])): ?>
            <div class="info-row">
                <span>Ref / Tran ID:</span>
                <span style="font-size: 10px;"><?= htmlspecialchars($order['payment_ref']) ?></span>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($order['paper_receipt_fallback'])): ?>
        <div style="margin: 8px 0; padding: 6px; border: 1px dashed #000; background: #fff; font-size: 10px; text-align: center; font-weight: 700; line-height: 1.4;">
            *** PAPER RECEIPT FALLBACK AUTHORIZED ***<br>
            Manager: <?= htmlspecialchars((string)($order['paper_receipt_authorized_by'] ?? 'Manager')) ?>
        </div>
    <?php endif; ?>

    <!-- Assigned Washers & Mechanics Crew -->
    <?php if (!empty($washers)): ?>
        <div class="info-row" style="margin-top: 6px; border-top: 1px dashed #ccc; padding-top: 4px;">
            <span>Washer Crew:</span>
            <span><?= htmlspecialchars(implode(', ', $washers)) ?></span>
        </div>
    <?php endif; ?>
    <?php if (!empty($mechanics)): ?>
        <div class="info-row" style="margin-top: 3px; border-top: 1px dashed #ccc; padding-top: 4px;">
            <span>Mechanic:</span>
            <span><?= htmlspecialchars(implode(', ', $mechanics)) ?></span>
        </div>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Footer -->
    <div class="footer">
        <div style="font-weight: 700; margin-bottom: 2px; font-family: 'Kantumruy Pro', sans-serif;"><?= htmlspecialchars($footerMsg) ?></div>
        <div style="font-size: 9.5px; color: #555;">Car Wash Service Pro v<?= APP_VERSION ?></div>
    </div>
</div>

<script>
// Auto print if ?auto_print=1
if (window.location.search.includes('auto_print=1')) {
    window.addEventListener('load', () => {
        setTimeout(() => { window.print(); }, 400);
    });
}
</script>

</body>
</html>
