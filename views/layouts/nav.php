<?php
declare(strict_types=1);

$role = Auth::getRole();
$currentView = $_GET['view'] ?? 'dashboard';

// Pending parts requests counter for warehouse badge
$navPendingParts = 0;
if (Auth::check() && Auth::can('oil_care.list')) {
    try {
        $navPdo = Database::getInstance();
        $navPendingParts = (int)$navPdo->query("SELECT COUNT(*) FROM parts_requests WHERE status = 'pending'")->fetchColumn();
    } catch (\Throwable $t) {}
}

$navLogo = get_setting('shop_logo', '');
$navNameEn = get_setting('shop_name_en', 'AquaGleam Car Wash & Detailing');
$navNameKm = get_setting('shop_name_km', 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប');
$navTitle = (Language::current() === 'km') ? ($navNameKm ?: $navNameEn ?: __('app_name')) : ($navNameEn ?: $navNameKm ?: __('app_name'));
$navSubtitle = (Language::current() === 'km') ? ($navNameEn ?: __('app_subtitle')) : ($navNameKm ?: __('app_subtitle'));

// Menu definition: structured groups with icons and permissions
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
                'view'       => 'appointments',
                'label'      => __('nav_appointments', 'Appointments'),
                'icon'       => 'fa-calendar-check',
                'permission' => 'extras.list',
            ],
            [
                'view'       => 'orders',
                'label'      => __('nav_orders', 'Orders & Invoices'),
                'icon'       => 'fa-list-ol',
                'permission' => 'orders.list',
            ],
            ['view' => 'shift', 'query' => ['section' => 'history'], 'label' => __('nav_shift_history', 'My Shift History'), 'icon' => 'fa-clock-rotate-left', 'permission' => 'pos.access', 'role_restricted' => ['cashier']],
            [
                'view'       => 'washer_jobs',
                'label'      => __('nav_washer_screen'),
                'icon'       => 'fa-soap',
                'permission' => 'washer_jobs.view',
                'role_restricted' => ['washer'],
            ],
            [
                'view'       => 'mechanic_jobs',
                'label'      => __('nav_mechanic_screen'),
                'icon'       => 'fa-wrench',
                'permission' => 'mechanic_jobs.view',
                'role_restricted' => ['mechanic', 'technical'],
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
            [
                'view'       => 'vehicle_history',
                'label'      => __('nav_vehicle_history', 'Vehicle Service History'),
                'icon'       => 'fa-clock-rotate-left',
                'permission' => 'vehicle_history.list',
            ],
            [
                'view'       => 'oil_reminders',
                'label'      => __('nav_oil_reminders', 'Oil Reminders'),
                'icon'       => 'fa-oil-can',
                'permission' => 'customers.list',
            ],
        ],
    ],
    [
        'id'    => 'services_catalog',
        'title' => __('nav_group_services', 'Services'),
        'icon'  => 'fa-soap',
        'items' => [
            [
                'view'       => 'services',
                'label'      => __('nav_services', 'Services & Pricing'),
                'icon'       => 'fa-list-check',
                'permission' => 'services.list',
            ],
            [
                'view'       => 'service_categories',
                'label'      => __('nav_service_categories', 'Service Categories'),
                'icon'       => 'fa-tags',
                'permission' => 'categories.list',
            ],
        ],
    ],
    [
        'id'    => 'products',
        'title' => __('nav_group_products', 'Products'),
        'icon'  => 'fa-box',
        'items' => [
            [
                'view'       => 'product_catalog',
                'label'      => __('nav_product_list', 'Product List'),
                'icon'       => 'fa-box',
                'permission' => 'oil_care.list',
            ],
            [
                'view'       => 'product_categories',
                'label'      => __('nav_product_categories', 'Product Categories'),
                'icon'       => 'fa-tags',
                'permission' => 'categories.list',
            ],
        ],
    ],
    [
        'id'    => 'stock',
        'title' => __('nav_group_stock', 'Stock'),
        'icon'  => 'fa-boxes-stacked',
        'items' => [
            [
                'view'       => 'stock_manage',
                'label'      => __('nav_manage_stock', 'Manage Stock'),
                'icon'       => 'fa-boxes-stacked',
                'permission' => 'oil_care.list',
            ],
            [
                'view'       => 'low_stock',
                'label'      => __('nav_low_stock', 'Low Stock'),
                'icon'       => 'fa-arrow-down-short-wide',
                'permission' => 'oil_care.list',
            ],
            [
                'view'       => 'stock_requests',
                'label'      => __('nav_technician_requests', 'Technician Requests'),
                'icon'       => 'fa-dolly',
                'permission' => 'oil_care.list',
                'badge'      => $navPendingParts > 0 ? (string)$navPendingParts : null,
                'badge_class'=> 'badge-amber',
            ],
            [
                'view'       => 'stock_movements',
                'label'      => __('nav_stock_movements', 'Stock Movements'),
                'icon'       => 'fa-arrow-right-arrow-left',
                'permission' => 'oil_care.list',
            ],
            [
                'view'       => 'stock_supplies',
                'label'      => __('nav_washing_supplies', 'Washing Supplies'),
                'icon'       => 'fa-flask',
                'permission' => 'oil_care.list',
            ],
            [
                'view'       => 'stock_counts',
                'label'      => __('nav_physical_counts', 'Physical Counts'),
                'icon'       => 'fa-scale-unbalanced',
                'permission' => 'oil_care.list',
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
                'allow_roles' => ['manager'],
            ],
            [
                'view'       => 'expense_categories',
                'label'      => __('nav_expense_categories', 'Expense Categories'),
                'icon'       => 'fa-tags',
                'permission' => 'categories.list',
            ],
        ],
    ],
    [
        'id'    => 'reports',
        'title' => __('nav_reports', 'Reports'),
        'icon'  => 'fa-chart-column',
        'items' => [
            ['view' => 'reports', 'query' => ['report' => 'financial_summary'], 'label' => __('nav_report_financial_summary', 'Financial Summary'), 'icon' => 'fa-chart-line', 'permission' => 'reports.view'],
            ['view' => 'reports', 'query' => ['report' => 'service_revenue'], 'label' => __('nav_report_service_revenue', 'Service Revenue'), 'icon' => 'fa-soap', 'permission' => 'reports.view'],
            ['view' => 'reports', 'query' => ['report' => 'product_sales'], 'label' => __('nav_report_product_sales', 'Product Sales'), 'icon' => 'fa-box-open', 'permission' => 'reports.view'],
            ['view' => 'reports', 'query' => ['report' => 'cancelled_orders'], 'label' => __('nav_report_cancelled_orders', 'Cancelled Orders'), 'icon' => 'fa-ban', 'permission' => 'reports.view', 'role_restricted' => ['owner', 'manager']],
            ['view' => 'cash_movements', 'label' => __('nav_cash_movements', 'Cash Movements'), 'icon' => 'fa-money-bill-transfer', 'permission' => 'reports.view', 'role_restricted' => ['owner', 'manager']],
            ['view' => 'cashier_sessions', 'label' => __('nav_cashier_sessions', 'Cashier Sessions'), 'icon' => 'fa-user-clock', 'permission' => 'reports.view', 'role_restricted' => ['owner', 'manager']],
            ['view' => 'shift', 'query' => ['section' => 'daily_closes'], 'label' => __('nav_daily_close_reports', 'Daily Close Reports'), 'icon' => 'fa-file-invoice-dollar', 'permission' => 'pos.access', 'role_restricted' => ['owner', 'manager', 'system_admin']],
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
                'view'       => 'recovery_bulk_entry',
                'label'      => __('nav_recovery', 'Disaster & Paper Recovery'),
                'icon'       => 'fa-file-circle-check',
                'permission' => 'recovery.view',
            ],
            [
                'view'       => 'sync_monitor',
                'label'      => __('nav_sync_monitor', 'Cloud Sync Monitor'),
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

// Filter groups and items by user permissions and roles
$visibleMenuGroups = [];
foreach ($rawMenuGroups as $grp) {
    $allowedItems = [];
    foreach ($grp['items'] as $item) {
        $allowRole = !empty($item['allow_roles']) && in_array(Auth::getRole(), (array)$item['allow_roles'], true);
        if (!Auth::can($item['permission']) && !$allowRole) {
            continue;
        }
        if (!empty($item['role_restricted']) && !in_array(Auth::getRole(), (array)$item['role_restricted'], true)) {
            continue;
        }
        if (!empty($item['profit_restricted']) && !Auth::canViewProfit()) {
            continue;
        }
        $allowedItems[] = $item;
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

    <?php
    // Determine effective view for sub-tab alias matching
    $effectiveView = $currentView;
    if (in_array($currentView, ['oil_care', 'parts_dispatch', 'products', 'supplies'], true)) {
        $legacyTab = $_GET['tab'] ?? (($navPendingParts > 0 || in_array($role, ['stock_control', 'warehouse'], true)) ? 'requests' : 'products');
        $tabViewMap = [
            'products' => 'stock_manage',
            'requests' => 'stock_requests',
            'ledger' => 'stock_movements',
            'supplies' => 'stock_supplies',
            'discrepancies' => 'stock_counts',
        ];
        $effectiveView = $tabViewMap[$legacyTab] ?? 'stock_manage';
    } elseif ($currentView === 'extras' && ($_GET['tab'] ?? '') === 'appointments') {
        $effectiveView = 'appointments';
    }
    ?>
    <ul class="nav-links" id="sidebarNavLinks">
        <?php foreach ($visibleMenuGroups as $grp): ?>
            <?php
            $itemCount = count($grp['items']);
            $hasActiveView = false;
            foreach ($grp['items'] as $it) {
                $queryMatches = true;
                foreach (($it['query'] ?? []) as $key => $value) {
                    $currentQueryValue = $_GET[$key] ?? (($it['view'] === 'shift' && $key === 'section') ? 'desk' : (($it['view'] === 'reports' && $key === 'report') ? 'financial_summary' : (($it['view'] === 'reports' && $key === 'section') ? 'financial' : '')));
                    if ((string)$currentQueryValue !== (string)$value) {
                        $queryMatches = false;
                        break;
                    }
                }
                if ($queryMatches && ($it['view'] === $effectiveView || $it['view'] === $currentView)) {
                    $hasActiveView = true;
                    break;
                }
            }
            ?>

            <?php if ($itemCount === 1): ?>
                <?php
                $single = $grp['items'][0];
                $singleUrl = ($single['view'] === 'pos') ? get_pos_url() : APP_URL . '/index.php?view=' . $single['view'];
                $isSingleActive = ($single['view'] === $effectiveView || $single['view'] === $currentView);
                ?>
                <li class="nav-item-single">
                    <a href="<?= $singleUrl ?>" 
                       class="nav-link <?= $isSingleActive ? 'active' : '' ?>"
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
                                <?php
                                $itemQuery = array_merge(['view' => $it['view']], $it['query'] ?? []);
                                if ($it['view'] === 'reports' && $currentView === 'reports') {
                                    foreach (['period', 'start_date', 'end_date'] as $dateFilterKey) {
                                        if (isset($_GET[$dateFilterKey])) $itemQuery[$dateFilterKey] = $_GET[$dateFilterKey];
                                    }
                                }
                                $itUrl = ($it['view'] === 'pos') ? get_pos_url() : APP_URL . '/index.php?' . http_build_query($itemQuery);
                                $queryMatches = true;
                                foreach (($it['query'] ?? []) as $key => $value) {
                                    $currentQueryValue = $_GET[$key] ?? (($it['view'] === 'shift' && $key === 'section') ? 'desk' : (($it['view'] === 'reports' && $key === 'report') ? 'financial_summary' : (($it['view'] === 'reports' && $key === 'section') ? 'financial' : '')));
                                    if ((string)$currentQueryValue !== (string)$value) {
                                        $queryMatches = false;
                                        break;
                                    }
                                }
                                $isItemActive = $queryMatches && (($it['view'] === $effectiveView) || ($it['view'] === $currentView && !in_array($currentView, ['oil_care', 'extras'], true)));
                                ?>
                                <li>
                                    <a href="<?= $itUrl ?>" 
                                       class="nav-link nav-sub-link <?= $isItemActive ? 'active' : '' ?>"
                                       data-tooltip="<?= htmlspecialchars($it['label']) ?>">
                                        <i class="fa-solid <?= $it['icon'] ?>"></i>
                                        <span><?= htmlspecialchars($it['label']) ?></span>
                                        <?php if (!empty($it['badge'])): ?>
                                            <span class="badge <?= htmlspecialchars($it['badge_class'] ?? 'badge-amber') ?>" style="margin-left: auto; font-size: 10px; padding: 2px 7px; border-radius: 9999px; font-weight: 700;"><?= htmlspecialchars((string)$it['badge']) ?></span>
                                        <?php endif; ?>
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
                                <?php
                                $itemQuery = array_merge(['view' => $it['view']], $it['query'] ?? []);
                                if ($it['view'] === 'reports' && $currentView === 'reports') {
                                    foreach (['period', 'start_date', 'end_date'] as $dateFilterKey) {
                                        if (isset($_GET[$dateFilterKey])) $itemQuery[$dateFilterKey] = $_GET[$dateFilterKey];
                                    }
                                }
                                $itUrl = ($it['view'] === 'pos') ? get_pos_url() : APP_URL . '/index.php?' . http_build_query($itemQuery);
                                $queryMatches = true;
                                foreach (($it['query'] ?? []) as $key => $value) {
                                    $currentQueryValue = $_GET[$key] ?? (($it['view'] === 'shift' && $key === 'section') ? 'desk' : (($it['view'] === 'reports' && $key === 'report') ? 'financial_summary' : (($it['view'] === 'reports' && $key === 'section') ? 'financial' : '')));
                                    if ((string)$currentQueryValue !== (string)$value) {
                                        $queryMatches = false;
                                        break;
                                    }
                                }
                                $isItemActive = $queryMatches && (($it['view'] === $effectiveView) || ($it['view'] === $currentView && !in_array($currentView, ['oil_care', 'extras'], true)));
                                ?>
                                <li>
                                    <a href="<?= $itUrl ?>" 
                                       class="sidebar-flyout-link <?= $isItemActive ? 'active' : '' ?>">
                                        <i class="fa-solid <?= $it['icon'] ?>"></i>
                                        <span><?= htmlspecialchars($it['label']) ?></span>
                                        <?php if (!empty($it['badge'])): ?>
                                            <span class="badge <?= htmlspecialchars($it['badge_class'] ?? 'badge-amber') ?>" style="margin-left: auto; font-size: 10px; padding: 2px 6px; border-radius: 9999px; font-weight: 700;"><?= htmlspecialchars((string)$it['badge']) ?></span>
                                        <?php endif; ?>
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
