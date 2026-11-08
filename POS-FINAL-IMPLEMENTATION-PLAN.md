# Car-WashService POS Final Implementation Plan

**Document ID:** POS-PLN-2026-FINAL
**Project:** Car-WashService (Local Counter Terminal)
**Scope:** Phased Engineering Roadmap, Technical Migration, Verification Plan, Release Governance
**Date:** November 8, 2026 (Updated - Round 11)
**Status:** Engineering Proposal – Awaiting Separate Executive Authorization (NOT Production-Ready Until Released)

---

## Executive Overview & Architectural Baseline

This implementation plan provides the technical execution blueprint for the Car-WashService Point-of-Sale (POS) counter system. It operationalizes the **six business decisions confirmed by the business owner on November 8, 2026**, defines technical design specifications, documents unresolved technical dependencies, and establishes a strict, non-destructive migration roadmap.

### Core Architectural Guardrails:
1. **100% Local LAN Self-Sufficiency:** The counter terminal functions with zero dependencies on external cloud APIs, public domains, or reverse tunnels. All fonts, styles, scripts, and runtime dependencies reside on the local counter server.
2. **Upfront Payment Service Gate:** Confirmed payment (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at IS NOT NULL`) are the sole mandatory gate pass before vehicle admission to wash apron or mechanical bays.
3. **Lift First $\rightarrow$ Wash Second Sequencing:** Mixed-service orders complete Bay 4 mechanical lift work prior to entering wash bays, ensuring spotless presentation at customer handover.
4. **Decoupled Financial & Presentation Layers:** Physical printer failure displays an advisory alert but never blocks wash apron operations. Any physical-printing gate (`require_receipt_before_wash`) is an **unapproved proposal requiring a separate owner decision**.
5. **Discrete Double-Entry Financial Ledgers:** Strict separation between **Net Settled Revenue** and **Net Operating Profit**, with unrecorded COGS flagged rather than assumed $0.00.
6. **Planned Test Status:** The 58 verification test specifications from [POS-IMPLEMENTATION-TEST-MATRIX.md](POS-IMPLEMENTATION-TEST-MATRIX.md) are **planned test specifications**; they have not been executed and do not represent passed tests.
7. **Executive Authorization Requirement:** This document is an engineering implementation proposal. The system remains strictly non-production-ready until all phases pass acceptance testing under separately authorized conditions and receive formal management release sign-off.

---

## Implementation Roadmap Overview

The implementation roadmap is organized into six sequential phases (Phases 0 through 5):

```mermaid
flowchart TD
    P0["Phase 0: Strictly Read-Only Inspection\n(Source files, git status, runtime versions, log review)"] --> P1["Phase 1: Technical Design & Unresolved Dependencies\n(Two-table KHQR schema, reversal finality, timestamps, fallbacks)"]
    P1 --> P2["Phase 2: Data & Domain Design Review\n(Schema impact, non-destructive migrations, exceptions view)"]
    P2 --> P3["Phase 3: Implementation Work Packages\n(WP-1 Gate, WP-2 Bays, WP-3 UI, WP-4 Ledgers, WP-5 Reports)"]
    P3 --> P4["Phase 4: Planned Test & Acceptance Execution\n(58 Test Matrix specifications, hardware testing, concurrency stress)"]
    P4 --> P5["Phase 5: Release Readiness & Staging Prerequisites\n(Isolated backup verification, runbook, rollback, executive sign-off)"]
```

---

## Phase 0: Strictly Read-Only Source, Runtime & Configuration Inspection

### 0.1 Objective
Establish an uncompromised, verified baseline of the local execution environment using strictly read-only inspection methods. Phase 0 contains **inspection only**.

### 0.2 Explicit Prohibitions & Safety Boundaries
Phase 0 strictly prohibits and does not require:
- **Never start a stopped database service** (`net start`, `service start`).
- **Never repair or modify an unstable database** (`REPAIR TABLE`, `mysqlcheck`).
- **Never create databases, dumps, or restores** (`CREATE DATABASE`, `mysqldump`, `mysql < dump.sql`).
- **Never clean, reset, stash, or overwrite existing user changes** in the Git working tree.
- **Inspect and report existing service status only.** If the MariaDB service is stopped, offline, or inaccessible, Phase 0 inspects the service status, configuration files (`my.ini`), and system event logs, reporting the status in the inspection log without attempting to start, recover, or repair the database.
- **Phase 0 must remain strictly read-only and separately authorized.**

### 0.3 Inspection Activities & Validation Checklist

| Inspection Domain | Inspection Target | Read-Only Method | Success Criteria |
| :--- | :--- | :--- | :--- |
| **Source Integrity** | Working tree & branch state | `git status` (without resets/stashes) | Inspect clean working tree on `main` or active changes; zero unapproved file modifications. |
| **PHP Runtime** | CLI & Web PHP modules | `php -v` & `php -m` (non-intrusive) | PHP 8.2+ verified; PDO_MySQL, OpenSSL, mbstring, and GD loaded. |
| **Database Server** | MariaDB version & status | Non-mutating status / service check | MariaDB 10.4+ / MySQL 8.0+ version inspected; service status reported as-is. |
| **Web Server** | Apache port binding | `netstat -ano \| findstr :80` | Port 80 bound locally; virtual host routes to local project root. |
| **Configuration Files** | Database credentials & paths | File inspection without credential exposure | Connection parameters verified against local instance; no secrets logged. |
| **System Logs** | Apache & PHP error logs | Log file inspection (`tail` / view) | Zero critical startup errors, segmentation faults, or permission blocks. |
| **Backup Documentation** | Disaster recovery documentation | Review existing operational runbooks | Existing backup SOP reviewed; risk items identified for Phase 5 staging. |

---

## Phase 1: Technical Design Specifications & Unresolved Dependencies

### 1.1 TD-1: Scoped KHQR Reference Uniqueness & Two-Table Schema (UTD-4)
* **Operational Flow:** The counter terminal utilizes an IoT soundbox and merchant smartphone bank app.
  1. **Primary Alert:** Soundbox chime alerts cashier to incoming funds.
  2. **Phone App Match:** If multiple customers are checking out simultaneously or amounts are identical, the soundbox chime alone is insufficient; the cashier **must match the transaction on the counter merchant phone**.
  3. **Authentic Reference Capture:** Cashier reads the genuine bank transaction reference code (`payment_ref`) from the bank notification and enters it into the POS interface.
  4. **Customer Screen Rejection:** Smartphone screens presented by customers are strictly rejected as proof of payment.
  5. **No Synthetic TRX:** Backend generation of synthetic reference strings (`TRX-` via `uniqid()`) is prohibited.
* **The MariaDB Nullable Column Vulnerability:**
  - Under SQL standards, `NULL != NULL` in unique indexes. If composite columns (`provider`, `merchant_account_id`, `payment_ref`) allow `NULL`, multiple identical rows with `NULL` bypass duplicate protection.
  - To enforce 100% database-level integrity, digital payments are separated from cash across two tables:
    1. General Tender Ledger (`order_payments`): Records financial amounts for all tenders; cash payments carry zero digital reference requirements.
    2. Dedicated Digital Ledger (`order_digital_payments`): Mandatory `NOT NULL` for `provider`, `merchant_account_id`, and `payment_ref`, enforced by `CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref)` with explicit foreign keys to `order_payments` and `orders`.
* **Transaction Validation & Rollback Contract:**
  - Database constraints alone cannot verify that every `khqr` tender possesses a corresponding detail row. This is enforced via backend application transactions:
    * Each digital payment must have exactly one row in `order_digital_payments`.
    * Cash payments must **never** create a row in `order_digital_payments`.
    * If digital insertion fails (duplicate bank reference or null attribute), the entire transaction rolls back atomically.
* **Provisional Reference Format Notice:**
  - Describing references as "4 to 8 digits" is **provisional** and not a confirmed universal standard.
  - References are **not globally unique**; uniqueness is scoped strictly to `(provider, merchant_account_id, payment_ref)`.
  - Official ABA PayWay and NBC Bakong documentation must be reviewed before finalizing format rules.

### 1.2 TD-2: Bank Reversal Outcomes, Timeout Safety & Unapproved Waiting Periods (UTD-3)
* **Three Distinct Reversal Outcomes:**
  1. *Definitive Bank Rejection:* Bank clearinghouse explicitly rejects the reversal request.
  2. *Request Timeout with Unknown Bank Outcome:* Network disconnect or gateway timeout occurs. **A timeout must NEVER be treated as a definitive rejection.**
  3. *Confirmed Bank Settlement:* Reversal cleared and credited to the customer.
* **Safety Reality on Offline LAN Counter:**
  - On an offline local LAN counter operating without direct bank gateway API integrations, **no automated mechanism exists to guarantee bank reversal finality**.
  - **No Automatic Retry on Timeout:** An uncertain reversal request must **never be automatically retried**, as retrying in-flight requests risks duplicate debits on the banking network.
  - **Replacement Cash Payout is Strictly BLOCKED:** The POS cashier interface **must not provide an active replacement-cash payout button or automated workflow** while the bank outcome remains uncertain.
  - **Unapproved 72-Hour Example Clarification:** Any mention of a "72-hour waiting period" is strictly an **unapproved operational example requiring bank and owner validation**. A 72-hour delay does NOT prove bank finality and does NOT permit automatic cash payouts.
  - **Process Remains Unresolved:** The replacement cash refund workflow is classified as an **Unresolved Technical Dependency**, requiring an owner-approved operational procedure before any technical implementation can be designed.

### 1.3 TD-3: Reporting Timestamps & Period Bounding (UTD-2)
* **Timestamp Distinctions:**
  - `provider_event_at`: External provider/bank timestamp.
  - `recorded_at`: Local POS ledger recording timestamp.
  - `disbursed_at` / `settled_at`: Currency departure (`disbursed_at`) or bank settlement confirmation (`settled_at`).
* **Reporting Period Recognition:**
  - Cash Payments & Manually Verified KHQR: Recognized on `recorded_at`.
  - Cash Refunds: Recognized on `disbursed_at`.
  - Settled Digital Refunds: Recognized on `settled_at`.
* **Period Boundary Formulation:**
  - Strict half-open interval: `event_timestamp >= :start_timestamp AND event_timestamp < :end_timestamp`. Both start and end are strictly bounded.
  - Delayed provider notifications recorded after a period cut point fall into the subsequent period, ensuring closed historical reports remain immutable.
* **Schema Design Dependency (UTD-2):**
  - These discrete timestamp columns do not exist in the current `orders` table ([init.php](database/init.php)) and require creation during Phase 2.

### 1.4 TD-4: Decoupled Period-Aware Historical Fallback Architecture (UTD-1)
* **Elimination of Unsupported Assumptions:**
  - Removed undefined flags (`is_initial_migration`) and non-existent fields (the schema in [init.php](database/init.php#L242-L280) possesses `total_usd`, `paid_at`, and `cancelled_at`, but **does NOT contain `orders.refunded_amount`**).
* **Period-Aware Fallback Principles:**
  - Payment recognition and refund recognition are evaluated independently within `[start_date, end_date)`.
  - Historical revenue is **never silently omitted** when an order has both historical payment information and subsequent ledger payments.
  - A subsequent ledger payment must **never be automatically added** to the full historical order total.
  - The existence of a subsequent ledger payment must not automatically exclude the historical order amount.
  - Split payments must not be double-counted.
  - Unreconstructable amounts are classified as **Historical Reconciliation Exceptions**, reported under audit, and **never silently included in confirmed revenue or treated as zero**.

#### Realistic Historical Scenarios:
1. **Fully Historical Paid Order (Unconflicted):** Order #101 created pre-cutover, `paid_at = '2026-10-15'`, `total_usd = 25.00`, `payment_status = 'paid'`. Zero rows in `order_payments` or `order_refunds`. Recognized in Oct 2026 as Confirmed Historical Payment = +$25.00. 100% confirmed.
2. **Historical Order with Subsequent Partial Ledger Payment (Split Payment):** Order #102 created pre-cutover, `total_usd = 50.00`, `payment_status = 'paid'`. After cutover, a partial payment of $20.00 is recorded in `order_payments` (`recorded_at = '2026-11-10'`). Confirmed Ledger in Nov = +$20.00. The historical database cannot prove whether the $20.00 is part of the $50.00 or additional to it; the historical order is isolated as a **Historical Split-Payment Exception** ($50.00 under audit on Exceptions tab) rather than guessing an allocation formula.
3. **Historical Order with Multiple Post-Cutover Partial Refunds:** Order #103 created pre-cutover, `total_usd = 60.00`, `payment_status = 'paid'`. Two partial refunds occur post-cutover: Refund A for $10 on Nov 12, Refund B for $15 on Nov 15. In Oct 2026, Confirmed Payment = +$60.00. In Nov 2026, Settled Refunds = +$25.00 ($10 + $15). Payments and refunds recognized independently in respective periods without disappearing.
4. **Historical Cancellation with No Reconstructable Refund Amount:** Order #104 created pre-cutover, `total_usd = 40.00`, `payment_status = 'paid'`, `cancelled_at = '2026-10-18'`. `orders.refunded_amount` does not exist in schema. Excluded from Confirmed Revenue and classified as a **Historical Unreconciled Cancellation Exception** ($40.00 under audit) for owner manual review.

* **Status:** The reporting algorithm is **not complete** until demonstrated against actual source fields and realistic examples during Phase 2.

### 1.5 TD-5: Unrecorded COGS Handling in Operating Profit
* Net Operating Profit = Net Settled Revenue - (Tracked COGS + Washer Commissions + Mechanic Commissions + Logged Expenses).
* If any sold line items lack cost data (`cogs_amount IS NULL`), Net Operating Profit **must not assume $0.00 COGS**. The report flags an **"Untracked Cost Alert"** indicating the count of untracked items (`untracked_cost_count`).

### 1.6 TD-6: Receipt Cutover Timestamp Configuration
* Cutover is governed by setting `receipt_v2_cutover_timestamp` in `settings`.
* **Fail-Closed Rule:** If the setting is missing or unparseable, the system fails closed (enforces electronic receipt requirement for all orders without exception).

---

## Phase 2: Data and Domain Design Review

### 2.1 Table Impact Matrix

| Table Name | Operation | Proposed Columns / Modifications | Purpose |
| :--- | :--- | :--- | :--- |
| `orders` | Read / Update | `receipt_issued_at DATETIME NULL`<br/>`receipt_number VARCHAR(50) NULL`<br/>`discount_approved_by INT NULL` | Decouple electronic receipt from physical print; track manager discount authorization. |
| `order_payments` | Create (New Table) | `id INT AUTO_INCREMENT PRIMARY KEY`<br/>`order_id INT NOT NULL`<br/>`payment_method ENUM('cash','khqr') NOT NULL`<br/>`amount_usd DECIMAL(10,2) NOT NULL`<br/>`amount_khr INT NOT NULL`<br/>`exchange_rate INT NOT NULL`<br/>`cashier_id INT NOT NULL`<br/>`recorded_at DATETIME NOT NULL`<br/>`CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id)`<br/>`INDEX idx_order_payments_order (order_id)`<br/>`INDEX idx_order_payments_recorded (recorded_at)` | General tender ledger for cash & digital payments; explicit foreign keys; cash carries no digital reference constraints. |
| `order_digital_payments` | Create (New Table) | `id INT AUTO_INCREMENT PRIMARY KEY`<br/>`payment_id INT NOT NULL UNIQUE`<br/>`order_id INT NOT NULL`<br/>`provider VARCHAR(50) NOT NULL`<br/>`merchant_account_id VARCHAR(50) NOT NULL`<br/>`payment_ref VARCHAR(100) NOT NULL`<br/>`provider_event_at DATETIME NULL`<br/>`created_at DATETIME NOT NULL`<br/>`CONSTRAINT fk_digital_payments_payment FOREIGN KEY (payment_id) REFERENCES order_payments(id) ON DELETE CASCADE`<br/>`CONSTRAINT fk_digital_payments_order FOREIGN KEY (order_id) REFERENCES orders(id)`<br/>`CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref)` | Dedicated digital transaction ledger; mandatory non-null attributes enforce 100% duplicate protection without nullable loopholes. |
| `order_refunds` | Create (New Table) | `id INT AUTO_INCREMENT PRIMARY KEY`<br/>`order_id INT NOT NULL`<br/>`payment_id INT NULL`<br/>`refund_seq INT NOT NULL`<br/>`amount_usd DECIMAL(10,2) NOT NULL`<br/>`amount_khr INT NOT NULL`<br/>`settlement_status ENUM('authorized_pending','cash_disbursed','bank_reversal_pending','settled','failed','bank_reversal_timeout','bank_reversal_blocked','cancelled')`<br/>`authorized_by INT NOT NULL`<br/>`disbursed_at DATETIME NULL`<br/>`settled_at DATETIME NULL`<br/>`bank_reversal_ref VARCHAR(100) NULL`<br/>`replaced_refund_id INT NULL`<br/>`CONSTRAINT fk_order_refunds_order FOREIGN KEY (order_id) REFERENCES orders(id)` | Finite state machine ledger for customer refunds and reversals. |
| `order_refund_events` | Create (New Table) | `id INT AUTO_INCREMENT PRIMARY KEY`<br/>`refund_id INT NOT NULL`<br/>`event_type VARCHAR(50) NOT NULL`<br/>`from_status VARCHAR(50) NOT NULL`<br/>`to_status VARCHAR(50) NOT NULL`<br/>`actor_id INT NOT NULL`<br/>`notes TEXT NULL`<br/>`created_at DATETIME NOT NULL`<br/>`CONSTRAINT fk_order_refund_events_refund FOREIGN KEY (refund_id) REFERENCES order_refunds(id) ON DELETE CASCADE` | Append-only audit history for refund state transitions. |
| `bays` | Update | Index on `(status, is_active)` | Atomic conditional reservation locks. |
| `bay_tasks` | Update | `task_sequence INT NOT NULL DEFAULT 1`<br/>`status ENUM('waiting','in_progress','completed','pending_prereq')` | Enforce Lift First $\rightarrow$ Wash Second staging. |
| `settings` | Read / Insert | `receipt_v2_cutover_timestamp DATETIME` | Authorized cutover date. |

### 2.2 Non-Destructive Migration Script (`database/migrate_pos_workflow_v3.php`)
* **Execution Rules:**
  1. The script uses idempotent DDL (`CREATE TABLE IF NOT EXISTS`) and inspects `information_schema.columns` before attempting column additions.
  2. Zero existing data rows in `orders` or `order_items` are altered, deleted, or converted.
  3. Pre-cutover ambiguous tickets are left untouched for owner review.

### 2.3 Historical Unreconciled Exceptions View
Create an informational SQL view for the owner reporting dashboard:
```sql
CREATE OR REPLACE VIEW view_historical_unreconciled_exceptions AS
SELECT
    o.id AS order_id,
    o.order_number,
    o.customer_name,
    o.plate_number,
    o.total_usd,
    o.status AS operational_status,
    o.payment_status,
    o.created_at,
    o.started_at,
    o.finished_at,
    o.cancelled_at,
    CASE
        WHEN o.status = 'finished' AND o.payment_status = 'unpaid' THEN 'Finished but Unpaid'
        WHEN o.status = 'paid' AND o.cancelled_at IS NOT NULL THEN 'Paid but Cancelled (Unknown Refund Amount)'
        WHEN o.status = 'paid' AND o.finished_at IS NULL AND o.cancelled_at IS NULL THEN 'Paid but Uncompleted'
        WHEN o.status = 'paid' AND EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id = o.id) THEN 'Historical Split Payment (Allocation Indeterminate)'
        ELSE 'Timestamp Conflict'
    END AS exception_reason
FROM orders o
WHERE o.created_at < (SELECT setting_value FROM settings WHERE setting_key = 'receipt_v2_cutover_timestamp')
  AND (
      (o.status = 'finished' AND o.payment_status = 'unpaid') OR
      (o.status = 'paid' AND o.cancelled_at IS NOT NULL) OR
      (o.payment_status IS NULL) OR
      (o.status = 'paid' AND EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id = o.id))
  );
```

---

## Phase 3: Implementation Work Packages

### WP-1: Core Gate & Receipt Decoupling
* **Files:** [OrderGate.php](core/OrderGate.php), [index.php](api/pos/index.php).
* **Specifications:**
  1. Introduce `orders.receipt_issued_at` and `orders.receipt_number`.
  2. Service admission requires `payment_status = 'paid'` AND `receipt_issued_at IS NOT NULL`.
  3. Physical paper printing failure displays an advisory alert on POS client but does not block apron entry.
  4. Any proposal for an optional physical-printing hard gate (`require_receipt_before_wash`) is classified as an unapproved proposal requiring a separate owner decision.
  5. Receipt reprinting is strictly read-only (`GET /api/pos/index.php?action=print_receipt`) and emits a `*** DUPLICATE RECEIPT ***` watermark.

### WP-2: Bay Manager & Lift-First Sequencing
* **Files:** [BayManager.php](core/BayManager.php).
* **Specifications:**
  1. Mixed wash + mechanical orders configure:
     - Task 1: Mechanical Lift Service (Bay 4, sequence = 1, status = `'waiting'`).
     - Task 2: Exterior Wash & Detailing (Bay 1 or 2, sequence = 2, status = `'pending_prereq'`).
  2. Checkout claims Bay 4 only; Bay 1 or 2 is deferred until Task 1 reaches `'completed'`, keeping wash bays available.
  3. Update task sequence validator (line 917) to enforce: Step 1 (Mechanical Lift) must be marked `'completed'` before Step 2 (Wash) can begin.

### WP-3: POS Client Single-Page Checkout UI
* **Files:** `pos/index.html`, `pos/js/app.js`, `pos/css/app.css`.
* **Specifications:**
  1. Unified single-page counter interface with three active zones:
     - Zone 1: License plate intake defaulting blank customer names to `'Walk-in Guest'`; vehicle category selector.
     - Zone 2: Category touch tabs for Wash Packages, Mechanical Tasks, and Car Care Products.
     - Zone 3: Cart summary, dual-currency live totals ($ USD / ៛ KHR), Quick Cash pads, KHQR bank reference input, change display.
  2. Idempotency token per cart session with immediate checkout button debouncing.
  3. Advisory toast system for printer status (advisory only; no workflow blockage).

### WP-4: Discrete Payment & Refund Ledgers
* **Files:** [index.php](api/pos/index.php), [CashierControl.php](core/CashierControl.php).
* **Specifications:**
  1. Record incoming tenders across `order_payments` (financial amounts) and `order_digital_payments` (mandatory non-null provider/ref).
  2. Implement refund finite state machine in `order_refunds`:
     - Manager PIN required for `authorized_pending`.
     - Atomic conditional disbursement lock (`rowCount === 1` opens cash drawer).
     - Bank reversal timeout and uncertain outcomes block replacement cash payouts pending an approved operational procedure.
     - Append-only event history in `order_refund_events`.
     - Enforce cumulative refund ceiling ($\sum \text{Refunds} \le \text{Total Payments}$).

### WP-5: Reporting Engine & Two-Tier Separation
* **Files:** [reports.php](views/owner/reports.php).
* **Specifications:**
  1. Two-Tier period-aware reporting queries bounded by `[:start_timestamp, :end_timestamp)`.
  2. Confirmed metrics separated from Unreconciled Historical Exceptions (split payments and pre-cutover cancellations reported under audit on separate tab).
  3. Separate dashboard metrics:
     - **Net Settled Revenue:** Gross Confirmed Payments minus Settled Refunds.
     - **Net Operating Profit:** Net Settled Revenue minus (COGS + Commissions + Expenses).
  4. Untracked Cost Alert displayed when line items lack COGS data.

---

## Phase 4: Planned Test and Acceptance Execution

### 4.1 Test Matrix Integration (58 Planned Test Specifications)
The 58 test specifications defined in [POS-IMPLEMENTATION-TEST-MATRIX.md](POS-IMPLEMENTATION-TEST-MATRIX.md) represent **planned test specifications**; they remain unexecuted and await separately authorized testing conditions:

| Test Group | ID Range | Core Focus | Planned Status |
| :--- | :--- | :--- | :--- |
| **Payment Before Service Gate** | TC-01 to TC-08 | Verify unpaid orders cannot enter bays; electronic receipt issuance gates apron. | Planned (Unexecuted) |
| **Receipt Decoupling & Printing** | TC-09 to TC-14 | Verify printer paper-out does not block service; verify reprint read-only safety. | Planned (Unexecuted) |
| **KHQR Scoped Matching** | TC-15 to TC-22 | Verify phone/soundbox matching; reject customer screenshots; test two-table constraint. | Planned (Unexecuted) |
| **Refund Safety & Finality** | TC-23 to TC-32 | Verify atomic disbursement lock; verify replacement payout blocked on uncertain reversal. | Planned (Unexecuted) |
| **Period-Aware Financial Reporting** | TC-33 to TC-42 | Verify decoupled fallbacks; multi-refund orders; reporting period boundary isolation. | Planned (Unexecuted) |
| **Multi-Stage Lift-First Dispatch** | TC-43 to TC-50 | Verify Bay 4 lift executes before wash; verify deferred wash bay reservation. | Planned (Unexecuted) |
| **Walk-in Guest & Concurrency** | TC-51 to TC-58 | Verify blank customer name defaults to `'Walk-in Guest'`; verify idempotency under double-clicks. | Planned (Unexecuted) |

### 4.2 Hardware & Counter Testing Plan
1. **ESC/POS Thermal Printer (80mm):**
   - Test printing under normal paper load.
   - Simulate paper-out condition: verify advisory toast appears on screen and vehicle moves to wash queue unimpeded.
2. **Cash Drawer Kick Pulse:**
   - Verify drawer opens upon cash confirmation.
   - Verify drawer does NOT open upon KHQR confirmation.
   - Verify drawer opens upon atomic cash refund disbursement.
3. **Soundbox & Merchant Phone Match:**
   - Simulate simultaneous $5.00 transfers: verify cashier matches bank reference on merchant phone app.

### 4.3 Concurrency & Stress Testing Plan
- Automated load test simulating 20 rapid checkout submissions with identical `idempotency_key`: verify exactly 1 database order created.
- Concurrent bay reservation attempts: verify atomic lock prevents double-booking of Bay 4 or Bay 1/2.
- Concurrent refund disbursement calls: verify atomic lock allows cash drawer pulse to fire exactly once.

---

## Phase 5: Controlled Release Readiness, Recovery & Rollback Safety

### 5.1 Recovery and Rollback Safety Principles
Recovery must strictly separate Application Code Rollback from Database Disaster Recovery:

1. **Separation of Concerns:**
   - **Application Code Rollback:** Reverting modified PHP, JavaScript, CSS, or HTML files to the pre-deployment Git release tag. This is a non-destructive application action that does not alter database state.
   - **Database Recovery:** A catastrophic, high-risk operational recovery action that requires separate, explicit executive authorization.

2. **Database Recovery Risks & Data Loss Assessment:**
   - Restoring a cold backup **destroys all transactional data created after the backup timestamp** (e.g. newly admitted vehicles, completed wash jobs, and cash drawer balances).
   - Recovery requires explicit, separate executive authorization.
   - The recovery point objective (RPO) and potential transaction loss must be formally assessed before executing any restore.
   - The backup integrity must be verified prior to recovery.
   - Restore validation must use an isolated test environment (`carwash_restore_test`).
   - **Never restore over the original production database without separate explicit authorization.**
   - Do not promise an unverified "five-minute recovery or rollback time"; actual recovery duration depends on dump size and must be demonstrated through approved staging tests.

### 5.2 Pre-Deployment Operational Runbook
1. **Pre-Cutover Checklist:**
   - [ ] Staging backup/restore verification completed successfully in isolated test database.
   - [ ] Counter hardware (printer, drawer, soundbox, LAN router) online and tested.
   - [ ] `receipt_v2_cutover_timestamp` set in `settings` to scheduled go-live hour.
   - [ ] Cashier floats verified and logged in [CashierControl.php](core/CashierControl.php).
2. **Deployment Sequence:**
   - Step 1: Deploy non-destructive schema migration `database/migrate_pos_workflow_v3.php`.
   - Step 2: Deploy updated PHP backend modules (`OrderGate.php`, `BayManager.php`, `api/pos/index.php`).
   - Step 3: Deploy single-page frontend (`pos/index.html`, `pos/js/app.js`, `pos/css/app.css`).
   - Step 4: Execute smoke verification tests.
   - Step 5: Transition counter cashiers to new interface.

### 5.3 Staff & Cashier Training Plan
- **Cashier Fast-Intake Training (< 15 seconds):**
  - Enter plate number $\rightarrow$ Select vehicle category $\rightarrow$ Tap service package $\rightarrow$ Collect cash/KHQR $\rightarrow$ Complete checkout.
  - Explain that customer name is optional (system automatically applies `'Walk-in Guest'`).
- **KHQR Anti-Fraud Training:**
  - Never look at customer phone screens.
  - Listen for soundbox chime; check merchant smartphone app for bank reference code; enter code into POS.
- **Service Flow Training:**
  - Inform technicians that mixed lube + wash cars proceed to Bay 4 Lift first, then wash bays second.

### 5.4 Executive Authorization Gate
> [!IMPORTANT]
> **Executive Sign-Off Requirement:**
> This implementation plan, its associated work packages, and the proposed deployment timeline are **subject to separate executive authorization by the business owner**. Under strict project governance rules, the software must NOT be described as "production-ready" until Phase 4 acceptance testing is completed on the staging counter and formal deployment sign-off is granted.

---

## Document Control & Verification

* **Lead Systems Architect:** Prepared for Engineering Review (November 8, 2026)
* **Operational Status:** Implementation Roadmap Complete – Ready for Phase 0 Read-Only Inspection upon Management Instruction
