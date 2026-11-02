<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/Loyalty.php';
require_once dirname(__DIR__, 2) . '/core/BayManager.php';

// Strict access: Only Owner can access settings
Auth::requirePermission('settings.view');
$pageTitle = __('nav_settings', 'Shop Settings');
$pdo = Database::getInstance();

$message = null;
$messageType = 'success';
$activeTab = $_GET['tab'] ?? 'shop';

$validTabs = ['shop', 'receipt', 'money', 'payment', 'bays', 'oil', 'loyalty', 'performance', 'telegram', 'sync', 'system', 'history'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'shop';
}

$currentUser = Auth::user();
$userId = (int)($currentUser['id'] ?? 0);
$userName = Auth::getFullName() ?? Auth::getUsername() ?? 'Owner';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($csrfError)) {
    if (!Auth::can('settings.edit')) {
        $message = 'Permission denied: Cannot modify system settings.';
        $messageType = 'danger';
    } else {
    $action = $_POST['action'] ?? '';
    $postedTab = $_POST['tab'] ?? $activeTab;
    if (in_array($postedTab, $validTabs, true)) {
        $activeTab = $postedTab;
    }

    // Helper for file uploads with size and security sanitization
    $handleUpload = function (string $fileKey, string $prefix) use ($userId, $userName, $pdo): array {
        if (empty($_FILES[$fileKey]['tmp_name']) || !is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
            return ['path' => null, 'error' => null];
        }

        $fileSize = (int)($_FILES[$fileKey]['size'] ?? 0);
        $maxBytes = 2 * 1024 * 1024; // 2 MB limit
        if ($fileSize > $maxBytes) {
            $mb = number_format($fileSize / 1024 / 1024, 2);
            return [
                'path' => null, 
                'error' => "The uploaded file is too large ({$mb} MB). Maximum allowed size is 2.0 MB to maintain fast system speed."
            ];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES[$fileKey]['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        if (!in_array($mime, $allowedMimes, true)) {
            return [
                'path' => null,
                'error' => "Unsupported file type ({$mime}). Only real PNG, JPG, WebP, and clean SVG image files are allowed."
            ];
        }

        $targetDir = dirname(__DIR__, 2) . '/public/uploads';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // 1. If SVG: sanitize XML content thoroughly
        if ($mime === 'image/svg+xml') {
            $content = (string)file_get_contents($_FILES[$fileKey]['tmp_name']);
            
            // Check for XML Entity or DOCTYPE attacks
            if (stripos($content, '<!ENTITY') !== false || stripos($content, '<!DOCTYPE') !== false) {
                return [
                    'path' => null,
                    'error' => "The uploaded SVG contains disallowed XML entity declarations or doctype references."
                ];
            }

            // Must contain an <svg tag
            if (!preg_match('/<svg\b[^>]*>/i', $content)) {
                return [
                    'path' => null,
                    'error' => "The uploaded file is not a valid SVG vector graphic."
                ];
            }

            // Remove any <script> tags and contents
            $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
            // Remove <foreignObject>
            $clean = preg_replace('/<foreignObject\b[^>]*>(.*?)<\/foreignObject>/is', '', $clean);
            // Remove on* event handlers (e.g. onload, onerror, onclick)
            $clean = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/i', '', $clean);
            $clean = preg_replace('/\s+on[a-z]+\s*=\s*[^ >]+/i', '', $clean);
            // Remove javascript: pseudo-protocols
            $clean = preg_replace('/(href|xlink:href)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '', $clean);

            $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.svg';
            $targetPath = $targetDir . '/' . $filename;
            if (file_put_contents($targetPath, $clean) !== false) {
                return ['path' => 'public/uploads/' . $filename, 'error' => null];
            } else {
                return ['path' => null, 'error' => "Could not save the sanitized SVG file to storage."];
            }
        }

        // 2. If raster image (PNG, JPG, WebP): verify with getimagesize
        $imgInfo = @getimagesize($_FILES[$fileKey]['tmp_name']);
        if ($imgInfo === false) {
            return [
                'path' => null,
                'error' => "The uploaded file failed image validation. It is not a genuine image."
            ];
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'png'
        };

        $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;
        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $targetPath)) {
            return ['path' => 'public/uploads/' . $filename, 'error' => null];
        }

        return ['path' => null, 'error' => "Failed to save uploaded image to storage."];
    };

    // 1. Save Shop Info
    if ($action === 'save_shop') {
        $km = trim($_POST['shop_name_km'] ?? '');
        $en = trim($_POST['shop_name_en'] ?? '');
        $addr = trim($_POST['shop_address'] ?? '');
        $phone = trim($_POST['shop_phone'] ?? '');

        $changed = 0;
        if (update_setting($pdo, 'shop_name_km', $km, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'shop_name_en', $en, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'shop_address', $addr, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'shop_phone', $phone, $userId, $userName)) $changed++;

        if (!empty($_POST['remove_logo'])) {
            if (update_setting($pdo, 'shop_logo', '', $userId, $userName)) $changed++;
        } else {
            $logoRes = $handleUpload('shop_logo_file', 'logo');
            if (!empty($logoRes['error'])) {
                $message = $logoRes['error'];
                $messageType = 'danger';
            } elseif (!empty($logoRes['path'])) {
                if (update_setting($pdo, 'shop_logo', $logoRes['path'], $userId, $userName)) $changed++;
            }
        }

        if ($messageType !== 'danger') {
            $message = $changed > 0 ? "Shop information updated successfully! ({$changed} items updated)" : "No changes made to shop information.";
            $messageType = $changed > 0 ? 'success' : 'info';
        }
    }

    // 2. Save Receipt Settings
    elseif ($action === 'save_receipt') {
        $footer = trim($_POST['receipt_footer'] ?? '');
        $showLogo = isset($_POST['receipt_show_logo']) && $_POST['receipt_show_logo'] === '1' ? '1' : '0';
        $lang = in_array($_POST['receipt_language'] ?? '', ['km', 'en', 'both'], true) ? $_POST['receipt_language'] : 'both';

        $changed = 0;
        if (update_setting($pdo, 'receipt_footer', $footer, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'receipt_show_logo', $showLogo, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'receipt_language', $lang, $userId, $userName)) $changed++;

        $message = $changed > 0 ? "Receipt configuration saved! ({$changed} items updated)" : "No changes made to receipt settings.";
        $messageType = $changed > 0 ? 'success' : 'info';
    }

    // 3. Save Money / Exchange Rate
    elseif ($action === 'save_money') {
        $rate = (int)($_POST['exchange_rate'] ?? 4100);
        if ($rate < 1000 || $rate > 20000) {
            $message = "Please enter a realistic exchange rate (between 1,000 and 20,000 KHR).";
            $messageType = 'danger';
        } else {
            $oldRate = get_exchange_rate();
            $changed = update_setting($pdo, 'exchange_rate', (string)$rate, $userId, $userName);
            if ($changed) {
                $message = "Exchange rate updated to 1 USD = " . number_format($rate) . " KHR! All new orders, POS pricing, receipts, and reports will now use this rate. Old orders keep their historical KHR totals.";
                $messageType = 'success';
            } else {
                $message = "Exchange rate remains unchanged at 1 USD = " . number_format($rate) . " KHR.";
                $messageType = 'info';
            }
        }
    }

    // 4. Save Payment (ABA & KHQR)
    elseif ($action === 'save_payment') {
        $abaName = trim($_POST['aba_account_name'] ?? '');
        $abaNum = trim($_POST['aba_account_num'] ?? '');

        $changed = 0;
        if (update_setting($pdo, 'aba_account_name', $abaName, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'aba_account_num', $abaNum, $userId, $userName)) $changed++;

        if (!empty($_POST['remove_khqr'])) {
            if (update_setting($pdo, 'khqr_image', '', $userId, $userName)) $changed++;
        } else {
            $khqrRes = $handleUpload('khqr_image_file', 'khqr');
            if (!empty($khqrRes['error'])) {
                $message = $khqrRes['error'];
                $messageType = 'danger';
            } elseif (!empty($khqrRes['path'])) {
                if (update_setting($pdo, 'khqr_image', $khqrRes['path'], $userId, $userName)) $changed++;
            }
        }

        if ($messageType !== 'danger') {
            $message = $changed > 0 ? "Payment details updated successfully! ({$changed} items updated)" : "No changes made to payment details.";
            $messageType = $changed > 0 ? 'success' : 'info';
        }
    }

    // 4b. Save / Update Bays (Rename, Skill Tag, or Switch On/Off) & TV Display Settings
    elseif ($action === 'save_bays') {
        $bayData = $_POST['bays'] ?? [];
        $updatedCount = 0;
        foreach ($bayData as $bayId => $bInfo) {
            $bayId = (int)$bayId;
            $newName = trim((string)($bInfo['name'] ?? ''));
            $skillTag = trim((string)($bInfo['skill_tag'] ?? 'wash_only'));
            $isActive = !empty($bInfo['is_active']);
            if ($bayId > 0 && $newName !== '') {
                $res = BayManager::updateBay($pdo, $bayId, $newName, $skillTag, $isActive, $userId, $userName, Auth::getRole() ?? 'owner');
                if (!empty($res['success'])) {
                    $updatedCount++;
                }
            }
        }

        // TV Display Settings
        $tvEnabled = isset($_POST['tv_display_enabled']) && $_POST['tv_display_enabled'] === '1' ? '1' : '0';
        $tvCode = trim((string)($_POST['tv_display_code'] ?? 'tv-office-88'));
        if ($tvCode === '') {
            $tvCode = 'tv-office-88';
        }
        $tvLang = in_array($_POST['tv_display_language'] ?? '', ['both', 'en', 'km'], true) ? $_POST['tv_display_language'] : 'both';

        update_setting($pdo, 'tv_display_enabled', $tvEnabled, $userId, $userName);
        update_setting($pdo, 'tv_display_code', $tvCode, $userId, $userName);
        update_setting($pdo, 'tv_display_language', $tvLang, $userId, $userName);

        // Smart Queue Engine Settings: Multipliers, Buffer, Delay Reasons
        $mulSedan = max(0.5, (float)($_POST['multiplier_sedan'] ?? 1.0));
        $mulSuv = max(0.5, (float)($_POST['multiplier_suv_pickup'] ?? 1.25));
        $mulTruck = max(0.5, (float)($_POST['multiplier_van_truck'] ?? 1.4));
        $mulMoto = max(0.2, (float)($_POST['multiplier_motorbike'] ?? 0.6));
        $bufferPercent = max(0, min(50, (int)($_POST['estimate_buffer_percent'] ?? 10)));

        update_setting($pdo, 'multiplier_sedan', (string)$mulSedan, $userId, $userName);
        update_setting($pdo, 'multiplier_small_car', (string)$mulSedan, $userId, $userName);
        update_setting($pdo, 'multiplier_suv_pickup', (string)$mulSuv, $userId, $userName);
        update_setting($pdo, 'multiplier_van_truck', (string)$mulTruck, $userId, $userName);
        update_setting($pdo, 'multiplier_motorbike', (string)$mulMoto, $userId, $userName);
        update_setting($pdo, 'estimate_buffer_percent', (string)$bufferPercent, $userId, $userName);

        if (isset($_POST['delay_reasons_text'])) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$_POST['delay_reasons_text']))));
            if (!empty($lines)) {
                update_setting($pdo, 'delay_reasons_list', json_encode($lines, JSON_UNESCAPED_UNICODE), $userId, $userName);
            }
        }

        $message = "Bays, TV queue display, and Smart Queue configuration saved successfully!";
        $messageType = 'success';
    }

    // 5. Save Oil Change Reminders
    elseif ($action === 'save_oil') {
        $dist = max(1000, (int)($_POST['default_oil_mileage'] ?? 10000));
        $months = max(1, (int)($_POST['default_oil_months'] ?? 3));

        $changed = 0;
        if (update_setting($pdo, 'default_oil_mileage', (string)$dist, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'default_oil_months', (string)$months, $userId, $userName)) $changed++;

        $message = $changed > 0 ? "Oil reminder defaults updated! New orders and POS will start with {$dist} KM and {$months} months." : "No changes made to oil reminders.";
        $messageType = $changed > 0 ? 'success' : 'info';
    }

    // 5b. Save Loyalty & Membership Settings
    elseif ($action === 'save_loyalty') {
        $earnRate = max(1, (int)($_POST['loyalty_earn_rate'] ?? 1));
        $redeemRate = max(1, (int)($_POST['loyalty_redeem_rate'] ?? 10));
        $goldMin = max(1, (int)($_POST['loyalty_tier_gold_min'] ?? 200));
        $platMin = max($goldMin + 1, (int)($_POST['loyalty_tier_platinum_min'] ?? 500));
        $stdMult = max(0.5, (float)($_POST['loyalty_multiplier_standard'] ?? 1.0));
        $goldMult = max(0.5, (float)($_POST['loyalty_multiplier_gold'] ?? 1.5));
        $platMult = max(0.5, (float)($_POST['loyalty_multiplier_platinum'] ?? 2.0));
        $goldWax = max(0, min(100, (int)($_POST['loyalty_wax_discount_gold'] ?? 10)));
        $platWax = max(0, min(100, (int)($_POST['loyalty_wax_discount_platinum'] ?? 15)));

        $changed = 0;
        if (update_setting($pdo, 'loyalty_earn_rate', (string)$earnRate, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_redeem_rate', (string)$redeemRate, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_tier_gold_min', (string)$goldMin, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_tier_platinum_min', (string)$platMin, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_multiplier_standard', (string)$stdMult, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_multiplier_gold', (string)$goldMult, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_multiplier_platinum', (string)$platMult, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_wax_discount_gold', (string)$goldWax, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'loyalty_wax_discount_platinum', (string)$platWax, $userId, $userName)) $changed++;

        // Refresh all customer tiers with new thresholds
        $pdo->prepare("UPDATE customers SET membership_level = 'platinum' WHERE lifetime_points >= ?")->execute([$platMin]);
        $pdo->prepare("UPDATE customers SET membership_level = 'gold' WHERE lifetime_points >= ? AND lifetime_points < ?")->execute([$goldMin, $platMin]);
        $pdo->prepare("UPDATE customers SET membership_level = 'standard' WHERE lifetime_points < ? OR lifetime_points IS NULL")->execute([$goldMin]);

        $message = $changed > 0 ? "Loyalty & Membership settings updated successfully! Customer tiers refreshed." : "No changes made to Loyalty settings.";
        $messageType = $changed > 0 ? 'success' : 'info';
    }

    // 6. Save Telegram
    elseif ($action === 'save_telegram') {
        $token = trim($_POST['telegram_bot_token'] ?? '');
        $chatId = trim($_POST['telegram_chat_id'] ?? '');

        $changed = 0;
        if (update_setting($pdo, 'telegram_bot_token', $token, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'telegram_chat_id', $chatId, $userId, $userName)) $changed++;

        if (!empty($_POST['send_test'])) {
            if (empty($token) || empty($chatId)) {
                $message = "Telegram settings saved, but cannot send test alert: Bot Token and Chat ID are required.";
                $messageType = 'warning';
            } else {
                // Attempt live test alert
                $testUrl = "https://api.telegram.org/bot" . urlencode($token) . "/sendMessage";
                $testPayload = [
                    'chat_id' => $chatId,
                    'text' => "[AquaGleam] Test Alert\nSettings connection verified successfully at " . date('d/m/Y H:i:s') . " by " . $userName . "!"
                ];
                $sentOk = false;
                $errDetail = '';
                try {
                    $ctx = stream_context_create([
                        'http' => [
                            'method' => 'POST',
                            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                            'content' => http_build_query($testPayload),
                            'timeout' => 5
                        ]
                    ]);
                    $resp = @file_get_contents($testUrl, false, $ctx);
                    if ($resp !== false) {
                        $json = json_decode($resp, true);
                        if (!empty($json['ok'])) {
                            $sentOk = true;
                        } else {
                            $errDetail = $json['description'] ?? 'Telegram API returned an error';
                        }
                    } else {
                        $errDetail = 'Network request to api.telegram.org failed or timed out';
                    }
                } catch (Throwable $t) {
                    $errDetail = $t->getMessage();
                }

                if ($sentOk) {
                    $message = "Telegram settings saved and LIVE test alert sent to Chat ID [{$chatId}] successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Telegram settings saved, but test message failed ({$errDetail}). Please verify your Bot Token and Chat ID.";
                    $messageType = 'warning';
                }
            }
        } else {
            $message = $changed > 0 ? "Telegram alert settings saved!" : "No changes made to Telegram settings.";
            $messageType = $changed > 0 ? 'success' : 'info';
        }
    }

    // 6b. Save Performance & Leaderboard Settings
    elseif ($action === 'save_performance') {
        $cphTarget = (float)($_POST['cars_per_labor_hour_target'] ?? 2.0);
        if ($cphTarget <= 0) $cphTarget = 2.0;

        $shopHours = (float)($_POST['shop_daily_open_hours'] ?? 10.0);
        if ($shopHours <= 0) $shopHours = 10.0;

        $lbEnabled = (isset($_POST['leaderboard_enabled']) && $_POST['leaderboard_enabled'] === '1') ? '1' : '0';

        $metrics = $_POST['leaderboard_metrics'] ?? ['cars_per_hour', 'jobs_completed', 'efficiency_factor'];
        if (!is_array($metrics)) $metrics = ['cars_per_hour'];
        $metricsJson = json_encode(array_values($metrics));

        $roles = $_POST['leaderboard_visible_roles'] ?? ['owner', 'manager'];
        if (!is_array($roles)) $roles = ['owner', 'manager'];
        $rolesJson = json_encode(array_values($roles));

        $changed = 0;
        if (update_setting($pdo, 'cars_per_labor_hour_target', (string)$cphTarget, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'shop_daily_open_hours', (string)$shopHours, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'leaderboard_enabled', $lbEnabled, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'leaderboard_metrics', $metricsJson, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'leaderboard_visible_roles', $rolesJson, $userId, $userName)) $changed++;

        $message = $changed > 0 ? "Performance & Leaderboard settings saved! ({$changed} items updated)" : "No changes made to Performance settings.";
        $messageType = $changed > 0 ? 'success' : 'info';
    }

    // 6c. Save Cloud Sync Settings
    elseif ($action === 'save_cloud_sync') {
        $syncEnabled = (isset($_POST['cloud_sync_enabled']) && $_POST['cloud_sync_enabled'] === '1') ? '1' : '0';
        $apiUrl = trim((string)($_POST['cloud_api_url'] ?? ''));
        $shopId = trim((string)($_POST['cloud_shop_id'] ?? ''));
        $apiToken = trim((string)($_POST['cloud_api_token'] ?? ''));
        $apiSecret = trim((string)($_POST['cloud_api_secret'] ?? ''));
        $offlineMode = (isset($_POST['cloud_offline_mode']) && $_POST['cloud_offline_mode'] === '1') ? '1' : '0';
        $allowInsecureTls = (isset($_POST['cloud_allow_insecure_tls']) && $_POST['cloud_allow_insecure_tls'] === '1') ? '1' : '0';

        $changed = 0;
        if (update_setting($pdo, 'cloud_sync_enabled', $syncEnabled, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'cloud_api_url', $apiUrl, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'cloud_shop_id', $shopId, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'cloud_api_token', $apiToken, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'cloud_api_secret', $apiSecret, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'cloud_offline_mode', $offlineMode, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'cloud_allow_insecure_tls', $allowInsecureTls, $userId, $userName)) $changed++;

        $message = $changed > 0 ? "Cloud Sync configuration saved! ({$changed} items updated)" : "No changes made to Cloud Sync settings.";
        $messageType = $changed > 0 ? 'success' : 'info';
    }

    // 7. Save System Settings
    elseif ($action === 'save_system') {
        $defLang = in_array($_POST['default_language'] ?? '', ['en', 'km'], true) ? $_POST['default_language'] : 'en';
        $tz = trim($_POST['app_timezone'] ?? 'Asia/Phnom_Penh');

        $changed = 0;
        if (update_setting($pdo, 'default_language', $defLang, $userId, $userName)) $changed++;
        if (update_setting($pdo, 'app_timezone', $tz, $userId, $userName)) $changed++;

        // Only Shop Owner can toggle Test mode
        if (Auth::getRole() === 'owner' && isset($_POST['demo_mode'])) {
            $demo = ($_POST['demo_mode'] === '1') ? '1' : '0';
            if (update_setting($pdo, 'demo_mode', $demo, $userId, $userName)) $changed++;
        }

        $message = $changed > 0 ? "System configuration updated! ({$changed} items updated)" : "No changes made to system settings.";
        $messageType = $changed > 0 ? 'success' : 'info';
    }

    // 7b. Explicit Owner Database Migrations Runner
    elseif ($action === 'run_migrations') {
        require_once dirname(__DIR__, 2) . '/database/migrate.php';
        $res = runMigrations(true);
        if (!empty($res['success'])) {
            $message = "Database schema migrations checked and executed successfully! All tables are up to date.";
            $messageType = 'success';
            $settings['migration_last_error'] = '';
        } else {
            $message = "Database migration warning: " . ($res['error'] ?? 'Unknown error');
            $messageType = 'danger';
            $settings['migration_last_error'] = $res['error'] ?? 'Unknown error';
        }
    }
    }
}

// Fetch all current settings
$settings = [];
$sStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $sStmt->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

// Fetch recent change history (latest 30)
$historyList = [];
try {
    $hStmt = $pdo->query("SELECT * FROM settings_history ORDER BY id DESC LIMIT 30");
    if ($hStmt) {
        $historyList = $hStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $t) {}

// Key Label Dictionary for History
$keyLabels = [
    'shop_name_en'        => 'Shop Name (English)',
    'shop_name_km'        => 'Shop Name (Khmer)',
    'shop_address'        => 'Shop Address',
    'shop_phone'          => 'Phone Numbers',
    'shop_logo'           => 'Shop Logo File',
    'receipt_footer'      => 'Receipt Footer Message',
    'receipt_show_logo'   => 'Receipt Logo Display',
    'receipt_language'    => 'Receipt Language Mode',
    'exchange_rate'       => 'Exchange Rate (USD to KHR)',
    'aba_account_name'    => 'ABA Account Name',
    'aba_account_num'     => 'ABA Account Number',
    'khqr_image'          => 'KHQR Stand Image',
    'default_oil_mileage' => 'Default Oil Mileage Interval',
    'default_oil_months'  => 'Default Oil Month Interval',
    'telegram_bot_token'  => 'Telegram Bot Token',
    'telegram_chat_id'    => 'Telegram Alert Chat ID',
    'default_language'    => 'Default System Language',
    'demo_mode'           => 'Quick Demo Login Switch',
    'app_timezone'        => 'Application Time Zone',
];

require_once dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 24px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
            <i class="fa-solid fa-gear mr-2" style="color: var(--primary);"></i><?= htmlspecialchars(__('nav_settings', 'Shop Settings')) ?>
        </h1>
        <p style="color: var(--text-muted); font-size: 14px;">
            Configure shop information, receipts, exchange rate, payments, reminders, and system parameters dynamically.
        </p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="<?= APP_URL ?>/index.php?view=receipt&test=1" target="_blank" class="btn btn-secondary btn-sm" title="Print a sample 80mm receipt with current settings">
            <i class="fa-solid fa-print mr-1"></i> <?= htmlspecialchars(__('print_test_receipt', 'Test Receipt (80mm)')) ?>
        </a>
        <a href="<?= APP_URL ?>/index.php?view=dashboard" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-arrow-left mr-1"></i> Dashboard
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-radius: var(--radius-md); font-weight: 600;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : ($messageType === 'danger' ? 'fa-circle-xmark' : 'fa-circle-info') ?>" style="font-size: 18px;"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: inherit; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<?php if (!empty($settings['migration_last_error'])): ?>
    <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #f87171; border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626; font-size: 22px;"></i>
            <div>
                <strong style="color: #991b1b; font-size: 14px;">Database Migration Warning (Owner Notice)</strong>
                <div style="color: #b91c1c; font-size: 13px; margin-top: 2px;"><?= htmlspecialchars($settings['migration_last_error']) ?></div>
                <div class="text-xs text-muted" style="margin-top: 2px;">Technical details have been written to storage/logs/migration_error.log.</div>
            </div>
        </div>
        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="run_migrations">
            <input type="hidden" name="tab" value="system">
            <button type="submit" class="btn btn-sm btn-primary" style="background: #dc2626; border-color: #dc2626;">
                <i class="fa-solid fa-rotate mr-1"></i> Check &amp; Retry Migrations
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- Settings Navigation Tabs -->
<div style="display: flex; gap: 6px; border-bottom: 2px solid var(--border); padding-bottom: 8px; margin-bottom: 24px; flex-wrap: wrap;">
    <button type="button" class="btn btn-sm <?= ($activeTab === 'shop') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('shop')">
        <i class="fa-solid fa-store mr-1"></i> 1. Shop Info
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'receipt') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('receipt')">
        <i class="fa-solid fa-receipt mr-1"></i> 2. Receipt
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'money') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('money')">
        <i class="fa-solid fa-money-bill-transfer mr-1"></i> 3. Money &amp; Rate
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'payment') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('payment')">
        <i class="fa-solid fa-qrcode mr-1"></i> 4. ABA &amp; KHQR
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'bays') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('bays')">
        <i class="fa-solid fa-warehouse mr-1"></i> <?= htmlspecialchars(__('nav_bays_queue', 'Bays & Queue')) ?>
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'oil') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('oil')">
        <i class="fa-solid fa-oil-can mr-1"></i> 5. Oil Reminders
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'loyalty') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('loyalty')">
        <i class="fa-solid fa-award mr-1" style="color: #f59e0b;"></i> 6. Loyalty &amp; Tiers
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'performance') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('performance')">
        <i class="fa-solid fa-gauge-high mr-1" style="color: #10b981;"></i> 7. Performance &amp; Leaderboard
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'telegram') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('telegram')">
        <i class="fa-brands fa-telegram mr-1" style="color: #0284c7;"></i> 8. Telegram
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'sync') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('sync')">
        <i class="fa-solid fa-cloud-arrow-up mr-1" style="color: #6366f1;"></i> 9. Cloud Sync
        <?php if (empty($settings['cloud_api_url']) || empty($settings['cloud_api_token']) || empty($settings['cloud_api_secret'])): ?>
            <span class="badge badge-warning" style="margin-left: 4px; padding: 2px 6px; font-size: 10px;" title="Not configured">!</span>
        <?php endif; ?>
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'system') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('system')">
        <i class="fa-solid fa-sliders mr-1"></i> 10. System
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'history') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('history')">
        <i class="fa-solid fa-clock-rotate-left mr-1"></i> Change History (<?= count($historyList) ?>)
    </button>
</div>

<!-- ======================================================== -->
<!-- GROUP 1: SHOP INFORMATION -->
<!-- ======================================================== -->
<div id="tab_shop" class="tab-pane" style="display: <?= ($activeTab === 'shop') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-store mr-2" style="color: var(--primary);"></i>Shop Information
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Displayed on receipt headers, counter screens, customer printouts, and reports.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_shop">
            <input type="hidden" name="tab" value="shop">

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Shop Name (Khmer) <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="shop_name_km" class="form-input" style="font-family: 'Kantumruy Pro', sans-serif;" value="<?= htmlspecialchars($settings['shop_name_km'] ?? 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប') ?>" required>
                    <div class="text-xs text-muted" style="margin-top: 4px;">ឈ្មោះហាងជាភាសាខ្មែរ</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Shop Name (English) <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="shop_name_en" class="form-input" value="<?= htmlspecialchars($settings['shop_name_en'] ?? 'AquaGleam Car Wash & Detailing') ?>" required>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Shop brand name in English</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Shop Address / Location</label>
                <input type="text" name="shop_address" class="form-input" value="<?= htmlspecialchars($settings['shop_address'] ?? 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh') ?>">
                <div class="text-xs text-muted" style="margin-top: 4px;">Printed on the top of 80mm receipts and contact cards</div>
            </div>

            <div class="form-group">
                <label class="form-label">Phone Numbers</label>
                <input type="text" name="shop_phone" class="form-input" value="<?= htmlspecialchars($settings['shop_phone'] ?? '012 334 556 / 098 776 543') ?>">
                <div class="text-xs text-muted" style="margin-top: 4px;">Separate multiple numbers with a slash (e.g. 012 334 556 / 098 776 543)</div>
            </div>

            <div class="form-group">
                <label class="form-label">Shop Logo</label>
                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                    <?php if (!empty($settings['shop_logo'])): ?>
                        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 8px; background: #fff; text-align: center;">
                            <img src="<?= APP_URL ?>/<?= htmlspecialchars($settings['shop_logo']) ?>" alt="Shop Logo" style="max-height: 60px; max-width: 140px; object-fit: contain; display: block; margin: 0 auto;">
                            <label style="display: block; font-size: 11px; margin-top: 6px; color: var(--danger); cursor: pointer;">
                                <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                            </label>
                        </div>
                    <?php else: ?>
                        <div style="width: 70px; height: 60px; border: 2px dashed var(--border); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 24px;">
                            <i class="fa-solid fa-image"></i>
                        </div>
                    <?php endif; ?>

                    <div style="flex: 1; min-width: 200px;">
                        <input type="file" name="shop_logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="form-input" style="padding: 8px;">
                        <div class="text-xs text-muted" style="margin-top: 4px;">Supported formats: PNG, JPG, WebP, SVG. Recommended height: 50-80px.</div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Shop Information
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 2: RECEIPT SETTINGS -->
<!-- ======================================================== -->
<div id="tab_receipt" class="tab-pane" style="display: <?= ($activeTab === 'receipt') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-receipt mr-2" style="color: var(--primary);"></i>Receipt &amp; 80mm Thermal Print
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Control logo display, bilingual output, and custom thank-you footer on customer receipts.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_receipt">
            <input type="hidden" name="tab" value="receipt">

            <div class="form-group">
                <label class="form-label">Thank-You Footer Message</label>
                <textarea name="receipt_footer" class="form-input" rows="2" style="font-family: 'Kantumruy Pro', sans-serif;"><?= htmlspecialchars($settings['receipt_footer'] ?? 'Thank you for choosing AquaGleam! / សូមអរគុណ!') ?></textarea>
                <div class="text-xs text-muted" style="margin-top: 4px;">Prints at the very bottom of every 80mm thermal receipt.</div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Show Shop Logo on Receipt</label>
                    <select name="receipt_show_logo" class="form-select">
                        <option value="1" <?= (($settings['receipt_show_logo'] ?? '1') === '1') ? 'selected' : '' ?>>Show Logo at Top</option>
                        <option value="0" <?= (($settings['receipt_show_logo'] ?? '1') === '0') ? 'selected' : '' ?>>Hide Logo (Text Only)</option>
                    </select>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Hiding the logo saves paper on compact 80mm printers.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Receipt Language Mode</label>
                    <select name="receipt_language" class="form-select">
                        <option value="both" <?= (($settings['receipt_language'] ?? 'both') === 'both') ? 'selected' : '' ?>>Bilingual (Both Khmer &amp; English)</option>
                        <option value="km" <?= (($settings['receipt_language'] ?? '') === 'km') ? 'selected' : '' ?>>Khmer Only (ភាសាខ្មែរ)</option>
                        <option value="en" <?= (($settings['receipt_language'] ?? '') === 'en') ? 'selected' : '' ?>>English Only</option>
                    </select>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Controls headings and labels on printed customer receipts.</div>
                </div>
            </div>

            <div style="background: #f8fafc; border: 1px dashed var(--border); border-radius: var(--radius-md); padding: 16px; margin: 20px 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div>
                    <div style="font-weight: 700; font-size: 14px; color: var(--text-main);">
                        <i class="fa-solid fa-print mr-1" style="color: #0284c7;"></i> Test Print Receipt on 80mm Paper
                    </div>
                    <div class="text-xs text-muted" style="margin-top: 2px;">
                        Preview sample order receipt with your current logo, language, and footer before handing to customers.
                    </div>
                </div>
                <a href="<?= APP_URL ?>/index.php?view=receipt&test=1" target="_blank" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Open Test Receipt (80mm)
                </a>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Receipt Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 3: MONEY & EXCHANGE RATE -->
<!-- ======================================================== -->
<div id="tab_money" class="tab-pane" style="display: <?= ($activeTab === 'money') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-money-bill-transfer mr-2" style="color: #16a34a;"></i>Money &amp; Exchange Rate
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Central USD ($) to KHR (៛) exchange rate used throughout the entire car wash system and external POS devices.
            </p>
        </div>

        <!-- Warning Alert Box -->
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 20px; color: #92400e;">
            <div style="display: flex; gap: 12px; align-items: flex-start;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 20px; color: #d97706; margin-top: 2px;"></i>
                <div>
                    <div style="font-weight: 700; font-size: 14px; margin-bottom: 2px;">Important Notice Regarding Exchange Rate Changes:</div>
                    <div style="font-size: 13px; line-height: 1.4;">
                        When you change the exchange rate, <strong>every new order, price conversion, dual receipt total, and monthly report</strong> will immediately use the new rate. <strong>Previously completed orders will permanently retain the riel amount they already had</strong> to keep your past bookkeeping accurate.
                    </div>
                </div>
            </div>
        </div>

        <form id="moneyForm" action="<?= APP_URL ?>/index.php?view=settings" method="POST" onsubmit="return confirmExchangeRateChange(event)">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_money">
            <input type="hidden" name="tab" value="money">

            <?php $currentRate = get_exchange_rate(); ?>
            <input type="hidden" id="current_exchange_rate_val" value="<?= $currentRate ?>">

            <div class="form-group">
                <label class="form-label" style="font-size: 15px; font-weight: 700;">Exchange Rate: 1 USD = ? KHR</label>
                <div style="display: flex; align-items: center; gap: 12px; max-width: 320px;">
                    <div style="font-size: 20px; font-weight: 800; color: var(--text-main);">$1.00 = </div>
                    <input type="number" name="exchange_rate" id="new_exchange_rate_input" class="form-input" style="font-size: 18px; font-weight: 800; font-family: var(--font-mono); color: #16a34a;" value="<?= $currentRate ?>" min="1000" max="20000" step="10" required>
                    <div style="font-size: 18px; font-weight: 700; color: var(--text-muted);">៛</div>
                </div>
                <div class="text-xs text-muted" style="margin-top: 6px;">
                    Standard Cambodia market rate is typically between 4,000 and 4,150 KHR per USD.
                </div>
            </div>

            <!-- Real-time Preview Table -->
            <div style="background: var(--bg-subtle); border-radius: var(--radius-md); padding: 14px 18px; margin: 20px 0;">
                <div style="font-weight: 700; font-size: 13px; color: var(--text-main); margin-bottom: 8px;">
                    <i class="fa-solid fa-calculator mr-1"></i> Live Conversion Preview:
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; font-size: 13px;">
                    <div style="background: var(--bg-card); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border);">
                        $2.50 = <strong id="prev_2_50" style="color: #16a34a;"><?= number_format(round(2.50 * $currentRate)) ?> ៛</strong>
                    </div>
                    <div style="background: var(--bg-card); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border);">
                        $5.00 = <strong id="prev_5_00" style="color: #16a34a;"><?= number_format(round(5.00 * $currentRate)) ?> ៛</strong>
                    </div>
                    <div style="background: var(--bg-card); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border);">
                        $10.00 = <strong id="prev_10_00" style="color: #16a34a;"><?= number_format(round(10.00 * $currentRate)) ?> ៛</strong>
                    </div>
                    <div style="background: var(--bg-card); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border);">
                        $25.00 = <strong id="prev_25_00" style="color: #16a34a;"><?= number_format(round(25.00 * $currentRate)) ?> ៛</strong>
                    </div>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary" id="saveRateBtn">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Update Exchange Rate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 4: PAYMENT (ABA & KHQR) -->
<!-- ======================================================== -->
<div id="tab_payment" class="tab-pane" style="display: <?= ($activeTab === 'payment') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-qrcode mr-2" style="color: #dc2626;"></i>Payment: ABA &amp; Counter KHQR
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Configure the shop's ABA account details and counter KHQR stand code shown to customers during payment.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_payment">
            <input type="hidden" name="tab" value="payment">

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">ABA Bank Account Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="aba_account_name" class="form-input" style="text-transform: uppercase;" value="<?= htmlspecialchars($settings['aba_account_name'] ?? 'CAR WASH SERVICE CO., LTD') ?>" required>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Registered business name on ABA Bank</div>
                </div>

                <div class="form-group">
                    <label class="form-label">ABA Account Number <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="aba_account_num" class="form-input" style="font-family: var(--font-mono); font-weight: 700;" value="<?= htmlspecialchars($settings['aba_account_num'] ?? '001 234 567') ?>" required>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Account number (e.g. 001 234 567)</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Counter KHQR Code Image</label>
                <div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
                    <?php if (!empty($settings['khqr_image'])): ?>
                        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px; background: #fff; text-align: center; max-width: 180px;">
                            <img src="<?= APP_URL ?>/<?= htmlspecialchars($settings['khqr_image']) ?>" alt="Counter KHQR" style="max-height: 150px; max-width: 150px; object-fit: contain; display: block; margin: 0 auto; border-radius: 4px;">
                            <label style="display: block; font-size: 11px; margin-top: 8px; color: var(--danger); cursor: pointer;">
                                <input type="checkbox" name="remove_khqr" value="1"> Remove KHQR image
                            </label>
                        </div>
                    <?php else: ?>
                        <div style="width: 140px; height: 140px; border: 2px dashed var(--border); border-radius: var(--radius-md); display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted); padding: 12px; text-align: center;">
                            <i class="fa-solid fa-qrcode" style="font-size: 36px; margin-bottom: 6px;"></i>
                            <span style="font-size: 11px;">No stand image uploaded</span>
                        </div>
                    <?php endif; ?>

                    <div style="flex: 1; min-width: 220px;">
                        <input type="file" name="khqr_image_file" accept="image/png,image/jpeg,image/webp" class="form-input" style="padding: 8px;">
                        <div class="text-xs text-muted" style="margin-top: 6px; line-height: 1.4;">
                            Upload the official KHQR QR stand image provided by ABA Bank. When cashiers tap the KHQR payment option on counter screens or POS, this official image will appear for customers to scan directly.
                        </div>
                        <?php if (!empty($settings['khqr_image'])): ?>
                        <div style="margin-top: 10px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('counterKhqrPreviewModal')">
                                <i class="fa-solid fa-eye mr-1"></i> Preview Counter Stand Display
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Payment Details
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Counter KHQR Stand Preview Modal -->
<?php if (!empty($settings['khqr_image'])): ?>
<div id="counterKhqrPreviewModal" class="modal-backdrop" style="display: none;">
    <div class="modal" style="max-width: 380px; text-align: center;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 16px; font-weight: 700;">Counter Stand Preview</h3>
            <button type="button" class="btn-close" onclick="closeModal('counterKhqrPreviewModal')">&times;</button>
        </div>
        <div style="background: #fff; padding: 16px; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow-md);">
            <div style="font-weight: 800; font-size: 15px; margin-bottom: 4px;"><?= htmlspecialchars($settings['aba_account_name'] ?? 'CAR WASH SERVICE CO., LTD') ?></div>
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px; font-family: var(--font-mono);">
                ABA: <?= htmlspecialchars($settings['aba_account_num'] ?? '001 234 567') ?>
            </div>
            <img src="<?= APP_URL ?>/<?= htmlspecialchars($settings['khqr_image']) ?>" alt="KHQR Stand" style="max-width: 100%; max-height: 280px; object-fit: contain; border-radius: 8px;">
            <div style="margin-top: 12px; font-size: 12px; color: var(--text-muted);">
                <i class="fa-solid fa-mobile-screen mr-1"></i> Scan to Pay with Any Banking App (Bakong)
            </div>
        </div>
        <div style="margin-top: 16px;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('counterKhqrPreviewModal')">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$baysList = BayManager::getAllBays($pdo);
?>
<!-- ======================================================== -->
<!-- GROUP 4B: BAYS CONFIGURATION & STATUS -->
<!-- ======================================================== -->
<div id="tab_bays" class="tab-pane" style="display: <?= ($activeTab === 'bays') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                    <i class="fa-solid fa-warehouse mr-2" style="color: var(--primary);"></i><?= htmlspecialchars(__('bays_and_queue', 'Car Wash Bays & Oil Change Bay')) ?>
                </h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                    Rename bays or switch them off temporarily for maintenance. Each bay displays real-time Free/Busy status and current vehicle.
                </p>
            </div>
            <div>
                <a href="?view=bays_queue" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-desktop mr-1"></i> Open Live Board
                </a>
            </div>
        </div>

        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_bays">
            <input type="hidden" name="tab" value="bays">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <?php foreach ($baysList as $b): 
                    $isBusy = ($b['status'] === 'busy' && !empty($b['current_car']));
                    $isActive = ($b['is_active'] === 1);
                    $car = $b['current_car'];
                    $isOil = ($b['bay_type'] === 'oil_change');
                ?>
                    <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; background: var(--bg-card); position: relative; border-left: 5px solid <?= !$isActive ? 'var(--text-light)' : ($isBusy ? 'var(--warning)' : 'var(--success)') ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 34px; height: 34px; border-radius: 8px; background: <?= $isOil ? '#ffedd5' : 'var(--primary-light)' ?>; color: <?= $isOil ? '#ea580c' : 'var(--primary)' ?>; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                                    <i class="fa-solid <?= $isOil ? 'fa-wrench' : 'fa-car-wash' ?>"></i>
                                </div>
                                <div>
                                    <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.5px;">
                                        Bay #<?= $b['bay_number'] ?> • <?= $isOil ? 'Oil Change & Technical' : 'Car Wash Bay' ?>
                                    </span>
                                    <h4 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin-top: 1px;">
                                        <?= htmlspecialchars($b['bay_name']) ?>
                                    </h4>
                                </div>
                            </div>
                            <div>
                                <?php if (!$isActive): ?>
                                    <span class="badge badge-slate" style="background: var(--bg-subtle); color: var(--text-muted);">
                                        <i class="fa-solid fa-power-off mr-1"></i> Off (Inactive)
                                    </span>
                                <?php elseif ($isBusy): ?>
                                    <span class="badge badge-warning" style="background: var(--warning-bg); color: var(--warning); font-weight: 700;">
                                        <i class="fa-solid fa-circle-dot mr-1"></i> Busy
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-success" style="background: var(--success-bg); color: var(--success); font-weight: 700;">
                                        <i class="fa-solid fa-circle-check mr-1"></i> Free
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Current Car in Bay Details -->
                        <div style="background: var(--bg-subtle); border-radius: var(--radius-sm); padding: 10px 12px; margin-bottom: 14px; font-size: 13px;">
                            <?php if (!$isActive): ?>
                                <span style="color: var(--text-muted); font-style: italic;">
                                    <i class="fa-solid fa-ban mr-1"></i> Bay is switched off. Cannot accept vehicles.
                                </span>
                            <?php elseif ($isBusy && $car): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <span style="font-family: var(--font-mono); font-weight: 800; font-size: 14px; background: var(--bg-card); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border);">
                                        <?= htmlspecialchars($car['plate_number']) ?>
                                    </span>
                                    <span style="font-size: 12px; color: var(--warning); font-weight: 600;">
                                        <i class="fa-solid fa-stopwatch mr-1"></i> In bay: <?= (int)$car['duration_minutes'] ?>m
                                    </span>
                                </div>
                                <div style="color: var(--text-muted); font-size: 12px;">
                                    <strong><?= htmlspecialchars($car['customer_name']) ?></strong> • <?= htmlspecialchars($car['service_names'] ?: 'Service') ?>
                                </div>
                            <?php else: ?>
                                <span style="color: var(--success); font-weight: 600;">
                                    <i class="fa-solid fa-circle-check mr-1"></i> Ready for next vehicle
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Rename, Skill Tag & Switch Controls -->
                        <div style="display: grid; grid-template-columns: 1.2fr 1fr auto; gap: 12px; align-items: center;">
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase;">
                                    Bay Name
                                </label>
                                <input type="text" 
                                       name="bays[<?= $b['id'] ?>][name]" 
                                       value="<?= htmlspecialchars($b['bay_name']) ?>" 
                                       class="form-input" 
                                       required
                                       style="width: 100%; font-size: 13px; padding: 7px 10px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase;">
                                    Skill Tag
                                </label>
                                <select name="bays[<?= $b['id'] ?>][skill_tag]" class="form-select" style="width: 100%; font-size: 13px; padding: 7px 10px;">
                                    <option value="wash_only" <?= ($b['skill_tag'] ?? 'wash_only') === 'wash_only' ? 'selected' : '' ?>>Wash Only</option>
                                    <option value="oil_only" <?= ($b['skill_tag'] ?? '') === 'oil_only' ? 'selected' : '' ?>>Oil Only</option>
                                    <option value="wash_and_oil" <?= ($b['skill_tag'] ?? '') === 'wash_and_oil' ? 'selected' : '' ?>>Wash and Oil</option>
                                    <option value="detailing" <?= ($b['skill_tag'] ?? '') === 'detailing' ? 'selected' : '' ?>>Detailing</option>
                                </select>
                            </div>
                            <div style="padding-top: 18px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text-main);">
                                    <input type="checkbox" 
                                           name="bays[<?= $b['id'] ?>][is_active]" 
                                           value="1" 
                                           <?= $isActive ? 'checked' : '' ?>
                                           style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;">
                                    <span>Active</span>
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- TV Customer Display Settings -->
            <div style="border-top: 2px dashed var(--border); padding-top: 22px; margin-top: 8px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 style="font-size: 16px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-tv" style="color: var(--primary);"></i>
                            <span>Office TV Customer Queue Display</span>
                        </h3>
                        <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                            Configures the full-screen queue display for the office TV. Big text, auto-refreshing, and strictly read-only.
                        </p>
                    </div>
                    <?php 
                    $curTvCode = $settings['tv_display_code'] ?? 'tv-office-88';
                    $curTvEnabled = ($settings['tv_display_enabled'] ?? '1') === '1';
                    $curTvLang = $settings['tv_display_language'] ?? 'both';
                    $tvUrl = APP_URL . '/tv.php?code=' . urlencode($curTvCode);
                    ?>
                    <div>
                        <a href="<?= $tvUrl ?>" target="_blank" class="btn btn-secondary btn-sm">
                            <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Open TV Screen
                        </a>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                    <!-- Enabled / Disabled Switch -->
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">TV Display Status</label>
                        <select name="tv_display_enabled" class="form-select">
                            <option value="1" <?= $curTvEnabled ? 'selected' : '' ?>>Enabled (Allow TV to open)</option>
                            <option value="0" <?= !$curTvEnabled ? 'selected' : '' ?>>Disabled (Show 'Not available')</option>
                        </select>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            When switched off, the TV shows "Not available".
                        </div>
                    </div>

                    <!-- Secret Code -->
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">
                            Secret TV Code <span style="color: var(--danger);">*</span>
                        </label>
                        <input type="text" name="tv_display_code" value="<?= htmlspecialchars($curTvCode) ?>" class="form-input" required style="font-family: var(--font-mono); font-weight: 700;">
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            Secret parameter in URL so only your office TV can open the screen.
                        </div>
                    </div>

                    <!-- Language Option -->
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Display Language</label>
                        <select name="tv_display_language" class="form-select">
                            <option value="both" <?= $curTvLang === 'both' ? 'selected' : '' ?>>Bilingual (English &amp; ភាសាខ្មែរ)</option>
                            <option value="km" <?= $curTvLang === 'km' ? 'selected' : '' ?>>Khmer Only (ភាសាខ្មែរ)</option>
                            <option value="en" <?= $curTvLang === 'en' ? 'selected' : '' ?>>English Only</option>
                        </select>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            Changes headers, "Free", "Washing", and "Ready for payment" labels.
                        </div>
                    </div>
                </div>

                <!-- Web address box -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px 14px; margin-top: 14px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">
                        Office TV Web Address (Bookmark on your TV browser):
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="tvWebAddressInput" readonly value="<?= htmlspecialchars($tvUrl) ?>" class="form-input" style="font-family: var(--font-mono); font-size: 12px; background: #ffffff;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="copyTvUrl()" style="white-space: nowrap;">
                            <i class="fa-solid fa-copy mr-1"></i> Copy URL
                        </button>
                    </div>
                </div>
            </div>

            <!-- Smart Queue Engine: Vehicle Multipliers, Buffer & Delay Reasons -->
            <div style="border-top: 2px dashed var(--border); padding-top: 22px; margin-top: 8px; margin-bottom: 24px;">
                <div style="margin-bottom: 16px;">
                    <h3 style="font-size: 16px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-calculator" style="color: var(--primary);"></i>
                        <span>Smart Queue Engine &amp; Duration Estimator</span>
                    </h3>
                    <p style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                        Configures vehicle size duration multipliers, customer estimate buffer percentage, and editable delay reasons.
                    </p>
                </div>

                <?php
                $mulSedan = get_setting('multiplier_sedan', '1.0');
                $mulSuv = get_setting('multiplier_suv_pickup', '1.25');
                $mulTruck = get_setting('multiplier_van_truck', '1.4');
                $mulMoto = get_setting('multiplier_motorbike', '0.6');
                $estBuffer = get_setting('estimate_buffer_percent', '10');
                require_once dirname(__DIR__, 2) . '/core/SmartQueueEngine.php';
                $delayReasons = SmartQueueEngine::getAvailableDelayReasons($pdo);
                $delayReasonsText = implode("\n", $delayReasons);
                ?>

                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">
                            <i class="fa-solid fa-car-side mr-1 text-primary"></i> Sedan Multiplier
                        </label>
                        <input type="number" step="0.05" min="0.5" max="3.0" name="multiplier_sedan" value="<?= htmlspecialchars((string)$mulSedan) ?>" class="form-input" required>
                        <div class="text-xs text-muted" style="margin-top: 4px;">Baseline car size (Default: 1.0)</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">
                            <i class="fa-solid fa-truck-pickup mr-1 text-primary"></i> SUV &amp; Pickup Multiplier
                        </label>
                        <input type="number" step="0.05" min="0.5" max="3.0" name="multiplier_suv_pickup" value="<?= htmlspecialchars((string)$mulSuv) ?>" class="form-input" required>
                        <div class="text-xs text-muted" style="margin-top: 4px;">Medium/Large body (Default: 1.25)</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">
                            <i class="fa-solid fa-van-shuttle mr-1 text-primary"></i> Truck &amp; Van Multiplier
                        </label>
                        <input type="number" step="0.05" min="0.5" max="3.0" name="multiplier_van_truck" value="<?= htmlspecialchars((string)$mulTruck) ?>" class="form-input" required>
                        <div class="text-xs text-muted" style="margin-top: 4px;">Heavy/Commercial (Default: 1.4)</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">
                            <i class="fa-solid fa-motorcycle mr-1 text-primary"></i> Motorcycle Multiplier
                        </label>
                        <input type="number" step="0.05" min="0.2" max="2.0" name="multiplier_motorbike" value="<?= htmlspecialchars((string)$mulMoto) ?>" class="form-input" required>
                        <div class="text-xs text-muted" style="margin-top: 4px;">Two-wheelers (Default: 0.6)</div>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">
                            <i class="fa-solid fa-hourglass-half mr-1 text-primary"></i> Estimate Buffer (%)
                        </label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="number" min="0" max="50" name="estimate_buffer_percent" value="<?= htmlspecialchars((string)$estBuffer) ?>" class="form-input" required style="max-width: 120px;">
                            <span style="font-weight: 700; color: var(--text-muted);">% buffer</span>
                        </div>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            Rounds the customer-shown estimate up so staff never miss promises. (Default: 10%)
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">
                            <i class="fa-solid fa-list-check mr-1 text-primary"></i> Editable Delay Reasons (One per line)
                        </label>
                        <textarea name="delay_reasons_text" class="form-textarea" rows="4" style="font-size: 13px; font-family: var(--font-family);"><?= htmlspecialchars($delayReasonsText) ?></textarea>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            Washer and technicians pick from this list when a job exceeds its estimate by 5+ minutes. (Note: "Waiting for part approval" is detected automatically).
                        </div>
                    </div>
                </div>
            </div>

            <div style="border-top: 1px solid var(--border); padding-top: 16px; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 5: OIL CHANGE REMINDER DEFAULTS -->
<!-- ======================================================== -->
<div id="tab_oil" class="tab-pane" style="display: <?= ($activeTab === 'oil') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-oil-can mr-2" style="color: #ea580c;"></i>Oil Change Reminder Defaults
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Default distance and time intervals used as initial values on web order forms and connected POS devices.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_oil">
            <input type="hidden" name="tab" value="oil">

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Default Distance Interval (Kilometers) <span style="color: var(--danger);">*</span></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" name="default_oil_mileage" class="form-input" style="font-size: 16px; font-weight: 700;" value="<?= htmlspecialchars($settings['default_oil_mileage'] ?? '10000') ?>" min="1000" step="500" required>
                        <span style="font-weight: 700; color: var(--text-muted);">KM</span>
                    </div>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Commonly 10,000 km (Full Synthetic) or 5,000 km (Semi-Synthetic)</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Default Time Interval (Months) <span style="color: var(--danger);">*</span></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" name="default_oil_months" class="form-input" style="font-size: 16px; font-weight: 700;" value="<?= htmlspecialchars($settings['default_oil_months'] ?? '3') ?>" min="1" max="24" required>
                        <span style="font-weight: 700; color: var(--text-muted);">Months</span>
                    </div>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Commonly 3 months or 6 months</div>
                </div>
            </div>

            <div style="background: #fff7ed; border: 1px solid #ffedd5; border-radius: var(--radius-md); padding: 14px 18px; margin: 16px 0; color: #9a3412; font-size: 13px;">
                <i class="fa-solid fa-circle-info mr-1"></i>
                <strong>Flexibility Note:</strong> When cashiers record an oil change on the New Order / POS screen, these values will be pre-filled automatically. The cashier can still change the distance or month interval for any individual order to match the customer's vehicle or specific oil grade.
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Oil Reminder Defaults
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 6: LOYALTY & MEMBERSHIP TIERS -->
<!-- ======================================================== -->
<div id="tab_loyalty" class="tab-pane" style="display: <?= ($activeTab === 'loyalty') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-award mr-2" style="color: #f59e0b;"></i>Loyalty Points &amp; Membership Tier Rules
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Configure customer point earning rates, cash redemption discounts, membership tier thresholds, and wax service perks.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_loyalty">
            <input type="hidden" name="tab" value="loyalty">

            <!-- Section 1: Points Rules -->
            <div style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-coins" style="color: var(--primary);"></i> 1. Earning &amp; Redemption Rules
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                <div class="form-group">
                    <label class="form-label">Earning Rate (Base Points per $1 Paid) <span style="color: var(--danger);">*</span></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" name="loyalty_earn_rate" class="form-input" style="font-size: 16px; font-weight: 700; font-family: var(--font-mono);" value="<?= htmlspecialchars($settings['loyalty_earn_rate'] ?? '1') ?>" min="1" max="100" required>
                        <span style="font-weight: 700; color: var(--text-muted);">pts / $1</span>
                    </div>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Points are awarded on whole dollars paid after discounts. Products, washes, and repairs count.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Redemption Rate (Points needed for $1 Credit) <span style="color: var(--danger);">*</span></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" name="loyalty_redeem_rate" class="form-input" style="font-size: 16px; font-weight: 700; font-family: var(--font-mono);" value="<?= htmlspecialchars($settings['loyalty_redeem_rate'] ?? '10') ?>" min="1" max="1000" required>
                        <span style="font-weight: 700; color: var(--text-muted);">pts = $1.00</span>
                    </div>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Default is 10 points = $1.00 credit (1 point = $0.10). Applied at checkout.</div>
                </div>
            </div>

            <!-- Section 2: Membership Tiers & Multipliers -->
            <div style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-ranking-star" style="color: #d97706;"></i> 2. Membership Tiers &amp; Multipliers
            </div>

            <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px;">
                <!-- Standard Tier -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-left: 4px solid #94a3b8; border-radius: var(--radius-md); padding: 14px 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span class="badge badge-slate" style="font-weight: 800; font-size: 12px;">STANDARD TIER</span>
                            <span class="text-xs text-muted ml-2">Default tier for all new customers</span>
                        </div>
                        <span class="badge badge-slate text-xs">Min: 0 lifetime pts</span>
                    </div>
                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <label class="form-label text-xs">Points Multiplier</label>
                            <input type="number" step="0.1" name="loyalty_multiplier_standard" class="form-input" style="font-weight: 700;" value="<?= htmlspecialchars($settings['loyalty_multiplier_standard'] ?? '1.0') ?>" min="0.5" max="10.0" required>
                        </div>
                        <div>
                            <label class="form-label text-xs">Wax Discount %</label>
                            <input type="text" class="form-input" value="0% (None)" disabled style="background: var(--bg-surface); opacity: 0.7;">
                        </div>
                    </div>
                </div>

                <!-- Gold VIP Tier -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-left: 4px solid #f59e0b; border-radius: var(--radius-md); padding: 14px 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span class="badge badge-amber" style="font-weight: 800; font-size: 12px;">GOLD VIP TIER</span>
                            <span class="text-xs text-muted ml-2">Frequent loyal customers</span>
                        </div>
                    </div>
                    <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
                        <div>
                            <label class="form-label text-xs">Lifetime Points Required <span style="color: var(--danger);">*</span></label>
                            <input type="number" name="loyalty_tier_gold_min" class="form-input" style="font-weight: 700;" value="<?= htmlspecialchars($settings['loyalty_tier_gold_min'] ?? '200') ?>" min="10" required>
                        </div>
                        <div>
                            <label class="form-label text-xs">Points Multiplier <span style="color: var(--danger);">*</span></label>
                            <input type="number" step="0.1" name="loyalty_multiplier_gold" class="form-input" style="font-weight: 700;" value="<?= htmlspecialchars($settings['loyalty_multiplier_gold'] ?? '1.5') ?>" min="0.5" max="10.0" required>
                        </div>
                        <div>
                            <label class="form-label text-xs">Wax Discount % <span style="color: var(--danger);">*</span></label>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <input type="number" name="loyalty_wax_discount_gold" class="form-input" style="font-weight: 700;" value="<?= htmlspecialchars($settings['loyalty_wax_discount_gold'] ?? '10') ?>" min="0" max="100" required>
                                <span style="font-weight: 700; color: var(--text-muted);">%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Platinum Fleet Tier -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-left: 4px solid #0284c7; border-radius: var(--radius-md); padding: 14px 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span class="badge badge-primary" style="font-weight: 800; font-size: 12px;">PLATINUM FLEET TIER</span>
                            <span class="text-xs text-muted ml-2">Top VIPs &amp; Fleet owners</span>
                        </div>
                    </div>
                    <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
                        <div>
                            <label class="form-label text-xs">Lifetime Points Required <span style="color: var(--danger);">*</span></label>
                            <input type="number" name="loyalty_tier_platinum_min" class="form-input" style="font-weight: 700;" value="<?= htmlspecialchars($settings['loyalty_tier_platinum_min'] ?? '500') ?>" min="50" required>
                        </div>
                        <div>
                            <label class="form-label text-xs">Points Multiplier <span style="color: var(--danger);">*</span></label>
                            <input type="number" step="0.1" name="loyalty_multiplier_platinum" class="form-input" style="font-weight: 700;" value="<?= htmlspecialchars($settings['loyalty_multiplier_platinum'] ?? '2.0') ?>" min="0.5" max="10.0" required>
                        </div>
                        <div>
                            <label class="form-label text-xs">Wax Discount % <span style="color: var(--danger);">*</span></label>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <input type="number" name="loyalty_wax_discount_platinum" class="form-input" style="font-weight: 700;" value="<?= htmlspecialchars($settings['loyalty_wax_discount_platinum'] ?? '15') ?>" min="0" max="100" required>
                                <span style="font-weight: 700; color: var(--text-muted);">%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px 16px; margin: 16px 0; font-size: 13px; color: var(--text-muted);">
                <i class="fa-solid fa-circle-info mr-1" style="color: var(--primary);"></i>
                <strong>Automatic Tier Updates:</strong> When you update tier thresholds, all existing customers' membership levels are automatically refreshed based on their lifetime earned points.
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Loyalty &amp; Tier Rules
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 6B: PERFORMANCE & LEADERBOARD SETTINGS -->
<!-- ======================================================== -->
<div id="tab_performance" class="tab-pane" style="display: <?= ($activeTab === 'performance') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-gauge-high mr-2" style="color: #10b981;"></i>Performance Targets &amp; Staff Leaderboard
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Configure labor productivity targets, shop open capacity, and customize the optional staff rankings leaderboard.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_performance">
            <input type="hidden" name="tab" value="performance">

            <?php
            $targetCph = (float)($settings['cars_per_labor_hour_target'] ?? '2.0');
            $openHours = (float)($settings['shop_daily_open_hours'] ?? '10.0');
            $lbActive = ($settings['leaderboard_enabled'] ?? '1') === '1';
            $savedMetrics = json_decode($settings['leaderboard_metrics'] ?? '[]', true) ?: ['cars_per_hour', 'jobs_completed', 'efficiency_factor'];
            $savedRoles = json_decode($settings['leaderboard_visible_roles'] ?? '[]', true) ?: ['owner', 'manager'];
            ?>

            <!-- Section 1: Productivity Targets -->
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-bullseye text-primary"></i> Labor Productivity Targets
                </h3>

                <div class="grid grid-cols-2" style="gap: 16px;">
                    <div>
                        <label class="form-label">Cars per Labor Hour Target</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="number" step="0.1" min="0.5" max="10.0" name="cars_per_labor_hour_target" class="form-control" value="<?= htmlspecialchars((string)$targetCph) ?>" required>
                            <span class="text-xs text-muted" style="white-space: nowrap;">cars / hr</span>
                        </div>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            Target benchmark for total cars completed divided by total clocked staff labor hours.
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Shop Daily Operating Hours</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="number" step="0.5" min="4.0" max="24.0" name="shop_daily_open_hours" class="form-control" value="<?= htmlspecialchars((string)$openHours) ?>" required>
                            <span class="text-xs text-muted" style="white-space: nowrap;">hours / day</span>
                        </div>
                        <div class="text-xs text-muted" style="margin-top: 4px;">
                            Daily shop availability per bay (e.g. 10.0 hrs = 8:00 AM to 6:00 PM). Used to compute Bay Utilization %.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Optional Staff Leaderboard -->
            <div style="border-top: 1px solid var(--border); padding-top: 20px; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i> Optional Staff Leaderboard
                </h3>
                <p class="text-xs text-muted" style="margin-bottom: 16px;">
                    Rank washers and technicians to encourage team productivity. You choose which metrics are shown and which staff roles can see it.
                </p>

                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; color: var(--text-main); margin: 0;">
                        <input type="checkbox" name="leaderboard_enabled" value="1" <?= $lbActive ? 'checked' : '' ?> style="width: 18px; height: 18px; cursor: pointer;">
                        <span>Enable Staff Leaderboard</span>
                    </label>
                    <div class="text-xs text-muted" style="margin-left: 28px; margin-top: 4px;">
                        When checked, ranks staff on the Dashboard and Reporting page based on configured metrics.
                    </div>
                </div>

                <!-- Measures to Display -->
                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600;">Visible Performance Metrics</label>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 6px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_metrics[]" value="cars_per_hour" <?= in_array('cars_per_hour', $savedMetrics, true) ? 'checked' : '' ?>>
                            <span><strong>Cars per Labor Hour</strong> (Cars completed divided by clocked hours)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_metrics[]" value="jobs_completed" <?= in_array('jobs_completed', $savedMetrics, true) ? 'checked' : '' ?>>
                            <span><strong>Total Completed Jobs</strong> (Total vehicles washed / repaired)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_metrics[]" value="efficiency_factor" <?= in_array('efficiency_factor', $savedMetrics, true) ? 'checked' : '' ?>>
                            <span><strong>Rolling 7-Day Efficiency %</strong> (Standard time vs actual time)</span>
                        </label>
                    </div>
                </div>

                <!-- Audience: Who can see it -->
                <div>
                    <label class="form-label" style="font-weight: 600;">Who Can View the Leaderboard</label>
                    <div class="text-xs text-muted" style="margin-bottom: 8px;">
                        Strict security rule: By default, only Owner and Manager see staff metrics. You can optionally allow washers or technicians to view the public leaderboard rankings.
                    </div>
                    <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_visible_roles[]" value="owner" checked disabled>
                            <span><strong>Owner</strong> (Always enabled)</span>
                        </label>
                        <input type="hidden" name="leaderboard_visible_roles[]" value="owner">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_visible_roles[]" value="manager" <?= in_array('manager', $savedRoles, true) ? 'checked' : '' ?>>
                            <span>Manager</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_visible_roles[]" value="washer" <?= in_array('washer', $savedRoles, true) ? 'checked' : '' ?>>
                            <span>Washer</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_visible_roles[]" value="technical" <?= in_array('technical', $savedRoles, true) ? 'checked' : '' ?>>
                            <span>Technician</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="leaderboard_visible_roles[]" value="cashier" <?= in_array('cashier', $savedRoles, true) ? 'checked' : '' ?>>
                            <span>Cashier</span>
                        </label>
                    </div>
                </div>
            </div>

            <div style="border-top: 1px solid var(--border); padding-top: 16px; text-align: right;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Performance &amp; Leaderboard Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 7: TELEGRAM SETTINGS -->
<!-- ======================================================== -->
<div id="tab_telegram" class="tab-pane" style="display: <?= ($activeTab === 'telegram') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-brands fa-telegram mr-2" style="color: #0284c7;"></i>Telegram Alerts &amp; Notifications
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Send automatic alerts to your shop staff channel or customers when a vehicle wash or repair is finished.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_telegram">
            <input type="hidden" name="tab" value="telegram">

            <div class="form-group">
                <label class="form-label">Telegram Bot API Token</label>
                <input type="text" name="telegram_bot_token" class="form-input" style="font-family: var(--font-mono);" placeholder="e.g. 7123456789:AAH7Xy..." value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>">
                <div class="text-xs text-muted" style="margin-top: 4px;">Obtained from @BotFather on Telegram</div>
            </div>

            <div class="form-group">
                <label class="form-label">Alert Chat ID / Group ID / Channel</label>
                <input type="text" name="telegram_chat_id" class="form-input" style="font-family: var(--font-mono);" placeholder="e.g. -100123456789 or @aquagleam_alerts" value="<?= htmlspecialchars($settings['telegram_chat_id'] ?? '') ?>">
                <div class="text-xs text-muted" style="margin-top: 4px;">Channel username or numeric ID where notifications should be posted</div>
            </div>

            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: var(--radius-md); padding: 14px 18px; margin: 16px 0; color: #0369a1; font-size: 13px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong>Also accessible in Extras:</strong> Telegram alert status can also be managed and viewed under <a href="<?= APP_URL ?>/index.php?view=extras&tab=telegram" style="color: #0284c7; font-weight: 700; text-decoration: underline;">Owner &gt; Extras &amp; Stock</a>.
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" name="send_test" value="1" class="btn btn-secondary">
                    <i class="fa-solid fa-paper-plane mr-1"></i> Send Test Alert
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Telegram Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 6c: CLOUD SYNC CONFIGURATION -->
<!-- ======================================================== -->
<div id="tab_sync" class="tab-pane" style="display: <?= ($activeTab === 'sync') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                        <i class="fa-solid fa-cloud-arrow-up mr-2" style="color: #6366f1;"></i>Cloud Sync Configuration
                    </h2>
                    <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                        Configure secure shop-to-cloud bidirectional synchronization according to the SYNC_API standard.
                    </p>
                </div>
                <a href="index.php?view=sync_monitor" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-gauge-high mr-1"></i> Open Sync Monitor
                </a>
            </div>
        </div>

        <form method="POST" action="index.php?view=settings&tab=sync">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_cloud_sync">
            <input type="hidden" name="tab" value="sync">

            <?php if (empty($settings['cloud_api_url']) || empty($settings['cloud_api_token']) || empty($settings['cloud_api_secret'])): ?>
            <div class="alert alert-warning" style="background: rgba(245, 158, 11, 0.12); border-left: 4px solid #f59e0b; border-radius: 6px; padding: 14px 18px; margin-bottom: 20px; font-size: 13px; color: #b45309;">
                <strong style="display: flex; align-items: center; gap: 6px; font-size: 14px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px;"></i> Cloud Sync Not Configured
                </strong>
                <p style="margin: 6px 0 0 0; color: #92400e; line-height: 1.5;">
                    The Cloud API Endpoint URL, Token, and HMAC Secret Key are required to connect with the cloud master server. Data synchronization is currently paused until these credentials are saved.
                </p>
            </div>
            <?php endif; ?>

            <div style="background: rgba(99, 102, 241, 0.08); border-left: 4px solid #6366f1; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: var(--text-main);">
                <strong><i class="fa-solid fa-shield-halved mr-1"></i> Enterprise Cloud Security &amp; Offline Resiliency:</strong>
                <p style="margin: 4px 0 0 0; color: var(--text-muted);">
                    All outbound packets are compressed with <code>gzip</code> and verified with HMAC-SHA256 signatures, timestamps, and nonces. Critical catalog prices and permissions are checked every 30 seconds.
                </p>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-weight: 600; display: flex; align-items: center; justify-content: space-between; cursor: pointer;">
                    <span><i class="fa-solid fa-toggle-on mr-2" style="color: var(--primary);"></i>Enable Cloud Sync Integration</span>
                    <input type="checkbox" name="cloud_sync_enabled" value="1" <?= (($settings['cloud_sync_enabled'] ?? '1') === '1') ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                </label>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                    When enabled, the background agent sends pending sales, job orders, and stock updates every 3 to 5 minutes.
                </small>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px;">
                    Cloud API Endpoint URL <span style="color: red;">*</span>
                </label>
                <input type="url" name="cloud_api_url" class="form-control" value="<?= htmlspecialchars($settings['cloud_api_url'] ?? '') ?>" placeholder="https://api.carwash-cloud.com/v1/sync" style="font-family: monospace;">
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                    The remote cloud hub URL supporting the SYNC_API push, pull, and critical check endpoints.
                </small>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px;">
                        Shop ID Identifier <span style="color: red;">*</span>
                    </label>
                    <input type="text" name="cloud_shop_id" class="form-control" value="<?= htmlspecialchars($settings['cloud_shop_id'] ?? '') ?>" placeholder="SHOP-PP-001" style="font-family: monospace;">
                </div>

                <div class="form-group">
                    <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px;">
                        Cloud API Token <span style="color: red;">*</span>
                    </label>
                    <input type="text" name="cloud_api_token" class="form-control" value="<?= htmlspecialchars($settings['cloud_api_token'] ?? '') ?>" placeholder="token_..." style="font-family: monospace;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px;">
                    HMAC API Secret Key <span style="color: red;">*</span>
                </label>
                <div style="display: flex; gap: 8px;">
                    <input type="password" id="cloud_api_secret_input" name="cloud_api_secret" class="form-control" value="<?= htmlspecialchars($settings['cloud_api_secret'] ?? '') ?>" placeholder="sec_..." style="font-family: monospace; flex: 1;">
                    <button type="button" class="btn btn-secondary" onclick="const f=document.getElementById('cloud_api_secret_input'); f.type=(f.type==='password'?'text':'password');">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                    Used locally to sign every outbound request payload with HMAC-SHA256. Never exposed to unauthorized users.
                </small>
            </div>

            <div class="form-group" style="margin-bottom: 24px; padding: 12px; background: rgba(239, 68, 68, 0.05); border: 1px dashed rgba(239, 68, 68, 0.3); border-radius: 6px;">
                <label style="font-weight: 600; display: flex; align-items: center; justify-content: space-between; cursor: pointer; color: #dc2626;">
                    <span><i class="fa-solid fa-plane-slash mr-2"></i>Simulate Offline Mode (Shop Server Standalone)</span>
                    <input type="checkbox" name="cloud_offline_mode" value="1" <?= (($settings['cloud_offline_mode'] ?? '0') === '1') ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                </label>
                <small style="color: var(--text-muted); display: block; margin-top: 6px;">
                    When checked, network calls to the cloud are paused to simulate an internet outage. All POS sales, job orders, and stock adjustments continue working 100% offline and will auto-sync upon reconnection.
                </small>
            </div>

            <?php if (defined('APP_ENV') && APP_ENV === 'development'): ?>
            <div class="form-group" style="margin-bottom: 24px; padding: 12px; background: rgba(245, 158, 11, 0.05); border: 1px dashed rgba(245, 158, 11, 0.3); border-radius: 6px;">
                <label style="font-weight: 600; display: flex; align-items: center; justify-content: space-between; cursor: pointer; color: #b45309;">
                    <span><i class="fa-solid fa-lock-open mr-2"></i>Allow Insecure TLS (Development Only)</span>
                    <input type="checkbox" name="cloud_allow_insecure_tls" value="1" <?= (($settings['cloud_allow_insecure_tls'] ?? '0') === '1') ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                </label>
                <small style="color: var(--text-muted); display: block; margin-top: 6px;">
                    Honoured only when APP_ENV is 'development'. Disables cURL certificate verification for local HTTPS self-signed test environments.
                </small>
            </div>
            <?php endif; ?>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Cloud Sync Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 7: SYSTEM SETTINGS -->
<!-- ======================================================== -->
<div id="tab_system" class="tab-pane" style="display: <?= ($activeTab === 'system') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-sliders mr-2" style="color: var(--primary);"></i>System Configuration
            </h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                Default system language, demo login switch, and server time zone.
            </p>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_system">
            <input type="hidden" name="tab" value="system">

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Default System Language</label>
                    <select name="default_language" class="form-select">
                        <option value="en" <?= (($settings['default_language'] ?? 'en') === 'en') ? 'selected' : '' ?>>English (Default)</option>
                        <option value="km" <?= (($settings['default_language'] ?? '') === 'km') ? 'selected' : '' ?>>ភាសាខ្មែរ (Khmer Default)</option>
                    </select>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Initial language for new devices before manual user selection.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Application Time Zone</label>
                    <select name="app_timezone" class="form-select">
                        <?php
                        $tzOptions = [
                            'Asia/Phnom_Penh' => 'Asia/Phnom_Penh (Cambodia GMT+7)',
                            'Asia/Bangkok'    => 'Asia/Bangkok (Thailand GMT+7)',
                            'Asia/Singapore'  => 'Asia/Singapore (GMT+8)',
                            'UTC'             => 'UTC (Coordinated Universal Time)'
                        ];
                        $curTz = $settings['app_timezone'] ?? 'Asia/Phnom_Penh';
                        foreach ($tzOptions as $tzVal => $tzLabel):
                        ?>
                            <option value="<?= $tzVal ?>" <?= ($tzVal === $curTz) ? 'selected' : '' ?>><?= $tzLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Used for order timestamps, receipts, and daily shift calculations.</div>
                </div>
            </div>

            <div class="form-group" style="margin-top: 8px;">
                <label class="form-label">Test Mode &amp; 1-Click Demo Login Switch</label>
                <div style="position: relative; display: flex; align-items: center; max-width: 320px;">
                    <span style="position: absolute; left: 12px; pointer-events: none; color: var(--primary); font-size: 14px; z-index: 2;">
                        <i id="demo_mode_icon" class="fa-solid <?= (($settings['demo_mode'] ?? '0') === '1') ? 'fa-circle-check' : 'fa-lock' ?>"></i>
                    </span>
                    <?php $isOwnerUser = (Auth::getRole() === 'owner'); ?>
                    <select name="demo_mode" id="demo_mode_select" class="form-select" style="padding-left: 36px; width: 100%;" <?= $isOwnerUser ? '' : 'disabled title="Only Shop Owner can toggle Test Mode"' ?> onchange="document.getElementById('demo_mode_icon').className = 'fa-solid ' + (this.value === '1' ? 'fa-circle-check' : 'fa-lock')">
                        <option value="1" <?= (($settings['demo_mode'] ?? '0') === '1') ? 'selected' : '' ?>>Enabled (Show 1-click test buttons on login screen)</option>
                        <option value="0" <?= (($settings['demo_mode'] ?? '0') === '0') ? 'selected' : '' ?>>Disabled (Require actual staff username &amp; PIN)</option>
                    </select>
                </div>
                <div class="text-xs text-muted" style="margin-top: 4px;">
                    <?= $isOwnerUser ? 'Shop Owner can turn Test Mode on/off. When off, sample accounts and 1-click logins are disabled.' : '<span style="color: var(--danger);"><i class="fa-solid fa-lock mr-1"></i> Only the Shop Owner has permission to toggle Test Mode.</span>' ?>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save System Configuration
                </button>
            </div>
        </form>

        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
                <div>
                    <h3 style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 2px;">
                        <i class="fa-solid fa-database mr-1" style="color: var(--primary);"></i> Database Schema &amp; Migrations
                    </h3>
                    <p class="text-xs text-muted" style="margin: 0;">
                        Ensure all tables, columns, dynamic categories, and schema updates are synchronized.
                    </p>
                </div>
                <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="run_migrations">
                    <input type="hidden" name="tab" value="system">
                    <button type="submit" class="btn btn-sm btn-secondary">
                        <i class="fa-solid fa-rotate mr-1"></i> Check &amp; Run Migrations
                    </button>
                </form>
            </div>

            <!-- Database Backups & 14-Day Retention Management -->
            <?php
                require_once dirname(__DIR__, 2) . '/core/Backup.php';
                $availableBackups = Backup::getAvailableBackups();
            ?>
            <div style="background: var(--bg-surface-alt, #f8fafc); border: 1px solid var(--border); border-radius: var(--radius-md, 8px); padding: 18px; margin-top: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <h4 style="font-size: 14px; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-box-archive text-success"></i> Automated 2:00 AM Daily Backups
                            <span class="badge badge-success" style="font-size: 11px;">14 Days Retention</span>
                        </h4>
                        <p class="text-xs text-muted" style="margin: 4px 0 0 0;">
                            The database is automatically saved every night at 2:00 AM into <code>storage/backups/</code>. Backups older than 14 days are automatically pruned.
                        </p>
                    </div>

                    <div style="display: flex; gap: 8px;">
                        <form action="<?= APP_URL ?>/index.php?action=create_backup_now" method="POST" style="margin: 0;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-secondary">
                                <i class="fa-solid fa-bolt mr-1"></i> Create Backup Now
                            </button>
                        </form>
                        <a href="<?= APP_URL ?>/index.php?action=download_backup" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-download mr-1"></i> Download Live Backup (.sql)
                        </a>
                    </div>
                </div>

                <!-- Existing Backups List -->
                <?php if (!empty($availableBackups)): ?>
                    <div style="margin-top: 14px; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; background: #fff;">
                        <table class="table" style="width: 100%; margin: 0; font-size: 12px;">
                            <thead style="background: var(--bg-surface-alt, #f1f5f9);">
                                <tr>
                                    <th style="padding: 8px 12px;">Backup Filename</th>
                                    <th style="padding: 8px 12px; width: 140px;">Created Date</th>
                                    <th style="padding: 8px 12px; width: 90px; text-align: center;">Size</th>
                                    <th style="padding: 8px 12px; width: 110px; text-align: center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($availableBackups, 0, 7) as $b): ?>
                                    <tr>
                                        <td style="padding: 8px 12px; font-family: monospace;">
                                            <i class="fa-solid fa-file-lines text-muted mr-1"></i> <?= htmlspecialchars($b['filename']) ?>
                                            <?php if ($b['is_auto']): ?>
                                                <span class="badge badge-info" style="font-size: 10px; margin-left: 6px;">2:00 AM Auto</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 8px 12px;"><?= htmlspecialchars($b['created_at']) ?></td>
                                        <td style="padding: 8px 12px; text-align: center; font-weight: 600;"><?= htmlspecialchars($b['size_formatted']) ?></td>
                                        <td style="padding: 8px 12px; text-align: center;">
                                            <a href="<?= APP_URL ?>/index.php?action=download_backup&file=<?= urlencode($b['filename']) ?>" class="btn btn-sm btn-secondary" style="padding: 2px 8px; font-size: 11px;" title="Download this file">
                                                <i class="fa-solid fa-download"></i> Download
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Step-by-Step Restoration Guide -->
                <details style="margin-top: 14px; background: rgba(14, 165, 233, 0.06); border: 1px solid rgba(14, 165, 233, 0.25); border-radius: 6px; padding: 10px 14px; font-size: 13px;">
                    <summary style="cursor: pointer; font-weight: 700; color: #0369a1;">
                        <i class="fa-solid fa-book-medical mr-1"></i> Quick Step-by-Step Guide: How to Restore a Database Backup
                    </summary>
                    <div style="margin-top: 10px; color: var(--text-main); line-height: 1.6;">
                        <p style="margin: 0 0 8px 0;">If you ever replace the mini-PC or need to roll back to a previous day:</p>
                        <ol style="margin: 0 0 10px 20px; padding: 0;">
                            <li><strong>Open Command Prompt as Administrator</strong> (Windows Key &rarr; type <code>cmd</code> &rarr; Run as Administrator).</li>
                            <li><strong>Navigate to MySQL bin directory</strong>:
                                <pre style="background: #1e293b; color: #f8fafc; padding: 6px 10px; border-radius: 4px; font-size: 12px; margin: 4px 0;">cd C:\xampp\mysql\bin</pre>
                            </li>
                            <li><strong>Run the single restore command</strong> (pointing to your latest backup file):
                                <pre style="background: #1e293b; color: #f8fafc; padding: 6px 10px; border-radius: 4px; font-size: 12px; margin: 4px 0;">mysql -u root -p carwash_db &lt; "C:\xampp\htdocs\Car-WashService\storage\backups\CarWash_AutoBackup_2026-10-28.sql"</pre>
                            </li>
                            <li>Type your MySQL root password when prompted and press <strong>Enter</strong>. In 5&ndash;10 seconds, all tables and historical sales will be completely restored.</li>
                        </ol>
                    </div>
                </details>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- GROUP 8: RECENT CHANGES AUDIT HISTORY -->
<!-- ======================================================== -->
<div id="tab_history" class="tab-pane" style="display: <?= ($activeTab === 'history') ? 'block' : 'none' ?>;">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 20px;">
            <div>
                <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                    <i class="fa-solid fa-clock-rotate-left mr-2" style="color: var(--primary);"></i>Settings Change Audit Log
                </h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                    Permanent audit history tracking who changed which setting and the exact time it was updated.
                </p>
            </div>
            <span class="badge badge-primary"><?= count($historyList) ?> Recent Records</span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 170px;">Date &amp; Time</th>
                        <th style="width: 220px;">Setting Key</th>
                        <th>Previous Value</th>
                        <th>New Value</th>
                        <th style="width: 160px;">Changed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyList)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                No changes have been recorded in the audit history yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyList as $h): ?>
                        <tr>
                            <td>
                                <strong><?= date('d/m/Y H:i:s', strtotime($h['changed_at'])) ?></strong>
                            </td>
                            <td>
                                <strong style="color: var(--text-main);"><?= htmlspecialchars($keyLabels[$h['setting_key']] ?? $h['setting_key']) ?></strong>
                                <div class="text-xs text-muted" style="font-family: var(--font-mono);"><?= htmlspecialchars($h['setting_key']) ?></div>
                            </td>
                            <td>
                                <?php if ($h['old_value'] === null || $h['old_value'] === ''): ?>
                                    <span class="text-muted" style="font-style: italic;">(empty)</span>
                                <?php else: ?>
                                    <span style="font-family: var(--font-mono); font-size: 12px; color: #dc2626; background: #fef2f2; padding: 2px 6px; border-radius: 4px; word-break: break-all;">
                                        <?= htmlspecialchars(mb_strimwidth((string)$h['old_value'], 0, 60, '...')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-family: var(--font-mono); font-size: 12px; color: #16a34a; background: #f0fdf4; padding: 2px 6px; border-radius: 4px; word-break: break-all; font-weight: 700;">
                                    <?= htmlspecialchars(mb_strimwidth((string)$h['new_value'], 0, 60, '...')) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><i class="fa-solid fa-user-check mr-1 text-muted"></i><?= htmlspecialchars($h['changed_by_name'] ?? 'Owner') ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Tab Switching
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
    const target = document.getElementById('tab_' + tabId);
    if (target) {
        target.style.display = 'block';
    }
    // Update button active states
    document.querySelectorAll('[onclick^="switchTab"]').forEach(btn => {
        if (btn.getAttribute('onclick').includes(tabId)) {
            btn.className = 'btn btn-sm btn-primary';
        } else {
            btn.className = 'btn btn-sm btn-secondary';
        }
    });
    // Update browser URL query param without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url);
}

// Live Exchange Rate Calculator & Confirmation
const rateInput = document.getElementById('new_exchange_rate_input');
if (rateInput) {
    rateInput.addEventListener('input', function() {
        const val = parseFloat(this.value) || 0;
        if (document.getElementById('prev_2_50')) document.getElementById('prev_2_50').innerText = Math.round(2.50 * val).toLocaleString() + ' ៛';
        if (document.getElementById('prev_5_00')) document.getElementById('prev_5_00').innerText = Math.round(5.00 * val).toLocaleString() + ' ៛';
        if (document.getElementById('prev_10_00')) document.getElementById('prev_10_00').innerText = Math.round(10.00 * val).toLocaleString() + ' ៛';
        if (document.getElementById('prev_25_00')) document.getElementById('prev_25_00').innerText = Math.round(25.00 * val).toLocaleString() + ' ៛';
    });
}

function confirmExchangeRateChange(e) {
    const curVal = document.getElementById('current_exchange_rate_val').value;
    const newVal = document.getElementById('new_exchange_rate_input').value;
    if (curVal !== newVal) {
        const msg = "WARNING: You are changing the exchange rate from " + parseInt(curVal).toLocaleString() + " KHR to " + parseInt(newVal).toLocaleString() + " KHR per USD.\n\n" +
                    "• All NEW orders, POS syncs, and financial reports will calculate with " + parseInt(newVal).toLocaleString() + " KHR.\n" +
                    "• Past completed orders will safely keep their original KHR totals.\n\n" +
                    "Are you sure you want to save this new exchange rate?";
        if (!confirm(msg)) {
            e.preventDefault();
            return false;
        }
    }
    return true;
}

// Modal Helpers
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

// Copy TV URL Helper
function copyTvUrl() {
    const input = document.getElementById('tvWebAddressInput');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(() => {
            alert('TV web address copied to clipboard!');
        }).catch(() => {
            document.execCommand('copy');
            alert('TV web address copied to clipboard!');
        });
    } else {
        document.execCommand('copy');
        alert('TV web address copied to clipboard!');
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/layouts/footer.php';
