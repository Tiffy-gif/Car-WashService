<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

$shopNameEn = get_setting('shop_name_en', 'AquaGleam Car Wash & Detailing');
$shopNameKm = get_setting('shop_name_km', 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប');
$shopPhone = get_setting('shop_phone', '012 334 556');
$exchangeRate = (int)get_setting('exchange_rate', '4100');

$currentUser = class_exists('Auth') ? Auth::user() : null;
$cashierName = $currentUser ? ($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff') : 'Cashier';

$manualId = 'MAN-' . date('Ymd-His');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Mode Receipt - <?= htmlspecialchars($shopNameEn) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css">
    <style>
        body {
            background-color: var(--bg-body, #f8fafc);
            font-family: var(--font-sans, system-ui, sans-serif);
            margin: 0;
            padding: 20px;
        }
        .manual-receipt-card {
            max-width: 420px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .manual-receipt-card {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print" style="max-width: 420px; margin: 0 auto 16px auto; display: flex; justify-content: space-between; align-items: center;">
    <button type="button" class="btn btn-secondary btn-sm" onclick="window.history.back()">
        <i class="fa-solid fa-arrow-left mr-1"></i> Back
    </button>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
        <i class="fa-solid fa-print mr-1"></i> Print / Save as PDF
    </button>
</div>

<div class="manual-receipt-card" id="receiptContent">
    <!-- Header -->
    <div style="text-align: center; border-bottom: 2px dashed #0f172a; padding-bottom: 12px; margin-bottom: 12px;">
        <span style="display: inline-block; background: #eab308; color: #713f12; padding: 2px 8px; font-size: 10px; font-weight: 800; border-radius: 4px; letter-spacing: 1px; margin-bottom: 6px;">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i> MANUAL MODE / OFFLINE RECEIPT
        </span>
        <h2 style="font-size: 16px; font-weight: 800; text-transform: uppercase; margin: 4px 0 2px 0; color: #0f172a;">
            <?= htmlspecialchars($shopNameEn) ?>
        </h2>
        <div style="font-size: 11px; color: #475569; font-weight: 600;">
            <?= htmlspecialchars($shopNameKm) ?>
        </div>
        <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
            Tel: <?= htmlspecialchars($shopPhone) ?>
        </div>
    </div>

    <!-- Receipt Meta -->
    <div style="font-size: 12px; line-height: 1.5; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
        <div style="display: flex; justify-content: space-between;">
            <span style="color: #64748b;">Receipt Slip #:</span>
            <strong style="font-family: monospace;" id="lblReceiptNo"><?= $manualId ?></strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
            <span style="color: #64748b;">Date &amp; Time:</span>
            <span><?= date('M d, Y H:i:s') ?></span>
        </div>
        <div style="display: flex; justify-content: space-between;">
            <span style="color: #64748b;">Cashier:</span>
            <span><?= htmlspecialchars($cashierName) ?></span>
        </div>
    </div>

    <!-- Editable Vehicle & Customer Form (Screen Mode) / Rendered (Print Mode) -->
    <div class="no-print" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 14px;">
        <div class="form-group" style="margin-bottom: 8px;">
            <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Plate Number *</label>
            <input type="text" id="inPlate" class="form-control form-control-sm" placeholder="e.g. 2AB-8888" style="text-transform: uppercase; font-weight: 800; font-family: monospace;" oninput="updatePreview()">
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Vehicle Type</label>
                <select id="inVType" class="form-select form-select-sm" onchange="updatePreview()">
                    <option value="Small Car">Small Car (ឡានតូច)</option>
                    <option value="SUV / Pickup">SUV / Pickup</option>
                    <option value="Van / Truck">Van / Truck</option>
                    <option value="Motorbike">Motorbike (ម៉ូតូ)</option>
                </select>
            </div>
            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Payment</label>
                <select id="inPayment" class="form-select form-select-sm" onchange="updatePreview()">
                    <option value="Cash">Cash (សាច់ប្រាក់)</option>
                    <option value="ABA / KHQR">ABA / KHQR</option>
                </select>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 8px;">
            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Service / Item</label>
                <input type="text" id="inService" class="form-control form-control-sm" placeholder="e.g. Premium Wash & Vacuum" oninput="updatePreview()">
            </div>
            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Price ($ USD)</label>
                <input type="number" step="0.5" min="0" id="inPrice" class="form-control form-control-sm" placeholder="10.00" oninput="updatePreview()">
            </div>
        </div>
    </div>

    <!-- Live Rendered Line Items for Print & View -->
    <div style="margin-bottom: 12px; font-size: 12px;">
        <div style="display: flex; justify-content: space-between; font-weight: 700; color: #0f172a; margin-bottom: 4px;">
            <span>Plate: <span id="outPlate" style="font-family: monospace;">[PLATE NUMBER]</span></span>
            <span id="outVType" style="color: #64748b;">Small Car</span>
        </div>

        <div style="border-top: 1px dashed #cbd5e1; border-bottom: 1px dashed #cbd5e1; padding: 8px 0; margin: 8px 0;">
            <div style="display: flex; justify-content: space-between; font-size: 12px;">
                <span id="outService">1x Wash Service</span>
                <strong id="outPrice">$0.00</strong>
            </div>
        </div>
    </div>

    <!-- Totals -->
    <div style="font-size: 13px; line-height: 1.6; border-bottom: 2px dashed #0f172a; padding-bottom: 10px; margin-bottom: 12px;">
        <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 15px; color: #0f172a;">
            <span>TOTAL USD:</span>
            <span id="outTotalUsd">$0.00</span>
        </div>
        <div style="display: flex; justify-content: space-between; font-weight: 700; color: #475569;">
            <span>TOTAL KHR:</span>
            <span id="outTotalKhr">0 ៛</span>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 11px; color: #64748b; margin-top: 2px;">
            <span>Payment Method:</span>
            <span id="outPayment">Cash</span>
        </div>
    </div>

    <!-- Footer Notice -->
    <div style="text-align: center; font-size: 10px; color: #64748b; line-height: 1.4;">
        <p style="margin: 0 0 4px 0; font-weight: 700; color: #0f172a;">
            Thank you for choosing <?= htmlspecialchars($shopNameEn) ?>!
        </p>
        <p style="margin: 0; color: #713f12; background: #fef9c3; padding: 4px; border-radius: 4px;">
            * Offline mode receipt. Retain for warranty &amp; entry into back office upon server recovery.
        </p>
    </div>
</div>

<script>
const RATE = <?= $exchangeRate ?>;

function updatePreview() {
    const plate = document.getElementById('inPlate').value.trim().toUpperCase() || '[PLATE NUMBER]';
    const vtype = document.getElementById('inVType').value;
    const svc = document.getElementById('inService').value.trim() || '1x Wash Service';
    const price = parseFloat(document.getElementById('inPrice').value) || 0.0;
    const pay = document.getElementById('inPayment').value;

    document.getElementById('outPlate').innerText = plate;
    document.getElementById('outVType').innerText = vtype;
    document.getElementById('outService').innerText = svc;
    document.getElementById('outPrice').innerText = '$' + price.toFixed(2);
    document.getElementById('outTotalUsd').innerText = '$' + price.toFixed(2);
    document.getElementById('outTotalKhr').innerText = Math.round(price * RATE).toLocaleString() + ' ៛';
    document.getElementById('outPayment').innerText = pay;

    // Save in browser localStorage for offline recovery
    if (plate !== '[PLATE NUMBER]' && price > 0) {
        const item = {
            id: document.getElementById('lblReceiptNo').innerText,
            plate: plate,
            vtype: vtype,
            service: svc,
            price_usd: price,
            payment: pay,
            timestamp: new Date().toISOString()
        };
        try {
            let list = JSON.parse(localStorage.getItem('manual_mode_receipts') || '[]');
            // update or append
            list = list.filter(x => x.id !== item.id);
            list.push(item);
            localStorage.setItem('manual_mode_receipts', JSON.stringify(list));
        } catch (e) {}
    }
}
</script>

</body>
</html>
