<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';

Auth::requirePermission('pos_devices.list');
$pageTitle = 'Connected POS Devices';
$pdo = Database::getInstance();

$message = '';
$messageType = 'success';
$newlyCreatedApiKey = '';
$newlyCreatedDeviceName = '';

if (isset($csrfError)) {
    $message = $csrfError;
    $messageType = 'danger';
}

// Handle Actions: Add Device, Block Device, Unblock Device, Regenerate Key, Delete Device
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($csrfError)) {
    $action = $_POST['action'] ?? '';

    // 1. Register New POS Device
    if ($action === 'register_device') {
        if (!Auth::can('pos_devices.add')) {
            $message = 'Permission denied: Cannot register new POS device.';
            $messageType = 'danger';
        } else {
            $name = trim($_POST['device_name'] ?? '');
            $uid = strtoupper(trim($_POST['device_uid'] ?? ''));
            $location = trim($_POST['location'] ?? 'Main Counter');

            if ($name === '' || $uid === '') {
                $message = 'Device Name and Device ID are required.';
                $messageType = 'danger';
            } else {
                // Check UID uniqueness
                $check = $pdo->prepare("SELECT COUNT(*) FROM pos_devices WHERE device_uid = ? AND status != 'inactive'");
                $check->execute([$uid]);
                if ((int)$check->fetchColumn() > 0) {
                    $message = "A device with ID '{$uid}' is already registered.";
                    $messageType = 'danger';
                } else {
                    $apiKey = 'pos_sec_' . bin2hex(random_bytes(16));
                    $keyHash = hash('sha256', $apiKey);
                    $ins = $pdo->prepare("INSERT INTO pos_devices (device_name, device_uid, api_key, api_key_hash, location, status) VALUES (?, ?, '', ?, ?, 'active')");
                    $ins->execute([$name, $uid, $keyHash, $location]);
                    $newlyCreatedApiKey = $apiKey;
                    $newlyCreatedDeviceName = $name;
                    $message = "New POS device '{$name}' authorized successfully!";
                    if (class_exists('AuditLogger')) {
                        AuditLogger::log('DEVICE_REGISTERED', "Authorized POS device '{$name}' ({$uid})", 'device');
                    }
                }
            }
        }
    }

    // 2. Toggle Status (Block / Unblock)
    elseif ($action === 'toggle_status') {
        if (!Auth::can('pos_devices.block')) {
            $message = 'Permission denied: Cannot block/unblock device.';
            $messageType = 'danger';
        } else {
            $id = (int)($_POST['device_id'] ?? 0);
            $newStatus = ($_POST['new_status'] === 'blocked') ? 'blocked' : 'active';
            $pdo->prepare("UPDATE pos_devices SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            $message = "Device status updated to: " . strtoupper($newStatus);
            if (class_exists('AuditLogger')) {
                AuditLogger::log('DEVICE_STATUS_CHANGE', "Updated device #{$id} status to {$newStatus}", 'device', (string)$id);
            }
        }
    }

    // 3. Regenerate Key
    elseif ($action === 'regenerate_key') {
        if (!Auth::can('pos_devices.regenerate')) {
            $message = 'Permission denied: Cannot regenerate device key.';
            $messageType = 'danger';
        } else {
            $id = (int)($_POST['device_id'] ?? 0);
            $newKey = 'pos_sec_' . bin2hex(random_bytes(16));
            $newKeyHash = hash('sha256', $newKey);
            $pdo->prepare("UPDATE pos_devices SET api_key = '', api_key_hash = ? WHERE id = ?")->execute([$newKeyHash, $id]);
            $dStmt = $pdo->prepare("SELECT device_name FROM pos_devices WHERE id = ?");
            $dStmt->execute([$id]);
            $devName = $dStmt->fetchColumn();
            $newlyCreatedApiKey = $newKey;
            $newlyCreatedDeviceName = $devName ? (string)$devName : "Device #{$id}";
            $message = "New API Key generated for " . ($devName ? "'{$devName}'" : "device #{$id}") . "!";
            if (class_exists('AuditLogger')) {
                AuditLogger::log('DEVICE_KEY_REGENERATED', "Regenerated key for device #{$id} ({$devName})", 'device', (string)$id);
            }
        }
    }

    // 4. Delete Device (Soft Delete)
    elseif ($action === 'delete_device') {
        if (!Auth::can('pos_devices.delete')) {
            $message = 'Permission denied: Cannot delete device.';
            $messageType = 'danger';
        } else {
            $id = (int)($_POST['device_id'] ?? 0);
            $dStmt = $pdo->prepare("SELECT device_name FROM pos_devices WHERE id = ?");
            $dStmt->execute([$id]);
            $devName = $dStmt->fetchColumn();
            $pdo->prepare("UPDATE pos_devices SET status = 'inactive' WHERE id = ?")->execute([$id]);
            $message = "Device '{$devName}' marked inactive (safe soft delete).";
            if (class_exists('AuditLogger')) {
                AuditLogger::log('DEVICE_DEACTIVATED', "Deactivated POS device '{$devName}'", 'device', (string)$id);
            }
        }
    }
}

// Fetch all active/blocked devices (excluding soft-deleted)
$devices = $pdo->query("SELECT * FROM pos_devices WHERE status != 'inactive' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Device stats
$stats = [
    'total' => count($devices),
    'active' => 0,
    'blocked' => 0
];
foreach ($devices as $d) {
    if ($d['status'] === 'active') $stats['active']++;
    if ($d['status'] === 'blocked') $stats['blocked']++;
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <i class="fa-solid fa-cash-register mr-1"></i> <?= count($devices) ?> Authorized Terminals
        </span>
        <span style="font-size: 13px; color: var(--text-muted);">
            Point-of-Sale Hardware, API Keys &amp; Device Pairing
        </span>
    </div>
    <div>
        <?php if (Auth::can('pos_devices.add')): ?>
            <button type="button" class="btn btn-primary" onclick="openModal('addDeviceModal')">
                <i class="fa-solid fa-plus mr-1"></i> Authorize New POS Device
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Newly Generated API Key Card (Shown once only upon create / regenerate) -->
<?php if (!empty($newlyCreatedApiKey)): ?>
    <div class="card" style="margin-bottom: 24px; background: #fffbeb; border: 2px solid #f59e0b; border-radius: var(--radius-md); padding: 18px 22px; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.12);">
        <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="background: #fef3c7; color: #d97706; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; margin-top: 2px;">
                <i class="fa-solid fa-key"></i>
            </div>
            <div style="flex: 1;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #92400e;">
                        <i class="fa-solid fa-circle-check mr-1" style="color: #16a34a;"></i>
                        New API Key: <?= htmlspecialchars($newlyCreatedDeviceName, ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-weight: 700; font-size: 11.5px;">
                        Shown Once Only
                    </span>
                </div>
                <p style="margin: 6px 0 12px 0; font-size: 13.5px; color: #78350f; line-height: 1.45;">
                    Copy and save this key now. It is secured as a SHA-256 hash and <strong>will not be displayed again</strong>. If lost, press <em>Regenerate Key</em>.
                </p>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <input type="text" id="newGeneratedApiKeyInput" readonly value="<?= htmlspecialchars($newlyCreatedApiKey, ENT_QUOTES, 'UTF-8') ?>" style="flex: 1; max-width: 440px; font-family: var(--font-mono); font-size: 13.5px; font-weight: 700; background: #ffffff; border: 1.5px solid #f59e0b; border-radius: 6px; padding: 8px 12px; color: #92400e;">
                    <button type="button" class="btn btn-warning" id="btnCopyNewApiKey" onclick="copyNewGeneratedKey()" style="background: #f59e0b; border-color: #d97706; color: #000; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-copy"></i>
                        <span>Copy Key</span>
                    </button>
                    <span id="copyNewApiKeyFeedback" style="display: none; font-size: 13px; font-weight: 700; color: #16a34a;">
                        <i class="fa-solid fa-check mr-1"></i> Copied to clipboard!
                    </span>
                </div>
            </div>
        </div>
    </div>
<?php elseif (!empty($message)): ?>
    <div style="background: <?= ($messageType === 'danger') ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color: <?= ($messageType === 'danger') ? 'var(--danger)' : 'var(--success)' ?>; padding: 14px 20px; border-radius: var(--radius-md); margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid <?= ($messageType === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
<?php endif; ?>

<!-- Compact Connection Endpoint Bar -->
<div class="card" style="margin-bottom: 20px; padding: 14px 20px; background: var(--bg-card); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; border-left: 4px solid var(--primary);">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <span style="font-weight: 700; font-size: 13px; color: var(--text-muted); text-transform: uppercase;">
            <i class="fa-solid fa-link mr-1" style="color: var(--primary);"></i> API Connection Endpoint:
        </span>
        <code style="font-size: 13.5px; font-weight: 700; color: var(--text-main); background: var(--bg-subtle); padding: 4px 10px; border-radius: 6px; border: 1px solid var(--border);">
            <?= htmlspecialchars(API_BASE_URL) ?>/api/pos/
        </code>
    </div>
    <div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText('<?= API_BASE_URL ?>/api/pos/'); alert('Endpoint URL copied to clipboard!')">
            <i class="fa-solid fa-copy mr-1"></i> Copy Endpoint
        </button>
    </div>
</div>

<!-- Minimal Device Status Summary -->
<div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 18px; display: flex; align-items: center; gap: 10px;">
        <div style="width: 10px; height: 10px; border-radius: 50%; background: var(--primary);"></div>
        <span style="font-size: 13px; color: var(--text-muted);">Total Devices: <strong style="color: var(--text-main);"><?= $stats['total'] ?></strong></span>
    </div>
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 18px; display: flex; align-items: center; gap: 10px;">
        <div style="width: 10px; height: 10px; border-radius: 50%; background: #16a34a;"></div>
        <span style="font-size: 13px; color: var(--text-muted);">Active / Allowed: <strong style="color: #16a34a;"><?= $stats['active'] ?></strong></span>
    </div>
    <?php if ($stats['blocked'] > 0): ?>
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 10px 18px; display: flex; align-items: center; gap: 10px;">
        <div style="width: 10px; height: 10px; border-radius: 50%; background: #dc2626;"></div>
        <span style="font-size: 13px; color: var(--text-muted);">Blocked: <strong style="color: #dc2626;"><?= $stats['blocked'] ?></strong></span>
    </div>
    <?php endif; ?>
</div>

<!-- Clean Authorized Terminals Whitelist Table -->
<div class="card" style="margin-bottom: 28px;">
    <div class="card-header">
        <h2 class="card-title" style="font-size: 16px;">
            <i class="fa-solid fa-shield-halved mr-1" style="color: var(--primary);"></i> Authorized POS Terminals
        </h2>
        <span class="text-xs text-muted">Active terminals authorized to communicate with this system</span>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Device Name &amp; Station</th>
                    <th>Identifier (UID)</th>
                    <th>Assigned Location</th>
                    <th>Status</th>
                    <th>Last Connected</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            No POS devices registered yet. Click "Authorize New POS Device" to connect your first counter tablet or station.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($devices as $d): 
                        $isActive = ($d['status'] === 'active');
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 700; font-size: 14px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid <?= $isActive ? 'fa-tablet-button text-success' : 'fa-ban text-danger' ?>" style="font-size: 16px;"></i>
                                <span><?= htmlspecialchars($d['device_name']) ?></span>
                            </div>
                            <div class="text-xs text-muted" style="margin-top: 2px;">Registered: <?= date('d/m/Y', strtotime($d['created_at'])) ?></div>
                        </td>
                        <td>
                            <span class="badge badge-slate" style="font-family: var(--font-mono); font-size: 12px; font-weight: 700;">
                                <?= htmlspecialchars($d['device_uid']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-xs text-muted">
                                <i class="fa-solid fa-location-dot mr-1"></i> <?= htmlspecialchars($d['location'] ?? 'Main Counter') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-circle-check mr-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger"><i class="fa-solid fa-ban mr-1"></i> Blocked</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($d['last_seen_at'])): ?>
                                <div style="font-size: 12px; font-weight: 600;"><?= date('d/m/Y H:i', strtotime($d['last_seen_at'])) ?></div>
                                <div class="text-xs text-muted">IP: <?= htmlspecialchars($d['last_ip'] ?? '-') ?></div>
                            <?php else: ?>
                                <span class="text-xs text-muted">Never connected</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                <!-- Block / Unblock Button -->
                                <?php if (Auth::can('pos_devices.block')): ?>
                                <form action="" method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $isActive ? 'blocked' : 'active' ?>">
                                    <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-secondary' : 'btn-primary' ?>" style="<?= $isActive ? 'color: var(--danger);' : '' ?>" title="<?= $isActive ? 'Block device access' : 'Unblock device' ?>">
                                        <i class="fa-solid <?= $isActive ? 'fa-ban' : 'fa-check' ?>"></i>
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Regenerate Key Button -->
                                <?php if (Auth::can('pos_devices.regenerate')): ?>
                                <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Regenerating will invalidate the existing key for this device. Continue?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="regenerate_key">
                                    <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Regenerate API Key">
                                        <i class="fa-solid fa-rotate"></i>
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Delete Device Button -->
                                <?php if (Auth::can('pos_devices.delete')): ?>
                                <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Remove this POS device?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_device">
                                    <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--danger);" title="Remove Device">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Authorize New POS Device -->
<div class="modal-backdrop" id="addDeviceModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fa-solid fa-plus-circle mr-1" style="color: var(--primary);"></i> Authorize New POS Device
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('addDeviceModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="register_device">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Device Name *</label>
                    <input type="text" name="device_name" class="form-input" placeholder="e.g. Counter Tablet #1 (Cashier)" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Device Unique Identifier (UID) *</label>
                    <input type="text" name="device_uid" class="form-input" placeholder="e.g. POS-CASHIER-01" style="font-family: var(--font-mono); text-transform: uppercase;" required>
                    <div class="text-xs text-muted" style="margin-top: 4px;">Hardware serial, tablet ID, or custom identifier.</div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Counter / Station Location</label>
                    <input type="text" name="location" class="form-input" placeholder="e.g. Front Counter, Bay 4 Oil Station">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addDeviceModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check mr-1"></i> Authorize &amp; Generate Key
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function copyNewGeneratedKey() {
    const input = document.getElementById('newGeneratedApiKeyInput');
    if (!input) return;
    const key = (input.value || '').trim();
    if (!key) return;

    const onSuccess = () => {
        const feedback = document.getElementById('copyNewApiKeyFeedback');
        const btn = document.getElementById('btnCopyNewApiKey');
        if (feedback) {
            feedback.style.display = 'inline-flex';
            setTimeout(() => { feedback.style.display = 'none'; }, 3000);
        }
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
            setTimeout(() => { btn.innerHTML = orig; }, 2000);
        }
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(key).then(onSuccess).catch(() => {
            input.focus();
            input.select();
            document.execCommand('copy');
            onSuccess();
        });
    } else {
        input.focus();
        input.select();
        document.execCommand('copy');
        onSuccess();
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
