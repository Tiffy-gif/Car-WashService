<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/CashierControl.php';

$appTimezone = get_setting('app_timezone', 'Asia/Phnom_Penh');
if (!empty($appTimezone)) {
    @date_default_timezone_set($appTimezone);
}

$type = $_GET['type'] ?? 'end'; // 'open', 'end', 'close_day'
$id = (int)($_GET['id'] ?? $_GET['shift_id'] ?? $_GET['close_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');
$zrep = $_GET['zrep'] ?? '';
$rawOtk = trim((string)($_GET['otk'] ?? ''));

$slipRef = ($type === 'close_day') ? (!empty($zrep) ? $zrep : ($id > 0 ? (string)$id : $date)) : (string)$id;

// ========================================================
// SECURITY ACCESS CONTROL: Dual-Path Authentication
// Path 1: Logged-in Web Session (Owner, Manager, or Shift Cashier)
// Path 2: Scrambled 2-minute One-Time Token (Tablet Fallback Print)
// ========================================================
$pdo = Database::getInstance();
$isAuthorized = false;
$authErrorType = 'denied'; // 'denied', 'expired', 'table_missing'

Auth::startSession();
if (Auth::check()) {
    $uRole = strtolower((string)Auth::getRole());
    $uId = (int)Auth::getUserId();
    if (in_array($uRole, ['owner', 'admin', 'system_admin', 'manager'], true)) {
        $isAuthorized = true;
    } elseif ($uRole === 'cashier') {
        if ($type === 'open' || $type === 'end') {
            $shiftOwnerId = (int)$pdo->query("SELECT cashier_id FROM cashier_shifts WHERE id = " . (int)$id)->fetchColumn();
            if ($shiftOwnerId === $uId) {
                $isAuthorized = true;
            }
        } elseif ($type === 'close_day') {
            $closerId = (int)$pdo->query("SELECT closed_by_user_id FROM pos_daily_closes WHERE id = " . (int)$id . " OR z_report_number = " . $pdo->quote($slipRef) . " OR close_date = " . $pdo->quote($slipRef))->fetchColumn();
            if ($closerId === $uId) {
                $isAuthorized = true;
            }
        }
    }
} elseif ($rawOtk !== '') {
    $otkRes = CashierControl::validateAndConsumeSlipPrintToken($rawOtk, $type, $slipRef);
    if (!empty($otkRes['valid'])) {
        $isAuthorized = true;
    } else {
        $authErrorType = $otkRes['error'] ?? 'denied';
    }
}

if (!$isAuthorized) {
    http_response_code(403);
    $titleEn = "Access Denied";
    $titleKm = "ការចូលប្រើត្រូវបានបដិសេធ";
    $descEn = "You do not have permission to view this slip. Only the owner, a manager, or the cashier assigned to this shift can access this page.";
    $descKm = "អ្នកមិនមានការអនុញ្ញាតដើម្បីមើលវិក្កយបត្រនេះទេ។ មានតែម្ចាស់ហាង អ្នកគ្រប់គ្រង ឬអ្នកគិតលុយនៃវេននេះប៉ុណ្ណោះដែលអាចមើលបាន។";

    if ($authErrorType === 'expired' || $authErrorType === 'already_used') {
        $titleEn = "Print Link Expired";
        $titleKm = "តំណភ្ជាប់បោះពុម្ពបានផុតកំណត់";
        $descEn = "This one-time print link has expired or has already been used. Please request a new print from the POS screen.";
        $descKm = "តំណភ្ជាប់បោះពុម្ពប្រើម្តងនេះបានផុតកំណត់ ឬត្រូវបានប្រើប្រាស់រួចហើយ។ សូមស្នើសុំបោះពុម្ពម្តងទៀតពីផ្ទាំង POS។";
    } elseif ($authErrorType === 'table_missing') {
        $titleEn = "Service Updating";
        $titleKm = "សេវាកម្មកំពុងធ្វើបច្ចុប្បន្នភាព";
        $descEn = "Slip printing security service is currently updating. Please try again shortly or log in.";
        $descKm = "សេវាកម្មបោះពុម្ពវិក្កយបត្រកំពុងធ្វើបច្ចុប្បន្នភាព។ សូមព្យាយាមម្តងទៀតក្នុងពេលបន្តិចទៀត ឬចូលគណនី។";
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($titleEn) ?> - Car Wash Pro</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { background: #f8fafc; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
            .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08); max-width: 440px; width: 100%; padding: 32px 24px; text-align: center; }
            .icon { width: 64px; height: 64px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 16px; }
            .title { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 6px; }
            .subtitle { font-size: 14px; font-weight: 600; color: #dc2626; margin-bottom: 14px; }
            .desc { font-size: 13px; color: #64748b; line-height: 1.5; margin-bottom: 24px; }
            .btn { display: inline-block; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; background: #0284c7; color: #fff; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon"><i class="fa-solid fa-lock"></i></div>
            <div class="title"><?= htmlspecialchars($titleEn) ?></div>
            <div class="subtitle"><?= htmlspecialchars($titleKm) ?></div>
            <div class="desc"><?= htmlspecialchars($descEn) ?><br><br><?= htmlspecialchars($descKm) ?></div>
            <a href="../../index.php" class="btn"><i class="fa-solid fa-house"></i> Return to Backoffice</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$slipData = null;
$error = null;

try {
    if ($type === 'open') {
        if ($id <= 0) {
            throw new Exception("Shift ID is required.");
        }
        $slipData = CashierControl::getOpenShiftSlipData($id);
    } elseif ($type === 'close_day') {
        $ref = !empty($zrep) ? $zrep : ($id > 0 ? (string)$id : $date);
        $slipData = CashierControl::getCloseDaySlipData($ref);
    } else { // 'end'
        if ($id <= 0) {
            throw new Exception("Shift ID is required.");
        }
        $slipData = CashierControl::getEndShiftSlipData($id);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$shop = $slipData['shop'] ?? [
    'shop_name_en' => get_setting('shop_name_en', 'My Car Wash'),
    'shop_name_km' => get_setting('shop_name_km', 'សេវាកម្មលាងរថយន្ត និងម៉ូតូ'),
    'shop_phone'   => get_setting('shop_phone', '012 334 556'),
    'shop_address' => get_setting('shop_address', 'Phnom Penh, Cambodia'),
    'shop_logo'    => get_setting('shop_logo', '')
];

$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($type === 'open' ? 'Open Float Slip' : ($type === 'close_day' ? 'Close Day Slip (Z-Report)' : 'End Shift Slip')) ?></title>
    <link rel="stylesheet" href="../../public/css/receipt-unified.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            background-color: #f1f5f9;
            margin: 0;
            padding: 20px;
            font-family: "Kantumruy Pro", "Noto Sans Khmer", "Segoe UI", sans-serif;
            color: #000000;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .slip-container {
            width: 80mm;
            max-width: 100%;
            background: #ffffff;
            box-shadow: 0 4px 14px rgba(0,0,0,0.1);
            padding: 24px 18px 45px 18px;
            box-sizing: border-box;
            font-size: 14px;
            line-height: 1.35;
        }
        .slip-actions {
            margin-bottom: 16px;
            display: flex;
            gap: 10px;
        }
        .btn-slip {
            background: #0284c7;
            color: #ffffff;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-slip-secondary {
            background: #64748b;
        }
        .slip-header {
            text-align: center;
            margin-bottom: 14px;
        }
        .slip-logo {
            max-width: 120px;
            max-height: 60px;
            margin-bottom: 6px;
        }
        .shop-km {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
        }
        .shop-en {
            font-size: 15px;
            font-weight: bold;
            margin: 2px 0;
        }
        .shop-sub {
            font-size: 12px;
            color: #111;
            margin: 2px 0;
        }
        .slip-title {
            text-align: center;
            font-size: 16px;
            font-weight: 800;
            margin: 12px 0 8px 0;
            padding: 4px;
            border-top: 1.5px dashed #000;
            border-bottom: 1.5px dashed #000;
        }
        .slip-row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
            font-size: 13px;
        }
        .slip-row-bold {
            font-weight: 700;
            font-size: 14px;
        }
        .slip-divider {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }
        .slip-divider-solid {
            border-top: 1.5px solid #000;
            margin: 8px 0;
        }
        .slip-table {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0;
            font-size: 13px;
        }
        .slip-table th, .slip-table td {
            padding: 3px 0;
        }
        .slip-table th {
            border-bottom: 1px solid #000;
            text-align: left;
        }
        .slip-table th.r, .slip-table td.r {
            text-align: right;
        }
        .badge-status {
            font-weight: 800;
            padding: 1px 4px;
            border-radius: 3px;
        }
        .badge-short {
            background: #000;
            color: #fff;
        }
        .badge-over {
            background: #222;
            color: #fff;
        }
        .badge-exact {
            background: #eee;
            color: #000;
            border: 1px solid #000;
        }
        .slip-signatures {
            margin-top: 25px;
            display: flex;
            justify-content: space-between;
            text-align: center;
            font-size: 12px;
        }
        .sig-block {
            width: 45%;
        }
        .sig-line {
            margin-top: 40px;
            border-top: 1px dashed #000;
            padding-top: 4px;
            font-weight: 600;
        }
        .slip-footer {
            margin-top: 18px;
            text-align: center;
            font-size: 11px;
            color: #222;
        }
        .reopen-banner {
            border: 2px solid #000;
            padding: 6px;
            margin: 8px 0;
            font-size: 12px;
            text-align: center;
            font-weight: 800;
            background: #f8fafc;
        }
        /* Generous 55px safe buffer before physical blade cut */
        .cut-buffer {
            height: 55px;
            width: 100%;
        }
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .slip-actions {
                display: none !important;
            }
            .slip-container {
                box-shadow: none;
                width: 100%;
                padding: 10px 8px 30px 8px;
            }
        }
    </style>
</head>
<body>

    <div class="slip-actions">
        <button class="btn-slip" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print Slip (80mm)
        </button>
        <button class="btn-slip btn-slip-secondary" onclick="window.close()">
            <i class="fa-solid fa-xmark"></i> Close
        </button>
    </div>

    <?php if ($error): ?>
        <div class="slip-container" style="text-align:center; color: #b91c1c; padding: 40px 20px;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size: 32px; margin-bottom: 12px;"></i>
            <h3 style="margin: 0 0 8px 0;">Slip Not Found</h3>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
    <?php else: ?>

        <div class="slip-container">
            <!-- Shop Header -->
            <div class="slip-header">
                <?php if (!empty($shop['shop_logo'])): ?>
                    <img src="../../<?= ltrim(htmlspecialchars($shop['shop_logo']), '/') ?>" class="slip-logo" alt="Logo" onerror="this.style.display='none'">
                <?php endif; ?>
                <div class="shop-km"><?= htmlspecialchars($shop['shop_name_km']) ?></div>
                <div class="shop-en"><?= htmlspecialchars($shop['shop_name_en']) ?></div>
                <div class="shop-sub"><?= htmlspecialchars($shop['shop_address']) ?></div>
                <div class="shop-sub">Tel: <?= htmlspecialchars($shop['shop_phone']) ?></div>
            </div>

            <!-- SLIP TYPE SPECIFIC CONTENT -->
            <?php if ($type === 'open'): ?>
                <!-- ================= 1. OPEN FLOAT SLIP ================= -->
                <div class="slip-title">
                    OPEN FLOAT SLIP<br>
                    <span style="font-size: 13px; font-weight: normal;">សន្លឹកបើកវេន (FLOAT)</span>
                </div>

                <div class="slip-row"><span>Shift #:</span> <strong><?= htmlspecialchars($slipData['shift_uid']) ?></strong></div>
                <div class="slip-row"><span>Cashier:</span> <strong><?= htmlspecialchars($slipData['cashier_name']) ?> (<?= htmlspecialchars($slipData['cashier_role'] ?? 'Cashier') ?>)</strong></div>
                <div class="slip-row"><span>Terminal:</span> <span><?= htmlspecialchars($slipData['device_name']) ?></span></div>
                <div class="slip-row"><span>Opened At:</span> <span><?= date('d/m/Y H:i', strtotime($slipData['started_at'])) ?></span></div>
                <div class="slip-row"><span>Exchange Rate:</span> <span>$1.00 = <?= number_format($slipData['exchange_rate']) ?> ៛</span></div>

                <div class="slip-divider"></div>

                <div class="slip-row slip-row-bold">
                    <span>Float USD:</span>
                    <span>$<?= number_format($slipData['opening_cash_usd'], 2) ?></span>
                </div>
                <div class="slip-row slip-row-bold">
                    <span>Float KHR:</span>
                    <span><?= number_format($slipData['opening_cash_khr']) ?> ៛</span>
                </div>
                <div class="slip-divider-solid"></div>
                <div class="slip-row slip-row-bold" style="font-size: 15px;">
                    <span>Total Float (USD):</span>
                    <span>$<?= number_format($slipData['total_opening_usd'], 2) ?></span>
                </div>

                <?php if (!empty($slipData['notes'])): ?>
                    <div class="slip-divider"></div>
                    <div style="font-size: 12px;"><strong>Note:</strong> <?= htmlspecialchars($slipData['notes']) ?></div>
                <?php endif; ?>

                <div class="slip-signatures">
                    <div class="sig-block">
                        <div class="sig-line">Cashier Signature</div>
                    </div>
                    <div class="sig-block">
                        <div class="sig-line">Manager Signature</div>
                    </div>
                </div>

            <?php elseif ($type === 'close_day'): ?>
                <!-- ================= 3. CLOSE DAY SLIP (Z-REPORT) ================= -->
                <div class="slip-title">
                    DAILY CLOSE (Z-REPORT)<br>
                    <span style="font-size: 13px; font-weight: normal;">សន្លឹកបិទបញ្ជីប្រចាំថ្ងៃ</span>
                </div>

                <div class="slip-row"><span>Z-Report #:</span> <strong><?= htmlspecialchars($slipData['z_report_number']) ?></strong></div>
                <div class="slip-row"><span>Business Date:</span> <strong><?= htmlspecialchars($slipData['close_date']) ?></strong></div>
                <div class="slip-row"><span>Day Opened By:</span> <strong><?= htmlspecialchars($slipData['opened_by_name'] ?? 'N/A') ?> (<?= htmlspecialchars($slipData['opened_by_role'] ?? 'Cashier') ?>)</strong></div>
                <?php if (!empty($slipData['opened_at'])): ?>
                    <div class="slip-row"><span>Opened At:</span> <span><?= date('d/m/Y H:i', strtotime($slipData['opened_at'])) ?></span></div>
                <?php endif; ?>
                <div class="slip-row"><span>Day Closed By:</span> <strong><?= htmlspecialchars($slipData['closed_by_name'] ?? $slipData['manager_name']) ?> (<?= htmlspecialchars($slipData['closed_by_role'] ?? 'Cashier') ?>)</strong></div>
                <div class="slip-row"><span>Closed At:</span> <span><?= date('d/m/Y H:i', strtotime($slipData['closed_at'])) ?></span></div>
                <div class="slip-row"><span>Exchange Rate:</span> <span>$1.00 = <?= number_format($slipData['exchange_rate']) ?> ៛</span></div>

                <?php if (!empty($slipData['needs_manager_review'])): ?>
                    <div class="reopen-banner" style="background: #fee2e2; border-color: #dc2626; color: #991b1b; padding: 6px; font-weight: bold; margin-bottom: 8px;">
                        ** NEEDS MANAGER REVIEW **<br>
                        <span style="font-size: 11px; font-weight: normal;">Discrepancy detected on Close Day (Short/Over: <?= ($slipData['total_difference_usd'] > 0 ? '+' : '') ?>$<?= number_format($slipData['total_difference_usd'], 2) ?>). Requires manager review and sign-off.</span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($slipData['reopen_history'])): ?>
                    <div class="reopen-banner">
                        ** ATTENTION: DAY WAS REOPENED **<br>
                        <?php foreach ($slipData['reopen_history'] as $rh): ?>
                            <span style="font-size: 11px; font-weight: normal;">
                                <?= htmlspecialchars($rh['reopened_at'] ?? '') ?> - <?= htmlspecialchars($rh['reason'] ?? '') ?>
                            </span><br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="slip-divider"></div>
                <div style="font-weight: 700; margin-bottom: 4px; text-transform: uppercase; font-size: 13px;">Sales Overview</div>
                <div class="slip-row"><span>Paid Orders Count:</span> <strong><?= $slipData['paid_orders_count'] ?></strong></div>
                <div class="slip-row"><span>Cash Sales (<?= $slipData['cash_sales_count'] ?>):</span> <span>$<?= number_format($slipData['cash_sales_usd'], 2) ?></span></div>
                <div class="slip-row"><span>ABA Pay:</span> <span>$<?= number_format($slipData['aba_sales_usd'], 2) ?></span></div>
                <div class="slip-row"><span>KHQR Pay:</span> <span>$<?= number_format($slipData['khqr_sales_usd'], 2) ?></span></div>
                <div class="slip-row"><span>Total Bank (<?= $slipData['bank_sales_count'] ?>):</span> <strong>$<?= number_format($slipData['bank_sales_usd'], 2) ?></strong></div>
                <?php if ($slipData['discount_total_usd'] > 0): ?>
                    <div class="slip-row"><span>Total Discounts:</span> <span>-$<?= number_format($slipData['discount_total_usd'], 2) ?></span></div>
                <?php endif; ?>
                <?php if ($slipData['voids_count'] > 0): ?>
                    <div class="slip-row"><span>Voids / Cancelled (<?= $slipData['voids_count'] ?>):</span> <span>$<?= number_format($slipData['voids_total_usd'], 2) ?></span></div>
                <?php endif; ?>
                <?php if ($slipData['cash_refunds_usd'] > 0): ?>
                    <div class="slip-row"><span>Refunds Paid:</span> <span>-$<?= number_format($slipData['cash_refunds_usd'], 2) ?></span></div>
                <?php endif; ?>
                <div class="slip-divider-solid"></div>
                <div class="slip-row slip-row-bold" style="font-size: 15px;">
                    <span>Total Sales:</span>
                    <span>$<?= number_format($slipData['total_sales_usd'], 2) ?></span>
                </div>
                <div class="slip-row" style="font-size: 12px; color: #333;">
                    <span>Total Sales (KHR):</span>
                    <span><?= number_format($slipData['total_sales_khr']) ?> ៛</span>
                </div>

                <div class="slip-divider"></div>
                <div style="font-weight: 700; margin-bottom: 4px; text-transform: uppercase; font-size: 13px;">Cash Drawer Consolidation</div>
                <table class="slip-table">
                    <thead>
                        <tr><th>Description</th><th class="r">USD ($)</th><th class="r">KHR (៛)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Opening Float</td><td class="r">$<?= number_format($slipData['opening_cash_usd'], 2) ?></td><td class="r"><?= number_format($slipData['opening_cash_khr']) ?></td></tr>
                        <tr><td>Cash Received</td><td class="r">+$<?= number_format($slipData['cash_received_usd'], 2) ?></td><td class="r">+<?= number_format($slipData['cash_received_khr']) ?></td></tr>
                        <tr><td>Change Given</td><td class="r">-$<?= number_format($slipData['change_given_usd'], 2) ?></td><td class="r">-<?= number_format($slipData['change_given_khr']) ?></td></tr>
                        <tr><td>Cash In</td><td class="r">+$<?= number_format($slipData['cash_in_usd'], 2) ?></td><td class="r">+<?= number_format($slipData['cash_in_khr']) ?></td></tr>
                        <tr><td><?= htmlspecialchars(__('slip_cash_out_drawer_payout', (class_exists('Language') && Language::current() === 'km') ? 'ដកប្រាក់ចេញ / ការបើកថតប្រាក់' : 'Cash Out / Drawer Payout')) ?></td><td class="r">-$<?= number_format($slipData['cash_out_usd'], 2) ?></td><td class="r">-<?= number_format($slipData['cash_out_khr']) ?></td></tr>
                        <tr><td>Refunds Paid</td><td class="r">-$<?= number_format($slipData['cash_refunds_usd'], 2) ?></td><td class="r">-<?= number_format($slipData['cash_refunds_khr']) ?></td></tr>
                        <tr style="font-weight: bold; border-top: 1px solid #000;">
                            <td>Expected Cash</td>
                            <td class="r">$<?= number_format($slipData['expected_cash_usd'], 2) ?></td>
                            <td class="r"><?= number_format($slipData['expected_cash_khr']) ?></td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td>Counted Cash</td>
                            <td class="r">$<?= number_format($slipData['counted_cash_usd'], 2) ?></td>
                            <td class="r"><?= number_format($slipData['counted_cash_khr']) ?></td>
                        </tr>
                        <tr style="font-weight: 800; border-top: 1px dashed #000;">
                            <td>Difference</td>
                            <td class="r">
                                <?= ($slipData['difference_usd'] > 0 ? '+' : '') ?>$<?= number_format($slipData['difference_usd'], 2) ?>
                                <span class="badge-status badge-<?= $slipData['diff_usd_status'] ?>"><?= strtoupper($slipData['diff_usd_status']) ?></span>
                            </td>
                            <td class="r">
                                <?= ($slipData['difference_khr'] > 0 ? '+' : '') ?><?= number_format($slipData['difference_khr']) ?>
                                <span class="badge-status badge-<?= $slipData['diff_khr_status'] ?>"><?= strtoupper($slipData['diff_khr_status']) ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="slip-divider-solid"></div>
                <div class="slip-row slip-row-bold" style="font-size: 15px;">
                    <span>Whole Day Difference:</span>
                    <span>
                        <?= ($slipData['total_difference_usd'] > 0 ? '+' : '') ?>$<?= number_format($slipData['total_difference_usd'], 2) ?>
                        <span class="badge-status badge-<?= $slipData['total_diff_status'] ?>"><?= strtoupper($slipData['total_diff_status']) ?></span>
                    </span>
                </div>

                <?php if (!empty($slipData['shifts'])): ?>
                    <div class="slip-divider"></div>
                    <div style="font-weight: 700; margin-bottom: 4px; text-transform: uppercase; font-size: 13px;">Shifts Breakdown (<?= count($slipData['shifts']) ?>)</div>
                    <?php foreach ($slipData['shifts'] as $sh): ?>
                        <div style="font-size: 12px; margin-bottom: 6px; padding: 4px; background: #f8fafc; border-left: 2px solid #000;">
                            <div><strong><?= htmlspecialchars($sh['shift_uid']) ?></strong> - <?= htmlspecialchars($sh['cashier_name']) ?></div>
                            <div style="color: #444;"><?= date('H:i', strtotime($sh['started_at'])) ?> - <?= date('H:i', strtotime($sh['ended_at'])) ?></div>
                            <div class="slip-row" style="margin: 1px 0;">
                                <span>Counted: $<?= number_format($sh['counted_usd'], 2) ?> / <?= number_format($sh['counted_khr']) ?>៛</span>
                                <strong>Diff: <?= ($sh['total_diff_usd'] > 0 ? '+' : '') ?>$<?= number_format($sh['total_diff_usd'], 2) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="slip-signatures">
                    <div class="sig-block" style="width: 100%;">
                        <div class="sig-line">Closed By: <?= htmlspecialchars($slipData['closed_by_name'] ?? $slipData['manager_name']) ?> (<?= htmlspecialchars($slipData['closed_by_role'] ?? 'Cashier') ?>)</div>
                    </div>
                    <?php if (!empty($slipData['needs_manager_review'])): ?>
                        <div class="sig-block" style="width: 100%; margin-top: 15px;">
                            <div class="sig-line">Manager Sign-Off: ________________________</div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- ================= 2. END SHIFT SLIP ================= -->
                <div class="slip-title">
                    <?= !empty($slipData['handover_to_name']) ? 'SHIFT HANDOVER SLIP' : 'SOLO END SHIFT SLIP' ?><br>
                    <span style="font-size: 13px; font-weight: normal;"><?= !empty($slipData['handover_to_name']) ? 'សន្លឹកផ្ទេរវេនបេឡា' : 'សន្លឹកបិទវេនបេឡាតែឯង' ?></span>
                </div>

                <div class="slip-row"><span>Shift #:</span> <strong><?= htmlspecialchars($slipData['shift_uid']) ?></strong></div>
                <div class="slip-row"><span>Cashier:</span> <strong><?= htmlspecialchars($slipData['cashier_name']) ?> (<?= htmlspecialchars($slipData['cashier_role'] ?? 'Cashier') ?>)</strong></div>
                <?php if (!empty($slipData['handover_to_name'])): ?>
                    <div class="slip-row"><span>Handover To:</span> <strong><?= htmlspecialchars($slipData['handover_to_name']) ?> (<?= htmlspecialchars($slipData['handover_to_role'] ?? 'Cashier') ?>)</strong></div>
                <?php endif; ?>
                <div class="slip-row"><span>Terminal:</span> <span><?= htmlspecialchars($slipData['device_name']) ?></span></div>
                <div class="slip-row"><span>Started:</span> <span><?= date('d/m/Y H:i', strtotime($slipData['started_at'])) ?></span></div>
                <div class="slip-row"><span>Ended:</span> <span><?= date('d/m/Y H:i', strtotime($slipData['ended_at'])) ?></span></div>
                <div class="slip-row"><span>Exchange Rate:</span> <span>$1.00 = <?= number_format($slipData['exchange_rate']) ?> ៛</span></div>

                <div class="slip-divider"></div>
                <div style="font-weight: 700; margin-bottom: 4px; text-transform: uppercase; font-size: 13px;">Shift Sales Summary</div>
                <div class="slip-row">
                    <span>Cash Sales (<?= $slipData['cash_sales_count'] ?> orders):</span>
                    <strong>$<?= number_format($slipData['cash_sales_usd'], 2) ?></strong>
                </div>
                <div class="slip-row">
                    <span>ABA / KHQR (<?= $slipData['bank_sales_count'] ?> orders):</span>
                    <strong>$<?= number_format($slipData['bank_sales_usd'], 2) ?></strong>
                </div>
                <div class="slip-divider-solid"></div>
                <div class="slip-row slip-row-bold" style="font-size: 15px;">
                    <span>Total Sales (<?= $slipData['total_orders_count'] ?> orders):</span>
                    <span>$<?= number_format($slipData['total_sales_usd'], 2) ?></span>
                </div>
                <div style="font-size: 11px; color: #555; text-align: right; margin-top: 2px;">
                    * ABA and KHQR are not in drawer cash
                </div>

                <div class="slip-divider"></div>
                <div style="font-weight: 700; margin-bottom: 4px; text-transform: uppercase; font-size: 13px;">Drawer Count Table</div>
                <table class="slip-table">
                    <thead>
                        <tr><th>Line</th><th class="r">USD ($)</th><th class="r">KHR (៛)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Opening Float</td><td class="r">$<?= number_format($slipData['opening_cash_usd'], 2) ?></td><td class="r"><?= number_format($slipData['opening_cash_khr']) ?></td></tr>
                        <tr><td>Cash Received</td><td class="r">+$<?= number_format($slipData['cash_received_usd'], 2) ?></td><td class="r">+<?= number_format($slipData['cash_received_khr']) ?></td></tr>
                        <tr><td>Change Given</td><td class="r">-$<?= number_format($slipData['change_given_usd'], 2) ?></td><td class="r">-<?= number_format($slipData['change_given_khr']) ?></td></tr>
                        <tr><td>Cash In</td><td class="r">+$<?= number_format($slipData['cash_in_usd'], 2) ?></td><td class="r">+<?= number_format($slipData['cash_in_khr']) ?></td></tr>
                        <tr><td><?= htmlspecialchars(__('slip_cash_out_drawer_payout', (class_exists('Language') && Language::current() === 'km') ? 'ដកប្រាក់ចេញ / ការបើកថតប្រាក់' : 'Cash Out / Drawer Payout')) ?></td><td class="r">-$<?= number_format($slipData['cash_out_usd'], 2) ?></td><td class="r">-<?= number_format($slipData['cash_out_khr']) ?></td></tr>
                        <tr><td>Cash Refunds</td><td class="r">-$<?= number_format($slipData['cash_refunds_usd'], 2) ?></td><td class="r">-<?= number_format($slipData['cash_refunds_khr']) ?></td></tr>
                        <tr style="font-weight: bold; border-top: 1px solid #000;">
                            <td>Expected Cash</td>
                            <td class="r">$<?= number_format($slipData['expected_cash_usd'], 2) ?></td>
                            <td class="r"><?= number_format($slipData['expected_cash_khr']) ?></td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td>Counted Cash</td>
                            <td class="r">$<?= number_format($slipData['counted_cash_usd'], 2) ?></td>
                            <td class="r"><?= number_format($slipData['counted_cash_khr']) ?></td>
                        </tr>
                        <tr style="font-weight: 800; border-top: 1px dashed #000;">
                            <td>Difference</td>
                            <td class="r">
                                <?= ($slipData['difference_usd'] > 0 ? '+' : '') ?>$<?= number_format($slipData['difference_usd'], 2) ?>
                                <span class="badge-status badge-<?= $slipData['diff_usd_status'] ?>"><?= strtoupper($slipData['diff_usd_status']) ?></span>
                            </td>
                            <td class="r">
                                <?= ($slipData['difference_khr'] > 0 ? '+' : '') ?><?= number_format($slipData['difference_khr']) ?>
                                <span class="badge-status badge-<?= $slipData['diff_khr_status'] ?>"><?= strtoupper($slipData['diff_khr_status']) ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="slip-divider-solid"></div>
                <div class="slip-row slip-row-bold" style="font-size: 15px;">
                    <span>Total Difference (USD):</span>
                    <span>
                        <?= ($slipData['total_difference_usd'] > 0 ? '+' : '') ?>$<?= number_format($slipData['total_difference_usd'], 2) ?>
                        <span class="badge-status badge-<?= $slipData['total_diff_status'] ?>"><?= strtoupper($slipData['total_diff_status']) ?></span>
                    </span>
                </div>

                <?php if (!empty($slipData['notes'])): ?>
                    <div class="slip-divider"></div>
                    <div style="font-size: 12px;"><strong>Note:</strong> <?= htmlspecialchars($slipData['notes']) ?></div>
                <?php endif; ?>

                <?php if (!empty($slipData['unpaid_orders'])): ?>
                    <div class="slip-divider"></div>
                    <div style="font-weight: 700; margin-bottom: 4px; text-transform: uppercase; font-size: 13px;">Carried Over Orders (<?= count($slipData['unpaid_orders']) ?>)</div>
                    <div style="font-size: 11px; margin-bottom: 6px; color: #555;">Unpaid/unfinished orders carry over to the next shift:</div>
                    <?php foreach ($slipData['unpaid_orders'] as $uo): ?>
                        <div class="slip-row" style="font-size: 12px; margin: 2px 0;">
                            <span><?= htmlspecialchars($uo['order_number']) ?> (<?= htmlspecialchars($uo['vehicle_plate'] ?: 'No Plate') ?>)</span>
                            <strong>$<?= number_format((float)$uo['total_usd'], 2) ?> [<?= strtoupper($uo['status']) ?>]</strong>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="slip-signatures">
                    <div class="sig-block">
                        <div class="sig-line">Outgoing Cashier: <?= htmlspecialchars($slipData['cashier_name']) ?> (<?= htmlspecialchars($slipData['cashier_role'] ?? 'Cashier') ?>)</div>
                    </div>
                    <div class="sig-block">
                        <div class="sig-line"><?= !empty($slipData['handover_to_name']) ? 'Incoming Cashier: ' . htmlspecialchars($slipData['handover_to_name']) . ' (' . htmlspecialchars($slipData['handover_to_role'] ?? 'Cashier') . ')' : 'Manager Sign-Off' ?></div>
                    </div>
                </div>

            <?php endif; ?>

            <div class="slip-footer">
                Printed on <?= date('d/m/Y H:i:s') ?> | Counter POS
            </div>

            <!-- Generous blank paper feed before cutter blade so signatures are never cut off -->
            <div class="cut-buffer"></div>
        </div>

    <?php endif; ?>

    <?php if ($autoPrint && !$error): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => window.print(), 350);
            });
        </script>
    <?php endif; ?>
</body>
</html>
