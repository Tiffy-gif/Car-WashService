# Final POS Engineering Review – Correction Changelog (Round 4)

**Document ID:** POS-REV-2026-004  
**Project:** Car-WashService  
**Date:** November 6, 2026  
**Status:** Superseded by Round 5 – See [POS-FINAL-REVIEW-CHANGELOG-5.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-REVIEW-CHANGELOG-5.md)  

---

> [!NOTE]
> **Round 5 Audit Update:**  
> Final legacy payment reporting decoupling and refund lifecycle integrity specifications have been formalized in Round 5. For the latest authoritative specifications, please refer to [POS-FINAL-REVIEW-CHANGELOG-5.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-REVIEW-CHANGELOG-5.md).

---

## Executive Overview

Following management's fourth-stage review of the Car-WashService POS cashier workflow architecture, this document records the definitive technical, financial integrity, and deployment consistency corrections across all engineering specifications in the repository:
1. [POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md)
2. [POS-IMPLEMENTATION-DEPENDENCY-MAP.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-DEPENDENCY-MAP.md)
3. [POS-IMPLEMENTATION-TEST-MATRIX.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md)
4. [POS-FINAL-REVIEW-CHANGELOG-3.md](file:///C:/xampp/htdocs/Car-WashService/POS-FINAL-REVIEW-CHANGELOG-3.md)

All revisions strictly enforce confirmed management directives: **optional customer registration**, **mandatory upfront payment**, **single-page checkout**, and **100% offline LAN self-sufficiency**, under strict read-only engineering safeguards.

---

## Detailed Registry of Round 4 Corrections

### 1. Refund Settlement Accounting: Authorization vs. Cash Disbursement vs. Bank Settlement

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 5.3 (Accounting Integrity: Refunds & Two-Tier Revenue Aggregation)
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 1.4 (Operation 4: Rollback & Cancellation Call Chain), Section 3 (Database Entity & Schema Alignment), Section 4.1 (Two-Tier Financial Revenue Query)
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 5 (`TC-BIZ-07`, `TC-BIZ-11`)
- **Previous Defect:**  
  The documentation previously treated an administrative manager authorization in the POS (`attestation_status = 'cashier_attested'`) as an immediate deduction from gross revenue and cash drawer balances. This created critical accounting discrepancies: an approved refund where cash had not yet been handed to the customer, or where a bank reversal was still in transit, was falsely deducted as settled money returned.
- **Round 4 Correction & Expected Behavior:**  
  1. **Four-Stage Settlement Lifecycle:** Extended `order_refunds.settlement_status` enum:
     - `'authorized_pending'`: Administrative manager authorization recorded in POS. Represents an **informational liability**, NOT cash returned. Does NOT reduce drawer cash or net settled revenue.
     - `'cash_disbursed'`: Cashier physically hands currency from the counter cash drawer to the customer. Timestamped with `disbursed_at = NOW()`. Deducts from active cash drawer balance and reduces net settled revenue.
     - `'bank_reversal_pending'`: POS dispatches digital refund request to bank gateway or records pending interbank reversal. Timestamped with `recorded_at = NOW()`.
     - `'settled'`: Bank gateway callback confirms interbank reversal or statement reconciliation matches credit. Timestamped with `settled_at = NOW()`. Deducts from net settled revenue upon bank confirmation.
  2. **Two-Tier Revenue Reporting Segmentation:**
     - `gross_payments_collected_usd`: Total incoming tender across shift/period.
     - `settled_refunds_usd`: Refunds with `settlement_status IN ('cash_disbursed', 'settled')`.
     - `pending_unsettled_refunds_usd`: Outstanding authorizations with `settlement_status IN ('authorized_pending', 'bank_reversal_pending')`.
     - `net_settled_revenue_usd = gross_payments_collected_usd - settled_refunds_usd` (Authoritative cash/bank inflow).
     - `net_realizable_revenue_usd = net_settled_revenue_usd - pending_unsettled_refunds_usd` (Adjusted for pending liabilities).
  3. **Preserved Governance:** Preserves manager PIN authentication, anti-self-approval enforcement (`manager_user_id != cashier_id`), and immutable audit logging (`ORDER_CANCELLED` / `REFUND_AUTHORIZED`).

---

### 2. Legacy Order Payment Reporting & Cross-Cutover Transaction Accounting

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 5.3 (Two-Tier Financial Revenue Aggregation Query)
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 4.1 (Two-Tier Financial Revenue Aggregation Query)
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 5 (`TC-BIZ-12`)
- **Previous Defect:**  
  Tier 1 of the revenue query joined `orders o` and restricted rows with `WHERE o.created_at >= cutover_ts`. If an order was originally created prior to cutover (e.g. yesterday in legacy format), but a transaction occurred post-cutover (e.g. customer returned today for an exchange, additional payment, or partial refund recorded in `order_payments` or `order_refunds`), the transaction was silently excluded from Tier 1 because its parent order date preceded cutover. Furthermore, if Tier 2 queried the historical date, it risked double-counting or inconsistency.
- **Round 4 Correction & Expected Behavior:**  
  1. **Ledger-First Architecture in Tier 1:** Tier 1 queries `order_payments p` and `order_refunds r` directly by their recording timestamps (`p.recorded_at BETWEEN :start_date AND :end_date` / `r.recorded_at BETWEEN :start_date AND :end_date`) without any parent order creation date restriction. Any discrete ledger event occurring post-cutover is 100% captured on its transaction date.
  2. **Strict Anti-Double-Counting Guard in Tier 2:** When Tier 2 runs historical fallback on `orders o` (`o.created_at < :cutover_ts`), it strictly excludes any legacy order that has discrete ledger rows using:
     ```sql
     AND NOT EXISTS (SELECT 1 FROM order_payments p WHERE p.order_id = o.id)
     AND NOT EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = o.id)
     ```
  3. **Result:** Zero transaction omissions, zero double-counting across reporting boundaries, and permanent historical audit integrity.

---

### 3. Event Time vs. Local Server Recording Time Distinction

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 2.3 (Subsection 5: Event Time vs. Recording Time), Section 5.3 (Schema Definitions)
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 3 (Database Entity & Schema Alignment)
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 2 (`TC-KHQR-12`)
- **Previous Defect:**  
  The documentation did not clearly differentiate between external bank event timestamps and local server ingestion timestamps. Relying on external bank event times for cashier shift reports creates drawer balancing errors when bank processing delays or timezone offsets occur, while manufacturing fake bank event times for manual cash checkouts violates financial compliance.
- **Round 4 Correction & Expected Behavior:**  
  1. **Dual-Timestamp Architecture:**
     - `recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`: Local counter server clock time when the transaction is committed in the database. **Authoritative for cashier shift boundaries, physical cash drawer balancing, and daily business day locks.**
     - `provider_event_at DATETIME NULL`: Exact event timestamp provided in external bank webhook payloads (e.g., ABA PayWay or Bakong). Preserved for bank statement audit reconciliation.
  2. **Non-Invention Rule:** For manual cash checkouts and manual merchant-device verifications, `provider_event_at` is strictly set to `NULL`. The backend **never** fabricates or back-calculates an external bank event timestamp.

---

### 4. Cutover Deployment SQL & Overwrite Safety

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 1.3 (OrderGate Logic Specification & Authorized Deployment Procedure)
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 3 (Table Impact Matrix – `system_settings`)
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 1 (`TC-REC-07`, `TC-REC-08`)
- **Previous Defect:**  
  The deployment SQL statement previously read:
  ```sql
  INSERT INTO system_settings (setting_key, setting_value, description)
  VALUES ('receipt_v2_cutover_timestamp', NOW(), ...)
  ON DUPLICATE KEY UPDATE updated_at = NOW();
  ```
  In MySQL/MariaDB, if `receipt_v2_cutover_timestamp` already existed with an invalid, test, or stale value, this query updated `updated_at` but left `setting_value` unchanged, allowing erroneous cutover timestamps to persist silently.
- **Round 4 Correction & Expected Behavior:**  
  1. **Idempotent Overwrite Deployment SQL:** Corrected the migration statement to explicitly update `setting_value`:
     ```sql
     INSERT INTO system_settings (setting_key, setting_value, description)
     VALUES ('receipt_v2_cutover_timestamp', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), 'Cutover timestamp for POS electronic receipt v2 and strict OrderGate enforcement')
     ON DUPLICATE KEY UPDATE 
         setting_value = VALUES(setting_value),
         updated_at = NOW();
     ```
  2. **Authorized Verification Procedure:** Defined automated verification check in `OrderGate::verifyCutoverConfiguration()`, which returns `true` only if the timestamp is non-empty, syntactically valid (`YYYY-MM-DD HH:MM:SS`), and represents a historical or current time (`<= NOW()`).

---

### 5. Reporting Query Configuration & Fail-Closed Enforcement

- **Affected Document Sections:**
  - `POS-FINAL-SOURCE-COMPATIBILITY-AUDIT.md`: Section 1.3, Section 5.3
  - `POS-IMPLEMENTATION-DEPENDENCY-MAP.md`: Section 4.1
  - `POS-IMPLEMENTATION-TEST-MATRIX.md`: Suite 5 (`TC-BIZ-13`)
- **Previous Defect:**  
  The reporting query CTE previously contained a silent fallback:
  ```sql
  SELECT COALESCE(
      (SELECT setting_value FROM system_settings WHERE setting_key = 'receipt_v2_cutover_timestamp'),
      '1970-01-01 00:00:00'
  ) AS cutover_ts
  ```
  If `receipt_v2_cutover_timestamp` was unconfigured or missing, `cutover_ts` evaluated to Unix epoch (`1970-01-01`). In Tier 2 historical fallback, `WHERE o.created_at < cutover_ts` evaluated to false for all modern orders, silently wiping out historical revenue from legacy reports without raising an error.
- **Round 4 Correction & Expected Behavior:**  
  1. **Fail-Closed Application Layer Check:** In `views/owner/reports.php` and reporting APIs, the PHP controller retrieves and validates `receipt_v2_cutover_timestamp` before executing queries.
  2. **Mandatory Halting on Missing Setting:** If the cutover timestamp is missing, empty, or invalid, the controller immediately throws `SystemConfigurationException` and halts with HTTP 500. It **never** executes SQL queries with a default or fallback date.
  3. **Validated Bound Parameter:** SQL queries accept `:cutover_ts` as a mandatory, strictly validated PDO parameter.

---

### 6. GitHub Changelog History Reconciliation & Working Tree State

- **Affected Document Sections:**
  - `POS-FINAL-REVIEW-CHANGELOG-3.md`: Header Status update & Round 4 reference banner
  - `POS-FINAL-REVIEW-CHANGELOG-4.md`: This reconciliation section
- **Previous Discrepancy:**  
  Previous changelogs contained statements stating that "no git commits or pushes were performed." However, inspecting the actual Git repository history reveals that previous engineering rounds were committed and pushed to `https://github.com/Tiffy-gif/Car-WashService` on branch `main` up to commit `6d7eb30`:
  - `2cc1bb2`: Initial workflow design revision
  - `22f47cf`: Design review corrections
  - `32c5394`: Source compatibility audit corrections
  - `6d7eb30`: Round 3 documentation corrections
- **Round 4 Reconciliation:**  
  1. **Published Remote Status:** Acknowledges that commits through `6d7eb30` represent remote published history on GitHub `main`.
  2. **Current Local Working Tree Status:** Reconciles and confirms that **all Round 4 modifications reside strictly in the local working directory** (`C:\xampp\htdocs\Car-WashService`).
  3. **Strict Policy Compliance:** In strict adherence to management instructions, **ZERO git commits and ZERO git pushes** have been performed in Round 4. The working tree remains uncommitted, awaiting executive review and authorization.

---

## Comprehensive Test Matrix Alignment (50 Planned Tests)

The test suite in [POS-IMPLEMENTATION-TEST-MATRIX.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md) has been expanded and verified across 5 functional suites:

| Suite | Focus Area | Test Count | Test IDs |
| :--- | :--- | :---: | :--- |
| **Suite 1** | Receipt Printing & OrderGate Decoupling | 8 | `TC-REC-01` – `TC-REC-08` |
| **Suite 2** | Anti-Fraud KHQR & Scoped Reference Verification | 12 | `TC-KHQR-01` – `TC-KHQR-12` (Includes `05A`, `05B`, and `12`) |
| **Suite 3** | Historical Migration, Cancellation & Refund Reconciliation | 8 | `TC-MIG-01` – `TC-MIG-08` |
| **Suite 4** | Bay Concurrency, Expiry, Safety & Commission Idempotency | 8 | `TC-BAY-01` – `TC-BAY-08` |
| **Suite 5** | Cashier Intake, Business Rules & Accounting Workflows | 13 | `TC-BIZ-01` – `TC-BIZ-13` |
| **Total** | **Comprehensive Planned Verification Test Suite** | **50** | **100% Deterministic Pass/Fail Criteria** |

---

## Read-Only Integrity Safeguards Confirmation

In accordance with strict management directives:
1. **Zero Production Code Touched:** No PHP scripts (`api/`, `core/`, `views/`), JavaScript (`pos/js/`), CSS, HTML, or configuration files were modified.
2. **Zero Database Migrations Executed:** No SQL schema migrations or DDL statements were run against MariaDB. Database tables and existing data remain 100% untouched.
3. **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were issued. All updates exist strictly in local Markdown files ready for executive inspection.
4. **Permanent LAN Self-Sufficiency:** Counter sales, thermal printing, and drawer kick logic operate with 0% external cloud dependencies.
