<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/StockControl.php';
require_once dirname(__DIR__, 2) . '/core/CashierControl.php';

// Accessible to Manager, Owner, Stock Control, and System Admin
$userRole = Auth::getRole();
if (!in_array($userRole, ['owner', 'manager', 'stock_control', 'system_admin'], true)) {
    http_response_code(403);
    die("Access Denied: You do not have permission to view the Stock Ledger.");
}

$pageTitle = __('nav_stock_ledger', 'Stock Ledger & History');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Stock Ledger Actions (Append-only)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Add Stock Correction Line (Fix mistakes by adding a correcting line with Manager PIN)
    if ($action === 'add_correction_line') {
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
    }

    // 2. Receive Supplier Stock
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
            $fullNotes = "Received from supplier: " . ($supplierRef ?: 'Restock') . ($notes ? " ({$notes})" : '');

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
    }

    // 3. Prevent any attempt to delete or edit stock history
    elseif (in_array($action, ['delete_movement', 'edit_movement'], true)) {
        try {
            StockControl::disallowMutation($action === 'delete_movement' ? 'deleted' : 'edited');
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Fetch products list for filters and modals
$allProducts = $pdo->query("SELECT id, name_en, name_km, stock_qty, reserved_qty, category FROM products WHERE is_active = 1 ORDER BY name_en ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$filterProduct = (int)($_GET['product_id'] ?? 0);
$filterType = trim($_GET['type'] ?? 'all');
$filterSearch = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM stock_movements WHERE 1=1";
$params = [];

if ($filterProduct > 0) {
    $sql .= " AND product_id = ?";
    $params[] = $filterProduct;
}

if ($filterType !== 'all' && in_array($filterType, ['received', 'used', 'reserved', 'released', 'adjusted', 'returned'], true)) {
    $sql .= " AND movement_type = ?";
    $params[] = $filterType;
}

if ($filterSearch !== '') {
    $sql .= " AND (reference_id LIKE ? OR notes LIKE ? OR performer_name LIKE ? OR product_name LIKE ?)";
    $like = '%' . $filterSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts
$totalLines = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 24px; gap: 10px; flex-wrap: wrap;">
    <a href="<?= APP_URL ?>/index.php?view=oil_care" class="btn btn-secondary">
        <i class="fa-solid fa-oil-can mr-1" style="color: #ea580c;"></i> Products &amp; Catalog
    </a>
    <button type="button" class="btn btn-secondary" onclick="openModal('receiveStockModal')">
        <i class="fa-solid fa-truck-ramp-box mr-1" style="color: #16a34a;"></i> Receive Supplier Stock
    </button>
    <button type="button" class="btn btn-primary" onclick="openModal('correctionModal')">
        <i class="fa-solid fa-pen-to-square mr-1"></i> Add Correcting Line
    </button>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span style="font-weight: 600;"><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Immutability Guarantee Banner -->
<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 24px; display: flex; align-items: center; gap: 14px;">
    <i class="fa-solid fa-shield-halved" style="color: #16a34a; font-size: 22px;"></i>
    <div style="flex: 1;">
        <strong style="color: #166534; font-size: 14px;">Append-Only Stock Ledger Protection</strong>
        <span class="text-xs" style="color: #14532d; display: block; margin-top: 2px;">
            Stock history lines cannot be edited or deleted anywhere in the system. Mistakes are fixed transparently by adding an append-only correcting line signed off with a Manager PIN.
        </span>
    </div>
    <span class="badge badge-success" style="font-size: 12px; font-weight: 700;">
        <?= number_format($totalLines) ?> Total Ledger Records
    </span>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 24px;">
    <form action="" method="GET" style="display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="view" value="stock_ledger">

        <div style="flex: 1; min-width: 200px;">
            <label class="form-label text-xs">Filter by Product</label>
            <select name="product_id" class="form-select">
                <option value="0">-- All Products --</option>
                <?php foreach ($allProducts as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= ($filterProduct === (int)$p['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name_en']) ?> (Available: <?= max(0, $p['stock_qty'] - ($p['reserved_qty'] ?? 0)) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="min-width: 170px;">
            <label class="form-label text-xs">Movement Type</label>
            <select name="type" class="form-select">
                <option value="all" <?= ($filterType === 'all') ? 'selected' : '' ?>>All Movement Types</option>
                <option value="received" <?= ($filterType === 'received') ? 'selected' : '' ?>>Received (In)</option>
                <option value="used" <?= ($filterType === 'used') ? 'selected' : '' ?>>Used / Sold</option>
                <option value="reserved" <?= ($filterType === 'reserved') ? 'selected' : '' ?>>Reserved (Queue)</option>
                <option value="released" <?= ($filterType === 'released') ? 'selected' : '' ?>>Released (Cancelled)</option>
                <option value="adjusted" <?= ($filterType === 'adjusted') ? 'selected' : '' ?>>Adjusted / Corrected</option>
                <option value="returned" <?= ($filterType === 'returned') ? 'selected' : '' ?>>Returned</option>
            </select>
        </div>

        <div style="flex: 1; min-width: 200px;">
            <label class="form-label text-xs">Search Reference / Notes / Staff</label>
            <input type="text" name="search" class="form-input" placeholder="e.g. CW-20261027, Supplier, Name" value="<?= htmlspecialchars($filterSearch) ?>">
        </div>

        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter mr-1"></i> Filter</button>
            <a href="<?= APP_URL ?>/index.php?view=stock_ledger" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Ledger Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-list-ol mr-1" style="color: var(--primary);"></i> Stock Movement Ledger
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table" style="font-size: 13.5px;">
            <thead>
                <tr>
                    <th>Movement UID</th>
                    <th>Date &amp; Time</th>
                    <th>Product</th>
                    <th>Movement Type</th>
                    <th style="text-align: right;">Quantity</th>
                    <th style="text-align: right;">Previous</th>
                    <th style="text-align: right;">New Balance</th>
                    <th>Reference</th>
                    <th>Why / Reason</th>
                    <th>Who (Staff)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 32px;">
                            No stock movements found matching your filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                        <?php
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
                            <td>
                                <strong><?= htmlspecialchars($m['product_name'] ?: ('Product #' . $m['product_id'])) ?></strong>
                            </td>
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
                            <td style="font-size: 12.5px;">
                                <span class="badge badge-secondary"><?= htmlspecialchars((string)($m['performer_name'] ?? 'System')) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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
                    Lines cannot be edited or deleted. This action fixes discrepancies by recording a new <strong>adjusted</strong> line with the real counted balance and explanation.
                </div>

                <div class="form-group">
                    <label class="form-label">Select Product *</label>
                    <select name="product_id" id="corr_product_id" class="form-select" required onchange="updateCorrPrevStock()">
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($allProducts as $p): ?>
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
                    <input type="text" name="reason" class="form-input" placeholder="e.g. Monthly physical inventory count variance, bottle damaged" required>
                </div>

                <!-- Manager PIN Authorization -->
                <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: var(--radius-md); padding: 14px; margin-bottom: 0;">
                    <div style="font-weight: 700; color: #ea580c; font-size: 13px; margin-bottom: 8px;">
                        <i class="fa-solid fa-user-lock mr-1"></i> Manager PIN Authorization
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label class="form-label text-xs">Manager Username *</label>
                        <input type="text" name="manager_username" class="form-input" value="<?= htmlspecialchars(in_array($userRole, ['owner', 'manager'], true) ? (Auth::getUsername() ?? '') : '') ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label text-xs">Manager 6-digit PIN *</label>
                        <input type="password" maxlength="6" name="manager_pin" class="form-input" placeholder="6-digit PIN" required style="font-family: var(--font-mono); letter-spacing: 4px;">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('correctionModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #b45309; border-color: #b45309;">Record Correcting Line</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Receive Supplier Stock -->
<div class="modal-backdrop" id="receiveStockModal">
    <div class="modal" style="max-width: 460px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #16a34a;"><i class="fa-solid fa-truck-ramp-box mr-1"></i> Receive Supplier Stock</h3>
            <button type="button" class="modal-close" onclick="closeModal('receiveStockModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="receive_stock">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Product *</label>
                    <select name="product_id" class="form-select" required>
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($allProducts as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['name_en']) ?> (Current on-hand: <?= (int)$p['stock_qty'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Quantity Received *</label>
                    <input type="number" step="1" min="1" name="qty_received" class="form-input" value="12" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Supplier Invoice / PO Reference</label>
                    <input type="text" name="supplier_ref" class="form-input" placeholder="e.g. INV-98214">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-input" placeholder="e.g. Received shipment from TotalEnergies distributor">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('receiveStockModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary" style="background: #16a34a; border-color: #16a34a;">Add to Inventory &amp; Ledger</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateCorrPrevStock() {
    let sel = document.getElementById('corr_product_id');
    let opt = sel.options[sel.selectedIndex];
    let stock = opt.getAttribute('data-stock') || '0';
    document.getElementById('corr_prev_stock').value = stock;
    document.getElementById('corr_new_balance').value = stock;
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
