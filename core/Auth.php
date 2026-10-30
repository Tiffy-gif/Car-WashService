<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';

class Auth {
    private static ?string $lastError = null;
    private static array $lastErrorData = [];

    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
            @ini_set('session.cookie_httponly', '1');
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.cookie_samesite', 'Strict');
            @ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            // Unique session name to avoid conflicts with other htdocs localhost projects
            session_name('carwash_sess');
            @session_start();
        }
    }

    public static function getLastError(): ?string {
        return self::$lastError;
    }

    public static function getLastErrorData(): array {
        return self::$lastErrorData;
    }

    public static function mustChangePin(): bool {
        self::startSession();
        return !empty($_SESSION['must_change_pin']);
    }

    /**
     * Authenticate staff user using Username and 6-digit PIN.
     * Enforces rate limiting: 5 wrong attempts locks user for 10 minutes.
     * Enforces test mode check: sample demo accounts require test mode switch ON.
     * Enforces customer role check: customer accounts cannot log in to back office.
     */
    public static function login(string $username, string $pin): bool {
        self::startSession();
        self::$lastError = null;
        self::$lastErrorData = [];

        $username = trim($username);
        $pin = trim($pin);

        if ($username === '' || $pin === '') {
            self::$lastError = 'invalid_credentials';
            return false;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('
            SELECT id, username, password_hash, full_name, role, role_id, status,
                   failed_login_attempts, locked_until, must_change_pin, is_sample_account
            FROM users
            WHERE username = ?
            LIMIT 1
        ');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 1. Unknown username
        if (!$user) {
            AuditLogger::log('LOGIN_FAILED', "Failed login attempt for unknown username '{$username}'", 'auth');
            self::$lastError = 'invalid_credentials';
            return false;
        }

        // 2. Customer role check: TV queue only, cannot log into back office
        if ($user['role'] === 'customer') {
            AuditLogger::log('LOGIN_BLOCKED', "Customer account cannot log into back office (TV queue only)", 'auth', (string)$user['id'], (int)$user['id'], $user['username'], $user['role']);
            self::$lastError = 'customer_no_login';
            return false;
        }

        // 3. Deactivated account
        if ($user['status'] !== 'active') {
            AuditLogger::log('LOGIN_BLOCKED', "Deactivated account attempted login", 'auth', (string)$user['id'], (int)$user['id'], $user['username'], $user['role']);
            self::$lastError = 'account_inactive';
            return false;
        }

        // 4. Rate-limiting check: 10-minute lockout after 5 wrong attempts
        if (!empty($user['locked_until'])) {
            $lockTime = strtotime($user['locked_until']);
            if ($lockTime > time()) {
                $remainingMinutes = (int)ceil(($lockTime - time()) / 60);
                self::$lastError = 'account_locked';
                self::$lastErrorData = ['remaining_minutes' => max(1, $remainingMinutes)];
                AuditLogger::log('LOGIN_LOCKED', "Login attempted on locked user. {$remainingMinutes} mins remaining.", 'auth', (string)$user['id'], (int)$user['id'], $user['username'], $user['role']);
                return false;
            } else {
                // Lock expired: reset counters
                $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);
                $user['failed_login_attempts'] = 0;
                $user['locked_until'] = null;
            }
        }

        // 5. Test mode check: Sample demo accounts only work when Test Mode switch in Settings is ON
        if (!empty($user['is_sample_account'])) {
            $demoMode = get_setting('demo_mode', '1');
            if ($demoMode !== '1') {
                self::$lastError = 'demo_disabled';
                AuditLogger::log('LOGIN_BLOCKED', "Sample account login blocked because Test Mode is OFF", 'auth', (string)$user['id'], (int)$user['id'], $user['username'], $user['role']);
                return false;
            }
        }

        // 6. Verify 6-digit PIN hash
        if (!password_verify($pin, $user['password_hash'])) {
            $attempts = (int)$user['failed_login_attempts'] + 1;
            if ($attempts >= 5) {
                $lockedUntil = date('Y-m-d H:i:s', time() + 600); // 10 minutes from now
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?")->execute([$attempts, $lockedUntil, $user['id']]);
                self::$lastError = 'account_locked';
                self::$lastErrorData = ['remaining_minutes' => 10];
                AuditLogger::log('LOGIN_LOCKED', "Account locked for 10 minutes after {$attempts} failed PIN attempts", 'auth', (string)$user['id'], (int)$user['id'], $user['username'], $user['role']);
            } else {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
                self::$lastError = 'invalid_credentials';
                self::$lastErrorData = ['attempts' => $attempts, 'remaining_attempts' => (5 - $attempts)];
                AuditLogger::log('LOGIN_FAILED', "Failed PIN login attempt ({$attempts}/5)", 'auth', (string)$user['id'], (int)$user['id'], $user['username'], $user['role']);
            }
            return false;
        }

        // 7. Successful Authentication
        // Reset failed attempts & lockout counter
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);
        AuditLogger::log('LOGIN_SUCCESS', "Successful PIN login", 'auth', (string)$user['id'], (int)$user['id'], $user['username'], $user['role']);

        session_regenerate_id(true);
        $_SESSION['user_id']         = (int)$user['id'];
        $_SESSION['username']        = $user['username'];
        $_SESSION['full_name']       = $user['full_name'];
        $_SESSION['role']            = $user['role'];
        $_SESSION['role_id']         = !empty($user['role_id']) ? (int)$user['role_id'] : null;
        $_SESSION['must_change_pin'] = (int)($user['must_change_pin'] ?? 0);
        $_SESSION['login_at']        = time();

        // Load permissions into server session
        self::loadPermissions((int)$user['id'], $_SESSION['role_id'], $user['role']);

        return true;
    }

    public static function logout(): void {
        self::startSession();
        if (self::check()) {
            AuditLogger::log('LOGOUT', "User logged out", 'auth', (string)self::getUserId());
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => !empty($params['secure']),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        session_destroy();
    }

    public static function check(): bool {
        self::startSession();
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            return false;
        }
        return true;
    }

    public static function isLoggedIn(): bool {
        return self::check();
    }

    public static function getUsername(): ?string {
        self::startSession();
        return $_SESSION['username'] ?? null;
    }

    public static function getUserId(): ?int {
        self::startSession();
        return $_SESSION['user_id'] ?? null;
    }

    public static function getId(): ?int {
        return self::getUserId();
    }

    public static function getRole(): ?string {
        self::startSession();
        return $_SESSION['role'] ?? null;
    }

    public static function getRoleId(): ?int {
        self::startSession();
        return $_SESSION['role_id'] ?? null;
    }

    public static function getFullName(): ?string {
        self::startSession();
        return $_SESSION['full_name'] ?? null;
    }

    public static function user(): ?array {
        self::startSession();
        if (!self::check()) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role'],
            'role_id' => $_SESSION['role_id'] ?? null,
            'must_change_pin' => $_SESSION['must_change_pin'] ?? 0,
        ];
    }

    public static function loadPermissions(?int $userId = null, ?int $roleId = null, ?string $roleCode = null): array {
        self::startSession();
        $userId = $userId ?? self::getUserId();
        $role = $roleCode ?? self::getRole();
        $roleId = $roleId ?? self::getRoleId();

        if (!$userId || !$role) {
            $_SESSION['permissions'] = [];
            return [];
        }

        $pdo = Database::getInstance();

        // Customer has no permissions in back office
        if ($role === 'customer') {
            $_SESSION['permissions'] = [];
            return [];
        }

        // Owner always has all permissions
        if ($role === 'owner') {
            $perms = $pdo->query("SELECT code FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
            $_SESSION['permissions'] = $perms;
            return $perms;
        }

        if (!$roleId) {
            $rStmt = $pdo->prepare("SELECT id FROM roles WHERE code = ? LIMIT 1");
            $rStmt->execute([$role]);
            $roleId = (int)$rStmt->fetchColumn();
            $_SESSION['role_id'] = $roleId ?: null;
        }

        if (!$roleId) {
            $_SESSION['permissions'] = [];
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT p.code 
            FROM role_permissions rp 
            JOIN permissions p ON rp.permission_id = p.id 
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);
        $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Explicit security rule: System Admin can NEVER have money permissions
        if (in_array($role, ['system_admin', 'admin'], true)) {
            $moneyPerms = [
                'reports.view', 'reports.export',
                'expenses.list', 'expenses.add', 'expenses.edit', 'expenses.delete',
                'commissions.list', 'commissions.pay',
                'dashboard.view', 'daily_close.view', 'daily_close.close'
            ];
            $perms = array_values(array_diff($perms, $moneyPerms));
        }

        $_SESSION['permissions'] = $perms;
        return $perms;
    }

    public static function getPermissionsByUserId(int $userId): array {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT role, role_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) return [];
        if ($u['role'] === 'customer') return [];
        if ($u['role'] === 'owner') {
            return $pdo->query("SELECT code FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        }
        $roleId = !empty($u['role_id']) ? (int)$u['role_id'] : null;
        if (!$roleId) {
            $rStmt = $pdo->prepare("SELECT id FROM roles WHERE code = ? LIMIT 1");
            $rStmt->execute([$u['role']]);
            $roleId = (int)$rStmt->fetchColumn();
        }
        if (!$roleId) return [];
        $pStmt = $pdo->prepare("
            SELECT p.code 
            FROM role_permissions rp 
            JOIN permissions p ON rp.permission_id = p.id 
            WHERE rp.role_id = ?
        ");
        $pStmt->execute([$roleId]);
        $perms = $pStmt->fetchAll(PDO::FETCH_COLUMN);

        if (in_array($u['role'], ['system_admin', 'admin'], true)) {
            $moneyPerms = [
                'reports.view', 'reports.export',
                'expenses.list', 'expenses.add', 'expenses.edit', 'expenses.delete',
                'commissions.list', 'commissions.pay',
                'dashboard.view', 'daily_close.view', 'daily_close.close'
            ];
            $perms = array_values(array_diff($perms, $moneyPerms));
        }

        return $perms;
    }

    public static function userHasPermission(int $userId, string $permission): bool {
        $perms = self::getPermissionsByUserId($userId);
        return in_array($permission, $perms, true);
    }

    public static function getUserRoleDetails(int $userId): ?array {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.full_name, u.role, u.role_id, u.status,
                   r.code AS role_code, r.name_en AS role_name_en, r.name_km AS role_name_km,
                   r.can_assign_washer, r.can_assign_mechanic, r.is_commission_eligible
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$res) return null;
        return $res;
    }

    public static function getPermissions(): array {
        self::startSession();
        if (!isset($_SESSION['permissions'])) {
            return self::loadPermissions();
        }
        return $_SESSION['permissions'];
    }

    public static function refreshPermissions(?int $userId = null): void {
        self::startSession();
        unset($_SESSION['permissions']);
        self::loadPermissions($userId);
    }

    /**
     * Server checks: Server-side check of real logged-in user permissions.
     * System Admin can NEVER see money reports.
     * Owner sees money and business reports.
     */
    public static function can(string $permission): bool {
        self::startSession();
        if (!self::check()) {
            return false;
        }

        $role = self::getRole();

        // Customer has no permissions in back office
        if ($role === 'customer') {
            return false;
        }

        // Owner sees money, business reports, and everything
        if ($role === 'owner') {
            return true;
        }

        // Ironclad System Admin check: System Admin CANNOT see money reports
        $forbiddenMoneyPerms = [
            'reports.view',
            'reports.export',
            'expenses.list',
            'expenses.add',
            'expenses.edit',
            'expenses.delete',
            'commissions.list',
            'commissions.pay',
            'dashboard.view',
            'daily_close.view',
            'daily_close.close'
        ];
        if (in_array($role, ['system_admin', 'admin'], true) && in_array($permission, $forbiddenMoneyPerms, true)) {
            return false;
        }

        if (!isset($_SESSION['permissions'])) {
            self::loadPermissions();
        }
        return in_array($permission, $_SESSION['permissions'] ?? [], true);
    }

    public static function canAny(array $permissions): bool {
        self::startSession();
        if (!self::check()) {
            return false;
        }
        foreach ($permissions as $p) {
            if (self::can($p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dedicated home screen for each built-in role:
     * 1. System Admin -> settings
     * 2. Owner -> dashboard
     * 3. Manager -> orders
     * 4. CRM Coordinator -> customers
     * 5. Cashier -> pos
     * 6. Stock Control (Warehouse) -> oil_care
     * 7. Technical -> mechanic_jobs
     * 8. Washer -> washer_jobs
     * 9. Wash Supervisor -> bays_queue
     * 10. Customer -> tv.php (queue TV display, no login)
     */
    public static function getDefaultViewForUser(): string {
        $role = self::getRole();
        return match($role) {
            'system_admin', 'admin'       => 'settings',
            'owner'                      => 'dashboard',
            'manager'                    => 'orders',
            'crm', 'crm_coordinator'    => 'customers',
            'cashier'                    => 'pos',
            'stock_control', 'warehouse' => 'oil_care',
            'technical', 'mechanic'      => 'mechanic_jobs',
            'washer'                     => 'washer_jobs',
            'wash_supervisor', 'supervisor' => 'bays_queue',
            'customer'                   => 'login',
            default                      => self::resolveFallbackViewByPermissions()
        };
    }

    private static function resolveFallbackViewByPermissions(): string {
        if (self::can('dashboard.view')) return 'dashboard';
        if (self::can('pos.access')) return 'pos';
        if (self::can('orders.list')) return 'orders';
        if (self::can('bays.view')) return 'bays_queue';
        if (self::can('washer_jobs.view')) return 'washer_jobs';
        if (self::can('mechanic_jobs.view')) return 'mechanic_jobs';
        if (self::can('vehicle_history.list')) return 'vehicle_history';
        if (self::can('customers.list')) return 'customers';
        if (self::can('services.list')) return 'services';
        if (self::can('oil_care.list')) return 'oil_care';
        if (self::can('extras.list')) return 'extras';
        if (self::can('settings.view')) return 'settings';
        return 'login';
    }

    public static function requirePermission(string $permission): void {
        self::startSession();
        if (!self::check()) {
            header('Location: ' . APP_URL . '/index.php?view=login');
            exit;
        }

        if (self::can($permission)) {
            return;
        }

        // Check if AJAX
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_GET['ajax']) || isset($_POST['ajax']));
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'FORBIDDEN',
                'message' => "Access denied. Required permission: {$permission}"
            ]);
            exit;
        }

        $fallbackView = self::getDefaultViewForUser();
        header('Location: ' . APP_URL . '/index.php?view=' . $fallbackView . '&msg=unauthorized');
        exit;
    }

    public static function requireRole(array|string $roles): void {
        self::startSession();
        $roles = (array)$roles;
        
        if (!self::check()) {
            header('Location: ' . APP_URL . '/index.php?view=login');
            exit;
        }

        $currentRole = self::getRole();
        if ($currentRole === 'owner') {
            return;
        }

        if (!in_array($currentRole, $roles, true)) {
            $safeView = self::getDefaultViewForUser();
            header('Location: ' . APP_URL . '/index.php?view=' . $safeView . '&msg=unauthorized');
            exit;
        }
    }

    public static function requireAuth(): void {
        self::startSession();
        if (!self::check()) {
            header('Location: ' . APP_URL . '/index.php?view=login');
            exit;
        }
    }
}
