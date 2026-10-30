<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/NotificationManager.php';
require_once dirname(__DIR__, 2) . '/core/TimecardManager.php';

Language::init();
$currentUser = Auth::user();
$currentLang = Language::current();

$unreadNotifCount = 0;
$soundMuted = false;
$initialNotifs = [];
$activeTimecard = null;
if ($currentUser) {
    try {
        $hdrPdo = Database::getInstance();
        $unreadNotifCount = NotificationManager::getUnreadCount($hdrPdo, (int)$currentUser['id'], $currentUser['role']);
        $soundMuted = NotificationManager::isSoundMuted($hdrPdo, (int)$currentUser['id']);
        $initialNotifs = NotificationManager::getNotifications($hdrPdo, (int)$currentUser['id'], $currentUser['role'], 15);
        $activeTimecard = TimecardManager::getActiveTimecard($hdrPdo, (int)$currentUser['id']);
    } catch (\Throwable $e) {
        // Fallback silently if tables are still initializing
    }
}

$hdrShopEn = get_setting('shop_name_en', 'AquaGleam Car Wash & Detailing');
$hdrShopKm = get_setting('shop_name_km', 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប');
$hdrLogo   = get_setting('shop_logo', '');
$hdrShopTitle = ($currentLang === 'km') ? ($hdrShopKm ?: $hdrShopEn ?: __('app_name')) : ($hdrShopEn ?: $hdrShopKm ?: __('app_name'));
$pageHeaderTitle = isset($pageTitle) && trim((string)$pageTitle) !== '' ? ($pageTitle . ' - ' . $hdrShopTitle) : $hdrShopTitle;
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageHeaderTitle) ?></title>
    <?php if (!empty($hdrLogo)): ?>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/<?= htmlspecialchars($hdrLogo) ?>">
    <?php endif; ?>
    <!-- Anti-flicker Early Initialization -->
    <script>
    (function() {
        try {
            var savedTheme = localStorage.getItem('carwash_theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
            if (localStorage.getItem('carwash_sidebar_collapsed') === 'true' && window.innerWidth > 1024) {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch(e) {}
    })();
    </script>
    <?php if ($currentUser): ?>
    <script>
    window.CARWASH_APP = {
        userId: <?= (int)$currentUser['id'] ?>,
        userRole: <?= json_encode($currentUser['role']) ?>,
        userName: <?= json_encode($currentUser['full_name'] ?? $currentUser['username']) ?>,
        soundMuted: <?= $soundMuted ? 'true' : 'false' ?>,
        currentLang: <?= json_encode($currentLang) ?>,
        baseUrl: <?= json_encode(APP_URL) ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>,
        activeTimecard: <?= json_encode($activeTimecard) ?>
    };
    </script>
    <?php endif; ?>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">
    <!-- Main Style (dynamic cache-buster ensures fresh CSS is always loaded) -->
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/css/style.css') ?>">
    <style>
    /* Critical Inline Styles for Side Menu Button & Drawer */
    .sidebar-toggle-btn {
        position: absolute;
        right: -15px;
        top: 55px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background-color: var(--bg-surface, #ffffff);
        border: 1px solid var(--border, #e2e8f0);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        color: var(--text-muted, #64748b);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        z-index: 50;
        transition: all 0.2s ease;
        outline: none;
        padding: 0;
    }
    .sidebar-toggle-btn:hover,
    .sidebar-toggle-btn:focus-visible {
        color: var(--primary, #0284c7);
        border-color: var(--primary, #0284c7);
        background-color: var(--bg-subtle, #f8fafc);
        box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
    }
    .sidebar-toggle-btn:focus-visible {
        outline: 2px solid var(--primary, #0284c7);
        outline-offset: 2px;
    }
    .brand {
        height: 70px;
        min-height: 70px;
        max-height: 70px;
        padding: 0 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        border-bottom: 1px solid var(--border, #e2e8f0);
        box-sizing: border-box;
    }
    .brand-icon {
        width: 44px;
        height: 44px;
        flex-shrink: 0;
    }
    .brand-title {
        font-size: 15px;
        line-height: 1.2;
    }
    .brand-subtitle {
        font-size: 11px;
        line-height: 1.2;
    }
    .mobile-menu-btn {
        display: none !important;
    }
    .mobile-sidebar-close {
        display: none !important;
    }
    .sidebar.collapsed,
    html.sidebar-collapsed .sidebar {
        width: 72px !important;
    }
    .sidebar.collapsed .brand,
    html.sidebar-collapsed .sidebar .brand {
        height: 70px !important;
        min-height: 70px !important;
        max-height: 70px !important;
        padding: 0 !important;
        justify-content: center !important;
        align-items: center !important;
    }
    .sidebar.collapsed .brand-text-wrap,
    html.sidebar-collapsed .sidebar .brand-text-wrap {
        display: none !important;
    }
    .sidebar.collapsed .brand-icon,
    html.sidebar-collapsed .sidebar .brand-icon {
        margin: 0 auto !important;
    }
    .sidebar.collapsed .nav-links,
    html.sidebar-collapsed .sidebar .nav-links {
        padding: 18px 0 !important;
        align-items: center !important;
        overflow-y: auto !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
    }
    .sidebar.collapsed .nav-links::-webkit-scrollbar,
    html.sidebar-collapsed .sidebar .nav-links::-webkit-scrollbar {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
    }
    .sidebar.collapsed .nav-links li,
    html.sidebar-collapsed .sidebar .nav-links li {
        width: 100% !important;
        display: flex !important;
        justify-content: center !important;
    }
    .sidebar.collapsed .nav-group,
    html.sidebar-collapsed .sidebar .nav-group {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        width: 100% !important;
    }
    .sidebar.collapsed .nav-group-btn,
    html.sidebar-collapsed .sidebar .nav-group-btn {
        width: 48px !important;
        height: 48px !important;
        padding: 0 !important;
        justify-content: center !important;
        border-radius: var(--radius-md);
        margin: 0 auto !important;
        background: transparent;
    }
    .sidebar.collapsed .nav-group-btn .nav-group-icon,
    html.sidebar-collapsed .sidebar .nav-group-btn .nav-group-icon {
        margin: 0 !important;
        font-size: 18px !important;
        width: auto !important;
        text-align: center !important;
    }
    .sidebar.collapsed .nav-group-title,
    html.sidebar-collapsed .sidebar .nav-group-title,
    .sidebar.collapsed .nav-group-arrow,
    html.sidebar-collapsed .sidebar .nav-group-arrow,
    .sidebar.collapsed .nav-sub-wrap,
    html.sidebar-collapsed .sidebar .nav-sub-wrap {
        display: none !important;
    }
    .sidebar.collapsed .nav-group.has-active .nav-group-btn,
    html.sidebar-collapsed .sidebar .nav-group.has-active .nav-group-btn {
        background-color: var(--primary-light) !important;
        color: var(--primary) !important;
    }
    .sidebar.collapsed .nav-link,
    html.sidebar-collapsed .sidebar .nav-link {
        width: 48px !important;
        height: 48px !important;
        padding: 0 !important;
        justify-content: center !important;
        margin: 0 auto !important;
    }
    .sidebar.collapsed .nav-link i,
    html.sidebar-collapsed .sidebar .nav-link i {
        margin: 0 !important;
        font-size: 18px !important;
        width: auto !important;
        text-align: center !important;
    }
    .sidebar.collapsed .nav-link span,
    html.sidebar-collapsed .sidebar .nav-link span {
        display: none !important;
    }
    .sidebar.collapsed .user-profile-bar,
    html.sidebar-collapsed .sidebar .user-profile-bar {
        padding: 16px 0 !important;
        justify-content: center !important;
    }
    .sidebar.collapsed .user-avatar-wrap,
    html.sidebar-collapsed .sidebar .user-avatar-wrap {
        justify-content: center !important;
    }
    .sidebar.collapsed .user-avatar-wrap > div:last-child,
    html.sidebar-collapsed .sidebar .user-avatar-wrap > div:last-child {
        display: none !important;
    }
    @media (max-width: 1024px) {
        .sidebar-toggle-btn {
            display: none !important;
        }
        .mobile-menu-btn {
            display: inline-flex !important;
        }
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            bottom: 0 !important;
            width: 270px !important;
            height: 100vh !important;
            z-index: 90 !important;
            transform: translateX(-100%);
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            background-color: var(--bg-surface, #ffffff);
            border-right: 1px solid var(--border, #e2e8f0);
        }
        .sidebar.mobile-open {
            transform: translateX(0) !important;
            box-shadow: 0 0 35px rgba(0, 0, 0, 0.45) !important;
        }
        .sidebar.mobile-open .brand-text-wrap,
        .sidebar.mobile-open .nav-link span,
        .sidebar.mobile-open .nav-group-title,
        .sidebar.mobile-open .nav-group-arrow,
        .sidebar.mobile-open .user-avatar-wrap > div:last-child {
            display: block !important;
        }
        .sidebar.mobile-open .nav-group {
            display: flex !important;
            width: 100% !important;
        }
        .sidebar.mobile-open .nav-group-btn {
            width: 100% !important;
            height: auto !important;
            padding: 11px 16px !important;
            justify-content: flex-start !important;
            display: flex !important;
        }
        .sidebar.mobile-open .nav-group-arrow {
            display: inline-block !important;
            margin-left: auto !important;
        }
        .sidebar.mobile-open .nav-sub-wrap {
            display: grid !important;
        }
        .sidebar.mobile-open .nav-link {
            width: 100% !important;
            height: auto !important;
            padding: 12px 16px !important;
            justify-content: flex-start !important;
        }
        .sidebar.mobile-open .nav-links {
            padding: 16px 12px !important;
            align-items: stretch !important;
        }
        .sidebar.mobile-open .brand {
            height: 70px !important;
            min-height: 70px !important;
            max-height: 70px !important;
            padding: 0 16px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
        }
        .sidebar.mobile-open .user-profile-bar {
            padding: 16px 20px !important;
            display: flex !important;
        }
        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            z-index: 85;
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .sidebar-backdrop.active {
            display: block !important;
            opacity: 1 !important;
        }
    }
    </style>
</head>
<body>
<div class="app-layout">
    <?php if ($currentUser): ?>
        <?php include __DIR__ . '/nav.php'; ?>
    <?php endif; ?>
    
    <div class="main-wrapper">
        <?php if ($currentUser): ?>
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <button type="button" class="btn btn-secondary btn-sm mobile-menu-btn" id="mobileMenuBtn" aria-label="Open navigation menu" title="Open navigation menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-title">
                    <?= htmlspecialchars($pageTitle ?? __('app_name')) ?>
                </div>
            </div>
            <div class="topbar-actions">
                <!-- Staff Timeclock Button -->
                <button type="button" class="btn btn-sm <?= $activeTimecard ? 'btn-success' : 'btn-secondary' ?>" id="timeclockBtn" onclick="toggleTimeclockModal()" title="<?= $activeTimecard ? 'Clocked in since ' . date('h:i A', strtotime($activeTimecard['clock_in'])) : 'Click to Clock In / Out' ?>" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                    <i class="fa-solid fa-clock" id="timeclockIcon" style="<?= $activeTimecard ? 'color: #ffffff;' : 'color: var(--text-muted);' ?>"></i>
                    <span id="topbarClockTimer"><?= $activeTimecard ? '--:--:--' : ($currentLang === 'km' ? 'ចុះវត្តមាន' : 'Clock In') ?></span>
                </button>

                <!-- Notification Sound Toggle -->
                <button type="button" class="btn btn-secondary btn-sm" id="soundToggleBtn" onclick="toggleNotificationSound()" title="<?= $soundMuted ? 'Unmute notification sounds' : 'Mute notification sounds' ?>" aria-label="Toggle notification sounds">
                    <i class="fa-solid <?= $soundMuted ? 'fa-volume-xmark text-danger' : 'fa-volume-high' ?>" id="soundToggleIcon"></i>
                </button>

                <!-- Notification Bell -->
                <div class="notification-dropdown-wrap">
                    <button type="button" class="btn btn-secondary btn-sm notif-bell-btn" id="notifBellBtn" onclick="toggleNotifDropdown(event)" title="<?= $currentLang === 'km' ? 'ការជូនដំណឹង' : 'Notifications' ?>" aria-label="Notifications" aria-expanded="false">
                        <i class="fa-solid fa-bell"></i>
                        <span class="notif-badge" id="notifBadge" style="<?= $unreadNotifCount > 0 ? '' : 'display: none;' ?>">
                            <?= $unreadNotifCount > 99 ? '99+' : $unreadNotifCount ?>
                        </span>
                    </button>
                    <div class="notification-panel" id="notifPanel" style="display: none;">
                        <div class="notif-panel-header">
                            <div style="font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-bell text-primary"></i>
                                <span><?= $currentLang === 'km' ? 'ការជូនដំណឹង' : 'Notifications' ?></span>
                            </div>
                            <div>
                                <button type="button" class="btn btn-link btn-xs" onclick="markAllNotifsRead(event)" style="font-size: 11px; padding: 2px 6px; text-decoration: none; cursor: pointer; color: var(--primary);">
                                    <?= $currentLang === 'km' ? 'សម្គាល់ទាំងអស់ថាបានអាន' : 'Mark all read' ?>
                                </button>
                            </div>
                        </div>
                        <div class="notif-panel-body" id="notifPanelList">
                            <?php if (empty($initialNotifs)): ?>
                                <div class="notif-empty">
                                    <i class="fa-regular fa-bell-slash" style="font-size: 28px; color: var(--text-light); margin-bottom: 8px; display: block;"></i>
                                    <?= $currentLang === 'km' ? 'គ្មានការជូនដំណឹងថ្មីទេ' : 'No notifications' ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($initialNotifs as $n): ?>
                                    <div class="notif-item <?= (int)$n['is_read'] === 0 ? 'unread' : '' ?>" onclick="handleNotifClick(event, <?= (int)$n['id'] ?>, '<?= htmlspecialchars($n['link_url'] ?? '', ENT_QUOTES) ?>')">
                                        <div class="notif-item-icon">
                                            <?php
                                            $cat = $n['category'] ?? '';
                                            if ($cat === 'transfer_approval') echo '<i class="fa-solid fa-building-columns text-primary"></i>';
                                            elseif ($cat === 'parts_request') echo '<i class="fa-solid fa-wrench text-warning"></i>';
                                            elseif ($cat === 'parts_approved') echo '<i class="fa-solid fa-circle-check text-success"></i>';
                                            elseif ($cat === 'parts_rejected') echo '<i class="fa-solid fa-circle-xmark text-danger"></i>';
                                            elseif ($cat === 'long_wait') echo '<i class="fa-solid fa-stopwatch text-danger"></i>';
                                            elseif ($cat === 'bay_idle') echo '<i class="fa-solid fa-triangle-exclamation text-warning"></i>';
                                            elseif ($cat === 'cash_variance') echo '<i class="fa-solid fa-sack-xmark text-danger"></i>';
                                            elseif ($cat === 'new_car') echo '<i class="fa-solid fa-car text-info"></i>';
                                            else echo '<i class="fa-solid fa-bell text-primary"></i>';
                                            ?>
                                        </div>
                                        <div class="notif-item-content">
                                            <div class="notif-item-title"><?= htmlspecialchars($currentLang === 'km' && !empty($n['title_km']) ? $n['title_km'] : $n['title_en']) ?></div>
                                            <div class="notif-item-msg"><?= htmlspecialchars($currentLang === 'km' && !empty($n['message_km']) ? $n['message_km'] : $n['message_en']) ?></div>
                                            <div class="notif-item-time"><?= date('H:i, d M', strtotime($n['created_at'])) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="notif-panel-footer">
                            <span style="display: inline-flex; align-items: center; gap: 6px;">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background-color: var(--success); display: inline-block;"></span>
                                <?= $currentLang === 'km' ? 'ប្រព័ន្ធធ្វើបច្ចុប្បន្នភាពផ្ទាល់' : 'Real-time live sync' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Language Toggle -->
                <?php if ($currentLang === 'en'): ?>
                    <a href="?lang=km&<?= http_build_query(array_diff_key($_GET, ['lang' => ''])) ?>" class="btn btn-secondary btn-sm" title="ប្តូរទៅជាភាសាខ្មែរ">
                        <i class="fa-solid fa-globe mr-1"></i> ភាសាខ្មែរ
                    </a>
                <?php else: ?>
                    <a href="?lang=en&<?= http_build_query(array_diff_key($_GET, ['lang' => ''])) ?>" class="btn btn-secondary btn-sm" title="Switch to English">
                        <i class="fa-solid fa-globe mr-1"></i> English
                    </a>
                <?php endif; ?>

                <!-- Dark/Light Theme Button -->
                <button type="button" class="btn btn-secondary btn-sm" id="themeToggleBtn" title="<?= htmlspecialchars(__('theme_toggle')) ?>">
                    <i class="fa-solid fa-moon"></i>
                </button>

                <!-- Logout -->
                <a href="<?= APP_URL ?>/index.php?action=logout" class="btn btn-danger btn-sm" title="<?= htmlspecialchars(__('logout')) ?>">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                </a>
            </div>

            <!-- Timeclock Modal Dialog -->
            <div id="timeclockModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); align-items: center; justify-content: center;">
                <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); width: 100%; max-width: 440px; box-shadow: var(--shadow-lg); overflow: hidden; margin: 16px;">
                    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg-subtle);">
                        <div style="font-weight: 700; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-clock text-primary"></i>
                            <span><?= $currentLang === 'km' ? 'វត្តមានបុគ្គលិក (Timeclock)' : 'Staff Shift Timeclock' ?></span>
                        </div>
                        <button type="button" onclick="closeTimeclockModal()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted);">&times;</button>
                    </div>
                    <div style="padding: 20px;">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding: 12px; background: var(--bg-subtle); border-radius: var(--radius-md);">
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px;">
                                <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 2)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 700; color: var(--text-main); font-size: 15px;"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></div>
                                <div class="text-xs text-muted" style="text-transform: capitalize;"><?= htmlspecialchars($currentUser['role']) ?></div>
                            </div>
                        </div>

                        <!-- Status Display -->
                        <div id="tcStatusActive" style="<?= $activeTimecard ? '' : 'display: none;' ?>">
                            <div style="text-align: center; padding: 12px 0;">
                                <div class="badge badge-success" style="font-size: 12px; padding: 4px 12px; margin-bottom: 8px;">
                                    <i class="fa-solid fa-circle-check mr-1"></i> <?= $currentLang === 'km' ? 'កំពុងស្ថិតក្នុងវេនការងារ' : 'Currently On Shift' ?>
                                </div>
                                <div class="text-xs text-muted"><?= $currentLang === 'km' ? 'ម៉ោងចូលចាប់ផ្តើម' : 'Clocked in at' ?>: <strong id="tcClockInTime"><?= $activeTimecard ? date('h:i:s A, d M', strtotime($activeTimecard['clock_in'])) : '' ?></strong></div>
                                <div id="modalLiveElapsed" style="font-size: 32px; font-weight: 800; font-family: var(--font-mono); color: var(--primary); margin: 12px 0;">
                                    00:00:00
                                </div>
                                <div class="text-xs text-muted"><?= $currentLang === 'km' ? 'ម៉ោងសរុបគិតជាទសភាគ' : 'Labor Hours' ?>: <span id="modalLiveHours" style="font-weight: 700; color: var(--text-main);">0.00</span> hrs</div>
                            </div>
                        </div>

                        <div id="tcStatusInactive" style="<?= $activeTimecard ? 'display: none;' : '' ?>">
                            <div style="text-align: center; padding: 16px 0;">
                                <div class="badge badge-secondary" style="font-size: 12px; padding: 4px 12px; margin-bottom: 8px;">
                                    <i class="fa-solid fa-moon mr-1"></i> <?= $currentLang === 'km' ? 'មិនទាន់ចុះវត្តមានចូលទេ' : 'Not Clocked In' ?>
                                </div>
                                <p class="text-xs text-muted" style="margin-top: 6px;">
                                    <?= $currentLang === 'km' ? 'សូមចុះវត្តមានចូលនៅពេលចាប់ផ្តើមវេនការងារ ដើម្បីវាស់ស្ទង់ផលិតភាព និងម៉ោងការងារពិតប្រាកដ។' : 'Clock in at the start of your shift to track labor hours and productivity.' ?>
                                </p>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div style="margin-top: 10px;">
                            <label class="form-label" style="font-size: 12px;"><?= $currentLang === 'km' ? 'កំណត់សម្គាល់ (ជម្រើស)' : 'Shift Note / Activity (Optional)' ?></label>
                            <input type="text" id="tcNotesInput" class="form-control form-control-sm" placeholder="e.g. Morning wash shift, bay assignment...">
                        </div>

                        <!-- Action Buttons -->
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="button" class="btn btn-secondary" onclick="closeTimeclockModal()" style="flex: 1;">
                                <?= $currentLang === 'km' ? 'បោះបង់' : 'Cancel' ?>
                            </button>
                            <button type="button" id="tcActionBtn" class="btn <?= $activeTimecard ? 'btn-danger' : 'btn-success' ?>" onclick="submitTimeclockAction()" style="flex: 2; font-weight: 700;">
                                <i class="fa-solid <?= $activeTimecard ? 'fa-arrow-right-from-bracket' : 'fa-arrow-right-to-bracket' ?> mr-1" id="tcActionIcon"></i>
                                <span id="tcActionBtnText"><?= $activeTimecard ? ($currentLang === 'km' ? 'ចេញពីវេនការងារ (Clock Out)' : 'Clock Out (End Shift)') : ($currentLang === 'km' ? 'ចូលវេនការងារ (Clock In)' : 'Clock In (Start Shift)') ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                var activeTc = window.CARWASH_APP && window.CARWASH_APP.activeTimecard;
                var clockInTimestamp = activeTc ? new Date(activeTc.clock_in.replace(' ', 'T')).getTime() : null;

                function formatHMS(seconds) {
                    var h = Math.floor(seconds / 3600);
                    var m = Math.floor((seconds % 3600) / 60);
                    var s = seconds % 60;
                    return (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
                }

                function updateTimers() {
                    if (!clockInTimestamp) return;
                    var now = Date.now();
                    var elapsedSec = Math.max(0, Math.floor((now - clockInTimestamp) / 1000));
                    var hms = formatHMS(elapsedSec);
                    var hrs = (elapsedSec / 3600).toFixed(2);

                    var topEl = document.getElementById('topbarClockTimer');
                    if (topEl) topEl.textContent = hms;

                    var modEl = document.getElementById('modalLiveElapsed');
                    if (modEl) modEl.textContent = hms;

                    var hrsEl = document.getElementById('modalLiveHours');
                    if (hrsEl) hrsEl.textContent = hrs;
                }

                if (clockInTimestamp) {
                    updateTimers();
                    setInterval(updateTimers, 1000);
                }

                window.toggleTimeclockModal = function() {
                    var m = document.getElementById('timeclockModal');
                    if (m) m.style.display = (m.style.display === 'flex' ? 'none' : 'flex');
                };

                window.closeTimeclockModal = function() {
                    var m = document.getElementById('timeclockModal');
                    if (m) m.style.display = 'none';
                };

                window.submitTimeclockAction = function() {
                    var btn = document.getElementById('tcActionBtn');
                    var notes = document.getElementById('tcNotesInput') ? document.getElementById('tcNotesInput').value : '';
                    var isCurrentlyActive = !!clockInTimestamp;
                    var subAction = isCurrentlyActive ? 'clock_out' : 'clock_in';

                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Processing...';

                    var formData = new FormData();
                    formData.append('timeclock_action', subAction);
                    formData.append('notes', notes);
                    formData.append('csrf_token', window.CARWASH_APP.csrfToken);

                    fetch(window.CARWASH_APP.baseUrl + '/index.php?action=staff_timeclock_toggle', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert(res.message || 'Error occurred while updating timecard.');
                            window.location.reload();
                        }
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        alert('Network communication error: ' + err.message);
                    });
                };
            })();
            </script>
        </header>
        <?php endif; ?>
        <main class="page-container">
