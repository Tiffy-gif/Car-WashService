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
$pdo = Database::getInstance();

$currentRole = Auth::getRole();
$message = null;
$messageType = 'success';

$pendingReqCount = (int)$pdo->query("SELECT COUNT(*) FROM parts_requests WHERE status = 'pending'")->fetchColumn();
$activeTab = $_GET['tab'] ?? ($pendingReqCount > 0 ? 'requests' : (in_array($currentRole, ['stock_control', 'warehouse'], true) ? 'requests' : 'products'));
$pageTitle = __('nav_products_warehouse', 'Products & Warehouse');

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

    // 7. Adjust Washing Supplies / Chemicals Stock
    elseif ($action === 'adjust_supply_stock') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $addQty = (float)($_POST['quantity_change'] ?? 0.00);
        if ($itemId > 0 && $addQty != 0) {
            $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?")->execute([$addQty, $itemId]);
            $message = "Chemical / Washing supply stock updated successfully!";
            $activeTab = 'supplies';
        }
    }

    // 8. Receive Supplier Delivery (Inbound PO)
    elseif ($action === 'receive_stock') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty_received'] ?? 0));
        $supplierRef = trim($_POST['supplier_ref'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $pStmt = $pdo->prepare("SELECT id, name_en, stock_qty FROM products WHERE id = ?");
        $pStmt->execute([$productId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);

        if ($prod) {
            $prevStock = (int)$prod['stock_qty'];
            $newStock = $prevStock + $qty;
            $fullNotes = "Received delivery: " . ($supplierRef ?: 'Restock') . ($notes ? " ({$notes})" : '');

            $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ?")->execute([$newStock, $productId]);
            $uid = StockControl::addLedgerEntry(
                $productId,
                $prod['name_en'],
                'received',
                (float)$qty,
                (float)$prevStock,
                (float)$newStock,
                $supplierRef ?: ('REC-' . date('Ymd')),
                $fullNotes,
                (int)Auth::getUserId()
            );

            AuditLogger::log('STOCK_RECEIVED', "Received {$qty} units of '{$prod['name_en']}'. New balance: {$newStock}. Ref: {$supplierRef}", 'product', (string)$productId);
            $message = "Received {$qty} units of '{$prod['name_en']}'. New balance is {$newStock}. (Ledger UID: {$uid})";
            $messageType = 'success';
        } else {
            $message = "Product not found.";
            $messageType = 'danger';
        }
        $activeTab = 'products';
    }

    // 9. Add Stock Correction Line (Manager PIN Authorization)
    elseif ($action === 'add_correction_line') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $newBalance = (int)($_POST['new_balance'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $mgrUsername = trim($_POST['manager_username'] ?? '');
        $mgrPin = (string)($_POST['manager_pin'] ?? '');

        try {
            $res = StockControl::addCorrectionLine($productId, $newBalance, $reason, $mgrUsername, $mgrPin, (int)Auth::getUserId());
            $message = $res['message'];
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
        $activeTab = 'ledger';
    }
}

// Fetch Oil Change Due Reminders (latest record per vehicle)
$reminderFilter = $_GET['filter'] ?? 'all';
$today = date('Y-m-d');
$soonDate = date('Y-m-d', strtotime('+30 days'));

// Check if status column exists in oil_change_logs
$hasStatusCol = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM oil_change_logs LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $hasStatusCol = !empty($colCheck);
} catch (Throwable $e) {
    $hasStatusCol = false;
}

if ($hasStatusCol) {
    $remSql = "SELECT o.* 
               FROM oil_change_logs o
               INNER JOIN (
                   SELECT plate_number, MAX(id) as max_id
                   FROM oil_change_logs
                   WHERE status != 'cancelled'
                   GROUP BY plate_number
               ) latest ON o.id = latest.max_id
               WHERE o.status != 'cancelled'
               ORDER BY o.next_due_date ASC";
} else {
    $remSql = "SELECT o.* 
               FROM oil_change_logs o
               INNER JOIN (
                   SELECT plate_number, MAX(id) as max_id
                   FROM oil_change_logs
                   GROUP BY plate_number
               ) latest ON o.id = latest.max_id
               ORDER BY o.next_due_date ASC";
}

try {
    $allReminders = $pdo->query($remSql)->fetchAll();
} catch (Throwable $e) {
    try {
        $fallbackSql = $hasStatusCol
            ? "SELECT * FROM oil_change_logs WHERE status != 'cancelled' ORDER BY next_due_date ASC"
            : "SELECT * FROM oil_change_logs ORDER BY next_due_date ASC";
        $allReminders = $pdo->query($fallbackSql)->fetchAll();
    } catch (Throwable $e2) {
        $allReminders = [];
    }
}

$dueSoonCount = 0;
$overdueCount = 0;
$goodCount = 0;

$filteredReminders = [];
foreach ($allReminders as $rem) {
    $dueDate = $rem['next_due_date'] ?? '';
    if (empty($dueDate) || strtotime($dueDate) === false) {
        $diffDays = 0;
        $statusKey = 'good';
        $goodCount++;
    } else {
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

// Fetch All Products & Compute Category & KPI Counts
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

// Fetch all active products for client-side search, filtering, and instant pagination
$allActiveProducts = $pdo->query("SELECT * FROM products WHERE is_active = 1 ORDER BY category_id ASC, category ASC, name_en ASC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate KPI Counts
$kpiCounts = [
    'all' => count($allActiveProducts),
    'in_stock' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0,
];

foreach ($allActiveProducts as $ap) {
    $apStock = (int)$ap['stock_qty'];
    $apRes = (int)($ap['reserved_qty'] ?? 0);
    $apAvail = max(0, $apStock - $apRes);
    $apMin = (int)($ap['min_stock_alert'] ?: 5);

    if ($apAvail <= 0) {
        $kpiCounts['out_of_stock']++;
    } elseif ($apAvail <= $apMin || $apAvail <= 10) {
        $kpiCounts['low_stock']++;
    } else {
        $kpiCounts['in_stock']++;
    }
}
$products = $allActiveProducts;
$categoryFilter = $_GET['cat'] ?? 'all';

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

// Fetch Stock Movements for Unified Movement History (Immutable Ledger)
$filterProdId = (int)($_GET['product_id'] ?? 0);
$filteredProductName = '';
if ($filterProdId > 0) {
    $totalMovementsCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = " . $filterProdId)->fetchColumn();
    $smStmt = $pdo->prepare("SELECT * FROM stock_movements WHERE product_id = ? ORDER BY created_at DESC LIMIT 100");
    $smStmt->execute([$filterProdId]);
    $stockMovements = $smStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allActiveProducts as $p) {
        if ($p['id'] == $filterProdId) {
            $filteredProductName = $p['name_en'];
            break;
        }
    }
} else {
    $totalMovementsCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
    $stockMovements = $pdo->query("SELECT * FROM stock_movements ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Physical Inventory Discrepancies
$discrepancies = $pdo->query("
    SELECT * FROM stock_discrepancies ORDER BY id DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Chemical & Washing Supplies
$supplies = $pdo->query("SELECT * FROM inventory ORDER BY category ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$lowSuppliesCount = 0;
foreach ($supplies as $s) {
    if ((float)$s['quantity'] <= (float)$s['min_alert_qty']) {
        $lowSuppliesCount++;
    }
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Products & Warehouse UI Styles (Harmonized with Project Design System) -->
<style>
/* Page Header */
.pw-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}
.pw-title {
    font-size: 24px;
    font-weight: 800;
    color: var(--text-main);
    margin: 0 0 4px 0;
    letter-spacing: -0.01em;
}
.pw-subtitle {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0;
}
.pw-header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* Navigation Tabs Bar (Clean button-pill style matching Services & Prices) */
.pw-tabs-nav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

/* 4 KPI Summary Cards Grid */
.pw-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
.pw-kpi-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 16px 20px;
    cursor: pointer;
    transition: all 0.15s ease;
    box-shadow: var(--shadow-sm);
    user-select: none;
}
.pw-kpi-card:hover {
    border-color: var(--primary-hover);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}
.pw-kpi-card.active {
    border: 1.5px solid var(--primary);
    background: var(--bg-surface);
    box-shadow: 0 2px 10px rgba(2, 132, 199, 0.12);
}
.pw-kpi-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.pw-kpi-num {
    font-size: 28px;
    font-weight: 800;
    color: var(--text-main);
    margin: 4px 0 2px 0;
    line-height: 1.2;
}
.pw-kpi-sub {
    font-size: 12px;
    color: var(--text-light);
}
.kpi-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.kpi-dot.in-stock { background: var(--success); }
.kpi-dot.low-stock { background: var(--warning); }
.kpi-dot.out-of-stock { background: var(--danger); }

/* Search & Filter Toolbar */
.pw-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.pw-search-wrap {
    position: relative;
    flex: 1;
    min-width: 280px;
    max-width: 480px;
}
.pw-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 14px;
}
.pw-search-input {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 10px 14px 10px 38px;
    font-size: 14px;
    background: var(--bg-surface);
    color: var(--text-main);
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    box-sizing: border-box;
}
.pw-search-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}
.pw-toolbar-right {
    display: flex;
    align-items: center;
    gap: 20px;
}
.pw-checkbox-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    user-select: none;
}
.pw-checkbox-label input[type="checkbox"] {
    accent-color: var(--primary);
    width: 16px;
    height: 16px;
    cursor: pointer;
}
.pw-sort-wrap {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted);
}
.pw-sort-select {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 500;
    background: var(--bg-surface);
    color: var(--text-main);
    outline: none;
    cursor: pointer;
}

/* Category Filter Pills (Button pills matching Services & Prices) */
.pw-cat-pills {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.pw-cat-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    white-space: nowrap;
    margin-right: 4px;
}

/* Products Table Card */
.pw-table-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}
.pw-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.pw-table th {
    padding: 14px 16px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid var(--border);
    background: var(--bg-subtle);
}
.pw-table td {
    padding: 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.pw-row {
    transition: background-color 0.15s ease;
}
.pw-row:hover {
    background-color: var(--bg-subtle);
}
.pw-row.selected-row {
    background-color: var(--primary-light) !important;
}

/* Product Info Cell */
.product-flex {
    display: flex;
    align-items: center;
    gap: 14px;
}
.product-icon-sq {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1px solid var(--border);
}
.product-name-en {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-main);
    line-height: 1.3;
}
.product-name-km {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 1px;
}
.product-meta {
    font-size: 12px;
    color: var(--text-light);
    margin-top: 2px;
}

/* Category Pill */
.cat-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: var(--radius-full);
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
    border: 1px solid var(--border);
}

/* Price Cell */
.price-main {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-main);
}
.price-sub {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 1px;
}

/* Stock Available Cell */
.stock-num {
    font-size: 18px;
    font-weight: 800;
    line-height: 1;
}
.stock-num.in-stock { color: var(--text-main); }
.stock-num.low-stock { color: var(--warning); }
.stock-num.out-of-stock { color: var(--danger); }
.stock-sub {
    font-size: 12px;
    color: var(--text-muted);
    margin-left: 6px;
}
.stock-track {
    height: 5px;
    width: 110px;
    background: var(--bg-subtle);
    border-radius: var(--radius-full);
    margin-top: 6px;
    overflow: hidden;
}
.stock-bar {
    height: 100%;
    border-radius: var(--radius-full);
    transition: width 0.3s ease;
}
.stock-bar.in-stock { background: var(--success); }
.stock-bar.low-stock { background: var(--warning); }
.stock-bar.out-of-stock { background: transparent; }

/* Status Pill */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: var(--radius-full);
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}
.status-pill .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}
.status-pill.in-stock { background: var(--success-bg); color: var(--success); }
.status-pill.in-stock .dot { background: var(--success); }
.status-pill.low-stock { background: var(--warning-bg); color: var(--warning); }
.status-pill.low-stock .dot { background: var(--warning); }
.status-pill.out-of-stock { background: var(--danger-bg); color: var(--danger); }
.status-pill.out-of-stock .dot { background: var(--danger); }

/* Row Action Buttons */
.btn-stock-plus {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-stock-plus:hover {
    background: var(--bg-subtle);
    border-color: var(--primary);
    color: var(--primary);
}
.btn-icon-sq {
    width: 32px;
    height: 32px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-icon-sq:hover {
    background: var(--bg-subtle);
    border-color: var(--primary);
    color: var(--primary);
}

/* Row Checkbox */
.pw-row-checkbox {
    accent-color: var(--primary);
    width: 16px;
    height: 16px;
    cursor: pointer;
}

/* Pagination Footer */
.pw-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    border-top: 1px solid var(--border);
    font-size: 13px;
    color: var(--text-muted);
    flex-wrap: wrap;
    gap: 12px;
}
.pw-rows-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}
.pw-page-btns {
    display: flex;
    align-items: center;
    gap: 6px;
}
.pw-page-btn {
    min-width: 32px;
    height: 32px;
    padding: 0 6px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--bg-surface);
    color: var(--text-main);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.pw-page-btn:hover:not(.active):not(:disabled) {
    background: var(--bg-subtle);
    border-color: var(--primary);
    color: var(--primary);
}
.pw-page-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: #ffffff;
    font-weight: 700;
}
.pw-page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Dropdown Action Menu */
.pw-dropdown-menu {
    position: absolute;
    right: 0;
    top: 38px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-lg);
    min-width: 200px;
    z-index: 50;
    padding: 6px 0;
}
.pw-dropdown-menu a {
    display: flex;
    align-items: center;
    padding: 8px 14px;
    font-size: 13px;
    color: var(--text-main);
    text-decoration: none;
    transition: background 0.1s ease;
}
.pw-dropdown-menu a:hover {
    background: var(--bg-subtle);
    color: var(--primary);
}

/* Bulk Floating Bar */
.pw-bulk-bar {
    position: sticky;
    top: 10px;
    z-index: 40;
    background: var(--text-main);
    color: var(--bg-surface);
    border-radius: var(--radius-md);
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: var(--shadow-lg);
    margin-bottom: 16px;
}

@media (max-width: 900px) {
    .pw-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 600px) {
    .pw-kpi-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<!-- Page Header (Unified Products & Stock Hub) -->
<div class="pw-header">
    <div>
        <h1 class="pw-title"><?= htmlspecialchars(__('nav_products_stock', 'Products & Stock')) ?></h1>
        <p class="pw-subtitle">Manage catalog items, live stock levels, supplier receiving, and bay parts requests.</p>
    </div>
    <div class="pw-header-actions">
        <button type="button" class="btn btn-secondary" onclick="openReceiveStockModal()">
            <i class="fa-solid fa-truck-ramp-box mr-1"></i> Receive Delivery
        </button>
        <?php if (Auth::can('oil_care.add')): ?>
            <button type="button" class="btn btn-primary" onclick="openNewProductModal()">
                <i class="fa-solid fa-plus mr-1"></i> Add Product
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs Navigation Bar (Matching Services & Prices Tab Style) -->
<div class="pw-tabs-nav">
    <button type="button" data-tab-target="products" class="btn btn-sm <?= ($activeTab === 'products') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('products')">
        <i class="fa-solid fa-boxes-stacked mr-1"></i> Products &amp; Stock (<?= $kpiCounts['all'] ?>)
    </button>
    <button type="button" data-tab-target="requests" class="btn btn-sm <?= ($activeTab === 'requests') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('requests')">
        <i class="fa-solid fa-dolly mr-1" data-color="#ea580c" style="<?= $activeTab !== 'requests' ? 'color: #ea580c;' : '' ?>"></i> Technician Bay Requests (<?= $pendingReqCount ?>)
    </button>
    <button type="button" data-tab-target="ledger" class="btn btn-sm <?= ($activeTab === 'ledger') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('ledger')">
        <i class="fa-solid fa-clipboard-list mr-1" data-color="#0284c7" style="<?= $activeTab !== 'ledger' ? 'color: #0284c7;' : '' ?>"></i> Stock Movement History (<?= $totalMovementsCount ?>)
    </button>
    <button type="button" data-tab-target="supplies" class="btn btn-sm <?= ($activeTab === 'supplies') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('supplies')">
        <i class="fa-solid fa-flask mr-1" data-color="#0284c7" style="<?= $activeTab !== 'supplies' ? 'color: #0284c7;' : '' ?>"></i> Chemicals &amp; Washing Supplies (<?= count($supplies) ?>)
    </button>
    <button type="button" data-tab-target="discrepancies" class="btn btn-sm <?= ($activeTab === 'discrepancies') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('discrepancies')">
        <i class="fa-solid fa-scale-unbalanced mr-1" data-color="#8b5cf6" style="<?= $activeTab !== 'discrepancies' ? 'color: #8b5cf6;' : '' ?>"></i> Physical Count Audits (<?= count($discrepancies) ?>)
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
<!-- TAB: STOCK MOVEMENT HISTORY (IMMUTABLE LEDGER) -->
<!-- ========================================== -->
<div id="tab_ledger" class="tab-pane" style="display: <?= ($activeTab === 'ledger') ? 'block' : 'none' ?>;">
    <div class="card" style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 12px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
                    <i class="fa-solid fa-clipboard-list mr-1" style="color: var(--primary);"></i> Immutable Stock Movement Ledger
                </h3>
                <div class="text-xs text-muted">Chronological audit trail of every stock inflow, outflow, bay reservation, and correction.</div>
                <?php if ($filterProdId > 0): ?>
                    <div style="margin-top: 6px; display: inline-flex; align-items: center; gap: 8px; background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600;">
                        <span>Filtered by: <strong><?= htmlspecialchars($filteredProductName ?: 'Product #' . $filterProdId) ?></strong></span>
                        <a href="<?= APP_URL ?>/index.php?view=products&tab=ledger" style="color: #0284c7; text-decoration: underline; margin-left: 4px;">Clear Filter</a>
                    </div>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-sm btn-secondary" onclick="openReceiveStockModal()">
                    <i class="fa-solid fa-truck-ramp-box mr-1"></i> Receive Delivery
                </button>
                <button type="button" class="btn btn-sm btn-secondary" style="border-color: #f59e0b; color: #b45309;" onclick="openCorrectionModal()">
                    <i class="fa-solid fa-pen-to-square mr-1"></i> Add Correcting Line
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table" style="font-size: 13px;">
                <thead>
                    <tr>
                        <th>Movement UID</th>
                        <th>Date &amp; Time</th>
                        <th>Product</th>
                        <th>Movement Type</th>
                        <th style="text-align: right;">Quantity</th>
                        <th style="text-align: right;">Previous</th>
                        <th style="text-align: right;">New Balance</th>
                        <th>Reference / PO</th>
                        <th>Notes / Reason</th>
                        <th>Staff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockMovements)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                No stock movements recorded yet. Movements appear here automatically when deliveries arrive, orders are placed, or parts are dispatched.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockMovements as $m): 
                            $mType = $m['movement_type'] ?? 'adjusted';
                            $badgeStyle = match($mType) {
                                'received' => 'background: #dcfce7; color: #15803d;',
                                'used'     => 'background: #e0f2fe; color: #0369a1;',
                                'reserved' => 'background: #ffedd5; color: #c2410c;',
                                'released' => 'background: #f3e8ff; color: #7e22ce;',
                                'adjusted' => 'background: #fef3c7; color: #b45309;',
                                'returned' => 'background: #ecfeff; color: #0e7490;',
                                default    => 'background: var(--bg-subtle); color: var(--text-main);'
                            };
                            $qtySign = in_array($mType, ['received', 'released', 'returned'], true) ? '+' : (in_array($mType, ['used', 'reserved'], true) ? '-' : '±');
                        ?>
                        <tr>
                            <td style="font-family: var(--font-mono); font-weight: 700; font-size: 12px;"><?= htmlspecialchars($m['movement_uid']) ?></td>
                            <td class="text-xs text-muted"><?= htmlspecialchars($m['created_at']) ?></td>
                            <td><strong><?= htmlspecialchars($m['product_name'] ?: ('Product #' . $m['product_id'])) ?></strong></td>
                            <td>
                                <span class="badge" style="<?= $badgeStyle ?> font-weight: 700; text-transform: uppercase; font-size: 11px;">
                                    <?= htmlspecialchars($mType) ?>
                                </span>
                            </td>
                            <td style="text-align: right; font-weight: 700;">
                                <span style="color: <?= ($qtySign === '+' ? '#16a34a' : ($qtySign === '-' ? '#dc2626' : '#d97706')) ?>;">
                                    <?= $qtySign ?><?= (int)$m['quantity'] ?>
                                </span>
                            </td>
                            <td style="text-align: right;" class="text-muted"><?= (int)$m['previous_qty'] ?></td>
                            <td style="text-align: right; font-weight: 800; font-size: 14px; color: var(--text-main);"><?= (int)$m['new_qty'] ?></td>
                            <td style="font-family: var(--font-mono); font-size: 12px; color: #0284c7;"><?= htmlspecialchars((string)($m['reference_id'] ?? '-')) ?></td>
                            <td style="font-size: 13px; color: var(--text-muted); max-width: 240px;"><?= htmlspecialchars((string)($m['notes'] ?? '-')) ?></td>
                            <td style="font-size: 12px;">
                                <span class="badge badge-secondary"><?= htmlspecialchars((string)($m['performer_name'] ?? 'System')) ?></span>
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
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="?view=oil_care&tab=reminders&filter=all" class="btn btn-sm <?= ($reminderFilter === 'all') ? 'btn-primary' : 'btn-secondary' ?>">
                All Vehicles (<?= count($allReminders) ?>)
            </a>
            <a href="?view=oil_care&tab=reminders&filter=due_soon" class="btn btn-sm <?= ($reminderFilter === 'due_soon') ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fa-solid fa-triangle-exclamation mr-1" style="<?= ($reminderFilter === 'due_soon') ? '' : 'color: var(--warning);' ?>"></i> Due Soon (<?= $dueSoonCount ?>)
            </a>
            <a href="?view=oil_care&tab=reminders&filter=overdue" class="btn btn-sm <?= ($reminderFilter === 'overdue') ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fa-solid fa-circle-exclamation mr-1" style="<?= ($reminderFilter === 'overdue') ? '' : 'color: var(--danger);' ?>"></i> Overdue (<?= $overdueCount ?>)
            </a>
            <a href="?view=oil_care&tab=reminders&filter=good" class="btn btn-sm <?= ($reminderFilter === 'good') ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fa-solid fa-circle-check mr-1" style="<?= ($reminderFilter === 'good') ? '' : 'color: var(--success);' ?>"></i> Good (<?= $goodCount ?>)
            </a>
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
                                <?php 
                                    $intervalKm = (int)$r['next_due_mileage'] - (int)$r['current_mileage'];
                                ?>
                                <?php if ($intervalKm > 0): ?>
                                    <div class="text-xs text-muted">+<?= number_format($intervalKm) ?> km interval</div>
                                <?php else: ?>
                                    <div class="text-xs text-muted">Scheduled Target</div>
                                <?php endif; ?>
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
<!-- TAB 2: PRODUCTS & STOCK CATALOG (PICTURE MATCH) -->
<!-- ========================================== -->
<div id="tab_products" class="tab-pane" style="display: <?= ($activeTab === 'products') ? 'block' : 'none' ?>;">
    
    <!-- 1. KPI Summary Cards Grid -->
    <div class="pw-kpi-grid">
        <div class="pw-kpi-card active" data-kpi="all" onclick="filterByKpi('all')">
            <div class="pw-kpi-title">All products</div>
            <div class="pw-kpi-num"><?= $kpiCounts['all'] ?></div>
            <div class="pw-kpi-sub">Everything in the catalog</div>
        </div>
        <div class="pw-kpi-card" data-kpi="in_stock" onclick="filterByKpi('in_stock')">
            <div class="pw-kpi-title"><span class="kpi-dot in-stock"></span> In stock</div>
            <div class="pw-kpi-num" style="color: #16a34a;"><?= $kpiCounts['in_stock'] ?></div>
            <div class="pw-kpi-sub">More than 10 units</div>
        </div>
        <div class="pw-kpi-card" data-kpi="low_stock" onclick="filterByKpi('low_stock')">
            <div class="pw-kpi-title"><span class="kpi-dot low-stock"></span> Low stock</div>
            <div class="pw-kpi-num" style="color: #d97706;"><?= $kpiCounts['low_stock'] ?></div>
            <div class="pw-kpi-sub">10 units or fewer</div>
        </div>
        <div class="pw-kpi-card" data-kpi="out_of_stock" onclick="filterByKpi('out_of_stock')">
            <div class="pw-kpi-title"><span class="kpi-dot out-of-stock"></span> Out of stock</div>
            <div class="pw-kpi-num" style="color: #dc2626;"><?= $kpiCounts['out_of_stock'] ?></div>
            <div class="pw-kpi-sub">Needs restocking</div>
        </div>
    </div>

    <!-- 2. Search & Filter Controls Toolbar -->
    <div class="pw-toolbar">
        <div class="pw-search-wrap">
            <i class="fa-solid fa-magnifying-glass pw-search-icon"></i>
            <input type="text" id="pwSearchInput" class="pw-search-input" placeholder="Search by name, brand or barcode" oninput="handleSearch(this.value)">
        </div>
        <div class="pw-toolbar-right">
            <label class="pw-checkbox-label">
                <input type="checkbox" id="pwHideOutOfStock" onchange="toggleHideOutOfStock(this.checked)">
                <span>Hide out of stock</span>
            </label>
            <div class="pw-sort-wrap">
                <span>Sort</span>
                <select id="pwSortSelect" class="pw-sort-select" onchange="handleSort(this.value)">
                    <option value="name_asc" selected>Name A to Z</option>
                    <option value="name_desc">Name Z to A</option>
                    <option value="price_asc">Price: Low to High</option>
                    <option value="price_desc">Price: High to Low</option>
                    <option value="stock_desc">Stock: High to Low</option>
                    <option value="stock_asc">Stock: Low to High</option>
                </select>
            </div>
        </div>
    </div>

    <!-- 3. Category Filter Pills (Matching Services & Prices Tab Style) -->
    <div class="pw-cat-pills">
        <span class="pw-cat-label">Category:</span>
        <button type="button" class="btn btn-sm btn-primary" data-cat="all" onclick="filterByCategory('all')">
            All (<?= $catCounts['all'] ?>)
        </button>
        <?php foreach ($productCategories as $pc): 
            $cnt = max($catCounts['id_' . $pc['id']] ?? 0, $catCounts['code_' . $pc['code']] ?? 0);
            $pcIcon = !empty($pc['icon']) ? $pc['icon'] : 'fa-tag';
            $pcColor = !empty($pc['color']) ? $pc['color'] : '#0284c7';
            $pcName = (Language::current() === 'km') ? $pc['name_km'] : $pc['name_en'];
        ?>
            <button type="button" class="btn btn-sm btn-secondary" data-cat="<?= htmlspecialchars($pc['code']) ?>" onclick="filterByCategory('<?= htmlspecialchars($pc['code']) ?>')">
                <i class="fa-solid <?= htmlspecialchars($pcIcon) ?> mr-1" data-color="<?= htmlspecialchars($pcColor) ?>" style="color: <?= htmlspecialchars($pcColor) ?>;"></i>
                <?= htmlspecialchars($pcName) ?> (<?= $cnt ?>)
            </button>
        <?php endforeach; ?>
    </div>

    <!-- 4. Bulk Action Floating Bar -->
    <div id="pwBulkBar" class="pw-bulk-bar" style="display: none;">
        <div><strong id="pwBulkCount">0</strong> products selected</div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn btn-sm btn-primary" onclick="openBulkRestock()">
                <i class="fa-solid fa-boxes-packing mr-1"></i> Restock Selected
            </button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="clearRowSelection()">
                Clear Selection
            </button>
        </div>
    </div>

    <!-- 5. Products Table Card -->
    <div class="pw-table-card">
        <div class="table-responsive">
            <table class="pw-table" id="pwProductsTable">
                <thead>
                    <tr>
                        <th style="width: 44px; text-align: center;">
                            <input type="checkbox" id="pwSelectAll" class="pw-row-checkbox" onchange="toggleSelectAll(this.checked)">
                        </th>
                        <th>PRODUCT</th>
                        <th>CATEGORY</th>
                        <th>PRICE</th>
                        <th>STOCK AVAILABLE</th>
                        <th>STATUS</th>
                        <th style="text-align: right; width: 170px;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody id="pwTableBody">
                    <?php foreach ($products as $p): 
                        $physical = (int)$p['stock_qty'];
                        $reserved = (int)($p['reserved_qty'] ?? 0);
                        $avail = max(0, $physical - $reserved);
                        $minAlert = (int)($p['min_stock_alert'] ?: 5);
                        $isLow = ($avail > 0 && ($avail <= $minAlert || $avail <= 10));
                        $isOut = ($avail <= 0);
                        
                        $cObj = null;
                        if (!empty($p['category_id']) && isset($prodCatMap['id_' . $p['category_id']])) {
                            $cObj = $prodCatMap['id_' . $p['category_id']];
                        } elseif (!empty($p['category']) && isset($prodCatMap['code_' . $p['category']])) {
                            $cObj = $prodCatMap['code_' . $p['category']];
                        }
                        $catCode = $p['category'] ?? ($cObj['code'] ?? 'spare_part');
                        $catIcon = $cObj['icon'] ?? 'fa-cube';
                        $catName = $cObj ? $cObj['name_en'] : ucfirst(str_replace('_', ' ', $catCode));

                        // Category pastel theme colors matching image
                        $catBg = '#f1f5f9';
                        $catTextColor = '#475569';
                        if ($catCode === 'engine_oil') {
                            $catBg = '#ffedd5';
                            $catTextColor = '#c2410c';
                            $catIcon = 'fa-oil-can';
                        } elseif ($catCode === 'oil_filter') {
                            $catBg = '#ccfbf1';
                            $catTextColor = '#0f766e';
                            $catIcon = 'fa-filter';
                        } elseif ($catCode === 'fluid') {
                            $catBg = '#dbeafe';
                            $catTextColor = '#1d4ed8';
                            $catIcon = 'fa-bottle-droplet';
                        } elseif ($catCode === 'care_product') {
                            $catBg = '#f3e8ff';
                            $catTextColor = '#7e22ce';
                            $catIcon = 'fa-cube';
                        } elseif ($catCode === 'spare_part') {
                            $catBg = '#f1f5f9';
                            $catTextColor = '#475569';
                            $catIcon = 'fa-gears';
                        }

                        // Stock Bar Calculation
                        $barPercent = 0;
                        if ($avail > 0) {
                            $barPercent = min(100, max(12, round(($avail / 30) * 100)));
                        }

                        $hasImg = !empty($p['thumb_path']) && file_exists(dirname(__DIR__, 2) . '/' . $p['thumb_path']);
                    ?>
                    <tr class="pw-row" 
                        data-id="<?= $p['id'] ?>"
                        data-name="<?= htmlspecialchars(strtolower($p['name_en'] . ' ' . ($p['name_km'] ?? '') . ' ' . ($p['brand'] ?? ''))) ?>"
                        data-cat="<?= htmlspecialchars($catCode) ?>"
                        data-stock="<?= $avail ?>"
                        data-stock-status="<?= $isOut ? 'out_of_stock' : ($isLow ? 'low_stock' : 'in_stock') ?>"
                        data-price="<?= (float)$p['price_usd'] ?>"
                        data-name-sort="<?= htmlspecialchars(strtolower($p['name_en'])) ?>">
                        <td style="text-align: center;">
                            <input type="checkbox" class="pw-row-checkbox" value="<?= $p['id'] ?>" onchange="handleRowCheckbox(this)">
                        </td>
                        <td>
                            <div class="product-flex">
                                <div class="product-icon-sq" style="background: <?= $catBg ?>; color: <?= $catTextColor ?>;">
                                    <?php if ($hasImg): ?>
                                        <img src="<?= APP_URL ?>/<?= htmlspecialchars($p['thumb_path']) ?>?v=<?= strtotime($p['image_updated_at'] ?? 'now') ?>" alt="" style="width: 100%; height: 100%; object-fit: contain; border-radius: 8px;">
                                    <?php else: ?>
                                        <i class="fa-solid <?= htmlspecialchars($catIcon) ?>"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div class="product-name-en"><?= htmlspecialchars($p['name_en']) ?></div>
                                    <?php if (!empty($p['name_km'])): ?>
                                        <div class="product-name-km"><?= htmlspecialchars($p['name_km']) ?></div>
                                    <?php endif; ?>
                                    <div class="product-meta">
                                        <?= htmlspecialchars($p['brand'] ?: 'Generic') ?> · <?= htmlspecialchars($p['unit'] ?: 'unit') ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="cat-pill" style="background: <?= $catBg ?>; color: <?= $catTextColor ?>;">
                                <?= htmlspecialchars($catName) ?>
                            </span>
                        </td>
                        <td>
                            <div class="price-main">$<?= number_format((float)$p['price_usd'], 2) ?></div>
                            <div class="price-sub">៛<?= number_format((int)$p['price_khr']) ?></div>
                        </td>
                        <td>
                            <div style="display: flex; align-items: baseline;">
                                <span class="stock-num <?= $isOut ? 'out-of-stock' : ($isLow ? 'low-stock' : 'in-stock') ?>"><?= $avail ?></span>
                                <span class="stock-sub"><?= $physical ?> on hand · <?= $reserved ?> reserved</span>
                            </div>
                            <div class="stock-track">
                                <div class="stock-bar <?= $isOut ? 'out-of-stock' : ($isLow ? 'low-stock' : 'in-stock') ?>" style="width: <?= $barPercent ?>%;"></div>
                            </div>
                        </td>
                        <td>
                            <?php if ($isOut): ?>
                                <span class="status-pill out-of-stock"><span class="dot"></span> Out of stock</span>
                            <?php elseif ($isLow): ?>
                                <span class="status-pill low-stock"><span class="dot"></span> Low stock</span>
                            <?php else: ?>
                                <span class="status-pill in-stock"><span class="dot"></span> In stock</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                <button type="button" class="btn-stock-plus" onclick="openQuickRestock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name_en'], ENT_QUOTES) ?>', <?= $physical ?>)">
                                    + Stock
                                </button>
                                <button type="button" class="btn-icon-sq" data-product="<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="openEditProductModal(this)" title="Edit Product">
                                    <i class="fa-solid fa-pencil"></i>
                                </button>
                                <div style="position: relative; display: inline-block;">
                                    <button type="button" class="btn-icon-sq" onclick="toggleRowMenu(event, this)" title="More options">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <div class="pw-dropdown-menu" style="display: none;">
                                        <a href="javascript:void(0)" onclick="openQuickRestock(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name_en'], ENT_QUOTES) ?>', <?= $physical ?>)">
                                            <i class="fa-solid fa-boxes-packing mr-2" style="color: #ea580c;"></i> Quick Restock
                                        </a>
                                        <a href="javascript:void(0)" onclick="openDiscrepancyModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name_en'], ENT_QUOTES) ?>', <?= $physical ?>)">
                                            <i class="fa-solid fa-scale-unbalanced mr-2" style="color: #8b5cf6;"></i> Log Discrepancy
                                        </a>
                                        <a href="<?= APP_URL ?>/index.php?view=products&tab=ledger&product_id=<?= $p['id'] ?>">
                                            <i class="fa-solid fa-clipboard-list mr-2" style="color: #0284c7;"></i> Stock Ledger History
                                        </a>
                                        <div style="height: 1px; background: #e5e7eb; margin: 4px 0;"></div>
                                        <form action="" method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars($p['name_en'], ENT_QUOTES) ?> from catalog?');" style="margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                            <button type="submit" style="width: 100%; text-align: left; background: none; border: none; padding: 8px 14px; font-size: 13px; color: #dc2626; cursor: pointer; display: flex; align-items: center;">
                                                <i class="fa-solid fa-trash-can mr-2"></i> Delete Product
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 6. Table Pagination Footer -->
        <div class="pw-footer">
            <div id="pwPaginationInfo">Showing 1 to 8 of <?= count($products) ?> products</div>
            <div style="display: flex; align-items: center; gap: 16px;">
                <div class="pw-rows-wrap">
                    <span>Rows</span>
                    <select id="pwRowsSelect" style="border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px 8px; font-size: 13px; background: #fff;" onchange="changeRowsPerPage(this.value)">
                        <option value="8" selected>8</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="pw-page-btns" id="pwPaginationBtns"></div>
            </div>
        </div>
    </div>
</div>

<!-- TAB: CHEMICALS & WASHING SUPPLIES INVENTORY -->
<!-- ========================================== -->
<div id="tab_supplies" class="tab-pane" style="display: <?= ($activeTab === 'supplies') ? 'block' : 'none' ?>;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="font-size: 17px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-flask mr-1" style="color: #0284c7;"></i> Chemical &amp; Washing Supplies Inventory
            </h2>
            <p class="text-xs text-muted">Shop consumables, shampoos, hydrophobic waxes, and microfiber accessories used in washing &amp; detailing bays.</p>
        </div>
        <div style="display: flex; gap: 8px;">
            <span class="badge badge-slate" style="padding: 6px 12px;">Total Items: <strong><?= count($supplies) ?></strong></span>
            <?php if ($lowSuppliesCount > 0): ?>
                <span class="badge badge-amber" style="padding: 6px 12px;"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Low Stock Alert: <strong><?= $lowSuppliesCount ?></strong></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Supply Name</th>
                        <th>Category</th>
                        <th>Current Quantity</th>
                        <th>Min Alert Level</th>
                        <th>Unit Cost</th>
                        <th>Stock Status</th>
                        <th style="text-align: right;">Quick Restock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($supplies)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-muted);">
                            <i class="fa-solid fa-flask" style="font-size: 36px; margin-bottom: 12px; opacity: 0.3;"></i>
                            <div>No washing chemical supplies found in inventory.</div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($supplies as $it): 
                            $isLow = (float)$it['quantity'] <= (float)$it['min_alert_qty'];
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <?php 
                                    $hasImg = !empty($it['thumb_path']) && file_exists(dirname(__DIR__, 2) . '/' . $it['thumb_path']);
                                    if ($hasImg): ?>
                                        <img src="<?= APP_URL ?>/<?= htmlspecialchars($it['thumb_path']) ?>?v=<?= strtotime($it['image_updated_at'] ?? 'now') ?>" alt="Supply" style="width: 42px; height: 42px; object-fit: contain; border-radius: var(--radius-md); background: #ffffff; border: 1px solid var(--border); padding: 2px; flex-shrink: 0; box-shadow: var(--shadow-xs);">
                                    <?php else: ?>
                                        <div style="width: 42px; height: 42px; border-radius: var(--radius-md); background: var(--bg-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; border: 1px solid var(--border);">
                                            <i class="fa-solid <?= ($it['category'] === 'soap' ? 'fa-soap' : ($it['category'] === 'wax' ? 'fa-shield-halved' : ($it['category'] === 'accessory' ? 'fa-rug' : 'fa-flask'))) ?>"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight: 700; color: var(--text-main); font-size: 14.5px;"><?= htmlspecialchars($it['item_name_en']) ?></div>
                                        <div class="text-xs text-muted"><?= htmlspecialchars($it['item_name_km']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-slate" style="text-transform: uppercase; font-weight: 700;"><?= htmlspecialchars($it['category']) ?></span>
                            </td>
                            <td>
                                <strong style="font-size: 16px; color: <?= $isLow ? 'var(--danger)' : 'var(--text-main)' ?>;">
                                    <?= (float)$it['quantity'] ?> <?= htmlspecialchars($it['unit']) ?>
                                </strong>
                            </td>
                            <td><?= (float)$it['min_alert_qty'] ?> <?= htmlspecialchars($it['unit']) ?></td>
                            <td><?= formatUSD((float)$it['unit_cost_usd']) ?></td>
                            <td>
                                <?php if ($isLow): ?>
                                    <span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Sufficient</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <form action="" method="POST" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="adjust_supply_stock">
                                    <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                    <input type="number" step="0.5" name="quantity_change" class="form-input" style="width: 70px; min-height: 32px; padding: 4px 8px; text-align: center;" value="5">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Restock item">
                                        <i class="fa-solid fa-plus mr-1"></i> Restock
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Modal: Quick Restock -->
<div class="modal-backdrop" id="quickRestockModal">
    <div class="modal" style="max-width: 420px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #ea580c;">
                <i class="fa-solid fa-boxes-packing mr-1"></i> Quick Restock
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('quickRestockModal')">&times;</button>
        </div>
        <form action="" method="POST" style="margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust_stock">
            <input type="hidden" name="product_id" id="qr_product_id" value="">
            <div class="modal-body">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Product</label>
                    <div id="qr_product_name" style="font-size: 15px; font-weight: 700; color: #111827;"></div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">Current stock: <strong id="qr_current_stock">0</strong></div>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Quantity to Add (+)</label>
                    <input type="number" name="qty_change" id="qr_qty_change" class="form-control" value="10" min="1" max="1000" required style="font-size: 18px; font-weight: 800; text-align: center; color: #ea580c;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('quickRestockModal')">Cancel</button>
                <button type="submit" class="btn" style="background: #ea580c; color: #fff; font-weight: 600;">Confirm Restock</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Receive Supplier Delivery -->
<div class="modal-backdrop" id="receiveStockModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #16a34a;"><i class="fa-solid fa-truck-ramp-box mr-1"></i> Receive Supplier Delivery</h3>
            <button type="button" class="modal-close" onclick="closeModal('receiveStockModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="receive_stock">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Product *</label>
                    <select name="product_id" id="rec_product_id" class="form-select" required>
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($allActiveProducts as $p): ?>
                            <option value="<?= $p['id'] ?>" data-stock="<?= (int)$p['stock_qty'] ?>">
                                <?= htmlspecialchars($p['name_en']) ?> (Current on-hand: <?= (int)$p['stock_qty'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Quantity Received (+)</label>
                    <input type="number" step="1" min="1" name="qty_received" id="rec_qty_received" class="form-input" value="12" required style="font-size: 16px; font-weight: 700;">
                </div>

                <div class="form-group">
                    <label class="form-label">Supplier Invoice / PO Reference</label>
                    <input type="text" name="supplier_ref" class="form-input" placeholder="e.g. INV-98214">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Arrival Notes</label>
                    <input type="text" name="notes" class="form-input" placeholder="e.g. Received delivery from TotalEnergies distributor">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('receiveStockModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #16a34a; border-color: #16a34a;">Confirm Delivery Received</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Correcting Line (Fix mistake without editing old lines) -->
<div class="modal-backdrop" id="correctionModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #b45309;"><i class="fa-solid fa-pen-to-square mr-1"></i> Add Stock Correcting Line</h3>
            <button type="button" class="modal-close" onclick="closeModal('correctionModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_correction_line">

            <div class="modal-body">
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 12px; margin-bottom: 14px; font-size: 13px; color: #92400e;">
                    <i class="fa-solid fa-info-circle mr-1"></i>
                    Past history lines cannot be edited or deleted. This action corrects discrepancies by recording a new <strong>adjusted</strong> line with the actual counted balance and reason.
                </div>

                <div class="form-group">
                    <label class="form-label">Select Product *</label>
                    <select name="product_id" id="corr_product_id" class="form-select" required onchange="updateCorrPrevStock()">
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($allActiveProducts as $p): ?>
                            <option value="<?= $p['id'] ?>" data-stock="<?= (int)$p['stock_qty'] ?>">
                                <?= htmlspecialchars($p['name_en']) ?> (Current on-hand: <?= (int)$p['stock_qty'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2" style="gap: 12px; margin-bottom: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label text-xs">Current System Stock</label>
                        <input type="text" id="corr_prev_stock" class="form-input" value="0" disabled style="background: var(--bg-subtle);">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label text-xs">Actual Physical Count *</label>
                        <input type="number" step="1" min="0" name="new_balance" id="corr_new_balance" class="form-input" placeholder="e.g. 15" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason / Explanation *</label>
                    <input type="text" name="reason" class="form-input" placeholder="e.g. Physical inventory count variance, damaged bottle" required>
                </div>

                <!-- Manager PIN Authorization -->
                <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: var(--radius-md); padding: 14px; margin-bottom: 0;">
                    <div style="font-weight: 700; color: #ea580c; font-size: 13px; margin-bottom: 8px;">
                        <i class="fa-solid fa-user-lock mr-1"></i> Manager PIN Authorization
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label class="form-label text-xs">Manager Username *</label>
                        <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars(in_array($currentRole, ['owner', 'manager'], true) ? (Auth::getUsername() ?? '') : '') ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label text-xs">Manager 6-digit PIN *</label>
                        <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('correctionModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #b45309; border-color: #b45309;">Record Correcting Line</button>
            </div>
        </form>
    </div>
</div>

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
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label class="form-label" style="margin-bottom: 0;">Category *</label>
                            <a href="?view=categories&tab=product" target="_blank" style="font-size: 11px; font-weight: 600; color: var(--primary); text-decoration: none;">
                                <i class="fa-solid fa-plus-circle mr-1"></i> New
                            </a>
                        </div>
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
    const targetPane = document.getElementById('tab_' + tabId);
    if (targetPane) targetPane.style.display = 'block';

    document.querySelectorAll('[data-tab-target]').forEach(btn => {
        const isTarget = (btn.getAttribute('data-tab-target') === tabId);
        btn.classList.toggle('active', isTarget);
        if (btn.classList.contains('btn')) {
            btn.classList.toggle('btn-primary', isTarget);
            btn.classList.toggle('btn-secondary', !isTarget);
        }
        const icon = btn.querySelector('i');
        if (icon) {
            const defaultColor = icon.getAttribute('data-color');
            if (defaultColor) {
                icon.style.color = isTarget ? '#ffffff' : defaultColor;
            }
        }
    });

    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);
}

// Client-Side Products Table Controller (Pixel-matched interactivity)
let pwState = {
    search: '',
    category: 'all',
    kpi: 'all',
    hideOutOfStock: false,
    sort: 'name_asc',
    rowsPerPage: 8,
    currentPage: 1,
    selectedIds: new Set()
};

function filterByKpi(kpiType) {
    pwState.kpi = kpiType;
    pwState.currentPage = 1;
    document.querySelectorAll('.pw-kpi-card').forEach(c => {
        c.classList.toggle('active', c.getAttribute('data-kpi') === kpiType);
    });
    applyPwFilters();
}

function filterByCategory(catCode) {
    pwState.category = catCode;
    pwState.currentPage = 1;
    document.querySelectorAll('.pw-cat-pills button[data-cat]').forEach(p => {
        const isTarget = (p.getAttribute('data-cat') === catCode);
        p.classList.toggle('active', isTarget);
        if (p.classList.contains('btn')) {
            p.classList.toggle('btn-primary', isTarget);
            p.classList.toggle('btn-secondary', !isTarget);
        }
        const icon = p.querySelector('i');
        if (icon) {
            const defaultColor = icon.getAttribute('data-color');
            if (defaultColor) {
                icon.style.color = isTarget ? '#ffffff' : defaultColor;
            }
        }
    });
    applyPwFilters();
}

function handleSearch(val) {
    pwState.search = (val || '').trim().toLowerCase();
    pwState.currentPage = 1;
    applyPwFilters();
}

function toggleHideOutOfStock(checked) {
    pwState.hideOutOfStock = !!checked;
    pwState.currentPage = 1;
    applyPwFilters();
}

function handleSort(sortVal) {
    pwState.sort = sortVal;
    applyPwFilters();
}

function changeRowsPerPage(val) {
    pwState.rowsPerPage = (val === 'all') ? 9999 : parseInt(val);
    pwState.currentPage = 1;
    applyPwFilters();
}

function applyPwFilters() {
    const rows = Array.from(document.querySelectorAll('.pw-row'));
    let matched = rows.filter(row => {
        const name = row.getAttribute('data-name') || '';
        const cat = row.getAttribute('data-cat') || '';
        const stockStatus = row.getAttribute('data-stock-status') || '';
        const stock = parseFloat(row.getAttribute('data-stock') || '0');

        if (pwState.search && !name.includes(pwState.search)) return false;
        if (pwState.category !== 'all' && cat !== pwState.category) return false;
        if (pwState.kpi !== 'all' && stockStatus !== pwState.kpi) return false;
        if (pwState.hideOutOfStock && stock <= 0) return false;

        return true;
    });

    // Sort matched rows
    matched.sort((a, b) => {
        if (pwState.sort === 'name_asc') {
            return (a.getAttribute('data-name-sort') || '').localeCompare(b.getAttribute('data-name-sort') || '');
        } else if (pwState.sort === 'name_desc') {
            return (b.getAttribute('data-name-sort') || '').localeCompare(a.getAttribute('data-name-sort') || '');
        } else if (pwState.sort === 'price_asc') {
            return parseFloat(a.getAttribute('data-price') || 0) - parseFloat(b.getAttribute('data-price') || 0);
        } else if (pwState.sort === 'price_desc') {
            return parseFloat(b.getAttribute('data-price') || 0) - parseFloat(a.getAttribute('data-price') || 0);
        } else if (pwState.sort === 'stock_desc') {
            return parseFloat(b.getAttribute('data-stock') || 0) - parseFloat(a.getAttribute('data-stock') || 0);
        } else if (pwState.sort === 'stock_asc') {
            return parseFloat(a.getAttribute('data-stock') || 0) - parseFloat(b.getAttribute('data-stock') || 0);
        }
        return 0;
    });

    const tbody = document.getElementById('pwTableBody');
    if (tbody) {
        matched.forEach(row => tbody.appendChild(row));
    }

    rows.forEach(row => {
        if (!matched.includes(row)) {
            row.style.display = 'none';
        }
    });

    const total = matched.length;
    const perPage = pwState.rowsPerPage;
    const totalPages = Math.ceil(total / perPage) || 1;
    if (pwState.currentPage > totalPages) pwState.currentPage = totalPages;

    const startIdx = (pwState.currentPage - 1) * perPage;
    const endIdx = startIdx + perPage;

    matched.forEach((row, i) => {
        row.style.display = (i >= startIdx && i < endIdx) ? '' : 'none';
    });

    const infoEl = document.getElementById('pwPaginationInfo');
    if (infoEl) {
        const displayStart = total === 0 ? 0 : startIdx + 1;
        const displayEnd = Math.min(endIdx, total);
        infoEl.innerText = `Showing ${displayStart} to ${displayEnd} of ${total} products`;
    }

    renderPaginationNav(totalPages);
}

function renderPaginationNav(totalPages) {
    const container = document.getElementById('pwPaginationBtns');
    if (!container) return;
    container.innerHTML = '';
    if (totalPages <= 1) return;

    const prevBtn = document.createElement('button');
    prevBtn.className = 'pw-page-btn';
    prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left" style="font-size: 11px;"></i>';
    prevBtn.disabled = (pwState.currentPage <= 1);
    prevBtn.onclick = () => { if (pwState.currentPage > 1) { pwState.currentPage--; applyPwFilters(); } };
    container.appendChild(prevBtn);

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = 'pw-page-btn' + (i === pwState.currentPage ? ' active' : '');
        btn.innerText = i;
        btn.onclick = () => { pwState.currentPage = i; applyPwFilters(); };
        container.appendChild(btn);
    }

    const nextBtn = document.createElement('button');
    nextBtn.className = 'pw-page-btn';
    nextBtn.innerHTML = '<i class="fa-solid fa-chevron-right" style="font-size: 11px;"></i>';
    nextBtn.disabled = (pwState.currentPage >= totalPages);
    nextBtn.onclick = () => { if (pwState.currentPage < totalPages) { pwState.currentPage++; applyPwFilters(); } };
    container.appendChild(nextBtn);
}

function toggleSelectAll(checked) {
    document.querySelectorAll('.pw-row').forEach(row => {
        if (row.style.display !== 'none') {
            const cb = row.querySelector('.pw-row-checkbox');
            if (cb) {
                cb.checked = checked;
                handleRowCheckbox(cb);
            }
        }
    });
}

function handleRowCheckbox(cb) {
    const row = cb.closest('tr');
    const id = cb.value;
    if (cb.checked) {
        pwState.selectedIds.add(id);
        row.classList.add('selected-row');
    } else {
        pwState.selectedIds.delete(id);
        row.classList.remove('selected-row');
    }
    updateBulkBar();
}

function updateBulkBar() {
    const bar = document.getElementById('pwBulkBar');
    const countEl = document.getElementById('pwBulkCount');
    const count = pwState.selectedIds.size;
    if (bar) {
        bar.style.display = count > 0 ? 'flex' : 'none';
    }
    if (countEl) countEl.innerText = count;
}

function clearRowSelection() {
    pwState.selectedIds.clear();
    document.querySelectorAll('.pw-row-checkbox').forEach(cb => {
        cb.checked = false;
        cb.closest('tr').classList.remove('selected-row');
    });
    const master = document.getElementById('pwSelectAll');
    if (master) master.checked = false;
    updateBulkBar();
}

function openQuickRestock(id, name, stock) {
    document.getElementById('qr_product_id').value = id;
    document.getElementById('qr_product_name').innerText = name;
    document.getElementById('qr_current_stock').innerText = stock;
    document.getElementById('qr_qty_change').value = '10';
    openModal('quickRestockModal');
}

function openBulkRestock() {
    if (pwState.selectedIds.size === 0) return;
    const firstId = Array.from(pwState.selectedIds)[0];
    openQuickRestock(firstId, `${pwState.selectedIds.size} Selected Products`, 'Multiple');
}

function toggleRowMenu(e, btn) {
    e.stopPropagation();
    const menu = btn.nextElementSibling;
    const isShown = menu.style.display === 'block';
    document.querySelectorAll('.pw-dropdown-menu').forEach(m => m.style.display = 'none');
    if (!isShown) {
        menu.style.display = 'block';
    }
}

document.addEventListener('click', () => {
    document.querySelectorAll('.pw-dropdown-menu').forEach(m => m.style.display = 'none');
});

window.addEventListener('DOMContentLoaded', () => {
    applyPwFilters();
});

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

<?php
$cfgShopName = get_setting('shop_name_en', get_setting('shop_name', 'AquaGleam Car Wash & Detailing'));
$cfgShopPhone = get_setting('shop_phone', '012 334 556');
?>
const shopName = <?= json_encode($cfgShopName) ?>;
const shopPhone = <?= json_encode($cfgShopPhone) ?>;

let currentReminderCopyText = '';
function openReminderAlertModal(btnOrName, plate, nextMileage, dueDate) {
    let customerName = btnOrName;
    if (btnOrName && btnOrName.getAttribute && btnOrName.getAttribute('data-customer')) {
        customerName = btnOrName.getAttribute('data-customer');
        plate = btnOrName.getAttribute('data-plate');
        nextMileage = btnOrName.getAttribute('data-mileage');
        dueDate = btnOrName.getAttribute('data-due');
    }
    const phonePart = shopPhone ? ` Tel: ${shopPhone}` : '';
    const text = `${shopName} Oil Change Reminder:\nDear ${customerName}, your vehicle ${plate} is due for its next engine oil change at ${nextMileage} KM (Due by: ${dueDate}). Visit us for premium synthetic oil & genuine filters!${phonePart}`;
    currentReminderCopyText = text;
    const msgBox = document.getElementById('reminderMsgBox');
    if (msgBox) msgBox.innerText = text;
    openModal('reminderAlertModal');
}

function copyReminderText() {
    const text = currentReminderCopyText;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            alert('Reminder message copied to clipboard!');
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        alert('Reminder message copied to clipboard!');
    } catch (e) {
        alert('Please manually copy the text from the preview box.');
    }
    document.body.removeChild(ta);
}
</script>

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
function openReceiveStockModal(prodId, defaultQty) {
    if (prodId) {
        const select = document.getElementById('rec_product_id');
        if (select) select.value = prodId;
    }
    if (defaultQty) {
        const qtyInput = document.getElementById('rec_qty_received');
        if (qtyInput) qtyInput.value = defaultQty;
    }
    openModal('receiveStockModal');
}

function openCorrectionModal(prodId) {
    if (prodId) {
        const select = document.getElementById('corr_product_id');
        if (select) {
            select.value = prodId;
            updateCorrPrevStock();
        }
    }
    openModal('correctionModal');
}

function updateCorrPrevStock() {
    const select = document.getElementById('corr_product_id');
    const prevInput = document.getElementById('corr_prev_stock');
    if (!select || !prevInput) return;
    const selectedOpt = select.options[select.selectedIndex];
    const stock = selectedOpt ? selectedOpt.getAttribute('data-stock') : '0';
    prevInput.value = stock || '0';
}

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
