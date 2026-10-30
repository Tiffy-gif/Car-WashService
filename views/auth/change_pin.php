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

$error = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New 6-Digit PIN - <?= htmlspecialchars($loginTitle) ?></title>
    <?php if (!empty($loginLogo)): ?>
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/<?= htmlspecialchars($loginLogo) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        .pin-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(135deg, var(--bg-body) 0%, var(--bg-subtle) 100%);
        }
        .pin-card {
            width: 100%;
            max-width: 460px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 36px 32px;
            box-shadow: var(--shadow-lg);
        }
        .pin-input {
            letter-spacing: 6px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="pin-wrap">
    <div class="pin-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="brand-icon" style="background: var(--primary-light, #e0f2fe); color: var(--primary); display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: var(--radius-md);">
                    <i class="fa-solid fa-key" style="font-size: 20px;"></i>
                </div>
                <div>
                    <h1 style="font-size: 19px; font-weight: 700; color: var(--text-main);">Change Your PIN</h1>
                    <p style="font-size: 13px; color: var(--text-muted);">A password reset was requested for your account</p>
                </div>
            </div>
        </div>

        <div style="background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 14px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;">
            <i class="fa-solid fa-shield-halved" style="margin-top: 2px; font-size: 15px;"></i>
            <div>
                <strong>Security Requirement:</strong> An Owner or System Admin has reset your PIN. You must choose a new <strong>6-digit numeric PIN</strong> before you can access the back office.
            </div>
        </div>

        <?php if ($error === 'invalid_length'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 14px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                PIN must be exactly 6 digits (numbers 0-9 only).
            </div>
        <?php elseif ($error === 'mismatch'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 14px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                The confirmation PIN does not match. Please re-enter both carefully.
            </div>
        <?php elseif ($error === 'csrf'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 14px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                Session token expired. Please refresh and try again.
            </div>
        <?php endif; ?>

        <form action="<?= APP_URL ?>/index.php?action=update_pin" method="POST">
            <?= csrf_field() ?>

            <div class="form-group" style="margin-bottom: 18px;">
                <label class="form-label" for="new_pin">New 6-Digit PIN</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-lock" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="password" id="new_pin" name="new_pin" class="form-input pin-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="••••••" required autofocus style="padding-left: 44px;">
                </div>
                <small class="text-muted" style="font-size: 12px; margin-top: 4px; display: block;">Enter exactly 6 digits (e.g. 583920)</small>
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label class="form-label" for="confirm_pin">Confirm New 6-Digit PIN</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-shield-check" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                    <input type="password" id="confirm_pin" name="confirm_pin" class="form-input pin-input" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="••••••" required style="padding-left: 44px;">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                <i class="fa-solid fa-circle-check mr-1"></i> Save New PIN &amp; Continue
            </button>
        </form>

        <div style="margin-top: 18px; text-align: center;">
            <a href="<?= APP_URL ?>/index.php?action=logout" class="text-muted text-sm" style="text-decoration: underline;">
                <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i> Cancel and Sign Out
            </a>
        </div>
    </div>
</div>
</body>
</html>
