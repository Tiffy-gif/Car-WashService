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
$exchangeRate = get_exchange_rate();

if ($isTest) {
    $order = [
        'id' => 0,
        'order_number' => 'ORD-' . date('ymd') . '-002',
        'customer_name' => 'Walk-in Guest',
        'customer_phone' => '',
        'plate_number' => 'W-5028',
        'vehicle_type' => '',
        'subtotal_usd' => 13.50,
        'discount_usd' => 0.00,
        'total_usd' => 13.50,
        'total_khr' => 55350,
        'payment_method' => 'cash',
        'amount_tendered_usd' => 13.50,
        'change_usd' => 0.00,
        'payment_ref' => null,
        'cashier_name' => 'Shop Owner',
        'created_at' => date('Y-m-d H:i:s'),
        'current_mileage' => 45000,
        'next_oil_mileage' => 50000,
        'next_oil_date' => date('Y-m-d', strtotime('+3 months')),
        'repair_notes' => '',
        'source' => 'pos_app',
        'pos_device_name' => 'Counter POS'
    ];
    $items = [
        ['service_name' => ($receiptLang === 'km' ? 'លាងសម្អាតខាងក្រៅ' : ($receiptLang === 'both' ? 'Exterior Wash / លាងខាងក្រៅ' : 'Exterior Wash')), 'item_type' => 'service', 'qty' => 1, 'price_usd' => 8.50],
        ['service_name' => ($receiptLang === 'km' ? 'បាញ់ក្រមួនការពារទឹក' : ($receiptLang === 'both' ? 'Hydrophobic Wax & Polish / បាញ់ក្រមួន' : 'Hydrophobic Wax & Polish')), 'item_type' => 'service', 'qty' => 1, 'price_usd' => 5.00],
    ];
} else {
    $orderId = (int)($_GET['id'] ?? 0);
    if ($orderId <= 0) {
        die("Invalid Order ID");
    }

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

    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll();
}

// Fetch shop settings & unified receipt configuration
$settings = [];
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $setStmt->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
$rcpt = get_receipt_config();

$showLogo        = $rcpt['show_logo'];
$showShopName    = $rcpt['show_shop_name'];
$showAddress     = $rcpt['show_address'];
$showPhone       = $rcpt['show_phone'];
$showInvoiceNo   = $rcpt['show_invoice_no'];
$showDate        = $rcpt['show_date'];
$showCashier     = $rcpt['show_cashier'];
$showCustomer    = $rcpt['show_customer'];
$showPlate       = $rcpt['show_plate'];
$showVehicleType = $rcpt['show_vehicle_type'];
$showWasher      = $rcpt['show_washer'];
$showItemCount   = $rcpt['show_item_count'];
$showDiscount    = $rcpt['show_discount'];
$showTax         = $rcpt['show_tax'];
$showExchangeRate= $rcpt['show_exchange_rate'];
$showPayment     = $rcpt['show_payment'];
$showOilReminder = $rcpt['show_oil_reminder'];
$showNotes       = $rcpt['show_notes'];
$showFooter      = $rcpt['show_footer'];

$receiptLang     = $rcpt['language'];
$shopLogo        = $settings['shop_logo'] ?? '';
$shopNameEn      = !empty($settings['shop_name_en']) ? $settings['shop_name_en'] : 'My Car Wash';
$shopNameKm      = !empty($settings['shop_name_km']) ? $settings['shop_name_km'] : 'សេវាកម្មលាងរថយន្ត';
$shopPhone       = !empty($settings['shop_phone']) ? $settings['shop_phone'] : '012 334 556 / 098 776 543';
$shopAddr        = !empty($settings['shop_address']) ? $settings['shop_address'] : 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh';
$footerMsg       = !empty($rcpt['footer_msg']) ? $rcpt['footer_msg'] : 'Thank you for your visit!';
$bottomLine      = !empty($rcpt['bottom_line']) ? $rcpt['bottom_line'] : 'Powered by Counter POS';
$welcomeLine     = $rcpt['welcome_line'];
$headerExtra     = $rcpt['header_extra'];
$footerExtra     = $rcpt['footer_extra'];

// Dynamic Classes for #printableReceipt
$receiptClasses = ['unified-receipt', 'receipt-card-shadow'];
if ($rcpt['paper_size'] === '58mm' || (isset($_GET['size']) && $_GET['size'] === '58mm')) {
    $receiptClasses[] = 'paper-58mm';
}
if (!empty($rcpt['font_size'])) {
    $receiptClasses[] = 'font-' . $rcpt['font_size'];
}
if (!$rcpt['bold_mode']) {
    $receiptClasses[] = 'bold-off';
}
if (!empty($rcpt['line_spacing'])) {
    $receiptClasses[] = 'spacing-' . $rcpt['line_spacing'];
}
$receiptClassAttr = implode(' ', $receiptClasses);
$headerAlignClass = 'align-' . ($rcpt['header_align'] ?? 'center');
$logoSizeClass = 'logo-' . ($rcpt['logo_size'] ?? 'medium');

// Label Helper with Overrides
$getLbl = function(string $key, string $km, string $en, string $both) use ($rcpt, $receiptLang): string {
    if (!empty($rcpt[$key])) return htmlspecialchars($rcpt[$key]);
    if ($receiptLang === 'km') return $km;
    if ($receiptLang === 'en') return $en;
    return $both;
};

$isReprint   = isset($_GET['reprint']) && $_GET['reprint'] == '1';
$isCancelled = ($order['status'] ?? '') === 'cancelled';
$isUnpaid    = (($order['payment_status'] ?? '') === 'unpaid' || ($order['status'] ?? '') === 'unpaid') && !$isCancelled;
$isCash      = strtolower((string)($order['payment_method'] ?? 'cash')) === 'cash';

$subtotal    = (float)($order['subtotal_usd'] ?? 0);
if ($subtotal <= 0 && !empty($items)) {
    foreach ($items as $it) {
        $subtotal += (float)($it['price_usd'] ?? 0);
    }
}
$discount    = (float)($order['discount_usd'] ?? 0);
$totalUsd    = (float)($order['total_usd'] ?? ($subtotal - $discount));
$totalKhr    = (int)($order['total_khr'] ?? round($totalUsd * $exchangeRate));
$tendered    = (float)($order['amount_tendered_usd'] ?? $totalUsd);
$changeUsd   = (float)($order['change_usd'] ?? 0);
$changeKhr   = (int)round($changeUsd * $exchangeRate);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?= htmlspecialchars($order['order_number']) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/receipt-unified.css">
    <style>
        * { box-sizing: border-box; }
        body {
            background: #f1f5f9;
            margin: 0;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .screen-actions {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-act {
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            padding: 9px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-act-primary { background: #0284c7; color: #fff; }
        .btn-act-primary:hover { background: #0369a1; }
        .btn-act-secondary { background: #0f172a; color: #fff; }
        .btn-act-secondary:hover { background: #1e293b; }
        .btn-act-outline { background: #ffffff; color: #334155; border: 1px solid #cbd5e1; }
        .btn-act-outline:hover { background: #f8fafc; }

        .paper-switcher {
            display: inline-flex;
            background: #e2e8f0;
            padding: 3px;
            border-radius: 8px;
            gap: 2px;
        }
        .paper-switcher button {
            border: none;
            background: transparent;
            color: #64748b;
            font-weight: 700;
            font-size: 12px;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .paper-switcher button.active {
            background: #ffffff;
            color: #0284c7;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .receipt-card-shadow {
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
    </style>
</head>
<body>

<div class="screen-actions">
    <button type="button" class="btn-act btn-act-primary" onclick="window.print()">
        <i class="fa-solid fa-print"></i> Print Receipt (Browser)
    </button>
    <button type="button" class="btn-act btn-act-secondary" id="btnDirectThermal" onclick="triggerDirectThermalPrint()">
        <i class="fa-solid fa-bolt"></i> Direct Thermal Print (80mm)
    </button>
    <div class="paper-switcher">
        <button type="button" id="toggle80" class="active" onclick="switchPaperWidth('80mm')">80mm</button>
        <button type="button" id="toggle58" onclick="switchPaperWidth('58mm')">58mm</button>
    </div>
    <?php if ($isTest): ?>
    <a href="<?= APP_URL ?>/index.php?view=settings&tab=receipt" class="btn-act btn-act-outline">
        <i class="fa-solid fa-arrow-left"></i> Back to Settings
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/index.php?view=orders" class="btn-act btn-act-outline">
        <i class="fa-solid fa-arrow-left"></i> Back to Orders
    </a>
    <?php endif; ?>
</div>

<div id="directPrintStatus" style="display: none; max-width: 360px; margin-bottom: 15px; padding: 10px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; text-align: center;"></div>

<!-- Unified Customer Receipt Card -->
<div id="printableReceipt" class="<?= $receiptClassAttr ?>">

    <!-- 1. Header & Shop Branding -->
    <div class="receipt-header <?= $headerAlignClass ?>">
        <?php if ($showLogo): ?>
        <div class="receipt-logo-wrap">
            <?php if (!empty($shopLogo)): ?>
                <img src="<?= APP_URL ?>/<?= htmlspecialchars($shopLogo) ?>" alt="Shop Logo" class="receipt-logo-img <?= $logoSizeClass ?>">
            <?php else: ?>
                <i class="fa-solid fa-droplet" style="color: #000000; font-size: 22px;"></i>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showShopName && $receiptLang !== 'en'): ?>
        <div class="receipt-shop-name-km"><?= htmlspecialchars($shopNameKm) ?></div>
        <?php endif; ?>

        <?php if ($showShopName && $receiptLang !== 'km'): ?>
        <div class="receipt-shop-name-en"><?= htmlspecialchars($shopNameEn) ?></div>
        <?php endif; ?>

        <?php if ($showAddress): ?>
        <div class="receipt-shop-address"><?= htmlspecialchars($shopAddr) ?></div>
        <?php endif; ?>

        <?php if ($showPhone): ?>
        <div class="receipt-shop-phone">Tel: <?= htmlspecialchars($shopPhone) ?></div>
        <?php endif; ?>

        <?php if (!empty($welcomeLine)): ?>
        <div class="receipt-welcome-line"><?= htmlspecialchars($welcomeLine) ?></div>
        <?php endif; ?>

        <?php if (!empty($headerExtra)): ?>
        <div class="receipt-extra-lines"><?= nl2br(htmlspecialchars($headerExtra)) ?></div>
        <?php endif; ?>

        <div class="receipt-divider-dashed"></div>
    </div>

    <!-- Status Banners (Reprint, Cancelled, Unpaid) -->
    <?php if ($isReprint): ?>
    <div class="receipt-status-banner">
        *** <?= ($receiptLang === 'km' ? 'ច្បាប់ចម្លង' : ($receiptLang === 'en' ? 'COPY / REPRINT' : 'ច្បាប់ចម្លង / COPY / REPRINT')) ?> ***
        <div style="font-size: 9px; font-weight: 600; margin-top: 2px;">
            Reprinted: <?= date('Y-m-d H:i:s') ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isCancelled): ?>
    <div class="receipt-status-banner cancelled">
        *** <?= ($receiptLang === 'km' ? 'បានបោះបង់' : ($receiptLang === 'en' ? 'CANCELLED' : 'បានបោះបង់ / ORDER CANCELLED')) ?> ***
    </div>
    <?php elseif ($isUnpaid): ?>
    <div class="receipt-status-banner">
        *** <?= ($receiptLang === 'km' ? 'មិនទាន់ទូទាត់' : ($receiptLang === 'en' ? 'UNPAID' : 'មិនទាន់ទូទាត់ / UNPAID')) ?> ***
    </div>
    <?php endif; ?>

    <!-- 2. Order Metadata -->
    <div class="receipt-meta-grid">
        <?php if ($showInvoiceNo || $showDate): ?>
        <div class="receipt-meta-row">
            <?php if ($showInvoiceNo): ?>
            <span><?= $getLbl('label_invoice', 'វិក្កយបត្រ៖', 'Invoice #:', 'វិក្កយបត្រ / Invoice #:') ?> <strong><?= htmlspecialchars($order['order_number']) ?></strong></span>
            <?php else: ?>
            <span></span>
            <?php endif; ?>
            <?php if ($showDate): ?>
            <span><?= date('Y-m-d H:i:s', strtotime($order['created_at'])) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showCashier || $showCustomer): ?>
        <div class="receipt-meta-row">
            <?php if ($showCashier): ?>
            <span><?= $getLbl('label_cashier', 'បេឡា៖', 'Cashier:', 'បេឡា / Cashier:') ?> <?= htmlspecialchars((string)($order['cashier_name'] ?? 'Shop Owner')) ?></span>
            <?php endif; ?>
            <?php if ($showCustomer): ?>
            <span><?= $getLbl('label_customer', 'អតិថិជន៖', 'Customer:', 'អតិថិជន / Customer:') ?> <?= htmlspecialchars((string)($order['customer_name'] ?? 'Walk-in Guest')) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showWasher && !empty($order['washers'])): 
            $washerNames = is_array($order['washers']) ? implode(', ', $order['washers']) : (string)$order['washers'];
        ?>
        <div class="receipt-meta-row">
            <span><?= $getLbl('label_washer', 'ជាងលាង៖', 'Washer:', 'ជាងលាង / Washer:') ?> <?= htmlspecialchars($washerNames) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- 3. Vehicle License Plate Badge -->
    <?php if ($showPlate): ?>
    <div class="receipt-plate-badge">
        <span class="receipt-plate-label">
            <?= $getLbl('label_plate', 'ស្លាកលេខ៖', 'PLATE #:', 'ស្លាកលេខ / PLATE #:') ?>
            <?php if ($showVehicleType && !empty($order['vehicle_type'])): ?>
                <span class="receipt-plate-sub">(<?= htmlspecialchars(strtoupper(str_replace('_', ' ', $order['vehicle_type']))) ?>)</span>
            <?php endif; ?>
        </span>
        <span class="receipt-plate-number"><?= htmlspecialchars($order['plate_number']) ?></span>
    </div>
    <?php endif; ?>

    <!-- 4. 3-Column Items Table -->
    <table class="receipt-items-table">
        <thead>
            <tr>
                <th style="text-align: left;"><?= $getLbl('label_item', 'ទំនិញ/សេវា', 'ITEM', 'ទំនិញ/សេវា (ITEM)') ?></th>
                <th style="text-align: center; width: 36px;"><?= $getLbl('label_qty', 'ចំនួន', 'QTY', 'ចំនួន') ?></th>
                <th style="text-align: right; width: 68px;"><?= $getLbl('label_price', 'តម្លៃ', 'PRICE', 'តម្លៃ') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): 
                $itemName = ($receiptLang === 'en') ? ($item['name_en'] ?? $item['service_name'] ?? $item['name'] ?? 'Service') : ($item['service_name'] ?? $item['name'] ?? $item['name_km'] ?? 'Service');
                $itemQty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
                $itemPrice = (float)($item['price_usd'] ?? 0);
                $unitPrice = ($itemQty > 1) ? ($itemPrice / $itemQty) : $itemPrice;
            ?>
            <tr>
                <td class="col-item">
                    <?= htmlspecialchars($itemName) ?>
                    <?php if ($itemQty > 1): ?>
                        <div style="font-size: 9px; font-weight: 600; font-family: monospace;">
                            (<?= $itemQty ?> x $<?= number_format($unitPrice, 2) ?>)
                        </div>
                    <?php endif; ?>
                </td>
                <td class="col-qty"><?= $itemQty ?></td>
                <td class="col-price">$<?= number_format($itemPrice, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($showItemCount): 
        $totItems = count($items);
        $totUnits = array_sum(array_map(fn($it) => (int)($it['qty'] ?? $it['quantity'] ?? 1), $items));
    ?>
    <div class="receipt-summary-row" style="font-size: 9.5px; margin: 3px 0;">
        <span>Total Items: <?= $totItems ?> (<?= $totUnits ?> Units)</span>
    </div>
    <?php endif; ?>

    <!-- 5. Totals Breakdown -->
    <div class="receipt-totals-block">
        <div class="receipt-divider-dashed"></div>

        <div class="receipt-summary-row">
            <span><?= $getLbl('label_subtotal', 'សរុបបឋម៖', 'Subtotal:', 'សរុបបឋម / Subtotal:') ?></span>
            <span style="font-family: monospace; font-weight: 700;">$<?= number_format($subtotal, 2) ?></span>
        </div>

        <?php if ($showDiscount && $discount > 0): ?>
        <div class="receipt-summary-row">
            <span><?= $getLbl('label_discount', 'បញ្ចុះតម្លៃ៖', 'Discount:', 'បញ្ចុះតម្លៃ / Discount:') ?></span>
            <span style="font-family: monospace; font-weight: 700;">-$<?= number_format($discount, 2) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($showTax): 
            $taxRate = 0.10; // 10% VAT
            $taxAmount = round($totalUsd * $taxRate, 2);
        ?>
        <div class="receipt-summary-row">
            <span><?= $getLbl('label_tax', 'ពន្ធ / Tax (10%):', 'Tax / VAT (10%):', 'ពន្ធ / Tax (10%):') ?></span>
            <span style="font-family: monospace; font-weight: 700;">$<?= number_format($taxAmount, 2) ?></span>
        </div>
        <?php endif; ?>

        <div class="receipt-divider-dashed"></div>

        <div class="receipt-total-usd-row">
            <span><?= $getLbl('label_total_usd', 'សរុប USD៖', 'TOTAL USD:', 'សរុប USD / TOTAL USD:') ?></span>
            <span class="amount">$<?= number_format($totalUsd, 2) ?></span>
        </div>
        <div class="receipt-total-khr-row">
            <span><?= $getLbl('label_total_khr', 'សរុប រៀល៖', 'TOTAL KHR:', 'សរុប រៀល / TOTAL KHR:') ?></span>
            <span><?= number_format($totalKhr) ?> ៛</span>
        </div>

        <?php if ($showExchangeRate || !empty($order['show_exchange_rate']) || !empty($order['exchange_rate'])): ?>
        <div class="receipt-exchange-rate">
            <?= $getLbl('label_rate', 'អត្រាប្តូរប្រាក់៖', 'Rate:', 'អត្រាប្តូរប្រាក់ / Rate:') ?> $1.00 = <?= number_format($exchangeRate) ?> ៛
        </div>
        <?php endif; ?>

        <div class="receipt-divider-solid"></div>

        <!-- 6. Payment Details -->
        <?php if ($showPayment): ?>
        <div class="receipt-payment-block">
            <div class="receipt-summary-row" style="font-weight: 700;">
                <span><?= $getLbl('label_payment', 'វិធីទូទាត់៖', 'Payment:', 'វិធីទូទាត់ / Payment:') ?></span>
                <span style="text-transform: uppercase;">
                    <?php 
                    $method = strtoupper((string)($order['payment_method'] ?? 'cash'));
                    if ($method === 'CASH') {
                        echo ($receiptLang === 'en') ? 'CASH' : (($receiptLang === 'km') ? 'សាច់ប្រាក់' : 'សាច់ប្រាក់ (CASH)');
                    } else {
                        echo htmlspecialchars($method);
                    }
                    ?>
                </span>
            </div>
            <?php if ($isCash && !$isUnpaid): ?>
            <div class="receipt-summary-row">
                <span><?= $getLbl('label_tendered', 'ប្រាក់ទទួល៖', 'Tendered:', 'ប្រាក់ទទួល / Tendered:') ?></span>
                <span style="font-family: monospace; font-weight: 700;">$<?= number_format($tendered, 2) ?></span>
            </div>
            <div class="receipt-summary-row" style="font-weight: 700;">
                <span><?= $getLbl('label_change', 'ប្រាក់អាប់៖', 'Change:', 'ប្រាក់អាប់ / Change:') ?></span>
                <span style="font-family: monospace;">$<?= number_format($changeUsd, 2) ?><?= ($receiptLang !== 'en' && $changeKhr > 0) ? ' (' . number_format($changeKhr) . ' ៛)' : '' ?></span>
            </div>
            <?php elseif ($isUnpaid): ?>
            <div class="receipt-summary-row" style="font-weight: 800;">
                <span><?= ($receiptLang === 'km' ? 'ប្រាក់ត្រូវបង់៖' : ($receiptLang === 'en' ? 'Amount Due:' : 'ប្រាក់ត្រូវបង់ / Amount Due:')) ?></span>
                <span style="font-family: monospace;">$<?= number_format($totalUsd, 2) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 7. Oil Service Reminder Box (if applicable) -->
    <?php if ($showOilReminder): ?>
    <div class="receipt-oil-reminder-box">
        <div class="receipt-oil-reminder-title">
            <i class="fa-solid fa-oil-can"></i> <?= ($receiptLang === 'km' ? 'រំលឹកប្តូរប្រេង' : ($receiptLang === 'en' ? 'OIL CARE REMINDER' : 'រំលឹកប្តូរប្រេង / OIL CARE REMINDER')) ?>
        </div>
        <div>
            <?php if (!empty($order['current_mileage']) && (float)$order['current_mileage'] > 0): ?>
                Current: <?= number_format((float)$order['current_mileage']) ?> km | Next: <?= number_format((float)$order['next_oil_mileage']) ?> km
                <?php if (!empty($order['next_oil_date'])): ?>
                    (Due: <?= date('d/m/Y', strtotime($order['next_oil_date'])) ?>)
                <?php endif; ?>
            <?php else: ?>
                Current: ________ km | Next: ________ km
            <?php endif; ?>
        </div>
        <?php if ($receiptLang !== 'en'): ?>
        <div style="font-size: 9px; margin-top: 2px;">
            សូមប្តូរប្រេងម៉ាស៊ីនពេលដល់កាលកំណត់ ឬគីឡូម៉ែត្រកំណត់
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 8. Inspection / Mechanic Notes (if applicable) -->
    <?php if ($showNotes && !empty($order['repair_notes'])): ?>
    <div class="receipt-notes-box">
        <div style="font-weight: 800; text-transform: uppercase; margin-bottom: 2px;">
            <?= ($receiptLang === 'km' ? 'ចំណាំត្រួតពិនិត្យ៖' : ($receiptLang === 'en' ? 'INSPECTION NOTE:' : 'ចំណាំត្រួតពិនិត្យ / INSPECTION NOTE:')) ?>
        </div>
        <div><?= htmlspecialchars($order['repair_notes']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Extra Footer Lines (Promotion / Policy) -->
    <?php if (!empty($footerExtra)): ?>
    <div class="receipt-extra-lines"><?= nl2br(htmlspecialchars($footerExtra)) ?></div>
    <?php endif; ?>

    <!-- 9. Thank You Footer -->
    <?php if ($showFooter): ?>
    <div class="receipt-divider-dashed"></div>
    <div class="receipt-footer">
        <div class="receipt-footer-msg"><?= htmlspecialchars($footerMsg) ?></div>
        <div class="receipt-footer-sub"><?= htmlspecialchars($bottomLine) ?></div>
    </div>
    <?php endif; ?>

</div>

<script>
function switchPaperWidth(size) {
    const receipt = document.getElementById('printableReceipt');
    const b80 = document.getElementById('toggle80');
    const b58 = document.getElementById('toggle58');
    if (size === '58mm') {
        receipt.classList.add('paper-58mm');
        b58.classList.add('active');
        b80.classList.remove('active');
    } else {
        receipt.classList.remove('paper-58mm');
        b80.classList.add('active');
        b58.classList.remove('active');
    }
}

async function triggerDirectThermalPrint() {
    const btn = document.getElementById('btnDirectThermal');
    const statusBox = document.getElementById('directPrintStatus');
    
    btn.disabled = true;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Sending to Thermal Printer...`;
    statusBox.style.display = 'none';

    try {
        // Read saved POS printer settings from localStorage if available
        let printerIp = '192.168.1.200';
        let printerPort = 9100;
        try {
            const rawCfg = localStorage.getItem('carwash_pos_config');
            if (rawCfg) {
                const cfg = JSON.parse(rawCfg);
                if (cfg.printerIp) printerIp = cfg.printerIp;
                if (cfg.printerPort) printerPort = parseInt(cfg.printerPort) || 9100;
            }
        } catch(e) {}

        // Send test / order print to api/printer.php
        const res = await fetch('<?= APP_URL ?>/api/printer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'test',
                ip: printerIp,
                port: printerPort
            })
        });

        const json = await res.json();
        statusBox.style.display = 'block';
        if (json.success) {
            statusBox.style.background = '#dcfce7';
            statusBox.style.color = '#15803d';
            statusBox.style.border = '1px solid #86efac';
            statusBox.innerHTML = `<i class="fa-solid fa-circle-check"></i> Sent to thermal printer (${printerIp}:${printerPort}) successfully!`;
        } else {
            statusBox.style.background = '#fee2e2';
            statusBox.style.color = '#b91c1c';
            statusBox.style.border = '1px solid #fca5a5';
            statusBox.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> Thermal printer notice: ${json.error || json.message || 'Check printer connection'}. Use browser print button above.`;
        }
    } catch(err) {
        statusBox.style.display = 'block';
        statusBox.style.background = '#fef3c7';
        statusBox.style.color = '#b45309';
        statusBox.style.border = '1px solid #fde68a';
        statusBox.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Could not reach thermal printer on LAN. Falling back to browser print window...`;
        setTimeout(() => { window.print(); }, 800);
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="fa-solid fa-bolt"></i> Direct Thermal Print (80mm)`;
    }
}

// Auto print if ?auto_print=1
if (window.location.search.includes('auto_print=1')) {
    window.addEventListener('load', () => {
        setTimeout(() => { window.print(); }, 400);
    });
}
</script>

</body>
</html>
