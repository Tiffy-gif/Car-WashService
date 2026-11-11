<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/ImageStorage.php';

Auth::requirePermission('services.list');
$pageTitle = __('nav_services');
$pdo = Database::getInstance();

// Handle Form Submission (Add / Edit / Delete)
$message = null;
$messageType = 'success';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_service') {
        $serviceId   = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        if ($serviceId && !Auth::can('services.edit')) {
            $message = 'Permission denied: Cannot edit services.';
            $messageType = 'danger';
        } elseif (!$serviceId && !Auth::can('services.add')) {
            $message = 'Permission denied: Cannot add services.';
            $messageType = 'danger';
        } else {
            $nameEn      = trim($_POST['name_en'] ?? '');
        $nameKm      = trim($_POST['name_km'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon        = trim($_POST['icon'] ?? 'fa-car-wash');
        $categoryId  = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $category    = trim($_POST['category'] ?? '');
        $isActive    = isset($_POST['is_active']) ? 1 : 0;
        $baseDuration = max(1, (int)($_POST['base_duration_minutes'] ?? 15));
        $removeImage = (($_POST['remove_image'] ?? '0') === '1');

        // Resolve category_id and category slug
        if ($categoryId) {
            $catLookup = $pdo->prepare("SELECT id, code FROM categories WHERE id = ?");
            $catLookup->execute([$categoryId]);
            if ($cRow = $catLookup->fetch()) {
                $category = $cRow['code'];
            }
        } elseif ($category !== '') {
            $catLookup = $pdo->prepare("SELECT id, code FROM categories WHERE type = 'service' AND code = ?");
            $catLookup->execute([$category]);
            if ($cRow = $catLookup->fetch()) {
                $categoryId = (int)$cRow['id'];
            }
        }

        if (!$categoryId && empty($category)) {
            $catLookup = $pdo->query("SELECT id, code FROM categories WHERE type = 'service' ORDER BY sort_order ASC LIMIT 1");
            if ($cRow = $catLookup->fetch()) {
                $categoryId = (int)$cRow['id'];
                $category = $cRow['code'];
            } else {
                $category = 'wash';
            }
        }

        if ($nameEn === '' || $nameKm === '') {
            $message = 'Service name in English and Khmer are required.';
            $messageType = 'danger';
        } else {
            // Handle optional image upload
            $hasNewImage = isset($_FILES['service_image']) && ($_FILES['service_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $newImagePath = null;
            $newThumbPath = null;
            $newImageUpdated = null;
            $uploadFailed = false;

            if ($hasNewImage) {
                $uploadRes = ImageStorage::processUpload($_FILES['service_image'], 'services', 'svc');
                if (!$uploadRes['success']) {
                    $message = $uploadRes['error'];
                    $messageType = 'danger';
                    $uploadFailed = true;
                } else {
                    $newImagePath = $uploadRes['image_path'];
                    $newThumbPath = $uploadRes['thumb_path'];
                    $newImageUpdated = $uploadRes['image_updated_at'];
                }
            }

            if (!$uploadFailed) {
                if ($serviceId) {
                    // Fetch existing image paths to clean up if replaced or removed
                    $curr = $pdo->prepare("SELECT image_path, thumb_path FROM services WHERE id = ?");
                    $curr->execute([$serviceId]);
                    $oldRow = $curr->fetch();
                    $oldImg = $oldRow['image_path'] ?? null;
                    $oldThumb = $oldRow['thumb_path'] ?? null;

                    if ($hasNewImage) {
                        if ($oldImg || $oldThumb) {
                            ImageStorage::deleteFiles($oldImg, $oldThumb);
                        }
                        $stmt = $pdo->prepare("UPDATE services SET name_en = ?, name_km = ?, description = ?, icon = ?, category = ?, category_id = ?, is_active = ?, base_duration_minutes = ?, image_path = ?, thumb_path = ?, image_updated_at = ? WHERE id = ?");
                        $stmt->execute([$nameEn, $nameKm, $description, $icon, $category, $categoryId, $isActive, $baseDuration, $newImagePath, $newThumbPath, $newImageUpdated, $serviceId]);
                    } elseif ($removeImage) {
                        if ($oldImg || $oldThumb) {
                            ImageStorage::deleteFiles($oldImg, $oldThumb);
                        }
                        $stmt = $pdo->prepare("UPDATE services SET name_en = ?, name_km = ?, description = ?, icon = ?, category = ?, category_id = ?, is_active = ?, base_duration_minutes = ?, image_path = NULL, thumb_path = NULL, image_updated_at = ? WHERE id = ?");
                        $stmt->execute([$nameEn, $nameKm, $description, $icon, $category, $categoryId, $isActive, $baseDuration, date('Y-m-d H:i:s'), $serviceId]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE services SET name_en = ?, name_km = ?, description = ?, icon = ?, category = ?, category_id = ?, is_active = ?, base_duration_minutes = ? WHERE id = ?");
                        $stmt->execute([$nameEn, $nameKm, $description, $icon, $category, $categoryId, $isActive, $baseDuration, $serviceId]);
                    }
                } else {
                    // Insert
                    $stmt = $pdo->prepare("INSERT INTO services (name_en, name_km, description, icon, category, category_id, is_active, base_duration_minutes, image_path, thumb_path, image_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nameEn, $nameKm, $description, $icon, $category, $categoryId, $isActive, $baseDuration, $newImagePath, $newThumbPath, $newImageUpdated]);
                    $serviceId = (int)$pdo->lastInsertId();
                }

                // Save tiered prices for 4 vehicle sizes
                $vTypes = ['motorbike', 'small_car', 'suv_pickup', 'van_truck'];
                $pStmt = $pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE price_usd = VALUES(price_usd), price_khr = VALUES(price_khr), estimated_minutes = VALUES(estimated_minutes)");
                
                // Check SQLite compatibility for ON DUPLICATE KEY UPDATE
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $pStmt = $pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes)
                        VALUES (?, ?, ?, ?, ?)
                        ON CONFLICT(service_id, vehicle_type) DO UPDATE SET price_usd = excluded.price_usd, price_khr = excluded.price_khr, estimated_minutes = excluded.estimated_minutes");
                }

                foreach ($vTypes as $vt) {
                    $usd = (float)($_POST['price_' . $vt] ?? 0.00);
                    $khr = (int)round($usd * get_exchange_rate());
                    $min = (int)($_POST['time_' . $vt] ?? 30);
                    $pStmt->execute([$serviceId, $vt, $usd, $khr, $min]);
                }

                audit_log('PRICE_CHANGE', "Updated price matrix for service '{$nameEn}' (Service #{$serviceId})", 'service', (string)$serviceId);

                $message = 'Service and tiered pricing saved successfully!';
            }
        }
        }
    } elseif ($action === 'toggle_service_status' || $action === 'delete_service') {
        if ($action === 'delete_service' && !Auth::can('services.delete')) {
            $message = 'Permission denied: Cannot delete services.';
            $messageType = 'danger';
        } elseif ($action === 'toggle_service_status' && !Auth::can('services.status')) {
            $message = 'Permission denied: Cannot change service status.';
            $messageType = 'danger';
        } else {
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $newActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
            if ($serviceId > 0) {
                $pdo->prepare("UPDATE services SET is_active = ? WHERE id = ?")->execute([$newActive, $serviceId]);
                $message = ($newActive === 1) ? 'Service reactivated successfully!' : 'Service deactivated successfully! Historical orders stay safe.';
            }
        }
    }
}

// Fetch service categories
$serviceCategories = $pdo->query("SELECT * FROM categories WHERE type = 'service' AND is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$categoriesMap = [];
foreach ($serviceCategories as $sc) {
    $categoriesMap['id_' . $sc['id']] = $sc;
    $categoriesMap['code_' . $sc['code']] = $sc;
}

// Fetch services with category filter
$catFilter = $_GET['cat'] ?? 'all';
$sSql = "SELECT * FROM services WHERE 1=1";
$params = [];
if ($catFilter !== 'all') {
    if (is_numeric($catFilter)) {
        $sSql .= " AND category_id = ?";
        $params[] = (int)$catFilter;
    } else {
        $sSql .= " AND (category = ? OR category_id IN (SELECT id FROM categories WHERE code = ?))";
        $params[] = $catFilter;
        $params[] = $catFilter;
    }
}
$sSql .= " ORDER BY category_id ASC, category ASC, id ASC";
$servicesStmt = $pdo->prepare($sSql);
$servicesStmt->execute($params);
$services = $servicesStmt->fetchAll();

$catCounts = [];
$countsStmt = $pdo->query("SELECT category_id, category, COUNT(*) as cnt FROM services GROUP BY category_id, category");
while ($r = $countsStmt->fetch()) {
    if (!empty($r['category_id'])) {
        $catCounts['id_' . $r['category_id']] = ($catCounts['id_' . $r['category_id']] ?? 0) + (int)$r['cnt'];
    }
    if (!empty($r['category'])) {
        $catCounts['code_' . $r['category']] = ($catCounts['code_' . $r['category']] ?? 0) + (int)$r['cnt'];
    }
}
$totalServicesCount = (int)$pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();

// Fetch all prices indexed by [service_id][vehicle_type]
$pricesStmt = $pdo->query("SELECT * FROM service_prices");
$prices = [];
while ($row = $pricesStmt->fetch()) {
    $prices[$row['service_id']][$row['vehicle_type']] = $row;
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<?php if ($message): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
<?php endif; ?>

<!-- Category Filter Tabs & Actions -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="?view=services&cat=all" class="btn btn-sm <?= ($catFilter === 'all') ? 'btn-primary' : 'btn-secondary' ?>">
            All Services (<?= $totalServicesCount ?>)
        </a>
        <?php foreach ($serviceCategories as $sc): 
            $isActiveTab = ($catFilter === $sc['code'] || $catFilter === (string)$sc['id']);
            $cnt = max($catCounts['id_' . $sc['id']] ?? 0, $catCounts['code_' . $sc['code']] ?? 0);
            $scIcon = !empty($sc['icon']) ? $sc['icon'] : 'fa-tag';
            $scColor = !empty($sc['color']) ? $sc['color'] : 'inherit';
            $scName = (Language::current() === 'km') ? $sc['name_km'] : $sc['name_en'];
        ?>
        <a href="?view=services&cat=<?= htmlspecialchars($sc['code']) ?>" class="btn btn-sm <?= $isActiveTab ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fa-solid <?= htmlspecialchars($scIcon) ?> mr-1" style="<?= !$isActiveTab && !empty($sc['color']) ? 'color: ' . htmlspecialchars($sc['color']) . ';' : '' ?>"></i>
            <?= htmlspecialchars($scName) ?> (<?= $cnt ?>)
        </a>
        <?php endforeach; ?>
    </div>
    <?php if (Auth::can('services.add')): ?>
    <button type="button" class="btn btn-primary" onclick="openNewServiceModal()">
        <i class="fa-solid fa-plus mr-1"></i> <?= htmlspecialchars(__('add_service')) ?>
    </button>
    <?php endif; ?>
</div>

<!-- Services List / Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 250px;">Service</th>
                    <th style="width: 110px;">Base Time</th>
                    <th><?= htmlspecialchars(__('motorbike')) ?></th>
                    <th><?= htmlspecialchars(__('small_car')) ?></th>
                    <th><?= htmlspecialchars(__('suv_pickup')) ?></th>
                    <th><?= htmlspecialchars(__('van_truck')) ?></th>
                    <th><?= htmlspecialchars(__('status')) ?></th>
                    <th style="text-align: right; width: 140px;"><?= htmlspecialchars(__('actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $s): 
                    $sPrices = $prices[$s['id']] ?? [];
                    $catObj = null;
                    if (!empty($s['category_id']) && isset($categoriesMap['id_' . $s['category_id']])) {
                        $catObj = $categoriesMap['id_' . $s['category_id']];
                    } elseif (!empty($s['category']) && isset($categoriesMap['code_' . $s['category']])) {
                        $catObj = $categoriesMap['code_' . $s['category']];
                    }
                    $catColor = $catObj['color'] ?? '#0284c7';
                    $catIcon  = $catObj['icon'] ?? 'fa-shower';
                    $catName  = $catObj ? ((Language::current() === 'km') ? $catObj['name_km'] : $catObj['name_en']) : ucfirst($s['category'] ?? 'Wash');
                ?>
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <?php 
                            $hasImg = !empty($s['thumb_path']) && file_exists(dirname(__DIR__, 2) . '/' . $s['thumb_path']);
                            if ($hasImg): ?>
                                <img src="<?= APP_URL ?>/<?= htmlspecialchars($s['thumb_path']) ?>?v=<?= strtotime($s['image_updated_at'] ?? 'now') ?>" alt="Service" style="width: 40px; height: 40px; object-fit: contain; border-radius: var(--radius-md); background: #ffffff; border: 1px solid var(--border); padding: 2px; flex-shrink: 0; box-shadow: var(--shadow-xs);">
                            <?php else: ?>
                                <div class="stat-icon" style="width: 40px; height: 40px; font-size: 16px; flex-shrink: 0; background: <?= htmlspecialchars($catColor) ?>18; color: <?= htmlspecialchars($catColor) ?>; border: 1px solid <?= htmlspecialchars($catColor) ?>35;">
                                    <i class="fa-solid <?= htmlspecialchars($s['icon'] ?? $catIcon) ?>"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-weight: 700; font-size: 14.5px; color: var(--text-main);">
                                        <?= htmlspecialchars((Language::current() === 'km') ? $s['name_km'] : $s['name_en']) ?>
                                    </span>
                                    <span class="badge" style="font-size: 10px; background: <?= htmlspecialchars($catColor) ?>18; color: <?= htmlspecialchars($catColor) ?>; border: 1px solid <?= htmlspecialchars($catColor) ?>35;">
                                        <i class="fa-solid <?= htmlspecialchars($catIcon) ?> mr-1"></i> <?= htmlspecialchars($catName) ?>
                                    </span>
                                </div>
                                <div class="text-xs text-muted">
                                    <?= htmlspecialchars((Language::current() === 'km') ? $s['name_en'] : $s['name_km']) ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-info" style="font-weight: 600; font-size: 11px;">
                            <i class="fa-regular fa-clock mr-1"></i> <?= (int)($s['base_duration_minutes'] ?? 15) ?> min
                        </span>
                    </td>
                    <td>
                        <?php $p = $sPrices['motorbike'] ?? null; ?>
                        <div><strong><?= $p ? formatUSD((float)$p['price_usd']) : '$0.00' ?></strong></div>
                        <div class="text-xs text-muted"><?= $p ? formatKHR((int)$p['price_khr']) : '0 ៛' ?> • <?= $p ? (int)$p['estimated_minutes'] : 20 ?>m</div>
                    </td>
                    <td>
                        <?php $p = $sPrices['small_car'] ?? null; ?>
                        <div><strong><?= $p ? formatUSD((float)$p['price_usd']) : '$0.00' ?></strong></div>
                        <div class="text-xs text-muted"><?= $p ? formatKHR((int)$p['price_khr']) : '0 ៛' ?> • <?= $p ? (int)$p['estimated_minutes'] : 30 ?>m</div>
                    </td>
                    <td>
                        <?php $p = $sPrices['suv_pickup'] ?? null; ?>
                        <div><strong><?= $p ? formatUSD((float)$p['price_usd']) : '$0.00' ?></strong></div>
                        <div class="text-xs text-muted"><?= $p ? formatKHR((int)$p['price_khr']) : '0 ៛' ?> • <?= $p ? (int)$p['estimated_minutes'] : 40 ?>m</div>
                    </td>
                    <td>
                        <?php $p = $sPrices['van_truck'] ?? null; ?>
                        <div><strong><?= $p ? formatUSD((float)$p['price_usd']) : '$0.00' ?></strong></div>
                        <div class="text-xs text-muted"><?= $p ? formatKHR((int)$p['price_khr']) : '0 ៛' ?> • <?= $p ? (int)$p['estimated_minutes'] : 50 ?>m</div>
                    </td>
                    <td>
                        <?php if ($s['is_active']): ?>
                            <span class="badge badge-success"><i class="fa-solid fa-check mr-1"></i> <?= htmlspecialchars(__('active')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-slate"><i class="fa-solid fa-ban mr-1"></i> <?= htmlspecialchars(__('inactive')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if (Auth::can('services.edit')): ?>
                        <button type="button" class="btn btn-secondary btn-sm" data-service="<?= htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" data-prices="<?= htmlspecialchars(json_encode($sPrices, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="editService(this)" title="<?= htmlspecialchars(__('edit_service')) ?>">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <?php endif; ?>
                        <?php if (Auth::can('services.status')): ?>
                        <form action="" method="POST" style="display: inline-block;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_service_status">
                            <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $s['is_active'] ? '0' : '1' ?>">
                            <?php if ($s['is_active']): ?>
                                <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Deactivate Service" onclick="return confirm('Deactivate this service? It will be hidden from new orders, but existing orders remain intact.');">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--success);" title="Reactivate Service">
                                    <i class="fa-solid fa-circle-check"></i>
                                </button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add / Edit Service -->
<div class="modal-backdrop" id="serviceModal">
    <div class="modal" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle"><?= htmlspecialchars(__('add_service')) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('serviceModal')">&times;</button>
        </div>
        <form action="" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_service">
            <input type="hidden" name="service_id" id="service_id" value="">

            <div class="modal-body">
                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('service_name_en')) ?> *</label>
                        <input type="text" name="name_en" id="name_en" class="form-input" placeholder="e.g. Foam Wash" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('service_name_km')) ?> *</label>
                        <input type="text" name="name_km" id="name_km" class="form-input" placeholder="ឧទាហរណ៍៖ លាងបាញ់ពពុះសាប៊ូ" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= htmlspecialchars(__('service_description')) ?></label>
                    <textarea name="description" id="description" class="form-textarea" rows="2" placeholder="Brief service details..."></textarea>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <!-- Custom Category Dropdown -->
                    <div class="form-group" style="position: relative;">
                        <label class="form-label">Service Category *</label>
                        <input type="hidden" name="category" id="category" value="">
                        <input type="hidden" name="category_id" id="category_id" value="">
                        <div id="categoryTrigger" class="form-input" tabindex="0" style="display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <i id="categoryIconPreview" class="fa-solid fa-shower" style="color: var(--primary); width: 16px; text-align: center;"></i>
                                <span id="categoryLabelText">Car Wash &amp; Detailing</span>
                            </div>
                            <i class="fa-solid fa-chevron-down" style="font-size: 11px; color: var(--text-muted);"></i>
                        </div>
                        <div id="categoryDropdownList" style="display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); z-index: 100; max-height: 220px; overflow-y: auto;">
                            <?php foreach ($serviceCategories as $sc): 
                                $scName = (Language::current() === 'km') ? $sc['name_km'] : $sc['name_en'];
                                $scIcon = !empty($sc['icon']) ? $sc['icon'] : 'fa-tag';
                                $scColor = !empty($sc['color']) ? $sc['color'] : '#0284c7';
                            ?>
                                <div class="cat-choice-item" data-id="<?= $sc['id'] ?>" data-value="<?= htmlspecialchars($sc['code']) ?>" data-icon="<?= htmlspecialchars($scIcon) ?>" data-color="<?= htmlspecialchars($scColor) ?>" data-label="<?= htmlspecialchars($scName) ?>" style="padding: 10px 14px; display: flex; align-items: center; gap: 10px; cursor: pointer; transition: background 0.15s ease; border-bottom: 1px solid var(--border-light);">
                                    <i class="fa-solid <?= htmlspecialchars($scIcon) ?>" style="color: <?= htmlspecialchars($scColor) ?>; width: 18px; text-align: center;"></i>
                                    <span><?= htmlspecialchars($scName) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Custom Icon Grid Dropdown -->
                    <div class="form-group" style="position: relative;">
                        <label class="form-label"><?= htmlspecialchars(__('icon')) ?></label>
                        <input type="hidden" name="icon" id="icon" value="fa-shower">
                        <div id="iconTrigger" class="form-input" tabindex="0" style="display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none;">
                            <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                                <i id="iconPreview" class="fa-solid fa-shower" style="color: var(--primary); width: 16px; text-align: center;"></i>
                                <span id="iconLabelText" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">fa-shower (Wash / Rinse)</span>
                            </div>
                            <i class="fa-solid fa-chevron-down" style="font-size: 11px; color: var(--text-muted); flex-shrink: 0; margin-left: 8px;"></i>
                        </div>
                        <div id="iconDropdownPanel" style="display: none; position: absolute; top: calc(100% + 4px); right: 0; width: 310px; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 100; padding: 10px; max-height: 165px; overflow-y: auto;">
                            <div id="iconTileGrid" style="display: grid; grid-template-columns: repeat(6, 44px); gap: 6px; justify-content: center;">
                                <!-- Icon tiles -->
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px; margin-bottom: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label"><i class="fa-regular fa-clock mr-1" style="color: var(--primary);"></i> Base Duration (minutes) *</label>
                        <input type="number" name="base_duration_minutes" id="base_duration_minutes" class="form-input" min="1" max="600" value="15" required>
                        <span class="text-xs text-muted">Standard benchmark for Sedan (1.0x). Size multipliers scale from this.</span>
                    </div>
                    <div class="form-group" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: flex-end; padding-bottom: 8px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="is_active" id="is_active" value="1" checked style="width: 18px; height: 18px;">
                            <span style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars(__('active')) ?></span>
                        </label>
                    </div>
                </div>

                <!-- Service Picture Upload Field -->
                <div class="form-group" style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); margin-bottom: 14px;">
                    <label class="form-label" style="display: flex; align-items: center; justify-content: space-between;">
                        <span><i class="fa-solid fa-image mr-1" style="color: var(--primary);"></i> Service Picture</span>
                        <span class="text-xs text-muted">JPG, PNG, WebP (Max 5 MB)</span>
                    </label>
                    <input type="hidden" name="remove_image" id="svc_remove_image" value="0">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <!-- Preview Box -->
                        <div id="svc_img_preview_box" style="width: 80px; height: 80px; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--bg-subtle); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; flex-shrink: 0; box-shadow: var(--shadow-xs);">
                            <img id="svc_img_preview" src="" alt="Preview" style="display: none; width: 100%; height: 100%; object-fit: contain; background: #ffffff; padding: 2px;">
                            <div id="svc_img_placeholder" style="color: var(--text-muted); font-size: 26px; text-align: center;">
                                <i class="fa-solid fa-shower" id="svc_placeholder_icon"></i>
                            </div>
                        </div>

                        <div style="flex: 1;">
                            <input type="file" name="service_image" id="svc_image_input" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handleServiceImageSelect(this)">
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('svc_image_input').click()">
                                    <i class="fa-solid fa-upload mr-1"></i> <span id="svc_img_btn_text">Choose Picture</span>
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" id="svc_img_remove_btn" onclick="removeServiceImage()" style="display: none;">
                                    <i class="fa-solid fa-trash mr-1"></i> Remove
                                </button>
                            </div>
                            <p class="text-xs text-muted" id="svc_img_help_text" style="margin-top: 6px;">
                                Upload an optional picture from your computer. Automatically formatted into 600px and 200px square images without stretching.
                            </p>
                        </div>
                    </div>
                </div>

                <h4 style="font-size: 15px; font-weight: 700; margin: 16px 0 12px; color: var(--primary);">
                    <i class="fa-solid fa-tags mr-1"></i> <?= htmlspecialchars(__('pricing_by_vehicle')) ?>
                </h4>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <!-- Motorbike -->
                    <div style="background: var(--bg-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border);">
                        <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px;">
                            <i class="fa-solid fa-motorcycle mr-1" style="color: #9333ea;"></i> <?= htmlspecialchars(__('motorbike')) ?>
                        </div>
                        <div class="grid grid-cols-2" style="gap: 8px;">
                            <div>
                                <label class="text-xs text-muted">Price ($)</label>
                                <input type="number" step="0.25" name="price_motorbike" id="price_motorbike" class="form-input" value="1.50" required>
                            </div>
                            <div>
                                <label class="text-xs text-muted">Time (min)</label>
                                <input type="number" name="time_motorbike" id="time_motorbike" class="form-input" value="20" required>
                            </div>
                        </div>
                    </div>

                    <!-- Small Car -->
                    <div style="background: var(--bg-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border);">
                        <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px;">
                            <i class="fa-solid fa-car-side mr-1" style="color: #0284c7;"></i> <?= htmlspecialchars(__('small_car')) ?>
                        </div>
                        <div class="grid grid-cols-2" style="gap: 8px;">
                            <div>
                                <label class="text-xs text-muted">Price ($)</label>
                                <input type="number" step="0.25" name="price_small_car" id="price_small_car" class="form-input" value="3.50" required>
                            </div>
                            <div>
                                <label class="text-xs text-muted">Time (min)</label>
                                <input type="number" name="time_small_car" id="time_small_car" class="form-input" value="30" required>
                            </div>
                        </div>
                    </div>

                    <!-- SUV / Pickup -->
                    <div style="background: var(--bg-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border);">
                        <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px;">
                            <i class="fa-solid fa-truck-pickup mr-1" style="color: #d97706;"></i> <?= htmlspecialchars(__('suv_pickup')) ?>
                        </div>
                        <div class="grid grid-cols-2" style="gap: 8px;">
                            <div>
                                <label class="text-xs text-muted">Price ($)</label>
                                <input type="number" step="0.25" name="price_suv_pickup" id="price_suv_pickup" class="form-input" value="5.00" required>
                            </div>
                            <div>
                                <label class="text-xs text-muted">Time (min)</label>
                                <input type="number" name="time_suv_pickup" id="time_suv_pickup" class="form-input" value="40" required>
                            </div>
                        </div>
                    </div>

                    <!-- Van / Truck -->
                    <div style="background: var(--bg-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border);">
                        <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px;">
                            <i class="fa-solid fa-van-shuttle mr-1" style="color: #475569;"></i> <?= htmlspecialchars(__('van_truck')) ?>
                        </div>
                        <div class="grid grid-cols-2" style="gap: 8px;">
                            <div>
                                <label class="text-xs text-muted">Price ($)</label>
                                <input type="number" step="0.25" name="price_van_truck" id="price_van_truck" class="form-input" value="7.00" required>
                            </div>
                            <div>
                                <label class="text-xs text-muted">Time (min)</label>
                                <input type="number" name="time_van_truck" id="time_van_truck" class="form-input" value="50" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('serviceModal')"><?= htmlspecialchars(__('cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= htmlspecialchars(__('save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const serviceCategoriesList = <?= json_encode($serviceCategories, JSON_UNESCAPED_UNICODE) ?>;
const serviceIcons = [
    { icon: 'fa-shower', name: 'fa-shower (Wash / Rinse)' },
    { icon: 'fa-broom', name: 'fa-broom (Interior Vacuum)' },
    { icon: 'fa-wand-magic-sparkles', name: 'fa-wand-magic-sparkles (Full Detail)' },
    { icon: 'fa-gears', name: 'fa-gears (Engine Bay)' },
    { icon: 'fa-shield-halved', name: 'fa-shield-halved (Wax / Protect)' },
    { icon: 'fa-soap', name: 'fa-soap (Foam Soap)' },
    { icon: 'fa-circle-dot', name: 'fa-circle-dot (Tire Patch)' },
    { icon: 'fa-arrows-rotate', name: 'fa-arrows-rotate (Tire Change / Rotation)' },
    { icon: 'fa-compact-disc', name: 'fa-compact-disc (Brakes)' },
    { icon: 'fa-magnifying-glass-chart', name: 'fa-magnifying-glass-chart (Inspection)' },
    { icon: 'fa-car-battery', name: 'fa-car-battery (Battery)' },
    { icon: 'fa-lightbulb', name: 'fa-lightbulb (Bulb Change)' },
    { icon: 'fa-wind', name: 'fa-wind (Wiper Blades)' },
    { icon: 'fa-wrench', name: 'fa-wrench (General Repair)' },
    { icon: 'fa-oil-can', name: 'fa-oil-can (Oil & Lubrication)' },
    { icon: 'fa-spray-can-sparkles', name: 'fa-spray-can-sparkles (Detail Spray)' },
    { icon: 'fa-car', name: 'fa-car (Car Detailing)' },
    { icon: 'fa-screwdriver-wrench', name: 'fa-screwdriver-wrench (Maintenance)' }
];

function initCustomDropdowns() {
    // Populate Icon Grid (18 icons, 6 per row in ~44px square tiles)
    const grid = document.getElementById('iconTileGrid');
    if (grid && grid.children.length === 0) {
        serviceIcons.forEach(item => {
            const tile = document.createElement('div');
            tile.className = 'icon-tile-item';
            tile.dataset.icon = item.icon;
            tile.dataset.name = item.name;
            tile.title = item.name;
            tile.style.cssText = 'width: 44px; height: 44px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1px solid var(--border); font-size: 16px; background: var(--bg-surface); color: var(--text-main); transition: all 0.15s ease; box-sizing: border-box;';
            tile.innerHTML = '<i class="fa-solid ' + item.icon + '"></i>';

            tile.addEventListener('mouseenter', () => {
                if (document.getElementById('icon').value !== item.icon) {
                    tile.style.background = 'var(--bg-subtle)';
                }
            });
            tile.addEventListener('mouseleave', () => {
                if (document.getElementById('icon').value !== item.icon) {
                    tile.style.background = 'var(--bg-surface)';
                }
            });
            tile.addEventListener('click', (e) => {
                e.stopPropagation();
                selectIconChoice(item.icon, item.name);
                document.getElementById('iconDropdownPanel').style.display = 'none';
            });
            grid.appendChild(tile);
        });
    }

    // Category trigger
    const catTrigger = document.getElementById('categoryTrigger');
    const catList = document.getElementById('categoryDropdownList');
    if (catTrigger && catList) {
        catTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = (catList.style.display === 'block');
            closeAllCustomDropdowns();
            catList.style.display = isOpen ? 'none' : 'block';
        });
    }

    // Category options
    document.querySelectorAll('.cat-choice-item').forEach(el => {
        el.addEventListener('mouseenter', () => { el.style.background = 'var(--bg-subtle)'; });
        el.addEventListener('mouseleave', () => { el.style.background = 'transparent'; });
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            selectCategoryChoice(el.dataset.value, el.dataset.id, el.dataset.label, el.dataset.icon, el.dataset.color);
            catList.style.display = 'none';
        });
    });

    // Icon trigger
    const iconTrigger = document.getElementById('iconTrigger');
    const iconPanel = document.getElementById('iconDropdownPanel');
    if (iconTrigger && iconPanel) {
        iconTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = (iconPanel.style.display === 'block');
            closeAllCustomDropdowns();
            iconPanel.style.display = isOpen ? 'none' : 'block';
        });
    }

    // Close on click outside
    document.addEventListener('click', (e) => {
        closeAllCustomDropdowns();
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllCustomDropdowns();
        }
    });
}

function closeAllCustomDropdowns() {
    const catList = document.getElementById('categoryDropdownList');
    if (catList) catList.style.display = 'none';
    const iconPanel = document.getElementById('iconDropdownPanel');
    if (iconPanel) iconPanel.style.display = 'none';
}

function selectCategoryChoice(val, id, labelText, iconClass, color) {
    const hidden = document.getElementById('category');
    const hiddenId = document.getElementById('category_id');
    const preview = document.getElementById('categoryIconPreview');
    const label = document.getElementById('categoryLabelText');

    hidden.value = val || '';
    if (hiddenId) hiddenId.value = id || '';
    preview.className = 'fa-solid ' + (iconClass || 'fa-tag');
    if (color) {
        preview.style.color = color;
    }
    label.innerText = labelText || val;
}

function selectIconChoice(iconClass, customName) {
    // Map deprecated fa-sparkles to fa-wand-magic-sparkles
    if (iconClass === 'fa-sparkles') iconClass = 'fa-wand-magic-sparkles';

    const hidden = document.getElementById('icon');
    const preview = document.getElementById('iconPreview');
    const label = document.getElementById('iconLabelText');

    hidden.value = iconClass;
    preview.className = 'fa-solid ' + iconClass;

    let foundName = customName;
    if (!foundName) {
        const item = serviceIcons.find(x => x.icon === iconClass);
        foundName = item ? item.name : iconClass;
    }
    label.innerText = foundName;

    // Highlight selected tile
    document.querySelectorAll('.icon-tile-item').forEach(tile => {
        if (tile.dataset.icon === iconClass) {
            tile.style.background = 'var(--primary)';
            tile.style.color = '#ffffff';
            tile.style.borderColor = 'var(--primary)';
        } else {
            tile.style.background = 'var(--bg-surface)';
            tile.style.color = 'var(--text-main)';
            tile.style.borderColor = 'var(--border)';
        }
    });
}

function resetServiceImageUI() {
    const input = document.getElementById('svc_image_input');
    if (input) input.value = '';
    const removeField = document.getElementById('svc_remove_image');
    if (removeField) removeField.value = '0';
    const preview = document.getElementById('svc_img_preview');
    if (preview) {
        preview.src = '';
        preview.style.display = 'none';
    }
    const placeholder = document.getElementById('svc_img_placeholder');
    if (placeholder) placeholder.style.display = 'block';
    const btnText = document.getElementById('svc_img_btn_text');
    if (btnText) btnText.innerText = 'Choose Picture';
    const removeBtn = document.getElementById('svc_img_remove_btn');
    if (removeBtn) removeBtn.style.display = 'none';
}

function setServiceImagePreview(url) {
    if (!url) {
        resetServiceImageUI();
        return;
    }
    const preview = document.getElementById('svc_img_preview');
    const placeholder = document.getElementById('svc_img_placeholder');
    const btnText = document.getElementById('svc_img_btn_text');
    const removeBtn = document.getElementById('svc_img_remove_btn');
    const removeField = document.getElementById('svc_remove_image');

    if (preview) {
        preview.src = url;
        preview.style.display = 'block';
    }
    if (placeholder) placeholder.style.display = 'none';
    if (btnText) btnText.innerText = 'Replace Picture';
    if (removeBtn) removeBtn.style.display = 'inline-flex';
    if (removeField) removeField.value = '0';
}

function handleServiceImageSelect(input) {
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

    document.getElementById('svc_remove_image').value = '0';
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('svc_img_preview').src = e.target.result;
        document.getElementById('svc_img_preview').style.display = 'block';
        document.getElementById('svc_img_placeholder').style.display = 'none';
        document.getElementById('svc_img_btn_text').innerText = 'Replace Picture';
        document.getElementById('svc_img_remove_btn').style.display = 'inline-flex';
    };
    reader.readAsDataURL(file);
}

function removeServiceImage() {
    document.getElementById('svc_image_input').value = '';
    document.getElementById('svc_remove_image').value = '1';
    document.getElementById('svc_img_preview').src = '';
    document.getElementById('svc_img_preview').style.display = 'none';
    document.getElementById('svc_img_placeholder').style.display = 'block';
    document.getElementById('svc_img_btn_text').innerText = 'Choose Picture';
    document.getElementById('svc_img_remove_btn').style.display = 'none';
}

function openNewServiceModal() {
    document.getElementById('modalTitle').innerText = <?= json_encode(__('add_service')) ?>;
    document.getElementById('service_id').value = '';
    document.getElementById('name_en').value = '';
    document.getElementById('name_km').value = '';
    document.getElementById('description').value = '';

    const defaultCat = (Array.isArray(serviceCategoriesList) && serviceCategoriesList.length > 0)
        ? serviceCategoriesList[0]
        : { id: '', code: 'wash', name_en: 'Car Wash & Detailing', name_km: 'លាងសម្អាត & ថែទាំរថយន្ត', icon: 'fa-shower', color: '#0284c7' };
    const defaultCatName = (<?= json_encode(Language::current()) ?> === 'km' && defaultCat.name_km) ? defaultCat.name_km : defaultCat.name_en;
    selectCategoryChoice(defaultCat.code, defaultCat.id, defaultCatName, defaultCat.icon, defaultCat.color);
    selectIconChoice(defaultCat.icon || 'fa-shower');
    document.getElementById('is_active').checked = true;
    document.getElementById('base_duration_minutes').value = '15';

    document.getElementById('price_motorbike').value = '1.50';
    document.getElementById('time_motorbike').value = '20';
    document.getElementById('price_small_car').value = '3.50';
    document.getElementById('time_small_car').value = '30';
    document.getElementById('price_suv_pickup').value = '5.00';
    document.getElementById('time_suv_pickup').value = '40';
    document.getElementById('price_van_truck').value = '7.00';
    document.getElementById('time_van_truck').value = '50';

    resetServiceImageUI();
    closeAllCustomDropdowns();
    openModal('serviceModal');
}

function editService(btn, prices) {
    let s = btn;
    if (btn && btn.getAttribute && btn.getAttribute('data-service')) {
        try {
            s = JSON.parse(btn.getAttribute('data-service'));
            prices = JSON.parse(btn.getAttribute('data-prices') || '{}');
        } catch (e) {
            console.error('Error parsing service data:', e);
            return;
        }
    }
    if (!prices) prices = {};

    document.getElementById('modalTitle').innerText = <?= json_encode(__('edit_service')) ?>;
    document.getElementById('service_id').value = s.id || '';
    document.getElementById('name_en').value = s.name_en || '';
    document.getElementById('name_km').value = s.name_km || '';
    document.getElementById('description').value = s.description || '';

    // Match category by category_id or code
    let matchedCat = (Array.isArray(serviceCategoriesList))
        ? serviceCategoriesList.find(c => (s.category_id && c.id == s.category_id) || (s.category && c.code === s.category))
        : null;
    if (!matchedCat) {
        matchedCat = { id: s.category_id || '', code: s.category || 'wash', name_en: s.category || 'Wash', icon: s.icon || 'fa-shower', color: '#0284c7' };
    }
    const catName = (<?= json_encode(Language::current()) ?> === 'km' && matchedCat.name_km) ? matchedCat.name_km : matchedCat.name_en;
    selectCategoryChoice(matchedCat.code, matchedCat.id, catName, matchedCat.icon, matchedCat.color);
    selectIconChoice(s.icon || matchedCat.icon || 'fa-shower');
    document.getElementById('is_active').checked = (s.is_active == 1);
    document.getElementById('base_duration_minutes').value = s.base_duration_minutes || '15';

    const m = prices.motorbike || { price_usd: 1.50, estimated_minutes: 20 };
    const sc = prices.small_car || { price_usd: 3.50, estimated_minutes: 30 };
    const suv = prices.suv_pickup || { price_usd: 5.00, estimated_minutes: 40 };
    const van = prices.van_truck || { price_usd: 7.00, estimated_minutes: 50 };

    document.getElementById('price_motorbike').value = m.price_usd ?? '1.50';
    document.getElementById('time_motorbike').value = m.estimated_minutes ?? '20';
    document.getElementById('price_small_car').value = sc.price_usd ?? '3.50';
    document.getElementById('time_small_car').value = sc.estimated_minutes ?? '30';
    document.getElementById('price_suv_pickup').value = suv.price_usd ?? '5.00';
    document.getElementById('time_suv_pickup').value = suv.estimated_minutes ?? '40';
    document.getElementById('price_van_truck').value = van.price_usd ?? '7.00';
    document.getElementById('time_van_truck').value = van.estimated_minutes ?? '50';

    resetServiceImageUI();
    if (s.thumb_path || s.image_path) {
        const url = '<?= APP_URL ?>/' + (s.thumb_path || s.image_path) + '?v=' + (s.image_updated_at || Date.now());
        setServiceImagePreview(url);
    }

    closeAllCustomDropdowns();
    openModal('serviceModal');
}

document.addEventListener('DOMContentLoaded', initCustomDropdowns);
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
