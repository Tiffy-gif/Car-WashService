<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/BayManager.php';
require_once dirname(__DIR__, 2) . '/core/TechnicalManager.php';
require_once dirname(__DIR__, 2) . '/core/ImageStorage.php';

Auth::requirePermission('mechanic_jobs.view');
$pageTitle = __('nav_mechanic_screen');
$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

$currentRole = Auth::getRole();
$mechanicId = Auth::getUserId();

// If owner or manager is inspecting mechanic view, allow switching mechanic
if (in_array($currentRole, ['owner', 'manager'], true)) {
    $firstMechId = (int)$pdo->query("SELECT id FROM users WHERE role IN ('mechanic', 'technical') AND status = 'active' LIMIT 1")->fetchColumn();
    $mechanicId = (int)($_GET['mechanic_id'] ?? ($firstMechId ?: $mechanicId));
}

// Fetch mechanic profile
$mStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$mStmt->execute([$mechanicId]);
$mechanic = $mStmt->fetch();

$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Mechanic Actions (Tasks, Multi-lane, Parts Requests, Inspections, Oil Adjustments)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    // 1. Assign Car to Bay
    if ($action === 'assign_bay') {
        $bayId = (int)($_POST['bay_id'] ?? 0);
        if ($bayId <= 0 || $orderId <= 0) {
            $message = 'Please select a valid bay and car.';
            $messageType = 'danger';
        } else {
            $res = BayManager::assignCarToBay($pdo, $bayId, $orderId, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic', 'mechanic');
            if (!empty($res['success'])) {
                TechnicalManager::ensureJobOrder($pdo, $orderId, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic');
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not assign bay.';
                $messageType = 'danger';
            }
        }
    }


    // 3. Start Specific Task in Lane
    elseif ($action === 'start_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        try {
            $res = TechnicalManager::startTask($pdo, $taskId, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic');
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 4. Finish Specific Task in Lane (with optional actual oil used)
    elseif ($action === 'finish_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $actualOil = !empty($_POST['actual_oil_used']) ? (float)$_POST['actual_oil_used'] : null;
        try {
            $res = TechnicalManager::finishTask($pdo, $taskId, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic', $actualOil);
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 5. Adjust Oil Amount
    elseif ($action === 'adjust_oil') {
        $actualOil = (float)($_POST['actual_oil_used'] ?? 0.0);
        try {
            $res = TechnicalManager::adjustOilUsage($pdo, $orderId, $actualOil, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic');
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 6. Update Guided Inspection Checklist Item
    elseif ($action === 'update_checklist_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $chkStatus = $_POST['status'] ?? 'pass';
        $measVal = trim($_POST['measurement_value'] ?? '');
        $chkNotes = trim($_POST['notes'] ?? '');
        $photoPath = null;

        // Handle photo upload
        if (isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadRes = ImageStorage::processUpload($_FILES['photo'], 'inspections', 'insp');
            if ($uploadRes['success']) {
                $photoPath = $uploadRes['image_path'];
            } else {
                $message = "Photo upload error: " . $uploadRes['error'];
                $messageType = 'danger';
            }
        } elseif (!empty($_POST['photo_url'])) {
            $photoPath = trim($_POST['photo_url']);
        }

        if ($messageType !== 'danger') {
            try {
                $res = TechnicalManager::updateChecklistItem(
                    $pdo,
                    $itemId,
                    $chkStatus,
                    $measVal,
                    $photoPath,
                    $chkNotes,
                    (int)$mechanic['id'],
                    $mechanic['full_name'] ?? 'Mechanic'
                );
                $message = $res['message'];
                $messageType = 'success';
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // 7. Request Parts from Warehouse / Stock Control
    elseif ($action === 'request_parts') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);
        $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
        $reqNotes = trim($_POST['notes'] ?? '');
        try {
            $res = TechnicalManager::requestParts(
                $pdo,
                $orderId,
                $productId,
                $qty,
                (int)$mechanic['id'],
                $mechanic['full_name'] ?? 'Mechanic',
                $taskId,
                $reqNotes
            );
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 8. Finish Full Technical Job Order
    elseif ($action === 'finish_car' || ($action === 'update_status' && ($_POST['new_status'] ?? '') === 'finished')) {
        $tookLonger = isset($_POST['took_longer']) ? (int)$_POST['took_longer'] : null;
        try {
            $res = TechnicalManager::finishJobOrder($pdo, $orderId, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic', 'technical', $tookLonger);
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 8b. Start Technical Job Order
    elseif ($action === 'update_status' && ($_POST['new_status'] ?? '') === 'working') {
        try {
            OrderGate::assertReceiptPrinted($pdo, $orderId, $_POST['manager_pin'] ?? null, $_POST['override_reason'] ?? null, null, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic', 'mechanic');
            $pdo->prepare("UPDATE orders SET repair_status = 'working', started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = ?")
                ->execute([$orderId]);
            $message = "Technical Job Order started.";
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 9. Save Mechanic Note
    elseif ($action === 'save_repair_note' && $orderId > 0) {
        $noteText = trim($_POST['repair_notes'] ?? '');
        $pdo->prepare("UPDATE orders SET repair_notes = ? WHERE id = ?")->execute([$noteText, $orderId]);
        $pdo->prepare("UPDATE vehicle_repair_history SET repair_notes = ? WHERE order_id = ?")->execute([$noteText, $orderId]);
        $message = "Mechanic repair note saved successfully!";
        $messageType = 'success';
    }
}

// Fetch orders in technical bays or with repair/oil change tasks
$jobsStmt = $pdo->query("
    SELECT o.*, 0.00 as commission_usd,
           COALESCE(o.repair_status, o.status) as current_job_status
    FROM orders o
    LEFT JOIN bays b ON o.bay_id = b.id
    WHERE o.status NOT IN ('cancelled')
      AND (
          o.bay_id IN (SELECT id FROM bays WHERE bay_type = 'oil_change')
          OR o.repair_status IN ('waiting_tech', 'working')
          OR o.id IN (SELECT DISTINCT order_id FROM order_tasks WHERE task_type = 'oil_change' AND status IN ('in_progress', 'waiting', 'pending_prereq', 'ready_for_tech'))
      )
    GROUP BY o.id
    ORDER BY CASE COALESCE(o.repair_status, o.status)
        WHEN 'working' THEN 1
        WHEN 'waiting' THEN 2
        WHEN 'finished' THEN 3
        WHEN 'paid' THEN 4
        ELSE 5 END, o.id DESC
");
$jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-load Job Orders and simultaneous lanes for each active job
$tasksByOrder = [];
$checklistsByOrder = [];
$partsRequestsByOrder = [];

foreach ($jobs as $job) {
    $jId = (int)$job['id'];
    TechnicalManager::ensureJobOrder($pdo, $jId, (int)$mechanic['id'], $mechanic['full_name'] ?? 'Mechanic');

    // Fetch tasks
    $tStmt = $pdo->prepare("SELECT * FROM order_tasks WHERE order_id = ? ORDER BY sequence ASC, id ASC");
    $tStmt->execute([$jId]);
    $tasksByOrder[$jId] = $tStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch inspection checklist
    $cStmt = $pdo->prepare("SELECT * FROM inspection_checklists WHERE order_id = ? ORDER BY id ASC");
    $cStmt->execute([$jId]);
    $checklistsByOrder[$jId] = $cStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch parts requests
    $pStmt = $pdo->prepare("SELECT * FROM parts_requests WHERE order_id = ? ORDER BY id DESC");
    $pStmt->execute([$jId]);
    $partsRequestsByOrder[$jId] = $pStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch items (services & spare parts) for each order
$orderIds = array_column($jobs, 'id');
$itemsByOrder = [];
if (!empty($orderIds)) {
    $inClause = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ($inClause)");
    $itemsStmt->execute($orderIds);
    while ($it = $itemsStmt->fetch()) {
        $itemsByOrder[$it['order_id']][] = $it;
    }
}

// All active products for Parts Request modal
$allProducts = $pdo->query("SELECT id, name_en, stock_qty, price_usd, unit FROM products WHERE is_active = 1 ORDER BY name_en ASC")->fetchAll(PDO::FETCH_ASSOC);

// Technical jobs completed today
$earnStmt = $pdo->query("
    SELECT 0.00 as total_earned, COUNT(DISTINCT o.id) as jobs_count
    FROM orders o
    WHERE o.status IN ('working', 'finished') 
      AND (o.bay_id IN (SELECT id FROM bays WHERE bay_type = 'oil_change') OR o.repair_status IS NOT NULL)
      AND DATE(o.created_at) = CURDATE()
");
$todayEarn = $earnStmt->fetch() ?: ['total_earned' => 0.00, 'jobs_count' => 0];

// Fetch bays and technical queue
$allBays = BayManager::getAllBays($pdo);
$oilBay = null;
foreach ($allBays as $b) {
    if ($b['bay_type'] === 'oil_change') {
        $oilBay = $b;
        break;
    }
}
$queues = BayManager::getQueues($pdo);
$techQueue = $queues['technical_queue'];

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-wrench mr-1" style="color: #ea580c;"></i> <?= htmlspecialchars(__('nav_mechanic_screen')) ?>
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">
            Bay 4 • Technical Service &amp; Maintenance Station
        </p>
    </div>

    <div style="display: flex; gap: 12px; align-items: center;">
        <div style="background: var(--bg-card); border: 1px solid var(--border); padding: 10px 18px; border-radius: var(--radius-md); text-align: right;">
            <div class="text-xs text-muted">Today's Jobs</div>
            <div style="font-size: 18px; font-weight: 700; color: #ea580c;">
                <?= (int)$todayEarn['jobs_count'] ?> jobs
            </div>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 22px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>


<!-- ======================================================== -->
<!-- 1. OIL CHANGE BAY & TECHNICAL QUEUE (FIFO) -->
<!-- ======================================================== -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px; margin-bottom: 28px;">
    <!-- Oil Bay Status -->
    <?php if ($oilBay): 
        $isOilBusy = ($oilBay['status'] === 'busy' && !empty($oilBay['current_order_id']));
        $isOilActive = ($oilBay['is_active'] === 1);
        $oilCar = $oilBay['current_car'];
    ?>
        <div class="card" style="padding: 16px; border-top: 5px solid <?= !$isOilActive ? 'var(--text-light)' : ($isOilBusy ? 'var(--warning)' : 'var(--success)') ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 32px; height: 32px; border-radius: 6px; background: #ffedd5; color: #ea580c; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-oil-can"></i>
                    </div>
                    <div>
                        <strong style="font-size: 15px; color: var(--text-main);"><?= htmlspecialchars($oilBay['bay_name']) ?></strong>
                        <div class="text-xs text-muted">Oil Change &amp; Technical Bay</div>
                    </div>
                </div>
                <div>
                    <?php if (!$isOilActive): ?>
                        <span class="badge badge-slate">Off</span>
                    <?php elseif ($isOilBusy): ?>
                        <span class="badge badge-warning"><i class="fa-solid fa-circle-dot mr-1"></i> Busy</span>
                    <?php else: ?>
                        <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Free</span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="min-height: 56px; margin-bottom: 12px;">
                <?php if (!$isOilActive): ?>
                    <span class="text-xs text-muted">Switched off in settings by owner</span>
                <?php elseif ($isOilBusy && $oilCar): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <span style="font-family: var(--font-mono); font-weight: 800; font-size: 16px; background: var(--bg-subtle); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border);">
                            <?= htmlspecialchars($oilCar['plate_number']) ?>
                        </span>
                        <span class="text-xs" style="color: var(--warning); font-weight: 700;">
                            <i class="fa-solid fa-stopwatch mr-1"></i> <?= (int)$oilCar['duration_minutes'] ?>m in bay
                        </span>
                    </div>
                    <div class="text-xs text-muted">
                        <?= htmlspecialchars($oilCar['service_names'] ?: 'Oil Change & Technical') ?> • <?= htmlspecialchars($oilCar['customer_name'] ?? 'Guest') ?>
                    </div>
                <?php else: ?>
                    <span class="text-xs text-success" style="font-weight: 600;">
                        <i class="fa-solid fa-circle-check mr-1"></i> Oil Bay is Free &amp; Ready
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($isOilBusy && $oilCar): ?>
                <button type="button" 
                        class="btn btn-primary btn-sm" 
                        style="width: 100%; background: var(--success); border-color: var(--success); font-weight: 700;"
                        onclick="openMechFinishModal(<?= (int)$oilBay['id'] ?>, <?= $oilCar['order_id'] ?>, '<?= htmlspecialchars($oilCar['plate_number'], ENT_QUOTES) ?>')">
                    <i class="fa-solid fa-check mr-1"></i> Finish Technical Job Order
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Technical Waiting Queue -->
    <div class="card" style="padding: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; border-radius: 6px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-list-ol"></i>
                </div>
                <div>
                    <strong style="font-size: 15px; color: var(--text-main);">Technical Queue (FIFO)</strong>
                    <div class="text-xs text-muted">First-in, first-out for Oil Change Bay</div>
                </div>
            </div>
            <span class="badge badge-amber"><?= count($techQueue) ?> waiting</span>
        </div>

        <div style="min-height: 56px;">
            <?php if (empty($techQueue)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 12px;" class="text-xs">
                    No technical/repair cars waiting in queue.
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($techQueue as $tq): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-subtle); padding: 6px 10px; border-radius: 4px; font-size: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="badge badge-primary" style="font-weight: 800; font-size: 11px;"><?= $tq['position_label'] ?></span>
                                <strong style="font-family: var(--font-mono);"><?= htmlspecialchars($tq['plate_number']) ?></strong>
                                <span class="text-muted">(<?= $tq['wait_minutes'] ?>m)</span>
                            </div>
                            <?php if ($oilBay && $oilBay['is_active'] && $oilBay['status'] === 'free'): ?>
                                <form action="" method="POST" style="margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign_bay">
                                    <input type="hidden" name="bay_id" value="<?= $oilBay['id'] ?>">
                                    <input type="hidden" name="order_id" value="<?= $tq['order_id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 2px 8px; background: #ea580c; border-color: #ea580c;">
                                        Put In Oil Bay
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-xs text-muted">Bay busy</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- 2. ACTIVE TECHNICAL JOB ORDERS & 2-LANE WORKFLOW -->
<!-- ======================================================== -->
<div style="margin-bottom: 24px;">
    <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-list-check" style="color: #ea580c;"></i>
        <span>Active Technical Job Orders &amp; Simultaneous Lanes</span>
    </h2>

    <?php if (empty($jobs)): ?>
        <div class="card" style="text-align: center; padding: 48px; color: var(--text-muted);">
            <div style="font-size: 42px; margin-bottom: 12px; color: #cbd5e1;"><i class="fa-solid fa-wrench"></i></div>
            <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">No Repair Jobs Assigned</h3>
            <p class="text-sm">When a car arrives in the Technical queue or is transferred from the wash, it will appear here with dual execution lanes.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <?php foreach ($jobs as $job): 
                $jId = (int)$job['id'];
                $status = $job['current_job_status'];
                $orderTasks = $tasksByOrder[$jId] ?? [];
                $checklists = $checklistsByOrder[$jId] ?? [];
                $partsReqs = $partsRequestsByOrder[$jId] ?? [];
                $items = $itemsByOrder[$jId] ?? [];

                // Filter tasks into Lane 1 and Lane 2
                $lane1Tasks = array_filter($orderTasks, fn($t) => ($t['lane'] ?? 'lane_1') === 'lane_1' && $t['task_type'] !== 'inspection');
                $lane2Tasks = array_filter($orderTasks, fn($t) => ($t['lane'] ?? '') === 'lane_2' || $t['task_type'] === 'inspection');

                // Determine parts request status
                $hasPendingParts = false;
                $hasApprovedParts = false;
                $hasRejectedParts = false;
                $lastReqText = '';

                foreach ($partsReqs as $pr) {
                    if ($pr['status'] === 'pending') {
                        $hasPendingParts = true;
                        $lastReqText = "{$pr['product_name']} (x{$pr['qty_requested']})";
                        break;
                    } elseif ($pr['status'] === 'approved') {
                        $hasApprovedParts = true;
                        $lastReqText = "{$pr['product_name']} (x{$pr['qty_requested']})";
                    } elseif ($pr['status'] === 'rejected') {
                        $hasRejectedParts = true;
                        $lastReqText = "{$pr['product_name']} - Reason: {$pr['review_reason']}";
                    }
                }

                $statusBadge = match($status) {
                    'waiting'  => ['label' => 'Waiting for Technician', 'badge' => 'badge-amber', 'icon' => 'fa-clock'],
                    'working'  => ['label' => 'Technical In Progress',  'badge' => 'badge-primary', 'icon' => 'fa-wrench'],
                    'finished' => ['label' => 'Job Order Finished',     'badge' => 'badge-success', 'icon' => 'fa-circle-check'],
                    'paid'     => ['label' => 'Completed & Paid',       'badge' => 'badge-purple', 'icon' => 'fa-dollar-sign'],
                    default    => ['label' => ucfirst($status),          'badge' => 'badge-secondary', 'icon' => 'fa-circle']
                };

                // Check mandatory inspection items completeness
                $mandTotal = 0;
                $mandPassed = 0;
                foreach ($checklists as $c) {
                    if ($c['is_mandatory']) {
                        $mandTotal++;
                        if ($c['status'] === 'pass' && !empty($c['photo_url'])) {
                            $mandPassed++;
                        }
                    }
                }
                $allMandatoryPassed = ($mandTotal > 0 && $mandTotal === $mandPassed);
            ?>
            <div class="card" style="border-top: 5px solid <?= ($status === 'working') ? '#0284c7' : (($status === 'waiting') ? '#f59e0b' : '#16a34a') ?>; padding: 20px;">
                <!-- Header Info & Actions -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                            <span style="font-family: var(--font-mono); font-size: 20px; font-weight: 800; background: var(--bg-subtle); padding: 4px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                <?= htmlspecialchars($job['plate_number']) ?>
                            </span>
                            <?= vehicleTypeBadge($job['vehicle_type']) ?>
                            <span class="badge badge-secondary" style="font-family: var(--font-mono); font-size: 11px;">
                                <i class="fa-solid fa-receipt mr-1"></i> Order #<?= htmlspecialchars($job['order_number']) ?>
                            </span>
                            <?php if (empty($job['receipt_printed_at'])): ?>
                                <span class="badge" style="background: #f59e0b; color: #fff; font-weight: 700; font-size: 11px;">
                                    <i class="fa-solid fa-receipt mr-1"></i> Receipt Pending
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($job['job_order_uid'])): ?>
                                <span class="badge badge-slate" style="font-family: var(--font-mono); font-size: 11px;">
                                    <i class="fa-solid fa-hashtag mr-1"></i> <?= htmlspecialchars($job['job_order_uid']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-muted">
                            Customer: <strong><?= htmlspecialchars($job['customer_name'] ?? 'Guest') ?></strong> • 
                            Phone: <?= htmlspecialchars($job['customer_phone'] ?? '-') ?> • 
                            Time: <?= date('H:i', strtotime($job['created_at'])) ?>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <span class="badge <?= $statusBadge['badge'] ?>" style="font-size: 13px; padding: 6px 12px;">
                            <i class="fa-solid <?= $statusBadge['icon'] ?> mr-1"></i> <?= $statusBadge['label'] ?>
                        </span>

                        <!-- Parts Request Button -->
                        <?php if ($status !== 'finished' && $status !== 'paid'): ?>
                            <button type="button" 
                                    class="btn btn-secondary btn-sm" 
                                    style="background: #ffedd5; color: #9a3412; border-color: #fed7aa; font-weight: 700;"
                                    onclick="openPartsRequestModal(<?= $jId ?>, '<?= htmlspecialchars($job['plate_number'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-boxes-packing mr-1"></i> Request Parts
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Live Parts Request Status Banner -->
                <?php if ($hasPendingParts): ?>
                    <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; color: #92400e;">
                        <div class="spinner-border spinner-border-sm" role="status" style="width: 14px; height: 14px; border: 2px solid #b45309; border-right-color: transparent; border-radius: 50%; display: inline-block; animation: spin 0.8s linear infinite;"></div>
                        <span style="font-weight: 700; font-size: 13px;">
                            <i class="fa-solid fa-hourglass-half mr-1"></i> Waiting for Parts:
                        </span>
                        <span style="font-size: 13px;"><?= htmlspecialchars($lastReqText) ?> (Pending Stock Control review in Warehouse)</span>
                    </div>
                <?php elseif ($hasApprovedParts): ?>
                    <div style="background: #dcfce7; border: 1px solid #bbf7d0; border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; color: #166534;">
                        <i class="fa-solid fa-box-open" style="font-size: 16px;"></i>
                        <span style="font-weight: 700; font-size: 13px;">
                            <i class="fa-solid fa-circle-check mr-1"></i> Parts Received:
                        </span>
                        <span style="font-size: 13px;"><?= htmlspecialchars($lastReqText) ?> released by Stock Control and ready for assembly.</span>
                    </div>
                <?php elseif ($hasRejectedParts): ?>
                    <div style="background: #fee2e2; border: 1px solid #fecaca; border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; color: #991b1b;">
                        <i class="fa-solid fa-circle-xmark" style="font-size: 16px;"></i>
                        <span style="font-weight: 700; font-size: 13px;">
                            Parts Request Rejected:
                        </span>
                        <span style="font-size: 13px;"><?= htmlspecialchars($lastReqText) ?></span>
                    </div>
                <?php endif; ?>

                <!-- SIMULTANEOUS 2-LANE WORKFLOW GRID -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 18px;">
                    <!-- ========================================== -->
                    <!-- LANE 1: MECHANICAL & OIL DRAIN / REFILL -->
                    <!-- ========================================== -->
                    <div style="background: #fafaf9; border: 1px solid #e7e5e4; border-radius: var(--radius-sm); padding: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #ea580c; padding-bottom: 8px; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 26px; height: 26px; border-radius: 6px; background: #ffedd5; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 12px;">
                                    <i class="fa-solid fa-gears"></i>
                                </div>
                                <strong style="font-size: 13.5px; color: #9a3412;">LANE 1: Mechanical / Oil Service</strong>
                            </div>
                            <span class="badge badge-secondary" style="font-size: 10px;">Simultaneous Lane</span>
                        </div>

                        <?php if (empty($lane1Tasks)): ?>
                            <div class="text-xs text-muted" style="padding: 10px; text-align: center;">No mechanical tasks for this order.</div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <?php foreach ($lane1Tasks as $t): 
                                    $tStatus = $t['status'];
                                    $isOil = ($t['task_type'] === 'oil_change');
                                    $resOil = (float)($t['reserved_oil_liters'] ?? 4.0);
                                    $actOil = (float)($t['actual_oil_used'] ?? $resOil);
                                ?>
                                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                            <div>
                                                <strong style="font-size: 13.5px; color: var(--text-main);">
                                                    <?= htmlspecialchars($t['title']) ?>
                                                </strong>
                                                <?php if ($isOil): ?>
                                                    <span class="badge badge-amber" style="font-size: 10px; margin-left: 4px;">Oil Service</span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="badge <?= ($tStatus === 'completed') ? 'badge-success' : (($tStatus === 'in_progress') ? 'badge-primary' : 'badge-slate') ?>" style="font-size: 11px;">
                                                <?= ucfirst(str_replace('_', ' ', $tStatus)) ?>
                                            </span>
                                        </div>

                                        <!-- Oil Usage & Adjustment Controls -->
                                        <?php if ($isOil): ?>
                                            <div style="background: #fffbeb; border: 1px dashed #fde68a; border-radius: 4px; padding: 8px 10px; margin-bottom: 10px; font-size: 12px;">
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                    <span class="text-muted">Reserved Capacity:</span>
                                                    <strong><?= number_format($resOil, 2) ?> Liters</strong>
                                                </div>

                                                <form action="" method="POST" style="display: flex; justify-content: space-between; align-items: center; gap: 8px; margin: 0;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="adjust_oil">
                                                    <input type="hidden" name="order_id" value="<?= $jId ?>">
                                                    <label class="text-muted" style="margin: 0; font-size: 11px;">Actual Oil Used:</label>
                                                    <div style="display: flex; align-items: center; gap: 4px;">
                                                        <input type="number" step="0.1" min="0.5" max="30" name="actual_oil_used" value="<?= number_format($actOil, 1) ?>" class="form-input" style="width: 70px; padding: 2px 6px; font-size: 12px; height: 28px; text-align: center;" <?= ($tStatus === 'completed') ? 'disabled' : '' ?>>
                                                        <span class="text-xs">L</span>
                                                        <?php if ($tStatus !== 'completed'): ?>
                                                            <button type="submit" class="btn btn-secondary btn-sm" style="font-size: 10px; padding: 2px 8px; height: 28px;" title="Adjust stock deduction to match actual oil used">
                                                                Update
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </form>
                                                <?php if ($actOil > $resOil): ?>
                                                    <div class="text-xs" style="color: #ea580c; margin-top: 4px; font-weight: 600;">
                                                        <i class="fa-solid fa-arrow-up-right-dots mr-1"></i> Extra <?= number_format($actOil - $resOil, 1) ?>L deducted from inventory ledger.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Task Actions -->
                                        <div style="display: flex; justify-content: flex-end; gap: 6px;">
                                            <?php if ($tStatus === 'waiting'): ?>
                                                <form action="" method="POST" style="margin: 0;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="start_task">
                                                    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size: 11.5px; padding: 3px 10px;">
                                                        <i class="fa-solid fa-play mr-1"></i> Start Task
                                                    </button>
                                                </form>
                                            <?php elseif ($tStatus === 'in_progress'): ?>
                                                <form action="" method="POST" style="margin: 0;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="finish_task">
                                                    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                                    <?php if ($isOil): ?>
                                                        <input type="hidden" name="actual_oil_used" value="<?= $actOil ?>">
                                                    <?php endif; ?>
                                                    <button type="submit" class="btn btn-success btn-sm" style="font-size: 11.5px; padding: 3px 10px;">
                                                        <i class="fa-solid fa-check mr-1"></i> Finish Task
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-xs text-success" style="font-weight: 700;">
                                                    <i class="fa-solid fa-circle-check mr-1"></i> Done
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ========================================== -->
                    <!-- LANE 2: GUIDED MULTI-POINT INSPECTION -->
                    <!-- ========================================== -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-sm); padding: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0284c7; padding-bottom: 8px; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 26px; height: 26px; border-radius: 6px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 12px;">
                                    <i class="fa-solid fa-clipboard-check"></i>
                                </div>
                                <strong style="font-size: 13.5px; color: #0369a1;">LANE 2: Guided Safety Inspection</strong>
                            </div>
                            <span class="badge <?= $allMandatoryPassed ? 'badge-success' : 'badge-amber' ?>" style="font-size: 10px;">
                                <?= $mandPassed ?>/<?= $mandTotal ?> Mandatory Passed
                            </span>
                        </div>

                        <!-- Checklist Items Table -->
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <?php foreach ($checklists as $chk): 
                                $isPass = ($chk['status'] === 'pass');
                                $isFail = ($chk['status'] === 'fail');
                                $hasPhoto = !empty($chk['photo_url']);
                            ?>
                                <div style="background: #fff; border: 1px solid <?= $isPass && $hasPhoto ? '#bbf7d0' : ($isFail ? '#fecaca' : '#cbd5e1') ?>; border-radius: 6px; padding: 10px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <!-- Photo Thumbnail -->
                                        <div style="width: 44px; height: 44px; border-radius: 4px; border: 1px solid var(--border); overflow: hidden; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <?php if ($hasPhoto): ?>
                                                <a href="<?= APP_URL ?>/<?= htmlspecialchars($chk['photo_url']) ?>" target="_blank" title="View Inspection Photo">
                                                    <img src="<?= APP_URL ?>/<?= htmlspecialchars($chk['photo_url']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                </a>
                                            <?php else: ?>
                                                <i class="fa-solid fa-camera text-muted" style="font-size: 14px;" title="No photo uploaded yet"></i>
                                            <?php endif; ?>
                                        </div>

                                        <div>
                                            <div style="font-size: 13px; font-weight: 700; color: var(--text-main);">
                                                <?= htmlspecialchars($chk['item_name']) ?>
                                                <?php if ($chk['is_mandatory']): ?>
                                                    <span style="color: var(--danger); font-size: 11px;" title="Mandatory checklist item">*</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-muted">
                                                Val: <strong><?= htmlspecialchars($chk['measurement_value'] ?: '-') ?></strong>
                                                <?php if (!empty($chk['checked_by_name'])): ?>
                                                    • Checked by <?= htmlspecialchars($chk['checked_by_name']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span class="badge <?= $isPass ? 'badge-success' : ($isFail ? 'badge-danger' : 'badge-slate') ?>" style="font-size: 10px;">
                                            <?= strtoupper($chk['status']) ?>
                                        </span>

                                        <?php if ($status !== 'finished' && $status !== 'paid'): ?>
                                            <button type="button" 
                                                    class="btn btn-secondary btn-sm" 
                                                    style="font-size: 11px; padding: 2px 8px; height: 26px;"
                                                    onclick="openChecklistModal(<?= $chk['id'] ?>, '<?= htmlspecialchars($chk['item_name'], ENT_QUOTES) ?>', '<?= $chk['status'] ?>', '<?= htmlspecialchars($chk['measurement_value'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($chk['notes'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($chk['photo_url'] ?? '', ENT_QUOTES) ?>')">
                                                <i class="fa-solid fa-camera mr-1"></i> Inspect
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Mechanic Notes Section -->
                <div style="margin-bottom: 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-sm); padding: 10px 12px;">
                    <form action="" method="POST" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_repair_note">
                        <input type="hidden" name="order_id" value="<?= $jId ?>">
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label class="text-xs" style="font-weight: 700; color: #92400e;">
                                <i class="fa-solid fa-note-sticky mr-1"></i> Technical Job Note:
                            </label>
                            <button type="submit" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 2px 8px; min-height: 24px;">
                                Save Note
                            </button>
                        </div>
                        <textarea name="repair_notes" class="form-textarea" rows="2" style="font-size: 13px; background: #fff;" placeholder="e.g. front brake pads replaced at 3.5mm; tire tread safe at 5mm..."><?= htmlspecialchars($job['repair_notes'] ?? '') ?></textarea>
                    </form>
                </div>

                <!-- Footer Meta & Actions -->
                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 12px; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <div class="text-xs text-muted">Technical Commission:</div>
                        <strong style="color: #ea580c; font-size: 15px;"><?= formatUSD((float)$job['commission_usd']) ?></strong>
                    </div>

                    <div style="display: flex; gap: 8px; align-items: center;">
                        <a href="<?= APP_URL ?>/index.php?view=vehicle_history&plate=<?= urlencode($job['plate_number']) ?>" class="btn btn-secondary btn-sm" title="View vehicle history">
                            <i class="fa-solid fa-clock-rotate-left mr-1"></i> History
                        </a>

                        <?php if ($status === 'waiting'): ?>
                            <form action="" method="POST" style="display: inline; margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="order_id" value="<?= $jId ?>">
                                <input type="hidden" name="new_status" value="working">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fa-solid fa-play mr-1"></i> Start Job Order
                                </button>
                            </form>
                        <?php elseif ($status === 'working'): ?>
                            <button type="button" 
                                    class="btn btn-sm" 
                                    style="background: #16a34a; color: #fff; font-weight: 700;"
                                    onclick="openMechFinishModal(0, <?= $jId ?>, '<?= htmlspecialchars($job['plate_number'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-circle-check mr-1"></i> Finish Job Order
                            </button>
                        <?php elseif ($status === 'finished'): ?>
                            <span class="text-xs" style="color: #16a34a; font-weight: 700;">
                                <i class="fa-solid fa-check-double mr-1"></i> <?= ((int)($job['is_mixed'] ?? 0) === 1) ? 'Mechanical Complete • Sent to Wash Queue' : 'Completed • Ready for Pickup' ?>
                            </span>
                        <?php elseif ($status === 'paid'): ?>
                            <span class="text-xs text-muted">
                                <i class="fa-solid fa-lock mr-1"></i> Paid &amp; Completed
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================== -->
<!-- MODAL 1: REQUEST PARTS MODAL -->
<!-- ======================================================== -->
<div id="partsRequestModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-boxes-packing" style="color: #ea580c;"></i>
                <span>Request Spare Parts from Warehouse</span>
            </h3>
            <button type="button" onclick="closePartsRequestModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_parts">
            <input type="hidden" name="order_id" id="prModalOrderId" value="">

            <div style="background: var(--bg-subtle); padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 14px; font-size: 13px;">
                Requesting parts for vehicle: <strong id="prModalPlate" style="font-family: var(--font-mono);">-</strong>
            </div>

            <div style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Select Product / Part <span style="color: var(--danger);">*</span></label>
                <select name="product_id" class="form-select" required style="width: 100%;">
                    <option value="">-- Choose part from catalog --</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars($p['name_en']) ?> (Stock: <?= (int)$p['stock_qty'] ?> <?= htmlspecialchars($p['unit'] ?? 'pcs') ?>) - <?= formatUSD((float)$p['price_usd']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Quantity <span style="color: var(--danger);">*</span></label>
                <input type="number" name="qty" class="form-input" min="1" max="100" value="1" required style="width: 100%;">
            </div>

            <div style="margin-bottom: 18px;">
                <label class="form-label">Notes for Stock Control (Optional)</label>
                <input type="text" name="notes" class="form-input" placeholder="e.g. For front axle replacement" style="width: 100%;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closePartsRequestModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #ea580c; border-color: #ea580c; font-weight: 700;">
                    <i class="fa-solid fa-paper-plane mr-1"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 2: GUIDED INSPECTION CHECKLIST ITEM UPDATE MODAL -->
<!-- ======================================================== -->
<div id="checklistModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clipboard-check" style="color: #0284c7;"></i>
                <span id="chkModalTitle">Guided Inspection Item</span>
            </h3>
            <button type="button" onclick="closeChecklistModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_checklist_item">
            <input type="hidden" name="item_id" id="chkModalItemId" value="">
            <input type="hidden" name="photo_url" id="chkModalExistingPhoto" value="">

            <div style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Inspection Status <span style="color: var(--danger);">*</span></label>
                <select name="status" id="chkModalStatus" class="form-select" required style="width: 100%;">
                    <option value="pass">PASS (Meets Safety Specs)</option>
                    <option value="fail">FAIL (Defective / Requires Service)</option>
                    <option value="pending">PENDING (Not inspected yet)</option>
                </select>
            </div>

            <div style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Measurement / Observation Value</label>
                <input type="text" name="measurement_value" id="chkModalMeasurement" class="form-input" placeholder="e.g. 5.5mm, 12.6V, No leaks" style="width: 100%;">
                <div class="text-xs text-muted" style="margin-top: 3px;">Enter exact numeric reading or condition (e.g. brake pad mm, tire tread depth).</div>
            </div>

            <div style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Inspection Photo <span style="color: var(--danger);">* (Mandatory for finish)</span></label>
                <input type="file" name="photo" accept="image/*" capture="environment" class="form-input" style="width: 100%; padding: 6px;">
                <div class="text-xs text-muted" style="margin-top: 3px;">Upload or capture clear photo of the inspected component.</div>
                <div id="chkModalPhotoPreview" style="margin-top: 6px; font-size: 11px; color: var(--success);"></div>
            </div>

            <div style="margin-bottom: 18px;">
                <label class="form-label">Technician Notes</label>
                <textarea name="notes" id="chkModalNotes" class="form-textarea" rows="2" placeholder="e.g. Outer pad worn evenly; rotors smooth."></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeChecklistModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #0284c7; border-color: #0284c7; font-weight: 700;">
                    <i class="fa-solid fa-save mr-1"></i> Save Inspection Item
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 3: FINISH TECHNICAL JOB ORDER MODAL -->
<!-- ======================================================== -->
<div id="mechFinishModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 440px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clipboard-check" style="color: #16a34a;"></i>
                <span>Finish Technical Job Order</span>
            </h3>
            <button type="button" onclick="closeMechFinishModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="finish_car">
            <input type="hidden" name="bay_id" id="mModalBayId" value="">
            <input type="hidden" name="order_id" id="mModalOrderId" value="">
            <input type="hidden" name="took_longer" id="mModalTookLonger" value="0">

            <div style="background: var(--bg-subtle); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;">
                <div style="font-size: 13px; color: var(--text-muted);">Completing Job for Vehicle:</div>
                <div style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); color: var(--text-main);" id="mModalPlate">
                    -
                </div>
            </div>

            <!-- Estimate Engine Question -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">
                    Did this technical service take longer than expected? <span style="color: var(--danger);">*</span>
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <button type="button" 
                            id="mBtnNo"
                            class="btn btn-secondary" 
                            style="padding: 10px; font-weight: 700; border: 2px solid var(--border);"
                            onclick="selectMTookLonger(0)">
                        <i class="fa-solid fa-thumbs-up mr-1" style="color: var(--success);"></i> No (On Schedule)
                    </button>
                    <button type="button" 
                            id="mBtnYes"
                            class="btn btn-secondary" 
                            style="padding: 10px; font-weight: 700; border: 2px solid var(--border);"
                            onclick="selectMTookLonger(1)">
                        <i class="fa-solid fa-clock-rotate-left mr-1" style="color: var(--warning);"></i> Yes (Took Longer)
                    </button>
                </div>
                <div class="text-xs text-muted" style="margin-top: 6px;">
                    Saved for the estimate engine to calibrate future repair and oil turnaround times.
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeMechFinishModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="mBtnSubmit" style="background: #16a34a; border-color: #16a34a;">
                    <i class="fa-solid fa-check mr-1"></i> Confirm Finish (Ready to Pay)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openPartsRequestModal(orderId, plate) {
    document.getElementById('prModalOrderId').value = orderId;
    document.getElementById('prModalPlate').textContent = plate;
    document.getElementById('partsRequestModal').style.display = 'flex';
}
function closePartsRequestModal() {
    document.getElementById('partsRequestModal').style.display = 'none';
}

function openChecklistModal(itemId, name, status, measurement, notes, photoUrl) {
    document.getElementById('chkModalItemId').value = itemId;
    document.getElementById('chkModalTitle').textContent = name;
    document.getElementById('chkModalStatus').value = status || 'pass';
    document.getElementById('chkModalMeasurement').value = measurement || '';
    document.getElementById('chkModalNotes').value = notes || '';
    document.getElementById('chkModalExistingPhoto').value = photoUrl || '';

    const preview = document.getElementById('chkModalPhotoPreview');
    if (photoUrl) {
        preview.textContent = 'Existing photo attached: ' + photoUrl;
    } else {
        preview.textContent = '';
    }

    document.getElementById('checklistModal').style.display = 'flex';
}
function closeChecklistModal() {
    document.getElementById('checklistModal').style.display = 'none';
}

function openMechFinishModal(bayId, orderId, plateNumber) {
    document.getElementById('mModalBayId').value = bayId;
    document.getElementById('mModalOrderId').value = orderId;
    document.getElementById('mModalPlate').textContent = plateNumber;
    selectMTookLonger(0);
    document.getElementById('mechFinishModal').style.display = 'flex';
}
function selectMTookLonger(val) {
    document.getElementById('mModalTookLonger').value = val;
    const btnNo = document.getElementById('mBtnNo');
    const btnYes = document.getElementById('mBtnYes');
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
function closeMechFinishModal() {
    document.getElementById('mechFinishModal').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePartsRequestModal();
        closeChecklistModal();
        closeMechFinishModal();
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
