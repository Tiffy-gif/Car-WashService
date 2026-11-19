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
            max-width: 460px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 32px 28px;
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

        /* Method Tab Switcher (Project Pill Style) */
        .login-method-nav {
            display: flex;
            background: var(--bg-subtle);
            padding: 4px;
            border-radius: var(--radius-md);
            gap: 4px;
            margin-bottom: 20px;
        }
        .login-method-btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: var(--radius-sm);
            background: transparent;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.15s ease;
            user-select: none;
        }
        .login-method-btn.active {
            background: var(--bg-surface);
            color: var(--primary);
            font-weight: 700;
            box-shadow: var(--shadow-sm);
        }

        /* PIN Dots Indicator */
        .pin-dots-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            margin: 6px 0 22px 0;
        }
        .pin-dot-circle {
            width: 13px;
            height: 13px;
            border-radius: 50%;
            border: 2px solid var(--border);
            background: transparent;
            transition: all 0.15s ease;
        }
        .pin-dot-circle.filled {
            background: var(--primary);
            border-color: var(--primary);
            transform: scale(1.15);
            box-shadow: 0 0 6px rgba(2, 132, 199, 0.4);
        }

        /* Numpad Keypad */
        .numpad-wrap {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .numpad-btn-cell {
            height: 48px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--bg-surface);
            font-size: 19px;
            font-weight: 700;
            color: var(--text-main);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.1s ease;
            user-select: none;
            box-shadow: var(--shadow-xs, 0 1px 2px rgba(0,0,0,0.03));
        }
        .numpad-btn-cell:hover {
            background: var(--bg-subtle);
            border-color: var(--primary-light);
            transform: translateY(-1px);
        }
        .numpad-btn-cell:active {
            background: var(--border);
            transform: scale(0.97);
        }
        .numpad-btn-cell.action-cell {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--bg-subtle);
        }
        .numpad-btn-cell.action-cell:hover {
            color: var(--text-main);
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
        <!-- Project Header: Brand Icon, Shop Name, Subtitle, and Language Switcher -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px;">
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
                    <h1 style="font-size: 18px; font-weight: 700; color: var(--text-main); margin: 0; line-height: 1.3;"><?= htmlspecialchars($loginTitle) ?></h1>
                    <p style="font-size: 12.5px; color: var(--text-muted); margin: 2px 0 0 0;"><?= htmlspecialchars($loginSub) ?></p>
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

        <!-- System Alerts -->
        <?php if ($msg === 'pin_changed'): ?>
            <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-check"></i>
                <span>Your PIN has been changed successfully. Please log in with your new PIN.</span>
            </div>
        <?php elseif ($msg === 'logged_out'): ?>
            <div style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-check"></i>
                <span>You have been logged out successfully. / បានចាកចេញពីប្រព័ន្ធដោយជោគជ័យ។</span>
            </div>
        <?php elseif ($msg === 'idle_timeout'): ?>
            <div style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-clock"></i>
                <span>Session logged out due to inactivity. Please sign in again.</span>
            </div>
        <?php elseif ($msg === 'session_invalid'): ?>
            <div style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Your session has expired. Please sign in to continue.</span>
            </div>
        <?php endif; ?>

        <?php if ($error === 'account_locked'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); border: 1px solid #fecaca; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.4;">
                <i class="fa-solid fa-lock" style="font-size: 16px; margin-top: 2px;"></i>
                <div>
                    <strong>Account Locked / គណនីត្រូវបានចាក់សោ:</strong><br>
                    5 wrong attempts. Locked for <strong><?= max(1, $remMins) ?> minutes</strong>.
                </div>
            </div>
        <?php elseif ($error === 'customer_no_login'): ?>
            <div style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px;">
                <i class="fa-solid fa-tv" style="font-size: 16px; margin-top: 2px;"></i>
                <div>
                    <strong>Customer Account:</strong> The Customer role does not log into the back office. Please use the public <a href="<?= APP_URL ?>/tv.php" target="_blank" style="font-weight: 700; text-decoration: underline;">Queue TV Screen (tv.php)</a>.
                </div>
            </div>
        <?php elseif ($error === 'demo_disabled'): ?>
            <div style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px; margin-top: 2px;"></i>
                <div>
                    <strong>Test Mode Disabled:</strong> The Shop Owner has disabled Test Mode. Real staff credentials required.
                </div>
            </div>
        <?php elseif ($error === 'ip_locked'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-hand"></i>
                <div><strong>Rate Limit Exceeded:</strong> Please wait <?= $remMins ?> minutes before trying again.</div>
            </div>
        <?php elseif ($error === 'account_inactive'): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-ban"></i>
                <div>This staff account is deactivated. Please contact your manager.</div>
            </div>
        <?php elseif ($error): ?>
            <div style="background: var(--danger-bg); color: var(--danger); padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div>
                    Invalid username or PIN/Password.
                    <?php if ($remAtt !== null): ?>
                        <span style="font-weight: 700;">(<?= $remAtt ?> attempts left)</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form Login -->
        <form id="loginForm" action="<?= APP_URL ?>/index.php?action=login" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="auth_type" id="authType" value="pin">
            <input type="hidden" name="pin" id="pinHiddenInput" value="">

            <!-- Username Field -->
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" for="username"><?= htmlspecialchars(__('username')) ?></label>
                <div style="position: relative;">
                    <i class="fa-solid fa-user" style="position: absolute; left: 16px; top: 14px; color: var(--text-muted);"></i>
                    <input type="text" id="username" name="username" class="form-input" style="padding-left: 44px;" placeholder="Username (e.g. owner, cashier, admin)" required autofocus value="<?= htmlspecialchars($_GET['user'] ?? '') ?>">
                </div>
            </div>

            <!-- Login Method Segmented Nav (PIN vs Password) -->
            <div class="login-method-nav">
                <button type="button" class="login-method-btn active" id="btnPinTab" onclick="selectLoginMethod('pin')">
                    <i class="fa-solid fa-key" style="color: var(--primary);"></i> 6-Digit PIN
                </button>
                <button type="button" class="login-method-btn" id="btnPasswordTab" onclick="selectLoginMethod('password')">
                    <i class="fa-solid fa-lock" style="color: var(--text-muted);"></i> Password
                </button>
            </div>

            <!-- PANE 1: PIN NUMPAD -->
            <div id="panePin" style="display: block;">
                <!-- 6 PIN Dots Indicator -->
                <div class="pin-dots-row" id="pinDotsRow">
                    <div class="pin-dot-circle" id="dot-0"></div>
                    <div class="pin-dot-circle" id="dot-1"></div>
                    <div class="pin-dot-circle" id="dot-2"></div>
                    <div class="pin-dot-circle" id="dot-3"></div>
                    <div class="pin-dot-circle" id="dot-4"></div>
                    <div class="pin-dot-circle" id="dot-5"></div>
                </div>

                <!-- On-Screen Keypad -->
                <div class="numpad-wrap">
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('1')">1</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('2')">2</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('3')">3</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('4')">4</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('5')">5</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('6')">6</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('7')">7</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('8')">8</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('9')">9</button>
                    <button type="button" class="numpad-btn-cell action-cell" onclick="handleNumpadClear()" title="Clear PIN">C</button>
                    <button type="button" class="numpad-btn-cell" onclick="handleNumpadDigit('0')">0</button>
                    <button type="button" class="numpad-btn-cell action-cell" onclick="handleNumpadBackspace()" title="Delete">
                        <i class="fa-solid fa-delete-left"></i>
                    </button>
                </div>
            </div>

            <!-- PANE 2: PASSWORD INPUT -->
            <div id="panePassword" style="display: none;">
                <div class="form-group" style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label class="form-label" for="passwordInput" style="margin-bottom: 0;">Password / ពាក្យសម្ងាត់</label>
                    </div>
                    <div style="position: relative;">
                        <i class="fa-solid fa-lock" style="position: absolute; left: 16px; top: 14px; color: var(--text-muted);"></i>
                        <input type="password" id="passwordInput" name="password" class="form-input" style="padding-left: 44px; padding-right: 44px;" placeholder="Enter password" autocomplete="current-password" disabled>
                        <button type="button" onclick="togglePasswordVisibility()" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 6px;" title="Show/Hide">
                            <i class="fa-solid fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg" id="pwdSubmitBtn" style="width: 100%;">
                    <i class="fa-solid fa-arrow-right-to-bracket mr-1"></i> Sign In / ចូលប្រើប្រាស់
                </button>
            </div>
        </form>

        <!-- Test Mode / 1-Click Demo Login Box -->
        <?php if ($demoMode === '1' && defined('ALLOW_DEMO_LOGIN') && ALLOW_DEMO_LOGIN && defined('APP_ENV') && APP_ENV !== 'production'): ?>
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
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=cashier" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-cash-register" style="color: var(--success);"></i> Cashier <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=stock_control" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-boxes-stacked" style="color: #d97706;"></i> Warehouse <span class="text-xs text-muted ml-auto">(123456)</span>
                </a>
                <a href="<?= APP_URL ?>/index.php?action=demo_login&role=technical" class="btn btn-secondary btn-sm" style="justify-content: flex-start; font-size: 11px;">
                    <i class="fa-solid fa-wrench" style="color: #ea580c;"></i> Technical <span class="text-xs text-muted ml-auto">(123456)</span>
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
        <div style="margin-top: 20px; text-align: center;">
            <a href="<?= APP_URL ?>/tv.php" target="_blank" class="text-xs text-muted" style="text-decoration: underline;">
                <i class="fa-solid fa-tv mr-1"></i> View Customer Queue TV (tv.php)
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
let currentMethod = 'pin';
let currentPin = '';
const PIN_MAX_LENGTH = 6;

function selectLoginMethod(method) {
    currentMethod = method;
    document.getElementById('authType').value = method;

    const btnPin = document.getElementById('btnPinTab');
    const btnPwd = document.getElementById('btnPasswordTab');
    const panePin = document.getElementById('panePin');
    const panePassword = document.getElementById('panePassword');
    const pwdInput = document.getElementById('passwordInput');
    const pinHiddenInput = document.getElementById('pinHiddenInput');

    if (method === 'pin') {
        btnPin.classList.add('active');
        btnPwd.classList.remove('active');
        btnPin.querySelector('i').style.color = 'var(--primary)';
        btnPwd.querySelector('i').style.color = 'var(--text-muted)';
        panePin.style.display = 'block';
        panePassword.style.display = 'none';
        if (pinHiddenInput) pinHiddenInput.disabled = false;
        if (pwdInput) {
            pwdInput.removeAttribute('required');
            pwdInput.disabled = true;
        }
    } else {
        btnPwd.classList.add('active');
        btnPin.classList.remove('active');
        btnPwd.querySelector('i').style.color = 'var(--primary)';
        btnPin.querySelector('i').style.color = 'var(--text-muted)';
        panePin.style.display = 'none';
        panePassword.style.display = 'block';
        if (pinHiddenInput) pinHiddenInput.disabled = true;
        if (pwdInput) {
            pwdInput.disabled = false;
            pwdInput.setAttribute('required', 'required');
            pwdInput.focus();
        }
    }
}

function updatePinDots() {
    for (let i = 0; i < PIN_MAX_LENGTH; i++) {
        const dot = document.getElementById('dot-' + i);
        if (dot) {
            if (i < currentPin.length) {
                dot.classList.add('filled');
            } else {
                dot.classList.remove('filled');
            }
        }
    }
    const hiddenInput = document.getElementById('pinHiddenInput');
    if (hiddenInput) {
        hiddenInput.value = currentPin;
    }
}

function handleNumpadDigit(digit) {
    if (currentPin.length < PIN_MAX_LENGTH) {
        currentPin += digit;
        updatePinDots();

        if (currentPin.length === PIN_MAX_LENGTH) {
            const usernameInput = document.getElementById('username');
            if (!usernameInput || !usernameInput.value.trim()) {
                if (usernameInput) {
                    usernameInput.focus();
                    usernameInput.style.borderColor = 'var(--danger)';
                    setTimeout(() => { usernameInput.style.borderColor = 'var(--border)'; }, 1200);
                }
                return;
            }
            setTimeout(() => {
                document.getElementById('loginForm').submit();
            }, 180);
        }
    }
}

function handleNumpadClear() {
    currentPin = '';
    updatePinDots();
}

function handleNumpadBackspace() {
    if (currentPin.length > 0) {
        currentPin = currentPin.slice(0, -1);
        updatePinDots();
    }
}

function togglePasswordVisibility() {
    const input = document.getElementById('passwordInput');
    const icon = document.getElementById('eyeIcon');
    if (!input || !icon) return;

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Physical Keyboard Listener
document.addEventListener('keydown', function(e) {
    if (currentMethod === 'pin') {
        const activeId = document.activeElement ? document.activeElement.id : '';

        // Allow normal typing in username field
        if (activeId === 'username') {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('username').blur();
            }
            return;
        }

        if (e.key >= '0' && e.key <= '9') {
            e.preventDefault();
            handleNumpadDigit(e.key);
        } else if (e.key === 'Backspace') {
            e.preventDefault();
            handleNumpadBackspace();
        } else if (e.key === 'Escape' || e.key === 'c' || e.key === 'C') {
            e.preventDefault();
            handleNumpadClear();
        } else if (e.key === 'Enter') {
            if (currentPin.length === PIN_MAX_LENGTH) {
                e.preventDefault();
                document.getElementById('loginForm').submit();
            }
        }
    }
});
</script>
</body>
</html>
