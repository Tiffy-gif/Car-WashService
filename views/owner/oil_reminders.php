<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/AuditLogger.php';

Auth::requireAuth();

// Permission check
if (!Auth::can('customers.list') && !Auth::can('vehicle_history.list') && !in_array(Auth::getRole(), ['owner', 'manager', 'cashier', 'admin'], true)) {
    Auth::denyAccess();
}

$pdo = Database::getInstance();
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

$pageTitle = 'Oil Change Reminders & Follow-ups';

$msg = '';
$msgType = 'success';

// Handle Contact Log POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_contact_note') {
    csrf_verify();
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $quickNote = trim((string)($_POST['quick_note'] ?? ''));
    $customNote = trim((string)($_POST['custom_note'] ?? ''));
    $snoozeDays = (int)($_POST['snooze_days'] ?? 0);

    $finalNote = $customNote !== '' ? $customNote : ($quickNote !== '' ? $quickNote : 'Customer contacted by staff');
    $snoozeDate = $snoozeDays > 0 ? date('Y-m-d', strtotime("+{$snoozeDays} days")) : null;

    if ($vehicleId > 0) {
        $upd = $pdo->prepare("
            UPDATE vehicles
            SET last_contacted_at = ?,
                contact_notes = ?,
                snooze_until = ?
            WHERE id = ?
        ");
        $upd->execute([$now, $finalNote, $snoozeDate, $vehicleId]);

        AuditLogger::log(
            'OIL_REMINDER_CONTACTED',
            "Staff logged contact note for vehicle #{$vehicleId}: '{$finalNote}'" . ($snoozeDate ? " (Snoozed until {$snoozeDate})" : ""),
            'vehicle',
            (string)$vehicleId
        );

        $msg = "Contact note successfully logged!";
        $msgType = 'success';
    }
}

// Filters
$filter = trim((string)($_GET['filter'] ?? 'actionable'));
$search = trim((string)($_GET['q'] ?? ''));

// Build Base Query
$sql = "
    SELECT
        v.id as vehicle_id,
        v.customer_id,
        v.plate_number,
        v.plate_province,
        v.vehicle_type,
        v.make_model,
        v.color,
        COALESCE(c.name, 'Valued Customer') as customer_name,
        c.phone as customer_phone,
        COALESCE(c.message_opt_in, 1) as message_opt_in,
        v.last_mileage,
        v.next_oil_mileage,
        v.next_oil_date,
        v.last_contacted_at,
        v.contact_notes,
        v.snooze_until,
        DATEDIFF(v.next_oil_date, CURDATE()) as days_remaining,
        CASE
            WHEN (v.next_oil_date IS NOT NULL AND v.next_oil_date < CURDATE())
                 OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= v.next_oil_mileage) THEN 'overdue'
            WHEN (v.next_oil_date IS NOT NULL AND v.next_oil_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                 OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= (v.next_oil_mileage - 500)) THEN 'due_soon'
            ELSE 'ok'
        END as due_status
    FROM vehicles v
    LEFT JOIN customers c ON v.customer_id = c.id
    WHERE (v.next_oil_date IS NOT NULL OR v.next_oil_mileage IS NOT NULL)
";

$params = [];

if ($search !== '') {
    $sql .= " AND (v.plate_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR v.make_model LIKE ?)";
    $sTerm = '%' . $search . '%';
    $params[] = $sTerm;
    $params[] = $sTerm;
    $params[] = $sTerm;
    $params[] = $sTerm;
}

if ($filter === 'overdue') {
    $sql .= " AND (
        (v.next_oil_date IS NOT NULL AND v.next_oil_date < CURDATE())
        OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= v.next_oil_mileage)
    )";
} elseif ($filter === 'due_soon') {
    $sql .= " AND (
        (v.next_oil_date IS NOT NULL AND v.next_oil_date >= CURDATE() AND v.next_oil_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= (v.next_oil_mileage - 500) AND v.last_mileage < v.next_oil_mileage)
    )";
} elseif ($filter === 'contacted') {
    $sql .= " AND v.last_contacted_at IS NOT NULL";
} elseif ($filter === 'actionable') {
    // Both overdue and due soon, ignoring snoozed unless expired
    $sql .= " AND (
        (v.next_oil_date IS NOT NULL AND v.next_oil_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= (v.next_oil_mileage - 500))
    )";
    $sql .= " AND (v.snooze_until IS NULL OR v.snooze_until <= CURDATE())";
}

$sql .= "
    ORDER BY
        CASE
            WHEN (v.next_oil_date IS NOT NULL AND v.next_oil_date < CURDATE())
                 OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= v.next_oil_mileage) THEN 1
            ELSE 2
        END,
        v.next_oil_date ASC,
        v.next_oil_mileage ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Overall Counts for Stat Cards
$countStats = $pdo->query("
    SELECT
        COUNT(CASE WHEN (v.next_oil_date IS NOT NULL AND v.next_oil_date < CURDATE())
                        OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= v.next_oil_mileage) THEN 1 END) as overdue_count,
        COUNT(CASE WHEN (v.next_oil_date IS NOT NULL AND v.next_oil_date >= CURDATE() AND v.next_oil_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
                        OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= (v.next_oil_mileage - 500) AND v.last_mileage < v.next_oil_mileage) THEN 1 END) as due_soon_count,
        COUNT(CASE WHEN v.last_contacted_at IS NOT NULL AND DATE(v.last_contacted_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as contacted_7d_count,
        COUNT(*) as total_tracked
    FROM vehicles v
    WHERE v.next_oil_date IS NOT NULL OR v.next_oil_mileage IS NOT NULL
")->fetch(PDO::FETCH_ASSOC);

$overdueCount = (int)($countStats['overdue_count'] ?? 0);
$dueSoonCount = (int)($countStats['due_soon_count'] ?? 0);
$contactedCount = (int)($countStats['contacted_7d_count'] ?? 0);
$totalTracked = (int)($countStats['total_tracked'] ?? 0);

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="max-width: 1400px; margin: 0 auto; padding-bottom: 60px;">

    <!-- Flash message -->
    <?php if ($msg !== ''): ?>
        <div style="padding: 12px 18px; margin-bottom: 20px; border-radius: var(--radius-md); background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; font-size: 14px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-circle-check mr-2"></i><?= htmlspecialchars($msg) ?></div>
            <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: #065f46; font-size: 16px;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Header Section -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 10px;">
                <span style="display: inline-flex; width: 38px; height: 38px; border-radius: 10px; background: #ffedd5; color: #ea580c; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fa-solid fa-oil-can"></i>
                </span>
                Oil Change Reminders & Follow-ups
            </h1>
            <p style="font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;">
                Customer call and messaging list for scheduled engine oil service. Direct calling & Telegram contact — no paid SMS required.
            </p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="<?= APP_URL ?>/index.php?view=pos" class="btn btn-primary" style="font-size: 13px;">
                <i class="fa-solid fa-cash-register mr-1"></i> Open POS
            </a>
            <a href="<?= APP_URL ?>/index.php?view=settings&tab=oil" class="btn btn-secondary" style="font-size: 13px;">
                <i class="fa-solid fa-sliders mr-1"></i> Oil Intervals
            </a>
        </div>
    </div>

    <!-- Stat Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card" style="padding: 16px 20px; border-left: 4px solid #ef4444; margin: 0;">
            <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #ef4444; letter-spacing: 0.5px;">Overdue Service</div>
            <div style="font-size: 28px; font-weight: 800; color: #b91c1c; margin-top: 4px;"><?= $overdueCount ?></div>
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Target date or mileage passed</div>
        </div>
        <div class="card" style="padding: 16px 20px; border-left: 4px solid #f59e0b; margin: 0;">
            <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #d97706; letter-spacing: 0.5px;">Due Soon (&le;30 Days / 500 KM)</div>
            <div style="font-size: 28px; font-weight: 800; color: #b45309; margin-top: 4px;"><?= $dueSoonCount ?></div>
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Approaching target service</div>
        </div>
        <div class="card" style="padding: 16px 20px; border-left: 4px solid #3b82f6; margin: 0;">
            <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #2563eb; letter-spacing: 0.5px;">Contacted (Last 7 Days)</div>
            <div style="font-size: 28px; font-weight: 800; color: #1d4ed8; margin-top: 4px;"><?= $contactedCount ?></div>
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Staff follow-ups logged</div>
        </div>
        <div class="card" style="padding: 16px 20px; border-left: 4px solid #10b981; margin: 0;">
            <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #059669; letter-spacing: 0.5px;">Total Tracked Vehicles</div>
            <div style="font-size: 28px; font-weight: 800; color: #047857; margin-top: 4px;"><?= $totalTracked ?></div>
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">With scheduled oil service target</div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
        <form method="GET" action="<?= APP_URL ?>/index.php" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
            <input type="hidden" name="view" value="oil_reminders">

            <!-- Filter Buttons -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="<?= APP_URL ?>/index.php?view=oil_reminders&filter=actionable<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                   class="btn btn-sm <?= ($filter === 'actionable') ? 'btn-primary' : 'btn-secondary' ?>">
                    <i class="fa-solid fa-list-check mr-1"></i> Actionable (Due + Overdue)
                </a>
                <a href="<?= APP_URL ?>/index.php?view=oil_reminders&filter=overdue<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                   class="btn btn-sm <?= ($filter === 'overdue') ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $filter === 'overdue' ? 'background: #dc2626;' : '' ?>">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> Overdue (<?= $overdueCount ?>)
                </a>
                <a href="<?= APP_URL ?>/index.php?view=oil_reminders&filter=due_soon<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                   class="btn btn-sm <?= ($filter === 'due_soon') ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $filter === 'due_soon' ? 'background: #d97706;' : '' ?>">
                    <i class="fa-solid fa-clock mr-1"></i> Due Soon (<?= $dueSoonCount ?>)
                </a>
                <a href="<?= APP_URL ?>/index.php?view=oil_reminders&filter=contacted<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                   class="btn btn-sm <?= ($filter === 'contacted') ? 'btn-primary' : 'btn-secondary' ?>">
                    <i class="fa-solid fa-phone mr-1"></i> Contacted
                </a>
                <a href="<?= APP_URL ?>/index.php?view=oil_reminders&filter=all<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                   class="btn btn-sm <?= ($filter === 'all') ? 'btn-primary' : 'btn-secondary' ?>">
                    All (<?= $totalTracked ?>)
                </a>
            </div>

            <!-- Search Field -->
            <div style="display: flex; gap: 8px; align-items: center; min-width: 280px; flex: 1; max-width: 400px;">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search plate, customer, phone..." class="form-input" style="font-size: 13px;">
                <button type="submit" class="btn btn-secondary btn-sm" style="white-space: nowrap;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
                <?php if ($search !== ''): ?>
                    <a href="<?= APP_URL ?>/index.php?view=oil_reminders&filter=<?= urlencode($filter) ?>" class="btn btn-sm" style="color: var(--text-muted);">
                        Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Vehicles List Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="background: var(--bg-surface); border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding: 12px 16px;">Status</th>
                        <th style="padding: 12px 16px;">Vehicle</th>
                        <th style="padding: 12px 16px;">Customer & Contact</th>
                        <th style="padding: 12px 16px;">Oil Target</th>
                        <th style="padding: 12px 16px;">Remaining</th>
                        <th style="padding: 12px 16px;">Last Contact / Notes</th>
                        <th style="padding: 12px 16px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vehicles)): ?>
                        <tr>
                            <td colspan="7" style="padding: 48px 16px; text-align: center; color: var(--text-muted);">
                                <div style="font-size: 36px; color: #cbd5e1; margin-bottom: 12px;">
                                    <i class="fa-solid fa-oil-can"></i>
                                </div>
                                <div style="font-size: 15px; font-weight: 600; color: var(--text-main);">No vehicles match this filter</div>
                                <div style="font-size: 13px; margin-top: 4px;">
                                    <?= ($totalTracked === 0) ? 'When oil services are logged on POS or Technical bays, scheduled service targets will automatically appear here.' : 'Try changing your search term or switching to the "All" tab.' ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vehicles as $v):
                            $isOverdue = ($v['due_status'] === 'overdue');
                            $isDueSoon = ($v['due_status'] === 'due_soon');
                            $cleanPhone = preg_replace('/[^\d+]/', '', (string)$v['customer_phone']);
                            $intlPhone = preg_replace('/^0/', '855', $cleanPhone);
                            $daysRem = $v['days_remaining'] !== null ? (int)$v['days_remaining'] : null;

                            // Calculate KM remaining
                            $kmRem = null;
                            if (!empty($v['next_oil_mileage']) && !empty($v['last_mileage'])) {
                                $kmRem = (int)$v['next_oil_mileage'] - (int)$v['last_mileage'];
                            }
                        ?>
                            <tr style="border-bottom: 1px solid var(--border); transition: background 0.15s ease;" onmouseover="this.style.background='var(--bg-surface)'" onmouseout="this.style.background='transparent'">
                                <!-- Status Badge -->
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <?php if ($isOverdue): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 6px; background: #fee2e2; color: #b91c1c; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> Overdue
                                        </span>
                                    <?php elseif ($isDueSoon): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 6px; background: #fef3c7; color: #b45309; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                                            <i class="fa-solid fa-clock"></i> Due Soon
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 6px; background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                                            <i class="fa-solid fa-check"></i> On Track
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($v['snooze_until']) && $v['snooze_until'] > $today): ?>
                                        <div style="font-size: 10px; color: #64748b; margin-top: 4px;">
                                            <i class="fa-solid fa-bed"></i> Snoozed: <?= date('d M', strtotime($v['snooze_until'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Vehicle Info -->
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <div style="font-weight: 700; font-size: 14px; color: var(--text-main); font-family: var(--font-mono, monospace);">
                                        <?= htmlspecialchars($v['plate_number']) ?>
                                    </div>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                        <?= htmlspecialchars($v['make_model'] ?? 'Vehicle') ?>
                                        <?php if (!empty($v['color'])): ?>
                                            • <span style="text-transform: capitalize;"><?= htmlspecialchars($v['color']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Customer & Contact -->
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <div style="font-weight: 600; color: var(--text-main);">
                                        <?= htmlspecialchars($v['customer_name']) ?>
                                    </div>

                                    <?php if (!empty($v['customer_phone'])): ?>
                                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                            <a href="tel:<?= htmlspecialchars($cleanPhone) ?>" class="btn btn-sm" style="padding: 2px 8px; font-size: 12px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="Click to call directly">
                                                <i class="fa-solid fa-phone" style="font-size: 11px;"></i>
                                                <?= htmlspecialchars($v['customer_phone']) ?>
                                            </a>

                                            <!-- Telegram direct link if local number converts to Cambodia intl -->
                                            <a href="https://t.me/+<?= htmlspecialchars($intlPhone) ?>" target="_blank" rel="noopener" class="btn btn-sm" style="padding: 2px 7px; font-size: 11px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;" title="Chat via Telegram">
                                                <i class="fa-brands fa-telegram"></i> Telegram
                                            </a>

                                            <!-- Copy button -->
                                            <button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($cleanPhone) ?>'); alert('Phone number copied: <?= htmlspecialchars($cleanPhone) ?>');" style="background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 12px; padding: 2px 4px;" title="Copy phone number">
                                                <i class="fa-solid fa-copy"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size: 12px; color: var(--text-muted); font-style: italic; margin-top: 2px;">
                                            No phone recorded
                                        </div>
                                    <?php endif; ?>

                                    <?php if ((int)$v['message_opt_in'] === 0): ?>
                                        <div style="font-size: 10px; color: #dc2626; margin-top: 3px; font-weight: 600;">
                                            <i class="fa-solid fa-ban mr-1"></i> Customer opted out of promotional messages
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Oil Target -->
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <?php if (!empty($v['next_oil_mileage'])): ?>
                                        <div style="font-weight: 600; color: var(--text-main);">
                                            <?= number_format((int)$v['next_oil_mileage']) ?> <span style="font-size: 11px; color: var(--text-muted);">KM</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($v['next_oil_date'])): ?>
                                        <div style="font-size: 12px; color: var(--text-muted);">
                                            <i class="fa-solid fa-calendar-day mr-1"></i><?= date('d M Y', strtotime($v['next_oil_date'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (empty($v['next_oil_mileage']) && empty($v['next_oil_date'])): ?>
                                        <span style="color: var(--text-muted); font-style: italic;">Not set</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Remaining Indicator -->
                                <td style="padding: 14px 16px; vertical-align: middle;">
                                    <?php if ($daysRem !== null): ?>
                                        <?php if ($daysRem < 0): ?>
                                            <div style="font-weight: 700; color: #b91c1c;">
                                                <?= abs($daysRem) ?> day(s) overdue
                                            </div>
                                        <?php elseif ($daysRem === 0): ?>
                                            <div style="font-weight: 700; color: #b45309;">
                                                Due TODAY
                                            </div>
                                        <?php else: ?>
                                            <div style="font-weight: 600; color: <?= $daysRem <= 7 ? '#b45309' : 'var(--text-main)' ?>;">
                                                In <?= $daysRem ?> days
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($kmRem !== null): ?>
                                        <div style="font-size: 11px; margin-top: 2px; color: <?= $kmRem <= 0 ? '#b91c1c; font-weight: 700;' : 'var(--text-muted);' ?>;">
                                            <?php if ($kmRem <= 0): ?>
                                                <?= number_format(abs($kmRem)) ?> km over target
                                            <?php else: ?>
                                                <?= number_format($kmRem) ?> km remaining
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Last Contact / Notes -->
                                <td style="padding: 14px 16px; vertical-align: middle; max-width: 240px;">
                                    <?php if (!empty($v['last_contacted_at'])): ?>
                                        <div style="font-size: 11px; font-weight: 600; color: var(--text-main);">
                                            <i class="fa-solid fa-phone-volume mr-1" style="color: #059669;"></i>
                                            <?= date('d M Y H:i', strtotime($v['last_contacted_at'])) ?>
                                        </div>
                                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($v['contact_notes'] ?? '') ?>">
                                            <?= htmlspecialchars($v['contact_notes'] ?? 'Contacted') ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: var(--text-muted); font-style: italic;">Not contacted yet</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td style="padding: 14px 16px; vertical-align: middle; text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openContactModal(<?= (int)$v['vehicle_id'] ?>, '<?= htmlspecialchars(addslashes($v['plate_number'])) ?>', '<?= htmlspecialchars(addslashes($v['customer_name'])) ?>', '<?= htmlspecialchars(addslashes($v['customer_phone'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($v['contact_notes'] ?? '')) ?>')" style="font-size: 12px;">
                                        <i class="fa-solid fa-pen-to-square mr-1"></i> Log Contact
                                    </button>
                                    <a href="<?= APP_URL ?>/index.php?view=pos&plate=<?= urlencode($v['plate_number']) ?>" class="btn btn-sm btn-primary" style="font-size: 12px; margin-left: 6px;" title="Admit vehicle for service at POS">
                                        <i class="fa-solid fa-plus"></i> Service
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

<!-- Modal: Log Call / Note -->
<div id="contactModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--bg-surface, #fff); border-radius: var(--radius-lg, 12px); width: 100%; max-width: 500px; padding: 24px; box-shadow: var(--shadow-xl); position: relative; margin: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-phone-volume" style="color: #059669;"></i> Log Customer Follow-up
            </h3>
            <button type="button" onclick="closeContactModal()" style="background: none; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= APP_URL ?>/index.php?view=oil_reminders&filter=<?= urlencode($filter) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_contact_note">
            <input type="hidden" name="vehicle_id" id="modalVehicleId" value="">

            <div style="background: var(--bg-subtle, #f8fafc); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; font-size: 13px;">
                    <span>Vehicle Plate: <strong id="modalPlate" style="font-family: var(--font-mono, monospace);"></strong></span>
                    <span>Customer: <strong id="modalCustomer"></strong></span>
                </div>
                <div style="margin-top: 6px; font-size: 13px; color: var(--text-muted);">
                    Phone: <strong id="modalPhone" style="color: #047857;"></strong>
                </div>
            </div>

            <!-- Quick Note Selection -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-size: 13px; font-weight: 600;">Outcome / Quick Note</label>
                <select name="quick_note" id="modalQuickNote" class="form-input" style="font-size: 13px;" onchange="handleQuickNoteChange(this.value)">
                    <option value="Customer called - booked appointment">📞 Customer called - booked appointment</option>
                    <option value="Sent Telegram / message reminder">💬 Sent Telegram / message reminder</option>
                    <option value="No answer - will retry tomorrow">⏳ No answer - will retry tomorrow</option>
                    <option value="Customer will come next week">🗓️ Customer will come next week</option>
                    <option value="Customer already serviced vehicle elsewhere">✅ Customer already serviced vehicle elsewhere</option>
                    <option value="custom">✏️ Other (Enter custom note below)</option>
                </select>
            </div>

            <!-- Custom Note Input -->
            <div class="form-group" id="customNoteGroup" style="display: none; margin-bottom: 14px;">
                <label class="form-label" style="font-size: 13px; font-weight: 600;">Custom Note Details</label>
                <textarea name="custom_note" id="modalCustomNote" class="form-input" rows="2" placeholder="e.g. Requested semi-synthetic oil, coming on Saturday afternoon..." style="font-size: 13px;"></textarea>
            </div>

            <!-- Snooze Follow-up -->
            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label" style="font-size: 13px; font-weight: 600;">Snooze Reminder (Optional)</label>
                <select name="snooze_days" class="form-input" style="font-size: 13px;">
                    <option value="0">Do not snooze (Remains in actionable list)</option>
                    <option value="7">Snooze for 7 days</option>
                    <option value="14">Snooze for 14 days</option>
                    <option value="30">Snooze for 30 days (Serviced elsewhere / long trip)</option>
                </select>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                    Snoozed vehicles are hidden from the "Actionable" tab until the snooze duration expires.
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeContactModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #059669; border-color: #059669;">
                    <i class="fa-solid fa-check mr-1"></i> Save Follow-up
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openContactModal(vehicleId, plate, customer, phone, existingNote) {
    document.getElementById('modalVehicleId').value = vehicleId;
    document.getElementById('modalPlate').innerText = plate;
    document.getElementById('modalCustomer').innerText = customer;
    document.getElementById('modalPhone').innerText = phone || 'None';
    document.getElementById('modalCustomNote').value = existingNote || '';

    const modal = document.getElementById('contactModal');
    modal.style.display = 'flex';
}

function closeContactModal() {
    document.getElementById('contactModal').style.display = 'none';
}

function handleQuickNoteChange(val) {
    const customGroup = document.getElementById('customNoteGroup');
    if (val === 'custom') {
        customGroup.style.display = 'block';
        document.getElementById('modalCustomNote').focus();
    } else {
        customGroup.style.display = 'none';
    }
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeContactModal();
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
