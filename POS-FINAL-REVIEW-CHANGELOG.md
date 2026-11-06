# Final POS Design Review – Correction Changelog (Round 1)

**Document ID:** POS-REV-2026-001  
**Project:** Car-WashService  
**Date:** November 6, 2026  
**Status:** Superseded by Round 2 Revisions ([POS-FINAL-REVIEW-CHANGELOG-2.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-REVIEW-CHANGELOG-2.md)) – Awaiting Management Approval  

> [!NOTE]
> **Round 2 Revisions Available:**  
> For subsequent technical corrections concerning transaction ledgers (`order_payments` & `order_refunds`), multiple partial refunds, MySQL NULL unique index defense, bank webhook replay specifications, deterministic commission idempotency keys, dynamic cutoff timestamps, and 3-minute TTL intervals, refer to **[POS-FINAL-REVIEW-CHANGELOG-2.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-REVIEW-CHANGELOG-2.md)**.

---

## Executive Overview

This changelog records the definitive architectural and documentation corrections applied across the three POS engineering reports:
1. `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`
2. `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`
3. `POS-IMPLEMENTATION-TEST-MATRIX.md`

All corrections enforce management's core business directives: **optional customer registration**, **payment before service**, **single-page checkout**, and **100% offline LAN self-sufficiency**, under strict read-only constraints.

---

## Detailed Correction Registry

### 1. KHQR Verification: Manual vs. Automated Boundaries & Scoped Deduplication

- **Previous Problem:**  
  The documentation previously claimed that the POS backend independently validates the bank-side transaction amount, currency, merchant account, and payment status when a cashier enters a bank reference. In manual merchant-device mode, this claim was technically inaccurate because the local POS does not query the commercial bank API. Additionally, synthetic `TRX-` references were previously auto-generated on empty input.
- **New Decision:**  
  1. Clearly distinguished **Tier 1 (Manual Merchant-Device Verification)** from **Tier 2 (Automated Bank Webhook)**.
  2. In manual mode, the cashier visually inspects the store's dedicated phone, Telegram bot, or IoT soundbox chime. The POS backend captures the cashier's attestation without claiming false automated validation.
  3. Immutably records: `payment_verified_by_id`, `payment_verified_by_name`, `payment_verified_at`, `payment_provider` (e.g. `aba_payway`, `bakong`), `merchant_account_id` (e.g. `001-234567`), `payment_ref`, and `verification_method = 'manual_merchant_device'`.
  4. Enforced scoped duplicate protection: `UNIQUE INDEX uq_provider_account_ref (payment_provider, merchant_account_id, payment_ref)`.
  5. Completely eradicated synthetic `TRX-` generation. Empty reference input is strictly rejected with HTTP 422: `BANK_REFERENCE_REQUIRED`.
  6. Automated webhook verification is specified to require genuine commercial bank integration and cryptographic signature verification (HMAC-SHA512).
- **Affected Modules:**  
  `api/pos/index.php`, `pos/js/app.js`, `core/BankTransferControl.php`, `orders` table.
- **Required Test Cases:**  
  `TC-KHQR-01`, `TC-KHQR-02`, `TC-KHQR-03`, `TC-KHQR-04`, `TC-KHQR-05`, `TC-KHQR-06`.

---

### 2. Historical Cancellation & Refund Separation

- **Previous Problem:**  
  The previous migration script automatically converted any historical order with `status = 'paid'` and cancellation evidence (`cancelled_at IS NOT NULL` or `cancel_reason IS NOT NULL`) into `payment_status = 'cancelled'`. This improperly erased real collected tender from historical cash drawers and bank ledgers by assuming operational cancellation always implied a financial refund.
- **New Decision:**  
  1. Strictly decoupled **operational service cancellation** (`status = 'cancelled'`) from **financial payment status** (`payment_status`).
  2. Preserved original payment records: If an order has `status = 'paid'`, tender was collected. In the absence of an explicit refund record (`refunded_amount > 0`), `payment_status` remains `'paid'`.
  3. Track full and partial refunds independently: Full refund (`payment_status = 'refunded'`, `refunded_amount = total_usd`), Partial refund (`payment_status = 'partially_refunded'`, `refunded_amount = :amount`), Paid cancellation with no refund (`status = 'cancelled'`, `payment_status = 'paid'`, `refunded_amount = 0.00`).
  4. Historical orders with cancellation notes but unknown refund trails are **kept completely unchanged** in the database and extracted into the *Manual Reconciliation Report* (`PAID_OPERATIONAL_CANCELLATION_UNKNOWN_REFUND`) for manager/accountant review.
- **Affected Modules:**  
  Database migration script, `core/BayManager.php`, `views/owner/reports.php`, `orders` table.
- **Required Test Cases:**  
  `TC-MIG-02`, `TC-MIG-06`, `TC-MIG-07`, `TC-BIZ-07`.

---

### 3. Redesigned Revenue Reporting Around Actual Financial Records

- **Previous Problem:**  
  Revenue SQL queries used clauses like `AND status != 'cancelled'` or filtered solely on `status = 'paid'`. This caused two severe accounting distortions: (a) it assumed `status = 'cancelled'` meant no money was received, improperly zeroing out retained payments from aborted washes, and (b) it dropped revenue to zero when operational status transitioned to `washing` or `finished`.
- **New Decision:**  
  1. Redesigned revenue calculations strictly around **actual payment and refund transaction ledgers**.
  2. Clear category definitions:
     - **Gross Payments Collected:** Total cash & digital funds received upon checkout (`payment_status IN ('paid', 'partially_refunded', 'refunded')`).
     - **Full Refunds Issued:** Total payments returned in full (`payment_status = 'refunded'`).
     - **Partial Refunds Issued:** Portions of payments refunded (`payment_status = 'partially_refunded'`).
     - **Net Retained Payments:** Actual retained revenue (`Gross - Full Refunds - Partial Refunds`). Retains funds from paid cancellations where no refund was issued.
     - **Unpaid Cancellations:** Orders cancelled prior to payment (`payment_status = 'unpaid' AND status = 'cancelled'`), contributing $0 to sales.
  3. Legacy reconciliation strategy: Unmigrated historical orders with `status = 'paid'` map to recognized revenue; ambiguous records are reconciled manually before impacting audited statements.
- **Affected Modules:**  
  `views/owner/reports.php`, `api/reports/index.php`, `core/CashierControl.php`.
- **Required Test Cases:**  
  `TC-BIZ-07`, `TC-MIG-02`.

---

### 4. Bay Reservation vs. Physical Occupancy & Crash Recovery

- **Previous Problem:**  
  Temporary intake reservations were conflated with physical bay occupancy. The previous design proposed a 15-minute background timeout that auto-released any bay marked `status = 'busy'` if its order timestamp exceeded 15 minutes. In a real workshop, heavy washes, polishing, or oil changes take 30–60 minutes; auto-releasing an occupied bay based on elapsed time would cause disastrous apron collisions and double-booking.
- **New Decision:**  
  1. Separated **temporary intake hold** (`reservation_state = 'intake_reserved'`, 3-minute TTL) from **physical bay occupancy** (`status = 'busy'`, `physical_occupancy = 'occupied'`).
  2. The 3-minute TTL applies **strictly to unconfirmed cart holds** where payment was abandoned.
  3. **Physically occupied bays are NEVER auto-released by timeout or elapsed time.** Physical occupancy can only be freed by: (a) legitimate technician/washer job completion on a workshop tablet, or (b) explicit Manager PIN override (`BAY_FORCE_RELEASED`).
  4. Specified transaction-safe bay release and stage progression (`transitionStageAtomically`) wrapped in a single database transaction with row-level locks (`SELECT ... FOR UPDATE`).
- **Affected Modules:**  
  `core/BayManager.php`, `bays` table, `api/pos/index.php`, `views/owner/bays_queue.php`.
- **Required Test Cases:**  
  `TC-BAY-01`, `TC-BAY-02`, `TC-BAY-03`, `TC-BAY-04`.

---

### 5. Task-Based Commission Idempotency

- **Previous Problem:**  
  Enforcing commission uniqueness strictly by composite key `UNIQUE(order_id, stage_sequence, worker_id)` would improperly reject legitimate multiple commissions if a cross-trained worker performed two distinct tasks within the same stage (e.g., in Stage 1 Mechanical: Task A Oil Change [$5] and Task B Brake Pad Replacement [$8]).
- **New Decision:**  
  1. Anchored commission records directly to explicit task instances (`task_id` in `order_tasks`).
  2. Protected against network retries or rapid double-clicks using a unique **commission idempotency key**: `commission_idempotency_key VARCHAR(64) UNIQUE` (derived from `hash('sha256', "comm_{order_id}_{task_id}_{worker_id}_{action_sequence}")`).
  3. Double-tapping "Complete Task" hits the idempotency unique constraint and is caught gracefully without duplicate payout.
  4. Distinct legitimate task assignments generate distinct idempotency keys (varying by `task_id`), allowing legitimate multiple commission earnings.
- **Affected Modules:**  
  `order_washers` table, `order_mechanics` table, `core/BayManager.php`, `views/washer/jobs.php`, `views/mechanic/jobs.php`.
- **Required Test Cases:**  
  `TC-BAY-05`, `TC-BAY-06`, `TC-BAY-07`.

---

### 6. Full Database Backup vs. Supplementary Table Copy & Rollback

- **Previous Problem:**  
  The documentation previously described a supplementary `JOIN`-based update from a table copy (`orders_migration_backup_20261106`) as a "complete rollback". In reality, a table-level join cannot revert child tables (`order_items`, `order_tasks`, `order_washers`), cannot delete newly inserted rows, cannot restore deleted rows, and cannot reset triggers, views, or auto-increment counters.
- **New Decision:**  
  1. Clearly documented that `CREATE TABLE orders_migration_backup AS SELECT * FROM orders;` is merely a supplementary local diff tool, **NOT** a complete rollback mechanism.
  2. Defined true disaster-recovery rollback as a complete database restore from a full logical dump (`mysqldump -u [user] -p --single-transaction --routines --triggers carwash_db > backup.sql`).
  3. Mandated verified restoration testing on an isolated staging schema (`carwash_test_restore`) with row count and table checksum verification (`CHECKSUM TABLE`) prior to executing migrations on production.
- **Affected Modules:**  
  Database migration runbooks, deployment guides, MariaDB administration procedures.
- **Required Test Cases:**  
  `TC-MIG-01`, `TC-MIG-08`.

---

### 7. Comprehensive Test Matrix Architecture & Planned Status Clarity

- **Previous Problem:**  
  The test matrix previously did not state explicitly that tests are planned specifications for staging/UAT rather than executed tests, and lacked dedicated test cases for several confirmed core business workflows (optional customer registration, upfront payment, cash tendering, manager discounts, printer failure, duplicate submissions, and offline LAN operation).
- **New Decision:**  
  1. Clarified document status: **All 35 test cases are PLANNED TEST SPECIFICATIONS** for execution during the implementation phase on an isolated test environment. The codebase remains in read-only audit status.
  2. Expanded Suite 5 into *Cashier Intake, Business Rules & Accounting Workflows*:
     - `TC-BIZ-01`: Optional Customer Registration (plate-first walk-in checkout without forced registration modal).
     - `TC-BIZ-02`: Mandatory Upfront Payment Enforcement (unpaid dispatch 100% blocked).
     - `TC-BIZ-03`: Cash Tender & Dual-Currency Change Calculation ($ & ៛ with drawer kick pulse).
     - `TC-BIZ-04`: Manager-Approved Discount Authorization Gate (PIN gate & anti-self-approval).
     - `TC-BIZ-05`: Duplicate Submission Defense (Cart Idempotency Token UUID).
     - `TC-BIZ-06`: 100% Offline Local LAN Resilience (LAN self-sufficiency during WAN blackout).
     - `TC-BIZ-07`: Actual Payment & Refund Financial Revenue Accounting (Gross vs Full Refund vs Partial Refund vs Paid Cancellation vs Unpaid Cancellation).
     - `TC-BIZ-08`: TV Queue Board & Worker Job Board Real-Time Synchronization.
- **Affected Modules:**  
  `POS-IMPLEMENTATION-TEST-MATRIX.md`.
- **Required Test Cases:**  
  Full suite of 35 test cases (`TC-REC-01..06`, `TC-KHQR-01..06`, `TC-MIG-01..08`, `TC-BAY-01..07`, `TC-BIZ-01..08`).

---

## Document Cross-Reference Map

| Revision Area | [POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md) | [POS-IMPLEMENTATION-DEPENDENCY-MAP.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-DEPENDENCY-MAP.md) | [POS-IMPLEMENTATION-TEST-MATRIX.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md) |
| :--- | :--- | :--- | :--- |
| **1. KHQR Verification** | Section 2.2, 2.3, 2.4 | Section 1.2, Section 3 | `TC-KHQR-01..06` |
| **2. Cancellation & Refunds** | Section 3.3, 3.5, 3.6 | Section 1.4, Section 3 | `TC-MIG-06, 07`, `TC-BIZ-07` |
| **3. Revenue Reporting** | Section 5.3 | Section 4.1 | `TC-BIZ-07`, `TC-MIG-02` |
| **4. Bay Reservation & Safety** | Section 4.3, 4.4 | Section 1.3, Section 3 | `TC-BAY-01..04` |
| **5. Commission Idempotency** | Section 4.5 | Section 1.3, Section 3 | `TC-BAY-05..07` |
| **6. Backup & Rollback** | Section 3.2, 3.7 | Section 4.3 | `TC-MIG-01, 08` |
| **7. Business Workflows & Tests** | Section 1.3, 4.3, 5.3 | Section 1.1, 1.2, 4.1 | `TC-BIZ-01..08`, `TC-REC-01..06` |

---

> [!IMPORTANT]
> **Strict Read-Only Confirmation:**  
> In accordance with executive instructions, **no application source code, SQL migrations, database records, or configuration files have been created or modified**. All updates are strictly confined to the engineering documentation. We are stopped and awaiting management authorization to proceed to implementation.