# Final POS Engineering Review – Correction Changelog (Round 3)

**Document ID:** POS-REV-2026-003  
**Project:** Car-WashService  
**Date:** November 6, 2026  
**Status:** Complete Architectural Revision – Awaiting Management Approval  

---

## Executive Overview

Following management's third-stage review of the POS cashier workflow simplification architecture, this document registers the final six technical, security, and accounting corrections across the Car-WashService engineering reports:
1. [POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md)
2. [POS-IMPLEMENTATION-DEPENDENCY-MAP.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-DEPENDENCY-MAP.md)
3. [POS-IMPLEMENTATION-TEST-MATRIX.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md)
4. [POS-FINAL-REVIEW-CHANGELOG-2.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-REVIEW-CHANGELOG-2.md)

All updates maintain strict compliance with confirmed management directives: **optional customer registration**, **mandatory upfront payment**, **single-page checkout**, and **100% offline LAN self-sufficiency**, under strict read-only engineering constraints.

---

## Detailed Registry of Corrections

### 1. Receipt Cutover Safety: Fail-Closed Protection & Authorized Deployment

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 1.3 (OrderGate Logic Specification & Authorized Deployment Procedure), Section 5.3 (Cutover Resolution in Revenue Queries).
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 4.1 (Two-Tier Financial Revenue Aggregation Query).
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 1 (`TC-REC-05`, `TC-REC-07`, `TC-REC-08`).
- **Previous Problem:**  
  The previous revision introduced a fallback default cutover timestamp of `'2099-12-31 23:59:59'`. This was a severe security flaw: if the setting was missing or unconfigured, every order created in 2026 evaluated as `created_at < cutover_ts`, classifying new orders as legacy orders and allowing them to bypass mandatory electronic receipt issuance.
- **New Decision & Expected Behavior:**  
  1. **Removed Unsafe Future Fallback:** Completely eradicated `'2099-12-31 23:59:59'` from application logic and database queries.
  2. **Fail-Closed Gate Architecture:** In `OrderGate::getCutoverTimestamp()`, if `receipt_v2_cutover_timestamp` is missing, empty, malformed, or in the future:
     - Throws `SystemConfigurationException`: *"System misconfigured: 'receipt_v2_cutover_timestamp' setting is missing or invalid. An authorized deployment process must configure and verify the cutover timestamp."*
     - Fails closed: order admission is strictly blocked, completely preventing new orders from bypassing electronic receipt issuance.
     - In fallback SQL CTEs, defaults to Unix epoch (`'1970-01-01 00:00:00'`), ensuring post-epoch orders never fall into the legacy compatibility path.
  3. **Authorized Deployment Verification:** The deployment runbook mandates:
     - Migration step inserts current deployment time:
       ```sql
       INSERT INTO system_settings (setting_key, setting_value, created_at, updated_at)
       VALUES ('receipt_v2_cutover_timestamp', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), NOW(), NOW())
       ON DUPLICATE KEY UPDATE updated_at = NOW();
       ```
     - Post-deployment verification command (`OrderGate::verifyCutoverConfiguration()`) asserts that the timestamp is set, valid, and `<= time()`. Deployment is halted if validation fails.
- **Required Test Cases:**  
  `TC-REC-05` (Authorized Deployment Configured Cutover Admission), `TC-REC-07` (Fail-Closed Gate on Missing Cutover Timestamp), `TC-REC-08` (Fail-Closed Gate on Malformed or Future Cutover Timestamp).

---

### 2. Revenue Reporting: Event-Timestamp Driven, Dual-Currency & Frozen Exchange Rates

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 5.3 (Ledger Schemas, Dual-Currency Accounting & Certified SQL Formulation).
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 3 (Table Impact Matrix), Section 4.1 (Two-Tier Financial SQL).
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 5 (`TC-BIZ-07`, `TC-BIZ-09`, `TC-BIZ-10`).
- **Previous Problem:**  
  The reporting queries previously filtered `order_payments` and `order_refunds` using `orders.created_at`. If an order was created on Day 1, paid on Day 1, but cancelled and refunded on Day 2, a report for Day 2 would show $0 refunds because the query filtered on the order's Day 1 creation timestamp. Furthermore, the reports did not define how dual-currency USD/KHR amounts are converted or how historical exchange rates are preserved.
- **New Decision & Expected Behavior:**  
  1. **Event-Timestamp Driven Filtering:**
     - Gross Payments Collected are filtered strictly by **payment event timestamp** (`p.created_at BETWEEN :start_date AND :end_date`).
     - Total Refunds Issued are filtered strictly by **refund event timestamp** (`r.created_at BETWEEN :start_date AND :end_date`).
     - A refund occurring on Day 2 against a Day 1 order correctly appears on the Day 2 financial statement.
  2. **Dual-Currency Staging & Historical Exchange Rate Freezing:**
     - `order_payments` and `order_refunds` store discrete columns: `amount_usd`, `amount_khr`, and `exchange_rate` (e.g. 4100).
     - The exchange rate active at the exact moment of payment or refund is permanently frozen in that transaction row.
     - Reports **never** recalculate historical transactions using subsequent live exchange rates, completely eliminating retrospective revenue drift.
  3. **Dual-Currency Output Specifications:**
     - Reports output native USD totals, native KHR totals, and a converted unified USD equivalent:  
       `total_usd_equivalent = amount_usd + (amount_khr / exchange_rate)`.
- **Required Test Cases:**  
  `TC-BIZ-07` (Event-Timestamp Driven Financial Revenue Accounting [Cross-Date Payments & Refunds]), `TC-BIZ-09` (Repeated Partial Refunds Multi-Event Recording), `TC-BIZ-10` (Dual-Currency Reporting & Historical Exchange Rate Preservation).

---

### 3. Payment Reference Uniqueness: Authoritative Ledger & Scoped Deduplication

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 2.2, Section 2.3 (Authoritative Payment Ledger Resolution & Scoped Deduplication), Section 5.3.
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 1.2 (Operation 2 Call Chain), Section 3 (Table Impact Matrix).
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 2 (`TC-KHQR-03`, `TC-KHQR-09`).
- **Previous Problem:**  
  Documentation placed the unique reference index on the `orders` entity while simultaneously declaring `order_payments` as the transaction ledger. This created confusion regarding which table was the authoritative source of truth, and did not explain how split-tender transactions or client retries interact with reference uniqueness.
- **New Decision & Expected Behavior:**  
  1. **Authoritative Table Resolution:** `order_payments` is the single authoritative source of financial truth. The `orders` table only maintains denormalized summary fields (`orders.payment_status`, `orders.total_usd`, `orders.refunded_amount`) for fast UI rendering.
  2. **Composite Unique Index on Authoritative Ledger:**
     ```sql
     ALTER TABLE order_payments 
       ADD UNIQUE INDEX uq_payment_ledger_provider_ref (payment_provider, merchant_account_id, payment_ref);
     ```
     All three columns are strictly `NOT NULL`, closing the MySQL `NULL != NULL` duplicate bypass.
  3. **Multiple Legitimate Payment Events & Split Tender:**
     - Split-tender orders (e.g. $10 Cash + $15 KHQR) create discrete rows in `order_payments` with sequential `payment_seq`. Each payment has distinct references (`CASH-101-1` vs `ABA-849201`), avoiding collision.
  4. **Client Retries & Network Resubmissions:**
     - Handled via `orders.idempotency_key`. A retry of the same checkout returns the existing order and its existing `order_payments` record.
     - Attempting to reuse an already-settled bank reference on a different order is halted by `uq_payment_ledger_provider_ref` with HTTP 409 `DUPLICATE_PAYMENT_REFERENCE`.
  5. **Provider & Account Scoping:** Preserved so identical reference strings from different banks or distinct merchant accounts never conflict.
- **Required Test Cases:**  
  `TC-KHQR-03` (Authoritative Ledger Scoped Duplicate Bank Reference Rejection), `TC-KHQR-09` (NULL Merchant Account Duplicate Defense).

---

### 4. Bank Webhook Signature Tests: Provider-Specific Compliance

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 2.3 (Automated Bank Webhook Security Architecture).
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 1.2 (Operation 2 Call Chain), Section 3 (Table Impact Matrix).
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 2 (`TC-KHQR-05A`, `TC-KHQR-05B`).
- **Previous Problem:**  
  Test case `TC-KHQR-05` previously tested a generic HMAC-SHA512 header. This contradicted the architectural audit which correctly noted that Cambodian banks do not use a generic HMAC standard (ABA PayWay uses a specific parameter-concatenation HMAC-SHA512 string; NBC Bakong uses RSA-SHA256 asymmetric public-key cryptography).
- **New Decision & Expected Behavior:**  
  1. **Separated Provider Tests:** Replaced generic test with two provider-specific specifications:
     - `TC-KHQR-05A`: **ABA PayWay Webhook Parameter-Sequence HMAC-SHA512 Verification** (validates against ABA's exact official concatenated parameter sequence).
     - `TC-KHQR-05B`: **NBC Bakong / KHQR Webhook Asymmetric RSA-SHA256 Verification** (validates JWT/payload against Bakong's official RSA-256 public key certificate via `openssl_verify()`).
  2. **Strict Non-Invention Policy:** Webhook logic must not invent API requirements. Implementation must be verified against official bank developer documentation before deployment.
- **Required Test Cases:**  
  `TC-KHQR-05A`, `TC-KHQR-05B`.

---

### 5. Webhook Replay Protection: Event Uniqueness, 5-Minute Window & Payload Tampering

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 2.3 (Webhook Security & Replay Architecture).
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 1.2, Section 3 (`webhook_events` schema).
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 2 (`TC-KHQR-07`, `TC-KHQR-08`, `TC-KHQR-10`, `TC-KHQR-11`).
- **Previous Problem:**  
  The documentation did not address replaying a valid signed webhook within the valid 5-minute timestamp window, nor did it have dedicated tests for altered payloads or duplicate deliveries.
- **New Decision & Expected Behavior:**  
  1. **Four-Tier Webhook Validation Pipeline:**
     - **Stage 1: Cryptographic Signature Check:** Raw payload signature verified first. If tampered (e.g. amount altered from $5 to $50), fails immediately with HTTP 401 `INVALID_SIGNATURE`.
     - **Stage 2: Timestamp Drift Check:** Validates `abs(CURRENT_TIMESTAMP - bank_timestamp) <= 300s`. Drift > 300s rejected with HTTP 400 `WEBHOOK_TIMESTAMP_EXPIRED`.
     - **Stage 3: Event ID Uniqueness:** Inserts into `webhook_events` (`UNIQUE KEY uq_provider_event (provider, event_id)`). If duplicate is detected:
       - Replays inside 5-minute window return HTTP 200 `{ status: 'already_processed' }` to stop bank retries.
       - **Zero additional payment records are inserted into `order_payments`**, and order balance remains unchanged.
     - **Stage 4: Authoritative Ledger Insertion:** Only newly verified events execute `order_payments` insertion and order gate unlocking.
- **Required Test Cases:**  
  `TC-KHQR-07` (Duplicate Webhook Delivery Idempotency), `TC-KHQR-08` (Webhook Replay Attack Outside Window [Timestamp Drift]), `TC-KHQR-10` (Webhook Replay Attack Inside 5-Minute Window), `TC-KHQR-11` (Altered Webhook Payload Tampering Defense).

---

### 6. Bay Expiry Boundary: Exact 3-Minute Standard & Occupied Bay Safety

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 4.4 (Heartbeat, Expiration, and Crash Recovery Specification).
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 3 (Table Impact Matrix for `bays`).
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 4 (`TC-BAY-03`).
- **Previous Problem:**  
  Discrepancies existed between SQL comparison operators (`<` vs `<=`) and test intervals, and tests lacked explicit verification of active checkout orders attached to bays during expiry sweeps.
- **New Decision & Expected Behavior:**  
  1. **Standardized Boundary Condition (`hold_expires_at <= CURRENT_TIMESTAMP`):**
     - **Before Expiry (T = 179s / < 180s):** `hold_expires_at > CURRENT_TIMESTAMP`. Hold is active; bay remains reserved for cashier checkout session.
     - **Exactly at Expiry (T = 180s / = 180s):** `hold_expires_at = CURRENT_TIMESTAMP`. Boundary reached; sweep releases hold back to `'free'`.
     - **After Expiry (T = 181s / > 180s):** `hold_expires_at < CURRENT_TIMESTAMP`. Hold is past expiry; sweep releases to `'free'`.
  2. **Strict Protection for Occupied Bays & Active Orders:**
     ```sql
     UPDATE bays 
     SET status = 'free', reservation_state = 'none', reserved_by_session_id = NULL, hold_expires_at = NULL 
     WHERE reservation_state = 'intake_reserved' 
       AND status != 'busy' 
       AND physical_occupancy = 'vacant' 
       AND current_order_id IS NULL 
       AND hold_expires_at <= CURRENT_TIMESTAMP;
     ```
     Bays with `status = 'busy'`, `physical_occupancy = 'occupied'`, or `current_order_id IS NOT NULL` are **never released by timeout**.
- **Required Test Cases:**  
  `TC-BAY-03` (Intake Hold TTL Expiry [Exact 3-Min Boundary Testing] vs. Occupied Bay Protection).

---

## Files Changed

| File Path | Nature of Document Updates | Current Status |
| :--- | :--- | :--- |
| **`POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`** | 1. Section 1.3: Removed `2099-12-31` fallback, implemented fail-closed `SystemConfigurationException` on missing/invalid cutover timestamp, defined authorized deployment procedure.<br>2. Section 2.3: Resolved `order_payments` as authoritative payment table, defined `uq_payment_ledger_provider_ref`, explained split-tender and retry handling.<br>3. Section 2.3 & 2.4: Defined bank-specific webhook signature specs (ABA PayWay vs Bakong), 5-minute replay defense, and payload tampering defense.<br>4. Section 4.4: Standardized exact 3-minute boundary `hold_expires_at <= CURRENT_TIMESTAMP` with occupied bay and active checkout guards.<br>5. Section 5.3: Updated two-tier revenue SQL to filter payments on `p.created_at` and refunds on `r.created_at`, added dual-currency USD/KHR conversion and frozen exchange rate preservation. | Modified |
| **`POS-IMPLEMENTATION-DEPENDENCY-MAP.md`** | 1. Section 1.2: Updated Operation 2 call chain to query duplicate protection against authoritative `order_payments` table.<br>2. Section 3: Updated ER diagram and Table Impact Matrix for `order_payments` (authoritative ledger, composite unique index, frozen exchange rate), `order_refunds`, `bays` (exact 180s boundary `<=`), and `webhook_events`.<br>3. Section 4.1: Updated Two-Tier Revenue Aggregation SQL with payment/refund event timestamps, fail-closed cutover (`1970-01-01`), and dual-currency outputs. | Modified |
| **`POS-IMPLEMENTATION-TEST-MATRIX.md`** | 1. Architecture: Expanded test matrix from 40 to **46 planned test specifications**.<br>2. Suite 1: Added `TC-REC-07` (missing cutover fail-closed) and `TC-REC-08` (invalid cutover fail-closed); updated `TC-REC-05`.<br>3. Suite 2: Updated `TC-KHQR-03` for authoritative ledger; split `TC-KHQR-05` into `TC-KHQR-05A` (ABA PayWay) and `TC-KHQR-05B` (NBC Bakong); added `TC-KHQR-10` (replay inside 5-min window) and `TC-KHQR-11` (altered payload tampering).<br>4. Suite 4: Reconciled `TC-BAY-03` to test exact 3-min boundary (179s, 180s, 181s) with occupied bay and active checkout protection.<br>5. Suite 5: Updated `TC-BIZ-07` for cross-date payments & refunds; updated `TC-BIZ-09`; added `TC-BIZ-10` (dual-currency reporting and frozen exchange rate preservation). | Modified |
| **`POS-FINAL-REVIEW-CHANGELOG-2.md`** | Updated status and header banner to reference Round 3 changelog. | Modified |
| **`POS-FINAL-REVIEW-CHANGELOG-3.md`** | **Created** this comprehensive changelog documenting the 6 corrections, affected sections, expected behavior, required tests, and strict read-only confirmations. | Untracked (New file) |

---

## Exact Remaining Limitations & Operational Boundaries

1. **External Bank API Reversal Realities for SMEs:**  
   In Cambodia, SME merchant accounts typically cannot initiate automated API refunds via Bakong/KHQR. The POS application records the **administrative attestation** of refund authorizations, but cash disbursement from the counter drawer or external manual merchant portal processing is required.
2. **Physical Banknote Counting Integrity:**  
   The POS accurately calculates dual-currency change and drawer balances. However, it cannot prevent physical cash mishandling by cashiers. Daily physical drawer counts (`CashierControl::closeShift()`) remain mandatory.
3. **Thermal Printer Peripheral Availability:**  
   Decoupled electronic receipts guarantee that service admission never blocks on paper jams or offline printers. However, physical paper output remains dependent on hardware availability; reprint requests require restoring printer paper/connectivity.
4. **Offline LAN Webhook Limitations:**  
   In total WAN blackout mode (100% offline LAN operation), automated bank webhooks cannot reach the local server. Counter staff must use Tier 1 manual merchant-device verification (mobile push on cellular store phone) or cash.

---

## Strict Executive Confirmations

In accordance with explicit management instructions:

1. **NO Application Source Code Modified:**  
   No PHP, JavaScript, CSS, or HTML files in `core/`, `api/`, `pos/`, `views/`, or `assets/` were altered or created.
2. **NO Database Records or Schemas Modified:**  
   No SQL migrations were executed, no database tables were altered, and no MariaDB records or data files were modified.
3. **NO Tests or Migrations Falsely Reported as Executed:**  
   All 46 verification test cases in `POS-IMPLEMENTATION-TEST-MATRIX.md` are strictly cataloged as **PLANNED TEST SPECIFICATIONS** for staging/UAT execution upon management approval.
4. **NO Git Commits or Pushes:**  
   The local Git repository remains uncommitted and unpushed.
5. **Preservation of Core Decisions:**  
   Optional customer registration, upfront payment, single-page checkout, and 100% offline LAN self-sufficiency remain 100% intact.

---

> [!IMPORTANT]
> **Audit Conclusion & Implementation Hold:**  
> All six required corrections are fully resolved, mathematically specified, and reconciled across the engineering documentation suite. **Work is stopped, and we await management review and approval.**