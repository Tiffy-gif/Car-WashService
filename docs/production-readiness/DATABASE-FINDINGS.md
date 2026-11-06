# Database Findings & Schema Integrity Audit
**Production Readiness Technical Audit &bull; Phase 1**
*System Scope: Car-WashService (Local Edge POS) & Car-WashCloud (Central Management Hub)*

---

## 1. Database Audit Executive Summary

During Phase 1 technical inspection, the database architecture, schema definitions, migration runners, database triggers, foreign keys, and transaction behaviors of both **Car-WashService** and **Car-WashCloud** were audited. 

The audit revealed significant architectural risks centered around the **dual-driver strategy** of `Car-WashService` (MySQL primary with SQLite fallback) and environment corruption in the local MySQL instance.

### Risk Level Breakdown

| Severity | Count | Primary Impact |
| :--- | :---: | :--- |
| **CRITICAL** | 4 | Local MariaDB/MySQL corruption causing server hangs; 60-second connection timeout latency; SQLite crash on `ON DUPLICATE KEY UPDATE` during every page load; 5 core operational tables missing from SQLite (`stock_movements`, etc.). |
| **HIGH** | 3 | MySQL-only functions (`CONCAT`, `MD5`, `NOW()`, `DATABASE()`) breaking runtime on SQLite; SQLite backups lack table DDL; Backup restore crashes on SQLite. |
| **MEDIUM** | 2 | Schema column inconsistencies (`cancelled_by_user_id`, `lane`, `expenses.is_active`); Missing immutable ledger triggers on SQLite. |
| **LOW** | 1 | Car-WashCloud lacks an offline database fallback. |
| **TOTAL** | **10** | **Database layer is NOT production-ready until driver parity and migrations are unified.** |

---

## 2. Database Findings Matrix

| Finding ID | Severity | Affected App | File or Component | Problem Summary | Current Status |
| :--- | :---: | :---: | :--- | :--- | :--- |
| `DB-01` | **CRITICAL** | Both | `c:\xampp\mysql\data\` | MariaDB development environment has over 1,950 bogus replication files (`master-*.info`) causing MariaDB to freeze on boot. | **Open (Failing)** |
| `DB-02` | **CRITICAL** | Car-WashService | `config/database.php`, `core/Database.php` | Hardcoded `'host' => 'localhost'` incurs a 60-second IPv6/IPv4 timeout before falling back to SQLite, causing extreme lag. | **Open (Failing)** |
| `DB-03` | **CRITICAL** | Car-WashService | `database/migrate_roles.php:208`, `database/init.php` | Unconditional `ON DUPLICATE KEY UPDATE` preparation throws a fatal SQL syntax error in SQLite on every page load. | **Open (Failing)** |
| `DB-04` | **CRITICAL** | Car-WashService | `database/migrate.php`, `database/carwash.sqlite` | `migrate_technical_warehouse.php` is omitted from `migrate.php`, causing `stock_movements`, `parts_requests`, and `timecards` to be absent from SQLite. | **Open (Failing)** |
| `DB-05` | **HIGH** | Car-WashService | `core/CloudSyncManager.php`, `api/pos/index.php` | Hardcoded MySQL dialect functions (`CONCAT(...)`, `MD5(...)`, `NOW()`, `SELECT DATABASE()`) crash or invalidate cache under SQLite. | **Open (Failing)** |
| `DB-06` | **HIGH** | Car-WashService | `core/Backup.php` (Lines 70–74) | Database backup dump logic only executes `SHOW CREATE TABLE` on MySQL; SQLite backups contain `DROP TABLE` and `INSERT` without `CREATE TABLE`. | **Open (Failing)** |
| `DB-07` | **HIGH** | Car-WashService | `core/Backup.php` (Lines 231, 256) | Database restore executes `SET FOREIGN_KEY_CHECKS=0`, which is invalid syntax in SQLite and aborts the restore process. | **Open (Failing)** |
| `DB-08` | **MEDIUM** | Car-WashService | `database/carwash.sqlite`, `tests/test_brief_compliance.php` | Schema missing columns (`orders.cancelled_by_user_id`, `order_tasks.lane`, `expenses.is_active`) expected by test suites. | **Open (Failing)** |
| `DB-09` | **MEDIUM** | Car-WashService | `database/migrate_immutable_ledgers.php` | Immutable triggers for `stock_movements` and `audit_logs` fail to install on SQLite, failing `test_ledger_immutable.php`. | **Open (Failing)** |
| `DB-10` | **LOW** | Car-WashCloud | `config/database.php` | Cloud hub has no offline database fallback and completely halts with an error message if MySQL is unavailable. | **Design Limitation** |

---

## 3. Detailed Database Findings

### DB-01: MariaDB Development Instance Corrupted by Multi-Source Replication Spool Files
* **Finding ID**: `DB-01`
* **Severity**: **CRITICAL**
* **Affected Application**: Both (`Car-WashService` and `Car-WashCloud`)
* **File or Component**: `c:\xampp\mysql\data\`
* **Problem Description**:
  During environment verification, attempts to connect to MySQL (`localhost:3306`) failed with:
  ```
  SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it
  ```
  When the MariaDB server was launched with `--console`, it logged thousands of lines:
  ```
  [ERROR] Master '   ': Slave I/O: Fatal error: Invalid (empty) username when attempting to connect to the master server.
  Reading Master_info: 'master-@0001.info'  Relay_info:'relay-log-@0001.info'
  ```
  A directory scan of `c:\xampp\mysql\data\` revealed **1,981 files**, including thousands of bogus replication state files (`master-*.info`, `relay-log-*.info`, `master-bay_number@0004size.info`). MariaDB attempts to initialize hundreds of defunct replication threads on startup, exhausting memory and hanging the server.
* **Business Impact**:
  1. `Car-WashCloud` cannot run at all on this development machine because it strictly requires MySQL.
  2. `Car-WashService` is forced into SQLite fallback mode for all operations.
* **Recommended Solution**:
  1. In the development XAMPP environment, cleanly purge the corrupt relay log and master info files (`master-*.info` and `relay-log-*.info`) from `c:\xampp\mysql\data\`.
  2. For live production shop deployments, provide a clean, automated MySQL/MariaDB service installation script that disables multi-source replication.
* **Test Procedure**:
  Start MariaDB via `c:\xampp\mysql_start.bat` and run `mysql -u root -e "SHOW DATABASES;"`. The command must return the list of databases without errors or hangs.
* **Current Status**: **Open (Failing in development environment)**

---

### DB-02: 60-Second Host Timeout Lag on `localhost` Before SQLite Fallback
* **Finding ID**: `DB-02`
* **Severity**: **CRITICAL**
* **Affected Application**: `Car-WashService`
* **File or Component**: `c:\xampp\htdocs\Car-WashService\config\database.php`, `core/Database.php`
* **Problem Description**:
  `config/database.php` specifies:
  ```php
  return [
      'driver'   => 'mysql',
      'host'     => 'localhost',
      'port'     => 3306,
      'dbname'   => 'carwash_db',
      'user'     => 'root',
      'pass'     => '',
      'sqlite_path' => dirname(__DIR__) . '/database/carwash.sqlite',
  ];
  ```
  In Windows environments, PDO connecting to `localhost` attempts an IPv6 socket connection (`[::1]:3306`). When MySQL is down, Windows network sockets wait up to **60 seconds** before returning a timeout exception. 
  Only after this 60-second delay does `Database::getInstance()` catch the `PDOException` and switch to SQLite:
  ```php
  } catch (PDOException $innerEx) {
      // Fallback to SQLite
      self::$instance = new PDO("sqlite:{$sqlitePath}", ...);
  }
  ```
  As observed during CLI test runs (`test_foundation.php`, `test_ledger_immutable.php`, `test_brief_compliance.php`), every single PHP execution froze for 60 seconds before executing.
* **Business Impact**:
  If a live car wash shop server experiences a MySQL crash, cashiers at the POS tablet will experience a 60-second freezing delay on every single button press, order creation, or receipt print. Customers waiting at the counter will be severely delayed.
* **Recommended Solution**:
  1. Change `'host'` to `'127.0.0.1'` to prevent IPv6 DNS resolution delays.
  2. Set `PDO::ATTR_TIMEOUT => 2` in the initial PDO connection options so that if MySQL is unreachable, the fallback to SQLite occurs in under 2 seconds rather than 60 seconds.
* **Test Procedure**:
  Stop MySQL and execute `php -r "require 'core/Database.php'; \$t=microtime(true); Database::getInstance(); echo round(microtime(true)-\$t, 2).'s';"` The elapsed time must be less than 2 seconds.
* **Current Status**: **Open (Failing - verified 60s lag)**

---

### DB-03: Incompatible `ON DUPLICATE KEY UPDATE` Crashes `migrate_roles.php` on Every SQLite Page Load
* **Finding ID**: `DB-03`
* **Severity**: **CRITICAL**
* **Affected Application**: `Car-WashService`
* **File or Component**: `c:\xampp\htdocs\Car-WashService\database\migrate_roles.php` (Line 208), `database/init.php`
* **Problem Description**:
  Every request to `index.php` loads `database/init.php`, which runs `initDatabase()`.
  In `migrate_roles.php`, line 208:
  ```php
  $insRoleStmt = $pdo->prepare("
      INSERT INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE 
          name_en = VALUES(name_en), ...
  ");
  ```
  This statement is prepared **unconditionally** before checking whether the database driver is SQLite.
  In SQLite, `ON DUPLICATE KEY UPDATE` is invalid syntax. PDO immediately throws:
  ```
  initDatabase error: SQLSTATE[HY000]: General error: 1 near "DUPLICATE": syntax error
  #0 migrate_roles.php(208): PDO->prepare(...)
  #1 init.php(619): migrateRoles()
  #2 init.php(914): initDatabase()
  ```
  Because this exception is caught and logged on every request, `storage/logs/migration_error.log` is flooded with hundreds of errors, and role migrations fail to complete.
* **Business Impact**:
  The application fails to maintain schema state when operating in offline SQLite mode, and system logs are inundated with redundant error stack traces.
* **Recommended Solution**:
  Check `$isSqlite` **before** preparing the statement:
  ```php
  if ($isSqlite) {
      $insRoleStmt = $pdo->prepare("INSERT OR REPLACE INTO roles (...) VALUES (...)");
  } else {
      $insRoleStmt = $pdo->prepare("INSERT INTO roles (...) VALUES (...) ON DUPLICATE KEY UPDATE ...");
  }
  ```
* **Test Procedure**:
  Run `php database/migrate_roles.php` with SQLite active. It must complete with exit code 0 and zero errors in `migration_error.log`.
* **Current Status**: **Open (Failing - verified via `migration_error.log`)**

---

### DB-04: Five Core Tables Missing from SQLite Because `migrate_technical_warehouse.php` is Omitted from `migrate.php`
* **Finding ID**: `DB-04`
* **Severity**: **CRITICAL**
* **Affected Application**: `Car-WashService`
* **File or Component**: `database/migrate.php` (Lines 71–87), `database/carwash.sqlite`
* **Problem Description**:
  `database/migrate.php` defines the master migration sequence in `runMigrations()`.
  While `migrate_technical_warehouse.php` exists in the repository and defines:
  1. `stock_movements` (Immutable inventory ledger)
  2. `parts_requests` (Technician bay parts requisition)
  3. `inspection_checklists` (Mandatory safety checks)
  4. `staff_timecards` (Staff attendance tracking)
  5. `timecard_corrections` (Audit log for shift punch edits)
  
  The function `migrateTechnicalWarehouse()` is **completely missing from `runMigrations()` in `migrate.php`**.
  As a direct result, when `test_brief_compliance.php` and `test_ledger_immutable.php` ran against `carwash.sqlite`, both tests failed:
  ```
  [FAIL] Table 'stock_movements' exists -> Table missing from schema
  [FAIL] Table 'parts_requests' exists -> Table missing from schema
  [FAIL] Table 'inspection_checklists' exists -> Table missing from schema
  [FAIL] Table 'staff_timecards' exists -> Table missing from schema
  [FAIL] Table 'timecard_corrections' exists -> Table missing from schema
  ```
* **Business Impact**:
  If the shop operates on SQLite (offline mode):
  - Warehouse stock deductions cannot be recorded.
  - Technicians cannot request parts from Bay 4.
  - Safety checklists cannot be saved.
  - Staff timecards and clock-in/clock-out actions throw fatal database exceptions.
* **Recommended Solution**:
  Include `require_once __DIR__ . '/migrate_technical_warehouse.php';` and call `migrateTechnicalWarehouse($force);` inside `runMigrations()` in `database/migrate.php`.
* **Test Procedure**:
  Run `php database/migrate.php` and then inspect SQLite tables using `SELECT name FROM sqlite_master WHERE type='table'`. Confirm all 5 tables exist.
* **Current Status**: **Open (Failing - verified via `test_brief_compliance.php`)**

---

### DB-05: MySQL Dialect Functions (`CONCAT`, `MD5`, `NOW()`, `DATABASE()`) Hardcoded in Core Classes
* **Finding ID**: `DB-05`
* **Severity**: **HIGH**
* **Affected Application**: `Car-WashService`
* **File or Component**: `core/CloudSyncManager.php`, `api/pos/index.php`, `scripts/dev_data_tool.php`
* **Problem Description**:
  Multiple core runtime scripts contain MySQL-only SQL functions that do not exist natively in SQLite:
  1. **`CONCAT(...)`**:
     In `CloudSyncManager.php` (Lines 444, 462, 490):
     ```php
     $pdo->exec("UPDATE orders SET is_synced = 1 ... WHERE ... OR CONCAT('sale-', id) IN ({$inList})");
     ```
     In SQLite, `CONCAT()` throws `PDOException: no such function: CONCAT`. (SQLite uses `||` for string concatenation).
  2. **`MD5(...)`**:
     In `api/pos/index.php` (Line 146):
     ```php
     $priceHash = $pdo->query("SELECT MD5(CONCAT(...))")->fetchColumn();
     ```
     In SQLite, `MD5()` throws `no such function: MD5`. The catch block falls back to generating a random hash on every request (`md5((string)time())`), breaking tablet cache synchronization.
  3. **`NOW()`**:
     In `CloudSyncManager.php` (Line 828) and `test_ledger_immutable.php`:
     ```php
     INSERT INTO sync_conflicts (...) VALUES (..., NOW(), NOW(), 'pending', NOW());
     ```
     In SQLite, `NOW()` throws `no such function: NOW`. (SQLite uses `CURRENT_TIMESTAMP` or `datetime('now')`).
  4. **`DATABASE()`**:
     In `scripts/dev_data_tool.php` (Line 50):
     ```php
     $dbname = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
     ```
     In SQLite, `DATABASE()` throws `no such function: DATABASE`.
* **Business Impact**:
  Core workflows (cloud sync confirmation, conflict resolution, pos revision fingerprinting) fail when operating under SQLite.
* **Recommended Solution**:
  1. Replace SQL string concatenation with standard SQL or perform formatting in PHP code.
  2. Use portable SQL datetime functions (`CURRENT_TIMESTAMP`) rather than MySQL `NOW()`.
  3. When initializing SQLite PDO connections in `Database.php`, register SQLite user-defined functions for `MD5` and `CONCAT`:
     ```php
     $pdo->sqliteCreateFunction('MD5', 'md5', 1);
     $pdo->sqliteCreateFunction('CONCAT', function(...$args) { return implode('', $args); });
     $pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
     ```
* **Test Procedure**:
  Execute sync push confirmation and revision computation under SQLite driver and confirm zero SQL errors.
* **Current Status**: **Open (Present in codebase)**

---

### DB-06: Database Backup Dump on SQLite Lacks Table Creation DDL
* **Finding ID**: `DB-06`
* **Severity**: **HIGH**
* **Affected Application**: `Car-WashService`
* **File or Component**: `c:\xampp\htdocs\Car-WashService\core\Backup.php` (Lines 63–76)
* **Problem Description**:
  In `Backup::generateSql()`, table schema generation reads:
  ```php
  $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
  if ($driver === 'mysql') {
      $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
      $createSql = $createStmt['Create Table'] ?? '';
      $dump .= $createSql . ";\n\n";
  }
  // Dump Data...
  ```
  If `$driver === 'sqlite'`, the logic emits `DROP TABLE IF EXISTS ...` and immediately proceeds to `INSERT INTO ...` without generating the `CREATE TABLE` structure.
* **Business Impact**:
  If a shop running on SQLite generates a backup file (either manual download or daily 2:00 AM auto-backup), the resulting `.sql` file cannot be restored into an empty database because the table creation statements are completely absent.
* **Recommended Solution**:
  For SQLite, query `sqlite_master` to retrieve the original DDL:
  ```php
  } elseif ($driver === 'sqlite') {
      $createStmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '{$table}'")->fetch(PDO::FETCH_ASSOC);
      $createSql = $createStmt['sql'] ?? '';
      $dump .= $createSql . ";\n\n";
  }
  ```
* **Test Procedure**:
  Run `php scripts/backup_daily.php` with SQLite active, inspect the generated `.sql` file in `storage/backups/`, and verify that `CREATE TABLE` statements are present for all tables.
* **Current Status**: **Open (Failing - verified in code)**

---

### DB-07: Database Restore Executes MySQL-Only Syntax and Fails on SQLite
* **Finding ID**: `DB-07`
* **Severity**: **HIGH**
* **Affected Application**: `Car-WashService`
* **File or Component**: `c:\xampp\htdocs\Car-WashService\core\Backup.php` (Lines 231, 256)
* **Problem Description**:
  In `Backup::restoreFromSql()`:
  ```php
  $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
  // ... loop executing SQL queries ...
  $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
  ```
  In SQLite, `SET FOREIGN_KEY_CHECKS` is not recognized and throws a syntax error. (SQLite uses `PRAGMA foreign_keys = OFF;`).
  As a result, calling `Backup::restoreFromSql()` under SQLite crashes on the very first query.
* **Business Impact**:
  The shop administrator cannot restore database backups via the settings UI when running on SQLite.
* **Recommended Solution**:
  Wrap foreign key toggling in driver checks:
  ```php
  if (Database::getDriver() === 'sqlite') {
      $pdo->exec("PRAGMA foreign_keys = OFF;");
  } else {
      $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
  }
  ```
* **Test Procedure**:
  Test restoring a backup file via `Backup::restoreFromSql()` under SQLite driver and confirm clean completion.
* **Current Status**: **Open (Failing - verified in code)**

---

### DB-08: Schema Column Inconsistencies Identified by Automated Test Suite
* **Finding ID**: `DB-08`
* **Severity**: **MEDIUM**
* **Affected Application**: `Car-WashService`
* **File or Component**: `database/carwash.sqlite`, `tests/test_brief_compliance.php`, `tests/test_foundation.php`
* **Problem Description**:
  When running `tests/test_brief_compliance.php` and `tests/test_foundation.php`, several required columns were reported missing from the active SQLite database:
  1. `orders.cancelled_by_user_id` & `orders.cancel_approved_by_user_id` (Required for cashier cancellation tracking).
  2. `order_tasks.lane` (Required for technical Bay 4 simultaneous mechanical vs inspection lanes).
  3. `customers.membership_tier_code` (Required for membership tier mapping).
  4. `expenses.is_active` (Required by `test_foundation.php:235`).
* **Business Impact**:
  Auditing cancelled orders, assigning simultaneous dual-lane tasks in Bay 4, and running automated foundation tests throw exceptions.
* **Recommended Solution**:
  Update database migration scripts (`migrate_foundation.php`, `migrate_order_cancel_audit.php`) to ensure these columns are defined consistently in both SQLite and MySQL table definitions.
* **Test Procedure**:
  Re-run `php tests/test_brief_compliance.php` and confirm all table/column checks pass.
* **Current Status**: **Open (Failing - verified via test output)**

---

### DB-09: Immutable Ledger Triggers Missing on SQLite Driver
* **Finding ID**: `DB-09`
* **Severity**: **MEDIUM**
* **Affected Application**: `Car-WashService`
* **File or Component**: `database/migrate_immutable_ledgers.php`, `tests/test_ledger_immutable.php`
* **Problem Description**:
  `test_ledger_immutable.php` checks that `stock_movements` and `audit_logs` cannot be updated or deleted:
  ```
  [FAIL] Trigger 'trg_stock_movements_before_delete' exists -> Missing trigger
  [FAIL] Trigger 'trg_stock_movements_before_update' exists -> Missing trigger
  [FAIL] Trigger 'trg_audit_logs_before_delete' exists -> Missing trigger
  [FAIL] Trigger 'trg_audit_logs_before_update' exists -> Missing trigger
  ```
  Because `stock_movements` was not created in SQLite (Finding `DB-04`), trigger creation on `stock_movements` failed silently.
* **Business Impact**:
  The immutable ledger fraud-prevention requirement is inactive on the local SQLite store.
* **Recommended Solution**:
  Ensure `migrate_technical_warehouse.php` runs before `migrate_immutable_ledgers.php` so that `stock_movements` exists prior to attaching triggers.
* **Test Procedure**:
  Run `php tests/test_ledger_immutable.php`. All 8 trigger tests must pass.
* **Current Status**: **Open (Failing - verified via test output)**

---

### DB-10: Car-WashCloud Has No Local SQLite Offline Fallback Mode
* **Finding ID**: `DB-10`
* **Severity**: **LOW**
* **Affected Application**: `Car-WashCloud`
* **File or Component**: `c:\xampp\htdocs\Car-WashCloud\config\database.php` (Lines 33–37)
* **Problem Description**:
  `Car-WashCloud/config/database.php` has no offline fallback:
  ```php
  try {
      self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
  } catch (PDOException $e) {
      error_log("Database connection failure: " . $e->getMessage());
      die("A database connection error occurred. Please check database configuration.");
  }
  ```
  While this is standard for central cloud web applications hosted on managed cloud infrastructure (AWS RDS, cPanel MariaDB), it prevents running the cloud dashboard on an offline testing laptop when MySQL is down.
* **Business Impact**:
  Architectural design limitation; does not affect live cloud hosting where MySQL is a managed daemon, but prevents offline dual-app testing.
* **Recommended Solution**:
  Maintain MySQL as mandatory for `Car-WashCloud` in production, but document the dependency clearly in the deployment manual.
* **Test Procedure**:
  Document in `FIRST_START_CHECKLIST.md` that `Car-WashCloud` requires an active MySQL 8.0 / MariaDB 10.4+ service.
* **Current Status**: **Known Architectural Constraint**

---

## 4. Summary of Database Blockers for Phase 2

To achieve database readiness, the following fixes are strictly required:
1. **Resolve MariaDB corruption in the development environment** (`DB-01`).
2. **Eliminate 60-second connection timeout on localhost** (`DB-02`).
3. **Fix `ON DUPLICATE KEY UPDATE` syntax error in `migrate_roles.php`** (`DB-03`).
4. **Wire `migrate_technical_warehouse.php` into `migrate.php` sequence** (`DB-04`).
5. **Implement SQLite UDF polyfills or replace MySQL dialect functions (`CONCAT`, `MD5`, `NOW()`)** (`DB-05`).
6. **Fix SQLite backup DDL generation and restore syntax in `Backup.php`** (`DB-06`, `DB-07`).

