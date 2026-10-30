<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('recovery.view');

$pageTitle = 'Printable Disaster Mode Paper Forms';
$pdo = Database::getInstance();

$shopNameEn = get_setting('shop_name_en', 'AquaGleam Car Wash & Detailing');
$shopNameKm = get_setting('shop_name_km', 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប');
$shopPhone = get_setting('shop_phone', '012 334 556');
$exchangeRate = (int)get_setting('exchange_rate', '4100');

$services = $pdo->query("SELECT id, name_en, name_km, category FROM services WHERE is_active = 1 ORDER BY category ASC, id ASC")->fetchAll();
$washers = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('washer', 'wash_supervisor') AND status = 'active' ORDER BY full_name ASC")->fetchAll();
$mechanics = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('technical', 'mechanic') AND status = 'active' ORDER BY full_name ASC")->fetchAll();

$formType = $_GET['form'] ?? 'job_card'; // 'job_card' or 'logbook'

require_once dirname(__DIR__, 2) . '/views/layouts/header.php';
?>

<style>
/* Screen Navigation & Controls */
.no-print-bar {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 14px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

/* Paper Styles - Printable Forms */
.paper-container {
    background: #ffffff;
    color: #000000;
    font-family: var(--font-sans), system-ui, sans-serif;
    margin: 0 auto;
}

@media print {
    body {
        background: #ffffff !important;
        color: #000000 !important;
        font-size: 11pt !important;
    }
    .topbar, .sidebar, .no-print-bar, .page-header, footer {
        display: none !important;
    }
    .page-container {
        padding: 0 !important;
        margin: 0 !important;
    }
    .paper-container {
        border: none !important;
        padding: 0 !important;
        box-shadow: none !important;
        max-width: 100% !important;
    }
    .page-break {
        page-break-after: always;
    }
}
</style>

<div class="no-print-bar">
    <div>
        <h1 style="font-size: 18px; font-weight: 700; margin: 0; color: var(--text-main);">
            <i class="fa-solid fa-print mr-2" style="color: var(--primary);"></i>Disaster Mode: Printable Paper Forms
        </h1>
        <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;">
            Print these blanks in advance to operate manually during power outages or server disruptions.
        </p>
    </div>

    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="index.php?view=paper_forms&form=job_card" class="btn btn-sm <?= ($formType === 'job_card') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid fa-id-card mr-1"></i> 1. Printable Job Card
        </a>
        <a href="index.php?view=paper_forms&form=logbook" class="btn btn-sm <?= ($formType === 'logbook') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid fa-table-list mr-1"></i> 2. Paper Reception Logbook
        </a>
        <a href="index.php?view=recovery_bulk_entry" class="btn btn-sm btn-secondary">
            <i class="fa-solid fa-keyboard mr-1"></i> Go to Bulk Recovery Entry
        </a>
        <button type="button" class="btn btn-sm btn-success" onclick="window.print()">
            <i class="fa-solid fa-print mr-1"></i> Print This Form (Ctrl+P)
        </button>
    </div>
</div>

<div class="paper-container" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); max-width: 900px;">

    <?php if ($formType === 'job_card'): ?>
        <!-- ======================================================== -->
        <!-- PRINTABLE EMERGENCY JOB CARDS (2-PER-PAGE TICKET STYLE) -->
        <!-- ======================================================== -->
        <?php for ($cardIdx = 1; $cardIdx <= 2; $cardIdx++): ?>
            <div style="border: 2px dashed #334155; border-radius: 8px; padding: 18px; margin-bottom: <?= ($cardIdx === 1) ? '24px' : '0' ?>; background: #fff;">
                <!-- Header -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 12px;">
                    <div>
                        <div style="font-size: 16px; font-weight: 800; text-transform: uppercase; color: #0f172a;">
                            <?= htmlspecialchars($shopNameEn) ?>
                        </div>
                        <div style="font-size: 12px; font-weight: 700; color: #475569;">
                            <?= htmlspecialchars($shopNameKm) ?> &bull; Tel: <?= htmlspecialchars($shopPhone) ?>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <span style="display: inline-block; background: #0f172a; color: #fff; padding: 3px 8px; font-size: 11px; font-weight: 800; border-radius: 4px; letter-spacing: 1px;">
                            EMERGENCY JOB CARD
                        </span>
                        <div style="font-size: 13px; font-weight: 800; margin-top: 4px; font-family: monospace;">
                            SLIP #: _________________
                        </div>
                    </div>
                </div>

                <!-- Customer & Vehicle Info Grid -->
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; margin-bottom: 10px; font-size: 12px;">
                    <div style="border: 1px solid #94a3b8; border-radius: 4px; padding: 6px;">
                        <strong>Plate Number (ផ្លាកលេខ):</strong>
                        <div style="font-size: 18px; font-weight: 800; font-family: monospace; letter-spacing: 2px; min-height: 24px; padding-top: 2px;">
                            &nbsp;
                        </div>
                    </div>
                    <div style="border: 1px solid #94a3b8; border-radius: 4px; padding: 6px;">
                        <strong>Bay # (បន្ទប់):</strong>
                        <div style="font-size: 15px; font-weight: 700; min-height: 24px;">&nbsp;</div>
                    </div>
                    <div style="border: 1px solid #94a3b8; border-radius: 4px; padding: 6px;">
                        <strong>Time In (ម៉ោងចូល):</strong>
                        <div style="font-size: 13px; font-weight: 700; min-height: 24px;">&nbsp;</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; font-size: 12px;">
                    <div><strong>Customer Name:</strong> ____________________________</div>
                    <div><strong>Customer Phone:</strong> ____________________________</div>
                </div>

                <!-- Vehicle Type Checkboxes -->
                <div style="background: #f1f5f9; padding: 6px 10px; border-radius: 4px; font-size: 11px; margin-bottom: 12px; display: flex; gap: 18px;">
                    <strong>Vehicle Type:</strong>
                    <label style="cursor: pointer;"><input type="checkbox"> Motorbike (ម៉ូតូ)</label>
                    <label style="cursor: pointer;"><input type="checkbox"> Small Car (ឡានតូច)</label>
                    <label style="cursor: pointer;"><input type="checkbox"> SUV / Pickup</label>
                    <label style="cursor: pointer;"><input type="checkbox"> Van / Truck</label>
                </div>

                <!-- Services Requested Checkboxes -->
                <div style="border: 1px solid #94a3b8; border-radius: 4px; padding: 8px; margin-bottom: 12px; font-size: 11px;">
                    <strong style="display: block; margin-bottom: 4px; text-transform: uppercase;">Requested Services &amp; Tasks (សេវាកម្ម):</strong>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;">
                        <label><input type="checkbox"> Basic Exterior Wash</label>
                        <label><input type="checkbox"> Full Premium Wash &amp; Vac</label>
                        <label><input type="checkbox"> Engine Bay Steam Wash</label>
                        <label><input type="checkbox"> Engine Oil &amp; Filter Change</label>
                        <label><input type="checkbox"> Brake &amp; Safety Inspection</label>
                        <label><input type="checkbox"> Hand Paste Wax Polish</label>
                        <label><input type="checkbox"> Tire Balancing &amp; Rotation</label>
                        <label><input type="checkbox"> Windshield Coating</label>
                        <label><input type="checkbox"> Other: ________________</label>
                    </div>
                </div>

                <!-- Staff Assignment & Payment Sign-off -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 10px; font-size: 11px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
                    <div>
                        <strong>Washer / Tech:</strong>
                        <div style="margin-top: 4px; border-bottom: 1px dotted #64748b; height: 20px;"></div>
                    </div>
                    <div>
                        <strong>Payment Collected:</strong>
                        <div style="margin-top: 4px;">
                            $ _______ USD &bull; _______ KHR<br>
                            <span style="font-size: 10px;">[ ] Cash &nbsp; [ ] KHQR / Bank</span>
                        </div>
                    </div>
                    <div>
                        <strong>Staff Signature &amp; Handover:</strong>
                        <div style="margin-top: 4px; border-bottom: 1px dotted #64748b; height: 20px;"></div>
                        <span style="font-size: 9px; color: #64748b;">Notice: File slip for recovery bulk entry upon power return.</span>
                    </div>
                </div>
            </div>
            <?php if ($cardIdx === 1): ?>
                <div style="text-align: center; color: #94a3b8; font-size: 10px; margin: -12px 0 12px 0;">
                    &mdash; - - - - - - - - - - - - - - - - - - - Cut Here - - - - - - - - - - - - - - - - - - - &mdash;
                </div>
            <?php endif; ?>
        <?php endfor; ?>

    <?php else: ?>
        <!-- ======================================================== -->
        <!-- PRINTABLE PAPER RECEPTION LOGBOOK SHEET (LANDSCAPE / TABULAR) -->
        <!-- ======================================================== -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 12px;">
            <div>
                <h2 style="font-size: 18px; font-weight: 800; margin: 0; color: #0f172a; text-transform: uppercase;">
                    <?= htmlspecialchars($shopNameEn) ?> &mdash; Reception Paper Logbook
                </h2>
                <div style="font-size: 12px; color: #475569;">
                    <?= htmlspecialchars($shopNameKm) ?> &bull; សៀវភៅកត់ត្រារថយន្តពេលដាច់ភ្លើង ឬប្រព័ន្ធផ្អាក
                </div>
            </div>
            <div style="text-align: right; font-size: 12px;">
                <strong>Date:</strong> ____________________ &nbsp;
                <strong>Cashier:</strong> ____________________
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 10.5px;">
            <thead>
                <tr style="background: #f1f5f9; border: 1.5px solid #0f172a;">
                    <th style="border: 1px solid #475569; padding: 6px; width: 35px; text-align: center;">#</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 65px; text-align: center;">Slip #</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 60px; text-align: center;">Time In</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 100px;">Plate Number</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 85px;">Vehicle Type</th>
                    <th style="border: 1px solid #475569; padding: 6px;">Services Requested</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 40px; text-align: center;">Bay</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 85px;">Staff Name</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 70px; text-align: right;">Total USD</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 60px; text-align: center;">Pay Mode</th>
                    <th style="border: 1px solid #475569; padding: 6px; width: 60px; text-align: center;">Time Out</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($row = 1; $row <= 18; $row++): ?>
                    <tr style="height: 32px;">
                        <td style="border: 1px solid #94a3b8; text-align: center; font-weight: 700;"><?= $row ?></td>
                        <td style="border: 1px solid #94a3b8;"></td>
                        <td style="border: 1px solid #94a3b8;"></td>
                        <td style="border: 1px solid #94a3b8; font-family: monospace; font-weight: 700;"></td>
                        <td style="border: 1px solid #94a3b8;"></td>
                        <td style="border: 1px solid #94a3b8;"></td>
                        <td style="border: 1px solid #94a3b8; text-align: center;"></td>
                        <td style="border: 1px solid #94a3b8;"></td>
                        <td style="border: 1px solid #94a3b8; text-align: right;">$</td>
                        <td style="border: 1px solid #94a3b8; text-align: center;"></td>
                        <td style="border: 1px solid #94a3b8;"></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-top: 14px; font-size: 11px; border-top: 1.5px solid #0f172a; padding-top: 10px;">
            <div>
                <strong>Shift Summary:</strong> Total Cars: ________ &bull; Total Cash USD: $ ________ &bull; Total KHR: ________ ៛ &bull; KHQR USD: $ ________
            </div>
            <div>
                <strong>Supervisor Verification:</strong> ____________________________ (Signature)
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require_once dirname(__DIR__, 2) . '/views/layouts/footer.php'; ?>
