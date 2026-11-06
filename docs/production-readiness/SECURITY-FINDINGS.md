# Security Findings & Vulnerability Assessment
**Production Readiness Technical Audit &bull; Phase 1**
*System Scope: Car-WashService (Local Edge POS) & Car-WashCloud (Central Management Hub)*

---

## 1. Security Audit Executive Summary

During Phase 1 technical inspection of **Car-WashService** and **Car-WashCloud**, both codebases, their configuration files, web server rules (`.htaccess`), authentication controllers, sessions, and operational scripts were audited. 

The audit identified **11 distinct security findings** ranging from **Critical** (immediate exposure of source code and sensitive internals to the public internet) to **Low** (protocol hardening). 

### Risk Level Breakdown

| Severity | Count | Primary Impact |
| :--- | :---: | :--- |
| **CRITICAL** | 2 | Full git repository source code exposure; Internal AST architectural and code mapping exposure via HTTP. |
| **HIGH** | 4 | Development mode active in database; Hardcoded default staff PINs; Sensitive CLI tools/snapshots in tree; Unrestricted uploads directory. |
| **MEDIUM** | 3 | Missing folder-level `.htaccess` defense-in-depth; Missing CSP headers on direct PHP output; Legacy plaintext PIN fallback. |
| **LOW** | 2 | HTTP without forced TLS in local API base URL; Cloud database configuration localhost root fallback. |
| **TOTAL** | **11** | **System is NOT production-ready until Critical and High findings are mitigated.** |

---

## 2. Security Findings Matrix

| Finding ID | Severity | Affected App | File or Component | Problem Summary | Current Status |
| :--- | :---: | :---: | :--- | :--- | :--- |
| `SEC-01` | **CRITICAL** | Both | `/.git/` repository directory | `.git` folder is directly accessible over HTTP (returns 200 OK), allowing complete source code, history, and secret extraction. | **Open (Failing)** |
| `SEC-02` | **CRITICAL** | Car-WashService | `/graphify-out/` web directory | Interactive system architecture and AST code caches are exposed over HTTP (returns 200 OK). | **Open (Failing)** |
| `SEC-03` | **HIGH** | Car-WashService | `settings.demo_mode`, `views/auth/login.php` | `demo_mode` is set to `1` in the database, displaying a red banner and enabling 1-click passwordless login. | **Open (Failing)** |
| `SEC-04` | **HIGH** | Car-WashService | `config/demo_accounts.php`, `database/init.php` | Known default passwords (`111111`, `123456`) and demo accounts (`admin`, `owner`, `cashier`, `washer1`) are committed and active. | **Open (Failing)** |
| `SEC-05` | **HIGH** | Car-WashService | `scripts/dev_data_tool.php`, `storage/snapshots/` | Dangerous CLI database wiping tools with `--force-dev` bypass and historical database snapshots are present in the deploy tree. | **Open (Failing)** |
| `SEC-06` | **HIGH** | Car-WashService | `public/uploads/` | Uploads directory lacks an `.htaccess` script execution prevention barrier, presenting arbitrary code execution risks. | **Open (Failing)** |
| `SEC-07` | **MEDIUM** | Car-WashService | `config/`, `core/`, `database/`, `scripts/`, `tests/` | Internal directories rely entirely on root Apache mod_rewrite without individual `.htaccess` defense-in-depth deny rules. | **Open (Failing)** |
| `SEC-08` | **MEDIUM** | Car-WashService | `index.php`, Apache response headers | Content-Security-Policy (CSP) response header is missing from direct PHP page responses (detected by `go_live_check.php`). | **Open (Failing)** |
| `SEC-09` | **MEDIUM** | Car-WashService | `core/Auth.php` | Authentication logic retains a fallback that accepts plaintext PINs matching `local_pin` before migrating to bcrypt. | **Open (Failing)** |
| `SEC-10` | **LOW** | Both | `config/app.php`, `config/config.php` | Local POS and Cloud API URLs default to plain `http://` without strict HTTPS transport encryption or HSTS headers. | **Open (Failing)** |
| `SEC-11` | **LOW** | Car-WashCloud | `config/config.php` | Local development database configuration allows root user with an empty password fallback on localhost. | **Open (Failing)** |

---

## 3. Detailed Security Findings

### SEC-01: `.git` Directory and Repository Data Publicly Exposed Over HTTP
* **Finding ID**: `SEC-01`
* **Severity**: **CRITICAL**
* **Affected Application**: Both (`Car-WashService` and `Car-WashCloud`)
* **File or Component**: `/.git/` directory (`/.git/config`, `/.git/HEAD`, `/.git/objects/`)
* **Problem Description**:
  During the live HTTP probe, running:
  ```bash
  curl -I http://localhost/Car-WashService/.git/config
  curl -I http://localhost/Car-WashCloud/.git/config
  ```
  Both endpoints returned `HTTP/1.1 200 OK` and allowed downloading the internal Git configuration file:
  ```ini
  [core]
      repositoryformatversion = 0
      filemode = false
      bare = false
      logallrefupdates = true
  [remote "origin"]
      url = https://github.com/Tiffy-gif/Car-WashService.git
  ```
  The existing `.htaccess` files in both projects contain:
  ```apache
  <FilesMatch "^\.">
      Order allow,deny
      Deny from all
  </FilesMatch>
  ```
  This directive only matches file basenames that begin with a period (such as `.htaccess` or `.env`). It **does not** match subdirectories named `.git/` or files located inside them (such as `.git/config` or `.git/HEAD`). Furthermore, in Apache 2.4, legacy `Order allow,deny` rules can be superseded by default directory authorizations unless `Require all denied` is explicitly applied.
* **Business Impact**:
  Any unauthorized external visitor or user on the local shop Wi-Fi network can clone the entire repository using automated tools (e.g. `git-dumper`). This exposes 100% of the proprietary source code, internal architectural diagrams, database migration scripts, developer commit messages, author email addresses, and any secrets or API tokens committed in Git history.
* **Recommended Solution**:
  1. Add an explicit Apache 2.4 block in the root `.htaccess` of both projects:
     ```apache
     # Block access to .git and all VCS directories
     RedirectMatch 404 /\.git
     <DirectoryMatch "/\.git">
         Require all denied
     </DirectoryMatch>
     ```
  2. For production deployments, ensure the `.git` folder is completely excluded or deleted from the deployed web root folder.
* **Test Procedure**:
  Execute `curl.exe -I http://localhost/<App>/.git/config` and `curl.exe -I http://localhost/<App>/.git/HEAD`. Both must return `HTTP/1.1 403 Forbidden` or `HTTP/1.1 404 Not Found`.
* **Current Status**: **Open (Failing - verified via live HTTP probe)**

---

### SEC-02: Internal AST Code Map and Architecture Directory Publicly Accessible Over HTTP
* **Finding ID**: `SEC-02`
* **Severity**: **CRITICAL**
* **Affected Application**: `Car-WashService`
* **File or Component**: `c:\xampp\htdocs\Car-WashService\graphify-out\` (`graph.html`, `GRAPH_REPORT.md`, `manifest.json`, `cache/ast/`)
* **Problem Description**:
  A recent static analysis tool run generated an internal code graph directory named `graphify-out/` directly inside the web root. When queried via HTTP:
  ```bash
  curl -I http://localhost/Car-WashService/graphify-out/graph.html
  ```
  The server returned `HTTP/1.1 200 OK` (724,682 bytes).
  The root `.htaccess` rewrite rule blocks `(config|core|database|storage|tests|docs|bin|scripts)/`, but does not include `graphify-out/`.
* **Business Impact**:
  Anyone browsing to `http://<server-ip>/Car-WashService/graphify-out/graph.html` can view an interactive visual map of all PHP classes, functions, variable dependencies, call graphs, and architectural weaknesses of the car wash system.
* **Recommended Solution**:
  1. Remove `graphify-out/` from the production deployment bundle.
  2. Add `graphify-out` to `.gitignore`.
  3. Add `graphify-out` to the `.htaccess` rewrite block rule.
* **Test Procedure**:
  Execute `curl.exe -I http://localhost/Car-WashService/graphify-out/graph.html`. The server must return `403 Forbidden` or `404 Not Found`.
* **Current Status**: **Open (Failing - verified via live HTTP probe)**

---

### SEC-03: Development Demo Mode Active in Database Enabling Passwordless 1-Click Login
* **Finding ID**: `SEC-03`
* **Severity**: **HIGH**
* **Affected Application**: `Car-WashService`
* **File or Component**: Database `settings.demo_mode`, `views/auth/login.php`, `index.php`
* **Problem Description**:
  In `Car-WashService/database/carwash.sqlite` (and the `settings` table), `demo_mode` is set to `'1'`.
  When `demo_mode === '1'`:
  1. A prominent red banner displays: `DEMO MODE ON - turn off before going live`.
  2. The login screen displays 10 buttons labeled "Test Mode: 1-Click Demo Login" for `System Admin`, `Owner`, `Manager`, `CRM`, `Cashier`, `Warehouse`, `Technical`, `Washer`, and `Wash Supervisor`.
  3. Clicking any button issues a GET request (`index.php?action=demo_login&role=owner`), which calls `Auth::login()` using hardcoded credentials from `config/demo_accounts.php` without requiring the user to type any password or PIN.
  Although `index.php` checks if the client IP is in `['127.0.0.1', '::1']`, on a shop LAN where the POS terminal runs on the local Mini-PC (or if reverse proxy headers are spoofed), any user sitting at the station or browser can authenticate as the Owner or System Admin with a single mouse click.
* **Business Impact**:
  Unauthorized staff or curious customers looking at the counter terminal can click "Owner" and immediately gain full access to view cash takings, modify service prices, delete staff, or change bank account settings.
* **Recommended Solution**:
  1. Update `settings` table: `UPDATE settings SET setting_value = '0' WHERE setting_key = 'demo_mode'`.
  2. Require that `demo_accounts.php` cannot be read unless `APP_ENV === 'development'`.
  3. Remove the demo 1-click UI buttons from `login.php` when `APP_ENV === 'production'`.
* **Test Procedure**:
  1. Run `php scripts/go_live_check.php`. Check #1 (`demo_mode is 0`) must report `[ OK ]`.
  2. Load `http://localhost/Car-WashService/index.php?view=login` in browser and confirm that no demo 1-click buttons or red banner appear.
* **Current Status**: **Open (Failing - verified via `go_live_check.php`)**

---

### SEC-04: Hardcoded Demo Staff Accounts and Known Default PINs in Codebase & Database
* **Finding ID**: `SEC-04`
* **Severity**: **HIGH**
* **Affected Application**: `Car-WashService`
* **File or Component**: `config/demo_accounts.php`, `database/carwash.sqlite`, `database/init.php`
* **Problem Description**:
  File `config/demo_accounts.php` is committed to version control and contains plaintext credentials:
  ```php
  'system_admin' => ['username' => 'admin',     'pin' => '111111'],
  'owner'        => ['username' => 'owner',     'pin' => '123456'],
  'manager'      => ['username' => 'manager',   'pin' => '123456'],
  'cashier'      => ['username' => 'cashier',   'pin' => '123456'],
  'washer1'      => ['username' => 'washer1',   'pin' => '123456'],
  'mechanic1'    => ['username' => 'mechanic1', 'pin' => '123456'],
  ```
  When running `scripts/go_live_check.php`, check #4 failed:
  ```
  [ FAIL ] No staff using known seed passwords (Known seed password verified for: owner, cashier, washer1, mechanic1)
  ```
* **Business Impact**:
  Anyone who has read the public documentation or user guides knows that the default PIN is `123456` or `111111`. If the shop opens for real business with these seed accounts, any employee or third party can log in as `owner` and access financial controls.
* **Recommended Solution**:
  1. Delete `config/demo_accounts.php` before production deployment.
  2. Force a mandatory PIN change on first login for all initial administrative users (`must_change_pin = 1`).
  3. Deactivate or delete sample staff accounts and have the real shop owner provision genuine staff accounts with unique, random 6-digit PINs.
* **Test Procedure**:
  Run `php scripts/go_live_check.php`. The check `No staff using known seed passwords` must report `[ OK ]`.
* **Current Status**: **Open (Failing - verified via `go_live_check.php`)**

---

### SEC-05: Sensitive Development Scripts and Historical Database Snapshots in Production Directory
* **Finding ID**: `SEC-05`
* **Severity**: **HIGH**
* **Affected Application**: `Car-WashService`
* **File or Component**: `scripts/dev_data_tool.php`, `scripts/dev_reset_logins.php`, `storage/snapshots/`
* **Problem Description**:
  The `scripts/` directory contains tools designed purely for local feature development:
  1. `dev_reset_logins.php`: Has a `--force-dev` command-line flag that resets all user accounts back to seed passwords even when `APP_ENV === 'production'`.
  2. `dev_data_tool.php`: Has commands to `reset`, `save`, and `restore` entire database tables, also allowing `--force-dev`.
  3. `storage/snapshots/`: Contains two full snapshot directories (`my_current_data_20261103_112411`, `pre_reset_20261103_112648`) containing unencrypted database dumps.
  Running `scripts/go_live_check.php` failed with:
  ```
  [ FAIL ] No dev helper scripts or snapshots present (Found: dev_reset_logins.php, dev_data_tool.php, snapshots/)
  ```
* **Business Impact**:
  If these scripts are invoked accidentally or maliciously via CLI or scheduler, live operational data could be overwritten or reset to sample testing states.
* **Recommended Solution**:
  1. Delete `dev_data_tool.php` and `dev_reset_logins.php` from production deployments.
  2. Delete or archive `storage/snapshots/` to a secure external cold backup.
* **Test Procedure**:
  Run `php scripts/go_live_check.php` and verify that the dev helper scripts check passes.
* **Current Status**: **Open (Failing - verified via `go_live_check.php`)**

---

### SEC-06: Uploads Directory Lacks Script Execution Prevention Barrier
* **Finding ID**: `SEC-06`
* **Severity**: **HIGH**
* **Affected Application**: `Car-WashService`
* **File or Component**: `c:\xampp\htdocs\Car-WashService\public\uploads\`
* **Problem Description**:
  In `Car-WashCloud`, the uploads directory (`assets/uploads/`) contains a hardened `.htaccess` file that explicitly disables PHP script execution:
  ```apache
  <FilesMatch "(?i)\.(php|phtml|php3|php4|php5|php7|php8|phps|phar|inc)$">
      Require all denied
  </FilesMatch>
  php_flag engine off
  Options -ExecCGI -Indexes
  ```
  However, in `Car-WashService`, `public/uploads/` has **no `.htaccess` file at all**. While `ImageStorage.php` performs validation on uploaded images, if an attacker discovers an upload vulnerability or bypass, any file uploaded with a PHP executable extension could be executed directly by requesting its URL (`http://localhost/Car-WashService/public/uploads/shell.php`).
* **Business Impact**:
  Potential remote code execution (RCE) on the local Mini-PC server if an image validation bypass or arbitrary file upload bug is discovered.
* **Recommended Solution**:
  Copy the hardened `.htaccess` script execution prevention file from `Car-WashCloud/assets/uploads/.htaccess` into `Car-WashService/public/uploads/.htaccess`.
* **Test Procedure**:
  Create a test script `test.php` inside `public/uploads/` and verify that browsing to `http://localhost/Car-WashService/public/uploads/test.php` returns `403 Forbidden` or `500 Server Error`, and does not execute PHP code.
* **Current Status**: **Open (Failing - verified file absence)**

---

### SEC-07: Internal Directories Lack Individual `.htaccess` Deny Rules (Defense-in-Depth)
* **Finding ID**: `SEC-07`
* **Severity**: **MEDIUM**
* **Affected Application**: `Car-WashService`
* **File or Component**: `config/`, `core/`, `database/`, `scripts/`, `tests/`, `docs/`, `bin/`
* **Problem Description**:
  In `Car-WashCloud`, every private subdirectory (`config/`, `includes/`, `scripts/`, `sql/`, `tests/`) contains its own individual `.htaccess` file containing `Deny from all` / `Require all denied`.
  In `Car-WashService`, these subdirectories have no individual `.htaccess` files. They rely entirely on a single RewriteRule in the root `.htaccess`:
  ```apache
  RewriteRule ^(config|core|database|storage|tests|docs|bin|scripts)/ - [F,L,NC]
  ```
  If Apache's `mod_rewrite` is disabled, if `AllowOverride` is not configured for the parent directory, or if the application is migrated to Nginx, IIS, or the PHP built-in server without custom URL rewrites, all internal directories become immediately readable.
* **Business Impact**:
  Configuration drift or web server misconfiguration would immediately expose database configurations, core classes, SQLite databases, and tests.
* **Recommended Solution**:
  Place a dedicated `.htaccess` file containing `Require all denied` inside `config/`, `core/`, `database/`, `scripts/`, `tests/`, `docs/`, and `bin/`.
* **Test Procedure**:
  Temporarily comment out the RewriteRule in root `.htaccess` and verify that requesting `http://localhost/Car-WashService/database/carwash.sqlite` still returns `403 Forbidden`.
* **Current Status**: **Open (Failing - verified file absence)**

---

### SEC-08: Content-Security-Policy (CSP) Header Missing on Direct PHP Output
* **Finding ID**: `SEC-08`
* **Severity**: **MEDIUM**
* **Affected Application**: `Car-WashService`
* **File or Component**: `index.php`, Apache response headers
* **Problem Description**:
  Although the root `.htaccess` includes a `<IfModule mod_headers.c>` block setting `Content-Security-Policy`, running `scripts/go_live_check.php` reports:
  ```
  [ FAIL ] CSP header present (Content-Security-Policy header missing from response)
  ```
  This occurs because the CLI pre-flight check inspects the headers returned by dynamic PHP scripts where `mod_headers` may not be active or where PHP response headers override Apache defaults.
* **Business Impact**:
  Without an enforced CSP header, client browsers lack defense-in-depth protection against cross-site scripting (XSS), script injection, and unauthorized frame embedding.
* **Recommended Solution**:
  Emit the CSP header in PHP code early in `config/app.php`:
  ```php
  if (!headers_sent()) {
      header("Content-Security-Policy: default-src 'self' 'unsafe-inline' data:; connect-src 'self' http://127.0.0.1:1818; font-src 'self' data:; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';");
  }
  ```
* **Test Procedure**:
  Run `php scripts/go_live_check.php` and verify check #12 (`CSP header present`) reports `[ OK ]`.
* **Current Status**: **Open (Failing - verified via `go_live_check.php`)**

---

### SEC-09: Authentication Logic Retains Plaintext Fallback for Legacy PINs
* **Finding ID**: `SEC-09`
* **Severity**: **MEDIUM**
* **Affected Application**: `Car-WashService`
* **File or Component**: `c:\xampp\htdocs\Car-WashService\core\Auth.php` (Lines 129–139)
* **Problem Description**:
  In `Auth::login()`, the password verification logic reads:
  ```php
  if (!empty($user['local_pin_hash']) && password_verify($pin, $user['local_pin_hash'])) {
      $pinValid = true;
  } elseif (!empty($user['password_hash']) && password_verify($pin, $user['password_hash'])) {
      $pinValid = true;
  } elseif (isset($user['local_pin']) && $user['local_pin'] !== null && (string)$user['local_pin'] === $pin) {
      // Legacy plain-text PIN matched: upgrade to bcrypt hash
      $pinValid = true;
      ...
  }
  ```
  If any legacy database record contains an un-hashed `local_pin` column, the application compares strings directly (`(string)$user['local_pin'] === $pin`), which is susceptible to timing attacks, and signifies that plaintext credentials may persist in older backups.
* **Business Impact**:
  Plaintext PINs in database columns violate password storage security standards (CWE-256).
* **Recommended Solution**:
  Run a one-time migration to hash all existing `local_pin` values into `local_pin_hash` using `password_hash($pin, PASSWORD_BCRYPT)` and drop the plain `local_pin` column. Remove the plaintext comparison fallback from `Auth.php`.
* **Test Procedure**:
  Inspect the `users` table schema in both databases to confirm `local_pin` is dropped or nullified, and verify authentication only succeeds against bcrypt hashes.
* **Current Status**: **Open (Present in codebase)**

---

### SEC-10: Local POS and Cloud API Base URLs Default to Plain HTTP
* **Finding ID**: `SEC-10`
* **Severity**: **LOW**
* **Affected Application**: Both (`Car-WashService` and `Car-WashCloud`)
* **File or Component**: `Car-WashService/config/app.php`, `Car-WashCloud/config/config.php`
* **Problem Description**:
  In `Car-WashService/config/app.php`:
  ```php
  define('API_BASE_URL', $protocol . '://' . $host . APP_URL);
  ```
  When tested by `scripts/go_live_check.php`:
  ```
  [ FAIL ] API_BASE_URL uses https (http://localhost/Car-WashService)
  ```
  Similarly, `Car-WashCloud/config/config.php` defaults to `http://localhost` if `HTTPS` server variables are not passed.
* **Business Impact**:
  If the POS tablet communicates with the local Mini-PC server over unencrypted HTTP across shop Wi-Fi, staff session tokens (`X-Staff-Token`), customer phone numbers, vehicle license plates, and sales transactions can be intercepted via packet sniffing.
* **Recommended Solution**:
  1. Generate a self-signed or local CA SSL certificate on the Mini-PC and bind Apache to port 443 with HTTPS.
  2. For the cloud portal, enforce HTTPS redirection and HSTS headers.
* **Test Procedure**:
  Run `php scripts/go_live_check.php` and verify check #9 (`API_BASE_URL uses https`) passes.
* **Current Status**: **Open (Failing - verified via `go_live_check.php`)**

---

### SEC-11: Cloud Localhost Development Fallback Permits Unrestricted Root User
* **Finding ID**: `SEC-11`
* **Severity**: **LOW**
* **Affected Application**: `Car-WashCloud`
* **File or Component**: `c:\xampp\htdocs\Car-WashCloud\config\config.php` (Lines 43–52)
* **Problem Description**:
  `config.php` defines a fallback for localhost:
  ```php
  define('DB_HOST', '127.0.0.1');
  define('DB_PORT', '3306');
  define('DB_NAME', 'carwash_cloud');
  define('DB_USER', 'root');
  define('DB_PASS', '');
  ```
  While production requires `config/local.php`, if a developer deploys the codebase to a staging or testing server named `localhost` or bound to `127.0.0.1`, it defaults to the superuser `root` with no password.
* **Business Impact**:
  Excessive database privileges for application runtime (violates Principle of Least Privilege).
* **Recommended Solution**:
  Require a dedicated MySQL user with limited DML/DDL permissions (`carwash_app_user`) even in local staging environments, and deprecate empty password fallbacks.
* **Test Procedure**:
  Inspect database credentials across all configuration files and verify no blank passwords exist.
* **Current Status**: **Open (Present in configuration)**

---

## 4. Summary of Security Blockers for Phase 2

To achieve security readiness, the following items must be resolved:
1. **Block `.git` and `graphify-out` over HTTP immediately** (`SEC-01`, `SEC-02`).
2. **Turn off `demo_mode` in database settings** (`SEC-03`).
3. **Quarantine or delete `demo_accounts.php`, `dev_data_tool.php`, `dev_reset_logins.php`, and `storage/snapshots/`** (`SEC-04`, `SEC-05`).
4. **Lock down `public/uploads/` against PHP script execution** (`SEC-06`).
5. **Ensure all subdirectories have defense-in-depth deny rules** (`SEC-07`).

