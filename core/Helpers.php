<?php
declare(strict_types=1);

function formatUSD(float|int|null $amount): string {
    $val = (float)($amount ?? 0);
    if ($val < 0) {
        return '-$' . number_format(abs($val), 2);
    }
    return '$' . number_format($val, 2);
}

function formatKHR(int|float|null $amount): string {
    $val = (float)($amount ?? 0);
    if ($val < 0) {
        return '-' . number_format(abs($val)) . ' ៛';
    }
    return number_format($val) . ' ៛';
}

function formatDualPrice(float|int|null $usd, int|float|null $khr = null): string {
    $usdVal = (float)($usd ?? 0);
    if ($khr === null || $khr <= 0) {
        $khr = (int)round($usdVal * get_exchange_rate());
    }
    return formatUSD($usdVal) . ' <span class="text-secondary text-sm">(' . formatKHR($khr) . ')</span>';
}

function vehicleTypeBadge(?string $type): string {
    $type = (string)($type ?? 'small_car');
    $types = [
        'motorbike'  => ['icon' => 'fa-motorcycle', 'label_en' => 'Motorbike', 'label_km' => 'ម៉ូតូ', 'badge' => 'badge-purple'],
        'small_car'  => ['icon' => 'fa-car-side',   'label_en' => 'Small Car', 'label_km' => 'ឡានតូច', 'badge' => 'badge-blue'],
        'suv_pickup' => ['icon' => 'fa-truck-pickup','label_en' => 'SUV / Pickup', 'label_km' => 'SUV / Pickup', 'badge' => 'badge-amber'],
        'van_truck'  => ['icon' => 'fa-van-shuttle', 'label_en' => 'Big Van / Truck', 'label_km' => 'ឡានធំ', 'badge' => 'badge-slate'],
    ];

    $info = $types[$type] ?? ['icon' => 'fa-car', 'label_en' => ucfirst($type), 'label_km' => $type, 'badge' => 'badge-slate'];
    $label = (Language::current() === 'km') ? $info['label_km'] : $info['label_en'];

    return '<span class="badge ' . $info['badge'] . '"><i class="fa-solid ' . $info['icon'] . ' mr-1"></i> ' . htmlspecialchars($label) . '</span>';
}

function statusBadge(?string $status): string {
    $status = (string)($status ?? 'waiting');
    $map = [
        'waiting'   => ['label' => __('status_waiting'), 'class' => 'badge-warning', 'icon' => 'fa-clock'],
        'washing'   => ['label' => __('status_washing'), 'class' => 'badge-info', 'icon' => 'fa-soap'],
        'finished'  => ['label' => __('status_finished'), 'class' => 'badge-success', 'icon' => 'fa-circle-check'],
        'paid'      => ['label' => __('status_paid'), 'class' => 'badge-primary', 'icon' => 'fa-receipt'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'badge-danger', 'icon' => 'fa-ban'],
    ];

    $item = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-slate', 'icon' => 'fa-circle-info'];
    return '<span class="badge ' . $item['class'] . '"><i class="fa-solid ' . $item['icon'] . ' mr-1"></i> ' . htmlspecialchars($item['label']) . '</span>';
}

function sanitize(?string $data): string {
    if ($data === null) return '';
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function safeStr(?string $str, string $fallback = '-'): string {
    if ($str === null || trim((string)$str) === '') {
        return $fallback;
    }
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function e(?string $str, string $fallback = ''): string {
    if ($str === null || trim((string)$str) === '') {
        return $fallback;
    }
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function app_log(string $level, string $message, array $context = []): void {
    if (class_exists('ErrorHandler')) {
        ErrorHandler::log($level, $message, $context);
    }
}

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (class_exists('Auth')) {
            Auth::startSession();
        } else {
            @session_start();
        }
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(?string $providedToken = null): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (class_exists('Auth')) {
            Auth::startSession();
        } else {
            @session_start();
        }
    }
    $token = $providedToken ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        return false;
    }
    return true;
}

function get_setting(string $key, ?string $default = null, bool $refresh = false): ?string {
    static $settingsCache = null;
    if ($settingsCache === null || $refresh) {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            $settingsCache = [];
            while ($row = $stmt->fetch()) {
                $settingsCache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $t) {
            $settingsCache = [];
        }
    }
    if ($key === '') {
        return null;
    }
    return $settingsCache[$key] ?? $default;
}

function refresh_settings_cache(): void {
    get_setting('', null, true);
}

function get_exchange_rate(): int {
    $rate = (int)get_setting('exchange_rate', (string)(defined('DEFAULT_EXCHANGE_RATE') ? DEFAULT_EXCHANGE_RATE : 4100));
    return $rate > 0 ? $rate : 4100;
}

/**
 * Get unified customer receipt configuration with defaults matching master thermal printout
 */
function get_receipt_config(): array {
    return [
        // Show / Hide switches
        'show_logo'          => (get_setting('receipt_show_logo', '1') !== '0'),
        'show_shop_name'     => (get_setting('receipt_show_shop_name', '1') !== '0'),
        'show_address'       => (get_setting('receipt_show_address', '1') !== '0'),
        'show_phone'         => (get_setting('receipt_show_phone', '1') !== '0'),
        'show_invoice_no'    => (get_setting('receipt_show_invoice_no', '1') !== '0'),
        'show_date'          => (get_setting('receipt_show_date', '1') !== '0'),
        'show_cashier'       => (get_setting('receipt_show_cashier', '1') !== '0'),
        'show_customer'      => (get_setting('receipt_show_customer', '1') !== '0'),
        'show_plate'         => (get_setting('receipt_show_plate', '1') !== '0'),
        'show_vehicle_type'  => (get_setting('receipt_show_vehicle_type', '0') === '1'),
        'show_washer'        => (get_setting('receipt_show_washer', '0') === '1'),
        'show_item_count'    => (get_setting('receipt_show_item_count', '0') === '1'),
        'show_discount'      => (get_setting('receipt_show_discount', '1') !== '0'),
        'show_tax'           => (get_setting('receipt_show_tax', '0') === '1'),
        'show_exchange_rate' => (get_setting('receipt_show_exchange_rate', '0') === '1'),
        'show_payment'       => (get_setting('receipt_show_payment', '1') !== '0'),
        'show_oil_reminder'  => (get_setting('receipt_show_oil_reminder', '1') !== '0'),
        'show_notes'         => (get_setting('receipt_show_notes', '1') !== '0'),
        'show_footer'        => (get_setting('receipt_show_footer', '1') !== '0'),

        // Custom Labels & Messages
        'welcome_line'       => (string)get_setting('receipt_welcome_line', ''),
        'footer_msg'         => (string)get_setting('receipt_footer', 'Thank you for your visit!'),
        'bottom_line'        => (string)get_setting('receipt_bottom_line', 'Powered by Counter POS'),
        'label_invoice'      => (string)get_setting('receipt_label_invoice', ''),
        'label_date'         => (string)get_setting('receipt_label_date', ''),
        'label_cashier'      => (string)get_setting('receipt_label_cashier', ''),
        'label_customer'     => (string)get_setting('receipt_label_customer', ''),
        'label_plate'        => (string)get_setting('receipt_label_plate', ''),
        'label_vehicle_type' => (string)get_setting('receipt_label_vehicle_type', ''),
        'label_washer'       => (string)get_setting('receipt_label_washer', ''),
        'label_item'         => (string)get_setting('receipt_label_item', ''),
        'label_qty'          => (string)get_setting('receipt_label_qty', ''),
        'label_price'        => (string)get_setting('receipt_label_price', ''),
        'label_subtotal'     => (string)get_setting('receipt_label_subtotal', ''),
        'label_discount'     => (string)get_setting('receipt_label_discount', ''),
        'label_tax'          => (string)get_setting('receipt_label_tax', ''),
        'label_total_usd'    => (string)get_setting('receipt_label_total_usd', ''),
        'label_total_khr'    => (string)get_setting('receipt_label_total_khr', ''),
        'label_rate'         => (string)get_setting('receipt_label_rate', ''),
        'label_payment'      => (string)get_setting('receipt_label_payment', ''),
        'label_tendered'     => (string)get_setting('receipt_label_tendered', ''),
        'label_change'       => (string)get_setting('receipt_label_change', ''),

        // Extra lines
        'header_extra'       => (string)get_setting('receipt_header_extra', ''),
        'footer_extra'       => (string)get_setting('receipt_footer_extra', ''),

        // Style choices
        'font_size'          => (string)get_setting('receipt_font_size', 'normal'),
        'bold_mode'          => (get_setting('receipt_bold_mode', '1') !== '0'),
        'header_align'       => (string)get_setting('receipt_header_align', 'center'),
        'line_spacing'       => (string)get_setting('receipt_line_spacing', 'normal'),
        'logo_size'          => (string)get_setting('receipt_logo_size', 'medium'),

        // Paper & Language
        'paper_size'         => (string)get_setting('receipt_paper_size', '80mm'),
        'language'           => (string)get_setting('receipt_language', 'en'),
    ];
}

function update_setting(PDO $pdo, string $key, ?string $newValue, ?int $userId, string $userName): bool {
    $curStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $curStmt->execute([$key]);
    $oldValue = $curStmt->fetchColumn();

    $newStr = (string)($newValue ?? '');
    $oldStr = ($oldValue !== false && $oldValue !== null) ? (string)$oldValue : null;

    if ($oldStr === $newStr) {
        refresh_settings_cache();
        return false; // No change
    }

    $isMysql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
    if ($isMysql) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    } else {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    }
    $stmt->execute([$key, $newStr]);

    refresh_settings_cache();

    // Record audit history
    try {
        $hStmt = $pdo->prepare("INSERT INTO settings_history (setting_key, old_value, new_value, changed_by_id, changed_by_name, changed_at) VALUES (?, ?, ?, ?, ?, ?)");
        $hStmt->execute([
            $key,
            $oldStr,
            $newStr,
            $userId,
            $userName,
            date('Y-m-d H:i:s')
        ]);
        audit_log('SETTING_CHANGE', "Setting '{$key}' changed from '{$oldStr}' to '{$newStr}'", 'setting', $key, $userId, $userName);
    } catch (Throwable $t) {
        app_log('ERROR', 'Failed to log settings history: ' . $t->getMessage(), ['key' => $key]);
    }

    return true;
}

/**
 * Generate a guaranteed unique collision-proof identifier.
 * Format: PREFIX-YYYYMMDD-HEX (e.g. ORD-20261027-A84D2F9C)
 */
function generate_unique_id(string $prefix): string {
    $datePart = date('Ymd');
    $randomPart = strtoupper(bin2hex(random_bytes(4)));
    return "{$prefix}-{$datePart}-{$randomPart}";
}

/**
 * Record a system-wide immutable audit trail entry.
 */
function audit_log(
    string $action,
    string $details,
    ?string $entityType = null,
    ?string $entityId = null,
    ?int $userId = null,
    ?string $username = null,
    ?string $role = null,
    ?PDO $pdo = null,
    bool $throwOnError = false
): string {
    if (!class_exists('AuditLogger')) {
        require_once __DIR__ . '/AuditLogger.php';
    }
    return AuditLogger::log($action, $details, $entityType, $entityId, $userId, $username, $role, $pdo, $throwOnError);
}

/**
 * Record a payment with a unique ID that never repeats.
 */
function record_payment(
    PDO $pdo,
    int $orderId,
    string $orderNumber,
    float $amountUsd,
    int $amountKhr,
    string $paymentMethod = 'cash',
    ?string $paymentUid = null,
    ?int $cashierId = null,
    string $status = 'completed'
): string {
    $uid = $paymentUid ?: generate_unique_id('PAY');
    $cashier = $cashierId ?? (class_exists('Auth') ? Auth::getUserId() : null);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                payment_uid, order_id, order_number, amount_usd, amount_khr,
                payment_method, payment_ref, cashier_id, status, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $uid,
            $orderId,
            $orderNumber,
            $amountUsd,
            $amountKhr,
            $paymentMethod,
            $uid,
            $cashier,
            $status
        ]);
    } catch (Throwable $e) {
        app_log('ERROR', 'Failed to record payment: ' . $e->getMessage(), ['uid' => $uid]);
    }

    return $uid;
}

/**
 * Record a stock movement with a unique non-repeating ID.
 */
function record_stock_movement(
    PDO $pdo,
    int $productId,
    string $movementType,
    float $qty,
    float $prevQty,
    float $newQty,
    ?string $referenceId = null,
    ?string $notes = null,
    ?int $userId = null
): string {
    $uid = generate_unique_id('STK');
    $user = $userId ?? (class_exists('Auth') ? Auth::getUserId() : null);

    $productName = null;
    try {
        $pStmt = $pdo->prepare("SELECT name_en FROM products WHERE id = ?");
        $pStmt->execute([$productId]);
        $productName = $pStmt->fetchColumn() ?: null;
    } catch (Throwable $t) {}

    $performerName = 'System';
    if ($user) {
        try {
            $uStmt = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
            $uStmt->execute([$user]);
            $u = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $performerName = "{$u['full_name']} (@{$u['username']})";
            }
        } catch (Throwable $t) {}
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements (
                movement_uid, product_id, product_name, movement_type, quantity,
                previous_qty, new_qty, reference_id, notes, performed_by, performer_name, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $uid,
            $productId,
            $productName,
            $movementType,
            $qty,
            $prevQty,
            $newQty,
            $referenceId,
            $notes,
            $user,
            $performerName
        ]);
    } catch (Throwable $e) {
        app_log('ERROR', 'Failed to record stock movement: ' . $e->getMessage(), ['uid' => $uid]);
    }

    return $uid;
}

/**
 * Returns the active Cashier POS URL based on the POS_UI setting ('new' vs 'legacy')
 */
function get_pos_url(): string {
    if (defined('POS_UI') && POS_UI === 'new') {
        return APP_URL . '/pos/index.html#pos';
    }
    return APP_URL . '/index.php?view=pos';
}


