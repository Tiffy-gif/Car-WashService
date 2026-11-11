<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Language::init();
$currentLang = Language::current();

$loginLogo = get_setting('shop_logo', '');
$loginNameEn = get_setting('shop_name_en', 'AquaGleam Car Wash & Detailing');
$loginNameKm = get_setting('shop_name_km', 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប');
$loginTitle = ($currentLang === 'km') ? ($loginNameKm ?: $loginNameEn ?: __('app_name')) : ($loginNameEn ?: $loginNameKm ?: __('app_name'));
$loginSub = ($currentLang === 'km') ? ($loginNameEn ?: __('app_subtitle')) : ($loginNameKm ?: __('app_subtitle'));

$error = $_GET['error'] ?? null;
$remMins = (int)($_GET['rem'] ?? 10);
$remAtt = isset($_GET['rem_att']) ? (int)$_GET['rem_att'] : null;
$msg = $_GET['msg'] ?? null;
$demoMode = get_setting('demo_mode', '0');
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('login')) ?> - <?= htmlspecialchars($loginTitle) ?></title>
    <?php if (!empty($loginLogo)): ?>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/<?= htmlspecialchars($loginLogo) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(135deg, var(--bg-body) 0%, var(--bg-subtle) 100%);
        }
        .login-card {
            width: 100%;
            max-width: 480px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 36px 32px;
            box-shadow: var(--shadow-lg);
        }
        .demo-box {
            margin-top: 24px;
            padding: 16px;
            background: var(--bg-subtle);
            border-radius: var(--radius-md);
            border: 1px dashed var(--border);
        }
        .demo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }
        .pin-input {
            letter-spacing: 4px;
            font-weight: 700;
        }
    </style>
</head>
<body>
<?php if ($demoMode === '1' && defined('ALLOW_DEMO_LOGIN') && ALLOW_DEMO_LOGIN && defined('APP_ENV') && APP_ENV !== 'production'): ?>
<div style="background: #dc2626; color: #ffffff; padding: 10px 16px; text-align: center; font-weight: 700; font-size: 13px; letter-spacing: 0.5px; z-index: 99999; box-shadow: 0 2px 4px rgba(0,0,0,0.2); width: 100%;">
    <i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> DEMO MODE ON - turn off before going live
</div>
<?php endif; ?>
<div class="login-wrap">
    <div class="login-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <?php if (!empty($loginLogo)): ?>
                    <div class="brand-icon" style="background: #ffffff; border: 1px solid var(--border); overflow: hidden; display: flex; align-items: center; justify-content: center; padding: 2px;">
                        <img src="<?= APP_URL ?>/<?= htmlspecialchars($loginLogo) ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    </div>
                <?php else: ?>
                    <div class="brand-icon">
                        <i class="fa-solid fa-car-wash"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h1 style="font-size: 19px; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($loginTitle) ?></h1>
                    <p style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($loginSub) ?></p>
                </div>
            </div>
            <div>
                <?php if ($currentLang === 'en'): ?>
                    <a href="?lang=km" class="btn btn-secondary btn-sm" title="ភាសាខ្មែរ"><i class="fa-solid fa-globe mr-1"></i> KM</a>
                <?php else: ?>
                    <a href="?lang=en" class="btn btn-secondary btn-sm" title="English"><i class="fa-solid fa-globe mr-1"></i> EN</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($msg === 'pin_changed'): ?>
            <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-check"></i>
                Your PIN has been changed successfully. Please log in with your new PIN.
            </div>
        <?php elseif ($msg === 'logged_out'): ?>
            <div style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-check"></i>
                You have been logged out successfully. / បានចាកចេញពីប្រព័ន្ធដោយជោគជ័យ។
            </div>
        <?php elseif ($msg === 'idle_timeout'): ?>
            <div style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clock"></i>
                Session logged out due to inactivity. Please sign in again. / បានចាកចេញដោយស្វ័យប្រវត្តដោយសារគ្មានសកម្មភាព។
            </div>
        <?php elseif ($msg === 'session_invalid'): ?>
            <div style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Your session has expired. Please sign in to continue. / វគ្គការងាររបស់អ្នកបានផុតកំណត់។ សូមចូលប្រើប្រាស់ឡើងវិញ។
            </div>
        <?php endif; ?>

        <?php if ($error === 'account_locked'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; padding: 14px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;">
                <i class="fa-solid fa-lock" style="font-size: 16px; margin-top: 2px;"></i>
                <div>
                    <strong>Account Locked:</strong> Too many incorrect tries (5 wrong attempts). This user is locked for <strong><?= max(1, $remMins) ?> more minutes</strong>. An Owner or System Admin can unlock or reset the PIN.
                </div>
            </div>
        <?php elseif ($error === 'customer_no_login'): ?>
            <div style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 14px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;">
                <i class="fa-solid fa-tv" style="font-size: 16px; margin-top: 2px;"></i>
                <div>
                    <strong>Customer Account:</strong> The Customer role does not log into the back office. Please use the public <a href="<?= APP_URL ?>/tv.php" target="_blank" style="font-weight: 700; text-decoration: underline;">Queue TV Screen (tv.php)</a>.
                </div>
            </div>
        <?php elseif ($error === 'demo_disabled'): ?>
            <div style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a; padding: 14px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px; margin-top: 2px;"></i>
                <div>
                    <strong>Test Mode Disabled:</strong> The Shop Owner has disabled Test Mode. 1-Click demo logins and sample accounts are turned off. Real staff credentials are required.
                </div>
            </div>
        <?php elseif ($error === 'ip_locked'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-hand"></i>
                <div>
                    <strong>Rate Limit Exceeded:</strong> Too many failed login attempts from your IP address. Please wait <?= $remMins ?> minutes before trying again.
                </div>
            </div>
        <?php elseif ($error === 'account_inactive'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 14px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-ban"></i>
                This staff account is deactivated. Please contact your manager or System Admin.
            </div>
        <?php elseif ($error): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div>
                    Invalid username or 6-digit PIN.
                    <?php if ($remAtt !== null): ?>
                        <span style="font-weight: 700;"><?= $remAtt ?> attempts left</span> before a 10-minute lockout.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form action="<?= APP_URL ?>/index.php?action=login" method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label" for="username"><?= htmlspecialchars(__('username')) ?></label>
                <div style="position: relative;">
                    <i class="fa-solid fa-user" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="text" id="username" name="username" class="form-input" style="padding-left: 44px;" placeholder="Username (e.g. owner, cashier, admin)" required autofocus>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="form-label" for="pin" style="margin-bottom: 0;">6-Digit Staff PIN</label>
                    <span class="text-xs text-muted"><i class="fa-solid fa-shield-halved mr-1"></i> 6 digits</span>
                </div>
                <div style="position: relative;">
                    <i class="fa-solid fa-key" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="password" id="pin" name="pin" class="form-input pin-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" style="padding-left: 44px;" placeholder="••••••" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                <i class="fa-solid fa-arrow-right-to-bracket mr-1"></i> Sign In with PIN
            </button>
        </form>

        <?php if ($demoMode === '1' && defined('ALLOW_DEMO_LOGIN') && ALLOW_DEMO_LOGIN && defined('APP_ENV') && APP_ENV !== 'production'): ?>
        <!-- Quick 1-Click Demo Login for Back Office Roles (Active in Test Mode) -->
        <div class="demo-box">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 13px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-bolt" style="color: var(--warning);"></i> Test Mode: 1-Click Demo Login
                </div>
                <span class="badge badge-success" style="font-size: 10px;">Test Mode ON</span>
            </div>
            <p style="font-size: 11px; color: var(--text-muted); margin-top: 4px; margin-bottom: 0;">
                Owner can toggle Test Mode OFF in Settings. All sample staff use 6-digit PINs.
            </p>

            <div class="demo-grid">
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=system_admin" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-sliders" style="color: #6366f1;"></i> System Admin <span class="text-xs text-muted ml-auto">(111111)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=owner" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-user-tie" style="color: var(--primary);"></i> Owner <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=manager" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-briefcase" style="color: #0284c7;"></i> Manager <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=crm" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-users-viewfinder" style="color: #0d9488;"></i> CRM Coord <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=cashier" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-cash-register" style="color: var(--success);"></i> Cashier <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=stock_control" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-boxes-stacked" style="color: #d97706;"></i> Warehouse <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=technical" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-wrench" style="color: #ea580c;"></i> Technical <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=washer" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-soap" style="color: #9333ea;"></i> Washer <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=wash_supervisor" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px; grid-column: span 2;">
                    <i class="fa-solid fa-user-shield" style="color: #0284c7;"></i> Wash Supervisor <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
            </div>

            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed var(--border); display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 11px; color: var(--text-muted);"><i class="fa-solid fa-tv mr-1"></i> Customer TV Screen:</span>
                <a href="<?= APP_URL ?>/tv.php" target="_blank" class="text-xs" style="font-weight: 700; color: var(--primary); text-decoration: underline;">
                    Open Queue TV (tv.php) &rarr;
                </a>
            </div>
        </div>
        <?php else: ?>
        <div style="margin-top: 20px; text-align: center; padding: 10px; background: var(--bg-subtle); border-radius: var(--radius-md); font-size: 12px; color: var(--text-muted);">
            <i class="fa-solid fa-lock mr-1"></i> Test Mode is OFF. Staff username &amp; 6-digit PIN required.
        </div>
        <div style="margin-top: 10px; text-align: center;">
            <a href="<?= APP_URL ?>/tv.php" target="_blank" class="text-xs text-muted" style="text-decoration: underline;">
                <i class="fa-solid fa-tv mr-1"></i> View Customer Queue TV (tv.php)
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
