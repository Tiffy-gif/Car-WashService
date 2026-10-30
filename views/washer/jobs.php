<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/BayManager.php';

Auth::requirePermission('washer_jobs.view');
$pageTitle = __('nav_washer_screen');
$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

$washerId = Auth::getUserId();
// If owner viewing washer screen, default to washer 1 (Rathana) or selected washer
if (Auth::getRole() === 'owner') {
    $washerId = (int)($_GET['washer_id'] ?? 3); // 3 is Rathana
}

// Fetch washer info
$wInfoStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$wInfoStmt->execute([$washerId]);
$washer = $wInfoStmt->fetch();

// Handle status updates and bay assignments
$msg = null;
$msgType = 'success';
if (isset($csrfError)) {
    $msg = $csrfError;
    $msgType = 'danger';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && !isset($csrfError)) {
    $action = $_POST['action'];

    // 1. Assign waiting car to bay
    if ($action === 'assign_bay') {
        $bayId = (int)($_POST['bay_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);

        if ($bayId <= 0 || $orderId <= 0) {
            $msg = 'Please select a valid bay and car.';
            $msgType = 'danger';
        } else {
            $res = BayManager::assignCarToBay($pdo, $bayId, $orderId, (int)$washer['id'], $washer['full_name'] ?? 'Washer', 'washer');
            if (!empty($res['success'])) {
                // Ensure washer is assigned in order_washers if not already
                $chkOw = $pdo->prepare("SELECT COUNT(*) FROM order_washers WHERE order_id = ? AND washer_id = ?");
                $chkOw->execute([$orderId, $washerId]);
                if (((int)$chkOw->fetchColumn()) === 0) {
                    $pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd) VALUES (?, ?, 0.00)")
                        ->execute([$orderId, $washerId]);
                }
                $msg = $res['message'];
                $msgType = 'success';
            } else {
                $msg = $res['message'] ?? 'Could not assign bay.';
                $msgType = 'danger';
            }
        }
    }

    // 2. Finish Wash (Bay becomes Free and order shows Ready to pay, or Handshake to Tech)
    elseif ($action === 'finish_car' || ($action === 'update_status' && ($_POST['new_status'] ?? '') === 'finished')) {
        $targetId = (int)($_POST['bay_id'] ?? $_POST['order_id'] ?? 0);
        $tookLonger = isset($_POST['took_longer']) ? (int)$_POST['took_longer'] : null;
        $res = BayManager::finishCar($pdo, $targetId, (int)$washer['id'], $washer['full_name'] ?? 'Washer', 'washer', $tookLonger);
        if (!empty($res['success'])) {
            $msg = $res['message'];
            $msgType = 'success';
        } else {
            $msg = $res['message'] ?? 'Could not finish wash.';
            $msgType = 'danger';
        }
    }

    // 3. Start Washing
    elseif ($action === 'update_status' && ($_POST['new_status'] ?? '') === 'washing') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $bayId = (int)($_POST['bay_id'] ?? 0);

        if ($bayId > 0) {
            $res = BayManager::assignCarToBay($pdo, $bayId, $orderId, (int)$washer['id'], $washer['full_name'] ?? 'Washer', 'washer');
            if (!empty($res['success'])) {
                $msg = $res['message'];
                $msgType = 'success';
            } else {
                $msg = $res['message'];
                $msgType = 'danger';
            }
        } else {
            $pdo->prepare("UPDATE orders SET status = 'washing', started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = ?")
                ->execute([$orderId]);
            $msg = "Order marked as Washing.";
            $msgType = 'success';
        }
    }
}

// Fetch wash bays & queue
$allBays = BayManager::getAllBays($pdo);
$washBays = array_filter($allBays, fn($b) => $b['bay_type'] === 'wash');
$queues = BayManager::getQueues($pdo);
$washQueue = $queues['wash_queue'];

// Fetch orders assigned to this washer
$jobsStmt = $pdo->prepare("
    SELECT o.*, ow.commission_usd, GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ') as service_names,
           b.bay_name
    FROM orders o
    JOIN order_washers ow ON o.id = ow.order_id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN bays b ON o.bay_id = b.id
    WHERE ow.washer_id = ?
    GROUP BY o.id
    ORDER BY CASE o.status 
        WHEN 'washing' THEN 1
        WHEN 'waiting' THEN 2
        WHEN 'finished' THEN 3
        WHEN 'paid' THEN 4
        ELSE 5 END, o.id DESC
");
$jobsStmt->execute([$washerId]);
$jobs = $jobsStmt->fetchAll();

// Washer's earnings today
$earnStmt = $pdo->prepare("
    SELECT COALESCE(SUM(ow.commission_usd), 0) as total_earned, COUNT(DISTINCT o.id) as jobs_count
    FROM orders o
    JOIN order_washers ow ON o.id = ow.order_id
    WHERE ow.washer_id = ? AND DATE(o.created_at) = CURDATE()
");
$earnStmt->execute([$washerId]);
$todayEarn = $earnStmt->fetch() ?: ['total_earned' => 0.00, 'jobs_count' => 0];

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-soap mr-1" style="color: var(--primary);"></i> <?= htmlspecialchars(__('nav_washer_screen')) ?>
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">
            Washer: <strong><?= htmlspecialchars($washer['full_name'] ?? 'Washer') ?></strong> • Commission: 
            <?= ($washer['commission_type'] === 'percentage') ? ((float)$washer['commission_rate'] . '% per job') : ('$' . number_format((float)$washer['commission_rate'], 2) . ' fixed/job') ?>
        </p>
    </div>
    <div style="display: flex; gap: 12px; align-items: center;">
        <div style="background: var(--bg-card); border: 1px solid var(--border); padding: 10px 18px; border-radius: var(--radius-md); text-align: right;">
            <div class="text-xs text-muted">Today's Commission</div>
            <div style="font-size: 18px; font-weight: 700; color: var(--success);"><?= formatUSD((float)$todayEarn['total_earned']) ?> <span class="text-xs text-muted">(<?= (int)$todayEarn['jobs_count'] ?> jobs)</span></div>
        </div>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; border-radius: var(--radius-md); font-weight: 600;">
        <div>
            <i class="fa-solid <?= $msgType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mr-1"></i> <?= htmlspecialchars($msg) ?>
        </div>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: inherit;">&times;</button>
    </div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- 1. LIVE CAR WASH BAYS (Bay 1, Bay 2, Bay 3) -->
<!-- ======================================================== -->
<div style="margin-bottom: 28px;">
    <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-warehouse" style="color: var(--primary);"></i>
        <span>Car Wash Bays Status</span>
    </h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
        <?php foreach ($washBays as $b): 
            $isBusy = ($b['status'] === 'busy' && !empty($b['current_car']));
            $isActive = ($b['is_active'] === 1);
            $car = $b['current_car'];
        ?>
            <div class="card" style="padding: 16px; border-left: 5px solid <?= !$isActive ? 'var(--text-light)' : ($isBusy ? 'var(--warning)' : 'var(--success)') ?>;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong style="font-size: 16px; color: var(--text-main);"><?= htmlspecialchars($b['bay_name']) ?></strong>
                    <?php if (!$isActive): ?>
                        <span class="badge badge-slate">Off</span>
                    <?php elseif ($isBusy): ?>
                        <span class="badge badge-warning"><i class="fa-solid fa-circle-dot mr-1"></i> Busy</span>
                    <?php else: ?>
                        <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Free</span>
                    <?php endif; ?>
                </div>

                <div style="min-height: 54px; margin-bottom: 10px;">
                    <?php if (!$isActive): ?>
                        <span class="text-xs text-muted">Switched off in settings</span>
                    <?php elseif ($isBusy && $car): ?>
                        <div style="font-family: var(--font-mono); font-weight: 800; font-size: 15px;">
                            <?= htmlspecialchars($car['plate_number']) ?>
                        </div>
                        <div class="text-xs text-muted">
                            <?= htmlspecialchars($car['service_names'] ?: 'Wash') ?> • <?= (int)$car['duration_minutes'] ?>m in bay
                        </div>
                    <?php else: ?>
                        <span class="text-xs text-success" style="font-weight: 600;">
                            <i class="fa-solid fa-circle-check mr-1"></i> Free &amp; Ready
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($isBusy && $car): 
                    $isMixed = !empty($car['is_mixed']);
                ?>
                    <button type="button" 
                            class="btn btn-primary btn-sm" 
                            style="width: 100%; background: <?= $isMixed ? '#9333ea' : 'var(--success)' ?>; border-color: <?= $isMixed ? '#9333ea' : 'var(--success)' ?>;"
                            onclick="openWasherFinishModal(<?= $b['id'] ?>, <?= $car['order_id'] ?>, '<?= htmlspecialchars($car['plate_number'], ENT_QUOTES) ?>', <?= $isMixed ? 1 : 0 ?>)">
                        <i class="fa-solid <?= $isMixed ? 'fa-paper-plane' : 'fa-check' ?> mr-1"></i> 
                        <?= $isMixed ? 'Send to Tech (Finish Wash)' : 'Finish Wash (Free Bay)' ?>
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ======================================================== -->
<!-- 2. CAR WASH WAITING QUEUE (FIFO) -->
<!-- ======================================================== -->
<?php if (!empty($washQueue)): ?>
<div style="margin-bottom: 28px;">
    <div class="card" style="padding: 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 14px;">
            <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-list-ol" style="color: var(--primary);"></i>
                <span>Waiting Cars in Queue (FIFO)</span>
            </h3>
            <span class="badge badge-primary"><?= count($washQueue) ?> waiting</span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($washQueue as $q): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-subtle); border-radius: var(--radius-sm); padding: 10px 14px; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="badge badge-primary" style="font-weight: 800; font-size: 13px;">
                            <?= $q['position_label'] ?>
                        </span>
                        <span style="font-family: var(--font-mono); font-weight: 800; font-size: 15px;">
                            <?= htmlspecialchars($q['plate_number']) ?>
                        </span>
                        <?= vehicleTypeBadge($q['vehicle_type']) ?>
                        <span class="text-xs text-muted">
                            <?= htmlspecialchars($q['service_names'] ?: 'Wash') ?> • <?= $q['wait_minutes'] ?>m waiting
                        </span>
                    </div>

                    <form action="" method="POST" style="display: flex; gap: 6px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="assign_bay">
                        <input type="hidden" name="order_id" value="<?= $q['order_id'] ?>">
                        <select name="bay_id" class="form-select" required style="font-size: 12px; padding: 4px 8px;">
                            <option value="">Put in Free Bay...</option>
                            <?php foreach ($washBays as $wb): ?>
                                <?php if ($wb['is_active']): ?>
                                    <option value="<?= $wb['id'] ?>" <?= $wb['status'] === 'busy' ? 'disabled' : '' ?>>
                                        <?= htmlspecialchars($wb['bay_name']) ?> <?= $wb['status'] === 'busy' ? '(Busy)' : '(Free)' ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">
                            Put in Bay
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- 3. MY ASSIGNED JOBS -->
<!-- ======================================================== -->
<h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 12px;">
    <i class="fa-solid fa-clipboard-user mr-1" style="color: var(--primary);"></i> My Assigned Jobs
</h3>

<div class="grid grid-cols-2" style="gap: 20px;">
    <?php if (empty($jobs)): ?>
        <div class="card" style="grid-column: span 2; text-align: center; padding: 40px; color: var(--text-muted);">
            <i class="fa-solid fa-clipboard-check" style="font-size: 40px; margin-bottom: 10px; opacity: 0.4;"></i>
            <h3>No jobs assigned currently</h3>
            <p class="text-sm">Select a waiting vehicle above to put it into a free wash bay.</p>
        </div>
    <?php else: ?>
        <?php foreach ($jobs as $job): ?>
            <div class="card" style="border-left: 6px solid <?= ($job['status'] === 'washing') ? 'var(--info)' : (($job['status'] === 'waiting') ? 'var(--warning)' : 'var(--success)') ?>;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                    <div>
                        <span style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); background: var(--bg-subtle); padding: 4px 10px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                            <?= htmlspecialchars($job['plate_number']) ?>
                        </span>
                        <?php if (!empty($job['bay_name'])): ?>
                            <span class="badge badge-primary" style="margin-left: 6px;">
                                <i class="fa-solid fa-warehouse mr-1"></i> <?= htmlspecialchars($job['bay_name']) ?>
                            </span>
                        <?php endif; ?>
                        <div style="margin-top: 6px;">
                            <?= vehicleTypeBadge($job['vehicle_type']) ?>
                        </div>
                    </div>
                    <div>
                        <?= statusBadge($job['status']) ?>
                    </div>
                </div>

                <div style="margin-bottom: 14px;">
                    <div style="font-size: 14px; font-weight: 700; color: var(--text-main);">
                        <?= htmlspecialchars($job['service_names'] ?? 'Wash Service') ?>
                    </div>
                    <div class="text-xs text-muted">
                        Customer: <?= htmlspecialchars((string)($job['customer_name'] ?? 'Guest')) ?> • Order #<?= htmlspecialchars((string)$job['order_number']) ?>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid var(--border);">
                    <div>
                        <span class="text-xs text-muted">Commission:</span>
                        <strong style="color: var(--success); font-size: 15px;"><?= formatUSD((float)$job['commission_usd']) ?></strong>
                    </div>

                    <div style="display: flex; gap: 8px;">
                        <?php if ($job['status'] === 'waiting'): ?>
                            <form action="" method="POST" style="display: flex; gap: 4px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="assign_bay">
                                <input type="hidden" name="order_id" value="<?= $job['id'] ?>">
                                <select name="bay_id" class="form-select" required style="font-size: 12px; padding: 4px 6px;">
                                    <?php foreach ($washBays as $wb): ?>
                                        <?php if ($wb['is_active']): ?>
                                            <option value="<?= $wb['id'] ?>" <?= $wb['status'] === 'busy' ? 'disabled' : '' ?>>
                                                <?= htmlspecialchars($wb['bay_name']) ?> <?= $wb['status'] === 'busy' ? '(Busy)' : '(Free)' ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fa-solid fa-play mr-1"></i> Start in Bay
                                </button>
                            </form>
                        <?php elseif ($job['status'] === 'washing'): 
                            $isMixed = !empty($job['is_mixed']);
                        ?>
                            <button type="button" 
                                    class="btn btn-primary btn-sm" 
                                    style="background-color: <?= $isMixed ? '#9333ea' : 'var(--success)' ?>; border-color: <?= $isMixed ? '#9333ea' : 'var(--success)' ?>;"
                                    onclick="openWasherFinishModal(<?= (int)($job['bay_id'] ?? 0) ?>, <?= $job['id'] ?>, '<?= htmlspecialchars($job['plate_number'], ENT_QUOTES) ?>', <?= $isMixed ? 1 : 0 ?>)">
                                <i class="fa-solid <?= $isMixed ? 'fa-paper-plane' : 'fa-check' ?> mr-1"></i> 
                                <?= $isMixed ? 'Send to Tech' : 'Finish Wash' ?>
                            </button>
                        <?php else: ?>
                            <span class="text-xs text-muted" style="font-weight: 600;">
                                <i class="fa-solid fa-circle-check" style="color: var(--success);"></i> <?= statusBadge($job['status']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- WASHER FINISH MODAL (ESTIMATE QUESTION & HANDSHAKE) -->
<div id="washerFinishModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 440px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clipboard-check" style="color: var(--primary);"></i>
                <span id="wModalTitle">Finish Car Wash</span>
            </h3>
            <button type="button" onclick="closeWasherFinishModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="finish_car">
            <input type="hidden" name="bay_id" id="wModalBayId" value="">
            <input type="hidden" name="order_id" id="wModalOrderId" value="">
            <input type="hidden" name="took_longer" id="wModalTookLonger" value="0">

            <div style="background: var(--bg-subtle); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;">
                <div style="font-size: 13px; color: var(--text-muted);">Vehicle:</div>
                <div style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); color: var(--text-main);" id="wModalPlate">
                    -
                </div>
                <div class="text-xs" id="wModalMixedNotice" style="display: none; color: #9333ea; font-weight: 700; margin-top: 4px;">
                    <i class="fa-solid fa-paper-plane mr-1"></i> Mixed Order: Finishing wash will send vehicle to the Technical screen.
                </div>
            </div>

            <!-- Estimate Engine Question -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">
                    Did this wash take longer than expected? <span style="color: var(--danger);">*</span>
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <button type="button" 
                            id="wBtnNo"
                            class="btn btn-secondary" 
                            style="padding: 10px; font-weight: 700; border: 2px solid var(--border);"
                            onclick="selectWTookLonger(0)">
                        <i class="fa-solid fa-thumbs-up mr-1" style="color: var(--success);"></i> No (On Schedule)
                    </button>
                    <button type="button" 
                            id="wBtnYes"
                            class="btn btn-secondary" 
                            style="padding: 10px; font-weight: 700; border: 2px solid var(--border);"
                            onclick="selectWTookLonger(1)">
                        <i class="fa-solid fa-clock-rotate-left mr-1" style="color: var(--warning);"></i> Yes (Took Longer)
                    </button>
                </div>
                <div class="text-xs text-muted" style="margin-top: 6px;">
                    Saved for the estimate engine to calibrate future wash duration estimates.
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeWasherFinishModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="wBtnSubmit" style="background: var(--success); border-color: var(--success);">
                    <i class="fa-solid fa-check mr-1"></i> Confirm &amp; Finish
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openWasherFinishModal(bayId, orderId, plateNumber, isMixed) {
    document.getElementById('wModalBayId').value = bayId;
    document.getElementById('wModalOrderId').value = orderId;
    document.getElementById('wModalPlate').textContent = plateNumber;
    document.getElementById('wModalMixedNotice').style.display = isMixed ? 'block' : 'none';
    document.getElementById('wBtnSubmit').innerHTML = isMixed 
        ? '<i class="fa-solid fa-paper-plane mr-1"></i> Send to Tech' 
        : '<i class="fa-solid fa-check mr-1"></i> Confirm Finish';
    document.getElementById('wBtnSubmit').style.backgroundColor = isMixed ? '#9333ea' : 'var(--success)';
    document.getElementById('wBtnSubmit').style.borderColor = isMixed ? '#9333ea' : 'var(--success)';
    selectWTookLonger(0);
    document.getElementById('washerFinishModal').style.display = 'flex';
}
function selectWTookLonger(val) {
    document.getElementById('wModalTookLonger').value = val;
    const btnNo = document.getElementById('wBtnNo');
    const btnYes = document.getElementById('wBtnYes');
    if (val === 1) {
        btnYes.style.background = '#fef3c7';
        btnYes.style.borderColor = 'var(--warning)';
        btnNo.style.background = 'var(--bg-subtle)';
        btnNo.style.borderColor = 'var(--border)';
    } else {
        btnNo.style.background = '#dcfce7';
        btnNo.style.borderColor = 'var(--success)';
        btnYes.style.background = 'var(--bg-subtle)';
        btnYes.style.borderColor = 'var(--border)';
    }
}
function closeWasherFinishModal() {
    document.getElementById('washerFinishModal').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeWasherFinishModal();
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
