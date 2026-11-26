<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('categories.list');
$pageTitle = __('nav_categories');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';
$categoryTypes = ['service', 'product', 'expense'];
$typeFilter = $_GET['type'] ?? $_GET['tab'] ?? 'product';
if (!in_array($typeFilter, $categoryTypes, true)) {
    $typeFilter = 'product';
}

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Save Category (Add or Edit)
    if ($action === 'save_category') {
        $catId     = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        if ($catId && !Auth::can('categories.edit')) {
            $message = 'Permission denied: Cannot edit category.';
            $messageType = 'danger';
        } elseif (!$catId && !Auth::can('categories.add')) {
            $message = 'Permission denied: Cannot add category.';
            $messageType = 'danger';
        } else {
        $type      = trim($_POST['type'] ?? 'product');
        $nameEn    = trim($_POST['name_en'] ?? '');
        $nameKm    = trim($_POST['name_km'] ?? '');
        $code      = trim($_POST['code'] ?? '');
        $defaultIcon = $type === 'service' ? 'fa-shower' : ($type === 'expense' ? 'fa-wallet' : 'fa-box');
        $icon      = trim($_POST['icon'] ?? $defaultIcon);
        $color     = trim($_POST['color'] ?? '#0284c7');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($type, $categoryTypes, true)) {
            $type = 'product';
        }

        // Category type and code are stable identifiers once created. Changing
        // either can detach existing services/products/expenses from their group.
        $existingCategory = null;
        if ($catId) {
            $existingStmt = $pdo->prepare('SELECT id, type, code, icon, color, sort_order FROM categories WHERE id = ?');
            $existingStmt->execute([$catId]);
            $existingCategory = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$existingCategory) {
                $message = 'Category not found.';
                $messageType = 'danger';
            } else {
                $type = $existingCategory['type'];
                $code = $existingCategory['code'];
                $icon = $existingCategory['icon'] ?? 'fa-box';
                $color = $existingCategory['color'] ?? '#0284c7';
                $sortOrder = (int)($existingCategory['sort_order'] ?? 0);
            }
        }
        $typeFilter = $type;

        // Normalize code slug
        if ($code === '') {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $nameEn));
            $code = trim($code, '_');
        } else {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $code));
            $code = trim($code, '_');
        }

        if ($messageType === 'danger') {
            // Preserve the error above and skip persistence.
        } elseif ($nameEn === '' || $code === '') {
            $message = 'Category name (English) and code slug are required.';
            $messageType = 'danger';
        } else {
            if ($nameKm === '') {
                $nameKm = $nameEn;
            }

            // The database has a global UNIQUE constraint on category codes.
            $dupSql = "SELECT id FROM categories WHERE code = ?";
            $dupParams = [$code];
            if ($catId) {
                $dupSql .= " AND id != ?";
                $dupParams[] = $catId;
            }
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute($dupParams);
            if ($dupStmt->fetch()) {
                $message = "A category with the code '{$code}' already exists. Please choose a different name.";
                $messageType = 'danger';
            } else {
                if ($catId) {
                    $stmt = $pdo->prepare("
                        UPDATE categories SET 
                            type = ?, code = ?, name_en = ?, name_km = ?, icon = ?, color = ?, sort_order = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([$type, $code, $nameEn, $nameKm, $icon, $color, $sortOrder, $isActive, $catId]);
                    $message = "Category '{$nameEn}' updated successfully!";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO categories (type, code, name_en, name_km, icon, color, sort_order, is_active, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$type, $code, $nameEn, $nameKm, $icon, $color, $sortOrder, $isActive]);
                    $message = "Category '{$nameEn}' created successfully!";
                }
            }
        }
        }
    }

    // 2. Toggle Status
    elseif ($action === 'toggle_status') {
        if (!Auth::can('categories.edit')) {
            $message = 'Permission denied: Cannot edit category status.';
            $messageType = 'danger';
        } else {
            $catId = (int)($_POST['category_id'] ?? 0);
            $newStatus = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
            if ($catId > 0) {
                $pdo->prepare("UPDATE categories SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$newStatus, $catId]);
                $message = "Category status updated.";
            }
        }
    }

    // 3. Delete Category (Protected by Deletion Guard)
    elseif ($action === 'delete_category') {
        if (!Auth::can('categories.delete')) {
            $message = 'Permission denied: Cannot delete category.';
            $messageType = 'danger';
        } else {
            $catId = (int)($_POST['category_id'] ?? 0);
            if ($catId > 0) {
            $catStmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
            $catStmt->execute([$catId]);
            $catRow = $catStmt->fetch();

            if ($catRow) {
                $typeFilter = $catRow['type'];
                $itemCount = 0;
                $tableLabel = 'items';

                if ($catRow['type'] === 'service') {
                    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE category_id = ? OR category = ?");
                    $cStmt->execute([$catRow['id'], $catRow['code']]);
                    $itemCount = (int)$cStmt->fetchColumn();
                    $tableLabel = 'services';
                } elseif ($catRow['type'] === 'product') {
                    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ? OR category = ?");
                    $cStmt->execute([$catRow['id'], $catRow['code']]);
                    $itemCount = (int)$cStmt->fetchColumn();
                    $tableLabel = 'products';
                } elseif ($catRow['type'] === 'expense') {
                    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = ? OR category = ?");
                    $cStmt->execute([$catRow['id'], $catRow['code']]);
                    $itemCount = (int)$cStmt->fetchColumn();
                    $tableLabel = 'expenses';
                }

                if ($itemCount > 0) {
                    $message = "Cannot delete category '{$catRow['name_en']}' because {$itemCount} {$tableLabel} are currently assigned to it. Please deactivate the category or reassign those {$tableLabel} first.";
                    $messageType = 'danger';
                } elseif (in_array($catRow['code'], ['wash', 'repair', 'engine_oil', 'oil_filter', 'fluid', 'care_product', 'spare_part'], true)) {
                    $message = "Cannot delete core system category '{$catRow['name_en']}'. You may deactivate it instead if not in use.";
                    $messageType = 'danger';
                } else {
                    // SOFT DELETE per requirement
                    $pdo->prepare("UPDATE categories SET is_active = 0 WHERE id = ?")->execute([$catId]);
                    audit_log('CATEGORY_DEACTIVATED', "Deactivated category '{$catRow['name_en']}' ({$catRow['code']})", 'category', (string)$catId);
                    $message = "Category '{$catRow['name_en']}' deactivated.";
                }
            }
        }
        }
    }
}

// This page is type-scoped by its entry point. The stock sidebar opens product
// categories, while the service editor can open the same list for services.
$categorySql = 'SELECT * FROM categories WHERE type = ? ORDER BY sort_order ASC, id ASC';
$catListStmt = $pdo->prepare($categorySql);
$catListStmt->execute([$typeFilter]);
$categories = $catListStmt->fetchAll(PDO::FETCH_ASSOC);

// Count distinct assigned records by either the current ID link or legacy code.
$assignedCounts = [];
foreach (['service' => 'services', 'product' => 'products', 'expense' => 'expenses'] as $type => $table) {
    $countSql = "SELECT c.id, COUNT(DISTINCT i.id) AS item_count
        FROM categories c LEFT JOIN {$table} i
        ON (i.category_id = c.id OR i.category = c.code)
        WHERE c.type = ? GROUP BY c.id";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute([$type]);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $countRow) {
        $assignedCounts[(int)$countRow['id']] = (int)$countRow['item_count'];
    }
}

$typeLabels = ['service' => 'Service', 'product' => 'Product', 'expense' => 'Expense'];
$defaultCreateType = $typeFilter;
$pageHeading = $typeFilter === 'product' ? 'Product Categories' : ($typeFilter === 'service' ? 'Service Categories' : 'Expense Categories');
$pageDescription = $typeFilter === 'product'
    ? 'Create product categories, then select one when adding a product.'
    : ($typeFilter === 'service' ? 'Group services so they are easier to manage.' : 'Group expenses so they are easier to manage.');

include dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Category page heading and actions -->
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
    <div>
        <h2 style="font-size:20px;font-weight:700;margin:0 0 4px;"><?= htmlspecialchars($pageHeading) ?></h2>
        <p class="text-sm text-muted" style="margin:0;"><?= htmlspecialchars($pageDescription) ?></p>
    </div>
    <?php if (Auth::can('categories.add')): ?>
    <button type="button" class="btn btn-primary" onclick="openNewCategoryModal('<?= htmlspecialchars($defaultCreateType, ENT_QUOTES, 'UTF-8') ?>')">
        <i class="fa-solid fa-plus mr-1"></i> Add <?= $typeFilter === 'product' ? 'Category' : htmlspecialchars($typeLabels[$typeFilter] . ' Category') ?>
    </button>
    <?php endif; ?>
</div>

<!-- Categories Table Card -->
<div class="table-toolbar">
    <input type="search" id="categorySearch" class="form-input" placeholder="Search categories..." style="max-width:320px;">
    <select id="categoryStatusFilter" class="form-input" style="width:auto;min-width:130px;">
        <option value="all">All statuses</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
</div>

<div class="table-card">
    <div class="table-responsive">
        <table class="table js-paginated-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Category slug</th>
                    <th style="width:150px;">Assigned products</th>
                    <th>Created On</th>
                    <th style="width: 120px;">Status</th>
                    <th style="text-align: right; width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 48px 20px;">
                            <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--bg-subtle); display: inline-flex; align-items: center; justify-content: center; font-size: 24px; color: var(--primary); margin-bottom: 12px;">
                                <i class="fa-solid fa-folder-plus"></i>
                            </div>
                            <h4 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
                                <?= $typeFilter === 'product' ? 'No Categories Yet' : 'No Categories Found' ?>
                            </h4>
                            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px; max-width: 420px; margin-left: auto; margin-right: auto;">
                                <?= $typeFilter === 'product' ? 'Add your first category, then choose it when adding products.' : htmlspecialchars($pageDescription) ?>
                            </p>
                            <?php if (Auth::can('categories.add')): ?><button type="button" class="btn btn-primary btn-sm" onclick="openNewCategoryModal('<?= htmlspecialchars($defaultCreateType, ENT_QUOTES, 'UTF-8') ?>')">
                                <i class="fa-solid fa-plus mr-1"></i> Add Category
                            </button><?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat):
                        $totalAssigned = $assignedCounts[(int)$cat['id']] ?? 0;
                        $createdOn = !empty($cat['created_at']) ? date('d M Y', strtotime($cat['created_at'])) : '—';
                    ?>
                    <tr class="category-row" data-category-search="<?= htmlspecialchars(strtolower($cat['name_en'] . ' ' . $cat['name_km'] . ' ' . $cat['code']), ENT_QUOTES, 'UTF-8') ?>" data-category-status="<?= $cat['is_active'] ? 'active' : 'inactive' ?>">
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div>
                                    <div style="font-weight: 700; font-size: 14.5px; color: var(--text-main);">
                                        <?= htmlspecialchars((Language::current() === 'km') ? $cat['name_km'] : $cat['name_en']) ?>
                                    </div>
                                    <div class="text-xs text-muted">
                                        <?= htmlspecialchars((Language::current() === 'km') ? $cat['name_en'] : $cat['name_km']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <code style="background: var(--bg-subtle); border: 1px solid var(--border); padding: 3px 8px; border-radius: 4px; font-size: 12px; color: var(--text-main);">
                                <?= htmlspecialchars($cat['code']) ?>
                            </code>
                        </td>
                        <td>
                            <span class="badge badge-slate"><?= $totalAssigned ?> <?= $totalAssigned === 1 ? 'product' : 'products' ?></span>
                        </td>
                        <td><?= htmlspecialchars($createdOn) ?></td>
                        <td>
                            <?php if ($cat['is_active']): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check mr-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-slate"><i class="fa-solid fa-ban mr-1"></i> Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <?php if (Auth::can('categories.edit')): ?><button type="button" class="btn btn-secondary btn-sm" data-category="<?= htmlspecialchars(json_encode($cat, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="editCategory(this)" title="Edit Category">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>

                                <form action="" method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $cat['is_active'] ? '0' : '1' ?>">
                                    <?php if ($cat['is_active']): ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Deactivate Category">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--success);" title="Activate Category">
                                            <i class="fa-solid fa-circle-check"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>

                                <?php if (Auth::can('categories.delete')): ?>
                                <form action="" method="POST" style="display: inline;" data-cat-name="<?= htmlspecialchars($cat['name_en'], ENT_QUOTES, 'UTF-8') ?>" data-assigned="<?= (int)$totalAssigned ?>" onsubmit="return confirmDeleteCategory(this);">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Delete Category">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
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

<!-- Add / Edit Category Modal -->
<div class="modal-backdrop" id="categoryModal">
    <div class="modal" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="modal-title" id="catModalTitle"><?= htmlspecialchars(__('add_category')) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('categoryModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="category_id" id="cat_id" value="">
            <input type="hidden" name="type" id="cat_type" value="<?= htmlspecialchars($defaultCreateType) ?>">
            <input type="hidden" name="code" id="cat_code" value="">

            <div class="modal-body">
                <p class="text-sm text-muted" id="catTypeHelp" style="margin:0 0 16px;">Products in this category can be selected when adding stock items.</p>
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('category_name_en')) ?> *</label>
                        <input type="text" name="name_en" id="cat_name_en" class="form-input" placeholder="e.g. Engine Detailing" required oninput="autoSuggestSlug(this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('category_name_km')) ?> *</label>
                        <input type="text" name="name_km" id="cat_name_km" class="form-input" placeholder="e.g. លាងបន្ទប់ម៉ាស៊ីន" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="cat_is_active" value="1" checked style="width: 18px; height: 18px;">
                        <span style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars(__('active')) ?></span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= htmlspecialchars(__('save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function autoSuggestSlug(val) {
    const codeField = document.getElementById('cat_code');
    if (!document.getElementById('cat_id').value) {
        let slug = val.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        codeField.value = slug;
    }
}

function openNewCategoryModal(defaultType) {
    const type = defaultType || '<?= $defaultCreateType ?>';
    let title = 'Add Category';
    if (type === 'service') {
        title = 'Add Service Category';
    } else if (type === 'product') {
        title = 'Add Product Category';
    } else if (type === 'expense') {
        title = 'Add Expense Category';
    }

    document.getElementById('catModalTitle').innerText = title;
    document.getElementById('cat_id').value = '';
    document.getElementById('cat_type').value = type;
    document.getElementById('catTypeHelp').textContent = type === 'product'
        ? 'Products in this category can be selected when adding stock items.'
        : (type === 'service' ? 'Services in this category can be selected when adding services.' : 'Expenses in this category can be selected when recording expenses.');
    document.getElementById('cat_name_en').value = '';
    document.getElementById('cat_name_en').placeholder = type === 'product' ? 'e.g. Engine Oil' : (type === 'service' ? 'e.g. Washing Services' : 'e.g. Utilities');
    document.getElementById('cat_name_km').value = '';
    document.getElementById('cat_code').value = '';
    document.getElementById('cat_is_active').checked = true;
    openModal('categoryModal');
}

function editCategory(btnOrCat) {
    let cat = btnOrCat;
    if (btnOrCat && btnOrCat.getAttribute && btnOrCat.getAttribute('data-category')) {
        try {
            cat = JSON.parse(btnOrCat.getAttribute('data-category'));
        } catch (e) {
            console.error('Error parsing category data:', e);
            return;
        }
    }
    if (!cat) return;

    const type = cat.type || 'service';
    let title = 'Edit Category';
    if (type === 'service') title = 'Edit Service Category';
    else if (type === 'product') title = 'Edit Product Category';
    else if (type === 'expense') title = 'Edit Expense Category';

    document.getElementById('catModalTitle').innerText = title;
    document.getElementById('cat_id').value = cat.id || '';
    document.getElementById('cat_type').value = type;
    document.getElementById('catTypeHelp').textContent = 'Category type cannot be changed after creation, so existing items stay correctly grouped.';
    document.getElementById('cat_name_en').value = cat.name_en || '';
    document.getElementById('cat_name_en').placeholder = 'Category name';
    document.getElementById('cat_name_km').value = cat.name_km || '';
    document.getElementById('cat_code').value = cat.code || '';
    document.getElementById('cat_is_active').checked = (cat.is_active == 1);
    openModal('categoryModal');
}

function confirmDeleteCategory(formOrName, assignedCount) {
    let catName = formOrName;
    let count = assignedCount;
    if (formOrName && formOrName.getAttribute) {
        catName = formOrName.getAttribute('data-cat-name') || 'this category';
        count = parseInt(formOrName.getAttribute('data-assigned')) || 0;
    }
    if (count > 0) {
        alert("Cannot delete category '" + catName + "' because " + count + " items are currently assigned to it.\n\nPlease deactivate the category or reassign those items first.");
        return false;
    }
    return confirm("Deactivate the category '" + catName + "'? It will be kept in records but hidden from new selections.");
}

function applyCategoryTableFilters() {
    const query = (document.getElementById('categorySearch')?.value || '').trim().toLowerCase();
    const status = document.getElementById('categoryStatusFilter')?.value || 'all';
    document.querySelectorAll('.category-row').forEach(row => {
        const matchesSearch = !query || (row.dataset.categorySearch || '').includes(query);
        const matchesStatus = status === 'all' || row.dataset.categoryStatus === status;
        row.hidden = !(matchesSearch && matchesStatus);
    });
}

document.getElementById('categorySearch')?.addEventListener('input', applyCategoryTableFilters);
document.getElementById('categoryStatusFilter')?.addEventListener('change', applyCategoryTableFilters);
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
