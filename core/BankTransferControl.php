<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CashierControl.php';
require_once __DIR__ . '/StockControl.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Loyalty.php';

class BankTransferControl {

    /**
     * Validate and process bank slip image upload.
     * Compresses the picture and saves it in public/uploads/bank_slips/.
     * Rejects non-image files, SVG, XML, and corrupted images.
     */
    public static function processSlipUpload(array $file): array {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'error' => 'A photo of the bank slip is required for bank transfer payments (ABA / KHQR).'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Bank slip upload failed with error code ' . $file['error']];
        }

        // Check size (max 8MB)
        $maxBytes = 8 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            return ['success' => false, 'error' => 'Bank slip picture is too large (max 8MB).'];
        }

        // Check extension
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExts, true)) {
            return ['success' => false, 'error' => 'Invalid bank slip picture format. Only real JPG, PNG, and WebP pictures are accepted.'];
        }

        $tmp = $file['tmp_name'] ?? '';
        if (!is_file($tmp) || !is_readable($tmp)) {
            return ['success' => false, 'error' => 'Temporary slip picture file could not be read.'];
        }

        // Prevent SVG / XML masquerading
        $header = (string)@file_get_contents($tmp, false, null, 0, 512);
        if (stripos($header, '<svg') !== false || stripos($header, '<?xml') !== false) {
            return ['success' => false, 'error' => 'SVG files and XML documents are not allowed. Please upload a real photo of the bank slip.'];
        }

        // Verify with getimagesize
        $info = @getimagesize($tmp);
        if ($info === false) {
            return ['success' => false, 'error' => 'The selected slip file is not a valid picture or is corrupted.'];
        }

        $mime = strtolower((string)($info['mime'] ?? ''));
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            return ['success' => false, 'error' => "Unsupported slip format ({$mime}). Only real JPG, PNG, and WebP images are allowed."];
        }

        // Load image into GD for compression
        $srcImg = null;
        if ($mime === 'image/jpeg') {
            $srcImg = @imagecreatefromjpeg($tmp);
        } elseif ($mime === 'image/png') {
            $srcImg = @imagecreatefrompng($tmp);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $srcImg = @imagecreatefromwebp($tmp);
        }

        if (!$srcImg) {
            return ['success' => false, 'error' => 'Failed to process picture for compression. Please try another image.'];
        }

        $origW = imagesx($srcImg);
        $origH = imagesy($srcImg);

        // Max dimension 1200px (preserve aspect ratio)
        $maxDim = 1200;
        if ($origW > $maxDim || $origH > $maxDim) {
            if ($origW >= $origH) {
                $newW = $maxDim;
                $newH = (int)round(($origH / $origW) * $maxDim);
            } else {
                $newH = $maxDim;
                $newW = (int)round(($origW / $origH) * $maxDim);
            }
        } else {
            $newW = $origW;
            $newH = $origH;
        }

        $dstImg = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        // Destination filename
        $destDir = dirname(__DIR__) . '/public/uploads/bank_slips';
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        $filename = 'slip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
        $fullPath = $destDir . '/' . $filename;
        $relPath = 'public/uploads/bank_slips/' . $filename;

        // Save compressed JPEG with 82% quality
        imagejpeg($dstImg, $fullPath, 82);

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        return [
            'success'   => true,
            'file_path' => $relPath,
            'filename'  => $filename
        ];
    }

    /**
     * Submit Bank Transfer Payment with Bank Slip.
     * Sets payment_status = 'pending_verification'.
     */
    public static function submitBankTransfer(
        int $orderId,
        string $paymentMethod, // 'aba' or 'khqr'
        ?array $slipFile,
        ?string $slipExistingPath = null,
        ?string $paymentRef = null,
        float $tenderedUsd = 0.00,
        float $discountUsd = 0.00,
        int $pointsToRedeem = 0,
        ?int $cashierId = null
    ): array {
        $pdo = Database::getInstance();

        // Fetch order
        $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found.");
        }

        if ($order['status'] === 'cancelled') {
            throw new Exception("Cannot process payment on cancelled order #{$order['order_number']}.");
        }

        // Validate slip photo: Cashier MUST attach a photo of the bank slip
        $slipPath = $slipExistingPath;
        if (!empty($slipFile) && isset($slipFile['tmp_name']) && !empty($slipFile['tmp_name'])) {
            $uploadRes = self::processSlipUpload($slipFile);
            if (!$uploadRes['success']) {
                throw new Exception($uploadRes['error']);
            }
            $slipPath = $uploadRes['file_path'];
        }

        if (empty($slipPath)) {
            throw new Exception("A photo of the bank transfer slip is required for ABA and KHQR payments. Please attach the transfer receipt.");
        }

        $subtotalUsd = (float)$order['subtotal_usd'];
        $customerId = !empty($order['customer_id']) ? (int)$order['customer_id'] : null;
        $userId = $cashierId ?? (class_exists('Auth') ? Auth::getUserId() : null);

        // Loyalty redemption check
        $pointsDiscountUsd = 0.00;
        $actualPointsRedeemed = 0;
        if ($pointsToRedeem > 0 && $customerId) {
            $maxUsdAllowed = max(0.00, $subtotalUsd - $discountUsd);
            $redResult = Loyalty::redeemPoints($pdo, $customerId, $orderId, $pointsToRedeem, $maxUsdAllowed, $userId);
            if (!empty($redResult['success'])) {
                $actualPointsRedeemed = (int)$redResult['points_redeemed'];
                $pointsDiscountUsd = (float)$redResult['discount_usd'];
            }
        }

        $totalDiscountUsd = round($discountUsd + $pointsDiscountUsd, 2);
        $totalUsd = max(0.00, round($subtotalUsd - $totalDiscountUsd, 2));
        $totalKhr = (int)round($totalUsd * get_exchange_rate());

        if (empty($paymentRef)) {
            $paymentRef = 'TRX-' . strtoupper(substr(md5(uniqid()), 0, 8));
        }

        // Update order to 'pending_verification'
        $upd = $pdo->prepare("
            UPDATE orders SET
                discount_usd = ?,
                points_redeemed = ?,
                points_discount_usd = ?,
                total_usd = ?,
                total_khr = ?,
                payment_method = ?,
                payment_status = 'pending_verification',
                payment_ref = ?,
                bank_slip_image = ?,
                bank_slip_uploaded_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $upd->execute([
            $totalDiscountUsd,
            $actualPointsRedeemed,
            $pointsDiscountUsd,
            $totalUsd,
            $totalKhr,
            $paymentMethod,
            $paymentRef,
            $slipPath,
            $orderId
        ]);

        AuditLogger::log(
            'BANK_TRANSFER_SUBMITTED',
            "Bank transfer payment ($" . number_format($totalUsd, 2) . " via " . strtoupper($paymentMethod) . ") submitted for Order #{$order['order_number']} with slip. Pending Manager Verification.",
            'order',
            (string)$orderId,
            $userId,
            null,
            'cashier'
        );

        return [
            'success'           => true,
            'order_number'      => $order['order_number'],
            'payment_status'    => 'pending_verification',
            'bank_slip_image'   => $slipPath,
            'total_usd'         => $totalUsd,
            'message'           => "Bank transfer received and marked as 'Pending Verification'. A Manager will verify the slip with the bank app."
        ];
    }

    /**
     * Manager Approves Bank Transfer after checking bank app.
     */
    public static function approveTransfer(
        int $orderId,
        string $managerUsername,
        string $managerPin,
        ?string $notes = null
    ): array {
        $mgr = CashierControl::verifyManagerPin($managerUsername, $managerPin);

        $pdo = Database::getInstance();
        $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found.");
        }

        if ($order['payment_status'] === 'paid') {
            throw new Exception("Order #{$order['order_number']} is already verified and marked as paid.");
        }

        $totalUsd = (float)$order['total_usd'];
        $totalKhr = (int)$order['total_khr'];
        $payMethod = $order['payment_method'] ?? 'aba';
        $payRef = $order['payment_ref'] ?? '';

        // Record official payment ID
        $payUid = record_payment($pdo, $orderId, $order['order_number'], $totalUsd, $totalKhr, $payMethod, $payRef);

        // Update order status to paid
        $upd = $pdo->prepare("
            UPDATE orders SET
                status = 'paid',
                payment_status = 'paid',
                paid_at = CURRENT_TIMESTAMP,
                payment_verified_by_id = ?,
                payment_verified_by_name = ?,
                payment_verified_at = CURRENT_TIMESTAMP,
                payment_rejection_reason = NULL
            WHERE id = ?
        ");
        $upd->execute([
            $mgr['manager_id'],
            $mgr['manager_name'],
            $orderId
        ]);

        // Commit stock reservation into used
        StockControl::commitOrderStock($orderId, $order['order_number'], $mgr['manager_id']);

        // Award loyalty points on confirmed payment
        $customerId = !empty($order['customer_id']) ? (int)$order['customer_id'] : null;
        if ($customerId) {
            try {
                Loyalty::awardEarnedPoints($orderId, $mgr['manager_id']);
            } catch (Exception $e) {
                error_log("Loyalty award error on order {$orderId}: " . $e->getMessage());
            }
        }

        // Re-sync crew commissions
        self::recalcCommissions($pdo, $orderId, $totalUsd);

        // Audit Log
        $logMsg = "Bank transfer payment of $" . number_format($totalUsd, 2) . " on Order #{$order['order_number']} verified and APPROVED by Manager {$mgr['manager_name']}. Payment ID: {$payUid}";
        AuditLogger::log('BANK_TRANSFER_APPROVED', $logMsg, 'order', (string)$orderId, $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        return [
            'success'           => true,
            'order_number'      => $order['order_number'],
            'payment_uid'       => $payUid,
            'manager_name'      => $mgr['manager_name'],
            'total_usd'         => $totalUsd,
            'message'           => "Bank transfer approved by Manager {$mgr['manager_name']}. Order #{$order['order_number']} is now officially marked as PAID."
        ];
    }

    /**
     * Manager Rejects Bank Transfer (slip does not match bank app).
     */
    public static function rejectTransfer(
        int $orderId,
        string $rejectionReason,
        string $managerUsername,
        string $managerPin
    ): array {
        $rejectionReason = trim($rejectionReason);
        if ($rejectionReason === '') {
            throw new Exception("Rejection reason is required (e.g. money not received in bank app, wrong reference, altered slip).");
        }

        $mgr = CashierControl::verifyManagerPin($managerUsername, $managerPin);

        $pdo = Database::getInstance();
        $ordStmt = $pdo->prepare("SELECT id, order_number, total_usd, cashier_id FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found.");
        }

        $upd = $pdo->prepare("
            UPDATE orders SET
                payment_status = 'rejected',
                payment_verified_by_id = ?,
                payment_verified_by_name = ?,
                payment_verified_at = CURRENT_TIMESTAMP,
                payment_rejection_reason = ?
            WHERE id = ?
        ");
        $upd->execute([
            $mgr['manager_id'],
            $mgr['manager_name'],
            $rejectionReason,
            $orderId
        ]);

        $logMsg = "Bank transfer on Order #{$order['order_number']} REJECTED by Manager {$mgr['manager_name']}. Reason: {$rejectionReason}";
        AuditLogger::log('BANK_TRANSFER_REJECTED', $logMsg, 'order', (string)$orderId, $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        return [
            'success'           => true,
            'order_number'      => $order['order_number'],
            'manager_name'      => $mgr['manager_name'],
            'rejection_reason'  => $rejectionReason,
            'message'           => "Bank transfer rejected: {$rejectionReason}."
        ];
    }

    /**
     * Fetch pending bank transfers for Manager review.
     */
    public static function getPendingTransfers(): array {
        $pdo = Database::getInstance();
        $stmt = $pdo->query("
            SELECT o.*, c.name as customer_name_full, c.phone as customer_phone_full
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            WHERE o.payment_status = 'pending_verification'
            ORDER BY o.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Authorize Paper Receipt Fallback when thermal printer fails.
     * Strictly requires Manager PIN authorization.
     */
    public static function authorizePaperReceiptFallback(
        int $orderId,
        string $reason,
        string $managerUsername,
        string $managerPin,
        ?int $cashierId = null
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Thermal printer offline / hardware malfunction';
        }

        $mgr = CashierControl::verifyManagerPin($managerUsername, $managerPin);
        $pdo = Database::getInstance();

        $ordStmt = $pdo->prepare("SELECT id, order_number, total_usd FROM orders WHERE id = ?");
        $ordStmt->execute([$orderId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order #{$orderId} not found.");
        }

        $pdo->prepare("
            UPDATE orders SET
                paper_receipt_fallback = 1,
                paper_receipt_authorized_by = ?
            WHERE id = ?
        ")->execute([$mgr['manager_name'], $orderId]);

        $voucherNumber = "PAP-" . strtoupper(substr(md5($order['order_number'] . microtime()), 0, 8));

        $logMsg = "Paper fallback receipt authorized for Order #{$order['order_number']} (Voucher: {$voucherNumber}) by Manager {$mgr['manager_name']}. Reason: {$reason}";
        AuditLogger::log('PAPER_RECEIPT_FALLBACK', $logMsg, 'receipt', (string)$orderId, $mgr['manager_id'], $mgr['manager_name'], $mgr['manager_role']);

        return [
            'success'           => true,
            'voucher_number'    => $voucherNumber,
            'order_number'      => $order['order_number'],
            'manager_name'      => $mgr['manager_name'],
            'message'           => "Paper receipt fallback authorized by Manager {$mgr['manager_name']}. Issue handwritten receipt Voucher #{$voucherNumber}."
        ];
    }

    private static function recalcCommissions(PDO $pdo, int $orderId, float $totalUsd): void {
        // Washer commissions
        $washers = $pdo->prepare("SELECT ow.id, u.commission_type, u.commission_rate FROM order_washers ow JOIN users u ON ow.washer_id = u.id WHERE ow.order_id = ? AND ow.is_active = 1");
        $washers->execute([$orderId]);
        $wRows = $washers->fetchAll(PDO::FETCH_ASSOC);
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
    }
}
