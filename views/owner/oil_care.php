<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/ImageStorage.php';
require_once dirname(__DIR__, 2) . '/core/TechnicalManager.php';
require_once dirname(__DIR__, 2) . '/core/StockControl.php';

Auth::requirePermission('oil_care.list');
$pageTitle = 'Oil Change & Car Care';
$pdo = Database::getInstance();

$currentRole = Auth::getRole();
$message = null;
$messageType = 'success';
$activeTab = $_GET['tab'] ?? (in_array($currentRole, ['stock_control', 'warehouse'], true) ? 'requests' : 'reminders');

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Product Add / Edit / Restock / Delete
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Add / Edit Product
    if ($action === 'save_product') {
        $pId = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
        if ($pId && !Auth::can('oil_care.edit')) {
            $message = 'Permission denied: Cannot edit product.';
            $messageType = 'danger';
        } elseif (!$pId && !Auth::can('oil_care.add')) {
            $message = 'Permission denied: Cannot add product.';
            $messageType = 'danger';
        } else {
        $nameEn = trim($_POST['name_en'] ?? '');
        $nameKm = trim($_POST['name_km'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $category = trim($_POST['category'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $unit = trim($_POST['unit'] ?? 'can');
        $costPrice = max(0.00, (float)($_POST['cost_price_usd'] ?? 0.00));
        $sellPrice = max(0.00, (float)($_POST['price_usd'] ?? 0.00));
        $priceKhr = (int)round($sellPrice * get_exchange_rate());
        $stockQty = (int)($_POST['stock_qty'] ?? 0);
        $minAlert = (int)($_POST['min_stock_alert'] ?? 5);
        $removeImage = (($_POST['remove_image'] ?? '0') === '1');

        // Resolve category_id and category slug
        if ($categoryId) {
            $catLookup = $pdo->prepare("SELECT id, code FROM categories WHERE id = ?");
            $catLookup->execute([$categoryId]);
            if ($cRow = $catLookup->fetch()) {
                $category = $cRow['code'];
            }
        } elseif ($category !== '') {
            $catLookup = $pdo->prepare("SELECT id, code FROM categories WHERE type = 'product' AND code = ?");
            $catLookup->execute([$category]);
            if ($cRow = $catLookup->fetch()) {
                $categoryId = (int)$cRow['id'];
            }
        }

        if (!$categoryId && empty($category)) {
            $catLookup = $pdo->query("SELECT id, code FROM categories WHERE type = 'product' ORDER BY sort_order ASC LIMIT 1");
            if ($cRow = $catLookup->fetch()) {
                $categoryId = (int)$cRow['id'];
                $category = $cRow['code'];
            } else {
                $category = 'engine_oil';
            }
        }

        if ($nameEn === '' || $sellPrice <= 0) {
            $message = 'Product name and selling price are required.';
            $messageType = 'danger';
            $activeTab = 'products';
        } else {
            if ($nameKm === '') $nameKm = $nameEn;

            // Handle optional image upload
            $hasNewImage = isset($_FILES['product_image']) && ($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $newImagePath = null;
            $newThumbPath = null;
            $newImageUpdated = null;
            $uploadFailed = false;

            if ($hasNewImage) {
                $uploadRes = ImageStorage::processUpload($_FILES['product_image'], 'products', 'prod');
                if (!$uploadRes['success']) {
                    $message = $uploadRes['error'];
                    $messageType = 'danger';
                    $uploadFailed = true;
                    $activeTab = 'products';
                } else {
                    $newImagePath = $uploadRes['image_path'];
                    $newThumbPath = $uploadRes['thumb_path'];
                    $newImageUpdated = $uploadRes['image_updated_at'];
                }
            }

            if (!$uploadFailed) {
                if ($pId) {
                    // Fetch existing image paths to clean up if replaced or removed
                    $curr = $pdo->prepare("SELECT image_path, thumb_path FROM products WHERE id = ?");
                    $curr->execute([$pId]);
                    $oldRow = $curr->fetch();
                    $oldImg = $oldRow['image_path'] ?? null;
                    $oldThumb = $oldRow['thumb_path'] ?? null;

                    if ($hasNewImage) {
                        // Delete previous image files
                        if ($oldImg || $oldThumb) {
                            ImageStorage::deleteFiles($oldImg, $oldThumb);
                        }
                        $stmt = $pdo->prepare("
                            UPDATE products SET 
                                name_en = ?, name_km = ?, category = ?, category_id = ?, brand = ?, unit = ?,
                                cost_price_usd = ?, price_usd = ?, price_khr = ?, stock_qty = ?, min_stock_alert = ?,
                                image_path = ?, thumb_path = ?, image_updated_at = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$nameEn, $nameKm, $category, $categoryId, $brand, $unit, $costPrice, $sellPrice, $priceKhr, $stockQty, $minAlert, $newImagePath, $newThumbPath, $newImageUpdated, $pId]);
                    } elseif ($removeImage) {
                        // User chose to remove existing image
                        if ($oldImg || $oldThumb) {
                            ImageStorage::deleteFiles($oldImg, $oldThumb);
                        }
                        $stmt = $pdo->prepare("
                            UPDATE products SET 
                                name_en = ?, name_km = ?, category = ?, category_id = ?, brand = ?, unit = ?,
                                cost_price_usd = ?, price_usd = ?, price_khr = ?, stock_qty = ?, min_stock_alert = ?,
                                image_path = NULL, thumb_path = NULL, image_updated_at = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$nameEn, $nameKm, $category, $categoryId, $brand, $unit, $costPrice, $sellPrice, $priceKhr, $stockQty, $minAlert, date('Y-m-d H:i:s'), $pId]);
                    } else {
                        // Keep existing image
                        $stmt = $pdo->prepare("
                            UPDATE products SET 
                                name_en = ?, name_km = ?, category = ?, category_id = ?, brand = ?, unit = ?,
                                cost_price_usd = ?, price_usd = ?, price_khr = ?, stock_qty = ?, min_stock_alert = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$nameEn, $nameKm, $category, $categoryId, $brand, $unit, $costPrice, $sellPrice, $priceKhr, $stockQty, $minAlert, $pId]);
                    }
                    audit_log('PRICE_CHANGE', "Updated catalog details/price for product '{$nameEn}' (Selling Price: $" . number_format($sellPrice, 2) . ")", 'product', (string)$pId);
                    $message = "Product '{$nameEn}' updated successfully!";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO products (name_en, name_km, category, category_id, brand, unit, cost_price_usd, price_usd, price_khr, stock_qty, min_stock_alert, image_path, thumb_path, image_updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$nameEn, $nameKm, $category, $categoryId, $brand, $unit, $costPrice, $sellPrice, $priceKhr, $stockQty, $minAlert, $newImagePath, $newThumbPath, $newImageUpdated]);
                    $newProdId = (int)$pdo->lastInsertId();
                    record_stock_movement($pdo, $newProdId, 'in', (float)$stockQty, 0.0, (float)$stockQty, "Initial stock for {$nameEn}");
                    audit_log('PRODUCT_CREATED', "Added product '{$nameEn}' at $" . number_format($sellPrice, 2) . " with initial stock {$stockQty}", 'product', (string)$newProdId);
                    $message = "Product '{$nameEn}' added to inventory!";
                }
                $activeTab = 'products';
            }
        }
        }
    }

    // 2. Quick Stock Adjustment (Records unique movement ID)
    elseif ($action === 'adjust_stock') {
        if (!Auth::can('oil_care.stock')) {
            $message = 'Permission denied: Cannot adjust product stock.';
            $messageType = 'danger';
        } else {
            $pId = (int)($_POST['product_id'] ?? 0);
            $qtyChange = (int)($_POST['qty_change'] ?? 0);
            if ($pId > 0 && $qtyChange != 0) {
                $curP = $pdo->prepare("SELECT stock_qty, name_en FROM products WHERE id = ?");
                $curP->execute([$pId]);
                $pRow = $curP->fetch();
                $prevQty = (float)($pRow['stock_qty'] ?? 0);
                $newQty = max(0.0, $prevQty + $qtyChange);

                $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?")->execute([$newQty, $pId]);
                record_stock_movement($pdo, $pId, ($qtyChange > 0 ? 'in' : 'out'), (float)abs($qtyChange), $prevQty, $newQty, "Quick stock adjust", "Adjusted by " . (Auth::getUsername() ?? 'staff'));
                audit_log('STOCK_ADJUSTMENT', "Adjusted stock for '{$pRow['name_en']}' by {$qtyChange} (From {$prevQty} to {$newQty})", 'product', (string)$pId);

                $message = "Stock adjusted successfully!";
                $activeTab = 'products';
            }
        }
    }

    // 3. Delete Product (Soft delete + image cleanup)
    elseif ($action === 'delete_product') {
        if (!Auth::can('oil_care.delete')) {
            $message = 'Permission denied: Cannot delete product.';
            $messageType = 'danger';
        } else {
            $pId = (int)($_POST['product_id'] ?? 0);
            if ($pId > 0) {
                $curr = $pdo->prepare("SELECT image_path, thumb_path, name_en FROM products WHERE id = ?");
                $curr->execute([$pId]);
                $pData = $curr->fetch();
                if ($pData) {
                    ImageStorage::deleteFiles($pData['image_path'] ?? null, $pData['thumb_path'] ?? null);
                    $pdo->prepare("UPDATE products SET is_active = 0, image_path = NULL, thumb_path = NULL, image_updated_at = ? WHERE id = ?")
                        ->execute([date('Y-m-d H:i:s'), $pId]);
                    $message = "Product '{$pData['name_en']}' removed and pictures cleaned up.";
                }
                $activeTab = 'products';
            }
        }
    }

    // 4. Approve Parts Request (Stock moves out with immutable stock history)
    elseif ($action === 'approve_parts_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        try {
            $res = TechnicalManager::approvePartsRequest(
                $pdo,
                $reqId,
                Auth::getUserId(),
                Auth::getFullName() ?? 'Stock Control',
                Auth::getRole() ?? 'stock_control'
            );
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
        $activeTab = 'requests';
    }

    // 5. Reject Parts Request with Reason
    elseif ($action === 'reject_parts_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? '');
        try {
            $res = TechnicalManager::rejectPartsRequest(
                $pdo,
                $reqId,
                $reason,
                Auth::getUserId(),
                Auth::getFullName() ?? 'Stock Control',
                Auth::getRole() ?? 'stock_control'
            );
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
        $activeTab = 'requests';
    }

    // 6. Log Physical Count Discrepancy
    elseif ($action === 'log_discrepancy') {
        $pId = (int)($_POST['product_id'] ?? 0);
        $counted = (int)($_POST['counted_qty'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        try {
            $res = TechnicalManager::logDiscrepancy(
                $pdo,
                $pId,
                $counted,
                $reason,
                Auth::getUserId(),
                Auth::getFullName() ?? 'Stock Control',
                Auth::getRole() ?? 'stock_control'
            );
            $message = $res['message'];
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
        $activeTab = 'discrepancies';
    }
}

// Fetch Oil Change Due Reminders
$reminderFilter = $_GET['filter'] ?? 'all';
$today = date('Y-m-d');
$soonDate = date('Y-m-d', strtotime('+30 days'));

$remSql = "SELECT * FROM oil_change_logs ORDER BY next_due_date ASC";
$allReminders = $pdo->query($remSql)->fetchAll();

$dueSoonCount = 0;
$overdueCount = 0;
$goodCount = 0;

$filteredReminders = [];
foreach ($allReminders as $rem) {
    $dueDate = $rem['next_due_date'];
    $diffDays = (int)round((strtotime($dueDate) - strtotime($today)) / 86400);

    if ($diffDays < 0) {
        $statusKey = 'overdue';
        $overdueCount++;
    } elseif ($diffDays <= 30) {
        $statusKey = 'due_soon';
        $dueSoonCount++;
    } else {
        $statusKey = 'good';
        $goodCount++;
    }

    $rem['diff_days'] = $diffDays;
    $rem['status_key'] = $statusKey;

    if ($reminderFilter === 'all' || $reminderFilter === $statusKey) {
        $filteredReminders[] = $rem;
    }
}

// Fetch All Product Categories
$productCategories = $pdo->query("SELECT * FROM categories WHERE type = 'product' AND is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$prodCatMap = [];
foreach ($productCategories as $pc) {
    $prodCatMap['id_' . $pc['id']] = $pc;
    $prodCatMap['code_' . $pc['code']] = $pc;
}

// Fetch All Products
$categoryFilter = $_GET['cat'] ?? 'all';
$pSql = "SELECT * FROM products WHERE is_active = 1";
$pParams = [];
if ($categoryFilter !== 'all') {
    if (is_numeric($categoryFilter)) {
        $pSql .= " AND category_id = ?";
        $pParams[] = (int)$categoryFilter;
    } else {
        $pSql .= " AND (category = ? OR category_id IN (SELECT id FROM categories WHERE code = ?))";
        $pParams[] = $categoryFilter;
        $pParams[] = $categoryFilter;
    }
}
$pSql .= " ORDER BY category_id ASC, category ASC, name_en ASC";
$pStmt = $pdo->prepare($pSql);
$pStmt->execute($pParams);
$products = $pStmt->fetchAll();

// Product counts
$catCounts = [
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn(),
];
$pCountsStmt = $pdo->query("SELECT category_id, category, COUNT(*) as cnt FROM products WHERE is_active = 1 GROUP BY category_id, category");
while ($r = $pCountsStmt->fetch()) {
    if (!empty($r['category_id'])) {
        $catCounts['id_' . $r['category_id']] = ($catCounts['id_' . $r['category_id']] ?? 0) + (int)$r['cnt'];
    }
    if (!empty($r['category'])) {
        $catCounts['code_' . $r['category']] = ($catCounts['code_' . $r['category']] ?? 0) + (int)$r['cnt'];
    }
}

// Fetch Parts Requests for Warehouse
$pendingReqCount = (int)$pdo->query("SELECT COUNT(*) FROM parts_requests WHERE status = 'pending'")->fetchColumn();
$partsRequests = $pdo->query("
    SELECT pr.*, o.order_number, o.plate_number, p.stock_qty, p.unit
    FROM parts_requests pr
    JOIN orders o ON pr.order_id = o.id
    JOIN products p ON pr.product_id = p.id
    ORDER BY CASE pr.status WHEN 'pending' THEN 1 ELSE 2 END, pr.id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Physical Inventory Discrepancies
$discrepancies = $pdo->query("
    SELECT * FROM stock_discrepancies ORDER BY id DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-warehouse mr-1" style="color: #ea580c;"></i> Warehouse &amp; Stock Control
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">Manage parts requests, real-time inventory, physical count discrepancies, and oil change records.</p>
    </div>
    <div style="display: flex; gap: 12px; align-items: center;">
        <?php if (Auth::can('pos.access')): ?>
            <a href="<?= get_pos_url() ?>" class="btn btn-primary">
                <i class="fa-solid fa-cash-register mr-1"></i> New Order / POS
            </a>
        <?php endif; ?>
        <?php if (Auth::can('oil_care.add')): ?>
            <button type="button" class="btn btn-secondary" onclick="openNewProductModal()">
                <i class="fa-solid fa-plus mr-1"></i> Add Product to Catalog
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Tabs Header -->
<div style="display: flex; gap: 8px; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 24px; flex-wrap: wrap;">
    <button type="button" class="btn btn-sm <?= ($activeTab === 'requests') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('requests')">
        <i class="fa-solid fa-boxes-packing mr-1" style="color: #ea580c;"></i> Parts Requests
        <?php if ($pendingReqCount > 0): ?>
            <span class="badge badge-danger" style="margin-left: 6px; font-weight: 800;"><?= $pendingReqCount ?> Pending</span>
        <?php else: ?>
            <span class="badge badge-slate" style="margin-left: 6px;">0</span>
        <?php endif; ?>
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'products') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('products')">
        <i class="fa-solid fa-boxes-stacked mr-1"></i> Products &amp; Stock (<?= $catCounts['all'] ?>)
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'discrepancies') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('discrepancies')">
        <i class="fa-solid fa-scale-unbalanced mr-1" style="color: #8b5cf6;"></i> Discrepancy Log (<?= count($discrepancies) ?>)
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'reminders') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('reminders')">
        <i class="fa-solid fa-bell mr-1" style="color: #f59e0b;"></i> Oil Due Reminders (<?= count($allReminders) ?>)
        <?php if ($dueSoonCount > 0 || $overdueCount > 0): ?>
            <span class="badge badge-amber" style="margin-left: 6px;"><?= $dueSoonCount + $overdueCount ?> Alert</span>
        <?php endif; ?>
    </button>
</div>

<!-- ========================================== -->
<!-- TAB: PARTS REQUESTS (WAREHOUSE LIVE FEED) -->
<!-- ========================================== -->
<div id="tab_requests" class="tab-pane" style="display: <?= ($activeTab === 'requests') ? 'block' : 'none' ?>;">
    <?php if ($pendingReqCount > 0): ?>
        <div class="card" style="border-left: 5px solid #ea580c; background: #fff7ed; padding: 16px 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 42px; height: 42px; border-radius: 8px; background: #ea580c; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 800; color: #9a3412; margin: 0;">
                        Incoming Technician Parts Requests (<?= $pendingReqCount ?> Pending)
                    </h3>
                    <div style="font-size: 12px; color: #c2410c;">Technicians in service bays requesting parts from warehouse stock. Stock will move out upon approval.</div>
                </div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="playWarehouseChime()">
                <i class="fa-solid fa-volume-high mr-1"></i> Sound Alert Active
            </button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
                    Warehouse Parts Request History
                </h3>
                <div class="text-xs text-muted">Stock Control reviews and releases parts directly to technician bays</div>
            </div>
            <span class="badge badge-secondary"><?= count($partsRequests) ?> Total Requests</span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Request UID</th>
                        <th>Vehicle &amp; Order</th>
                        <th>Part Requested</th>
                        <th>Requested Qty</th>
                        <th>Current Physical Stock</th>
                        <th>Requested By</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($partsRequests)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                No parts requests found. When a technician requests parts from their screen, it appears here in real-time.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($partsRequests as $pr): 
                            $prStatus = $pr['status'];
                            $canReview = ($prStatus === 'pending');
                        ?>
                            <tr style="<?= ($prStatus === 'pending') ? 'background: #fffbeb;' : '' ?>">
                                <td>
                                    <span style="font-family: var(--font-mono); font-weight: 700; font-size: 12px;">
                                        <?= htmlspecialchars($pr['request_uid']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div><strong style="font-family: var(--font-mono);"><?= htmlspecialchars($pr['plate_number']) ?></strong></div>
                                    <div class="text-xs text-muted">#<?= htmlspecialchars($pr['order_number']) ?></div>
                                </td>
                                <td>
                                    <strong style="color: var(--text-main);"><?= htmlspecialchars($pr['product_name']) ?></strong>
                                    <?php if (!empty($pr['notes'])): ?>
                                        <div class="text-xs text-muted"><i class="fa-solid fa-comment mr-1"></i><?= htmlspecialchars($pr['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="font-size: 15px; color: #ea580c;"><?= (int)$pr['qty_requested'] ?></strong>
                                    <span class="text-xs text-muted"><?= htmlspecialchars($pr['unit'] ?? 'pcs') ?></span>
                                </td>
                                <td>
                                    <strong><?= (int)$pr['stock_qty'] ?></strong>
                                    <span class="text-xs text-muted"><?= htmlspecialchars($pr['unit'] ?? 'pcs') ?> in stock</span>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($pr['requested_by_name']) ?></div>
                                </td>
                                <td>
                                    <div class="text-xs"><?= date('M d, H:i', strtotime($pr['requested_at'])) ?></div>
                                </td>
                                <td>
                                    <?php if ($prStatus === 'pending'): ?>
                                        <span class="badge badge-amber"><i class="fa-solid fa-hourglass-half mr-1"></i> Pending Review</span>
                                    <?php elseif ($prStatus === 'approved'): ?>
                                        <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Approved</span>
                                        <div class="text-xs text-muted" style="margin-top: 2px;">By <?= htmlspecialchars($pr['reviewed_by_name'] ?? 'Staff') ?></div>
                                    <?php elseif ($prStatus === 'rejected'): ?>
                                        <span class="badge badge-danger"><i class="fa-solid fa-circle-xmark mr-1"></i> Rejected</span>
                                        <div class="text-xs text-danger" style="margin-top: 2px;"><?= htmlspecialchars($pr['review_reason'] ?? '') ?></div>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><?= ucfirst($prStatus) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($canReview): ?>
                                        <div style="display: inline-flex; gap: 6px;">
                                            <form action="" method="POST" style="margin: 0;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="approve_parts_request">
                                                <input type="hidden" name="request_id" value="<?= $pr['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-sm" style="font-weight: 700;">
                                                    <i class="fa-solid fa-check mr-1"></i> Approve
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-danger btn-sm" style="font-weight: 700;" onclick="openRejectPartsModal(<?= $pr['id'] ?>, '<?= htmlspecialchars($pr['product_name'], ENT_QUOTES) ?>')">
                                                <i class="fa-solid fa-xmark mr-1"></i> Reject
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- TAB: DISCREPANCY LOG (PHYSICAL COUNT)     -->
<!-- ========================================== -->
<div id="tab_discrepancies" class="tab-pane" style="display: <?= ($activeTab === 'discrepancies') ? 'block' : 'none' ?>;">
    <div class="card" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 10px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
                    Physical Inventory Count Discrepancy Log
                </h3>
                <div class="text-xs text-muted">Record differences found during physical shelf counting. Adjustments are logged to immutable stock ledger.</div>
            </div>
            <button type="button" class="btn btn-primary" onclick="openDiscrepancyModal(0, '', 0)">
                <i class="fa-solid fa-plus mr-1"></i> Log Physical Count Discrepancy
            </button>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Discrepancy UID</th>
                        <th>Date &amp; Time</th>
                        <th>Product Name</th>
                        <th>System Qty</th>
                        <th>Physical Counted</th>
                        <th>Difference</th>
                        <th>Reason / Notes</th>
                        <th>Logged By</th>
                        <th>Device</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($discrepancies)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                No stock discrepancies recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($discrepancies as $disc): 
                            $diff = (int)$disc['difference_qty'];
                        ?>
                            <tr>
                                <td>
                                    <span style="font-family: var(--font-mono); font-weight: 700; font-size: 12px;">
                                        <?= htmlspecialchars($disc['discrepancy_uid']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-xs"><?= date('M d, Y H:i', strtotime($disc['logged_at'])) ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($disc['product_name']) ?></strong>
                                </td>
                                <td><?= (int)$disc['system_qty'] ?></td>
                                <td><strong style="color: var(--primary);"><?= (int)$disc['counted_qty'] ?></strong></td>
                                <td>
                                    <?php if ($diff < 0): ?>
                                        <span class="badge badge-danger"><?= $diff ?> (Shortage)</span>
                                    <?php elseif ($diff > 0): ?>
                                        <span class="badge badge-success">+<?= $diff ?> (Overage)</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">0 (Match)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 12.5px;"><?= htmlspecialchars($disc['reason']) ?></div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($disc['logged_by_name']) ?></div>
                                    <div class="text-xs text-muted"><?= strtoupper(htmlspecialchars($disc['logged_by_role'])) ?></div>
                                </td>
                                <td>
                                    <span class="text-xs text-muted"><?= htmlspecialchars(substr($disc['device_name'] ?? 'System', 0, 30)) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- TAB 1: OIL CHANGE DUE REMINDERS -->
<!-- ========================================== -->
<div id="tab_reminders" class="tab-pane" style="display: <?= ($activeTab === 'reminders') ? 'block' : 'none' ?>;">
    
    <!-- Status KPI Cards -->
    <div class="grid grid-cols-3" style="gap: 16px; margin-bottom: 24px;">
        <div class="stat-card" style="border-left: 6px solid var(--danger);">
            <div class="stat-icon" style="background: var(--danger-bg); color: var(--danger);">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <div class="stat-value" style="color: var(--danger);"><?= $overdueCount ?> Vehicles</div>
                <div class="stat-label">Overdue for Oil Change</div>
            </div>
        </div>

        <div class="stat-card" style="border-left: 6px solid var(--warning);">
            <div class="stat-icon amber">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <div class="stat-value" style="color: var(--warning);"><?= $dueSoonCount ?> Vehicles</div>
                <div class="stat-label">Due Soon (Next 30 Days)</div>
            </div>
        </div>

        <div class="stat-card" style="border-left: 6px solid var(--success);">
            <div class="stat-icon green">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <div class="stat-value" style="color: var(--success);"><?= $goodCount ?> Vehicles</div>
                <div class="stat-label">Good Status (> 30 Days)</div>
            </div>
        </div>
    </div>

    <!-- Filter Buttons & Search -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; gap: 8px;">
            <a href="?view=oil_care&tab=reminders&filter=all" class="btn btn-sm <?= ($reminderFilter === 'all') ? 'btn-primary' : 'btn-secondary' ?>">All Vehicles</a>
            <a href="?view=oil_care&tab=reminders&filter=due_soon" class="btn btn-sm <?= ($reminderFilter === 'due_soon') ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fa-solid fa-triangle-exclamation mr-1" style="color: var(--warning);"></i> Due Soon (<?= $dueSoonCount ?>)
            </a>
            <a href="?view=oil_care&tab=reminders&filter=overdue" class="btn btn-sm <?= ($reminderFilter === 'overdue') ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fa-solid fa-circle-exclamation mr-1" style="color: var(--danger);"></i> Overdue (<?= $overdueCount ?>)
            </a>
            <a href="?view=oil_care&tab=reminders&filter=good" class="btn btn-sm <?= ($reminderFilter === 'good') ? 'btn-primary' : 'btn-secondary' ?>">Good</a>
        </div>
        <input type="text" id="tableSearchInput" class="form-input" style="width: 240px; min-height: 38px; padding: 4px 12px;" placeholder="Search plate or customer...">
    </div>

    <!-- Reminders Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table searchable-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Plate Number</th>
                        <th style="width: 180px;">Customer</th>
                        <th>Oil & Filter Package</th>
                        <th style="width: 140px;">Last Service</th>
                        <th style="width: 150px;">Next Due Mileage</th>
                        <th style="width: 140px;">Next Due Date</th>
                        <th style="width: 130px;">Due Status</th>
                        <th style="text-align: right; width: 140px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filteredReminders)): ?>
                        <tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 32px;">No vehicles found for this filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filteredReminders as $r): ?>
                        <tr>
                            <td>
                                <span style="font-family: var(--font-mono); font-weight: 800; font-size: 14px; background: var(--bg-subtle); padding: 3px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                    <?= htmlspecialchars($r['plate_number']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: var(--text-main); font-size: 14px;"><?= htmlspecialchars((string)($r['customer_name'] ?? 'Customer')) ?></div>
                                <?php if (!empty($r['customer_phone'])): ?>
                                    <div class="text-xs text-muted"><i class="fa-solid fa-phone mr-1"></i> <?= htmlspecialchars((string)$r['customer_phone']) ?></div>
                                <?php else: ?>
                                    <div class="text-xs text-muted">-</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 13.5px;"><?= htmlspecialchars((string)($r['oil_product_name'] ?? 'Engine Oil Change')) ?></div>
                                <?php if (!empty($r['notes'])): ?>
                                    <div class="text-xs text-muted"><?= htmlspecialchars((string)$r['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= number_format((int)$r['current_mileage']) ?> km</strong></div>
                                <div class="text-xs text-muted"><?= htmlspecialchars((string)$r['oil_change_date']) ?></div>
                            </td>
                            <td>
                                <strong style="font-size: 15px; color: var(--primary);">
                                    <?= number_format((int)$r['next_due_mileage']) ?> km
                                </strong>
                                <div class="text-xs text-muted">+10,000 km interval</div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars((string)$r['next_due_date']) ?></strong>
                            </td>
                            <td>
                                <?php if ($r['status_key'] === 'overdue'): ?>
                                    <span class="badge badge-danger">
                                        <i class="fa-solid fa-triangle-exclamation mr-1"></i> Overdue (<?= abs($r['diff_days']) ?>d ago)
                                    </span>
                                <?php elseif ($r['status_key'] === 'due_soon'): ?>
                                    <span class="badge badge-warning">
                                        <i class="fa-solid fa-clock mr-1"></i> Due in <?= $r['diff_days'] ?> days
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-success">
                                        <i class="fa-solid fa-circle-check mr-1"></i> <?= $r['diff_days'] ?> days left
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <?php if (!empty($r['customer_phone'])): ?>
                                        <a href="tel:<?= htmlspecialchars((string)$r['customer_phone']) ?>" class="btn btn-secondary btn-sm" title="Call Customer">
                                            <i class="fa-solid fa-phone"></i>
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary btn-sm" title="Send Telegram / SMS Reminder" data-customer="<?= htmlspecialchars($r['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?>" data-plate="<?= htmlspecialchars($r['plate_number'], ENT_QUOTES, 'UTF-8') ?>" data-mileage="<?= number_format((int)$r['next_due_mileage']) ?>" data-due="<?= htmlspecialchars($r['next_due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" onclick="openReminderAlertModal(this)">
                                        <i class="fa-brands fa-telegram" style="color: #0284c7;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- TAB 2: PRODUCTS & STOCK CATALOG -->
<!-- ========================================== -->
<div id="tab_products" class="tab-pane" style="display: <?= ($activeTab === 'products') ? 'block' : 'none' ?>;">
    
    <!-- Category Filter Bar -->
    <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
        <a href="?view=oil_care&tab=products&cat=all" class="btn btn-sm <?= ($categoryFilter === 'all') ? 'btn-primary' : 'btn-secondary' ?>">
            All Products (<?= $catCounts['all'] ?>)
        </a>
        <?php foreach ($productCategories as $pc): 
            $isActiveFilter = ($categoryFilter === $pc['code'] || $categoryFilter === (string)$pc['id']);
            $cnt = max($catCounts['id_' . $pc['id']] ?? 0, $catCounts['code_' . $pc['code']] ?? 0);
            $pcIcon = !empty($pc['icon']) ? $pc['icon'] : 'fa-box';
            $pcColor = !empty($pc['color']) ? $pc['color'] : '#ea580c';
            $pcName = (Language::current() === 'km') ? $pc['name_km'] : $pc['name_en'];
        ?>
            <a href="?view=oil_care&tab=products&cat=<?= htmlspecialchars($pc['code']) ?>" class="btn btn-sm <?= $isActiveFilter ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fa-solid <?= htmlspecialchars($pcIcon) ?> mr-1" style="<?= !$isActiveFilter ? 'color: ' . htmlspecialchars($pcColor) . ';' : '' ?>"></i>
                <?= htmlspecialchars($pcName) ?> (<?= $cnt ?>)
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Products Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product &amp; Brand</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>Price (USD / ៛)</th>
                        <th>Physical Stock</th>
                        <th>Reserved</th>
                        <th>Available</th>
                        <th>Alert Status</th>
                        <th style="text-align: right; width: 220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): 
                        $physical = (int)$p['stock_qty'];
                        $reserved = (int)($p['reserved_qty'] ?? 0);
                        $avail = max(0, $physical - $reserved);
                        $isLow = $physical <= (int)$p['min_stock_alert'];
                        $cObj = null;
                        if (!empty($p['category_id']) && isset($prodCatMap['id_' . $p['category_id']])) {
                            $cObj = $prodCatMap['id_' . $p['category_id']];
                        } elseif (!empty($p['category']) && isset($prodCatMap['code_' . $p['category']])) {
                            $cObj = $prodCatMap['code_' . $p['category']];
                        }
                        $catIcon = $cObj['icon'] ?? 'fa-oil-can';
                        $catColor = $cObj['color'] ?? '#ea580c';
                        $catName = $cObj ? ((Language::current() === 'km') ? $cObj['name_km'] : $cObj['name_en']) : ucfirst(str_replace('_', ' ', $p['category']));
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <?php 
                                $hasImg = !empty($p['thumb_path']) && file_exists(dirname(__DIR__, 2) . '/' . $p['thumb_path']);
                                if ($hasImg): ?>
                                    <img src="<?= APP_URL ?>/<?= htmlspecialchars($p['thumb_path']) ?>?v=<?= strtotime($p['image_updated_at'] ?? 'now') ?>" alt="Product" style="width: 44px; height: 44px; object-fit: contain; border-radius: var(--radius-md); background: #ffffff; border: 1px solid var(--border); padding: 2px; flex-shrink: 0; box-shadow: var(--shadow-xs);">
                                <?php else: ?>
                                    <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: <?= htmlspecialchars($catColor) ?>18; color: <?= htmlspecialchars($catColor) ?>; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; border: 1px solid <?= htmlspecialchars($catColor) ?>35;">
                                        <i class="fa-solid <?= htmlspecialchars($catIcon) ?>"></i>
                                    </div>
                                <?php endif; ?>
                                <div style="min-width: 0;">
                                    <div style="font-weight: 700; font-size: 14.5px; color: var(--text-main);">
                                        <?= htmlspecialchars($p['name_en']) ?>
                                    </div>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($p['name_km']) ?></div>
                                    <?php if (!empty($p['brand'])): ?>
                                        <span class="badge badge-slate text-xs" style="margin-top: 4px;"><?= htmlspecialchars($p['brand']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge" style="font-size: 11px; background: <?= htmlspecialchars($catColor) ?>18; color: <?= htmlspecialchars($catColor) ?>; border: 1px solid <?= htmlspecialchars($catColor) ?>35;">
                                <i class="fa-solid <?= htmlspecialchars($catIcon) ?> mr-1"></i> <?= htmlspecialchars($catName) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($p['unit']) ?></td>
                        <td>
                            <div><strong style="color: var(--primary); font-size: 15px;"><?= formatUSD((float)$p['price_usd']) ?></strong></div>
                            <div class="text-xs text-muted"><?= formatKHR((int)$p['price_khr']) ?></div>
                        </td>
                        <td>
                            <strong style="font-size: 16px; color: <?= $isLow ? 'var(--danger)' : '#16a34a' ?>;">
                                <?= $physical ?>
                            </strong>
                        </td>
                        <td>
                            <span class="badge badge-secondary" style="font-weight: 700;"><?= $reserved ?></span>
                        </td>
                        <td>
                            <strong style="font-size: 16px; color: <?= ($avail <= 0) ? 'var(--danger)' : 'var(--text-main)' ?>;">
                                <?= $avail ?>
                            </strong>
                        </td>
                        <td>
                            <?php if ($physical <= 0): ?>
                                <span class="badge badge-danger"><i class="fa-solid fa-ban mr-1"></i> Out of Stock</span>
                            <?php elseif ($isLow): ?>
                                <span class="badge badge-warning"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Low (≤ <?= $p['min_stock_alert'] ?>)</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check mr-1"></i> OK</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                <!-- Restock button -->
                                <form action="" method="POST" style="display: inline-flex; gap: 4px; align-items: center; margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="adjust_stock">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <input type="number" name="qty_change" style="width: 48px; min-height: 30px; padding: 2px 4px; text-align: center; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 12px;" value="5">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Add stock"><i class="fa-solid fa-plus"></i></button>
                                </form>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="openDiscrepancyModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name_en'], ENT_QUOTES) ?>', <?= $physical ?>)" title="Log Physical Count Discrepancy">
                                    <i class="fa-solid fa-scale-unbalanced" style="color: #8b5cf6;"></i>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" data-product="<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="openEditProductModal(this)" title="Edit Product">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Add / Edit Product -->
<div class="modal-backdrop" id="productModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="pModalTitle">Add Product to Catalog</h3>
            <button type="button" class="modal-close" onclick="closeModal('productModal')">&times;</button>
        </div>
        <form action="" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="product_id" id="prod_id" value="">

            <div class="modal-body">
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Product Name (English) *</label>
                        <input type="text" name="name_en" id="prod_name_en" class="form-input" placeholder="e.g. Castrol EDGE 5W-30 (4L)" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Name (Khmer)</label>
                        <input type="text" name="name_km" id="prod_name_km" class="form-input" placeholder="e.g. ប្រេងម៉ាស៊ីន Castrol 5W-30">
                    </div>
                </div>

                <div class="grid grid-cols-3" style="gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <input type="hidden" name="category_id" id="prod_category_id" value="">
                        <select name="category" id="prod_category" class="form-select" onchange="syncProdCategoryId(this)">
                            <?php foreach ($productCategories as $pc): 
                                $pcName = (Language::current() === 'km') ? $pc['name_km'] : $pc['name_en'];
                            ?>
                                <option value="<?= htmlspecialchars($pc['code']) ?>" data-id="<?= $pc['id'] ?>"><?= htmlspecialchars($pcName) ?> (<?= htmlspecialchars($pc['name_en']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Brand</label>
                        <input type="text" name="brand" id="prod_brand" class="form-input" placeholder="e.g. Castrol, Mobil 1">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Packaging Unit</label>
                        <input type="text" name="unit" id="prod_unit" class="form-input" placeholder="can (4L) / bottle (1L) / pcs" value="can (4L)">
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Cost Price ($ USD)</label>
                        <input type="number" step="0.25" min="0" name="cost_price_usd" id="prod_cost_price" class="form-input" placeholder="28.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Selling Price ($ USD) *</label>
                        <input type="number" step="0.25" min="0.25" name="price_usd" id="prod_sell_price" class="form-input" style="font-weight: 700; font-size: 16px;" placeholder="38.00" required>
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Initial Stock Quantity *</label>
                        <input type="number" name="stock_qty" id="prod_stock_qty" class="form-input" value="12" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Minimum Stock Alert</label>
                        <input type="number" name="min_stock_alert" id="prod_min_alert" class="form-input" value="5">
                    </div>
                </div>

                <!-- Product Picture Upload Field -->
                <div class="form-group" style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); margin-bottom: 0;">
                    <label class="form-label" style="display: flex; align-items: center; justify-content: space-between;">
                        <span><i class="fa-solid fa-image mr-1" style="color: var(--primary);"></i> Product Picture</span>
                        <span class="text-xs text-muted">JPG, PNG, WebP (Max 5 MB)</span>
                    </label>
                    <input type="hidden" name="remove_image" id="prod_remove_image" value="0">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <!-- Preview Box -->
                        <div id="prod_img_preview_box" style="width: 84px; height: 84px; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-subtle); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; flex-shrink: 0; box-shadow: var(--shadow-xs);">
                            <img id="prod_img_preview" src="" alt="Preview" style="display: none; width: 100%; height: 100%; object-fit: contain; background: #ffffff; padding: 2px;">
                            <div id="prod_img_placeholder" style="color: var(--text-muted); font-size: 26px; text-align: center;">
                                <i class="fa-solid fa-box-open"></i>
                            </div>
                        </div>

                        <div style="flex: 1;">
                            <input type="file" name="product_image" id="prod_image_input" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handleProductImageSelect(this)">
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('prod_image_input').click()">
                                    <i class="fa-solid fa-upload mr-1"></i> <span id="prod_img_btn_text">Choose Picture</span>
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" id="prod_img_remove_btn" onclick="removeProductImage()" style="display: none;">
                                    <i class="fa-solid fa-trash mr-1"></i> Remove
                                </button>
                            </div>
                            <p class="text-xs text-muted" id="prod_img_help_text" style="margin-top: 6px;">
                                Upload a picture from your computer. It is automatically formatted into 600px and 200px square thumbnails without stretching.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('productModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Send Reminder Message Preview -->
<div class="modal-backdrop" id="reminderAlertModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-brands fa-telegram mr-1" style="color: #0284c7;"></i> Send Oil Change Reminder</h3>
            <button type="button" class="modal-close" onclick="closeModal('reminderAlertModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="background: var(--bg-subtle); border: 1px solid var(--border); padding: 16px; border-radius: var(--radius-md); font-family: var(--font-mono); font-size: 13px; line-height: 1.5;" id="reminderMsgBox">
                <!-- Injected via JS -->
            </div>
            <p class="text-xs text-muted" style="margin-top: 14px;">
                You can copy this text to send via Telegram or SMS to remind the customer that their vehicle is due for maintenance.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('reminderAlertModal')">Close</button>
            <button type="button" class="btn btn-primary" onclick="copyReminderText()"><i class="fa-solid fa-copy mr-1"></i> Copy Message</button>
        </div>
    </div>
</div>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
    document.getElementById('tab_' + tabId).style.display = 'block';

    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);
}

function resetProductImageUI() {
    const input = document.getElementById('prod_image_input');
    if (input) input.value = '';
    const rem = document.getElementById('prod_remove_image');
    if (rem) rem.value = '0';
    const preview = document.getElementById('prod_img_preview');
    if (preview) {
        preview.src = '';
        preview.style.display = 'none';
    }
    const ph = document.getElementById('prod_img_placeholder');
    if (ph) ph.style.display = 'block';
    const btnText = document.getElementById('prod_img_btn_text');
    if (btnText) btnText.innerText = 'Choose Picture';
    const remBtn = document.getElementById('prod_img_remove_btn');
    if (remBtn) remBtn.style.display = 'none';
}

function setProductImagePreview(url) {
    if (url) {
        const preview = document.getElementById('prod_img_preview');
        preview.src = url;
        preview.style.display = 'block';
        document.getElementById('prod_img_placeholder').style.display = 'none';
        document.getElementById('prod_img_btn_text').innerText = 'Replace Picture';
        document.getElementById('prod_img_remove_btn').style.display = 'inline-flex';
    } else {
        resetProductImageUI();
    }
}

function handleProductImageSelect(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    // Client-side validation: Max 5MB
    if (file.size > 5 * 1024 * 1024) {
        alert('This picture is too large (' + (file.size / (1024 * 1024)).toFixed(2) + ' MB). The maximum allowed size is 5 MB.');
        input.value = '';
        return;
    }

    // Client-side validation: Reject SVG
    const name = file.name.toLowerCase();
    if (name.endsWith('.svg') || file.type === 'image/svg+xml') {
        alert('SVG vector files are not allowed. Please choose a real JPG, PNG, or WebP picture.');
        input.value = '';
        return;
    }

    // Check allowed image MIME/extensions
    const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!validTypes.includes(file.type) && !name.endsWith('.jpg') && !name.endsWith('.jpeg') && !name.endsWith('.png') && !name.endsWith('.webp')) {
        alert('Invalid image format. Please select a JPG, PNG, or WebP picture.');
        input.value = '';
        return;
    }

    document.getElementById('prod_remove_image').value = '0';
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('prod_img_preview').src = e.target.result;
        document.getElementById('prod_img_preview').style.display = 'block';
        document.getElementById('prod_img_placeholder').style.display = 'none';
        document.getElementById('prod_img_btn_text').innerText = 'Replace Picture';
        document.getElementById('prod_img_remove_btn').style.display = 'inline-flex';
    };
    reader.readAsDataURL(file);
}

function removeProductImage() {
    document.getElementById('prod_image_input').value = '';
    document.getElementById('prod_remove_image').value = '1';
    document.getElementById('prod_img_preview').src = '';
    document.getElementById('prod_img_preview').style.display = 'none';
    document.getElementById('prod_img_placeholder').style.display = 'block';
    document.getElementById('prod_img_btn_text').innerText = 'Choose Picture';
    document.getElementById('prod_img_remove_btn').style.display = 'none';
}

const productCategoriesList = <?= json_encode($productCategories) ?>;

function syncProdCategoryId(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.id) {
        document.getElementById('prod_category_id').value = opt.dataset.id;
    }
}

function openNewProductModal() {
    document.getElementById('pModalTitle').innerText = 'Add Product to Catalog';
    document.getElementById('prod_id').value = '';
    document.getElementById('prod_name_en').value = '';
    document.getElementById('prod_name_km').value = '';
    
    const defaultCat = productCategoriesList[0] || { id: '', code: 'engine_oil' };
    document.getElementById('prod_category').value = defaultCat.code;
    document.getElementById('prod_category_id').value = defaultCat.id;
    
    document.getElementById('prod_brand').value = '';
    document.getElementById('prod_unit').value = 'can (4L)';
    document.getElementById('prod_cost_price').value = '25.00';
    document.getElementById('prod_sell_price').value = '35.00';
    document.getElementById('prod_stock_qty').value = '10';
    document.getElementById('prod_min_alert').value = '4';
    resetProductImageUI();
    openModal('productModal');
}

function openEditProductModal(btnOrP) {
    let p = btnOrP;
    if (btnOrP && btnOrP.getAttribute && btnOrP.getAttribute('data-product')) {
        try {
            p = JSON.parse(btnOrP.getAttribute('data-product'));
        } catch (e) {
            console.error('Error parsing product data:', e);
            return;
        }
    }
    if (!p) return;

    document.getElementById('pModalTitle').innerText = 'Edit Product: ' + (p.name_en || '');
    document.getElementById('prod_id').value = p.id || '';
    document.getElementById('prod_name_en').value = p.name_en || '';
    document.getElementById('prod_name_km').value = p.name_km || '';

    let matchedCat = (Array.isArray(productCategoriesList))
        ? productCategoriesList.find(c => (p.category_id && c.id == p.category_id) || (p.category && c.code === p.category))
        : null;
    if (matchedCat) {
        document.getElementById('prod_category').value = matchedCat.code;
        document.getElementById('prod_category_id').value = matchedCat.id;
    } else {
        document.getElementById('prod_category').value = p.category || '';
        document.getElementById('prod_category_id').value = p.category_id || '';
    }

    document.getElementById('prod_brand').value = p.brand || '';
    document.getElementById('prod_unit').value = p.unit || 'unit';
    document.getElementById('prod_cost_price').value = p.cost_price_usd ? parseFloat(p.cost_price_usd).toFixed(2) : '0.00';
    document.getElementById('prod_sell_price').value = p.price_usd ? parseFloat(p.price_usd).toFixed(2) : '0.00';
    document.getElementById('prod_stock_qty').value = p.stock_qty ?? 0;
    document.getElementById('prod_min_alert').value = p.min_stock_alert ?? 0;
    resetProductImageUI();
    if (p.thumb_path || p.image_path) {
        const url = '<?= APP_URL ?>/' + (p.thumb_path || p.image_path) + '?v=' + (p.image_updated_at || Date.now());
        setProductImagePreview(url);
    }
    openModal('productModal');
}

let currentReminderCopyText = '';
function openReminderAlertModal(btnOrName, plate, nextMileage, dueDate) {
    let customerName = btnOrName;
    if (btnOrName && btnOrName.getAttribute && btnOrName.getAttribute('data-customer')) {
        customerName = btnOrName.getAttribute('data-customer');
        plate = btnOrName.getAttribute('data-plate');
        nextMileage = btnOrName.getAttribute('data-mileage');
        dueDate = btnOrName.getAttribute('data-due');
    }
    const text = `AquaGleam Oil Change Reminder:\nDear ${customerName}, your vehicle ${plate} is due for its next engine oil change at ${nextMileage} KM (Due by: ${dueDate}). Visit us for premium synthetic oil & genuine filters! Tel: 012 334 556`;
    currentReminderCopyText = text;
    document.getElementById('reminderMsgBox').innerText = text;
    openModal('reminderAlertModal');
}

function copyReminderText() {
    navigator.clipboard.writeText(currentReminderCopyText).then(() => {
        alert('Reminder message copied to clipboard!');
    });
}
<!-- Modal: Reject Parts Request -->
<div class="modal-backdrop" id="rejectPartsModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: var(--danger);">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Reject Parts Request
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('rejectPartsModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject_parts_request">
            <input type="hidden" name="request_id" id="reject_parts_id" value="">

            <div class="modal-body">
                <p class="text-sm text-muted" style="margin-bottom: 14px;">
                    Rejecting request for: <strong id="reject_parts_product_name">-</strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Rejection Reason <span style="color: var(--danger);">* (Mandatory)</span></label>
                    <textarea name="reject_reason" class="form-textarea" rows="3" placeholder="e.g. Out of physical stock; wrong specification for this vehicle..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('rejectPartsModal')">Cancel</button>
                <button type="submit" class="btn btn-danger" style="font-weight: 700;">
                    <i class="fa-solid fa-ban mr-1"></i> Confirm Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Log Physical Count Discrepancy -->
<div class="modal-backdrop" id="discrepancyModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #8b5cf6;">
                <i class="fa-solid fa-scale-unbalanced mr-1"></i> Log Physical Count Discrepancy
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('discrepancyModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="log_discrepancy">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Product *</label>
                    <select name="product_id" id="disc_product_id" class="form-select" required onchange="syncDiscrepancyProduct(this)">
                        <option value="">-- Choose product --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>" data-stock="<?= (int)$p['stock_qty'] ?>">
                                <?= htmlspecialchars($p['name_en']) ?> (System Stock: <?= (int)$p['stock_qty'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2" style="gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">System Expected Stock</label>
                        <input type="number" id="disc_system_qty" class="form-input" readonly value="0" style="background: var(--bg-subtle);">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Physical Counted Stock *</label>
                        <input type="number" name="counted_qty" id="disc_counted_qty" class="form-input" required min="0" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason for Variance / Audit Notes *</label>
                    <textarea name="reason" class="form-textarea" rows="2" placeholder="e.g. Broken bottle during restocking, or physical count discrepancy at weekly audit." required></textarea>
                </div>
                <div class="text-xs text-muted">
                    This discrepancy is recorded in the Discrepancy Log and writes an adjusting movement into the immutable stock ledger.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('discrepancyModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #8b5cf6; border-color: #8b5cf6; font-weight: 700;">
                    <i class="fa-solid fa-save mr-1"></i> Record Discrepancy
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectPartsModal(reqId, productName) {
    document.getElementById('reject_parts_id').value = reqId;
    document.getElementById('reject_parts_product_name').textContent = productName;
    openModal('rejectPartsModal');
}

function openDiscrepancyModal(productId, productName, currentStock) {
    if (productId > 0) {
        document.getElementById('disc_product_id').value = productId;
        document.getElementById('disc_system_qty').value = currentStock;
        document.getElementById('disc_counted_qty').value = currentStock;
    } else {
        document.getElementById('disc_product_id').value = '';
        document.getElementById('disc_system_qty').value = 0;
        document.getElementById('disc_counted_qty').value = 0;
    }
    openModal('discrepancyModal');
}

function syncDiscrepancyProduct(sel) {
    const opt = sel.options[sel.selectedIndex];
    const stock = opt.getAttribute('data-stock') || 0;
    document.getElementById('disc_system_qty').value = stock;
    document.getElementById('disc_counted_qty').value = stock;
}

function playWarehouseChime() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(659.25, ctx.currentTime); // E5
        osc.frequency.setValueAtTime(880, ctx.currentTime + 0.15); // A5
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
        osc.start();
        osc.stop(ctx.currentTime + 0.5);
    } catch (e) {
        console.warn('Audio synthesis not supported or blocked:', e);
    }
}

// Auto chime when pending requests exist
<?php if ($pendingReqCount > 0): ?>
window.addEventListener('DOMContentLoaded', function() {
    // Attempt playback after user interaction or load
    setTimeout(playWarehouseChime, 500);
});
<?php endif; ?>
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
