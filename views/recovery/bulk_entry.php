<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/AuditLogger.php';

Auth::requirePermission('recovery.create');

$pageTitle = 'Disaster Recovery: Bulk Paper Entry';
$pdo = Database::getInstance();

$currentUser = Auth::user();
$userId = (int)($currentUser['id'] ?? 0);
$userName = Auth::getFullName() ?? Auth::getUsername() ?? 'Staff Member';

$message = null;
$messageType = 'success';
$exchangeRate = (int)get_setting('exchange_rate', '4100');

// Handle Bulk Form POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_bulk_recovery') {
    if (!verify_csrf()) {
        $message = 'Security token invalid or session expired. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $slips = $_POST['paper_slip_number'] ?? [];
        $plates = $_POST['plate_number'] ?? [];
        $vtypes = $_POST['vehicle_type'] ?? [];
        $services = $_POST['service_id'] ?? [];
        $amounts = $_POST['total_usd'] ?? [];
        $payMethods = $_POST['payment_method'] ?? [];
        $arrivedTimes = $_POST['arrived_at'] ?? [];
        $staffIds = $_POST['staff_id'] ?? [];
        $notes = $_POST['notes'] ?? [];

        $createdCount = 0;
        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < count($plates); $i++) {
            $plate = strtoupper(trim((string)($plates[$i] ?? '')));
            if ($plate === '') continue;

            $slipNum = trim((string)($slips[$i] ?? ''));
            $vtype = trim((string)($vtypes[$i] ?? 'small_car'));
            $serviceId = (int)($services[$i] ?? 0);
            $amountUsd = max(0.0, (float)($amounts[$i] ?? 0.0));
            $payMethod = in_array($payMethods[$i] ?? '', ['cash', 'aba_khqr', 'card'], true) ? $payMethods[$i] : 'cash';
            $arrivedAt = !empty($arrivedTimes[$i]) ? date('Y-m-d H:i:s', strtotime($arrivedTimes[$i])) : $now;
            $staffId = (int)($staffIds[$i] ?? 0);
            $noteText = trim((string)($notes[$i] ?? ''));

            // Generate clean sequential order number
            $orderNumber = 'ORD-REC-' . date('Ymd-His', strtotime($arrivedAt)) . '-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
            $orderUid = 'UID-REC-' . bin2hex(random_bytes(6));
            $totalKhr = (int)round($amountUsd * $exchangeRate);
            $finalNote = "entered after recovery" . ($slipNum !== '' ? " (Paper Slip #{$slipNum})" : "") . ($noteText !== '' ? " - {$noteText}" : "");

            // Insert into orders
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, order_uid, customer_name, plate_number, vehicle_type,
                    total_usd, total_khr, payment_status, payment_method, status,
                    cashier_id, created_at, arrived_at, finished_at, paid_at,
                    is_recovered, paper_slip_number, recovered_by_id, recovered_by_name,
                    recovered_at, recovery_notes, notes, is_active, is_synced
                ) VALUES (
                    ?, ?, 'Walk-in (Recovered)', ?, ?,
                    ?, ?, 'paid', ?, 'completed',
                    ?, ?, ?, ?, ?,
                    1, ?, ?, ?,
                    ?, ?, ?, 1, 0
                )
            ");
            $stmt->execute([
                $orderNumber, $orderUid, $plate, $vtype,
                $amountUsd, $totalKhr, $payMethod,
                $userId, $arrivedAt, $arrivedAt, $arrivedAt, $arrivedAt,
                $slipNum ?: null, $userId, $userName,
                $now, $finalNote, $finalNote
            ]);

            $orderId = (int)$pdo->lastInsertId();

            // Insert service order item
            if ($serviceId > 0) {
                $svc = $pdo->query("SELECT name_en, name_km FROM services WHERE id = {$serviceId}")->fetch(PDO::FETCH_ASSOC);
                $svcName = $svc['name_en'] ?? 'Wash Service';
                $pdo->prepare("
                    INSERT INTO order_items (order_id, service_id, item_type, service_name, qty, price_usd, price_khr, created_at)
                    VALUES (?, ?, 'service', ?, 1, ?, ?, ?)
                ")->execute([$orderId, $serviceId, $svcName, $amountUsd, $totalKhr, $arrivedAt]);
            }

            // Assign staff member if selected
            if ($staffId > 0) {
                $pdo->prepare("INSERT INTO order_washers (order_id, washer_id) VALUES (?, ?)")
                    ->execute([$orderId, $staffId]);
            }

            // Audit Trail Log
            AuditLogger::log(
                'RECOVERY_ENTRY_CREATED',
                "Paper record (Plate: {$plate}, Slip: {$slipNum}) entered after recovery by {$userName}",
                'orders',
                (string)$orderId
            );

            $createdCount++;
        }

        if ($createdCount > 0) {
            $message = "Successfully entered and recovered {$createdCount} paper record(s) into the system! All records are permanently marked 'entered after recovery'.";
            $messageType = 'success';
        } else {
            $message = "No records were entered. Please fill in at least one plate number.";
            $messageType = 'warning';
        }
    }
}

// Fetch all active services for pricing dropdown
$servicesList = $pdo->query("SELECT id, name_en, name_km, category FROM services WHERE is_active = 1 ORDER BY category ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$servicePricesRaw = $pdo->query("SELECT service_id, vehicle_type, price_usd FROM service_prices")->fetchAll(PDO::FETCH_ASSOC);
$priceMap = [];
foreach ($servicePricesRaw as $sp) {
    $priceMap[$sp['service_id']][$sp['vehicle_type']] = (float)$sp['price_usd'];
}

// Fetch staff for assignment dropdown
$allStaff = $pdo->query("SELECT id, full_name, role FROM users WHERE status = 'active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent recovered orders
$recoveredOrders = $pdo->query("
    SELECT o.id, o.order_number, o.paper_slip_number, o.plate_number, o.vehicle_type,
           o.total_usd, o.payment_method, o.arrived_at, o.recovered_by_name, o.recovered_at,
           o.recovery_notes
    FROM orders o
    WHERE o.is_recovered = 1
    ORDER BY o.recovered_at DESC, o.id DESC
    LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC);

require_once dirname(__DIR__, 2) . '/views/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 14px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0;">
            <i class="fa-solid fa-file-circle-check mr-2" style="color: #ea580c;"></i>Disaster Recovery: Bulk Paper Entry
        </h1>
        <p style="font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0;">
            Enter manual paper job cards and reception logbook records into the system following an outage.
        </p>
    </div>

    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <a href="index.php?view=paper_forms&form=job_card" class="btn btn-secondary btn-sm" target="_blank">
            <i class="fa-solid fa-print mr-1"></i> Print Blank Job Cards
        </a>
        <a href="index.php?view=paper_forms&form=logbook" class="btn btn-secondary btn-sm" target="_blank">
            <i class="fa-solid fa-table-list mr-1"></i> Print Paper Logbook
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 20px;">
        <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mr-2"></i>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div style="background: rgba(234, 88, 12, 0.08); border-left: 4px solid #ea580c; border-radius: 6px; padding: 12px 16px; margin-bottom: 24px; font-size: 13px; color: var(--text-main);">
    <strong><i class="fa-solid fa-shield-halved mr-1"></i> Recovery Compliance &amp; Audit Trail:</strong>
    <p style="margin: 4px 0 0 0; color: var(--text-muted);">
        Every transaction submitted from this screen is permanently marked <span class="badge badge-warning" style="font-size: 11px;">entered after recovery</span> and records your name (<strong><?= htmlspecialchars($userName) ?></strong>) and the current timestamp in the immutable audit trail. Original arrival and service times from the paper slip are preserved.
    </p>
</div>

<!-- Bulk Entry Form Card -->
<div class="card" style="margin-bottom: 30px;">
    <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
            <i class="fa-solid fa-keyboard mr-2" style="color: var(--primary);"></i>Type Paper Records Below
        </h2>
        <button type="button" class="btn btn-sm btn-secondary" onclick="addNewRow()">
            <i class="fa-solid fa-plus mr-1"></i> Add Another Row
        </button>
    </div>

    <form method="POST" action="index.php?view=recovery_bulk_entry" id="bulkRecoveryForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_bulk_recovery">

        <div style="overflow-x: auto;">
            <table class="table" id="recoveryTable" style="width: 100%; min-width: 950px; font-size: 13px;">
                <thead>
                    <tr style="background: var(--bg-surface-alt, #f8fafc);">
                        <th style="width: 40px; text-align: center;">#</th>
                        <th style="width: 110px;">Paper Slip #</th>
                        <th style="width: 130px;">Plate Number *</th>
                        <th style="width: 130px;">Vehicle Type</th>
                        <th style="width: 180px;">Service</th>
                        <th style="width: 110px;">Amount ($)</th>
                        <th style="width: 110px;">Pay Method</th>
                        <th style="width: 170px;">Original Time</th>
                        <th style="width: 150px;">Staff Assigned</th>
                        <th style="width: 40px;"></th>
                    </tr>
                </thead>
                <tbody id="recoveryRows">
                    <?php for ($r = 1; $r <= 5; $r++): ?>
                        <tr class="recovery-row" data-row-id="<?= $r ?>">
                            <td style="text-align: center; font-weight: 700; color: var(--text-muted);"><?= $r ?></td>
                            <td>
                                <input type="text" name="paper_slip_number[]" class="form-control form-control-sm" placeholder="SLIP-<?= str_pad((string)$r, 3, '0', STR_PAD_LEFT) ?>" style="font-family: monospace;">
                            </td>
                            <td>
                                <input type="text" name="plate_number[]" class="form-control form-control-sm plate-input" placeholder="e.g. 2AB-1234" style="text-transform: uppercase; font-weight: 700; font-family: monospace;">
                            </td>
                            <td>
                                <select name="vehicle_type[]" class="form-select form-select-sm vtype-select" onchange="autoFillPrice(this)">
                                    <option value="small_car">Small Car</option>
                                    <option value="suv_pickup">SUV / Pickup</option>
                                    <option value="van_truck">Van / Truck</option>
                                    <option value="motorbike">Motorbike</option>
                                </select>
                            </td>
                            <td>
                                <select name="service_id[]" class="form-select form-select-sm svc-select" onchange="autoFillPrice(this)">
                                    <option value="0">-- Select Service --</option>
                                    <?php foreach ($servicesList as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name_en']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <div style="position: relative;">
                                    <span style="position: absolute; left: 8px; top: 6px; color: var(--text-muted); font-size: 11px;">$</span>
                                    <input type="number" step="0.25" min="0" name="total_usd[]" class="form-control form-control-sm amount-input" style="padding-left: 20px;" placeholder="0.00">
                                </div>
                            </td>
                            <td>
                                <select name="payment_method[]" class="form-select form-select-sm">
                                    <option value="cash">Cash (សាច់ប្រាក់)</option>
                                    <option value="aba_khqr">ABA / KHQR</option>
                                    <option value="card">Card / Other</option>
                                </select>
                            </td>
                            <td>
                                <input type="datetime-local" name="arrived_at[]" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>">
                            </td>
                            <td>
                                <select name="staff_id[]" class="form-select form-select-sm">
                                    <option value="0">-- None --</option>
                                    <?php foreach ($allStaff as $st): ?>
                                        <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['full_name']) ?> (<?= htmlspecialchars($st['role']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="text-align: center;">
                                <button type="button" class="btn btn-sm btn-link text-danger" onclick="removeRow(this)" title="Delete Row" style="padding: 2px;">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 12px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="addNewRow()">
                <i class="fa-solid fa-plus mr-1"></i> Add Another Row
            </button>

            <button type="submit" class="btn btn-primary" onclick="return confirm('Save all entered paper records? They will be marked as entered after recovery.');">
                <i class="fa-solid fa-floppy-disk mr-1"></i> Save &amp; Recover Paper Records
            </button>
        </div>
    </form>
</div>

<!-- History of Recovered Orders -->
<div class="card">
    <div style="border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
            <i class="fa-solid fa-clock-rotate-left mr-2" style="color: var(--primary);"></i>Recent Recovered Records Log
        </h2>
        <span class="badge badge-warning">
            <?= count($recoveredOrders) ?> recovered order(s)
        </span>
    </div>

    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 140px;">Order #</th>
                    <th style="width: 100px;">Slip #</th>
                    <th style="width: 120px;">Plate</th>
                    <th style="width: 110px;">Type</th>
                    <th style="width: 90px; text-align: right;">Total</th>
                    <th style="width: 90px;">Method</th>
                    <th style="width: 140px;">Paper Arrival</th>
                    <th>Recovery Audit Stamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recoveredOrders)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 24px;">
                            No disaster recovery records entered yet. All normal digital sales are in the Orders list.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recoveredOrders as $ro): ?>
                        <tr>
                            <td style="font-family: monospace; font-size: 12px;"><?= htmlspecialchars($ro['order_number']) ?></td>
                            <td style="font-weight: 700; font-family: monospace; color: #ea580c;">
                                <?= htmlspecialchars($ro['paper_slip_number'] ?? '&mdash;') ?>
                            </td>
                            <td style="font-weight: 700; font-family: monospace;"><?= htmlspecialchars($ro['plate_number']) ?></td>
                            <td><span class="badge badge-slate" style="font-size: 11px;"><?= htmlspecialchars($ro['vehicle_type']) ?></span></td>
                            <td style="text-align: right; font-weight: 700;">$<?= number_format((float)$ro['total_usd'], 2) ?></td>
                            <td><span class="badge badge-success" style="font-size: 11px;"><?= htmlspecialchars($ro['payment_method']) ?></span></td>
                            <td style="font-size: 12px;"><?= htmlspecialchars($ro['arrived_at']) ?></td>
                            <td>
                                <span class="badge badge-warning" style="font-size: 11px;">
                                    <i class="fa-solid fa-file-signature mr-1"></i> entered after recovery
                                </span>
                                <span style="font-size: 12px; color: var(--text-muted); margin-left: 6px;">
                                    by <strong><?= htmlspecialchars($ro['recovered_by_name'] ?? 'Staff') ?></strong> at <?= htmlspecialchars($ro['recovered_at']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const PRICE_MAP = <?= json_encode($priceMap) ?>;

function autoFillPrice(el) {
    const row = el.closest('tr');
    if (!row) return;

    const vtype = row.querySelector('.vtype-select').value;
    const svcId = row.querySelector('.svc-select').value;
    const amountInput = row.querySelector('.amount-input');

    if (svcId && PRICE_MAP[svcId] && PRICE_MAP[svcId][vtype] !== undefined) {
        amountInput.value = parseFloat(PRICE_MAP[svcId][vtype]).toFixed(2);
    }
}

function removeRow(btn) {
    const tbody = document.getElementById('recoveryRows');
    const rows = tbody.querySelectorAll('.recovery-row');
    if (rows.length <= 1) {
        alert('You must keep at least one row.');
        return;
    }
    btn.closest('tr').remove();
    reindexRows();
}

function reindexRows() {
    const rows = document.querySelectorAll('#recoveryRows .recovery-row');
    rows.forEach((row, idx) => {
        row.querySelector('td:first-child').innerText = idx + 1;
    });
}

function addNewRow() {
    const tbody = document.getElementById('recoveryRows');
    const rowCount = tbody.querySelectorAll('.recovery-row').length + 1;
    const newTr = document.createElement('tr');
    newTr.className = 'recovery-row';
    newTr.setAttribute('data-row-id', rowCount);

    const nowVal = new Date().toISOString().slice(0, 16);

    let svcOptions = '<option value="0">-- Select Service --</option>';
    <?php foreach ($servicesList as $s): ?>
        svcOptions += '<option value="<?= $s['id'] ?>"><?= addslashes($s['name_en']) ?></option>';
    <?php endforeach; ?>

    let staffOptions = '<option value="0">-- None --</option>';
    <?php foreach ($allStaff as $st): ?>
        staffOptions += '<option value="<?= $st['id'] ?>"><?= addslashes($st['full_name']) ?> (<?= addslashes($st['role']) ?>)</option>';
    <?php endforeach; ?>

    newTr.innerHTML = `
        <td style="text-align: center; font-weight: 700; color: var(--text-muted);">${rowCount}</td>
        <td>
            <input type="text" name="paper_slip_number[]" class="form-control form-control-sm" placeholder="SLIP-${String(rowCount).padStart(3, '0')}" style="font-family: monospace;">
        </td>
        <td>
            <input type="text" name="plate_number[]" class="form-control form-control-sm plate-input" placeholder="e.g. 2AB-1234" style="text-transform: uppercase; font-weight: 700; font-family: monospace;">
        </td>
        <td>
            <select name="vehicle_type[]" class="form-select form-select-sm vtype-select" onchange="autoFillPrice(this)">
                <option value="small_car">Small Car</option>
                <option value="suv_pickup">SUV / Pickup</option>
                <option value="van_truck">Van / Truck</option>
                <option value="motorbike">Motorbike</option>
            </select>
        </td>
        <td>
            <select name="service_id[]" class="form-select form-select-sm svc-select" onchange="autoFillPrice(this)">
                ${svcOptions}
            </select>
        </td>
        <td>
            <div style="position: relative;">
                <span style="position: absolute; left: 8px; top: 6px; color: var(--text-muted); font-size: 11px;">$</span>
                <input type="number" step="0.25" min="0" name="total_usd[]" class="form-control form-control-sm amount-input" style="padding-left: 20px;" placeholder="0.00">
            </div>
        </td>
        <td>
            <select name="payment_method[]" class="form-select form-select-sm">
                <option value="cash">Cash (សាច់ប្រាក់)</option>
                <option value="aba_khqr">ABA / KHQR</option>
                <option value="card">Card / Other</option>
            </select>
        </td>
        <td>
            <input type="datetime-local" name="arrived_at[]" class="form-control form-control-sm" value="${nowVal}">
        </td>
        <td>
            <select name="staff_id[]" class="form-select form-select-sm">
                ${staffOptions}
            </select>
        </td>
        <td style="text-align: center;">
            <button type="button" class="btn btn-sm btn-link text-danger" onclick="removeRow(this)" title="Delete Row" style="padding: 2px;">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </td>
    `;

    tbody.appendChild(newTr);
}
</script>

<?php require_once dirname(__DIR__, 2) . '/views/layouts/footer.php'; ?>
