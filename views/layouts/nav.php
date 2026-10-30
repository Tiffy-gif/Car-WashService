<?php
declare(strict_types=1);

$role = Auth::getRole();
$currentView = $_GET['view'] ?? 'dashboard';

$navLogo = get_setting('shop_logo', '');
$navNameEn = get_setting('shop_name_en', 'AquaGleam Car Wash & Detailing');
$navNameKm = get_setting('shop_name_km', 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប');
$navTitle = (Language::current() === 'km') ? ($navNameKm ?: $navNameEn ?: __('app_name')) : ($navNameEn ?: $navNameKm ?: __('app_name'));
$navSubtitle = (Language::current() === 'km') ? ($navNameEn ?: __('app_subtitle')) : ($navNameKm ?: __('app_subtitle'));

// Menu definition: 6 structured groups with icons and permissions
$rawMenuGroups = [
    [
        'id'    => 'dashboard',
        'title' => __('nav_dashboard'),
        'icon'  => 'fa-chart-pie',
        'items' => [
            [
                'view'       => 'dashboard',
                'label'      => __('nav_dashboard'),
                'icon'       => 'fa-chart-pie',
                'permission' => 'dashboard.view',
            ],
        ],
    ],
    [
        'id'    => 'sales_ops',
        'title' => __('nav_group_sales_ops'),
        'icon'  => 'fa-cash-register',
        'items' => [
            [
                'view'       => 'pos',
                'label'      => __('nav_pos'),
                'icon'       => 'fa-cash-register',
                'permission' => 'pos.access',
            ],
            [
                'view'       => 'shift',
                'label'      => __('nav_shifts', 'Shifts & Cash Handover'),
                'icon'       => 'fa-hand-holding-dollar',
                'permission' => 'pos.access',
            ],
            [
                'view'       => 'orders',
                'label'      => __('nav_orders'),
                'icon'       => 'fa-list-ol',
                'permission' => 'orders.list',
            ],
            [
                'view'       => 'bays_queue',
                'label'      => __('nav_bays_queue', 'Bays & Queue'),
                'icon'       => 'fa-warehouse',
                'permission' => 'bays.view',
            ],
            [
                'view'       => 'washer_jobs',
                'label'      => __('nav_washer_screen'),
                'icon'       => 'fa-soap',
                'permission' => 'washer_jobs.view',
            ],
            [
                'view'       => 'mechanic_jobs',
                'label'      => __('nav_mechanic_screen'),
                'icon'       => 'fa-wrench',
                'permission' => 'mechanic_jobs.view',
            ],
            [
                'view'       => 'vehicle_history',
                'label'      => __('nav_vehicle_history'),
                'icon'       => 'fa-clock-rotate-left',
                'permission' => 'vehicle_history.list',
            ],
            [
                'view'       => 'recovery_bulk_entry',
                'label'      => 'Disaster & Paper Recovery',
                'icon'       => 'fa-file-circle-check',
                'permission' => 'recovery.view',
            ],
        ],
    ],
    [
        'id'    => 'customers',
        'title' => __('nav_customers'),
        'icon'  => 'fa-users',
        'items' => [
            [
                'view'       => 'customers',
                'label'      => __('nav_customers'),
                'icon'       => 'fa-users',
                'permission' => 'customers.list',
            ],
        ],
    ],
    [
        'id'    => 'catalog_stock',
        'title' => __('nav_group_catalog_stock'),
        'icon'  => 'fa-boxes-stacked',
        'items' => [
            [
                'view'       => 'services',
                'label'      => __('nav_services'),
                'icon'       => 'fa-list-check',
                'permission' => 'services.list',
            ],
            [
                'view'       => 'oil_care',
                'label'      => __('nav_oil_care'),
                'icon'       => 'fa-oil-can',
                'permission' => 'oil_care.list',
            ],
            [
                'view'       => 'stock_ledger',
                'label'      => __('nav_stock_ledger', 'Stock Ledger & History'),
                'icon'       => 'fa-clipboard-list',
                'permission' => 'oil_care.list',
            ],
            [
                'view'       => 'extras',
                'label'      => __('nav_extras'),
                'icon'       => 'fa-puzzle-piece',
                'permission' => 'extras.list',
            ],
            [
                'view'       => 'categories',
                'label'      => __('nav_categories'),
                'icon'       => 'fa-tags',
                'permission' => 'categories.list',
            ],
        ],
    ],
    [
        'id'    => 'finance',
        'title' => __('nav_group_finance'),
        'icon'  => 'fa-sack-dollar',
        'items' => [
            [
                'view'       => 'expenses',
                'label'      => __('nav_expenses'),
                'icon'       => 'fa-wallet',
                'permission' => 'expenses.list',
            ],
            [
                'view'       => 'commissions',
                'label'      => __('nav_commissions'),
                'icon'       => 'fa-hand-holding-dollar',
                'permission' => 'commissions.list',
            ],
            [
                'view'       => 'reports',
                'label'      => __('nav_reports'),
                'icon'       => 'fa-chart-line',
                'permission' => 'reports.view',
            ],
        ],
    ],
    [
        'id'    => 'admin',
        'title' => __('nav_group_admin'),
        'icon'  => 'fa-sliders',
        'items' => [
            [
                'view'       => 'staff',
                'label'      => __('nav_staff'),
                'icon'       => 'fa-users-gear',
                'permission' => 'staff.list',
            ],
            [
                'view'       => 'roles',
                'label'      => __('nav_roles'),
                'icon'       => 'fa-user-shield',
                'permission' => 'roles.list',
            ],
            [
                'view'       => 'pos_devices',
                'label'      => __('nav_pos_devices'),
                'icon'       => 'fa-tablet-screen-button',
                'permission' => 'pos_devices.list',
            ],
            [
                'view'       => 'settings',
                'label'      => __('nav_settings'),
                'icon'       => 'fa-gear',
                'permission' => 'settings.view',
            ],
            [
                'view'       => 'sync_monitor',
                'label'      => 'Sync Monitor',
                'icon'       => 'fa-arrows-rotate',
                'permission' => 'sync.view',
            ],
            [
                'view'       => 'audit_logs',
                'label'      => __('nav_audit_logs', 'Audit History'),
                'icon'       => 'fa-shield-halved',
                'permission' => 'audit.view',
            ],
        ],
    ],
];

// Filter groups and items by user permissions
$visibleMenuGroups = [];
foreach ($rawMenuGroups as $grp) {
    $allowedItems = [];
    foreach ($grp['items'] as $item) {
        if (Auth::can($item['permission'])) {
            $allowedItems[] = $item;
        }
    }
    if (!empty($allowedItems)) {
        $grp['items'] = $allowedItems;
        $visibleMenuGroups[] = $grp;
    }
}
?>
<aside class="sidebar" id="appSidebar" data-accordion="false">
    <!-- Desktop Sidebar Toggle Button (30px round button centered on horizontal divider line) -->
    <button type="button" id="sidebarToggleBtn" class="sidebar-toggle-btn" aria-label="Collapse side menu" title="Collapse menu" style="position: absolute; right: -15px; top: 55px; width: 30px; height: 30px; border-radius: 50%; background: var(--bg-surface); border: 1px solid var(--border); box-shadow: 0 2px 6px rgba(0,0,0,0.1); color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 50; padding: 0;">
        <i class="fa-solid fa-chevron-left" id="sidebarToggleIcon" style="font-size: 11px;"></i>
    </button>

    <div class="brand">
        <?php if (!empty($navLogo)): ?>
            <div class="brand-icon" style="background: #ffffff; border: 1px solid var(--border); overflow: hidden; display: flex; align-items: center; justify-content: center; padding: 2px;">
                <img src="<?= APP_URL ?>/<?= htmlspecialchars($navLogo) ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
            </div>
        <?php else: ?>
            <div class="brand-icon">
                <i class="fa-solid fa-car-wash"></i>
            </div>
        <?php endif; ?>
        <div class="brand-text-wrap" style="overflow: hidden; min-width: 0; flex: 1;">
            <div class="brand-title" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($navTitle) ?>"><?= htmlspecialchars($navTitle) ?></div>
            <div class="brand-subtitle" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($navSubtitle) ?></div>
        </div>
    </div>

    <ul class="nav-links" id="sidebarNavLinks">
        <?php foreach ($visibleMenuGroups as $grp): ?>
            <?php
            $itemCount = count($grp['items']);
            $hasActiveView = false;
            foreach ($grp['items'] as $it) {
                if ($it['view'] === $currentView) {
                    $hasActiveView = true;
                    break;
                }
            }
            ?>

            <?php if ($itemCount === 1): ?>
                <?php
                $single = $grp['items'][0];
                $singleUrl = ($single['view'] === 'pos') ? get_pos_url() : APP_URL . '/index.php?view=' . $single['view'];
                ?>
                <li class="nav-item-single">
                    <a href="<?= $singleUrl ?>" 
                       class="nav-link <?= ($currentView === $single['view']) ? 'active' : '' ?>"
                       data-tooltip="<?= htmlspecialchars($single['label']) ?>">
                        <i class="fa-solid <?= $single['icon'] ?>"></i>
                        <span><?= htmlspecialchars($single['label']) ?></span>
                    </a>
                </li>
            <?php else: ?>
                <li class="nav-group <?= $hasActiveView ? 'has-active is-open' : '' ?>" 
                    data-group-id="<?= htmlspecialchars($grp['id']) ?>" 
                    id="navGroup_<?= htmlspecialchars($grp['id']) ?>">
                    <button type="button" 
                            class="nav-group-btn" 
                            id="btn_<?= htmlspecialchars($grp['id']) ?>"
                            aria-expanded="<?= $hasActiveView ? 'true' : 'false' ?>" 
                            aria-controls="subwrap_<?= htmlspecialchars($grp['id']) ?>"
                            data-group-id="<?= htmlspecialchars($grp['id']) ?>"
                            data-tooltip="<?= htmlspecialchars($grp['title']) ?>">
                        <i class="fa-solid <?= $grp['icon'] ?> nav-group-icon"></i>
                        <span class="nav-group-title"><?= htmlspecialchars($grp['title']) ?></span>
                        <i class="fa-solid fa-chevron-down nav-group-arrow" aria-hidden="true"></i>
                    </button>
                    <div class="nav-sub-wrap" id="subwrap_<?= htmlspecialchars($grp['id']) ?>">
                        <ul class="nav-sub-links" role="region" aria-labelledby="btn_<?= htmlspecialchars($grp['id']) ?>">
                            <?php foreach ($grp['items'] as $it): ?>
                                <?php $itUrl = ($it['view'] === 'pos') ? get_pos_url() : APP_URL . '/index.php?view=' . $it['view']; ?>
                                <li>
                                    <a href="<?= $itUrl ?>" 
                                       class="nav-link nav-sub-link <?= ($currentView === $it['view']) ? 'active' : '' ?>"
                                       data-tooltip="<?= htmlspecialchars($it['label']) ?>">
                                        <i class="fa-solid <?= $it['icon'] ?>"></i>
                                        <span><?= htmlspecialchars($it['label']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Hidden template data for Collapsed Sidebar Flyout -->
                    <div class="nav-group-flyout-template" style="display: none;" aria-hidden="true">
                        <div class="sidebar-flyout-header">
                            <i class="fa-solid <?= $grp['icon'] ?>"></i>
                            <span><?= htmlspecialchars($grp['title']) ?></span>
                        </div>
                        <ul class="sidebar-flyout-links">
                            <?php foreach ($grp['items'] as $it): ?>
                                <?php $itUrl = ($it['view'] === 'pos') ? get_pos_url() : APP_URL . '/index.php?view=' . $it['view']; ?>
                                <li>
                                    <a href="<?= $itUrl ?>" 
                                       class="sidebar-flyout-link <?= ($currentView === $it['view']) ? 'active' : '' ?>">
                                        <i class="fa-solid <?= $it['icon'] ?>"></i>
                                        <span><?= htmlspecialchars($it['label']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>

    <div class="user-profile-bar">
        <div class="user-avatar-wrap">
            <div class="user-avatar">
                <i class="fa-solid fa-user"></i>
            </div>
            <div>
                <div style="font-size: 13px; font-weight: 600;"><?= htmlspecialchars(Auth::getFullName() ?? '') ?></div>
                <?php
                $roleDisplay = __('role_' . $role);
                if ($roleDisplay === 'role_' . $role) {
                    $roleDisplay = ucfirst(str_replace('_', ' ', (string)$role));
                }
                ?>
                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">
                    <?= htmlspecialchars($roleDisplay) ?>
                </div>
            </div>
        </div>
    </div>
</aside>

<!-- Dark Backdrop Layer for Mobile & Tablet -->
<div id="sidebarBackdrop" class="sidebar-backdrop" aria-hidden="true"></div>

<!-- Floating Tooltip for Collapsed Sidebar (Single Links & Avatar) -->
<div id="sidebarTooltip" class="sidebar-tooltip" role="tooltip" aria-hidden="true"></div>

<!-- Floating Flyout Menu for Collapsed Sidebar (Category Groups) -->
<div id="sidebarFlyout" class="sidebar-flyout" role="menu" aria-hidden="true"></div>
