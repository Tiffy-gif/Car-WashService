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
$activeTab = $_GET['tab'] ?? 'service';
if (!in_array($activeTab, ['service', 'product', 'expense'], true)) {
    $activeTab = 'service';
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
        $type      = trim($_POST['type'] ?? 'service');
        $nameEn    = trim($_POST['name_en'] ?? '');
        $nameKm    = trim($_POST['name_km'] ?? '');
        $code      = trim($_POST['code'] ?? '');
        $icon      = trim($_POST['icon'] ?? 'fa-tag');
        $color     = trim($_POST['color'] ?? '#0284c7');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($type, ['service', 'product', 'expense'], true)) {
            $type = 'service';
        }
        $activeTab = $type;

        // Normalize code slug
        if ($code === '') {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $nameEn));
            $code = trim($code, '_');
        } else {
            $code = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $code));
            $code = trim($code, '_');
        }

        if ($nameEn === '' || $code === '') {
            $message = 'Category name (English) and code slug are required.';
            $messageType = 'danger';
        } else {
            if ($nameKm === '') {
                $nameKm = $nameEn;
            }

            // Check duplicate code within the same type
            $dupSql = "SELECT id FROM categories WHERE type = ? AND code = ?";
            $dupParams = [$type, $code];
            if ($catId) {
                $dupSql .= " AND id != ?";
                $dupParams[] = $catId;
            }
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute($dupParams);
            if ($dupStmt->fetch()) {
                $message = "A category with the code '{$code}' already exists for {$type}s. Please choose a different code.";
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
            $newStatus = (int)($_POST['is_active'] ?? 1);
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
                $activeTab = $catRow['type'];
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
                    $message = "Category '{$catRow['name_en']}' marked inactive (soft deleted).";
                }
            }
        }
        }
    }
}

// Fetch all categories for current tab
$catListStmt = $pdo->prepare("SELECT * FROM categories WHERE type = ? ORDER BY sort_order ASC, id ASC");
$catListStmt->execute([$activeTab]);
$categories = $catListStmt->fetchAll();

// Count items assigned to each category
$assignedCounts = [];
if ($activeTab === 'service') {
    $rows = $pdo->query("SELECT id, category_id, category FROM services")->fetchAll();
    foreach ($rows as $r) {
        if (!empty($r['category_id'])) {
            $assignedCounts['id_' . $r['category_id']] = ($assignedCounts['id_' . $r['category_id']] ?? 0) + 1;
        } elseif (!empty($r['category'])) {
            $assignedCounts['code_' . $r['category']] = ($assignedCounts['code_' . $r['category']] ?? 0) + 1;
        }
    }
} elseif ($activeTab === 'product') {
    $rows = $pdo->query("SELECT id, category_id, category FROM products")->fetchAll();
    foreach ($rows as $r) {
        if (!empty($r['category_id'])) {
            $assignedCounts['id_' . $r['category_id']] = ($assignedCounts['id_' . $r['category_id']] ?? 0) + 1;
        } elseif (!empty($r['category'])) {
            $assignedCounts['code_' . $r['category']] = ($assignedCounts['code_' . $r['category']] ?? 0) + 1;
        }
    }
} elseif ($activeTab === 'expense') {
    $rows = $pdo->query("SELECT id, category_id, category FROM expenses")->fetchAll();
    foreach ($rows as $r) {
        if (!empty($r['category_id'])) {
            $assignedCounts['id_' . $r['category_id']] = ($assignedCounts['id_' . $r['category_id']] ?? 0) + 1;
        } elseif (!empty($r['category'])) {
            $assignedCounts['code_' . $r['category']] = ($assignedCounts['code_' . $r['category']] ?? 0) + 1;
        }
    }
}

// Overall counts for tabs
$tabCounts = [
    'service' => (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE type = 'service'")->fetchColumn(),
    'product' => (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE type = 'product'")->fetchColumn(),
    'expense' => (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE type = 'expense'")->fetchColumn(),
];

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main);">
            <i class="fa-solid fa-tags mr-1" style="color: var(--primary);"></i> <?= htmlspecialchars(__('nav_categories')) ?>
        </h1>
        <p style="font-size: 14px; color: var(--text-muted);">
            Manage and organize dynamic categories for Services, Products & Spare Parts, and Operating Expenses.
        </p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openNewCategoryModal('<?= $activeTab ?>')">
        <i class="fa-solid fa-plus mr-1"></i> <?= htmlspecialchars(__('add_category')) ?>
    </button>
</div>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Tabs Header -->
<div style="display: flex; gap: 8px; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 24px; flex-wrap: wrap;">
    <a href="?view=categories&tab=service" class="btn btn-sm <?= ($activeTab === 'service') ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fa-solid fa-shower mr-1"></i> Service Categories (<?= $tabCounts['service'] ?>)
    </a>
    <a href="?view=categories&tab=product" class="btn btn-sm <?= ($activeTab === 'product') ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fa-solid fa-boxes-stacked mr-1"></i> Product Categories (<?= $tabCounts['product'] ?>)
    </a>
    <a href="?view=categories&tab=expense" class="btn btn-sm <?= ($activeTab === 'expense') ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fa-solid fa-wallet mr-1"></i> Expense Categories (<?= $tabCounts['expense'] ?>)
    </a>
</div>

<!-- Categories Table Card -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 60px;">Sort</th>
                    <th style="width: 280px;">Category Name</th>
                    <th>Code / Slug</th>
                    <th style="width: 140px;">Assigned Items</th>
                    <th style="width: 120px;">Status</th>
                    <th style="text-align: right; width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 40px;">
                            <i class="fa-solid fa-folder-open" style="font-size: 32px; opacity: 0.3; margin-bottom: 8px;"></i>
                            <p>No categories found for this section. Click "+ Add New Category" to create one.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $cat): 
                        $catColor = !empty($cat['color']) ? $cat['color'] : '#0284c7';
                        $catIcon  = !empty($cat['icon']) ? $cat['icon'] : 'fa-tag';
                        
                        $countById = $assignedCounts['id_' . $cat['id']] ?? 0;
                        $countByCode = $assignedCounts['code_' . $cat['code']] ?? 0;
                        $totalAssigned = max($countById, $countByCode);
                    ?>
                    <tr>
                        <td>
                            <span class="badge badge-slate" style="font-family: var(--font-mono); font-size: 11px;">
                                <?= (int)$cat['sort_order'] ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: <?= htmlspecialchars($catColor) ?>18; color: <?= htmlspecialchars($catColor) ?>; border: 1px solid <?= htmlspecialchars($catColor) ?>35; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;">
                                    <i class="fa-solid <?= htmlspecialchars($catIcon) ?>"></i>
                                </div>
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
                            <span class="badge badge-slate" style="font-size: 12px;">
                                <?= $totalAssigned ?> <?= ($activeTab === 'service') ? 'services' : (($activeTab === 'product') ? 'products' : 'expenses') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($cat['is_active']): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check mr-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-slate"><i class="fa-solid fa-ban mr-1"></i> Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <button type="button" class="btn btn-secondary btn-sm" data-category="<?= htmlspecialchars(json_encode($cat, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="editCategory(this)" title="Edit Category">
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

                                <form action="" method="POST" style="display: inline;" data-cat-name="<?= htmlspecialchars($cat['name_en'], ENT_QUOTES, 'UTF-8') ?>" data-assigned="<?= (int)$totalAssigned ?>" onsubmit="return confirmDeleteCategory(this);">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Delete Category">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
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
    <div class="modal" style="max-width: 560px;">
        <div class="modal-header">
            <h3 class="modal-title" id="catModalTitle"><?= htmlspecialchars(__('add_category')) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('categoryModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="category_id" id="cat_id" value="">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?= htmlspecialchars(__('category_type')) ?> *</label>
                    <select name="type" id="cat_type" class="form-select" required>
                        <option value="service" <?= ($activeTab === 'service') ? 'selected' : '' ?>>Service Category</option>
                        <option value="product" <?= ($activeTab === 'product') ? 'selected' : '' ?>>Product / Stock Category</option>
                        <option value="expense" <?= ($activeTab === 'expense') ? 'selected' : '' ?>>Expense Category</option>
                    </select>
                </div>

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

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('category_code')) ?> *</label>
                        <input type="text" name="code" id="cat_code" class="form-input" placeholder="e.g. engine_detailing" required pattern="[a-zA-Z0-9_]+" title="Only alphanumeric and underscore allowed">
                        <span class="text-xs text-muted">Unique key used in catalog and POS</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('sort_order')) ?></label>
                        <input type="number" name="sort_order" id="cat_sort_order" class="form-input" value="0">
                        <span class="text-xs text-muted">Lower numbers appear first</span>
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('icon')) ?></label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <div id="iconPreviewBadge" style="width: 40px; height: 40px; border-radius: var(--radius-md); background: var(--bg-subtle); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; color: var(--primary);">
                                <i class="fa-solid fa-tag" id="previewIcon"></i>
                            </div>
                            <input type="text" name="icon" id="cat_icon" class="form-input" value="fa-tag" placeholder="fa-shower, fa-wrench..." oninput="updateIconPreview(this.value)">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('color')) ?></label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="color" id="cat_color_picker" value="#0284c7" style="width: 40px; height: 40px; padding: 2px; border: 1px solid var(--border); border-radius: var(--radius-md); cursor: pointer;" oninput="document.getElementById('cat_color').value = this.value; updateColorPreview(this.value)">
                            <input type="text" name="color" id="cat_color" class="form-input" value="#0284c7" placeholder="#0284c7" oninput="document.getElementById('cat_color_picker').value = this.value; updateColorPreview(this.value)">
                        </div>
                    </div>
                </div>

                <!-- Quick Icon Presets -->
                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="text-xs text-muted" style="margin-bottom: 6px; display: block;">Suggested Icons (Click to select):</label>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <?php 
                        $suggestedIcons = [
                            'fa-shower', 'fa-broom', 'fa-sparkles', 'fa-gears', 'fa-wrench',
                            'fa-oil-can', 'fa-filter', 'fa-bottle-droplet', 'fa-spray-can-sparkles',
                            'fa-water', 'fa-bolt', 'fa-soap', 'fa-money-bill-wave', 'fa-screwdriver-wrench',
                            'fa-shield-halved', 'fa-battery-full', 'fa-box', 'fa-tag'
                        ];
                        foreach ($suggestedIcons as $si): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;" onclick="selectPresetIcon('<?= $si ?>')">
                                <i class="fa-solid <?= $si ?>"></i>
                            </button>
                        <?php endforeach; ?>
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
    // Only auto-fill if adding new category (not editing)
    if (!document.getElementById('cat_id').value) {
        let slug = val.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        codeField.value = slug;
    }
}

function updateIconPreview(iconClass) {
    const iconEl = document.getElementById('previewIcon');
    iconEl.className = 'fa-solid ' + (iconClass.trim() || 'fa-tag');
}

function selectPresetIcon(iconClass) {
    document.getElementById('cat_icon').value = iconClass;
    updateIconPreview(iconClass);
}

function updateColorPreview(colorHex) {
    const badge = document.getElementById('iconPreviewBadge');
    badge.style.color = colorHex;
}

function openNewCategoryModal(defaultType) {
    document.getElementById('catModalTitle').innerText = <?= json_encode(__('add_category')) ?>;
    document.getElementById('cat_id').value = '';
    document.getElementById('cat_type').value = defaultType || 'service';
    document.getElementById('cat_name_en').value = '';
    document.getElementById('cat_name_km').value = '';
    document.getElementById('cat_code').value = '';
    document.getElementById('cat_sort_order').value = '0';
    document.getElementById('cat_icon').value = 'fa-tag';
    document.getElementById('cat_color').value = '#0284c7';
    document.getElementById('cat_color_picker').value = '#0284c7';
    document.getElementById('cat_is_active').checked = true;

    updateIconPreview('fa-tag');
    updateColorPreview('#0284c7');
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

    document.getElementById('catModalTitle').innerText = <?= json_encode(__('edit_category')) ?>;
    document.getElementById('cat_id').value = cat.id || '';
    document.getElementById('cat_type').value = cat.type || 'service';
    document.getElementById('cat_name_en').value = cat.name_en || '';
    document.getElementById('cat_name_km').value = cat.name_km || '';
    document.getElementById('cat_code').value = cat.code || '';
    document.getElementById('cat_sort_order').value = cat.sort_order ?? '0';
    document.getElementById('cat_icon').value = cat.icon || 'fa-tag';
    
    const color = cat.color || '#0284c7';
    document.getElementById('cat_color').value = color;
    document.getElementById('cat_color_picker').value = color;
    document.getElementById('cat_is_active').checked = (cat.is_active == 1);

    updateIconPreview(cat.icon || 'fa-tag');
    updateColorPreview(color);
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
    return confirm("Are you sure you want to permanently delete the category '" + catName + "'?");
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
