<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/Language.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/AuditLogger.php';

// Strict permission: Owner and System Admin only
Auth::requirePermission('audit.view');

$pageTitle = 'Audit Trail & Activity Log';
$pdo = Database::getInstance();

// Search & Filter parameters
$filterUser    = trim($_GET['user'] ?? '');
$filterAction  = trim($_GET['action_type'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to'] ?? '');
$filterKeyword  = trim($_GET['q'] ?? '');
$page           = max(1, (int)($_GET['p'] ?? 1));
$limit          = 30;
$offset         = ($page - 1) * $limit;

$filters = [
    'username'  => $filterUser,
    'action'    => $filterAction,
    'date_from' => $filterDateFrom,
    'date_to'   => $filterDateTo,
    'keyword'   => $filterKeyword,
];

$searchResult = AuditLogger::search($filters, $limit, $offset);
$totalRecords = $searchResult['total'];
$records      = $searchResult['records'];
$totalPages   = max(1, (int)ceil($totalRecords / $limit));

// Summary statistics
$todayDate = date('Y-m-d');
$statsTotal = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$statsTodayLogins = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_SUCCESS' AND DATE(created_at) = '{$todayDate}'")->fetchColumn();
$statsSecurityAlerts = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('LOGIN_FAILED', 'LOGIN_LOCKED', 'LOGIN_BLOCKED') AND DATE(created_at) = '{$todayDate}'")->fetchColumn();
$statsChanges = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('PRICE_CHANGE', 'ROLE_CHANGE', 'ORDER_CANCEL', 'SETTING_CHANGE', 'PIN_RESET', 'PIN_CHANGED')")->fetchColumn();

// Fetch distinct users for dropdown filter
$distinctUsers = $pdo->query("SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);

// Action badge styling helper
function getActionBadge(string $action): string {
    return match($action) {
        'LOGIN_SUCCESS'        => '<span class="badge badge-success"><i class="fa-solid fa-arrow-right-to-bracket mr-1"></i> Login Success</span>',
        'LOGIN_FAILED'         => '<span class="badge badge-danger"><i class="fa-solid fa-circle-xmark mr-1"></i> Wrong PIN</span>',
        'LOGIN_LOCKED'         => '<span class="badge badge-danger" style="background: #991b1b; color: white;"><i class="fa-solid fa-lock mr-1"></i> User Locked</span>',
        'LOGIN_BLOCKED'        => '<span class="badge badge-warning"><i class="fa-solid fa-ban mr-1"></i> Login Blocked</span>',
        'LOGOUT'               => '<span class="badge badge-slate"><i class="fa-solid fa-arrow-right-from-bracket mr-1"></i> Logged Out</span>',
        'PIN_RESET'            => '<span class="badge badge-warning"><i class="fa-solid fa-key mr-1"></i> PIN Reset</span>',
        'PIN_CHANGED'          => '<span class="badge badge-info"><i class="fa-solid fa-shield-check mr-1"></i> PIN Changed</span>',
        'ROLE_CHANGE', 'ROLE_CREATED', 'ROLE_UPDATED' => '<span class="badge badge-purple"><i class="fa-solid fa-user-shield mr-1"></i> Role Change</span>',
        'PRICE_CHANGE'         => '<span class="badge badge-primary"><i class="fa-solid fa-tag mr-1"></i> Price Change</span>',
        'ORDER_CANCEL'         => '<span class="badge badge-danger"><i class="fa-solid fa-ban mr-1"></i> Order Cancelled</span>',
        'SETTING_CHANGE'       => '<span class="badge badge-amber"><i class="fa-solid fa-gear mr-1"></i> Settings Change</span>',
        'BACKUP_DOWNLOAD'      => '<span class="badge badge-info"><i class="fa-solid fa-download mr-1"></i> Backup</span>',
        default                => '<span class="badge badge-slate">' . htmlspecialchars($action) . '</span>'
    };
}

require_once dirname(__DIR__, 2) . '/views/layouts/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <i class="fa-solid fa-shield-halved mr-1"></i> Security Event Trail
        </span>
        <span style="font-size: 13px; color: var(--text-muted);">
            Compliance Audit &bull; Administrative &amp; Cashier Activity
        </span>
    </div>
    <div>
        <span class="badge badge-info" style="padding: 8px 12px; font-size: 12px;">
            <i class="fa-solid fa-lock mr-1"></i> Append-Only &bull; No Edits / No Deletions
        </span>
    </div>
</div>

    <!-- Stats Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card" style="padding: 16px 20px; border-left: 4px solid var(--primary);">
            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">
                Total Log Entries
            </div>
            <div style="font-size: 24px; font-weight: 800; color: var(--text-main);">
                <?= number_format($statsTotal) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 4px;">
                <i class="fa-solid fa-database mr-1"></i> Unique log IDs that never repeat
            </div>
        </div>

        <div class="card" style="padding: 16px 20px; border-left: 4px solid var(--success);">
            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">
                Logins Today
            </div>
            <div style="font-size: 24px; font-weight: 800; color: var(--success);">
                <?= number_format($statsTodayLogins) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 4px;">
                <i class="fa-solid fa-user-check mr-1"></i> Successful staff PIN authentications
            </div>
        </div>

        <div class="card" style="padding: 16px 20px; border-left: 4px solid <?= $statsSecurityAlerts > 0 ? 'var(--danger)' : 'var(--border)' ?>;">
            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">
                Security Alerts Today
            </div>
            <div style="font-size: 24px; font-weight: 800; color: <?= $statsSecurityAlerts > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>;">
                <?= number_format($statsSecurityAlerts) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 4px;">
                <i class="fa-solid fa-shield-virus mr-1"></i> Failed attempts &amp; locked accounts
            </div>
        </div>

        <div class="card" style="padding: 16px 20px; border-left: 4px solid #8b5cf6;">
            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">
                System Changes Logged
            </div>
            <div style="font-size: 24px; font-weight: 800; color: #8b5cf6;">
                <?= number_format($statsChanges) ?>
            </div>
            <div class="text-xs text-muted" style="margin-top: 4px;">
                <i class="fa-solid fa-pen-to-square mr-1"></i> Prices, roles, settings &amp; cancellations
            </div>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card" style="padding: 18px 20px; margin-bottom: 24px;">
        <form method="GET" action="<?= APP_URL ?>/index.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) 120px; gap: 12px; align-items: end;">
            <input type="hidden" name="view" value="audit_logs">

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label text-xs" style="margin-bottom: 4px;"><i class="fa-solid fa-filter mr-1"></i> Action Category</label>
                <select name="action_type" class="form-select" style="width: 100%;">
                    <option value="">All Actions</option>
                    <option value="logins" <?= ($filterAction === 'logins') ? 'selected' : '' ?>>Logins &amp; Lockouts</option>
                    <option value="pin_resets" <?= ($filterAction === 'pin_resets') ? 'selected' : '' ?>>PIN Resets &amp; Changes</option>
                    <option value="role_changes" <?= ($filterAction === 'role_changes') ? 'selected' : '' ?>>Role Changes &amp; Staff</option>
                    <option value="price_changes" <?= ($filterAction === 'price_changes') ? 'selected' : '' ?>>Price Changes</option>
                    <option value="cancellations" <?= ($filterAction === 'cancellations') ? 'selected' : '' ?>>Cancellations</option>
                    <option value="settings_changes" <?= ($filterAction === 'settings_changes') ? 'selected' : '' ?>>Settings Changes</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label text-xs" style="margin-bottom: 4px;"><i class="fa-solid fa-user mr-1"></i> Staff Member</label>
                <select name="user" class="form-select" style="width: 100%;">
                    <option value="">All Staff</option>
                    <?php foreach ($distinctUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u) ?>" <?= ($filterUser === $u) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label text-xs" style="margin-bottom: 4px;"><i class="fa-solid fa-calendar mr-1"></i> From Date</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" class="form-input" style="width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label text-xs" style="margin-bottom: 4px;"><i class="fa-solid fa-calendar mr-1"></i> To Date</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" class="form-input" style="width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label text-xs" style="margin-bottom: 4px;"><i class="fa-solid fa-magnifying-glass mr-1"></i> Search Details</label>
                <input type="text" name="q" value="<?= htmlspecialchars($filterKeyword) ?>" class="form-input" placeholder="Keyword / IP / Log ID" style="width: 100%;">
            </div>

            <div style="display: flex; gap: 6px;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">
                    <i class="fa-solid fa-search"></i> Filter
                </button>
                <?php if ($filterAction || $filterUser || $filterDateFrom || $filterDateTo || $filterKeyword): ?>
                    <a href="<?= APP_URL ?>/index.php?view=audit_logs" class="btn btn-secondary" title="Reset Filters">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Audit Log Records Table Card -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 14px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-list-check mr-1" style="color: var(--primary);"></i>
                Activity Trail (Showing <?= count($records) ?> of <?= number_format($totalRecords) ?> entries)
            </div>
            <div class="text-xs text-muted">
                Page <?= $page ?> of <?= $totalPages ?>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="width: 160px;">Date &amp; Time</th>
                        <th style="width: 170px;">Unique Log ID</th>
                        <th style="width: 160px;">Staff User &amp; Role</th>
                        <th style="width: 150px;">Action</th>
                        <th>Details &amp; Subject</th>
                        <th style="width: 130px;">IP Address</th>
                        <th style="width: 180px;">Device / Browser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-muted);">
                                <i class="fa-solid fa-clipboard-check" style="font-size: 36px; margin-bottom: 12px; display: block; opacity: 0.5;"></i>
                                No audit records match the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $log): ?>
                            <tr>
                                <td style="white-space: nowrap; font-size: 12px; color: var(--text-muted);">
                                    <i class="fa-regular fa-clock mr-1"></i>
                                    <?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?>
                                </td>
                                <td>
                                    <code style="font-size: 11px; padding: 2px 6px; background: var(--bg-subtle); border-radius: 4px; border: 1px solid var(--border);">
                                        <?= htmlspecialchars($log['log_uid']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?php if (!empty($log['username'])): ?>
                                        <div style="font-weight: 700; font-size: 13px; color: var(--text-main);">
                                            <?= htmlspecialchars($log['username']) ?>
                                        </div>
                                        <div class="text-xs text-muted">
                                            <?= htmlspecialchars(strtoupper($log['role'] ?? 'staff')) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size: 12px;">System / Guest</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= getActionBadge($log['action']) ?>
                                </td>
                                <td style="font-size: 13px; color: var(--text-main);">
                                    <?= htmlspecialchars($log['details'] ?? '-') ?>
                                    <?php if (!empty($log['entity_type'])): ?>
                                        <span class="text-xs text-muted" style="display: block; margin-top: 2px;">
                                            Target: <?= htmlspecialchars($log['entity_type']) ?> #<?= htmlspecialchars($log['entity_id'] ?? '') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px; color: var(--text-muted); font-family: monospace;">
                                    <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                                </td>
                                <td style="font-size: 12px; color: var(--text-muted);" title="<?= htmlspecialchars($log['user_agent'] ?? '') ?>">
                                    <i class="fa-solid fa-desktop mr-1"></i>
                                    <?= htmlspecialchars($log['device_name'] ?? 'Web Client') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                <div class="text-xs text-muted">
                    Showing <?= $offset + 1 ?> to <?= min($totalRecords, $offset + $limit) ?> of <?= number_format($totalRecords) ?> entries
                </div>
                <div style="display: flex; gap: 6px;">
                    <?php if ($page > 1): ?>
                        <a href="<?= APP_URL ?>/index.php?<?= http_build_query(array_merge($_GET, ['p' => $page - 1])) ?>" class="btn btn-secondary btn-sm">
                            &laquo; Previous
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="<?= APP_URL ?>/index.php?<?= http_build_query(array_merge($_GET, ['p' => $i])) ?>" class="btn <?= ($i === $page) ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= APP_URL ?>/index.php?<?= http_build_query(array_merge($_GET, ['p' => $page + 1])) ?>" class="btn btn-secondary btn-sm">
                            Next &raquo;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php
require_once dirname(__DIR__, 2) . '/views/layouts/footer.php';
?>
