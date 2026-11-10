<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/BayManager.php';
require_once dirname(__DIR__, 2) . '/core/SmartQueueEngine.php';

// Access: Owner, Manager, Wash Supervisor, Cashier, or anyone with bays.view / orders.list
if (!Auth::can('bays.view') && !Auth::can('orders.list') && !in_array(Auth::getRole(), ['owner', 'manager', 'wash_supervisor', 'cashier'], true)) {
    Auth::requirePermission('bays.view');
}

$pageTitle = __('nav_bays_queue', 'Bays & Queue Board');
$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

$currentUserId = (int)(Auth::getUserId() ?? 0);
$currentUserName = Auth::getFullName() ?? Auth::getUsername() ?? 'Staff';
$currentUserRole = Auth::getRole() ?? 'cashier';
$canSupervisorOverride = in_array(strtolower($currentUserRole), ['owner', 'manager', 'wash_supervisor', 'system_admin'], true) || Auth::can('bays.move_queue');

$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Assign Car to Bay
    if ($action === 'assign_bay') {
        $bayId = (int)($_POST['bay_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if ($bayId <= 0 || $orderId <= 0) {
            $message = 'Please select a valid bay and vehicle.';
            $messageType = 'danger';
        } else {
            $mgrPin = !empty($_POST['manager_pin']) ? trim((string)$_POST['manager_pin']) : null;
            $mgrUser = !empty($_POST['manager_username']) ? trim((string)$_POST['manager_username']) : null;
            $res = BayManager::assignCarToBay($pdo, $bayId, $orderId, $currentUserId, $currentUserName, $currentUserRole, $reason ?: null, $mgrPin, $mgrUser);
            if (!empty($res['success'])) {
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not assign car to bay.';
                $messageType = 'danger';
            }
        }
    }

    // 2. Finish Car (Bay becomes Free & order moves to QC or handshake)
    elseif ($action === 'finish_car') {
        $bayId = !empty($_POST['bay_id']) ? (int)$_POST['bay_id'] : null;
        $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $targetId = (int)($_POST['bay_id'] ?? $_POST['order_id'] ?? 0);
        $tookLonger = isset($_POST['took_longer']) ? (int)$_POST['took_longer'] : null;
        $reason = trim((string)($_POST['reason'] ?? ''));

        if ($targetId <= 0 && $orderId <= 0) {
            $message = 'Invalid bay or vehicle selected.';
            $messageType = 'danger';
        } else {
            $res = BayManager::finishCar($pdo, $bayId, $orderId ?: $targetId, $currentUserId, $currentUserName, $currentUserRole, $tookLonger, $reason ?: null);
            if (!empty($res['success'])) {
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not finish vehicle.';
                $messageType = 'danger';
            }
        }
    }

    // 2b. Finishing Pad QC Pass (Supervisor approves vehicle after physical inspection)
    elseif ($action === 'qc_pass') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $checklist = $_POST['checklist'] ?? [];
        $notes = trim((string)($_POST['qc_notes'] ?? ''));
        $res = BayManager::passQc($pdo, $orderId, $currentUserId, $currentUserName, $currentUserRole, $checklist, $notes ?: null);
        if (!empty($res['success'])) {
            $message = $res['message'];
            $messageType = 'success';
        } else {
            $message = $res['message'] ?? 'Could not approve QC.';
            $messageType = 'danger';
        }
    }

    // 2c. Finishing Pad QC Fail (Supervisor rejects checklist and returns vehicle to Wash Queue for rework)
    elseif ($action === 'qc_fail') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['qc_reason'] ?? ''));
        if ($reason === '') {
            $message = 'A specific failure reason is required to reject QC.';
            $messageType = 'danger';
        } else {
            $res = BayManager::failQc($pdo, $orderId, $reason, $currentUserId, $currentUserName, $currentUserRole);
            if (!empty($res['success'])) {
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not reject QC.';
                $messageType = 'danger';
            }
        }
    }

    // 2d. Customer Handover Verification (Permitted only after QC inspection passes)
    elseif ($action === 'customer_handover') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $res = BayManager::handoverToCustomer($pdo, $orderId, $currentUserId, $currentUserName, $currentUserRole);
        if (!empty($res['success'])) {
            $message = $res['message'];
            $messageType = 'success';
        } else {
            $message = $res['message'] ?? 'Could not hand over vehicle.';
            $messageType = 'danger';
        }
    }

    // 3. Reassign Bay (Supervisor Drag & Drop between bays)
    elseif ($action === 'reassign_bay') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newBayId = (int)($_POST['new_bay_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if (!$canSupervisorOverride) {
            $message = 'Permission denied: Only the supervisor, manager, or owner can reassign a bay.';
            $messageType = 'danger';
        } elseif ($reason === '') {
            $message = 'A reason is required to reassign a car to another bay.';
            $messageType = 'danger';
        } else {
            $res = BayManager::reassignBay($pdo, $orderId, $newBayId, $reason, $currentUserId, $currentUserName, $currentUserRole);
            if (!empty($res['success'])) {
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not reassign bay.';
                $messageType = 'danger';
            }
        }
    }

    // 4. Change Queue Position (Supervisor Drag & Drop in line)
    elseif ($action === 'change_queue_position' || $action === 'move_queue_car') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $targetPos = (int)($_POST['target_position'] ?? 1);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if (!$canSupervisorOverride) {
            $message = 'Permission denied: Only the supervisor, manager, or owner can change queue positions.';
            $messageType = 'danger';
        } elseif ($reason === '') {
            $message = 'A reason is required to change a car\'s place in the queue.';
            $messageType = 'danger';
        } else {
            $res = BayManager::changeQueuePosition($pdo, $orderId, $targetPos, $reason, $currentUserId, $currentUserName, $currentUserRole);
            if (!empty($res['success'])) {
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not change queue position.';
                $messageType = 'danger';
            }
        }
    }


    // 6. Mark Customer Arrived
    elseif ($action === 'customer_arrived') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            $message = 'Invalid order selected.';
            $messageType = 'danger';
        } else {
            $res = BayManager::markCustomerArrived($pdo, $orderId, $currentUserId, $currentUserName, $currentUserRole);
            if (!empty($res['success'])) {
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not mark customer as arrived.';
                $messageType = 'danger';
            }
        }
    }

    // 7. Set Delay Reason
    elseif ($action === 'set_delay_reason') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['delay_reason'] ?? ''));
        if ($reason === 'other' || empty($reason)) {
            $reason = trim((string)($_POST['custom_delay_reason'] ?? $reason));
        }
        if ($orderId <= 0 || empty($reason)) {
            $message = 'Please specify a valid order and delay reason.';
            $messageType = 'danger';
        } else {
            $ok = SmartQueueEngine::setOrderDelayReason($pdo, $orderId, $reason, $currentUserId, $currentUserName);
            if ($ok) {
                $message = 'Delay reason updated successfully.';
                $messageType = 'success';
            } else {
                $message = 'Could not update delay reason.';
                $messageType = 'danger';
            }
        }
    }

    // 8. Resolve Learning Review (Owner/Manager only)
    elseif ($action === 'resolve_duration_review') {
        if (!in_array(strtolower($currentUserRole), ['owner', 'manager'], true)) {
            $message = 'Permission denied: Only Owner or Manager can review base durations.';
            $messageType = 'danger';
        } else {
            $reviewId = (int)($_POST['review_id'] ?? 0);
            $decision = trim((string)($_POST['decision'] ?? 'dismiss'));
            $newBase = !empty($_POST['new_base_duration']) ? (int)$_POST['new_base_duration'] : null;
            $notes = trim((string)($_POST['notes'] ?? ''));

            $res = SmartQueueEngine::resolveReview($pdo, $reviewId, $decision, $newBase, $currentUserId, $currentUserName, $notes);
            if ($res['success']) {
                $message = $res['message'];
                $messageType = 'success';
            } else {
                $message = $res['message'] ?? 'Could not resolve duration review.';
                $messageType = 'danger';
            }
        }
    }
}

// Fetch live data
$bays = BayManager::getAllBays($pdo);
$queues = BayManager::getQueues($pdo);
$washQueue = $queues['wash_queue'];
$techQueue = $queues['technical_queue'];
$qcQueue = $queues['qc_queue'] ?? [];
$queueHealth = BayManager::getQueueHealth($pdo);
$historyLogs = BayManager::getHistory($pdo, 25);
$availableDelayReasons = SmartQueueEngine::getAvailableDelayReasons($pdo);
$isOwnerOrManager = in_array(strtolower($currentUserRole), ['owner', 'manager'], true);
$pendingReviews = $isOwnerOrManager ? SmartQueueEngine::getPendingReviews($pdo) : [];
$staffEfficiencies = $isOwnerOrManager ? SmartQueueEngine::getAllStaffEfficiency($pdo) : [];

// Counts
$freeWashBays = 0;
$freeTechBays = 0;
foreach ($bays as $b) {
    if ($b['is_active'] && $b['status'] === 'free') {
        if ($b['skill_tag'] === 'oil_only') {
            $freeTechBays++;
        } else {
            $freeWashBays++;
        }
    }
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<style>
/* Queue & Bay Interactive Styles */
.drag-over {
    border: 2px dashed var(--primary) !important;
    background: #f0f9ff !important;
}
.bay-card-interactive {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.bay-card-interactive:hover {
    box-shadow: var(--shadow-md);
}
.pulse-idle-alert {
    animation: pulseIdle 1.5s infinite;
}
@keyframes pulseIdle {
    0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
    70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}
.car-queue-card {
    cursor: grab;
    transition: transform 0.15s ease, background 0.15s ease;
}
.car-queue-card:active {
    cursor: grabbing;
}
.car-queue-card:hover {
    background: #f8fafc;
}
</style>

<!-- Page Header & Actions -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-warehouse" style="color: var(--primary);"></i>
            <span><?= htmlspecialchars(__('nav_bays_queue', 'Wash Supervisor & Bays Board')) ?></span>
        </h1>
        <p style="font-size: 14px; color: var(--text-muted); margin-top: 4px;">
            Shop floor control • Priority dispatching (P0–P3) • Mixed order handshakes • Idle bay alerts
        </p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleChimeAudio()" id="btnChimeToggle" title="Test / Mute Alert Chime">
            <i class="fa-solid fa-bell mr-1" id="iconChime"></i> Alert Chime: <span id="lblChimeState">On</span>
        </button>
        <a href="?view=bays_queue" class="btn btn-secondary btn-sm" title="Refresh Board">
            <i class="fa-solid fa-rotate-right mr-1"></i> Refresh
        </a>
        <?php if ($currentUserRole === 'owner'): ?>
            <a href="?view=settings&tab=bays" class="btn btn-secondary btn-sm" title="Rename or Change Bay Skill Tags in Settings">
                <i class="fa-solid fa-gear mr-1"></i> Settings
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-radius: var(--radius-md); font-weight: 600;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="font-size: 18px;"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: inherit; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- 1. QUEUE HEALTH PANEL                                    -->
<!-- ======================================================== -->
<div class="card" style="padding: 18px 22px; margin-bottom: 24px; border-radius: var(--radius-lg); background: var(--bg-card); border: 1px solid var(--border);">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="width: 34px; height: 34px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                <i class="fa-solid fa-heart-pulse"></i>
            </div>
            <div>
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin: 0;">Queue Health &amp; Floor Efficiency</h3>
                <span class="text-xs text-muted">Automated floor analytics and bottleneck detection</span>
            </div>
        </div>
        <div>
            <?php if ($queueHealth['has_idle_alert']): ?>
                <span class="badge badge-danger pulse-idle-alert" style="font-weight: 800; font-size: 12px; padding: 6px 12px;">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> Idle Bay Alert Active!
                </span>
            <?php else: ?>
                <span class="badge badge-success" style="font-weight: 700; font-size: 12px; padding: 6px 12px;">
                    <i class="fa-solid fa-circle-check mr-1"></i> Floor Operations Healthy
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Health Metrics Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
        <div style="background: var(--bg-subtle); border-radius: var(--radius-md); padding: 12px 16px;">
            <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Vehicles Waiting</div>
            <div style="font-size: 24px; font-weight: 900; color: var(--text-main); margin-top: 4px;">
                <?= $queueHealth['total_waiting'] ?>
                <span style="font-size: 13px; font-weight: 500; color: var(--text-muted); margin-left: 6px;">
                    (<?= $queueHealth['wash_waiting'] ?> wash, <?= $queueHealth['tech_waiting'] ?> tech)
                </span>
            </div>
        </div>

        <div style="background: var(--bg-subtle); border-radius: var(--radius-md); padding: 12px 16px;">
            <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Average Wait Time</div>
            <div style="font-size: 24px; font-weight: 900; color: <?= $queueHealth['avg_wait_minutes'] > 30 ? 'var(--danger)' : 'var(--text-main)' ?>; margin-top: 4px;">
                <?= $queueHealth['avg_wait_minutes'] ?> <span style="font-size: 14px; font-weight: 600;">mins</span>
            </div>
        </div>

        <div style="background: <?= $queueHealth['bottleneck_bay'] ? '#fff1f2' : 'var(--bg-subtle)' ?>; border: <?= $queueHealth['bottleneck_bay'] ? '1px solid #fecdd3' : '1px solid transparent' ?>; border-radius: var(--radius-md); padding: 12px 16px;">
            <div class="text-xs" style="color: <?= $queueHealth['bottleneck_bay'] ? '#be123c' : 'var(--text-muted)' ?>; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">
                <i class="fa-solid fa-hourglass-half mr-1"></i> Bottleneck Bay
            </div>
            <div style="font-size: 16px; font-weight: 800; color: <?= $queueHealth['bottleneck_bay'] ? '#9f1239' : 'var(--text-main)' ?>; margin-top: 6px;">
                <?php if ($queueHealth['bottleneck_bay']): ?>
                    <?= htmlspecialchars($queueHealth['bottleneck_bay']['bay_name']) ?>
                    <span style="font-size: 12px; font-weight: 700; background: #be123c; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">
                        <?= (int)$queueHealth['bottleneck_bay']['duration_minutes'] ?>m in bay
                    </span>
                <?php else: ?>
                    <span style="color: var(--text-muted); font-size: 14px; font-weight: 500;">No bottlenecks detected</span>
                <?php endif; ?>
            </div>
        </div>

        <div style="background: var(--bg-subtle); border-radius: var(--radius-md); padding: 12px 16px;">
            <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Available Bays</div>
            <div style="font-size: 22px; font-weight: 900; color: var(--success); margin-top: 4px;">
                <?= ($freeWashBays + $freeTechBays) ?> <span style="font-size: 13px; font-weight: 500; color: var(--text-muted);">/ <?= count($bays) ?> Total</span>
            </div>
        </div>
    </div>

    <!-- Critical Idle Alert Banner if bay idle > 5 min while matching cars waiting -->
    <?php if ($queueHealth['has_idle_alert']): ?>
        <div style="margin-top: 16px; background: #fee2e2; border: 2px solid #ef4444; border-radius: var(--radius-md); padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 50%; background: #ef4444; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px;" class="pulse-idle-alert">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <strong style="color: #991b1b; font-size: 15px;">CRITICAL IDLE ALERT: Empty Bays While Customers Wait!</strong>
                    <div style="font-size: 13px; color: #b91c1c; margin-top: 2px;">
                        <?php foreach ($queueHealth['idle_alert_bays'] as $ib): ?>
                            <strong><?= htmlspecialchars($ib['bay_name']) ?></strong> (Tag: <?= htmlspecialchars($ib['skill_tag']) ?>) has been idle for <strong><?= $ib['idle_minutes'] ?> mins</strong> while <strong><?= $ib['matching_waiting'] ?> matching car(s)</strong> are waiting! &nbsp;
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-sm" style="background: #b91c1c; color: #fff; font-weight: 700;" onclick="scrollDownToQueue()">
                Assign Next Waiting Car Now
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================== -->
<!-- 2. BAYS STATUS GRID (Bays 1-3 Wash, Bay 4 Oil Change)   -->
<!-- ======================================================== -->
<div style="margin-bottom: 32px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <h2 style="font-size: 18px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-cubes-stacked" style="color: var(--primary);"></i>
            <span>Active Bays Floor Control</span>
        </h2>
        <span class="text-xs text-muted"><i class="fa-solid fa-hand mr-1"></i> Drag cars from queue into bays, or drag between bays to reassign</span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
        <?php foreach ($bays as $b): 
            $isActive = ($b['is_active'] === 1);
            $isBusy = ($b['status'] === 'busy' && !empty($b['current_car']));
            $car = $b['current_car'];
            $skill = $b['skill_tag'];
            $isOil = ($b['bay_type'] === 'oil_change' || $skill === 'oil_only');
            
            // Skill Tag Badge Label & Color
            $skillBadge = match($skill) {
                'wash_only' => ['Wash Only', 'badge-primary'],
                'oil_only' => ['Oil Only', 'badge-amber'],
                'wash_and_oil' => ['Wash & Oil', 'badge-purple'],
                'detailing' => ['Detailing', 'badge-info'],
                default => ['Wash', 'badge-slate']
            };

            $isIdleAlertBay = false;
            if ($queueHealth['has_idle_alert']) {
                foreach ($queueHealth['idle_alert_bays'] as $ib) {
                    if ($ib['bay_id'] === $b['id']) {
                        $isIdleAlertBay = true;
                        break;
                    }
                }
            }

            $accentColor = !$isActive ? 'var(--text-light)' : ($isIdleAlertBay ? 'var(--danger)' : ($isBusy ? 'var(--warning)' : 'var(--success)'));
            $remInfo = ['remaining_minutes' => null, 'is_delayed' => false, 'delay_minutes' => 0, 'delay_reason' => null];
            if ($isBusy && $car) {
                $remInfo = SmartQueueEngine::getBayRemainingMinutes($pdo, (int)$b['id']);
            }
        ?>
            <div class="card bay-card-interactive <?= $isIdleAlertBay ? 'pulse-idle-alert' : '' ?>" 
                 id="bay_card_<?= $b['id'] ?>"
                 style="border-top: 5px solid <?= $accentColor ?>; padding: 18px; position: relative; display: flex; flex-direction: column; justify-content: space-between;"
                 ondragover="handleBayDragOver(event)"
                 ondragleave="handleBayDragLeave(event)"
                 ondrop="handleBayDrop(event, <?= $b['id'] ?>, '<?= htmlspecialchars($b['bay_name'], ENT_QUOTES) ?>', '<?= $b['status'] ?>', '<?= $skill ?>')">
                
                <div>
                    <!-- Card Top Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 38px; height: 38px; border-radius: 8px; background: <?= $isOil ? '#ffedd5' : 'var(--primary-light)' ?>; color: <?= $isOil ? '#ea580c' : 'var(--primary)' ?>; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                <i class="fa-solid <?= $isOil ? 'fa-wrench' : 'fa-car-wash' ?>"></i>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.5px;">
                                        Bay #<?= $b['bay_number'] ?>
                                    </span>
                                    <span class="badge <?= $skillBadge[1] ?>" style="font-size: 10px; padding: 1px 6px;">
                                        <?= $skillBadge[0] ?>
                                    </span>
                                </div>
                                <h3 style="font-size: 17px; font-weight: 800; color: var(--text-main); margin-top: 2px;">
                                    <?= htmlspecialchars($b['bay_name']) ?>
                                </h3>
                            </div>
                        </div>
                        <div>
                            <?php if (!$isActive): ?>
                                <span class="badge" style="background: var(--bg-subtle); color: var(--text-muted); border: 1px solid var(--border);">
                                    <i class="fa-solid fa-power-off mr-1"></i> Off
                                </span>
                            <?php elseif ($isBusy): ?>
                                <span class="badge badge-warning" style="font-weight: 700;">
                                    <i class="fa-solid fa-circle-dot mr-1"></i> Busy
                                </span>
                            <?php elseif ($isIdleAlertBay): ?>
                                <span class="badge badge-danger" style="font-weight: 800;">
                                    <i class="fa-solid fa-bell mr-1"></i> Idle Alert (<?= (int)$b['idle_minutes'] ?>m)
                                </span>
                            <?php else: ?>
                                <span class="badge badge-success" style="font-weight: 700;">
                                    <i class="fa-solid fa-circle-check mr-1"></i> Free (<?= (int)$b['idle_minutes'] ?>m)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bay Content / Vehicle Details -->
                    <div style="min-height: 90px; display: flex; flex-direction: column; justify-content: center; margin-bottom: 14px;">
                        <?php if (!$isActive): ?>
                            <div style="text-align: center; color: var(--text-muted); padding: 14px; background: var(--bg-subtle); border-radius: var(--radius-sm);">
                                <i class="fa-solid fa-ban" style="font-size: 20px; opacity: 0.5; margin-bottom: 4px;"></i>
                                <div style="font-size: 13px; font-weight: 600;">Bay is Switched Off</div>
                                <div class="text-xs">Owner can reactivate in Settings</div>
                            </div>
                        <?php elseif ($isBusy && $car): ?>
                            <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px;"
                                 draggable="true"
                                 ondragstart="handleBayCarDragStart(event, <?= $car['order_id'] ?>, '<?= htmlspecialchars($car['plate_number'], ENT_QUOTES) ?>', <?= $b['id'] ?>)">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <span style="font-family: var(--font-mono); font-size: 16px; font-weight: 800; background: var(--bg-card); padding: 2px 10px; border-radius: 4px; border: 1px solid var(--border);">
                                        <?= htmlspecialchars($car['plate_number']) ?>
                                    </span>
                                    <span style="font-size: 12px; font-weight: 700; color: var(--warning);">
                                        <i class="fa-solid fa-stopwatch mr-1"></i> <?= (int)$car['duration_minutes'] ?>m in bay
                                    </span>
                                </div>
                                <div style="font-size: 13px; font-weight: 700; color: var(--text-main);">
                                    <?= htmlspecialchars($car['service_names'] ?: 'Service') ?>
                                </div>
                                <div class="text-xs text-muted" style="margin-top: 2px;">
                                    <?= htmlspecialchars($car['customer_name']) ?> <?= $car['customer_phone'] ? '• ' . htmlspecialchars($car['customer_phone']) : '' ?>
                                </div>
                                <?php if (!empty($car['is_mixed'])): ?>
                                    <div style="margin-top: 6px;">
                                        <span class="badge badge-purple" style="font-size: 11px;">
                                            <i class="fa-solid fa-layer-group mr-1"></i> Mixed Order (Step 1 Wash)
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <!-- Smart Queue Remaining Time & Overdue / Delay Reason -->
                                <div style="display: flex; gap: 6px; align-items: center; margin-top: 8px; flex-wrap: wrap;">
                                    <?php if ($remInfo['remaining_minutes'] !== null): ?>
                                        <span class="badge badge-info" style="font-size: 11px; font-weight: 700;">
                                            <i class="fa-regular fa-clock mr-1"></i> ~<?= (int)$remInfo['remaining_minutes'] ?>m left
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($remInfo['is_delayed']): ?>
                                        <span class="badge badge-danger" style="font-size: 11px; font-weight: 800;">
                                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> Late (+<?= (int)$remInfo['delay_minutes'] ?>m)
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($remInfo['delay_reason'])): ?>
                                        <span class="badge badge-amber" style="font-size: 11px; max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($remInfo['delay_reason']) ?>">
                                            <i class="fa-solid fa-tag mr-1"></i> <?= htmlspecialchars($remInfo['delay_reason']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 1px 7px; font-size: 10px; font-weight: 700;" onclick="openDelayReasonModal(<?= $car['order_id'] ?>, '<?= htmlspecialchars($car['plate_number'], ENT_QUOTES) ?>', '<?= htmlspecialchars($remInfo['delay_reason'] ?? '', ENT_QUOTES) ?>')">
                                        <i class="fa-solid fa-pen mr-1"></i> Delay Reason
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; color: <?= $isIdleAlertBay ? 'var(--danger)' : 'var(--success)' ?>; padding: 16px; background: <?= $isIdleAlertBay ? '#fee2e2' : 'var(--success-bg)' ?>; border-radius: var(--radius-sm); border: 1px dashed <?= $isIdleAlertBay ? 'var(--danger)' : 'var(--success)' ?>;">
                                <i class="fa-solid <?= $isIdleAlertBay ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>" style="font-size: 24px; margin-bottom: 4px;"></i>
                                <div style="font-size: 14px; font-weight: 700;">
                                    <?= $isIdleAlertBay ? "Bay Empty for {$b['idle_minutes']} mins!" : "Bay is Free" ?>
                                </div>
                                <div class="text-xs text-muted">Drag next waiting car here or select below</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Bay Action Buttons -->
                <div style="border-top: 1px solid var(--border); padding-top: 12px; margin-top: 6px;">
                    <?php if (!$isActive): ?>
                        <button type="button" class="btn btn-secondary btn-sm" style="width: 100%; opacity: 0.6;" disabled>
                            Bay Inactive
                        </button>
                    <?php elseif ($isBusy && $car): ?>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" 
                                    class="btn btn-primary btn-sm" 
                                    style="flex: 1; background: var(--success); border-color: var(--success);"
                                    onclick="openFinishModal(<?= $b['id'] ?>, <?= $car['order_id'] ?>, '<?= htmlspecialchars($car['plate_number'], ENT_QUOTES) ?>', <?= (int)$car['is_mixed'] ?>, <?= $isOil ? 1 : 0 ?>)">
                                <i class="fa-solid fa-check-double mr-1"></i> 
                                <?= $isOil ? (!empty($car['is_mixed']) ? 'Finish Lift &amp; Send to Wash' : 'Finish Mechanical Job') : 'Finish Wash (Send to QC)' ?>
                            </button>
                            <?php if ($canSupervisorOverride): ?>
                                <button type="button" 
                                        class="btn btn-secondary btn-sm" 
                                        title="Reassign to another bay"
                                        onclick="openReassignModal(<?= $car['order_id'] ?>, '<?= htmlspecialchars($car['plate_number'], ENT_QUOTES) ?>', <?= $b['id'] ?>)">
                                    <i class="fa-solid fa-arrows-split-up-and-left"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Quick Assign -->
                        <?php 
                        $eligibleQueue = $isOil ? $techQueue : $washQueue;
                        ?>
                        <?php if (empty($eligibleQueue)): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="width: 100%;" disabled>
                                <i class="fa-solid fa-car mr-1"></i> No <?= $isOil ? 'Tech' : 'Wash' ?> Cars in Queue
                            </button>
                        <?php else: ?>
                            <form action="" method="POST" style="display: flex; gap: 6px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="assign_bay">
                                <input type="hidden" name="bay_id" value="<?= $b['id'] ?>">
                                <select name="order_id" class="form-select" required style="font-size: 12px; padding: 5px 8px; flex: 1;">
                                    <option value="">-- Put Next Car In --</option>
                                    <?php foreach ($eligibleQueue as $qItem): ?>
                                        <option value="<?= $qItem['order_id'] ?>">
                                            #<?= $qItem['position'] ?> [<?= $qItem['priority_code'] ?>] <?= htmlspecialchars($qItem['plate_number']) ?> (<?= htmlspecialchars($qItem['customer_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm" style="white-space: nowrap;">
                                    Assign
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ======================================================== -->
<!-- 3. QUEUES: WASH & TECHNICAL (P0-P3 PRIORITY + FIFO)     -->
<!-- ======================================================== -->
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(440px, 1fr)); gap: 24px; margin-bottom: 32px;" id="queue_section">
    
    <!-- WASH QUEUE -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 6px; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-soap"></i>
                </div>
                <div>
                    <h3 style="font-size: 17px; font-weight: 800; color: var(--text-main); margin: 0;">
                        Car Wash Queue (Priority &amp; FIFO)
                    </h3>
                    <div class="text-xs text-muted">Bays 1 to 3 • P0 VIP/Promoted &gt; P1 Appt &gt; P2 Express &gt; P3 Walk-in</div>
                </div>
            </div>
            <div>
                <span class="badge badge-primary" style="font-size: 13px; font-weight: 700; padding: 4px 10px;">
                    <?= count($washQueue) ?> waiting
                </span>
            </div>
        </div>

        <?php if (empty($washQueue)): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                <i class="fa-solid fa-square-check" style="font-size: 40px; opacity: 0.3; margin-bottom: 10px;"></i>
                <div style="font-size: 15px; font-weight: 600;">Wash Queue is Empty</div>
                <div class="text-xs">Arriving wash orders join this priority line automatically.</div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($washQueue as $q): 
                    $carBayScores = SmartQueueEngine::scoreBaysForCar($pdo, (int)$q['order_id']);
                    $bestBay = !empty($carBayScores) ? $carBayScores[0] : null;
                ?>
                    <div class="car-queue-card" 
                         style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; background: var(--bg-card); display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap;"
                         draggable="true"
                         ondragstart="handleQueueDragStart(event, <?= $q['order_id'] ?>, '<?= htmlspecialchars($q['plate_number'], ENT_QUOTES) ?>', <?= $q['position'] ?>)"
                         ondragover="handleQueueCardDragOver(event)"
                         ondrop="handleQueueCardDrop(event, <?= $q['order_id'] ?>, <?= $q['position'] ?>)">
                        
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <!-- Position Number Badge -->
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: <?= $q['position'] === 1 ? 'var(--primary)' : 'var(--bg-subtle)' ?>; color: <?= $q['position'] === 1 ? '#ffffff' : 'var(--text-main)' ?>; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: 900; font-size: 14px; border: 2px solid <?= $q['position'] === 1 ? 'var(--primary)' : 'var(--border)' ?>; flex-shrink: 0;">
                                <?= $q['position_label'] ?>
                            </div>

                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span style="font-family: var(--font-mono); font-size: 16px; font-weight: 800; background: var(--bg-subtle); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border);">
                                        <?= htmlspecialchars($q['plate_number']) ?>
                                    </span>
                                    <span class="badge <?= $q['priority_badge'] ?>" style="font-weight: 800; font-size: 11px;">
                                        <?= htmlspecialchars($q['priority_label']) ?>
                                    </span>
                                    <?= vehicleTypeBadge($q['vehicle_type']) ?>
                                    <?php if (!empty($q['is_mixed'])): ?>
                                        <span class="badge badge-purple" style="font-size: 10px;">
                                            <i class="fa-solid fa-layer-group mr-1"></i> Mixed Order
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 13px; font-weight: 700; color: var(--text-main); margin-top: 4px;">
                                    <?= htmlspecialchars($q['service_names'] ?: 'Car Wash') ?>
                                </div>
                                <div class="text-xs text-muted" style="margin-top: 2px;">
                                    <?= htmlspecialchars($q['customer_name']) ?> • Arrived: <?= date('h:i A', strtotime($q['arrived_at'])) ?> (<strong><?= $q['wait_minutes'] ?>m</strong> in queue)
                                </div>
                                <?php if ($bestBay): ?>
                                    <div style="margin-top: 4px;">
                                        <span class="badge badge-purple" style="font-size: 10.5px; font-weight: 700;">
                                            <i class="fa-solid fa-wand-magic-sparkles mr-1"></i> Suggested: Bay <?= $bestBay['bay_number'] ?> (<?= htmlspecialchars($bestBay['bay_name']) ?>)
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Queue Actions -->
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <!-- Put into Bay Dropdown -->
                            <form action="" method="POST" style="display: flex; gap: 4px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="assign_bay">
                                <input type="hidden" name="order_id" value="<?= $q['order_id'] ?>">
                                <select name="bay_id" class="form-select" required style="font-size: 12px; padding: 6px 8px;">
                                    <option value="">Put in Free Bay...</option>
                                    <?php foreach ($bays as $b): ?>
                                        <?php if ($b['skill_tag'] !== 'oil_only' && $b['is_active']): ?>
                                            <option value="<?= $b['id'] ?>" <?= $b['status'] === 'busy' ? 'disabled' : '' ?>>
                                                <?= htmlspecialchars($b['bay_name']) ?> (<?= $b['skill_tag'] ?>) <?= ($bestBay && $bestBay['bay_id'] == $b['id']) ? '★ Recommended' : ($b['status'] === 'busy' ? '(Busy)' : '(Free)') ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm" title="Put this car into selected bay">
                                    Put In
                                </button>
                            </form>

                            <!-- Change Place / Move Up (Supervisor Override) -->
                            <?php if ($canSupervisorOverride): ?>
                                <button type="button" 
                                        class="btn btn-secondary btn-sm" 
                                        title="Change position in line (Supervisor Override)"
                                        onclick="openMoveQueueModal(<?= $q['order_id'] ?>, '<?= htmlspecialchars($q['plate_number'], ENT_QUOTES) ?>', <?= $q['position'] ?>)">
                                    <i class="fa-solid fa-arrows-up-down mr-1" style="color: var(--primary);"></i> Change Place
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TECHNICAL & OIL CHANGE QUEUE -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 6px; background: #ffedd5; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-wrench"></i>
                </div>
                <div>
                    <h3 style="font-size: 17px; font-weight: 800; color: var(--text-main); margin: 0;">
                        Technical &amp; Oil Queue
                    </h3>
                    <div class="text-xs text-muted">Dedicated queue for Bay 4 (Oil / Technical)</div>
                </div>
            </div>
            <div>
                <span class="badge badge-amber" style="font-size: 13px; font-weight: 700; padding: 4px 10px;">
                    <?= count($techQueue) ?> waiting
                </span>
            </div>
        </div>

        <?php if (empty($techQueue)): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                <i class="fa-solid fa-square-check" style="font-size: 40px; opacity: 0.3; margin-bottom: 10px;"></i>
                <div style="font-size: 15px; font-weight: 600;">Technical Queue is Empty</div>
                <div class="text-xs">Cars with oil change or transferred from wash appear here.</div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($techQueue as $q): 
                    $techBayScores = SmartQueueEngine::scoreBaysForCar($pdo, (int)$q['order_id']);
                    $bestTechBay = !empty($techBayScores) ? $techBayScores[0] : null;
                ?>
                    <div class="car-queue-card" 
                         style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; background: var(--bg-card); display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap;"
                         draggable="true"
                         ondragstart="handleQueueDragStart(event, <?= $q['order_id'] ?>, '<?= htmlspecialchars($q['plate_number'], ENT_QUOTES) ?>', <?= $q['position'] ?>)"
                         ondragover="handleQueueCardDragOver(event)"
                         ondrop="handleQueueCardDrop(event, <?= $q['order_id'] ?>, <?= $q['position'] ?>)">
                        
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <!-- Position Number Badge -->
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: <?= $q['position'] === 1 ? '#ea580c' : 'var(--bg-subtle)' ?>; color: <?= $q['position'] === 1 ? '#ffffff' : 'var(--text-main)' ?>; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: 900; font-size: 14px; border: 2px solid <?= $q['position'] === 1 ? '#ea580c' : 'var(--border)' ?>; flex-shrink: 0;">
                                <?= $q['position_label'] ?>
                            </div>

                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span style="font-family: var(--font-mono); font-size: 16px; font-weight: 800; background: var(--bg-subtle); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border);">
                                        <?= htmlspecialchars($q['plate_number']) ?>
                                    </span>
                                    <span class="badge <?= $q['priority_badge'] ?>" style="font-weight: 800; font-size: 11px;">
                                        <?= htmlspecialchars($q['priority_label']) ?>
                                    </span>
                                    <?php if (!empty($q['is_mixed'])): ?>
                                        <span class="badge badge-purple" style="font-size: 10px;">
                                            <i class="fa-solid fa-layer-group mr-1"></i> Mixed Order (Step 1 Lift)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 13px; font-weight: 700; color: var(--text-main); margin-top: 4px;">
                                    <?= htmlspecialchars($q['service_names'] ?: 'Oil Change & Technical') ?>
                                </div>
                                <div class="text-xs text-muted" style="margin-top: 2px;">
                                    <?= htmlspecialchars($q['customer_name']) ?> • Arrived: <?= date('h:i A', strtotime($q['arrived_at'])) ?> (<strong><?= $q['wait_minutes'] ?>m</strong> in queue)
                                </div>
                                <?php if ($bestTechBay): ?>
                                    <div style="margin-top: 4px;">
                                        <span class="badge badge-purple" style="font-size: 10.5px; font-weight: 700;">
                                            <i class="fa-solid fa-wand-magic-sparkles mr-1"></i> Suggested: Bay <?= $bestTechBay['bay_number'] ?> (<?= htmlspecialchars($bestTechBay['bay_name']) ?>)
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Technical Queue Actions -->
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <?php 
                            $oilBay = null;
                            foreach ($bays as $b) {
                                if (in_array($b['skill_tag'], ['oil_only', 'wash_and_oil'], true)) { 
                                    $oilBay = $b; 
                                    if ($b['status'] === 'free') break; 
                                }
                            }
                            ?>
                            <?php if ($oilBay && $oilBay['is_active'] && $oilBay['status'] === 'free'): ?>
                                <form action="" method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign_bay">
                                    <input type="hidden" name="bay_id" value="<?= $oilBay['id'] ?>">
                                    <input type="hidden" name="order_id" value="<?= $q['order_id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm" style="background: #ea580c; border-color: #ea580c;">
                                        <i class="fa-solid fa-wrench mr-1"></i> Put in <?= htmlspecialchars($oilBay['bay_name']) ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm" disabled>
                                    Oil Bay Busy
                                </button>
                            <?php endif; ?>

                            <!-- Move Up / Change Place -->
                            <?php if ($canSupervisorOverride): ?>
                                <button type="button" 
                                        class="btn btn-secondary btn-sm" 
                                        title="Change position in line (Supervisor Override)"
                                        onclick="openMoveQueueModal(<?= $q['order_id'] ?>, '<?= htmlspecialchars($q['plate_number'], ENT_QUOTES) ?>', <?= $q['position'] ?>)">
                                    <i class="fa-solid fa-arrows-up-down mr-1" style="color: #ea580c;"></i> Change Place
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- FINISHING PAD (QUALITY CONTROL & SIGN-OFF) -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 6px; background: #ecfdf5; color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-clipboard-check"></i>
                </div>
                <div>
                    <h3 style="font-size: 17px; font-weight: 800; color: var(--text-main); margin: 0;">
                        Finishing Pad (QC Inspection)
                    </h3>
                    <div class="text-xs text-muted">Quality Gate • 5-point physical check before customer handover</div>
                </div>
            </div>
            <div>
                <span class="badge badge-success" style="font-size: 13px; font-weight: 700; padding: 4px 10px;">
                    <?= count($qcQueue) ?> on pad
                </span>
            </div>
        </div>

        <?php if (empty($qcQueue)): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                <i class="fa-solid fa-square-check" style="font-size: 40px; opacity: 0.3; margin-bottom: 10px;"></i>
                <div style="font-size: 15px; font-weight: 600;">Finishing Pad is Empty</div>
                <div class="text-xs">Vehicles completing wash bays appear here for final QC inspection.</div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($qcQueue as $q): ?>
                    <div class="car-queue-card" style="border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 14px; background: #f0fdf4; display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--success); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: 900; font-size: 14px; border: 2px solid var(--success); flex-shrink: 0;">
                                <?= $q['position_label'] ?>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span style="font-family: var(--font-mono); font-size: 16px; font-weight: 800; background: #fff; padding: 2px 8px; border-radius: 4px; border: 1px solid #bbf7d0;">
                                        <?= htmlspecialchars($q['plate_number']) ?>
                                    </span>
                                    <span class="badge badge-success" style="font-size: 10px; font-weight: 800;">
                                        <i class="fa-solid fa-sparkles mr-1"></i> Wash Complete • Awaiting QC
                                    </span>
                                    <?= vehicleTypeBadge($q['vehicle_type']) ?>
                                </div>
                                <div style="font-size: 13px; font-weight: 700; color: var(--text-main); margin-top: 4px;">
                                    <?= htmlspecialchars($q['service_names'] ?: 'Car Wash Service') ?>
                                </div>
                                <div class="text-xs text-muted" style="margin-top: 2px;">
                                    <?= htmlspecialchars($q['customer_name']) ?> • Arrived: <?= date('h:i A', strtotime($q['arrived_at'])) ?> (<strong><?= $q['wait_minutes'] ?>m</strong>)
                                </div>
                                <div style="margin-top: 6px; font-size: 11px; color: #166534; display: flex; gap: 8px; flex-wrap: wrap;">
                                    <span><i class="fa-solid fa-check mr-1"></i>Rims/Tires</span>
                                    <span><i class="fa-solid fa-check mr-1"></i>Windshield</span>
                                    <span><i class="fa-solid fa-check mr-1"></i>Carpets</span>
                                    <span><i class="fa-solid fa-check mr-1"></i>Sills Dry</span>
                                    <span><i class="fa-solid fa-check mr-1"></i>Appearance</span>
                                </div>
                            </div>
                        </div>

                        <!-- QC Supervisor Actions -->
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <form action="" method="POST" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="qc_pass">
                                <input type="hidden" name="order_id" value="<?= $q['order_id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm" style="background: var(--success); border-color: var(--success); font-weight: 700;" title="Confirm all 5 checklist items pass and mark vehicle Ready for Pickup">
                                    <i class="fa-solid fa-circle-check mr-1"></i> Pass QC
                                </button>
                            </form>

                            <button type="button" class="btn btn-secondary btn-sm" style="color: var(--danger); border-color: #fca5a5; font-weight: 700;" onclick="openQcFailPrompt(<?= $q['order_id'] ?>, '<?= htmlspecialchars($q['plate_number'], ENT_QUOTES) ?>')">
                                <i class="fa-solid fa-rotate-left mr-1"></i> Fail QC (Rework)
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php if ($isOwnerOrManager): ?>
<!-- ======================================================== -->
<!-- 4. DURATION LEARNING FLAGS & STAFF EFFICIENCY (OWNER/MGR)-->
<!-- ======================================================== -->
<div class="grid grid-cols-2" style="gap: 20px; margin-bottom: 32px;">
    
    <!-- Flagged Duration Reviews -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; border-radius: 6px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;">
                        Duration Learning Review Flags
                    </h3>
                    <div class="text-xs text-muted">Overdue >20% or flagged by washer</div>
                </div>
            </div>
            <span class="badge <?= empty($pendingReviews) ? 'badge-success' : 'badge-danger' ?>" style="font-size: 11px;">
                <?= count($pendingReviews) ?> Pending
            </span>
        </div>

        <?php if (empty($pendingReviews)): ?>
            <div style="text-align: center; padding: 24px; color: var(--text-muted);">
                <i class="fa-solid fa-check-double" style="font-size: 28px; opacity: 0.3; margin-bottom: 6px;"></i>
                <div style="font-size: 13px; font-weight: 600;">All Estimates Balanced</div>
                <div class="text-xs">No service &amp; vehicle sizes flagged for review right now.</div>
            </div>
        <?php else: ?>
            <div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($pendingReviews as $rv): ?>
                    <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong><?= htmlspecialchars($rv['service_name_en'] ?? 'Service') ?></strong>
                            <span class="badge badge-purple" style="font-size: 10px;"><?= htmlspecialchars($rv['vehicle_type']) ?></span>
                        </div>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            Est: <strong><?= (int)($rv['estimated_minutes'] ?? 0) ?>m</strong> • Actual: <strong style="color: var(--danger);"><?= (int)($rv['actual_minutes'] ?? 0) ?>m</strong> (Deviation: +<?= (float)($rv['deviation_percent'] ?? 0) ?>%)
                            <?= !empty($rv['took_longer_answered']) ? '• <span style="color: #b45309; font-weight: 700;">Washer noted: took longer</span>' : '' ?>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 6px; margin-top: 8px;">
                            <!-- Dismiss -->
                            <form action="" method="POST" style="display: inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resolve_duration_review">
                                <input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>">
                                <input type="hidden" name="decision" value="dismiss">
                                <button type="submit" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 3px 8px;">
                                    Dismiss
                                </button>
                            </form>
                            <!-- Accept & Adjust Base Time -->
                            <button type="button" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 3px 8px;" onclick="openAdjustBaseModal(<?= (int)$rv['id'] ?>, '<?= htmlspecialchars((string)($rv['service_name_en'] ?? 'Service'), ENT_QUOTES) ?>', <?= (int)($rv['actual_minutes'] ?? 0) ?>)">
                                <i class="fa-solid fa-sliders mr-1"></i> Adjust Base
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Staff Efficiency Rolling Average -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 32px; height: 32px; border-radius: 6px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                    <i class="fa-solid fa-user-clock"></i>
                </div>
                <div>
                    <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;">
                        Staff Speed &amp; Efficiency
                    </h3>
                    <div class="text-xs text-muted">Rolling average factor (Actual / Base)</div>
                </div>
            </div>
            <span class="badge badge-info" style="font-size: 11px;">
                <?= count($staffEfficiencies) ?> Active Staff
            </span>
        </div>

        <?php if (empty($staffEfficiencies)): ?>
            <div style="text-align: center; padding: 24px; color: var(--text-muted);">
                <i class="fa-solid fa-users-slash" style="font-size: 28px; opacity: 0.3; margin-bottom: 6px;"></i>
                <div style="font-size: 13px; font-weight: 600;">No Staff Metrics Yet</div>
                <div class="text-xs">Efficiency will automatically calculate as jobs complete.</div>
            </div>
        <?php else: ?>
            <div style="max-height: 280px; overflow-y: auto;">
                <table class="table-clean" style="width: 100%; font-size: 12px;">
                    <thead>
                        <tr>
                            <th>Staff Member</th>
                            <th>Role</th>
                            <th style="text-align: right;">Jobs</th>
                            <th style="text-align: right;">Efficiency</th>
                            <th style="text-align: right;">Pace</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffEfficiencies as $se): 
                            $eff = (float)($se['efficiency'] ?? 1.0);
                            $badgeClass = $eff <= 0.95 ? 'badge-success' : ($eff >= 1.2 ? 'badge-danger' : 'badge-info');
                            $paceLabel = $eff <= 0.95 ? 'Fast' : ($eff >= 1.2 ? 'Slower' : 'Standard');
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)($se['full_name'] ?? 'Staff Member')) ?></strong></td>
                                <td><span class="badge badge-slate" style="font-size: 10px;"><?= htmlspecialchars((string)($se['role'] ?? 'Staff')) ?></span></td>
                                <td style="text-align: right;"><?= (int)($se['completed_jobs'] ?? 0) ?></td>
                                <td style="text-align: right; font-family: var(--font-mono); font-weight: 700;">
                                    <?= number_format($eff, 2) ?>x
                                </td>
                                <td style="text-align: right;">
                                    <span class="badge <?= $badgeClass ?>" style="font-size: 10px;">
                                        <?= $paceLabel ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- 5. BAYS & QUEUE AUDIT HISTORY LOG                        -->
<!-- ======================================================== -->
<div class="card" style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
        <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-clock-rotate-left" style="color: var(--primary);"></i>
            <span>Bays &amp; Queue Assignment History</span>
        </h3>
        <span class="text-xs text-muted">Audits who put which car in which bay, when, handshakes, and reasons</span>
    </div>

    <div style="overflow-x: auto;">
        <table class="table-clean" style="width: 100%; font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 140px;">Timestamp</th>
                    <th style="width: 130px;">Action</th>
                    <th style="width: 120px;">Vehicle Plate</th>
                    <th style="width: 130px;">Bay</th>
                    <th style="width: 150px;">Staff Member</th>
                    <th>Reason / Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historyLogs)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">
                            No bay assignment or queue history recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historyLogs as $h): ?>
                        <tr>
                            <td style="font-family: var(--font-mono); color: var(--text-muted); font-size: 12px;">
                                <?= date('Y-m-d H:i:s', strtotime($h['created_at'])) ?>
                            </td>
                            <td>
                                <?php if ($h['action'] === 'assigned'): ?>
                                    <span class="badge badge-info"><i class="fa-solid fa-arrow-right-to-bracket mr-1"></i> Assigned</span>
                                <?php elseif ($h['action'] === 'finished'): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Finished</span>
                                <?php elseif ($h['action'] === 'sent_to_tech'): ?>
                                    <span class="badge badge-purple"><i class="fa-solid fa-paper-plane mr-1"></i> Sent to Tech</span>
                                <?php elseif ($h['action'] === 'received_by_tech'): ?>
                                    <span class="badge badge-amber"><i class="fa-solid fa-handshake mr-1"></i> Received Tech</span>
                                <?php elseif ($h['action'] === 'reassigned'): ?>
                                    <span class="badge badge-danger"><i class="fa-solid fa-arrows-split-up-and-left mr-1"></i> Reassigned</span>
                                <?php elseif ($h['action'] === 'queue_moved'): ?>
                                    <span class="badge badge-purple"><i class="fa-solid fa-arrow-up mr-1"></i> Moved in Line</span>
                                <?php elseif ($h['action'] === 'arrived'): ?>
                                    <span class="badge badge-warning"><i class="fa-solid fa-clock mr-1"></i> Arrived</span>
                                <?php else: ?>
                                    <span class="badge badge-slate"><?= htmlspecialchars($h['action']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family: var(--font-mono); font-weight: 700;">
                                <?= htmlspecialchars($h['plate_number']) ?>
                            </td>
                            <td style="font-weight: 600;">
                                <?= htmlspecialchars($h['bay_name'] ?: '-') ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($h['performed_by_name']) ?></strong>
                                <span class="text-xs text-muted">(<?= htmlspecialchars($h['performed_by_role']) ?>)</span>
                            </td>
                            <td style="color: var(--text-main);">
                                <?php if (!empty($h['reason'])): ?>
                                    <strong style="color: #9333ea;">Reason: <?= htmlspecialchars($h['reason']) ?></strong> — 
                                <?php endif; ?>
                                <?= htmlspecialchars($h['details'] ?? '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 1: FINISH CAR WITH ESTIMATE QUESTION               -->
<!-- ======================================================== -->
<div id="finishCarModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 460px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clipboard-check" style="color: var(--success);"></i>
                <span id="modalFinishTitle">Finish Service</span>
            </h3>
            <button type="button" onclick="closeFinishModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST" id="finishCarForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="finish_car">
            <input type="hidden" name="bay_id" id="modalFinishBayId" value="">
            <input type="hidden" name="order_id" id="modalFinishOrderId" value="">
            <input type="hidden" name="took_longer" id="modalFinishTookLonger" value="0">

            <div style="background: var(--bg-subtle); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;">
                <div style="font-size: 13px; color: var(--text-muted);">Completing Job for Vehicle:</div>
                <div style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); color: var(--text-main);" id="modalFinishPlateNumber">
                    -
                </div>
                <div class="text-xs text-muted" id="modalFinishMixedNotice" style="display: none; color: #9333ea; font-weight: 700; margin-top: 4px;">
                    <i class="fa-solid fa-layer-group mr-1"></i> Mixed Order: Finishing Lift in Bay 4 will send vehicle to Wash Queue.
                </div>
            </div>

            <!-- Estimate Engine Question -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">
                    Did this service take longer than expected? <span style="color: var(--danger);">*</span>
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <button type="button" 
                            id="btnTookLongerNo"
                            class="btn btn-secondary" 
                            style="padding: 10px; font-weight: 700; border: 2px solid var(--border);"
                            onclick="selectTookLonger(0)">
                        <i class="fa-solid fa-thumbs-up mr-1" style="color: var(--success);"></i> No (On Schedule)
                    </button>
                    <button type="button" 
                            id="btnTookLongerYes"
                            class="btn btn-secondary" 
                            style="padding: 10px; font-weight: 700; border: 2px solid var(--border);"
                            onclick="selectTookLonger(1)">
                        <i class="fa-solid fa-clock-rotate-left mr-1" style="color: var(--warning);"></i> Yes (Took Longer)
                    </button>
                </div>
                <div class="text-xs text-muted" style="margin-top: 6px;">
                    Saved for the service estimate engine to calibrate future wait times.
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeFinishModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnFinishConfirmSubmit" style="background: var(--success); border-color: var(--success);">
                    <i class="fa-solid fa-check mr-1"></i> Confirm &amp; Finish
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 2: CHANGE QUEUE PLACE (SUPERVISOR OVERRIDE)        -->
<!-- ======================================================== -->
<div id="moveQueueModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-arrows-up-down" style="color: var(--primary);"></i>
                <span>Change Place in Queue</span>
            </h3>
            <button type="button" onclick="closeMoveQueueModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_queue_position">
            <input type="hidden" name="order_id" id="modalOrderId" value="">

            <div style="background: var(--bg-subtle); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;">
                <div style="font-size: 13px; color: var(--text-muted);">Selected Vehicle:</div>
                <div style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); color: var(--text-main);" id="modalPlateNumber">
                    -
                </div>
                <div class="text-xs text-muted" id="modalCurrentPos">Current Position: -</div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Target Position in Queue</label>
                <input type="number" name="target_position" id="modalTargetPos" class="form-input" min="1" max="50" value="1" required style="width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label" style="font-weight: 700;">
                    Reason for Moving Car <span style="color: var(--danger);">*</span>
                </label>
                <input type="text" 
                       name="reason" 
                       id="modalReasonInput" 
                       class="form-input" 
                       required 
                       placeholder="e.g., VIP customer, urgent airport trip, elderly client..."
                       style="width: 100%;">
                <div class="text-xs text-muted" style="margin-top: 4px;">
                    This reason is required and will be permanently recorded in the audit history.
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeMoveQueueModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check mr-1"></i> Confirm &amp; Move
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 3: REASSIGN BAY (SUPERVISOR OVERRIDE)              -->
<!-- ======================================================== -->
<div id="reassignBayModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-arrows-split-up-and-left" style="color: var(--primary);"></i>
                <span>Reassign Car to Another Bay</span>
            </h3>
            <button type="button" onclick="closeReassignModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reassign_bay">
            <input type="hidden" name="order_id" id="reassignOrderId" value="">

            <div style="background: var(--bg-subtle); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;">
                <div style="font-size: 13px; color: var(--text-muted);">Moving Vehicle:</div>
                <div style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); color: var(--text-main);" id="reassignPlateNumber">
                    -
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Select Destination Bay</label>
                <select name="new_bay_id" id="reassignNewBayId" class="form-select" required style="width: 100%;">
                    <?php foreach ($bays as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= ($b['status'] === 'busy' || !$b['is_active']) ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($b['bay_name']) ?> (<?= $b['skill_tag'] ?>) <?= $b['status'] === 'busy' ? '- Currently Busy' : ($b['is_active'] ? '- Free' : '- Inactive') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label" style="font-weight: 700;">
                    Reason for Reassigning <span style="color: var(--danger);">*</span>
                </label>
                <input type="text" 
                       name="reason" 
                       id="reassignReasonInput" 
                       class="form-input" 
                       required 
                       placeholder="e.g., Equipment maintenance on original bay, balancing queue..."
                       style="width: 100%;">
                <div class="text-xs text-muted" style="margin-top: 4px;">
                    This reason is required and will be permanently recorded in the audit history.
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeReassignModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-arrows-split-up-and-left mr-1"></i> Confirm &amp; Reassign
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 4: DELAY REASON PICKER                             -->
<!-- ======================================================== -->
<div id="delayReasonModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clock-rotate-left" style="color: var(--warning);"></i>
                <span>Set Service Delay Reason</span>
            </h3>
            <button type="button" onclick="closeDelayReasonModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_delay_reason">
            <input type="hidden" name="order_id" id="delayOrderId" value="">

            <div style="background: var(--bg-subtle); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;">
                <div style="font-size: 13px; color: var(--text-muted);">Vehicle:</div>
                <div style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); color: var(--text-main);" id="delayPlateNumber">
                    -
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">Select Reason for Delay</label>
                <select name="delay_reason" id="delayReasonSelect" class="form-select" required style="width: 100%;" onchange="toggleCustomDelayInput(this.value)">
                    <option value="">-- Choose Reason --</option>
                    <?php foreach ($availableDelayReasons as $reasonItem): ?>
                        <option value="<?= htmlspecialchars($reasonItem) ?>"><?= htmlspecialchars($reasonItem) ?></option>
                    <?php endforeach; ?>
                    <option value="other">Other / Custom Reason...</option>
                </select>
            </div>

            <div class="form-group" id="customDelayGroup" style="display: none; margin-bottom: 20px;">
                <label class="form-label" style="font-weight: 700;">Custom Delay Reason</label>
                <input type="text" name="custom_delay_reason" id="customDelayInput" class="form-input" placeholder="Type specific delay reason..." style="width: 100%;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeDelayReasonModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: var(--warning); border-color: var(--warning); color: #000;">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Delay Reason
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 5: ADJUST BASE DURATION FROM LEARNING REVIEW       -->
<!-- ======================================================== -->
<div id="adjustBaseModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-sliders" style="color: var(--primary);"></i>
                <span>Calibrate Service Base Duration</span>
            </h3>
            <button type="button" onclick="closeAdjustBaseModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resolve_duration_review">
            <input type="hidden" name="review_id" id="adjustReviewId" value="">
            <input type="hidden" name="decision" value="update">

            <div style="background: var(--bg-subtle); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px;">
                <div style="font-size: 13px; color: var(--text-muted);">Calibrating Service:</div>
                <div style="font-size: 17px; font-weight: 800; color: var(--text-main);" id="adjustServiceName">
                    -
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 700;">New Base Duration (Minutes)</label>
                <input type="number" name="new_base_duration" id="adjustNewDuration" class="form-input" min="1" max="600" required style="width: 100%;">
                <div class="text-xs text-muted" style="margin-top: 4px;">Benchmark minutes for standard Sedan. Vehicle size multipliers automatically apply.</div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label">Review Notes (Optional)</label>
                <input type="text" name="notes" class="form-input" placeholder="e.g., Increased base wash duration due to newer foam guns..." style="width: 100%;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeAdjustBaseModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check mr-1"></i> Update Base Time
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL 6: OVERRIDE RECOMMENDED BAY REASON                 -->
<!-- ======================================================== -->
<div id="assignOverrideModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 480px; box-shadow: var(--shadow-lg); border-radius: var(--radius-md);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-triangle-exclamation" style="color: var(--warning);"></i>
                <span>Override Recommended Bay</span>
            </h3>
            <button type="button" onclick="closeAssignOverrideModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_bay">
            <input type="hidden" name="order_id" id="overrideOrderId" value="">
            <input type="hidden" name="bay_id" id="overrideBayId" value="">

            <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 12px; border-radius: var(--radius-sm); margin-bottom: 16px; color: #92400e; font-size: 13px;">
                <i class="fa-solid fa-circle-info mr-1"></i> You are assigning this car to a bay other than the system recommended bay (<strong id="overrideSuggestedBayName">-</strong>). An override reason is required.
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label" style="font-weight: 700;">Reason for Override <span style="color: var(--danger);">*</span></label>
                <input type="text" name="reason" id="overrideReasonInput" class="form-input" required placeholder="e.g., Customer requested Bay 1 nozzle..." style="width: 100%;">
                <div class="text-xs text-muted" style="margin-top: 4px;">This override will be permanently logged in the audit trail.</div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeAssignOverrideModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: var(--warning); border-color: var(--warning); color: #000;">
                    <i class="fa-solid fa-check mr-1"></i> Confirm Override &amp; Assign
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Chime Audio System
let chimeEnabled = true;
function playIdleChime() {
    if (!chimeEnabled) return;
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const now = audioCtx.currentTime;
        
        // Two-tone pleasant but alert chime (E5 -> G#5)
        const osc1 = audioCtx.createOscillator();
        const gain1 = audioCtx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(659.25, now);
        gain1.gain.setValueAtTime(0.12, now);
        gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
        osc1.connect(gain1);
        gain1.connect(audioCtx.destination);
        osc1.start(now);
        osc1.stop(now + 0.35);
        
        const osc2 = audioCtx.createOscillator();
        const gain2 = audioCtx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(830.61, now + 0.2);
        gain2.gain.setValueAtTime(0.15, now + 0.2);
        gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.65);
        osc2.connect(gain2);
        gain2.connect(audioCtx.destination);
        osc2.start(now + 0.2);
        osc2.stop(now + 0.65);
    } catch (e) {
        console.warn("Audio Context note:", e);
    }
}

function toggleChimeAudio() {
    chimeEnabled = !chimeEnabled;
    document.getElementById('lblChimeState').textContent = chimeEnabled ? 'On' : 'Muted';
    document.getElementById('iconChime').className = chimeEnabled ? 'fa-solid fa-bell mr-1' : 'fa-solid fa-bell-slash mr-1';
    if (chimeEnabled) {
        playIdleChime();
    }
}

<?php if ($queueHealth['has_idle_alert']): ?>
// Auto-chime on alert
window.addEventListener('load', function() {
    setTimeout(playIdleChime, 1000);
});
<?php endif; ?>

function scrollDownToQueue() {
    document.getElementById('queue_section').scrollIntoView({ behavior: 'smooth' });
}

// Modal 1: Finish Car
function openFinishModal(bayId, orderId, plateNumber, isMixed, isOil = 0) {
    document.getElementById('modalFinishBayId').value = bayId;
    document.getElementById('modalFinishOrderId').value = orderId;
    document.getElementById('modalFinishPlateNumber').textContent = plateNumber;
    document.getElementById('modalFinishMixedNotice').style.display = (isMixed && isOil) ? 'block' : 'none';
    
    let btnText = '<i class="fa-solid fa-check mr-1"></i> Confirm & Finish';
    if (isOil && isMixed) {
        btnText = '<i class="fa-solid fa-paper-plane mr-1"></i> Send to Wash Queue';
    } else if (!isOil) {
        btnText = '<i class="fa-solid fa-clipboard-check mr-1"></i> Send to Finishing Pad (QC)';
    }
    document.getElementById('btnFinishConfirmSubmit').innerHTML = btnText;
    selectTookLonger(0);
    document.getElementById('finishCarModal').style.display = 'flex';
}

function openQcFailPrompt(orderId, plate) {
    const reason = prompt('Vehicle ' + plate + ' - Enter QC failure reason (car will return to Wash Queue for rework):');
    if (reason && reason.trim()) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?= csrf_token() ?>';
        form.appendChild(csrfInput);

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'qc_fail';
        form.appendChild(actionInput);

        const orderInput = document.createElement('input');
        orderInput.type = 'hidden';
        orderInput.name = 'order_id';
        orderInput.value = orderId;
        form.appendChild(orderInput);

        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'qc_reason';
        reasonInput.value = reason.trim();
        form.appendChild(reasonInput);

        document.body.appendChild(form);
        form.submit();
    }
}
function selectTookLonger(val) {
    document.getElementById('modalFinishTookLonger').value = val;
    const btnNo = document.getElementById('btnTookLongerNo');
    const btnYes = document.getElementById('btnTookLongerYes');
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
function closeFinishModal() {
    document.getElementById('finishCarModal').style.display = 'none';
}

// Modal 2: Change Queue Place
function openMoveQueueModal(orderId, plateNumber, currentPos) {
    document.getElementById('modalOrderId').value = orderId;
    document.getElementById('modalPlateNumber').textContent = plateNumber;
    document.getElementById('modalCurrentPos').textContent = 'Current Position: #' + currentPos;
    document.getElementById('modalTargetPos').value = Math.max(1, currentPos - 1);
    document.getElementById('modalReasonInput').value = '';
    document.getElementById('moveQueueModal').style.display = 'flex';
    document.getElementById('modalReasonInput').focus();
}
function closeMoveQueueModal() {
    document.getElementById('moveQueueModal').style.display = 'none';
}

// Modal 3: Reassign Bay
function openReassignModal(orderId, plateNumber, currentBayId, targetBayId = null) {
    document.getElementById('reassignOrderId').value = orderId;
    document.getElementById('reassignPlateNumber').textContent = plateNumber;
    if (targetBayId) {
        document.getElementById('reassignNewBayId').value = targetBayId;
    }
    document.getElementById('reassignReasonInput').value = '';
    document.getElementById('reassignBayModal').style.display = 'flex';
    document.getElementById('reassignReasonInput').focus();
}
function closeReassignModal() {
    document.getElementById('reassignBayModal').style.display = 'none';
}

// Modal 4: Delay Reason
function openDelayReasonModal(orderId, plateNumber, currentReason) {
    document.getElementById('delayOrderId').value = orderId;
    document.getElementById('delayPlateNumber').textContent = plateNumber;
    const select = document.getElementById('delayReasonSelect');
    let found = false;
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].value === currentReason) {
            select.selectedIndex = i;
            found = true;
            break;
        }
    }
    if (!found && currentReason) {
        select.value = 'other';
        document.getElementById('customDelayGroup').style.display = 'block';
        document.getElementById('customDelayInput').value = currentReason;
    } else {
        if (!found) select.selectedIndex = 0;
        document.getElementById('customDelayGroup').style.display = 'none';
        document.getElementById('customDelayInput').value = '';
    }
    document.getElementById('delayReasonModal').style.display = 'flex';
}
function closeDelayReasonModal() {
    document.getElementById('delayReasonModal').style.display = 'none';
}
function toggleCustomDelayInput(val) {
    const grp = document.getElementById('customDelayGroup');
    const input = document.getElementById('customDelayInput');
    if (val === 'other') {
        grp.style.display = 'block';
        input.required = true;
        input.focus();
    } else {
        grp.style.display = 'none';
        input.required = false;
    }
}

// Modal 5: Adjust Base Duration
function openAdjustBaseModal(reviewId, serviceName, actualMinutes) {
    document.getElementById('adjustReviewId').value = reviewId;
    document.getElementById('adjustServiceName').textContent = serviceName;
    document.getElementById('adjustNewDuration').value = actualMinutes;
    document.getElementById('adjustBaseModal').style.display = 'flex';
    document.getElementById('adjustNewDuration').focus();
}
function closeAdjustBaseModal() {
    document.getElementById('adjustBaseModal').style.display = 'none';
}

// Modal 6: Override Recommended Bay
function openAssignOverrideModal(orderId, bayId, suggestedBayName) {
    document.getElementById('overrideOrderId').value = orderId;
    document.getElementById('overrideBayId').value = bayId;
    document.getElementById('overrideSuggestedBayName').textContent = suggestedBayName;
    document.getElementById('overrideReasonInput').value = '';
    document.getElementById('assignOverrideModal').style.display = 'flex';
    document.getElementById('overrideReasonInput').focus();
}
function closeAssignOverrideModal() {
    document.getElementById('assignOverrideModal').style.display = 'none';
}

// Trigger override modal if rejected by server
<?php if (!empty($overridePromptData)): ?>
window.addEventListener('load', function() {
    openAssignOverrideModal(
        <?= (int)$overridePromptData['order_id'] ?>,
        <?= (int)$overridePromptData['bay_id'] ?>,
        <?= json_encode($overridePromptData['suggested_bay_name']) ?>
    );
});
<?php endif; ?>

// Drag & Drop Handlers
let draggedCarData = null;

function handleQueueDragStart(e, orderId, plateNumber, position) {
    draggedCarData = { type: 'queue', orderId, plateNumber, position };
    e.dataTransfer.setData('text/plain', JSON.stringify(draggedCarData));
}

function handleBayCarDragStart(e, orderId, plateNumber, bayId) {
    draggedCarData = { type: 'bay', orderId, plateNumber, bayId };
    e.dataTransfer.setData('text/plain', JSON.stringify(draggedCarData));
}

function handleBayDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}

function handleBayDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}

function handleBayDrop(e, bayId, bayName, bayStatus, baySkill) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');

    if (!draggedCarData) return;

    if (draggedCarData.type === 'queue') {
        // Drop waiting car into a bay
        if (bayStatus === 'busy') {
            alert('Cannot drop car into ' + bayName + ' because it is currently busy!');
            return;
        }
        // Submit assign_bay
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_bay">
            <input type="hidden" name="bay_id" value="${bayId}">
            <input type="hidden" name="order_id" value="${draggedCarData.orderId}">
        `;
        document.body.appendChild(form);
        form.submit();
    } else if (draggedCarData.type === 'bay') {
        // Drop car from one bay to another
        if (draggedCarData.bayId === bayId) return; // same bay
        openReassignModal(draggedCarData.orderId, draggedCarData.plateNumber, draggedCarData.bayId, bayId);
    }
}

function handleQueueCardDragOver(e) {
    e.preventDefault();
}

function handleQueueCardDrop(e, targetOrderId, targetPosition) {
    e.preventDefault();
    if (!draggedCarData || draggedCarData.type !== 'queue') return;
    if (draggedCarData.orderId === targetOrderId) return;
    openMoveQueueModal(draggedCarData.orderId, draggedCarData.plateNumber, targetPosition);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFinishModal();
        closeMoveQueueModal();
        closeReassignModal();
        closeDelayReasonModal();
        closeAdjustBaseModal();
        closeAssignOverrideModal();
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
