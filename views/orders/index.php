<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/Loyalty.php';
require_once dirname(__DIR__, 2) . '/core/BayManager.php';
require_once dirname(__DIR__, 2) . '/core/CashierControl.php';
require_once dirname(__DIR__, 2) . '/core/StockControl.php';
require_once dirname(__DIR__, 2) . '/core/BankTransferControl.php';

Auth::requirePermission('orders.list');
$pageTitle = __('nav_orders');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';
$paidOrderId = null;

$dayLock = CashierControl::isDayLocked();

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Order Actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    // 1. Process Payment (Cash, ABA, KHQR)
    if ($action === 'process_payment' && $orderId > 0) {
        if (!empty($dayLock['locked'])) {
            $message = 'Cannot process payment: ' . $dayLock['message'];
            $messageType = 'danger';
        } else {
        $manualDiscountUsd = max(0.00, (float)($_POST['discount_usd'] ?? 0.00));
        if (!Auth::can('pos.pay_order') && !Auth::can('orders.change_status')) {
            $message = 'Permission denied: Cannot process payment.';
            $messageType = 'danger';
        } elseif ($manualDiscountUsd > 0.001 && !Auth::can('pos.discount')) {
            $message = 'Permission denied: Cannot apply discounts (pos.discount required).';
            $messageType = 'danger';
        } else {
        $payMethod = $_POST['payment_method'] ?? 'cash';
        $pointsToRedeem = max(0, (int)($_POST['points_redeemed'] ?? 0));
        $tenderedUsd = max(0.00, (float)($_POST['amount_tendered_usd'] ?? 0.00));
        $payRef = trim($_POST['payment_ref'] ?? '');
        $userId = Auth::getId() ? (int)Auth::getId() : null;

        // Fetch order details
        $ordStmt = $pdo->prepare("SELECT id, order_number, customer_id, subtotal_usd, points_redeemed, points_discount_usd FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $ordRow = $ordStmt->fetch();

        if ($ordRow) {
            $subtotalUsd = (float)$ordRow['subtotal_usd'];
            $customerId = !empty($ordRow['customer_id']) ? (int)$ordRow['customer_id'] : null;

            // Existing points redemption on order
            $pointsDiscountUsd = (float)($ordRow['points_discount_usd'] ?? 0.00);
            $actualPointsRedeemed = (int)($ordRow['points_redeemed'] ?? 0);

            $proceedPayment = true;

            if ($manualDiscountUsd > $subtotalUsd) {
                $message = "Discount ($" . number_format($manualDiscountUsd, 2) . ") cannot exceed order total ($" . number_format($subtotalUsd, 2) . "). Total cannot be below zero.";
                $messageType = 'danger';
                $proceedPayment = false;
            } elseif ($pointsToRedeem > 0 && $customerId && $actualPointsRedeemed == 0) {
                try {
                    $maxUsdAllowed = max(0.00, $subtotalUsd - $manualDiscountUsd);
                    $redResult = Loyalty::redeemPoints($pdo, $customerId, $orderId, $pointsToRedeem, $maxUsdAllowed, $userId);
                    if (!empty($redResult['success'])) {
                        $actualPointsRedeemed = (int)$redResult['points_redeemed'];
                        $pointsDiscountUsd = (float)$redResult['discount_usd'];
                    } else {
                        $message = "Cannot process payment: " . ($redResult['error'] ?? 'Loyalty points redemption failed.');
                        $messageType = 'danger';
                        $proceedPayment = false;
                    }
                } catch (Exception $e) {
                    $message = "Cannot process payment: " . $e->getMessage();
                    $messageType = 'danger';
                    $proceedPayment = false;
                }
            }

            if ($proceedPayment) {
                $totalDiscountUsd = round($manualDiscountUsd + $pointsDiscountUsd, 2);
                if ($totalDiscountUsd > $subtotalUsd) {
                    $message = "Total discount ($" . number_format($totalDiscountUsd, 2) . ") cannot exceed order subtotal ($" . number_format($subtotalUsd, 2) . "). Total cannot be below zero.";
                    $messageType = 'danger';
                    $proceedPayment = false;
                }
            }

            if ($proceedPayment) {
                $totalUsd = max(0.00, round($subtotalUsd - $totalDiscountUsd, 2));
                $totalKhr = (int)round($totalUsd * get_exchange_rate());
            
            $isBankTransfer = in_array($payMethod, ['aba', 'khqr'], true);

            if ($isBankTransfer) {
                // Bank transfer: cashier must attach a photo of the bank slip
                $slipFile = $_FILES['slip_image'] ?? null;
                $slipUpload = null;
                if (!empty($slipFile) && isset($slipFile['tmp_name']) && !empty($slipFile['tmp_name'])) {
                    $slipUpload = BankTransferControl::processSlipUpload($slipFile);
                }

                if (!$slipUpload || !$slipUpload['success']) {
                    $message = "Cannot process bank transfer: " . ($slipUpload['error'] ?? "A photo of the bank transfer slip is required for ABA and KHQR payments.");
                    $messageType = 'danger';
                    $proceedPayment = false;
                } else {
                    $slipPath = $slipUpload['file_path'];
                    if (empty($payRef)) {
                        $payRef = 'TRX-' . strtoupper(substr(md5(uniqid()), 0, 8));
                    }

                    // Update order with pending_verification (excluded from revenue reports until approved)
                    $uStmt = $pdo->prepare("
                        UPDATE orders SET
                            discount_usd = ?,
                            points_redeemed = ?,
                            points_discount_usd = ?,
                            total_usd = ?,
                            total_khr = ?,
                            payment_method = ?,
                            payment_status = 'pending_verification',
                            amount_tendered_usd = ?,
                            change_usd = 0.00,
                            payment_ref = ?,
                            bank_slip_image = ?,
                            bank_slip_uploaded_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $uStmt->execute([
                        $totalDiscountUsd, $actualPointsRedeemed, $pointsDiscountUsd,
                        $totalUsd, $totalKhr, $payMethod,
                        $totalUsd, $payRef, $slipPath, $orderId
                    ]);

                    AuditLogger::log('BANK_TRANSFER_SUBMITTED', "Bank transfer ($" . number_format($totalUsd, 2) . " via " . strtoupper($payMethod) . ") submitted for Order #{$ordRow['order_number']} with slip. Pending Manager Verification.", 'order', (string)$orderId);

                    $message = "Bank transfer of " . formatUSD($totalUsd) . " via " . strtoupper($payMethod) . " received with slip photo. Marked as 'Pending Verification' awaiting Manager approval.";
                    $messageType = 'warning';
                }
            } else {
                // Cash Payment: Immediate Finalization
                $changeUsd = ($payMethod === 'cash') ? max(0.00, $tenderedUsd - $totalUsd) : 0.00;

                if ($payMethod === 'cash' && $tenderedUsd < $totalUsd) {
                    $tenderedUsd = $totalUsd;
                    $changeUsd = 0.00;
                }

                $payUid = record_payment($pdo, $orderId, (string)($ordRow['order_number'] ?? "ORD-{$orderId}"), $totalUsd, $totalKhr, $payMethod, $payRef ?: null);
                $payRef = $payUid;

                // Update order
                $uStmt = $pdo->prepare("
                    UPDATE orders SET
                        discount_usd = ?,
                        points_redeemed = ?,
                        points_discount_usd = ?,
                        total_usd = ?,
                        total_khr = ?,
                        payment_method = ?,
                        payment_status = 'paid',
                        status = 'paid',
                        amount_tendered_usd = ?,
                        change_usd = ?,
                        payment_ref = ?,
                        paid_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $uStmt->execute([
                    $totalDiscountUsd, $actualPointsRedeemed, $pointsDiscountUsd,
                    $totalUsd, $totalKhr, $payMethod,
                    $tenderedUsd, $changeUsd, $payRef, $orderId
                ]);

                // Commit reserved stock into used
                StockControl::commitOrderStock($orderId, (string)($ordRow['order_number'] ?? "ORD-{$orderId}"), $userId);

                // Award Loyalty Points for paid order (atomic & idempotent)
                $ptsAwarded = 0;
                if ($customerId) {
                    try {
                        $ptsAwarded = Loyalty::awardEarnedPoints($orderId, $userId);
                    } catch (Exception $e) {
                        error_log("Loyalty award error on order {$orderId}: " . $e->getMessage());
                    }
                }

                // Re-sync washer commissions with final total
                $washers = $pdo->prepare("SELECT ow.id, ow.washer_id, u.commission_type, u.commission_rate FROM order_washers ow JOIN users u ON ow.washer_id = u.id WHERE ow.order_id = ?");
                $washers->execute([$orderId]);
                $wRows = $washers->fetchAll();
                $numW = count($wRows);

                if ($numW > 0) {
                    $commUpdate = $pdo->prepare("UPDATE order_washers SET commission_usd = ? WHERE id = ?");
                    foreach ($wRows as $wr) {
                        if ($wr['commission_type'] === 'percentage') {
                            $cUsd = round(($totalUsd * ((float)$wr['commission_rate'] / 100)) / $numW, 2);
                        } else {
                            $cUsd = round((float)$wr['commission_rate'] / $numW, 2);
                        }
                        $commUpdate->execute([$cUsd, $wr['id']]);
                    }
                }

                // Re-sync mechanic commissions with final total
                $mechanics = $pdo->prepare("SELECT om.id, om.mechanic_id, u.commission_type, u.commission_rate FROM order_mechanics om JOIN users u ON om.mechanic_id = u.id WHERE om.order_id = ?");
                $mechanics->execute([$orderId]);
                $mRows = $mechanics->fetchAll();
                $numM = count($mRows);

                if ($numM > 0) {
                    $mCommUpdate = $pdo->prepare("UPDATE order_mechanics SET commission_usd = ? WHERE id = ?");
                    foreach ($mRows as $mr) {
                        if ($mr['commission_type'] === 'percentage') {
                            $cUsd = round(($totalUsd * ((float)$mr['commission_rate'] / 100)) / $numM, 2);
                        } else {
                            $cUsd = round((float)$mr['commission_rate'] / $numM, 2);
                        }
                        $mCommUpdate->execute([$cUsd, $mr['id']]);
                    }
                }

                // Also update vehicle_repair_history total if entry exists
                $pdo->prepare("UPDATE vehicle_repair_history SET total_cost_usd = ? WHERE order_id = ?")->execute([$totalUsd, $orderId]);

                $paidOrderId = $orderId;
                $loyaltyMsg = ($ptsAwarded > 0) ? " Customer earned +{$ptsAwarded} loyalty pts!" : "";
                $message = "Payment of " . formatUSD($totalUsd) . " via " . strtoupper($payMethod) . " received for Order #" . $ordRow['order_number'] . "!" . $loyaltyMsg;
            }
        }
        }
        }
        }
    }

    // 1b. Approve Bank Transfer (Manager Verification)
    elseif ($action === 'approve_bank_transfer' && $orderId > 0) {
        $mgrUsername = trim($_POST['manager_username'] ?? '');
        $mgrPin = (string)($_POST['manager_pin'] ?? '');
        try {
            $res = BankTransferControl::approveTransfer($orderId, $mgrUsername, $mgrPin);
            $message = $res['message'];
            $messageType = 'success';
            $paidOrderId = $orderId; // triggers auto-print
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 1c. Reject Bank Transfer
    elseif ($action === 'reject_bank_transfer' && $orderId > 0) {
        $mgrUsername = trim($_POST['manager_username'] ?? '');
        $mgrPin = (string)($_POST['manager_pin'] ?? '');
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $res = BankTransferControl::rejectTransfer($orderId, $reason, $mgrUsername, $mgrPin);
            $message = $res['message'];
            $messageType = 'warning';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 1d. Authorize Paper Receipt Fallback
    elseif ($action === 'paper_receipt_fallback' && $orderId > 0) {
        $mgrUsername = trim($_POST['manager_username'] ?? '');
        $mgrPin = (string)($_POST['manager_pin'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        try {
            $res = BankTransferControl::authorizePaperReceiptFallback($orderId, $reason, $mgrUsername, $mgrPin, (int)Auth::getUserId());
            $message = $res['message'];
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }

    // 2. Simple Status Change (waiting -> washing -> finished)
    elseif ($action === 'change_status' && $orderId > 0) {
        if (!Auth::can('orders.change_status')) {
            $message = 'Permission denied: Cannot change order status.';
            $messageType = 'danger';
        } else {
            $newStatus = $_POST['new_status'] ?? '';
            if ($newStatus === 'finished') {
                $fRes = BayManager::finishCar($pdo, $orderId, (int)Auth::getUserId(), Auth::getFullName() ?? 'Staff', Auth::getRole() ?? 'cashier');
                $message = $fRes['message'] ?? 'Order marked as Ready to pay and bay freed.';
                $messageType = !empty($fRes['success']) ? 'success' : 'danger';
            } elseif (in_array($newStatus, ['waiting', 'washing', 'paid', 'cancelled'], true)) {
                $extraSql = "";
                if ($newStatus === 'washing') {
                    $extraSql = ", started_at = COALESCE(started_at, CURRENT_TIMESTAMP)";
                } elseif ($newStatus === 'paid') {
                    $extraSql = ", paid_at = CURRENT_TIMESTAMP, payment_status = 'paid'";
                }

                $stmt = $pdo->prepare("UPDATE orders SET status = ? {$extraSql} WHERE id = ?");
                $stmt->execute([$newStatus, $orderId]);

                // If changed to paid, award loyalty points
                if ($newStatus === 'paid') {
                    $userId = Auth::getId() ? (int)Auth::getId() : null;
                    try {
                        Loyalty::awardEarnedPoints($orderId, $userId);
                    } catch (Exception $e) {
                        error_log("Loyalty award error on order {$orderId}: " . $e->getMessage());
                    }
                }

                $message = "Order status updated to: " . strtoupper($newStatus);
            }
        }
    }

    // 3. Assign Washers
    elseif ($action === 'assign_washers' && $orderId > 0) {
        if (!Auth::can('orders.change_crew')) {
            $message = 'Permission denied: Cannot change assigned crew.';
            $messageType = 'danger';
        } else {
            $washerIds = $_POST['washers'] ?? [];
            // SOFT DELETE previous washer assignments: Never hard delete
            $pdo->prepare("UPDATE order_washers SET is_active = 0 WHERE order_id = ?")->execute([$orderId]);

            $ordStmt = $pdo->prepare("SELECT total_usd FROM orders WHERE id = ?");
            $ordStmt->execute([$orderId]);
            $totalUsd = (float)$ordStmt->fetchColumn();

            if (!empty($washerIds)) {
                $wInfoStmt = $pdo->prepare("SELECT id, commission_type, commission_rate FROM users WHERE id = ?");
                $wInsStmt = $pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd) VALUES (?, ?, ?)");
                $numWashers = count($washerIds);

                foreach ($washerIds as $wId) {
                    $wId = (int)$wId;
                    $wInfoStmt->execute([$wId]);
                    $washer = $wInfoStmt->fetch();

                    $commUsd = 0.00;
                    if ($washer) {
                        if ($washer['commission_type'] === 'percentage') {
                            $commUsd = round(($totalUsd * ((float)$washer['commission_rate'] / 100)) / $numWashers, 2);
                        } else {
                            $commUsd = round((float)$washer['commission_rate'] / $numWashers, 2);
                        }
                    }
                    $wInsStmt->execute([$orderId, $wId, $commUsd]);
                }
            }
            $message = "Washers successfully assigned!";
        }
    }

    // 4. Cancel Order: Restore stock, remove commission, reverse loyalty points, exclude from reports
    elseif ($action === 'cancel_order' && $orderId > 0) {
        if ((!Auth::can('orders.cancel') && !Auth::can('pos.cancel_order')) || !in_array(Auth::getRole(), ['owner', 'manager', 'system_admin'], true)) {
            $message = 'Permission denied: Cannot cancel order.';
            $messageType = 'danger';
        } else {
            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            if ($cancelReason === '') {
                $cancelReason = 'Customer requested cancellation';
            }

            $ordStmt = $pdo->prepare("SELECT id, order_number, status, payment_status FROM orders WHERE id = ?");
            $ordStmt->execute([$orderId]);
            $order = $ordStmt->fetch();

            if ($order) {
                if ($order['status'] === 'cancelled') {
                    $message = "Order #{$order['order_number']} is already cancelled.";
                    $messageType = 'warning';
                } else {
                    $isPaidOrder = ($order['status'] === 'paid' || $order['payment_status'] === 'paid');
                    $canProceed = true;
                    $mgrInfo = null;

                    if ($isPaidOrder) {
                        $mgrUser = trim($_POST['manager_username'] ?? (Auth::getUsername() ?? ''));
                        $mgrPin = (string)($_POST['manager_pin'] ?? '');
                        try {
                            $mgrInfo = CashierControl::verifyManagerPin($mgrUser, $mgrPin);
                        } catch (Exception $e) {
                            $message = 'Manager PIN authorization required to cancel a paid order: ' . $e->getMessage();
                            $messageType = 'danger';
                            $canProceed = false;
                        }
                    }

                    if ($canProceed) {
                        $currUser = Auth::user();
                        $cancellerName = $currUser ? "{$currUser['full_name']} (" . ucfirst($currUser['role']) . ")" : 'Staff';
                        $fullReason = "Cancelled by {$cancellerName}" . ($mgrInfo ? " [Auth by Manager: {$mgrInfo['manager_name']}]" : "") . ": {$cancelReason}";

                        // Release reserved stock or restore committed stock
                        if ($isPaidOrder) {
                            $itemsStmt = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL");
                            $itemsStmt->execute([$orderId]);
                            $items = $itemsStmt->fetchAll();

                            $restockStmt = $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
                            foreach ($items as $item) {
                                $restockStmt->execute([(int)$item['qty'], (int)$item['product_id']]);
                            }
                        } else {
                            StockControl::releaseOrderReservations($orderId, $order['order_number'], (int)Auth::getUserId(), $fullReason);
                        }

                        // Free any bay occupied by this order
                        $pdo->prepare("UPDATE bays SET status = 'free', current_order_id = NULL, occupied_at = NULL WHERE current_order_id = ?")->execute([$orderId]);

                        // Soft-cancel commissions: Never hard delete
                        $pdo->prepare("UPDATE order_washers SET is_active = 0, commission_usd = 0 WHERE order_id = ?")->execute([$orderId]);
                        $pdo->prepare("UPDATE order_mechanics SET is_active = 0, commission_usd = 0 WHERE order_id = ?")->execute([$orderId]);

                        // Reverse loyalty points (refund redeemed points & clawback earned points)
                        $userId = Auth::getId() ? (int)Auth::getId() : null;
                        try {
                            Loyalty::reversePointsForOrder($pdo, $orderId, $userId, $fullReason);
                        } catch (Exception $e) {
                            error_log("Loyalty reversal error on cancel order {$orderId}: " . $e->getMessage());
                        }

                        // Update order to cancelled
                        $cancelStmt = $pdo->prepare("
                            UPDATE orders 
                            SET status = 'cancelled', 
                                payment_status = 'cancelled', 
                                repair_status = 'cancelled', 
                                cancel_reason = ?, 
                                cancelled_at = CURRENT_TIMESTAMP, 
                                notes = CONCAT(COALESCE(notes, ''), ?) 
                            WHERE id = ?
                        ");
                        $cancelStmt->execute([$fullReason, " [{$fullReason}]", $orderId]);

                        AuditLogger::log('ORDER_CANCEL', "Cancelled order #{$order['order_number']}. Reason: {$fullReason}", 'order', (string)$orderId);
                        $message = "Order #{$order['order_number']} has been CANCELLED. Inventory stock and loyalty points were restored.";
                    }
                }
            }
        }
    }

    // 5. Process Refund (Strictly requires Manager PIN, Blocks Offline Refunds)
    elseif ($action === 'process_refund' && $orderId > 0) {
        $refundAmountUsd = (float)($_POST['refund_amount_usd'] ?? 0.00);
        $refundReason = trim($_POST['refund_reason'] ?? '');
        $mgrUsername = trim($_POST['manager_username'] ?? '');
        $mgrPin = (string)($_POST['manager_pin'] ?? '');
        $isOffline = !empty($_POST['is_offline']) && ($_POST['is_offline'] === '1' || $_POST['is_offline'] === 'true');

        if ($isOffline) {
            $message = "Offline refunds are strictly prohibited. If connection is lost, use the paper logbook signed by the Manager.";
            $messageType = 'danger';
        } else {
            try {
                $res = CashierControl::processRefund(
                    $orderId,
                    $refundAmountUsd,
                    $refundReason,
                    $mgrUsername,
                    $mgrPin,
                    (int)Auth::getUserId(),
                    true
                );
                $message = $res['message'];
                $messageType = 'success';
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Fetch active washers for assignment modal (check role flag or legacy role)
$allWashers = $pdo->query("SELECT u.id, u.full_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE (r.can_assign_washer = 1 OR u.role = 'washer') AND u.status = 'active'")->fetchAll();

// Fetch settings (for ABA / KHQR)
$settings = [];
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $setStmt->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

// Filter status tab
$selectedTab = $_GET['tab'] ?? 'all';

// Fetch orders with items, washers, mechanics, and customer loyalty info
$sql = "
    SELECT o.*,
           c.loyalty_points as cust_loyalty_points,
           c.lifetime_points as cust_lifetime_points,
           c.membership_level as cust_membership_level,
           GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ') as service_names,
           GROUP_CONCAT(DISTINCT uw.full_name SEPARATOR ', ') as washer_names,
           GROUP_CONCAT(DISTINCT um.full_name SEPARATOR ', ') as mechanic_names
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN order_washers ow ON o.id = ow.order_id
    LEFT JOIN users uw ON ow.washer_id = uw.id
    LEFT JOIN order_mechanics om ON o.id = om.order_id
    LEFT JOIN users um ON om.mechanic_id = um.id
";

if ($selectedTab === 'pending_transfers') {
    $sql .= " WHERE o.payment_status = 'pending_verification'";
} elseif ($selectedTab !== 'all') {
    $sql .= " WHERE o.status = " . $pdo->quote($selectedTab);
}

$sql .= " GROUP BY o.id ORDER BY CASE o.status 
            WHEN 'washing' THEN 1
            WHEN 'waiting' THEN 2
            WHEN 'finished' THEN 3
            WHEN 'paid' THEN 4
            ELSE 5 END, o.id DESC";

$orders = $pdo->query($sql)->fetchAll();

// Counts per status
$countsStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM orders WHERE DATE(created_at) = CURDATE() GROUP BY status");
$tabCounts = ['all' => 0, 'waiting' => 0, 'washing' => 0, 'finished' => 0, 'paid' => 0, 'cancelled' => 0, 'pending_transfers' => 0];
while ($row = $countsStmt->fetch()) {
    $tabCounts[$row['status']] = (int)$row['cnt'];
    $tabCounts['all'] += (int)$row['cnt'];
}
$pendingCntStmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'pending_verification'");
$tabCounts['pending_transfers'] = (int)$pendingCntStmt->fetchColumn();

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-list-ol mr-1" style="color: var(--primary);"></i> <?= htmlspecialchars(__('nav_orders')) ?>
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">Real-time tracking of vehicle wash status: Waiting &rarr; Washing &rarr; Finished &rarr; Paid.</p>
    </div>
    <div style="display: flex; gap: 12px;">
        <a href="<?= get_pos_url() ?>" class="btn btn-primary">
            <i class="fa-solid fa-plus mr-1"></i> New Wash Order
        </a>
    </div>
</div>

<?php if (isset($_GET['new_order'])): ?>
    <div style="background: var(--success-bg); color: var(--success); padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid fa-circle-check"></i>
        <span>Order <strong><?= htmlspecialchars($_GET['new_order']) ?></strong> has been created and placed into the wash queue!</span>
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <?php if ($paidOrderId): ?>
            <div style="display: flex; gap: 8px;">
                <a href="<?= APP_URL ?>/views/orders/receipt.php?id=<?= $paidOrderId ?>&auto_print=1" target="_blank" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-print mr-1"></i> Print Receipt (80mm)
                </a>
                <button type="button" class="btn btn-sm" style="background: #d97706; color: #fff; border-color: #d97706;"
                        onclick="openPaperFallbackModal(<?= (int)$paidOrderId ?>, '<?= (int)$paidOrderId ?>')">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> Printer Broken? (Paper Fallback)
                </button>
            </div>
            <script>
            window.addEventListener('DOMContentLoaded', () => {
                const pWin = window.open('<?= APP_URL ?>/views/orders/receipt.php?id=<?= (int)$paidOrderId ?>&auto_print=1', '_blank', 'width=450,height=650');
                if (!pWin) {
                    console.warn('Printer pop-up window was blocked by the browser.');
                }
            });
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Status Filter Tabs & Search -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px;">
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="?view=orders&tab=all" class="btn btn-sm <?= ($selectedTab === 'all') ? 'btn-primary' : 'btn-secondary' ?>">
            All Orders (<?= $tabCounts['all'] ?>)
        </a>
        <a href="?view=orders&tab=waiting" class="btn btn-sm <?= ($selectedTab === 'waiting') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid fa-clock mr-1" style="color: var(--warning);"></i> <?= htmlspecialchars(__('status_waiting')) ?> (<?= $tabCounts['waiting'] ?>)
        </a>
        <a href="?view=orders&tab=washing" class="btn btn-sm <?= ($selectedTab === 'washing') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid fa-soap mr-1" style="color: var(--info);"></i> <?= htmlspecialchars(__('status_washing')) ?> (<?= $tabCounts['washing'] ?>)
        </a>
        <a href="?view=orders&tab=finished" class="btn btn-sm <?= ($selectedTab === 'finished') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid fa-circle-check mr-1" style="color: var(--success);"></i> <?= htmlspecialchars(__('status_finished')) ?> (<?= $tabCounts['finished'] ?>)
        </a>
        <a href="?view=orders&tab=paid" class="btn btn-sm <?= ($selectedTab === 'paid') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid fa-receipt mr-1"></i> <?= htmlspecialchars(__('status_paid')) ?> (<?= $tabCounts['paid'] ?>)
        </a>
        <a href="?view=orders&tab=pending_transfers" class="btn btn-sm <?= ($selectedTab === 'pending_transfers') ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $tabCounts['pending_transfers'] > 0 ? 'border-color: #f59e0b; color: #b45309; font-weight: 700;' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left mr-1" style="color: #f59e0b;"></i> Pending Transfers (<?= $tabCounts['pending_transfers'] ?>)
        </a>
        <a href="?view=orders&tab=cancelled" class="btn btn-sm <?= ($selectedTab === 'cancelled') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid fa-ban mr-1" style="color: var(--danger);"></i> Cancelled (<?= $tabCounts['cancelled'] ?? 0 ?>)
        </a>
    </div>

    <div style="min-width: 260px;">
        <input type="text" id="tableSearchInput" class="form-input" style="min-height: 40px; padding: 6px 14px;" placeholder="Search plate or customer...">
    </div>
</div>

<!-- Orders Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table searchable-table">
            <thead>
                <tr>
                    <th style="width: 140px;">Order #</th>
                    <th style="width: 160px;">Plate / Vehicle</th>
                    <th>Customer</th>
                    <th>Services</th>
                    <th>Washers</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th style="text-align: right; width: 220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 48px; color: var(--text-muted);">
                            <i class="fa-solid fa-list-check" style="font-size: 32px; opacity: 0.3; margin-bottom: 8px;"></i>
                            <p>No orders found for this filter.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $ord): ?>
                    <tr>
                        <td>
                            <strong style="color: var(--primary);"><?= htmlspecialchars($ord['order_number']) ?></strong>
                            <div class="text-xs text-muted"><?= date('H:i', strtotime($ord['created_at'])) ?></div>
                            <?php if (($ord['source'] ?? '') === 'pos_app'): ?>
                                <div style="margin-top: 3px;">
                                    <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 10px; padding: 2px 6px;" title="Created via POS App: <?= htmlspecialchars($ord['pos_device_name'] ?? 'Counter') ?>">
                                        <i class="fa-solid fa-tablet-screen-button mr-1"></i> POS App
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-family: var(--font-mono); font-size: 15px; font-weight: 800; background: var(--bg-subtle); padding: 2px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border); display: inline-block;">
                                <?= htmlspecialchars($ord['plate_number']) ?>
                            </div>
                            <div style="margin-top: 4px;">
                                <?= vehicleTypeBadge($ord['vehicle_type']) ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars((string)($ord['customer_name'] ?? 'Walk-in Guest')) ?></div>
                            <?php if (!empty($ord['customer_phone'])): ?>
                                <div class="text-xs text-muted"><?= htmlspecialchars((string)$ord['customer_phone']) ?></div>
                            <?php else: ?>
                                <div class="text-xs text-muted">-</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-main); font-size: 13px;">
                                <?= htmlspecialchars((string)($ord['service_names'] ?? '-')) ?>
                            </div>
                            <?php if (!empty($ord['notes'])): ?>
                                <div class="text-xs text-muted" style="color: var(--warning);"><i class="fa-solid fa-note-sticky mr-1"></i> <?= htmlspecialchars((string)$ord['notes']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($ord['repair_notes'])): ?>
                                <div class="text-xs" style="color: #ea580c; margin-top: 3px;">
                                    <i class="fa-solid fa-wrench mr-1"></i> <?= htmlspecialchars((string)$ord['repair_notes']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <?php if (!empty($ord['washer_names'])): ?>
                                    <span class="badge badge-purple" style="font-size: 11px;">
                                        <i class="fa-solid fa-soap mr-1"></i> <?= htmlspecialchars((string)$ord['washer_names']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($ord['mechanic_names'])): ?>
                                    <span class="badge" style="background: #ffedd5; color: #ea580c; font-size: 11px;">
                                        <i class="fa-solid fa-wrench mr-1"></i> <?= htmlspecialchars((string)$ord['mechanic_names']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (empty($ord['washer_names']) && empty($ord['mechanic_names'])): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 4px 8px;"
                                            data-order-id="<?= (int)$ord['id'] ?>"
                                            data-plate="<?= htmlspecialchars((string)$ord['plate_number'], ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="openAssignWasherModal(this)">
                                        + Assign Washer
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div><strong style="font-size: 15px;"><?= formatUSD((float)$ord['total_usd']) ?></strong></div>
                            <div class="text-xs text-muted"><?= formatKHR((int)$ord['total_khr']) ?></div>
                        </td>
                        <td>
                            <?php if (($ord['payment_status'] ?? '') === 'pending_verification'): ?>
                                <span class="badge" style="background: #fef3c7; color: #b45309; font-weight: 700; border: 1px solid #fde68a;">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> Pending Verification
                                </span>
                                <div class="text-xs text-muted" style="margin-top: 2px;">
                                    <i class="fa-solid fa-building-columns mr-1"></i> <?= strtoupper((string)($ord['payment_method'] ?? 'Bank')) ?>
                                </div>
                            <?php else: ?>
                                <?= statusBadge($ord['status']) ?>
                                <?php if ($ord['status'] === 'paid'): ?>
                                    <div class="text-xs text-muted" style="text-transform: uppercase; font-weight: 600; margin-top: 2px;">
                                        <i class="fa-solid fa-wallet mr-1"></i> <?= htmlspecialchars((string)($ord['payment_method'] ?? 'unpaid')) ?>
                                        <?php if (!empty($ord['paper_receipt_fallback'])): ?>
                                            <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 10px; margin-left: 2px;" title="Manual Paper Receipt Fallback">Paper</span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($ord['status'] === 'cancelled' && !empty($ord['cancel_reason'])): ?>
                                    <div class="text-xs" style="color: var(--danger); margin-top: 3px;" title="<?= htmlspecialchars($ord['cancel_reason']) ?>">
                                        <i class="fa-solid fa-circle-xmark mr-1"></i> <?= htmlspecialchars(mb_strimwidth($ord['cancel_reason'], 0, 22, '...')) ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <?php if ($ord['status'] === 'cancelled'): ?>
                                    <span class="badge badge-danger" style="font-size: 11px;"><i class="fa-solid fa-ban mr-1"></i> Cancelled</span>
                                <?php else: ?>
                                    <?php if ($ord['status'] === 'waiting'): ?>
                                        <form action="" method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            <input type="hidden" name="new_status" value="washing">
                                            <button type="submit" class="btn btn-primary btn-sm" title="Start Washing">
                                                <i class="fa-solid fa-soap mr-1"></i> Wash
                                            </button>
                                        </form>
                                    <?php elseif ($ord['status'] === 'washing'): ?>
                                        <form action="" method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            <input type="hidden" name="new_status" value="finished">
                                            <button type="submit" class="btn btn-sm" style="background: var(--success); color: #fff; border-color: var(--success);" title="Finish Washing">
                                                <i class="fa-solid fa-check mr-1"></i> Done
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (($ord['payment_status'] ?? '') === 'pending_verification'): ?>
                                        <!-- Bank Slip Button -->
                                        <?php if (!empty($ord['bank_slip_image'])): ?>
                                            <button type="button" class="btn btn-secondary btn-sm" style="color: #0284c7;" title="View Bank Transfer Slip"
                                                    onclick="viewBankSlip('<?= APP_URL ?>/<?= htmlspecialchars($ord['bank_slip_image'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= formatUSD((float)$ord['total_usd']) ?>')">
                                                <i class="fa-solid fa-receipt mr-1"></i> Slip
                                            </button>
                                        <?php endif; ?>

                                        <?php if (in_array(Auth::getRole(), ['owner', 'manager', 'system_admin'], true)): ?>
                                            <!-- Manager Approve Bank Transfer -->
                                            <button type="button" class="btn btn-primary btn-sm" style="background: #16a34a; border-color: #16a34a;" title="Approve Bank Transfer (Manager PIN Required)"
                                                    onclick="openApproveModal(<?= (int)$ord['id'] ?>, '<?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= formatUSD((float)$ord['total_usd']) ?>')">
                                                <i class="fa-solid fa-circle-check mr-1"></i> Verify
                                            </button>
                                            <!-- Manager Reject Bank Transfer -->
                                            <button type="button" class="btn btn-secondary btn-sm" style="color: #dc2626;" title="Reject Bank Transfer (Manager PIN Required)"
                                                    onclick="openRejectModal(<?= (int)$ord['id'] ?>, '<?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="fa-solid fa-circle-xmark mr-1"></i> Reject
                                            </button>
                                        <?php else: ?>
                                            <span class="badge" style="background: #f1f5f9; color: #64748b; font-size: 11px;" title="Awaiting Manager Verification">
                                                <i class="fa-solid fa-hourglass-half mr-1"></i> Awaiting Mgr
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($ord['status'] !== 'paid'): ?>
                                        <!-- Checkout / Pay Button -->
                                        <button type="button" class="btn btn-primary btn-sm" style="background: #16a34a; border-color: #16a34a;"
                                                data-order="<?= htmlspecialchars(json_encode($ord, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                                                onclick="openCheckoutModal(this)">
                                            <i class="fa-solid fa-sack-dollar mr-1"></i> Pay
                                        </button>
                                    <?php else: ?>
                                        <!-- View/Print Receipt Button -->
                                        <a href="<?= APP_URL ?>/views/orders/receipt.php?id=<?= $ord['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="Print 80mm Receipt">
                                            <i class="fa-solid fa-print mr-1"></i> Receipt
                                        </a>
                                        <!-- Paper Receipt Fallback (if printer is broken) -->
                                        <?php if (empty($ord['paper_receipt_fallback'])): ?>
                                            <button type="button" class="btn btn-secondary btn-sm" style="color: #d97706;" title="Printer Broken? Paper Fallback (Manager PIN)"
                                                    onclick="openPaperFallbackModal(<?= (int)$ord['id'] ?>, '<?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="fa-solid fa-file-signature"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (in_array(Auth::getRole(), ['owner', 'manager', 'system_admin'], true)): ?>
                                            <!-- Manager Refund Button (Strictly hidden from cashiers) -->
                                            <button type="button" class="btn btn-secondary btn-sm" style="color: #dc2626;" title="Process Refund (Manager PIN Required)"
                                                    data-order-id="<?= (int)$ord['id'] ?>"
                                                    data-order-num="<?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-plate="<?= htmlspecialchars($ord['plate_number'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-total="<?= (float)$ord['total_usd'] ?>"
                                                    onclick="openRefundModal(this)">
                                                <i class="fa-solid fa-rotate-left mr-1"></i> Refund
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- Reassign Washers Button -->
                                    <button type="button" class="btn btn-secondary btn-sm" title="Reassign Washers"
                                            data-order-id="<?= (int)$ord['id'] ?>"
                                            data-plate="<?= htmlspecialchars($ord['plate_number'], ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="openAssignWasherModal(this)">
                                        <i class="fa-solid fa-user-pen"></i>
                                    </button>

                                    <!-- Cancel Order Button: Unpaid orders or Manager/Owner for paid orders -->
                                    <?php if ((Auth::can('orders.cancel') || Auth::can('pos.cancel_order')) && in_array(Auth::getRole(), ['owner', 'manager', 'system_admin'], true)): ?>
                                        <button type="button" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Cancel Order (Restores Stock & Commission)"
                                                data-order-id="<?= (int)$ord['id'] ?>"
                                                data-order-num="<?= htmlspecialchars($ord['order_number'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-plate="<?= htmlspecialchars($ord['plate_number'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-is-paid="<?= ($ord['status'] === 'paid' || $ord['payment_status'] === 'paid') ? '1' : '0' ?>"
                                                onclick="openCancelOrderModal(this)">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Assign Washers -->
<div class="modal-backdrop" id="assignWasherModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-user-group mr-1" style="color: var(--primary);"></i> Assign Washers</h3>
            <button type="button" class="modal-close" onclick="closeModal('assignWasherModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_washers">
            <input type="hidden" name="order_id" id="assign_order_id" value="">

            <div class="modal-body">
                <p id="assign_order_plate" style="font-weight: 700; font-size: 16px; margin-bottom: 16px; color: var(--text-main);"></p>
                <div class="form-group">
                    <label class="form-label">Select Washer(s) on Bay Duty:</label>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($allWashers as $w): ?>
                            <label style="display: flex; align-items: center; gap: 10px; background: var(--bg-subtle); padding: 10px 14px; border-radius: var(--radius-md); border: 1px solid var(--border); cursor: pointer;">
                                <input type="checkbox" name="washers[]" value="<?= $w['id'] ?>" style="width: 18px; height: 18px;">
                                <span style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars($w['full_name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignWasherModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check mr-1"></i> Save Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Checkout & Payment (Cash, ABA, KHQR) -->
<div class="modal-backdrop" id="checkoutModal">
    <div class="modal" style="max-width: 620px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title"><i class="fa-solid fa-cash-register mr-1" style="color: #16a34a;"></i> Order Checkout & Payment</h3>
                <div class="text-xs text-muted" id="chkOrderNum">Order #...</div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('checkoutModal')">&times;</button>
        </div>

        <form action="" method="POST" id="checkoutForm" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_payment">
            <input type="hidden" name="order_id" id="chk_order_id" value="">
            <input type="hidden" name="payment_method" id="chk_payment_method" value="cash">

            <div class="modal-body">
                <!-- Vehicle & Customer Header -->
                <div style="background: var(--bg-subtle); padding: 14px 18px; border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div id="chkPlate" style="font-family: var(--font-mono); font-size: 18px; font-weight: 800; color: var(--text-main);">2BQ-8899</div>
                        <div class="text-xs text-muted" id="chkCustomer">Sokha Meng</div>
                    </div>
                    <div style="text-align: right;">
                        <div class="text-xs text-muted">Subtotal</div>
                        <strong id="chkSubtotal" style="font-size: 16px;">$0.00</strong>
                    </div>
                </div>

                <!-- Discount & Loyalty Points Inputs -->
                <div class="grid grid-cols-2" style="gap: 16px; margin-bottom: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Manual Discount ($)</label>
                        <input type="number" step="0.25" min="0" name="discount_usd" id="chk_discount_usd" class="form-input" value="0.00" oninput="recalcCheckout()">
                    </div>
                    <div>
                        <label class="form-label">Final Amount Due</label>
                        <div style="display: flex; align-items: baseline; gap: 8px;">
                            <span id="chkTotalUsd" style="font-size: 24px; font-weight: 800; color: #16a34a;">$0.00</span>
                            <span id="chkTotalKhr" class="text-xs text-muted" style="font-weight: 600;">(0 ៛)</span>
                        </div>
                    </div>
                </div>

                <!-- Loyalty Points Redemption Section (shows when order has registered customer) -->
                <div id="chkLoyaltyBox" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div>
                            <span style="font-weight: 700; color: #1e40af; font-size: 13.5px;">
                                <i class="fa-solid fa-crown mr-1" style="color: #d97706;"></i> <span id="chkCustTier">Standard</span> Member
                            </span>
                            <span class="text-xs text-muted" style="margin-left: 6px;">
                                Available: <strong id="chkCustPoints" style="color: #1e40af;">0 pts</strong>
                            </span>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnMaxRedeem" onclick="applyMaxRedeemPoints()" style="font-size: 11px; padding: 2px 8px;">
                            Redeem Max
                        </button>
                    </div>

                    <div style="display: flex; gap: 12px; align-items: center;">
                        <div style="flex: 1;">
                            <label class="text-xs text-muted" style="display: block; margin-bottom: 4px;">Points to Redeem (<?= Loyalty::getRedeemRate() ?> pts = $1.00 credit):</label>
                            <input type="number" step="<?= Loyalty::getRedeemRate() ?>" min="0" name="points_redeemed" id="chk_points_redeemed" class="form-input" style="height: 36px; padding: 4px 10px;" value="0" oninput="recalcCheckout()">
                        </div>
                        <div style="text-align: right; min-width: 130px;">
                            <span class="text-xs text-muted" style="display: block;">Points Credit Value:</span>
                            <strong id="chkPointsCredit" style="color: var(--success); font-size: 16px;">-$0.00</strong>
                        </div>
                    </div>
                </div>

                <!-- Payment Method Selector Tabs -->
                <label class="form-label" style="margin-bottom: 10px;">Select Payment Method:</label>
                <div class="grid grid-cols-3" style="gap: 12px; margin-bottom: 20px;">
                    <button type="button" class="pay-method-btn active" id="btnPayCash" onclick="setPayMethod('cash')">
                        <i class="fa-solid fa-money-bill-1-wave" style="font-size: 22px; color: #16a34a; margin-bottom: 6px;"></i>
                        <span style="font-weight: 700; font-size: 14px;">Cash</span>
                        <span class="text-xs text-muted">សាច់ប្រាក់</span>
                    </button>

                    <button type="button" class="pay-method-btn" id="btnPayAba" onclick="setPayMethod('aba')">
                        <i class="fa-solid fa-building-columns" style="font-size: 22px; color: #0284c7; margin-bottom: 6px;"></i>
                        <span style="font-weight: 700; font-size: 14px;">ABA Bank</span>
                        <span class="text-xs text-muted">ផ្ទេរតាម ABA</span>
                    </button>

                    <button type="button" class="pay-method-btn" id="btnPayKhqr" onclick="setPayMethod('khqr')">
                        <i class="fa-solid fa-qrcode" style="font-size: 22px; color: #dc2626; margin-bottom: 6px;"></i>
                        <span style="font-weight: 700; font-size: 14px;">KHQR</span>
                        <span class="text-xs text-muted">Bakong QR</span>
                    </button>
                </div>

                <!-- Pay Option 1: Cash Tab -->
                <div id="paySectionCash" class="pay-section">
                    <div class="form-group">
                        <label class="form-label">Cash Tendered ($ USD)</label>
                        <input type="number" step="0.5" name="amount_tendered_usd" id="amount_tendered_usd" class="form-input" style="font-size: 18px; font-weight: 700;" value="0.00" oninput="calcChange()">
                    </div>

                    <!-- Quick Cash Suggestion Buttons -->
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="setQuickCash('exact')">Exact Amount</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="setQuickCash(5)">$5.00</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="setQuickCash(10)">$10.00</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="setQuickCash(20)">$20.00</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="setQuickCash(50)">$50.00</button>
                    </div>

                    <!-- Change Calculator Box -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 14px 18px; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div class="text-xs" style="color: #166534; font-weight: 600; text-transform: uppercase;">Change to Customer:</div>
                            <div id="changeUsd" style="font-size: 22px; font-weight: 800; color: #16a34a;">$0.00</div>
                        </div>
                        <div id="changeKhr" style="font-size: 15px; font-weight: 700; color: #166534;">
                            0 ៛
                        </div>
                    </div>
                </div>

                <!-- Pay Option 2: ABA Transfer Tab -->
                <div id="paySectionAba" class="pay-section" style="display: none;">
                    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: var(--radius-md); padding: 16px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="stat-icon blue" style="width: 44px; height: 44px; font-size: 20px;">
                                <i class="fa-solid fa-building-columns"></i>
                            </div>
                            <div>
                                <div style="font-weight: 700; color: var(--text-main); font-size: 15px;"><?= htmlspecialchars($settings['aba_account_name'] ?? 'CAR WASH SERVICE CO., LTD') ?></div>
                                <div style="font-family: var(--font-mono); font-size: 16px; font-weight: 800; color: #0284c7;">
                                    <?= htmlspecialchars($settings['aba_account_num'] ?? '001 234 567') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">ABA Transaction / Approval Code (Optional)</label>
                        <input type="text" name="payment_ref" id="aba_payment_ref" class="form-input" placeholder="e.g. 9845123">
                    </div>
                </div>

                <!-- Pay Option 3: KHQR Bakong Tab -->
                <div id="paySectionKhqr" class="pay-section" style="display: none; text-align: center;">
                    <div class="khqr-card" style="display: inline-block; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 24px; box-shadow: var(--shadow-md); max-width: 300px; width: 100%;">
                        <!-- Bakong Red Header -->
                        <div style="background: #dc2626; color: #fff; padding: 6px 14px; border-radius: 8px; font-weight: 800; font-size: 14px; letter-spacing: 1px; display: inline-block; margin-bottom: 12px;">
                            KHQR
                        </div>
                        <div style="font-weight: 700; font-size: 14px; color: #0f172a; margin-bottom: 2px;">
                            <?= htmlspecialchars($settings['aba_account_name'] ?? 'CAR WASH SERVICE CO., LTD') ?>
                        </div>
                        <div style="font-size: 18px; font-weight: 800; color: #dc2626; margin-bottom: 12px;">
                            <span id="khqrAmountUsd">$0.00</span> <span class="text-xs text-muted" id="khqrAmountKhr">(0 ៛)</span>
                        </div>

                        <!-- Live QR Code / Custom Counter KHQR Image -->
                        <div style="background: #fff; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; display: inline-block; margin-bottom: 10px;">
                            <?php if (!empty($settings['khqr_image'])): ?>
                                <img src="<?= APP_URL ?>/<?= htmlspecialchars($settings['khqr_image']) ?>" alt="KHQR" style="max-width: 200px; max-height: 220px; object-fit: contain; display: block; border-radius: 6px;">
                            <?php else: ?>
                                <img id="khqrQrImage" src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=KHQR_PAYMENT_SAMPLE" alt="KHQR" style="width: 160px; height: 160px; display: block;">
                            <?php endif; ?>
                        </div>

                        <div class="text-xs text-muted">
                            <i class="fa-solid fa-mobile-screen mr-1"></i> Scan with ABA Mobile or any Bakong App
                        </div>
                    </div>
                </div>

                <!-- Bank Slip Upload for ABA and KHQR Transfers -->
                <div id="bankSlipSection" style="display: none; margin-top: 16px; background: #fffbeb; border: 1px dashed #f59e0b; border-radius: var(--radius-md); padding: 14px;">
                    <label class="form-label" style="font-weight: 700; color: #92400e;">
                        <i class="fa-solid fa-camera mr-1"></i> Bank Transfer Slip Photo <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="file" name="slip_image" id="bank_slip_image" class="form-input" accept="image/jpeg,image/png,image/webp" style="background: #fff;" onchange="previewSlip(this)">
                    <div id="slipPreviewContainer" style="display: none; margin-top: 10px; text-align: center;">
                        <img id="slipPreviewImg" src="" alt="Slip Preview" style="max-height: 160px; max-width: 100%; border-radius: 6px; border: 1px solid #cbd5e1; box-shadow: var(--shadow-sm);">
                    </div>
                    <div class="text-xs" style="color: #92400e; margin-top: 6px; line-height: 1.4;">
                        <i class="fa-solid fa-shield-halved mr-1"></i> <strong>Bank Verification Rule:</strong> Attaching a real customer payment receipt/screenshot is required. The order will be marked <strong>Pending Verification</strong> until verified by a Manager against the bank app.
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('checkoutModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #16a34a; border-color: #16a34a;" id="btnConfirmPayment">
                    <i class="fa-solid fa-circle-check mr-1"></i> Confirm & Collect Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Cancel Order -->
<?php if ((Auth::can('orders.cancel') || Auth::can('pos.cancel_order')) && in_array(Auth::getRole(), ['owner', 'manager', 'system_admin'], true)): ?>
<div class="modal-backdrop" id="cancelOrderModal">
    <div class="modal" style="max-width: 460px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: var(--danger);"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Cancel Order</h3>
            <button type="button" class="modal-close" onclick="closeModal('cancelOrderModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" name="order_id" id="cancel_order_id" value="">

            <div class="modal-body">
                <p id="cancel_order_label" style="font-weight: 700; font-size: 15px; margin-bottom: 12px; color: var(--text-main);"></p>
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: 12px; margin-bottom: 16px; font-size: 13px; color: #991b1b; line-height: 1.5;">
                    <i class="fa-solid fa-circle-exclamation mr-1"></i>
                    <strong>Important:</strong> Cancelling this order will:
                    <ul style="margin: 6px 0 0 18px; padding: 0;">
                        <li>Restore any deducted products & spare parts to stock</li>
                        <li>Remove washer and mechanic commissions</li>
                        <li>Exclude this order from all revenue and income reports</li>
                    </ul>
                </div>
                <div class="form-group">
                    <label class="form-label">Cancellation Reason *</label>
                    <input type="text" name="cancel_reason" id="cancel_reason" class="form-input" placeholder="e.g. Customer changed mind, wrong vehicle" required>
                </div>

                <!-- Manager PIN Confirmation for Paid Orders -->
                <div id="cancel_manager_pin_box" style="display: none; background: #fff7ed; border: 1px solid #fed7aa; border-radius: var(--radius-md); padding: 14px; margin-top: 14px;">
                    <div style="font-weight: 700; color: #ea580c; font-size: 13px; margin-bottom: 8px;">
                        <i class="fa-solid fa-user-lock mr-1"></i> Paid Order: Manager Authorization Required
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label class="form-label text-xs">Manager Username</label>
                        <input type="text" name="manager_username" id="cancel_manager_username" class="form-input" value="<?= htmlspecialchars(Auth::getUsername() ?? '') ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label text-xs">Manager 6-digit PIN *</label>
                        <input type="password" maxlength="6" name="manager_pin" id="cancel_manager_pin" class="form-input" placeholder="6-digit PIN" style="font-family: var(--font-mono); letter-spacing: 4px;">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancelOrderModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-ban mr-1"></i> Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- Modal: Process Refund / Void (Requires Manager PIN, Blocks Offline) -->
<div class="modal-backdrop" id="refundModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #dc2626;"><i class="fa-solid fa-rotate-left mr-1"></i> Process Order Refund</h3>
            <button type="button" class="modal-close" onclick="closeModal('refundModal')">&times;</button>
        </div>
        <form action="" method="POST" id="refundForm" onsubmit="return validateRefundOnline();">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_refund">
            <input type="hidden" name="order_id" id="refund_order_id" value="">
            <input type="hidden" name="is_offline" id="refund_is_offline" value="0">

            <div class="modal-body">
                <div id="refund_order_label" style="font-weight: 700; font-size: 15px; margin-bottom: 12px; color: var(--text-main);"></div>

                <!-- Offline Alert Warning -->
                <div id="offlineRefundWarning" style="display: none; background: #fef2f2; border: 2px solid #ef4444; border-radius: var(--radius-md); padding: 14px; color: #991b1b; font-size: 13.5px; line-height: 1.5; margin-bottom: 14px;">
                    <i class="fa-solid fa-triangle-exclamation mr-1" style="font-size: 16px;"></i>
                    <strong>Offline refunds are not allowed!</strong> If the connection is lost, use the paper logbook signed by the Manager.
                </div>

                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center;">
                    <span class="text-muted">Original Order Paid Amount:</span>
                    <strong id="refund_total_display" style="font-size: 16px; color: #16a34a;">$0.00</strong>
                </div>

                <div class="form-group">
                    <label class="form-label">Refund Amount USD ($) *</label>
                    <input type="number" step="0.01" min="0.01" name="refund_amount_usd" id="refund_amount_usd" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason for Refund *</label>
                    <input type="text" name="refund_reason" id="refund_reason" class="form-input" placeholder="e.g. Dissatisfied customer, billing mistake" required>
                </div>

                <!-- Manager PIN Authorization -->
                <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: var(--radius-md); padding: 14px; margin-bottom: 0;">
                    <div style="font-weight: 700; color: #ea580c; font-size: 13px; margin-bottom: 8px;">
                        <i class="fa-solid fa-user-lock mr-1"></i> Manager Confirmation (Manager types PIN on screen)
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label class="form-label text-xs">Manager Username *</label>
                        <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars(in_array(Auth::getRole(), ['owner', 'manager'], true) ? (Auth::getUsername() ?? '') : '') ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label text-xs">Manager 6-digit PIN *</label>
                        <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('refundModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-danger" id="btnSubmitRefund"><i class="fa-solid fa-rotate-left mr-1"></i> Authorize &amp; Process Refund</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: View Bank Transfer Slip -->
<div class="modal-backdrop" id="viewSlipModal">
    <div class="modal" style="max-width: 520px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title"><i class="fa-solid fa-receipt mr-1 text-primary"></i> Bank Transfer Slip</h3>
                <div class="text-xs text-muted" id="viewSlipSubtitle">Order #</div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('viewSlipModal')">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center;">
            <div id="slipImageContainer" style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px; margin-bottom: 12px; min-height: 220px; display: flex; align-items: center; justify-content: center;">
                <img id="viewSlipImage" src="" alt="Bank Transfer Slip" style="max-height: 480px; max-width: 100%; border-radius: 6px; box-shadow: var(--shadow-sm);">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('viewSlipModal')">Close</button>
            <a id="downloadSlipBtn" href="#" target="_blank" class="btn btn-primary">
                <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Open Full View
            </a>
        </div>
    </div>
</div>

<!-- Modal: Approve Bank Transfer -->
<div class="modal-backdrop" id="approveTransferModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" style="color: #16a34a;"><i class="fa-solid fa-circle-check mr-1"></i> Verify &amp; Settle Bank Transfer</h3>
                <div class="text-xs text-muted" id="approveOrderSubtitle">Order #</div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('approveTransferModal')">&times;</button>
        </div>
        <form action="" method="POST" id="approveTransferForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_bank_transfer">
            <input type="hidden" name="order_id" id="approve_order_id" value="">
            <div class="modal-body">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 14px; margin-bottom: 16px; font-size: 13px; color: #166534;">
                    <i class="fa-solid fa-info-circle mr-1"></i> Please check your bank merchant notification to ensure <strong id="approveOrderAmount">$0.00</strong> was legitimately credited to the business account before signing off.
                </div>
                <div class="form-group">
                    <label class="form-label">Manager Username <span class="text-danger">*</span></label>
                    <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars(Auth::getUsername() ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Manager 6-Digit PIN <span class="text-danger">*</span></label>
                    <input type="password" name="manager_pin" class="form-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="6-digit PIN" style="font-family: var(--font-mono); letter-spacing: 4px;" required autofocus>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Notes (Optional)</label>
                    <input type="text" name="notes" class="form-input" placeholder="e.g. Verified in ABA Merchant App">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('approveTransferModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #16a34a; border-color: #16a34a;">
                    <i class="fa-solid fa-circle-check mr-1"></i> Approve &amp; Settle
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Reject Bank Transfer -->
<div class="modal-backdrop" id="rejectTransferModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" style="color: #dc2626;"><i class="fa-solid fa-circle-xmark mr-1"></i> Reject Bank Transfer</h3>
                <div class="text-xs text-muted" id="rejectOrderSubtitle">Order #</div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('rejectTransferModal')">&times;</button>
        </div>
        <form action="" method="POST" id="rejectTransferForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject_bank_transfer">
            <input type="hidden" name="order_id" id="reject_order_id" value="">
            <div class="modal-body">
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: 14px; margin-bottom: 16px; font-size: 13px; color: #991b1b;">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> Rejecting will flag this bank transfer as unverified or invalid. The order will revert to unpaid so the cashier can collect valid payment.
                </div>
                <div class="form-group">
                    <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                    <textarea name="rejection_reason" class="form-input" rows="3" placeholder="e.g. Funds not received in account, altered screenshot, incorrect amount" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Manager Username <span class="text-danger">*</span></label>
                    <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars(Auth::getUsername() ?? '') ?>" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Manager 6-Digit PIN <span class="text-danger">*</span></label>
                    <input type="password" name="manager_pin" class="form-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="6-digit PIN" style="font-family: var(--font-mono); letter-spacing: 4px;" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rejectTransferModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #dc2626; border-color: #dc2626;">
                    <i class="fa-solid fa-ban mr-1"></i> Confirm Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Paper Receipt Fallback (Printer Failure) -->
<div class="modal-backdrop" id="paperFallbackModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" style="color: #d97706;"><i class="fa-solid fa-print mr-1"></i> Printer Failure: Paper Receipt Fallback</h3>
                <div class="text-xs text-muted" id="paperFallbackSubtitle">Order #</div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('paperFallbackModal')">&times;</button>
        </div>
        <form action="" method="POST" id="paperFallbackForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="paper_receipt_fallback">
            <input type="hidden" name="order_id" id="paper_fallback_order_id" value="">
            <div class="modal-body">
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 14px; margin-bottom: 16px; font-size: 13px; color: #92400e;">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> <strong>Thermal Printer Policy:</strong> If the receipt printer is offline, jammed, or out of paper, a hand-written paper logbook receipt must be issued. This requires <strong>Manager PIN</strong> authorization and will be permanently recorded in the audit log.
                </div>
                <div class="form-group">
                    <label class="form-label">Manager Username <span class="text-danger">*</span></label>
                    <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars(Auth::getUsername() ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Manager 6-Digit PIN <span class="text-danger">*</span></label>
                    <input type="password" name="manager_pin" class="form-input" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="6-digit PIN" style="font-family: var(--font-mono); letter-spacing: 4px;" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Reason / Manual Paper Receipt Number <span class="text-danger">*</span></label>
                    <input type="text" name="reason" class="form-input" placeholder="e.g. Printer out of thermal paper, issued Paper Receipt #P-8821" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('paperFallbackModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #d97706; border-color: #d97706;">
                    <i class="fa-solid fa-file-signature mr-1"></i> Authorize Paper Receipt
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.pay-method-btn {
    background: var(--bg-surface);
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    padding: 14px 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    cursor: pointer;
    transition: all 0.15s ease;
}
.pay-method-btn:hover {
    border-color: var(--primary);
}
.pay-method-btn.active {
    border-color: #16a34a;
    background: #f0fdf4;
    box-shadow: 0 4px 10px rgba(22, 163, 74, 0.15);
}
</style>

<script>
let currentSubtotal = 0.00;
let currentTotal = 0.00;
let currentCustLoyaltyPoints = 0;
let currentOrderPointsRedeemed = 0;
let currentOrderPointsDiscount = 0.00;
const REDEEM_RATE = <?= Loyalty::getRedeemRate() ?>;
const EXCHANGE_RATE = <?= get_exchange_rate() ?>;

function openCancelOrderModal(btnOrOrderId, orderNum, plate) {
    let orderId = btnOrOrderId;
    let isPaid = '0';
    if (btnOrOrderId && btnOrOrderId.getAttribute && btnOrOrderId.getAttribute('data-order-id')) {
        orderId = btnOrOrderId.getAttribute('data-order-id');
        orderNum = btnOrOrderId.getAttribute('data-order-num');
        plate = btnOrOrderId.getAttribute('data-plate');
        isPaid = btnOrOrderId.getAttribute('data-is-paid') || '0';
    }
    document.getElementById('cancel_order_id').value = orderId;
    document.getElementById('cancel_order_label').innerText = 'Cancel Order: ' + orderNum + ' (Plate: ' + plate + ')';
    document.getElementById('cancel_reason').value = '';

    let pinBox = document.getElementById('cancel_manager_pin_box');
    let mgrPinInp = document.getElementById('cancel_manager_pin');
    if (pinBox) {
        if (isPaid === '1') {
            pinBox.style.display = 'block';
            if (mgrPinInp) mgrPinInp.required = true;
        } else {
            pinBox.style.display = 'none';
            if (mgrPinInp) mgrPinInp.required = false;
        }
    }
    openModal('cancelOrderModal');
}

function openRefundModal(btn) {
    let orderId = btn.getAttribute('data-order-id');
    let orderNum = btn.getAttribute('data-order-num');
    let plate = btn.getAttribute('data-plate');
    let total = parseFloat(btn.getAttribute('data-total') || '0');

    document.getElementById('refund_order_id').value = orderId;
    document.getElementById('refund_order_label').innerText = 'Refund Order #' + orderNum + ' (Plate: ' + plate + ')';
    document.getElementById('refund_amount_usd').value = total.toFixed(2);
    document.getElementById('refund_amount_usd').max = total.toFixed(2);
    document.getElementById('refund_total_display').innerText = '$' + total.toFixed(2);
    document.getElementById('refund_reason').value = '';

    checkRefundOnlineStatus();
    openModal('refundModal');
}

function checkRefundOnlineStatus() {
    let isOffline = !navigator.onLine;
    let warn = document.getElementById('offlineRefundWarning');
    let btn = document.getElementById('btnSubmitRefund');
    let hiddenOffline = document.getElementById('refund_is_offline');

    if (warn) warn.style.display = isOffline ? 'block' : 'none';
    if (btn) btn.disabled = isOffline;
    if (hiddenOffline) hiddenOffline.value = isOffline ? '1' : '0';
}

function validateRefundOnline() {
    if (!navigator.onLine) {
        alert('Offline refunds are not allowed. If connection is lost, use the paper logbook signed by the Manager.');
        return false;
    }
    return true;
}

function openAssignWasherModal(btnOrOrderId, plate) {
    let orderId = btnOrOrderId;
    if (btnOrOrderId && btnOrOrderId.getAttribute && btnOrOrderId.getAttribute('data-order-id')) {
        orderId = btnOrOrderId.getAttribute('data-order-id');
        plate = btnOrOrderId.getAttribute('data-plate');
    }
    document.getElementById('assign_order_id').value = orderId;
    document.getElementById('assign_order_plate').innerText = 'Vehicle: ' + plate;
    openModal('assignWasherModal');
}

function openCheckoutModal(btnOrOrder) {
    let order = btnOrOrder;
    if (btnOrOrder && btnOrOrder.getAttribute && btnOrOrder.getAttribute('data-order')) {
        try {
            order = JSON.parse(btnOrOrder.getAttribute('data-order'));
        } catch (e) {
            console.error('Failed to parse order data', e);
            return;
        }
    }
    if (!order) return;

    document.getElementById('chk_order_id').value = order.id;
    document.getElementById('chkOrderNum').innerText = 'Order #' + order.order_number;
    document.getElementById('chkPlate').innerText = order.plate_number;
    document.getElementById('chkCustomer').innerText = order.customer_name + ' • ' + order.vehicle_type.toUpperCase();

    currentSubtotal = parseFloat(order.subtotal_usd);
    document.getElementById('chkSubtotal').innerText = '$' + currentSubtotal.toFixed(2);

    // Points already redeemed on this order (e.g. from POS creation or previous save)
    currentOrderPointsRedeemed = parseInt(order.points_redeemed || 0);
    currentOrderPointsDiscount = parseFloat(order.points_discount_usd || 0);
    const existingTotalDiscount = parseFloat(order.discount_usd || 0);
    const initialManualDiscount = Math.max(0, existingTotalDiscount - currentOrderPointsDiscount);
    document.getElementById('chk_discount_usd').value = initialManualDiscount.toFixed(2);

    // Setup Loyalty Section
    currentCustLoyaltyPoints = parseInt(order.cust_loyalty_points || 0);
    const hasCust = !!order.customer_id;
    const loyaltyBox = document.getElementById('chkLoyaltyBox');
    const ptsInput = document.getElementById('chk_points_redeemed');
    const btnMax = document.getElementById('btnMaxRedeem');
    const tierName = (order.cust_membership_level || 'Standard').toUpperCase();
    
    if (currentOrderPointsRedeemed > 0) {
        // Points were already redeemed for this order at creation time!
        loyaltyBox.style.display = 'block';
        document.getElementById('chkCustTier').innerText = tierName;
        document.getElementById('chkCustPoints').innerText = currentCustLoyaltyPoints.toLocaleString() + ' pts (Remaining)';
        ptsInput.value = currentOrderPointsRedeemed;
        ptsInput.readOnly = true;
        ptsInput.style.backgroundColor = 'var(--bg-subtle)';
        if (btnMax) btnMax.style.display = 'none';
        document.getElementById('chkPointsCredit').innerText = '-$' + currentOrderPointsDiscount.toFixed(2);
    } else if (hasCust && currentCustLoyaltyPoints > 0) {
        loyaltyBox.style.display = 'block';
        document.getElementById('chkCustTier').innerText = tierName;
        document.getElementById('chkCustPoints').innerText = currentCustLoyaltyPoints.toLocaleString() + ' pts';
        ptsInput.value = '0';
        ptsInput.readOnly = false;
        ptsInput.style.backgroundColor = '';
        ptsInput.max = currentCustLoyaltyPoints;
        if (btnMax) btnMax.style.display = 'inline-block';
        document.getElementById('chkPointsCredit').innerText = '-$0.00';
    } else {
        loyaltyBox.style.display = 'none';
        ptsInput.value = '0';
        ptsInput.readOnly = false;
        ptsInput.style.backgroundColor = '';
        if (btnMax) btnMax.style.display = 'none';
        document.getElementById('chkPointsCredit').innerText = '-$0.00';
    }

    recalcCheckout();
    setPayMethod('cash');
    openModal('checkoutModal');
}

function applyMaxRedeemPoints() {
    if (currentOrderPointsRedeemed > 0) return;
    const discInput = document.getElementById('chk_discount_usd');
    const manualDisc = Math.max(0, parseFloat(discInput.value) || 0);
    const remainingToPay = Math.max(0, currentSubtotal - manualDisc);
    
    // REDEEM_RATE pts = $1 credit => max points needed = ceil(remainingToPay * REDEEM_RATE)
    const maxPointsNeeded = Math.ceil(remainingToPay * REDEEM_RATE);
    const pointsToUse = Math.min(currentCustLoyaltyPoints, maxPointsNeeded);
    
    document.getElementById('chk_points_redeemed').value = pointsToUse;
    recalcCheckout();
}

function recalcCheckout() {
    const discInput = document.getElementById('chk_discount_usd');
    const manualDiscount = Math.max(0, parseFloat(discInput.value) || 0);
    
    let pointsDiscount = 0.00;
    if (currentOrderPointsRedeemed > 0) {
        pointsDiscount = currentOrderPointsDiscount;
        document.getElementById('chkPointsCredit').innerText = '-$' + pointsDiscount.toFixed(2);
    } else {
        let pointsToRedeem = Math.max(0, parseInt(document.getElementById('chk_points_redeemed').value) || 0);
        if (pointsToRedeem > currentCustLoyaltyPoints) {
            pointsToRedeem = currentCustLoyaltyPoints;
            document.getElementById('chk_points_redeemed').value = pointsToRedeem;
        }
        pointsDiscount = Math.round((pointsToRedeem / REDEEM_RATE) * 100) / 100;
        document.getElementById('chkPointsCredit').innerText = '-$' + pointsDiscount.toFixed(2);
    }

    const totalDiscount = manualDiscount + pointsDiscount;
    currentTotal = Math.max(0, currentSubtotal - totalDiscount);
    const totalKhr = Math.round(currentTotal * EXCHANGE_RATE);

    document.getElementById('chkTotalUsd').innerText = '$' + currentTotal.toFixed(2);
    document.getElementById('chkTotalKhr').innerText = '(' + totalKhr.toLocaleString() + ' ៛)';
    document.getElementById('amount_tendered_usd').value = currentTotal.toFixed(2);

    // Update KHQR amounts
    document.getElementById('khqrAmountUsd').innerText = '$' + currentTotal.toFixed(2);
    document.getElementById('khqrAmountKhr').innerText = '(' + totalKhr.toLocaleString() + ' ៛)';

    // Update QR Code URL
    const qrText = encodeURIComponent(`BAKONG_KHQR_CARWASH_${document.getElementById('chk_order_id').value}_USD_${currentTotal.toFixed(2)}`);
    document.getElementById('khqrQrImage').src = `https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=${qrText}`;

    calcChange();
}

function setPayMethod(method) {
    document.getElementById('chk_payment_method').value = method;

    // Toggle button active state
    document.getElementById('btnPayCash').classList.toggle('active', method === 'cash');
    document.getElementById('btnPayAba').classList.toggle('active', method === 'aba');
    document.getElementById('btnPayKhqr').classList.toggle('active', method === 'khqr');

    // Toggle sections
    document.getElementById('paySectionCash').style.display = (method === 'cash') ? 'block' : 'none';
    document.getElementById('paySectionAba').style.display = (method === 'aba') ? 'block' : 'none';
    document.getElementById('paySectionKhqr').style.display = (method === 'khqr') ? 'block' : 'none';

    // Show/hide bank slip section
    const slipSection = document.getElementById('bankSlipSection');
    if (slipSection) {
        slipSection.style.display = (method === 'aba' || method === 'khqr') ? 'block' : 'none';
        const slipInput = document.getElementById('bank_slip_image');
        if (slipInput) {
            slipInput.required = (method === 'aba' || method === 'khqr');
        }
    }
}

function previewSlip(input) {
    const previewContainer = document.getElementById('slipPreviewContainer');
    const previewImg = document.getElementById('slipPreviewImg');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewContainer.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        previewContainer.style.display = 'none';
    }
}

function viewBankSlip(imgUrl, orderNum, amount) {
    document.getElementById('viewSlipSubtitle').innerText = 'Order #' + orderNum + ' (' + amount + ')';
    document.getElementById('viewSlipImage').src = imgUrl;
    document.getElementById('downloadSlipBtn').href = imgUrl;
    openModal('viewSlipModal');
}

function openApproveModal(orderId, orderNum, amount) {
    document.getElementById('approve_order_id').value = orderId;
    document.getElementById('approveOrderSubtitle').innerText = 'Order #' + orderNum;
    document.getElementById('approveOrderAmount').innerText = amount;
    openModal('approveTransferModal');
}

function openRejectModal(orderId, orderNum) {
    document.getElementById('reject_order_id').value = orderId;
    document.getElementById('rejectOrderSubtitle').innerText = 'Order #' + orderNum;
    openModal('rejectTransferModal');
}

function openPaperFallbackModal(orderId, orderNum) {
    document.getElementById('paper_fallback_order_id').value = orderId;
    document.getElementById('paperFallbackSubtitle').innerText = 'Order #' + orderNum;
    openModal('paperFallbackModal');
}

function setQuickCash(amt) {
    if (amt === 'exact') {
        document.getElementById('amount_tendered_usd').value = currentTotal.toFixed(2);
    } else {
        document.getElementById('amount_tendered_usd').value = parseFloat(amt).toFixed(2);
    }
    calcChange();
}

function calcChange() {
    const tendered = parseFloat(document.getElementById('amount_tendered_usd').value) || 0;
    const change = Math.max(0, tendered - currentTotal);
    const changeKhr = Math.round(change * EXCHANGE_RATE);

    document.getElementById('changeUsd').innerText = '$' + change.toFixed(2);
    document.getElementById('changeKhr').innerText = changeKhr.toLocaleString() + ' ៛';
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
