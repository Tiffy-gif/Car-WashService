<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/CloudSyncManager.php';

// Strict permission: sync.view (Owner and Manager)
Auth::requirePermission('sync.view');

$pageTitle = 'Cloud Sync Monitor';
$pdo = Database::getInstance();

$isOffline = CloudSyncManager::isOfflineMode($pdo);
$syncCounts = CloudSyncManager::getUnsyncedCounts($pdo);
$totalPending = $syncCounts['total'];

// Fetch last sync times
$lastUpload = $pdo->query("SELECT created_at, status, error_message AS message FROM sync_logs WHERE sync_type = 'push' ORDER BY id DESC LIMIT 1")->fetch();
$lastDownload = $pdo->query("SELECT created_at, status, error_message AS message FROM sync_logs WHERE sync_type = 'pull' ORDER BY id DESC LIMIT 1")->fetch();
$lastSuccess = $pdo->query("SELECT created_at FROM sync_logs WHERE status = 'success' ORDER BY id DESC LIMIT 1")->fetchColumn();

// Fetch unresolved conflicts
$conflicts = $pdo->query("SELECT * FROM sync_conflicts WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();
$conflictCount = count($conflicts);

// Fetch recent sync logs
$logs = $pdo->query("SELECT * FROM sync_logs ORDER BY id DESC LIMIT 25")->fetchAll();
$failureLogs = $pdo->query("SELECT * FROM sync_logs WHERE status IN ('failed','error') ORDER BY id DESC LIMIT 5")->fetchAll();

// Cloud settings
$syncEnabled = (get_setting('cloud_sync_enabled', '1') === '1');
$cloudUrl = get_setting('cloud_api_url', 'http://localhost/Car-WashService/api/cloud/index.php');
$shopId = get_setting('cloud_shop_id', 'shop_phnom_penh_01');

require_once dirname(__DIR__, 2) . '/views/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 14px;">
    <div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <h1 style="font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0;">
                <i class="fa-solid fa-cloud-arrow-up mr-2" style="color: #6366f1;"></i>Cloud Sync Monitor
            </h1>
            <?php if ($isOffline): ?>
                <span class="badge badge-danger" style="font-size: 13px; padding: 6px 12px;">
                    <i class="fa-solid fa-plane-slash mr-1"></i> Shop Offline (Stand-alone)
                </span>
            <?php else: ?>
                <span class="badge badge-success" style="font-size: 13px; padding: 6px 12px;">
                    <i class="fa-solid fa-signal mr-1"></i> Online &amp; Connected
                </span>
            <?php endif; ?>
        </div>
        <p style="font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;">
            Shop ID: <strong><?= htmlspecialchars($shopId) ?></strong> &bull; Endpoint: <code><?= htmlspecialchars($cloudUrl) ?></code>
        </p>
    </div>

    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <!-- Toggle Offline Mode Form -->
        <form method="POST" action="index.php?action=toggle_offline_mode" style="margin: 0;">
            <?= csrf_field() ?>
            <button type="submit" class="btn <?= $isOffline ? 'btn-success' : 'btn-secondary' ?>" title="Simulate network disconnection">
                <i class="fa-solid <?= $isOffline ? 'fa-plug' : 'fa-plane-slash' ?> mr-1"></i>
                <?= $isOffline ? 'Reconnect (Go Online)' : 'Simulate Offline' ?>
            </button>
        </form>

        <!-- Force Sync Button -->
        <form method="POST" action="index.php?action=trigger_force_sync" style="margin: 0;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" <?= $isOffline ? 'disabled title="Cannot sync while offline"' : '' ?>>
                <i class="fa-solid fa-arrows-rotate mr-1"></i> Force Sync Now
            </button>
        </form>

        <a href="index.php?view=settings&tab=sync" class="btn btn-secondary" title="Sync Settings">
            <i class="fa-solid fa-gear"></i>
        </a>
    </div>
</div>

<?php if (isset($_SESSION['flash_sync_message'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_sync_type'] ?? 'info') ?> alert-dismissible" style="margin-bottom: 20px;">
        <i class="fa-solid fa-circle-info mr-2"></i><?= htmlspecialchars($_SESSION['flash_sync_message']) ?>
    </div>
    <?php unset($_SESSION['flash_sync_message'], $_SESSION['flash_sync_type']); ?>
<?php endif; ?>

<!-- Top Metrics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <!-- Card 1: Connection Health -->
    <div class="card" style="padding: 18px;">
        <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Sync Status</div>
        <div style="font-size: 22px; font-weight: 700; color: <?= $isOffline ? '#ef4444' : '#10b981' ?>; margin: 8px 0 4px 0;">
            <?= $isOffline ? 'Offline Mode' : ($syncEnabled ? 'Active (Auto)' : 'Disabled') ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);">
            Interval: 3&ndash;5 min &bull; Fast-check: 30s
        </div>
    </div>

    <!-- Card 2: Last Successful Sync -->
    <div class="card" style="padding: 18px;">
        <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Last Successful Sync</div>
        <div style="font-size: 18px; font-weight: 700; color: var(--text-main); margin: 8px 0 4px 0;">
            <?= $lastSuccess ? htmlspecialchars(date('H:i:s', strtotime($lastSuccess))) : 'No sync yet' ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);">
            <?= $lastSuccess ? htmlspecialchars(date('M d, Y', strtotime($lastSuccess))) : 'Awaiting first push' ?>
        </div>
    </div>

    <!-- Card 3: Waiting Queue -->
    <div class="card" style="padding: 18px;">
        <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Pending Records</div>
        <div style="font-size: 24px; font-weight: 700; color: <?= $totalPending > 0 ? '#f59e0b' : '#10b981' ?>; margin: 8px 0 4px 0;">
            <?= number_format($totalPending) ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);">
            Records waiting to upload
        </div>
    </div>

    <!-- Card 4: Conflicts -->
    <div class="card" style="padding: 18px;">
        <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Version Conflicts</div>
        <div style="font-size: 24px; font-weight: 700; color: <?= $conflictCount > 0 ? '#ef4444' : '#10b981' ?>; margin: 8px 0 4px 0;">
            <?= number_format($conflictCount) ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);">
            <?= $conflictCount > 0 ? 'Requires Manager review' : 'All versions aligned' ?>
        </div>
    </div>
</div>

<!-- Pending Records Queue Breakdown -->
<div class="card" style="margin-bottom: 24px;">
    <div style="border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
            <i class="fa-solid fa-list-check mr-2" style="color: var(--primary);"></i>Pending Outbound Queue Breakdown
        </h2>
        <span class="badge <?= $totalPending > 0 ? 'badge-warning' : 'badge-success' ?>">
            <?= $totalPending ?> unsynced record(s)
        </span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-receipt mr-1"></i> Sales / Invoices</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['orders'] ?></div>
        </div>
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-wrench mr-1"></i> Job Orders &amp; Tasks</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['tasks'] ?></div>
        </div>
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-boxes-stacked mr-1"></i> Stock Movements</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['stock'] ?></div>
        </div>
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-cash-register mr-1"></i> Shifts &amp; Z-Reports</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['shifts'] ?></div>
        </div>
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-business-time mr-1"></i> Staff Timecards</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['timecards'] ?></div>
        </div>
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-heart mr-1"></i> CRM &amp; Loyalty</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['loyalty'] ?></div>
        </div>
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-shield-halved mr-1"></i> Audit Logs</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['audit'] ?></div>
        </div>
        <div style="background: var(--bg-surface-alt, #f8fafc); padding: 12px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-chart-line mr-1"></i> Performance Metrics</div>
            <div style="font-size: 20px; font-weight: 700; margin-top: 4px;"><?= $syncCounts['performance_metrics'] ?? 0 ?></div>
        </div>
    </div>
</div>

<!-- Conflicts Review Section -->
<?php if ($conflictCount > 0): ?>
<div class="card" style="margin-bottom: 24px; border-left: 4px solid #ef4444;">
    <div style="border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
        <h2 style="font-size: 16px; font-weight: 700; color: #dc2626; margin: 0;">
            <i class="fa-solid fa-triangle-exclamation mr-2"></i>Detected Record Conflicts (<?= $conflictCount ?>)
        </h2>
        <p style="font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0;">
            The cloud and the shop server both modified these records independently. As required by SYNC_API, automatic overwriting is blocked. Choose which version to retain.
        </p>
    </div>

    <div style="display: flex; flex-direction: column; gap: 16px;">
        <?php foreach ($conflicts as $c): ?>
            <?php 
                $localData = json_decode((string)$c['local_data'], true) ?: [];
                $cloudData = json_decode((string)$c['cloud_data'], true) ?: [];
            ?>
            <div style="border: 1px solid var(--border); border-radius: 8px; padding: 16px; background: var(--bg-surface-alt, #fafafa);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <span class="badge badge-purple" style="font-size: 12px;"><?= htmlspecialchars(strtoupper($c['record_type'])) ?></span>
                        <span style="font-weight: 700; font-family: monospace; margin-left: 6px;">UID: <?= htmlspecialchars($c['record_uid']) ?></span>
                        <span style="font-size: 12px; color: var(--text-muted); margin-left: 10px;">
                            Detected: <?= htmlspecialchars($c['created_at']) ?>
                        </span>
                    </div>

                    <div style="display: flex; gap: 8px;">
                        <form method="POST" action="index.php?action=resolve_sync_conflict" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="conflict_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="resolution" value="keep_shop">
                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Keep shop server version?');">
                                <i class="fa-solid fa-store mr-1"></i> Keep Shop Version
                            </button>
                        </form>

                        <form method="POST" action="index.php?action=resolve_sync_conflict" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="conflict_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="resolution" value="accept_cloud">
                            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Overwrite with Cloud version?');">
                                <i class="fa-solid fa-cloud-arrow-down mr-1"></i> Accept Cloud Version
                            </button>
                        </form>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 12px;">
                        <div style="font-weight: 700; font-size: 12px; color: var(--primary); margin-bottom: 8px;">
                            <i class="fa-solid fa-store mr-1"></i> Local Shop Version
                        </div>
                        <pre style="font-size: 12px; margin: 0; background: transparent; padding: 0; overflow-x: auto; font-family: monospace;"><?= htmlspecialchars(json_encode($localData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </div>

                    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 12px;">
                        <div style="font-weight: 700; font-size: 12px; color: #6366f1; margin-bottom: 8px;">
                            <i class="fa-solid fa-cloud mr-1"></i> Cloud Version
                        </div>
                        <pre style="font-size: 12px; margin: 0; background: transparent; padding: 0; overflow-x: auto; font-family: monospace;"><?= htmlspecialchars(json_encode($cloudData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent Failures & Discrepancies (if any) -->
<?php if (!empty($failureLogs)): ?>
<div class="card" style="margin-bottom: 24px; border-left: 4px solid #f59e0b;">
    <div style="border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 12px;">
        <h2 style="font-size: 15px; font-weight: 700; color: #b45309; margin: 0;">
            <i class="fa-solid fa-triangle-exclamation mr-2"></i>Recent Sync Failures &amp; Retries
        </h2>
    </div>
    <div style="display: flex; flex-direction: column; gap: 8px;">
        <?php foreach ($failureLogs as $fl): ?>
            <div style="background: rgba(245, 158, 11, 0.08); border-radius: 6px; padding: 10px 14px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span class="badge badge-warning"><?= htmlspecialchars(($fl['sync_type'] === 'push' || $fl['sync_type'] === 'upload') ? 'UPLOAD' : 'DOWNLOAD') ?></span>
                    <strong style="margin-left: 8px;"><?= htmlspecialchars($fl['error_message'] ?? 'Network or verification error') ?></strong>
                </div>
                <span style="color: var(--text-muted); font-size: 12px;"><?= htmlspecialchars($fl['created_at']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Sync Logs Table -->
<div class="card">
    <div style="border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px;">
        <h2 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
            <i class="fa-solid fa-clock-rotate-left mr-2" style="color: var(--primary);"></i>Recent Sync Activity History
        </h2>
    </div>

    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 160px;">Timestamp</th>
                    <th style="width: 110px;">Type / Direction</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 100px; text-align: center;">Records</th>
                    <th>Details / Error Message</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">
                            No sync yet
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td style="font-family: monospace; font-size: 12px;"><?= htmlspecialchars($l['created_at']) ?></td>
                            <td>
                                <?php if (($l['sync_type'] ?? '') === 'push' || ($l['sync_type'] ?? '') === 'upload'): ?>
                                    <span class="badge badge-purple"><i class="fa-solid fa-arrow-up mr-1"></i> Upload</span>
                                <?php else: ?>
                                    <span class="badge badge-info"><i class="fa-solid fa-arrow-down mr-1"></i> Download</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($l['status'] === 'success'): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check mr-1"></i> Success</span>
                                <?php elseif ($l['status'] === 'retry'): ?>
                                    <span class="badge badge-warning"><i class="fa-solid fa-arrows-rotate mr-1"></i> Retry</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fa-solid fa-xmark mr-1"></i> Error</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; font-weight: 600;">
                                <?= (int)(($l['records_sent'] ?? 0) > 0 ? $l['records_sent'] : ($l['records_received'] ?? 0)) ?>
                            </td>
                            <td style="color: var(--text-main);"><?= htmlspecialchars($l['error_message'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/views/layouts/footer.php'; ?>