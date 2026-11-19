<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/CashierControl.php';

Auth::requirePermission('pos.access');
$pageTitle = __('nav_shifts', 'Shifts & Cash Handover');
$pdo = Database::getInstance();

$currentUserId = (int)Auth::getUserId();
$currentUsername = Auth::getUsername() ?? '';
$currentUserRole = Auth::getRole() ?? 'cashier';
$currentFullName = Auth::getFullName() ?? $currentUsername;
$isManagerOrOwner = in_array($currentUserRole, ['owner', 'manager', 'system_admin'], true);
$canCloseDay = $isManagerOrOwner || Auth::userHasPermission($currentUserId, 'daily_close.close') || Auth::userHasPermission($currentUserId, 'pos.close_day') || $currentUserRole === 'cashier';

$message = null;
$messageType = 'success';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Start Shift (Cashier enters starting cash / change fund)
        if ($action === 'start_shift') {
            $openingUsd = max(0.00, (float)($_POST['opening_cash_usd'] ?? 0.00));
            $openingKhr = max(0, (int)($_POST['opening_cash_khr'] ?? 0));
            $notes = trim($_POST['notes'] ?? '');

            $res = CashierControl::startShift($currentUserId, $currentFullName, $openingUsd, $openingKhr, null, null, $notes);
            $message = "Shift started successfully with starting cash $" . number_format($openingUsd, 2) . ". <a href='slip.php?type=open&id=" . (int)$res['shift_id'] . "' target='_blank' class='btn btn-xs btn-primary ml-2' style='display:inline-flex;align-items:center;margin-left:8px;'><i class='fa-solid fa-print mr-1'></i> Print Open Float Slip</a>";
            $messageType = 'success';
        }

        // 2. End Shift / Handover (Both cashiers sign off with PINs)
        elseif ($action === 'end_shift') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $countedUsd = max(0.00, (float)($_POST['counted_cash_usd'] ?? 0.00));
            $countedKhr = max(0, (int)($_POST['counted_cash_khr'] ?? 0));
            $outgoingPin = (string)($_POST['outgoing_pin'] ?? '');
            $incomingUsername = trim($_POST['incoming_username'] ?? '');
            $incomingPin = (string)($_POST['incoming_pin'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $res = CashierControl::endShift($shiftId, $countedUsd, $countedKhr, $outgoingPin, $incomingUsername, $incomingPin, $notes);
            $printBtn = " <a href='slip.php?type=end&id={$shiftId}' target='_blank' class='btn btn-xs btn-primary ml-2' style='display:inline-flex;align-items:center;margin-left:8px;'><i class='fa-solid fa-print mr-1'></i> Print Slip</a>";

            if (!empty($res['has_shortage'])) {
                if ($isManagerOrOwner) {
                    $message = "Shift handover complete. Warning: Cash shortage of $" . number_format($res['shortage_owed_usd'], 2) . " recorded." . $printBtn;
                    $messageType = 'warning';
                } else {
                    $message = "Shift handover successfully signed off by both cashiers." . $printBtn;
                    $messageType = 'success';
                }
            } else {
                $message = "Shift handover successfully completed and signed off." . $printBtn;
                $messageType = 'success';
            }
        }

        // 2B. Solo End Shift (Single cashier / day end - requires only cashier's own PIN)
        elseif ($action === 'end_shift_solo') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $countedUsd = max(0.00, (float)($_POST['counted_cash_usd'] ?? 0.00));
            $countedKhr = max(0, (int)($_POST['counted_cash_khr'] ?? 0));
            $cashierPin = (string)($_POST['cashier_pin'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $res = CashierControl::endShiftSolo($shiftId, $countedUsd, $countedKhr, $cashierPin, $notes);
            $printBtn = " <a href='slip.php?type=end&id={$shiftId}' target='_blank' class='btn btn-xs btn-primary ml-2' style='display:inline-flex;align-items:center;margin-left:8px;'><i class='fa-solid fa-print mr-1'></i> Print Slip</a>";

            if (!empty($res['has_shortage'])) {
                $message = "Solo shift ended. Warning: Cash shortage of $" . number_format($res['shortage_owed_usd'], 2) . " recorded." . $printBtn;
                $messageType = 'warning';
            } else {
                $message = "Solo shift ended successfully. Cash drawer closed." . $printBtn;
                $messageType = 'success';
            }
        }

        // 2C. Manager Review & Close Overnight Shift (Requires written reason + manager PIN)
        elseif ($action === 'manager_review_shift') {
            $shiftId = (int)($_POST['shift_id'] ?? 0);
            $countedUsd = max(0.00, (float)($_POST['counted_cash_usd'] ?? 0.00));
            $countedKhr = max(0, (int)($_POST['counted_cash_khr'] ?? 0));
            $mgrUsername = trim($_POST['manager_username'] ?? '');
            $mgrPin = (string)($_POST['manager_pin'] ?? '');
            $reason = trim($_POST['review_reason'] ?? '');

            $res = CashierControl::managerCloseReviewShift($shiftId, $countedUsd, $countedKhr, $mgrUsername, $mgrPin, $reason);
            $printBtn = " <a href='slip.php?type=end&id={$shiftId}' target='_blank' class='btn btn-xs btn-primary ml-2' style='display:inline-flex;align-items:center;margin-left:8px;'><i class='fa-solid fa-print mr-1'></i> Print Slip</a>";
            $message = "Overnight shift #{$shiftId} reviewed and closed by manager. Written reason recorded." . $printBtn;
            $messageType = 'success';
        }

        // 3. Trigger Spot Audit (Manager PIN required, cashier counts drawer blindly)
        elseif ($action === 'spot_audit') {
            $cashierId = (int)($_POST['cashier_id'] ?? 0);
            $countedUsd = max(0.00, (float)($_POST['counted_cash_usd'] ?? 0.00));
            $countedKhr = max(0, (int)($_POST['counted_cash_khr'] ?? 0));
            $mgrUsername = trim($_POST['manager_username'] ?? '');
            $mgrPin = (string)($_POST['manager_pin'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $res = CashierControl::conductSpotAudit($cashierId, $countedUsd, $countedKhr, $mgrUsername, $mgrPin, $notes);
            $diffText = ($res['difference_usd'] < 0) ? "Shortage of $" . number_format($res['shortage_owed_usd'], 2) . " owed" : "Over/Exact ($" . number_format($res['difference_usd'], 2) . ")";
            $message = "Spot audit recorded for {$res['cashier_name']}. Result: {$diffText}.";
            $messageType = ($res['shortage_owed_usd'] > 0) ? 'warning' : 'success';
        }

        // 4. Open Drawer Without Sale (Manager PIN required)
        elseif ($action === 'open_drawer_no_sale') {
            $reason = trim($_POST['reason'] ?? '');
            $mgrUsername = trim($_POST['manager_username'] ?? '');
            $mgrPin = (string)($_POST['manager_pin'] ?? '');

            $res = CashierControl::openDrawerNoSale($reason, $mgrUsername, $mgrPin, $currentUserId);
            $message = "Cash drawer opened without sale. Event logged: {$res['event_uid']}.";
            $messageType = 'success';
        }

        // 5. Close Day & Generate Final Z-Report (Manager PIN required)
        elseif ($action === 'close_day') {
            $countedUsd = max(0.00, (float)($_POST['counted_cash_usd'] ?? 0.00));
            $countedKhr = max(0, (int)($_POST['counted_cash_khr'] ?? 0));
            $mgrUsername = trim($_POST['manager_username'] ?? '');
            $mgrPin = (string)($_POST['manager_pin'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $res = CashierControl::closeDayZReport($mgrUsername, $mgrPin, $countedUsd, $countedKhr, $notes);
            $closeRef = $res['close_id'] ?? $res['z_report_number'];
            $printBtn = " <a href='slip.php?type=close_day&id={$closeRef}' target='_blank' class='btn btn-xs btn-primary ml-2' style='display:inline-flex;align-items:center;margin-left:8px;'><i class='fa-solid fa-print mr-1'></i> Print Z-Report Slip</a>";
            $message = "Day closed successfully! Final Z-Report: {$res['z_report_number']}. Total Sales: $" . number_format($res['total_sales_usd'], 2) . $printBtn;
            $messageType = 'success';
        }

        // 6. Reopen Closed Day (Manager PIN + Reason required)
        elseif ($action === 'reopen_day') {
            $targetDate = trim($_POST['close_date'] ?? date('Y-m-d'));
            $reason = trim($_POST['reopen_reason'] ?? '');
            $mgrUsername = trim($_POST['manager_username'] ?? '');
            $mgrPin = (string)($_POST['manager_pin'] ?? '');

            $res = CashierControl::reopenClosedDay($targetDate, $reason, $mgrUsername, $mgrPin);
            $message = $res['message'] ?? "Day {$targetDate} reopened.";
            $messageType = 'success';
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Fetch active shift for current cashier
$activeShift = CashierControl::getActiveShift($currentUserId);
$expectedShiftCash = null;
if ($activeShift && $isManagerOrOwner) {
    // Only Managers and Owners can see expected cash
    $expectedShiftCash = CashierControl::calculateShiftExpectedCash((int)$activeShift['id']);
}

// Fetch shifts needing manager review (left open overnight)
$shiftsNeedingReview = CashierControl::getAllShiftsNeedingReview();
$oldShiftNeedingReview = CashierControl::getOldShiftNeedingReview($currentUserId);

// Check day lock status
$dayLock = CashierControl::isDayLocked();

// Fetch shift history:
// Cashiers only see their own shifts (WITHOUT expected cash or shortage if blind cashiering)
// Managers and Owners see all shifts and all shortages
if ($isManagerOrOwner) {
    $shiftsStmt = $pdo->query("SELECT * FROM cashier_shifts ORDER BY id DESC LIMIT 50");
    $allShifts = $shiftsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch shortages owed by cashiers
    $shortageStmt = $pdo->query("
        SELECT id, shift_uid, cashier_name, ended_at, difference_usd, shortage_owed_usd, notes
        FROM cashier_shifts
        WHERE shortage_owed_usd > 0
        ORDER BY id DESC LIMIT 50
    ");
    $shortagesOwed = $shortageStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch spot audits
    $auditsStmt = $pdo->query("SELECT * FROM spot_audits ORDER BY id DESC LIMIT 50");
    $allSpotAudits = $auditsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch daily closes (Z-reports)
    $closesStmt = $pdo->query("SELECT * FROM pos_daily_closes ORDER BY close_date DESC, id DESC LIMIT 30");
    $dailyCloses = $closesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Active cashiers list for spot audit
    $cashiersList = $pdo->query("SELECT id, username, full_name FROM users WHERE (role = 'cashier' OR role_id IN (SELECT id FROM roles WHERE code = 'cashier')) AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $shiftsStmt = $pdo->prepare("SELECT id, shift_uid, started_at, ended_at, opening_cash_usd, opening_cash_khr, status, notes FROM cashier_shifts WHERE cashier_id = ? ORDER BY id DESC LIMIT 30");
    $shiftsStmt->execute([$currentUserId]);
    $allShifts = $shiftsStmt->fetchAll(PDO::FETCH_ASSOC);
    $shortagesOwed = [];
    $allSpotAudits = [];
    $dailyCloses = [];
    if ($canCloseDay) {
        $closesStmt = $pdo->query("SELECT * FROM pos_daily_closes ORDER BY close_date DESC, id DESC LIMIT 30");
        $dailyCloses = $closesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $cashiersList = [];
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <i class="fa-solid fa-hand-holding-dollar mr-1"></i> Cash &amp; Shift Operations
        </span>
        <span style="font-size: 13px; color: var(--text-muted);">
            Blind Handovers, Drawer Audits &amp; Daily Z-Reports
        </span>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <a href="<?= get_pos_url() ?>" class="btn btn-secondary">
            <i class="fa-solid fa-cash-register mr-1"></i> POS Register
        </a>
        <button type="button" class="btn btn-secondary" onclick="openModal('drawerNoSaleModal')">
            <i class="fa-solid fa-unlock-keyhole mr-1" style="color: #ea580c;"></i> Open Drawer (No Sale)
        </button>
        <?php if ($canCloseDay): ?>
            <button type="button" class="btn btn-primary" onclick="openModal('closeDayModal')">
                <i class="fa-solid fa-file-invoice-dollar mr-1"></i> Close Day &amp; Z-Report
            </button>
        <?php endif; ?>
        <?php if ($isManagerOrOwner): ?>
            <button type="button" class="btn btn-secondary" onclick="openModal('spotAuditModal')">
                <i class="fa-solid fa-magnifying-glass-chart mr-1" style="color: #2563eb;"></i> Trigger Spot Audit
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : (($messageType === 'warning') ? '#fef3c7' : 'var(--success-bg)') ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : (($messageType === 'warning') ? '#92400e' : 'var(--success)') ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px; border: 1px solid <?= ($messageType === 'danger') ? '#fecaca' : (($messageType === 'warning') ? '#fde68a' : '#bbf7d0') ?>;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : (($messageType === 'warning') ? 'fa-triangle-exclamation' : 'fa-circle-check') ?>"></i>
        <span style="font-weight: 600;"><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Day Lock Warning Banner -->
<?php if (!empty($dayLock['locked'])): ?>
    <div style="background: #fef2f2; border: 2px solid #ef4444; border-radius: var(--radius-md); padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 14px;">
        <i class="fa-solid fa-lock" style="color: #dc2626; font-size: 24px; margin-top: 2px;"></i>
        <div style="flex: 1;">
            <div style="font-weight: 800; color: #991b1b; font-size: 16px;">SALES ARE CURRENTLY LOCKED</div>
            <div style="color: #7f1d1d; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($dayLock['message']) ?></div>
            <?php if ($isManagerOrOwner && ($dayLock['reason'] ?? '') === 'day_closed'): ?>
                <div style="margin-top: 12px;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="openModal('reopenDayModal')">
                        <i class="fa-solid fa-lock-open mr-1"></i> Reopen Closed Day with Manager PIN
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Old Shift Needing Manager Review Banner -->
<?php if ($oldShiftNeedingReview): ?>
    <div style="background: #fff7ed; border: 2px solid #ea580c; border-radius: var(--radius-md); padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 14px;">
        <i class="fa-solid fa-triangle-exclamation" style="color: #ea580c; font-size: 24px; margin-top: 2px;"></i>
        <div style="flex: 1;">
            <div style="font-weight: 800; color: #9a3412; font-size: 15px;">OVERNIGHT SHIFT REQUIRES MANAGER REVIEW</div>
            <div style="color: #7c2d12; font-size: 13.5px; margin-top: 4px;">
                Your shift (<strong><?= htmlspecialchars($oldShiftNeedingReview['shift_uid']) ?></strong> started on <?= htmlspecialchars($oldShiftNeedingReview['started_at']) ?>) was left open overnight from a previous day.
                System policy requires a manager to count the drawer and sign off with a written reason before you can start a new shift for today.
            </div>
            <?php if ($isManagerOrOwner): ?>
                <div style="margin-top: 10px;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="openReviewModal(<?= (int)$oldShiftNeedingReview['id'] ?>, '<?= htmlspecialchars($oldShiftNeedingReview['shift_uid']) ?>', '<?= htmlspecialchars($oldShiftNeedingReview['cashier_name']) ?>', '<?= htmlspecialchars($oldShiftNeedingReview['started_at']) ?>', <?= (float)$oldShiftNeedingReview['opening_cash_usd'] ?>)">
                        <i class="fa-solid fa-user-shield mr-1"></i> Review &amp; Close This Shift
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Manager Review List of Overnight Shifts -->
<?php if ($isManagerOrOwner && !empty($shiftsNeedingReview)): ?>
    <div class="card" style="border: 2px solid #f97316; margin-bottom: 24px;">
        <div class="card-header" style="background: #fff7ed;">
            <h2 class="card-title" style="color: #c2410c;">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Shifts Left Open from Previous Days (Needs Manager Review)
            </h2>
            <span class="badge badge-warning"><?= count($shiftsNeedingReview) ?> Pending Review</span>
        </div>
        <div class="table-responsive">
            <table class="table" style="font-size: 13px;">
                <thead>
                    <tr>
                        <th>Shift UID</th>
                        <th>Cashier</th>
                        <th>Terminal</th>
                        <th>Started At</th>
                        <th>Opening Float</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shiftsNeedingReview as $nr): ?>
                        <tr>
                            <td style="font-family: var(--font-mono); font-weight: 700;"><?= htmlspecialchars($nr['shift_uid']) ?></td>
                            <td><strong><?= htmlspecialchars($nr['cashier_name']) ?></strong></td>
                            <td><?= htmlspecialchars($nr['pos_device_name'] ?? 'Counter') ?></td>
                            <td class="text-xs"><?= htmlspecialchars($nr['started_at']) ?></td>
                            <td><?= formatUSD((float)$nr['opening_cash_usd']) ?></td>
                            <td><span class="badge badge-danger">Needs Review</span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="openReviewModal(<?= (int)$nr['id'] ?>, '<?= htmlspecialchars($nr['shift_uid']) ?>', '<?= htmlspecialchars($nr['cashier_name']) ?>', '<?= htmlspecialchars($nr['started_at']) ?>', <?= (float)$nr['opening_cash_usd'] ?>)">
                                    <i class="fa-solid fa-user-shield mr-1"></i> Review &amp; Close
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; align-items: start;">
    
    <!-- Active Shift Card -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fa-solid fa-clock mr-1" style="color: var(--primary);"></i> Current Cashier Shift
            </h2>
            <?php if ($activeShift): ?>
                <span class="badge badge-success"><i class="fa-solid fa-circle-dot mr-1"></i> ACTIVE SHIFT</span>
            <?php else: ?>
                <span class="badge badge-warning">NO ACTIVE SHIFT</span>
            <?php endif; ?>
        </div>

        <?php if ($activeShift): ?>
            <div style="padding: 16px; background: var(--bg-subtle); border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span class="text-muted">Shift UID:</span>
                    <strong style="font-family: var(--font-mono);"><?= htmlspecialchars($activeShift['shift_uid']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span class="text-muted">Cashier:</span>
                    <strong><?= htmlspecialchars($activeShift['cashier_name']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span class="text-muted">Started At:</span>
                    <span><?= htmlspecialchars($activeShift['started_at']) ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span class="text-muted">Starting Cash (Float):</span>
                    <strong style="color: var(--primary);"><?= formatUSD((float)$activeShift['opening_cash_usd']) ?> / <?= number_format((int)($activeShift['opening_cash_khr'] ?? 0)) ?> ៛</strong>
                </div>
                <div style="margin-bottom: 12px; text-align: right;">
                    <a href="slip.php?type=open&id=<?= (int)$activeShift['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="font-weight: 600;">
                        <i class="fa-solid fa-print mr-1"></i> Print / Reprint Open Slip
                    </a>
                </div>

                <!-- Blind Cashiering Enforcement: Manager/Owner sees expected totals, Cashier NEVER sees it -->
                <?php if ($isManagerOrOwner && $expectedShiftCash): ?>
                    <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed var(--border);">
                        <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700; margin-bottom: 8px; color: #2563eb;">
                            <i class="fa-solid fa-eye mr-1"></i> Manager Live Cash Monitor:
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px;">
                            <span>Cash Sales in Shift:</span>
                            <strong><?= formatUSD((float)$expectedShiftCash['cash_sales_usd']) ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px;">
                            <span>Cash Expenses in Shift:</span>
                            <span style="color: var(--danger);">-<?= formatUSD((float)$expectedShiftCash['expenses_usd']) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 15px; font-weight: 800; color: #16a34a; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border);">
                            <span>Expected Cash in Drawer:</span>
                            <span><?= formatUSD((float)$expectedShiftCash['expected_cash_usd']) ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 14px; padding: 10px 14px; background: #f0fdf4; border-radius: var(--radius-sm); border: 1px solid #bbf7d0; font-size: 13px; color: #166534;">
                        <i class="fa-solid fa-shield-halved mr-1"></i> <strong>Blind Cashiering Active:</strong> Drawer expected cash is calculated and verified securely on the server.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Handover / Solo Close Shift Forms -->
            <div style="border-top: 1px solid var(--border); padding-top: 16px;">
                <div style="display: flex; gap: 8px; margin-bottom: 16px;">
                    <button type="button" class="btn btn-sm btn-primary" id="btnTabSolo" onclick="switchShiftCloseTab('solo')" style="font-weight: 700;">
                        <i class="fa-solid fa-user mr-1"></i> Solo End Shift
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" id="btnTabHandover" onclick="switchShiftCloseTab('handover')" style="font-weight: 600;">
                        <i class="fa-solid fa-handshake mr-1"></i> Handover to Next Cashier
                    </button>
                </div>

                <!-- Form 1: Solo End Shift (Cashier's PIN only) -->
                <div id="shiftCloseSoloForm">
                    <form action="" method="POST" onsubmit="return confirm('Confirm closing your shift alone? Your counted cash and personal PIN will be recorded.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="end_shift_solo">
                        <input type="hidden" name="shift_id" value="<?= (int)$activeShift['id'] ?>">

                        <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 12px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Counted USD ($) *</label>
                                <input type="number" step="0.01" min="0" name="counted_cash_usd" class="form-input" placeholder="0.00" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Counted KHR (៛)</label>
                                <input type="number" step="100" min="0" name="counted_cash_khr" class="form-input" value="0">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label">Cashier Security PIN (<?= htmlspecialchars($activeShift['cashier_name']) ?>) *</label>
                            <input type="password" maxlength="6" name="cashier_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 16px;">
                            <label class="form-label">Closing Notes</label>
                            <input type="text" name="notes" class="form-input" placeholder="Optional notes (e.g. Solo closing for the day)">
                        </div>

                        <button type="submit" class="btn btn-danger" style="width: 100%; font-weight: 700;">
                            <i class="fa-solid fa-lock mr-1"></i> End Shift (Solo - Cashier PIN Only)
                        </button>
                    </form>
                </div>

                <!-- Form 2: Handover to Next Cashier (Both PINs) -->
                <div id="shiftCloseHandoverForm" style="display: none;">
                    <form action="" method="POST" onsubmit="return confirm('Confirm shift handover? Both outgoing and incoming cashiers must sign off with their PIN.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="end_shift">
                        <input type="hidden" name="shift_id" value="<?= (int)$activeShift['id'] ?>">

                        <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 12px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Blind Counted USD ($) *</label>
                                <input type="number" step="0.01" min="0" name="counted_cash_usd" class="form-input" placeholder="0.00" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Blind Counted KHR (៛)</label>
                                <input type="number" step="100" min="0" name="counted_cash_khr" class="form-input" value="0">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label">Outgoing Cashier PIN (<?= htmlspecialchars($activeShift['cashier_name']) ?>) *</label>
                            <input type="password" maxlength="6" name="outgoing_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                        </div>

                        <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 14px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Incoming Cashier Username *</label>
                                <input type="text" name="incoming_username" class="form-input" placeholder="e.g. cashier2" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Incoming Cashier PIN *</label>
                                <input type="password" maxlength="6" name="incoming_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 16px;">
                            <label class="form-label">Handover Notes</label>
                            <input type="text" name="notes" class="form-input" placeholder="Optional notes for manager">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; font-weight: 700;">
                            <i class="fa-solid fa-signature mr-1"></i> Sign Off Shift Handover (Both PINs)
                        </button>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Start Shift Form -->
            <form action="" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start_shift">

                <div style="padding: 16px; background: var(--bg-subtle); border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 16px;">
                    <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 14px;">
                        Before processing customer orders and receiving cash, enter the change fund (float) placed inside the cash drawer.
                    </p>

                    <div class="grid grid-cols-2" style="gap: 14px; margin-bottom: 14px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Starting Cash USD ($) *</label>
                            <input type="number" step="0.25" min="0" name="opening_cash_usd" class="form-input" value="100.00" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Starting Cash KHR (៛)</label>
                            <input type="number" step="1000" min="0" name="opening_cash_khr" class="form-input" value="0">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-input" placeholder="e.g. Morning opening float from safe">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; background: #16a34a; border-color: #16a34a;">
                    <i class="fa-solid fa-cash-register mr-1"></i> Start Shift with Starting Cash
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Right Side: Manager Debt & Shortage Tracker (or Cashier instructions) -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fa-solid fa-scale-unbalanced mr-1" style="color: #ea580c;"></i>
                <?= $isManagerOrOwner ? 'Cashier Shortage & Debt Tracker' : 'Shift Information & Security' ?>
            </h2>
            <?php if ($isManagerOrOwner): ?>
                <span class="badge badge-purple"><?= count($shortagesOwed) ?> Recorded</span>
            <?php endif; ?>
        </div>

        <?php if ($isManagerOrOwner): ?>
            <?php if (empty($shortagesOwed)): ?>
                <div style="padding: 24px; text-align: center; color: var(--text-muted); background: var(--bg-subtle); border-radius: var(--radius-md);">
                    <i class="fa-solid fa-circle-check" style="font-size: 32px; color: #16a34a; margin-bottom: 8px;"></i>
                    <p style="font-weight: 600; margin: 0;">No cash shortages recorded.</p>
                    <span class="text-xs">All drawer balances have matched or balanced out.</span>
                </div>
            <?php else: ?>
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th>Shift UID</th>
                                <th>Cashier</th>
                                <th>Ended At</th>
                                <th style="text-align: right;">Shortage Owed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shortagesOwed as $sh): ?>
                                <tr>
                                    <td style="font-family: var(--font-mono); font-weight: 700;"><?= htmlspecialchars($sh['shift_uid']) ?></td>
                                    <td><strong style="color: var(--text-main);"><?= htmlspecialchars($sh['cashier_name']) ?></strong></td>
                                    <td class="text-xs text-muted"><?= htmlspecialchars($sh['ended_at']) ?></td>
                                    <td style="text-align: right;">
                                        <span class="badge badge-danger" style="font-size: 13px; font-weight: 800;">
                                            $<?= number_format((float)$sh['shortage_owed_usd'], 2) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="padding: 16px; background: var(--bg-subtle); border-radius: var(--radius-md); font-size: 13.5px; line-height: 1.6; color: var(--text-muted);">
                <div style="display: flex; gap: 10px; margin-bottom: 12px;">
                    <i class="fa-solid fa-shield-cat" style="color: var(--primary); font-size: 20px; margin-top: 2px;"></i>
                    <div>
                        <strong style="color: var(--text-main);">Blind Drawer Count Rule:</strong>
                        <div>Cashiers count physical bills and coins blindly without checking calculated system totals.</div>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 12px;">
                    <i class="fa-solid fa-user-lock" style="color: #ea580c; font-size: 20px; margin-top: 2px;"></i>
                    <div>
                        <strong style="color: var(--text-main);">Dual PIN Sign-off:</strong>
                        <div>Both the outgoing and incoming cashiers must verify and enter their 6-digit PINs during shift handover.</div>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <i class="fa-solid fa-file-signature" style="color: #dc2626; font-size: 20px; margin-top: 2px;"></i>
                    <div>
                        <strong style="color: var(--text-main);">Offline Refund Policy:</strong>
                        <div>Offline refunds are prohibited. In case of internet outage, record refunds in the paper logbook signed by the Manager.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Shift History Table -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-list-check mr-1" style="color: var(--primary);"></i>
            <?= $isManagerOrOwner ? 'All Cashier Shifts History' : 'My Shift History' ?>
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Shift UID</th>
                    <?php if ($isManagerOrOwner): ?>
                        <th>Cashier</th>
                    <?php endif; ?>
                    <th>Started</th>
                    <th>Ended</th>
                    <th>Opening Cash</th>
                    <?php if ($isManagerOrOwner): ?>
                        <th>Expected</th>
                        <th>Counted</th>
                        <th>Difference</th>
                        <th>Shortage Owed</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allShifts)): ?>
                    <tr>
                        <td colspan="<?= $isManagerOrOwner ? '11' : '6' ?>" style="text-align: center; color: var(--text-muted); padding: 24px;">
                            No shift records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($allShifts as $s): ?>
                        <tr>
                            <td style="font-family: var(--font-mono); font-weight: 700;"><?= htmlspecialchars($s['shift_uid']) ?></td>
                            <?php if ($isManagerOrOwner): ?>
                                <td><?= htmlspecialchars($s['cashier_name'] ?? '-') ?></td>
                            <?php endif; ?>
                            <td class="text-xs"><?= htmlspecialchars($s['started_at']) ?></td>
                            <td class="text-xs"><?= htmlspecialchars($s['ended_at'] ?? 'Active') ?></td>
                            <td><?= formatUSD((float)$s['opening_cash_usd']) ?></td>
                            <?php if ($isManagerOrOwner): ?>
                                <td><?= formatUSD((float)($s['expected_cash_usd'] ?? 0)) ?></td>
                                <td><?= formatUSD((float)($s['counted_cash_usd'] ?? 0)) ?></td>
                                <td>
                                    <?php $d = (float)($s['difference_usd'] ?? 0); ?>
                                    <span style="font-weight: 700; color: <?= ($d < 0) ? 'var(--danger)' : (($d > 0) ? 'var(--success)' : 'inherit') ?>;">
                                        <?= ($d > 0 ? '+' : '') . formatUSD($d) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $so = (float)($s['shortage_owed_usd'] ?? 0); ?>
                                    <?php if ($so > 0): ?>
                                        <span class="badge badge-danger">$<?= number_format($so, 2) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($s['status'] === 'open'): ?>
                                    <span class="badge badge-success">Open</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Ended</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['status'] === 'closed'): ?>
                                    <a href="slip.php?type=end&id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-secondary btn-xs" style="font-weight: 600;">
                                        <i class="fa-solid fa-print mr-1"></i> End Slip
                                    </a>
                                <?php else: ?>
                                    <a href="slip.php?type=open&id=<?= (int)$s['id'] ?>" target="_blank" class="btn btn-secondary btn-xs" style="font-weight: 600;">
                                        <i class="fa-solid fa-print mr-1"></i> Open Slip
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canCloseDay): ?>
    <!-- Daily Closes / Z-Report History Table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fa-solid fa-file-invoice-dollar mr-1" style="color: #16a34a;"></i> Daily Closes &amp; Z-Reports
            </h2>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Close Date</th>
                        <th>Z-Report #</th>
                        <th>Closed By</th>
                        <th>Closed At</th>
                        <th>Total Sales</th>
                        <th>Cash Sales</th>
                        <th>Expected Cash</th>
                        <th>Counted Cash</th>
                        <th>Short / Over</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailyCloses)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; color: var(--text-muted); padding: 24px;">No daily close records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailyCloses as $dc): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($dc['close_date']) ?></strong></td>
                                <td style="font-family: var(--font-mono); font-weight: 700;"><?= htmlspecialchars((string)($dc['z_report_number'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($dc['closed_by_name'] ?? $dc['manager_name'] ?? '-')) ?> (<?= htmlspecialchars(ucfirst($dc['role'] ?? 'Cashier')) ?>)</td>
                                <td class="text-xs"><?= htmlspecialchars((string)($dc['closed_at'] ?? '-')) ?></td>
                                <td><strong><?= formatUSD((float)($dc['total_sales_usd'] ?? 0)) ?></strong></td>
                                <td><?= formatUSD((float)($dc['cash_sales_usd'] ?? 0)) ?></td>
                                <td><?= formatUSD((float)($dc['expected_cash_usd'] ?? 0)) ?></td>
                                <td><?= formatUSD((float)($dc['counted_cash_usd'] ?? 0)) ?></td>
                                <td>
                                    <?php 
                                        $dso = (float)($dc['shortage_owed_usd'] ?? 0);
                                        $diffTot = (float)($dc['total_difference_usd'] ?? 0);
                                        $isDiscrepancy = ($dso > 0 || $diffTot != 0 || (isset($dc['notes']) && strpos($dc['notes'], 'Needs Manager Review') !== false));
                                    ?>
                                    <?php if ($isDiscrepancy): ?>
                                        <span class="badge badge-danger" style="background: #fef2f2; color: #dc2626; border: 1px solid #f87171;" title="Needs Manager Review">
                                            <?= ($diffTot > 0 ? '+' : '-') ?>$<?= number_format(abs($diffTot ?: $dso), 2) ?> (Needs Review)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success" style="background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0;">Balanced</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($dc['status'] === 'closed'): ?>
                                        <span class="badge badge-danger"><i class="fa-solid fa-lock mr-1"></i> Closed</span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><i class="fa-solid fa-lock-open mr-1"></i> Open</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="slip.php?type=close_day&id=<?= (int)$dc['id'] ?>" target="_blank" class="btn btn-primary btn-sm" style="font-weight: 600;">
                                        <i class="fa-solid fa-print mr-1"></i> Print Z-Report
                                    </a>
                                    <?php if ($isManagerOrOwner && $dc['status'] === 'closed'): ?>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openReopenModal('<?= htmlspecialchars($dc['close_date']) ?>')">
                                            <i class="fa-solid fa-lock-open mr-1"></i> Reopen
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Modal: Open Drawer (No Sale) -->
<div class="modal-backdrop" id="drawerNoSaleModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #ea580c;"><i class="fa-solid fa-unlock-keyhole mr-1"></i> Open Drawer (No Sale)</h3>
            <button type="button" class="modal-close" onclick="closeModal('drawerNoSaleModal')">&times;</button>
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
                <button type="button" class="btn btn-secondary" onclick="closeModal('drawerNoSaleModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #ea580c; border-color: #ea580c;">Authorize &amp; Open Drawer</button>
            </div>
        </form>
    </div>
</div>

<?php if ($isManagerOrOwner): ?>
    <!-- Modal: Spot Audit -->
    <div class="modal-backdrop" id="spotAuditModal">
        <div class="modal" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #2563eb;"><i class="fa-solid fa-magnifying-glass-chart mr-1"></i> Unannounced Spot Audit</h3>
                <button type="button" class="modal-close" onclick="closeModal('spotAuditModal')">&times;</button>
            </div>
            <form action="" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="spot_audit">

                <div class="modal-body">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-md); padding: 12px; margin-bottom: 14px; font-size: 13px; color: #1e40af;">
                        The cashier counts the drawer blindly right now with no advance warning. The system compares it with live expected sales and logs any difference.
                    </div>

                    <div class="form-group">
                        <label class="form-label">Select Cashier to Audit *</label>
                        <select name="cashier_id" class="form-select" required>
                            <?php foreach ($cashiersList as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?> (@<?= htmlspecialchars($c['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Blind Counted USD ($) *</label>
                            <input type="number" step="0.01" min="0" name="counted_cash_usd" class="form-input" placeholder="0.00" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Blind Counted KHR (៛)</label>
                            <input type="number" step="100" min="0" name="counted_cash_khr" class="form-input" value="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Manager Username *</label>
                        <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars($currentUsername) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Manager 6-digit PIN *</label>
                        <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Audit Notes</label>
                        <input type="text" name="notes" class="form-input" placeholder="e.g. Midday spot check">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('spotAuditModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                    <button type="submit" class="btn btn-primary" style="background: #2563eb; border-color: #2563eb;">Record Spot Audit</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Close Day & Z-Report -->
    <div class="modal-backdrop" id="closeDayModal">
        <div class="modal" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #16a34a;"><i class="fa-solid fa-file-invoice-dollar mr-1"></i> Close Day &amp; Generate Z-Report</h3>
                <button type="button" class="modal-close" onclick="closeModal('closeDayModal')">&times;</button>
            </div>
            <form action="" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="close_day">

                <div class="modal-body">
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 12px; margin-bottom: 14px; font-size: 13px; color: #166534;">
                        Closing the day locks sales, computes whole-day totals, tallies physical cash against expected cash, and generates the final permanent Z-Report.
                    </div>

                    <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Final Counted Cash USD ($) *</label>
                            <input type="number" step="0.01" min="0" name="counted_cash_usd" class="form-input" placeholder="0.00" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Final Counted Cash KHR (៛)</label>
                            <input type="number" step="100" min="0" name="counted_cash_khr" class="form-input" value="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username (Cashier / Manager / Owner) *</label>
                        <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars($currentUsername) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">6-digit Authorization PIN *</label>
                        <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Closing Notes</label>
                        <input type="text" name="notes" class="form-input" placeholder="e.g. End of day register close">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('closeDayModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                    <button type="submit" class="btn btn-primary" style="background: #16a34a; border-color: #16a34a;">Confirm Close Day &amp; Z-Report</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Reopen Closed Day -->
    <div class="modal-backdrop" id="reopenDayModal">
        <div class="modal" style="max-width: 440px;">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #dc2626;"><i class="fa-solid fa-lock-open mr-1"></i> Reopen Closed Day</h3>
                <button type="button" class="modal-close" onclick="closeModal('reopenDayModal')">&times;</button>
            </div>
            <form action="" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reopen_day">

                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Target Date *</label>
                        <input type="date" name="close_date" id="reopen_close_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Reason for Reopening *</label>
                        <input type="text" name="reopen_reason" class="form-input" placeholder="e.g. Late customer checkout, error correction" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Manager / Owner Username *</label>
                        <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars($currentUsername) ?>" required>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Manager 6-digit PIN *</label>
                        <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('reopenDayModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                    <button type="submit" class="btn btn-danger">Reopen Day &amp; Resume Sales</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Manager Review & Close Overnight Shift -->
    <div class="modal-backdrop" id="managerReviewShiftModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #dc2626;"><i class="fa-solid fa-user-shield mr-1"></i> Manager Review &amp; Close Shift</h3>
                <button type="button" class="modal-close" onclick="closeModal('managerReviewShiftModal')">&times;</button>
            </div>
            <form action="" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="manager_review_shift">
                <input type="hidden" name="shift_id" id="review_modal_shift_id" value="">

                <div class="modal-body">
                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: 12px; margin-bottom: 14px; font-size: 13px; color: #991b1b;">
                        <strong>Overnight Shift Left Open:</strong> System policy prohibits auto-closing. The manager must physically count drawer cash and record a written reason before closing this shift.
                    </div>

                    <div style="background: var(--bg-subtle); padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 14px; font-size: 13px;">
                        <div>Shift: <strong id="review_modal_shift_uid">-</strong></div>
                        <div>Cashier: <strong id="review_modal_cashier">-</strong></div>
                        <div>Started: <span id="review_modal_started" class="text-muted">-</span></div>
                        <div>Opening Float: <strong id="review_modal_opening" style="color: var(--primary);">-</strong></div>
                    </div>

                    <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Manager Counted USD ($) *</label>
                            <input type="number" step="0.01" min="0" name="counted_cash_usd" class="form-input" placeholder="0.00" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Manager Counted KHR (៛)</label>
                            <input type="number" step="100" min="0" name="counted_cash_khr" class="form-input" value="0">
                        </div>
                    </div>

                    <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Manager Username *</label>
                            <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars($currentUsername) ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Manager PIN *</label>
                            <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Written Reason for Closing Shift *</label>
                        <input type="text" name="review_reason" class="form-input" placeholder="e.g. Left open after night closing, cash verified against drawer" required minlength="3">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('managerReviewShiftModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-check mr-1"></i> Review &amp; Close Shift</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
function switchShiftCloseTab(mode) {
    const soloTab = document.getElementById('shiftCloseSoloForm');
    const handoverTab = document.getElementById('shiftCloseHandoverForm');
    const btnSolo = document.getElementById('btnTabSolo');
    const btnHandover = document.getElementById('btnTabHandover');
    if (mode === 'solo') {
        if (soloTab) soloTab.style.display = 'block';
        if (handoverTab) handoverTab.style.display = 'none';
        if (btnSolo) {
            btnSolo.className = 'btn btn-sm btn-primary';
            btnSolo.style.fontWeight = '700';
        }
        if (btnHandover) {
            btnHandover.className = 'btn btn-sm btn-secondary';
            btnHandover.style.fontWeight = '600';
        }
    } else {
        if (soloTab) soloTab.style.display = 'none';
        if (handoverTab) handoverTab.style.display = 'block';
        if (btnSolo) {
            btnSolo.className = 'btn btn-sm btn-secondary';
            btnSolo.style.fontWeight = '600';
        }
        if (btnHandover) {
            btnHandover.className = 'btn btn-sm btn-primary';
            btnHandover.style.fontWeight = '700';
        }
    }
}

function openReviewModal(shiftId, shiftUid, cashierName, startedAt, openingFloat) {
    const elId = document.getElementById('review_modal_shift_id');
    const elUid = document.getElementById('review_modal_shift_uid');
    const elCashier = document.getElementById('review_modal_cashier');
    const elStarted = document.getElementById('review_modal_started');
    const elOpening = document.getElementById('review_modal_opening');

    if (elId) elId.value = shiftId;
    if (elUid) elUid.textContent = shiftUid;
    if (elCashier) elCashier.textContent = cashierName;
    if (elStarted) elStarted.textContent = startedAt;
    if (elOpening) elOpening.textContent = '$' + parseFloat(openingFloat || 0).toFixed(2);

    openModal('managerReviewShiftModal');
}

function openReopenModal(dateStr) {
    let inp = document.getElementById('reopen_close_date');
    if (inp) {
        inp.value = dateStr;
    }
    openModal('reopenDayModal');
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
