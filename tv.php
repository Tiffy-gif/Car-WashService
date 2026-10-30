<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/core/BayManager.php';

$appTimezone = get_setting('app_timezone', 'Asia/Phnom_Penh');
if (!empty($appTimezone)) {
    @date_default_timezone_set($appTimezone);
}

$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

// 1. Safety & Access Verification
$tvEnabled = get_setting('tv_display_enabled', '1');
$tvSecret = get_setting('tv_display_code', 'tv-office-88');

$providedCode = (string)($_GET['code'] ?? $_GET['token'] ?? '');

$isAuthorized = ($tvEnabled === '1' && $tvSecret !== '' && hash_equals($tvSecret, $providedCode));

// 2. Feed Endpoint (Read-Only JSON API for TV polling)
if (($_GET['action'] ?? '') === 'feed') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    if (!$isAuthorized) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'NOT_AVAILABLE',
            'message' => 'Not available'
        ]);
        exit;
    }

    $data = BayManager::getTvDisplayData($pdo);
    echo json_encode($data);
    exit;
}

// 3. If Not Authorized, show a plain "Not available" screen
if (!$isAuthorized) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Not available</title>
        <style>
            body {
                background: #f8fafc;
                color: #475569;
                font-family: system-ui, -apple-system, sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
            }
            .box {
                text-align: center;
                padding: 40px;
                background: #ffffff;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
                max-width: 360px;
            }
            h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0; }
            p { font-size: 14px; margin: 0; color: #64748b; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>Not available</h1>
            <p>This queue display is currently disabled or the secret code is incorrect.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 4. Load initial data for first paint
$initialData = BayManager::getTvDisplayData($pdo);
$lang = $initialData['language'];

$shopNameEn = get_setting('shop_name_en', 'AquaGleam Car Wash & Detailing');
$shopNameKm = get_setting('shop_name_km', 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប');
$shopLogo = get_setting('shop_logo', '');

// Labels per configured language
$t = [
    'title' => match($lang) {
        'km' => $shopNameKm ?: $shopNameEn,
        'en' => $shopNameEn ?: $shopNameKm,
        default => ($shopNameEn ?: 'Car Wash') . ' • ' . ($shopNameKm ?: '')
    },
    'subtitle' => match($lang) {
        'km' => 'ផ្ទាំងព័ត៌មានជួររង់ចាំ និងកន្លែងផ្តល់សេវា',
        'en' => 'Live Customer Queue & Service Bays Display',
        default => 'Live Customer Queue & Service Bays Display • ផ្ទាំងព័ត៌មានជួររង់ចាំ'
    },
    'bays_header' => match($lang) {
        'km' => 'កន្លែងផ្តល់សេវាកម្ម',
        'en' => 'Service Bays',
        default => 'Service Bays / កន្លែងផ្តល់សេវា'
    },
    'waiting_header' => match($lang) {
        'km' => 'រថយន្តក្នុងជួររង់ចាំ',
        'en' => 'Waiting in Line',
        default => 'Waiting in Line / រថយន្តក្នុងជួររង់ចាំ'
    },
    'ready_header' => match($lang) {
        'km' => 'រួចរាល់សម្រាប់ទទួល - Ready for Pickup',
        'en' => 'Ready for Pickup',
        default => 'Ready for Pickup / រួចរាល់សម្រាប់ទទួល'
    },
    'ready_sub' => match($lang) {
        'km' => 'រថយន្តបានរួចរាល់ សូមអញ្ជើញមកទទួល និងទូទាត់ប្រាក់',
        'en' => 'Vehicles ready for collection. Please proceed to cashier counter',
        default => 'Ready for pickup & payment / រួចរាល់សម្រាប់ទទួល'
    },
    'next' => match($lang) {
        'km' => 'បន្ទាប់',
        'en' => 'NEXT',
        default => 'NEXT / បន្ទាប់'
    },
    'more_waiting' => match($lang) {
        'km' => '+ %d គ្រឿងទៀតក្នុងជួរ',
        'en' => '+ %d more waiting',
        default => '+ %d more waiting / គ្រឿងទៀតក្នុងជួរ'
    },
    'no_waiting' => match($lang) {
        'km' => 'គ្មានរថយន្តកំពុងរង់ចាំ',
        'en' => 'No vehicles waiting in line',
        default => 'No vehicles waiting in line / គ្មានរថយន្តរង់ចាំ'
    },
    'no_ready' => match($lang) {
        'km' => 'មិនទាន់មានរថយន្តរួចរាល់',
        'en' => 'No finished vehicles awaiting payment',
        default => 'No vehicles awaiting payment / មិនទាន់មានរថយន្តរួចរាល់'
    },
    'reconnecting' => match($lang) {
        'km' => 'កំពុងភ្ជាប់ឡើងវិញ...',
        'en' => 'Reconnecting...',
        default => 'Reconnecting... / កំពុងភ្ជាប់ឡើងវិញ...'
    }
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang === 'km' ? 'km' : 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($t['title']) ?> - Customer Queue Display</title>
    
    <!-- Local Offline Font Awesome -->
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">

    <style>
        :root {
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --bg-subtle: #f1f5f9;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            --primary: #0284c7;
            --primary-light: #e0f2fe;
            --success: #16a34a;
            --success-bg: #dcfce7;
            --warning: #d97706;
            --warning-bg: #fef3c7;
            --font-family: 'Kantumruy Pro', 'Inter', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-main);
            font-family: var(--font-family);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* TV Header Bar */
        .tv-header {
            background: #ffffff;
            border-bottom: 2px solid var(--border);
            padding: 18px 36px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            flex-shrink: 0;
        }

        .shop-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .shop-logo {
            width: 58px;
            height: 58px;
            object-fit: contain;
            border-radius: 10px;
            background: #ffffff;
        }

        .shop-logo-fallback {
            width: 58px;
            height: 58px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .shop-name {
            font-size: 26px;
            font-weight: 900;
            color: var(--text-main);
            letter-spacing: -0.5px;
            line-height: 1.15;
        }

        .shop-subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 3px;
            font-weight: 600;
        }

        .tv-clock {
            text-align: right;
        }

        .clock-time {
            font-size: 30px;
            font-weight: 900;
            font-family: var(--font-mono);
            color: var(--text-main);
            letter-spacing: 0.5px;
            line-height: 1;
        }

        .clock-date {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 700;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* TV Main Layout */
        .tv-body {
            flex: 1;
            padding: 24px 36px 36px 36px;
            display: flex;
            flex-direction: column;
            gap: 28px;
            max-width: 2200px;
            width: 100%;
            margin: 0 auto;
        }

        /* SECTION 1: BAYS ROW */
        .bays-section-title {
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 800;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bays-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .bay-card {
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 18px;
            padding: 24px 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            transition: all 0.25s ease;
            position: relative;
            min-height: 190px;
        }

        .bay-card.bay-free {
            border-top: 8px solid var(--success);
            background: linear-gradient(180deg, #ffffff 0%, #fafffc 100%);
        }

        .bay-card.bay-busy {
            border-top: 8px solid var(--warning);
            background: linear-gradient(180deg, #ffffff 0%, #fffdfa 100%);
        }

        .bay-card.bay-oil {
            border-top: 8px solid #ea580c;
        }

        .bay-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .bay-name {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bay-type-icon {
            color: var(--primary);
            font-size: 18px;
        }
        .bay-card.bay-oil .bay-type-icon {
            color: #ea580c;
        }

        .status-pill {
            font-size: 13px;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pill-free,
        .pill-waiting {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .pill-busy,
        .pill-in_progress {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid rgba(2, 132, 199, 0.4);
        }

        .pill-detailing {
            background: #f3e8ff;
            color: #7e22ce;
            border: 1px solid rgba(126, 34, 206, 0.4);
        }

        .pill-ready {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid rgba(22, 163, 74, 0.4);
            animation: pulse-border 2s infinite;
        }

        .pill-oil {
            background: #ffedd5;
            color: #c2410c;
            border: 1px solid rgba(234, 88, 12, 0.3);
        }

        @keyframes pulse-border {
            0%, 100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.4); }
            50% { box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.15); }
        }

        .bay-content {
            margin: auto 0;
            text-align: center;
            padding: 8px 0;
        }

        .bay-plate {
            font-family: var(--font-mono);
            font-size: 32px;
            font-weight: 900;
            color: var(--text-main);
            letter-spacing: 1px;
            background: var(--bg-subtle);
            border: 2px solid var(--border);
            padding: 6px 16px;
            border-radius: 12px;
            display: inline-block;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .bay-free-label {
            font-size: 32px;
            font-weight: 900;
            color: var(--success);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .bay-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 700;
            border-top: 1px solid var(--border);
            padding-top: 10px;
            margin-top: 8px;
        }

        .tv-delay-banner {
            margin-top: 10px;
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 13px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            animation: pulse-glow 2s infinite;
        }

        /* SECTION 2: QUEUE & READY SPLIT */
        .bottom-split {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 28px;
            flex: 1;
        }

        .panel-card {
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 20px;
            padding: 24px 28px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            margin-bottom: 20px;
        }

        .panel-title {
            font-size: 22px;
            font-weight: 900;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-badge {
            font-size: 14px;
            font-weight: 800;
            padding: 6px 16px;
            border-radius: 9999px;
            background: var(--primary-light);
            color: var(--primary);
        }

        .ready-badge {
            background: var(--success-bg);
            color: var(--success);
        }

        /* WAITING GRID (up to 8 cars) */
        .waiting-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            align-content: start;
            flex: 1;
        }

        .waiting-item {
            background: var(--bg-subtle);
            border: 2px solid var(--border);
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: transform 0.2s;
        }

        .waiting-item.is-next {
            background: #e0f2fe;
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.4);
            grid-column: span 2;
            padding: 18px 24px;
        }

        .pos-badge {
            font-size: 20px;
            font-weight: 900;
            color: var(--text-muted);
            width: 36px;
            text-align: center;
            font-family: var(--font-mono);
        }

        .is-next .pos-badge {
            color: var(--primary);
            font-size: 24px;
        }

        .waiting-plate {
            font-family: var(--font-mono);
            font-size: 26px;
            font-weight: 900;
            color: var(--text-main);
            letter-spacing: 0.5px;
        }

        .is-next .waiting-plate {
            font-size: 34px;
            color: #0369a1;
        }

        .next-tag {
            background: #0284c7;
            color: #ffffff;
            font-size: 13px;
            font-weight: 900;
            padding: 6px 14px;
            border-radius: 9999px;
            letter-spacing: 1px;
            text-transform: uppercase;
            animation: pulse-glow 2s infinite;
        }

        @keyframes pulse-glow {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.85; transform: scale(1.04); }
        }

        .more-banner {
            grid-column: span 2;
            background: #ffffff;
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            font-size: 16px;
            font-weight: 800;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* READY FOR PAYMENT CARS */
        .ready-panel {
            background: #ffffff;
            border-top: 8px solid var(--success);
        }

        .ready-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }

        .ready-item {
            background: #f0fdf4;
            border: 2px solid #bbf7d0;
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .ready-plate {
            font-family: var(--font-mono);
            font-size: 28px;
            font-weight: 900;
            color: #15803d;
            letter-spacing: 1px;
        }

        .ready-tag {
            background: var(--success);
            color: #ffffff;
            font-size: 13px;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ready-instruction {
            margin-top: 16px;
            background: var(--bg-subtle);
            border-radius: 12px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
        }

        .ready-instruction i {
            font-size: 20px;
            color: var(--success);
        }

        .empty-placeholder {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-light);
            font-size: 16px;
            font-weight: 700;
            margin: auto;
        }

        /* Floating Connection / TV Bar */
        .floating-controls {
            position: fixed;
            bottom: 16px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1000;
        }

        .reconnecting-pill {
            display: none;
            background: #ef4444;
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 800;
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
            animation: pulse-glow 1.5s infinite;
        }

        .fullscreen-btn {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid var(--border);
            color: var(--text-muted);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 14px;
            cursor: pointer;
            backdrop-filter: blur(4px);
            transition: all 0.2s;
        }
        .fullscreen-btn:hover {
            background: #ffffff;
            color: var(--text-main);
        }

        /* RESPONSIVENESS for Laptops and standard screens */
        @media (max-width: 1200px) {
            .bays-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .bottom-split {
                grid-template-columns: 1fr;
            }
            .bay-plate {
                font-size: 26px;
            }
            .waiting-grid {
                grid-template-columns: 1fr;
            }
            .waiting-item.is-next {
                grid-column: span 1;
            }
            .more-banner {
                grid-column: span 1;
            }
        }

        @media (max-width: 768px) {
            .tv-header {
                padding: 12px 18px;
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .tv-clock {
                text-align: center;
            }
            .tv-body {
                padding: 16px;
            }
            .bays-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <!-- 1. Header Bar -->
    <header class="tv-header">
        <div class="shop-brand">
            <?php if (!empty($shopLogo) && file_exists(__DIR__ . '/' . ltrim($shopLogo, '/'))): ?>
                <img src="<?= APP_URL ?>/<?= htmlspecialchars(ltrim($shopLogo, '/')) ?>" alt="Logo" class="shop-logo">
            <?php else: ?>
                <div class="shop-logo-fallback">
                    <i class="fa-solid fa-car-wash"></i>
                </div>
            <?php endif; ?>
            <div>
                <div class="shop-name"><?= htmlspecialchars($t['title']) ?></div>
                <div class="shop-subtitle"><?= htmlspecialchars($t['subtitle']) ?></div>
            </div>
        </div>

        <div class="tv-clock">
            <div class="clock-time" id="clockTime"><?= date('h:i:s A') ?></div>
            <div class="clock-date" id="clockDate"><?= date('l, d M Y') ?></div>
        </div>
    </header>

    <!-- 2. TV Main Body -->
    <main class="tv-body">

        <!-- Top: Service Bays Grid -->
        <section>
            <div class="bays-section-title">
                <i class="fa-solid fa-warehouse"></i>
                <span><?= htmlspecialchars($t['bays_header']) ?></span>
            </div>
            <div class="bays-grid" id="baysGrid">
                <!-- Populated via JavaScript / PHP fallback -->
                <?php foreach ($initialData['bays'] as $b): 
                    $badgeKey = $b['badge_key'] ?? ($b['is_busy'] ? 'in_progress' : 'waiting');
                    $isOil = ($b['bay_type'] === 'oil_change');
                    $cardClass = $isOil && $b['is_busy'] ? 'bay-oil' : 'bay-' . $badgeKey;
                    $pillClass = 'status-pill pill-' . $badgeKey;
                    $iconClass = $isOil ? 'fa-wrench' : ($badgeKey === 'detailing' ? 'fa-wand-magic-sparkles' : ($badgeKey === 'ready' ? 'fa-check-double' : ($b['is_busy'] ? 'fa-circle-dot' : 'fa-circle-check')));
                ?>
                    <div class="bay-card <?= $cardClass ?>">
                        <div class="bay-top">
                            <div class="bay-name">
                                <i class="fa-solid <?= $isOil ? 'fa-wrench' : 'fa-car-wash' ?> bay-type-icon"></i>
                                <span><?= htmlspecialchars($b['bay_name']) ?></span>
                            </div>
                            <span class="<?= $pillClass ?>">
                                <i class="fa-solid <?= $iconClass ?>"></i>
                                <?= htmlspecialchars($b['status_text']) ?>
                            </span>
                        </div>

                        <div class="bay-content">
                            <?php if ($b['is_busy'] && !empty($b['plate_number'])): ?>
                                <div class="bay-plate"><?= htmlspecialchars($b['plate_number']) ?></div>
                                <?php if (!empty($b['delay_reason'])): ?>
                                    <div class="tv-delay-banner">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        <span><?= htmlspecialchars($b['delay_reason']) ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="bay-free-label">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <span><?= htmlspecialchars($b['status_text']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="bay-footer">
                            <span>Bay #<?= $b['bay_number'] ?></span>
                            <?php if ($b['is_busy']): ?>
                                <span style="color: var(--primary); font-weight: 800;">
                                    <i class="fa-solid fa-clock mr-1"></i> <?= (int)($b['remaining_minutes'] ?? $b['duration_minutes'] ?? 0) ?>m left
                                </span>
                            <?php else: ?>
                                <span style="color: var(--success);"><i class="fa-solid fa-arrow-down mr-1"></i> Ready</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Bottom: Waiting Queue (Left) & Ready for Payment (Right) -->
        <section class="bottom-split">

            <!-- LEFT: WAITING IN LINE (FIFO) -->
            <div class="panel-card">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fa-solid fa-list-ol" style="color: var(--primary);"></i>
                        <span><?= htmlspecialchars($t['waiting_header']) ?></span>
                    </div>
                    <span class="panel-badge" id="waitingBadge">
                        <?= $initialData['waiting_total'] ?> in queue
                    </span>
                </div>

                <div class="waiting-grid" id="waitingGrid">
                    <?php if (empty($initialData['waiting_list'])): ?>
                        <div class="empty-placeholder" style="grid-column: span 2;">
                            <i class="fa-solid fa-square-check" style="font-size: 36px; display: block; margin-bottom: 8px; opacity: 0.3;"></i>
                            <?= htmlspecialchars($t['no_waiting']) ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($initialData['waiting_list'] as $item): ?>
                            <div class="waiting-item <?= $item['is_next'] ? 'is-next' : '' ?>">
                                <div style="display: flex; align-items: center; gap: 14px;">
                                    <div class="pos-badge"><?= $item['position'] ?></div>
                                    <div class="waiting-plate"><?= htmlspecialchars($item['plate_number']) ?></div>
                                </div>
                                <?php if ($item['is_next']): ?>
                                    <span class="next-tag"><i class="fa-solid fa-arrow-right mr-1"></i> <?= htmlspecialchars($t['next']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($initialData['waiting_more_count'] > 0): ?>
                            <div class="more-banner">
                                <?= sprintf(htmlspecialchars($t['more_waiting']), $initialData['waiting_more_count']) ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: READY FOR PAYMENT -->
            <div class="panel-card ready-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fa-solid fa-circle-check" style="color: var(--success);"></i>
                        <span><?= htmlspecialchars($t['ready_header']) ?></span>
                    </div>
                    <span class="panel-badge ready-badge" id="readyBadge">
                        <?= $initialData['ready_total'] ?> Ready
                    </span>
                </div>

                <div class="ready-list" id="readyList">
                    <?php if (empty($initialData['ready_cars'])): ?>
                        <div class="empty-placeholder">
                            <i class="fa-solid fa-receipt" style="font-size: 36px; display: block; margin-bottom: 8px; opacity: 0.3;"></i>
                            <?= htmlspecialchars($t['no_ready']) ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($initialData['ready_cars'] as $rc): ?>
                            <div class="ready-item">
                                <div class="ready-plate"><?= htmlspecialchars($rc['plate_number']) ?></div>
                                <span class="ready-tag">
                                    <i class="fa-solid fa-check-double"></i> Ready for Pickup / រួចរាល់សម្រាប់ទទួល
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="ready-instruction">
                    <i class="fa-solid fa-cash-register"></i>
                    <div><?= htmlspecialchars($t['ready_sub']) ?></div>
                </div>
            </div>

        </section>

    </main>

    <!-- Floating UI: Reconnecting indicator & Fullscreen Toggle -->
    <div class="floating-controls">
        <div class="reconnecting-pill" id="reconnectingPill">
            <i class="fa-solid fa-rotate fa-spin mr-1"></i> <?= htmlspecialchars($t['reconnecting']) ?>
        </div>
        <button type="button" class="fullscreen-btn" onclick="toggleFullScreen()" title="Toggle Fullscreen">
            <i class="fa-solid fa-expand"></i>
        </button>
    </div>

    <!-- Client-Side Auto-Update & Polling Engine -->
    <script>
        const SECRET_CODE = <?= json_encode($providedCode) ?>;
        const FEED_URL = 'tv.php?action=feed&code=' + encodeURIComponent(SECRET_CODE);
        const STREAM_URL = 'api/queue_stream.php?code=' + encodeURIComponent(SECRET_CODE);
        const POLL_INTERVAL = 1000; // 1 second live updates fallback

        const translations = <?= json_encode($t) ?>;

        // 1. Live Clock ticking every second
        function updateClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const dateStr = now.toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'short', day: 'numeric' });
            
            const clockTimeEl = document.getElementById('clockTime');
            const clockDateEl = document.getElementById('clockDate');
            if (clockTimeEl) clockTimeEl.textContent = timeStr;
            if (clockDateEl) clockDateEl.textContent = dateStr;
        }
        setInterval(updateClock, 1000);

        // 2. Fullscreen Toggle
        function toggleFullScreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }

        // 3. Safe HTML Escaping for Privacy Output
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // 4. Server-Sent Events (SSE) with Automatic 3-Error Polling Fallback
        let consecutiveFailures = 0;
        let eventSource = null;
        let sseErrorCount = 0;
        let sseFallbackActive = false;
        let pollIntervalId = null;
        let lastPayloadHash = '';

        function startPollingFallback() {
            if (sseFallbackActive) return;
            sseFallbackActive = true;
            console.warn('EventSource failed 3 times in a row. Switching to automatic HTTP polling fallback.');
            if (eventSource) {
                try { eventSource.close(); } catch (e) {}
                eventSource = null;
            }
            if (!pollIntervalId) {
                pollIntervalId = setInterval(pollQueueData, POLL_INTERVAL);
                pollQueueData();
            }
        }

        function initEventSource() {
            if (!window.EventSource) {
                startPollingFallback();
                return;
            }

            try {
                const url = STREAM_URL + (lastPayloadHash ? '&last_hash=' + encodeURIComponent(lastPayloadHash) : '');
                eventSource = new EventSource(url);

                const handlePayload = function(e) {
                    try {
                        sseErrorCount = 0;
                        const reconnectingEl = document.getElementById('reconnectingPill');
                        if (reconnectingEl) reconnectingEl.style.display = 'none';
                        if (e.lastEventId) {
                            lastPayloadHash = e.lastEventId;
                        }
                        const data = JSON.parse(e.data);
                        if (data && data.success) {
                            renderTvDisplay(data);
                        }
                    } catch (err) {
                        console.error('Error handling SSE payload:', err);
                    }
                };

                eventSource.addEventListener('queue_update', handlePayload);
                eventSource.onmessage = handlePayload;

                eventSource.onerror = function(err) {
                    sseErrorCount++;
                    console.warn(`EventSource connection error (${sseErrorCount}/3)`);
                    const reconnectingEl = document.getElementById('reconnectingPill');
                    if (sseErrorCount >= 3) {
                        startPollingFallback();
                    } else if (reconnectingEl && sseErrorCount >= 2) {
                        reconnectingEl.style.display = 'inline-flex';
                    }
                };
            } catch (err) {
                startPollingFallback();
            }
        }

        async function pollQueueData() {
            const reconnectingEl = document.getElementById('reconnectingPill');

            try {
                const response = await fetch(FEED_URL, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const data = await response.json();
                if (!data || !data.success) {
                    throw new Error(data.message || 'Invalid payload');
                }

                // Success: hide reconnecting badge & reset failure count
                consecutiveFailures = 0;
                if (reconnectingEl) reconnectingEl.style.display = 'none';

                renderTvDisplay(data);

            } catch (err) {
                consecutiveFailures++;
                console.warn('TV Polling attempt failed:', err.message);

                // Show discreet reconnecting pill after 2 continuous failed attempts
                // Keep all current content strictly on screen!
                if (reconnectingEl && consecutiveFailures >= 2) {
                    reconnectingEl.style.display = 'inline-flex';
                }
            }
        }

        // 5. Render DOM smoothly
        function renderTvDisplay(data) {
            // A. Update Bays
            const baysGrid = document.getElementById('baysGrid');
            if (baysGrid && Array.isArray(data.bays)) {
                let html = '';
                data.bays.forEach(b => {
                    const badgeKey = b.badge_key || (b.is_busy ? 'in_progress' : 'waiting');
                    const isOil = (b.bay_type === 'oil_change');
                    const cardClass = isOil && b.is_busy ? 'bay-oil' : 'bay-' + badgeKey;
                    const pillClass = 'status-pill pill-' + badgeKey;
                    const iconClass = isOil ? 'fa-wrench' : (badgeKey === 'detailing' ? 'fa-wand-magic-sparkles' : (badgeKey === 'ready' ? 'fa-check-double' : (b.is_busy ? 'fa-circle-dot' : 'fa-circle-check')));

                    html += `
                        <div class="bay-card ${cardClass}">
                            <div class="bay-top">
                                <div class="bay-name">
                                    <i class="fa-solid ${isOil ? 'fa-wrench' : 'fa-car-wash'} bay-type-icon"></i>
                                    <span>${escapeHtml(b.bay_name)}</span>
                                </div>
                                <span class="${pillClass}">
                                    <i class="fa-solid ${iconClass}"></i>
                                    ${escapeHtml(b.status_text)}
                                </span>
                            </div>

                            <div class="bay-content">
                                ${b.is_busy && b.plate_number ? `
                                    <div class="bay-plate">${escapeHtml(b.plate_number)}</div>
                                    ${b.delay_reason ? `
                                        <div class="tv-delay-banner">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            <span>${escapeHtml(b.delay_reason)}</span>
                                        </div>
                                    ` : ''}
                                ` : `
                                    <div class="bay-free-label">
                                        <i class="fa-solid fa-circle-check"></i>
                                        <span>${escapeHtml(b.status_text)}</span>
                                    </div>
                                `}
                            </div>

                            <div class="bay-footer">
                                <span>Bay #${b.bay_number}</span>
                                ${b.is_busy ? `
                                    <span style="color: var(--primary); font-weight: 800;"><i class="fa-solid fa-clock mr-1"></i> ${b.remaining_minutes || b.duration_minutes || 0}m left</span>
                                ` : `
                                    <span style="color: var(--success);"><i class="fa-solid fa-arrow-down mr-1"></i> Ready</span>
                                `}
                            </div>
                        </div>
                    `;
                });
                baysGrid.innerHTML = html;
            }

            // B. Update Waiting List
            const waitingGrid = document.getElementById('waitingGrid');
            const waitingBadge = document.getElementById('waitingBadge');
            if (waitingBadge) {
                waitingBadge.textContent = (data.waiting_total || 0) + ' in queue';
            }

            if (waitingGrid) {
                if (!data.waiting_list || data.waiting_list.length === 0) {
                    waitingGrid.innerHTML = `
                        <div class="empty-placeholder" style="grid-column: span 2;">
                            <i class="fa-solid fa-square-check" style="font-size: 36px; display: block; margin-bottom: 8px; opacity: 0.3;"></i>
                            ${escapeHtml(translations.no_waiting)}
                        </div>
                    `;
                } else {
                    let wHtml = '';
                    data.waiting_list.forEach(item => {
                        wHtml += `
                            <div class="waiting-item ${item.is_next ? 'is-next' : ''}">
                                <div style="display: flex; align-items: center; gap: 14px;">
                                    <div class="pos-badge">${item.position}</div>
                                    <div class="waiting-plate">${escapeHtml(item.plate_number)}</div>
                                </div>
                                ${item.is_next ? `
                                    <span class="next-tag"><i class="fa-solid fa-arrow-right mr-1"></i> ${escapeHtml(translations.next)}</span>
                                ` : ''}
                            </div>
                        `;
                    });

                    if (data.waiting_more_count > 0) {
                        const moreText = translations.more_waiting.replace('%d', data.waiting_more_count);
                        wHtml += `
                            <div class="more-banner">
                                ${escapeHtml(moreText)}
                            </div>
                        `;
                    }
                    waitingGrid.innerHTML = wHtml;
                }
            }

            // C. Update Ready for Payment List
            const readyList = document.getElementById('readyList');
            const readyBadge = document.getElementById('readyBadge');
            if (readyBadge) {
                readyBadge.textContent = (data.ready_total || 0) + ' Ready';
            }

            if (readyList) {
                if (!data.ready_cars || data.ready_cars.length === 0) {
                    readyList.innerHTML = `
                        <div class="empty-placeholder">
                            <i class="fa-solid fa-receipt" style="font-size: 36px; display: block; margin-bottom: 8px; opacity: 0.3;"></i>
                            ${escapeHtml(translations.no_ready)}
                        </div>
                    `;
                } else {
                    let rHtml = '';
                    data.ready_cars.forEach(rc => {
                        rHtml += `
                            <div class="ready-item">
                                <div class="ready-plate">${escapeHtml(rc.plate_number)}</div>
                                <span class="ready-tag">
                                    <i class="fa-solid fa-check-double"></i> Ready for Pickup / រួចរាល់សម្រាប់ទទួល
                                </span>
                            </div>
                        `;
                    });
                    readyList.innerHTML = rHtml;
                }
            }
        }

        // Start Realtime SSE Connection (with fallback polling if 3 errors occur)
        initEventSource();
    </script>
</body>
</html>
