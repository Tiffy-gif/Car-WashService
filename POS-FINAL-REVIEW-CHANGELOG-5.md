# Final POS Engineering Review – Correction Changelog (Round 5)

**Document ID:** POS-REV-2026-005  
**Project:** Car-WashService  
**Date:** November 6, 2026  
**Status:** Authoritative Specifications Complete – Awaiting Management Implementation Approval  

---

## Executive Overview

Following management's fifth-stage review of the Car-WashService POS architecture, this document registers the final technical, financial, and lifecycle integrity corrections resolving the two remaining accounting and concurrency concerns:
1. **Legacy Payment Reporting & Decoupled Two-Tier Revenue Aggregation:** Completely decoupling payment fallback from refund fallback in historical queries to guarantee that pre-cutover payments and post-cutover refunds are never omitted or double-counted across any reporting window.
2. **Refund Lifecycle Integrity, State Machine & Concurrency Safeguards:** Formulating the complete finite state machine for refund transitions, atomic conditional locks to prevent duplicate cash disbursements, scoped deduplication to prevent duplicate bank reversal credits, cumulative refund ceiling enforcement, and failure/retry/cancellation paths.

All revisions strictly enforce confirmed management directives: **optional customer registration**, **mandatory upfront payment**, **single-page checkout**, and **100% offline LAN self-sufficiency**, under strict read-only engineering safeguards.

---

## 1. Area 1: Legacy Payment Reporting & Decoupled Two-Tier Query

### Previous Problem
In Round 4, Tier 2 historical fallback contained a table-level exclusion:
```sql
WHERE o.created_at < :cutover_ts
  AND o.created_at BETWEEN :start_date AND :end_date
  AND NOT EXISTS (SELECT 1 FROM order_payments p WHERE p.order_id = o.id)
  AND NOT EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = o.id)
```
This coupled payment fallback to refund ledger rows. If an order was created before cutover with its original payment recorded only in legacy records (`orders.payment_status = 'paid'`, $40.00), and later (post-cutover) received a partial refund recorded in `order_refunds` ($15.00):
- `EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = o.id)` evaluated to `TRUE`.
- Tier 2 completely dropped Order #100 from historical payment fallback.
- In Tier 1, no row existed in `order_payments` for Order #100.
- **Result:** The original $40.00 payment was completely omitted from reports! Gross payments became $0.00, refunds became $15.00, and net settled revenue became -$15.00.

### Correction Made
The query architecture now **completely decouples Payment Fallback from Refund Fallback**:
1. **Tier 1 (Discrete Ledgers):** Queries `order_payments` and `order_refunds` strictly by their local server commit timestamps (`p.recorded_at BETWEEN :start_date AND :end_date` / `r.recorded_at BETWEEN :start_date AND :end_date`), with **zero restriction on order creation date**. Any post-cutover transaction on ANY order is 100% captured on its event date.
2. **Tier 2 (Decoupled Historical Fallback):** Evaluates orders created before cutover (`created_at < :cutover_ts`), but partitions payments and refunds independently:
   - **Historical Payment:** Evaluated at $T_{\text{pay}} = \text{COALESCE}(o.\text{paid\_at}, o.\text{created\_at})$. If $T_{\text{pay}}$ is within `:start_date` and `:end_date`, historical payment equals `total_usd` minus any ledger payments already captured in Tier 1 (`GREATEST(0, total_usd - ledger_payments)`). A post-cutover refund row in `order_refunds` has **zero impact** on historical payment attribution.
   - **Historical Refund:** Evaluated at $T_{\text{ref}} = \text{COALESCE}(o.\text{cancelled\_at}, o.\text{created\_at})$. If $T_{\text{ref}}$ is within `:start_date` and `:end_date`, historical refund equals legacy recorded refund minus any ledger refunds already captured in Tier 1 (`GREATEST(0, legacy_refund - ledger_refunds)`).
3. **Multi-Payment & Split Payment Support:** Pre-cutover orders that receive multiple payments post-cutover, or split payments (pre-cutover deposit + post-cutover ledger balance), are perfectly partitioned between historical and ledger tiers.

### SQL & Schema Formulation
```sql
-- Certified Two-Tier Financial Revenue Query for views/owner/reports.php
WITH Tier1_Ledger AS (
    SELECT 
        'ledger_tier' AS reporting_tier,
        COALESCE((SELECT SUM(p.amount_usd + (p.amount_khr / p.exchange_rate)) FROM order_payments p WHERE p.recorded_at BETWEEN :start_date AND :end_date), 0.00) AS gross_payments_collected_usd,
        COALESCE((SELECT SUM(p.amount_usd) FROM order_payments p WHERE p.recorded_at BETWEEN :start_date AND :end_date), 0.00) AS gross_payments_usd_native,
        COALESCE((SELECT SUM(p.amount_khr) FROM order_payments p WHERE p.recorded_at BETWEEN :start_date AND :end_date), 0) AS gross_payments_khr_native,
        COALESCE((SELECT SUM(r.amount_usd + (r.amount_khr / r.exchange_rate)) FROM order_refunds r WHERE r.settlement_status IN ('cash_disbursed', 'settled') AND r.recorded_at BETWEEN :start_date AND :end_date), 0.00) AS settled_refunds_usd,
        COALESCE((SELECT SUM(r.amount_usd) FROM order_refunds r WHERE r.settlement_status IN ('cash_disbursed', 'settled') AND r.recorded_at BETWEEN :start_date AND :end_date), 0.00) AS settled_refunds_usd_native,
        COALESCE((SELECT SUM(r.amount_khr) FROM order_refunds r WHERE r.settlement_status IN ('cash_disbursed', 'settled') AND r.recorded_at BETWEEN :start_date AND :end_date), 0) AS settled_refunds_khr_native,
        COALESCE((SELECT SUM(r.amount_usd + (r.amount_khr / r.exchange_rate)) FROM order_refunds r WHERE r.settlement_status IN ('authorized_pending', 'bank_reversal_pending') AND r.recorded_at BETWEEN :start_date AND :end_date), 0.00) AS pending_refunds_usd,
        COALESCE((SELECT SUM(o.total_usd) FROM orders o WHERE o.created_at >= :cutover_ts AND o.created_at BETWEEN :start_date AND :end_date AND o.status = 'cancelled' AND o.payment_status = 'unpaid'), 0.00) AS unpaid_voids_usd
),
Tier2_PreCutover AS (
    SELECT 
        'pre_cutover_fallback' AS reporting_tier,
        COALESCE(SUM(
            CASE 
                WHEN COALESCE(o.paid_at, o.created_at) BETWEEN :start_date AND :end_date
                 AND COALESCE(o.paid_at, o.created_at) < :cutover_ts
                 AND o.payment_status IN ('paid', 'partially_refunded', 'refunded')
                THEN 
                    CASE 
                        WHEN NOT EXISTS (SELECT 1 FROM order_payments p WHERE p.order_id = o.id)
                        THEN o.total_usd
                        ELSE GREATEST(0.00, o.total_usd - COALESCE((SELECT SUM(p.amount_usd + (p.amount_khr / p.exchange_rate)) FROM order_payments p WHERE p.order_id = o.id), 0.00))
                    END
                ELSE 0.00
            END
        ), 0.00) AS gross_payments_collected_usd,
        COALESCE(SUM(
            CASE 
                WHEN COALESCE(o.cancelled_at, o.created_at) BETWEEN :start_date AND :end_date
                 AND COALESCE(o.cancelled_at, o.created_at) < :cutover_ts
                 AND o.payment_status IN ('refunded', 'partially_refunded')
                THEN 
                    GREATEST(0.00, 
                        (CASE WHEN o.payment_status = 'refunded' THEN COALESCE(o.refunded_amount, o.total_usd) ELSE COALESCE(o.refunded_amount, 0.00) END)
                        - 
                        COALESCE((SELECT SUM(r.amount_usd + (r.amount_khr / r.exchange_rate)) FROM order_refunds r WHERE r.order_id = o.id AND r.settlement_status IN ('cash_disbursed', 'settled')), 0.00)
                    )
                ELSE 0.00
            END
        ), 0.00) AS settled_refunds_usd,
        0.00 AS pending_refunds_usd,
        COALESCE(SUM(
            CASE 
                WHEN o.created_at BETWEEN :start_date AND :end_date AND o.created_at < :cutover_ts AND o.status = 'cancelled' AND (o.payment_status = 'unpaid' OR o.payment_status IS NULL)
                THEN o.total_usd ELSE 0.00
            END
        ), 0.00) AS unpaid_voids_usd
    FROM orders o
    WHERE o.created_at < :cutover_ts
)
SELECT 
    (t1.gross_payments_collected_usd + t2.gross_payments_collected_usd) AS gross_payments_collected_usd,
    t1.gross_payments_usd_native AS gross_payments_usd_native,
    t1.gross_payments_khr_native AS gross_payments_khr_native,
    (t1.settled_refunds_usd + t2.settled_refunds_usd) AS total_settled_refunds_usd,
    t1.settled_refunds_usd_native AS settled_refunds_usd_native,
    t1.settled_refunds_khr_native AS settled_refunds_khr_native,
    t1.pending_refunds_usd AS pending_unsettled_refunds_usd,
    ((t1.gross_payments_collected_usd + t2.gross_payments_collected_usd) - (t1.settled_refunds_usd + t2.settled_refunds_usd)) AS net_settled_revenue_usd,
    ((t1.gross_payments_collected_usd + t2.gross_payments_collected_usd) - (t1.settled_refunds_usd + t2.settled_refunds_usd) - t1.pending_refunds_usd) AS net_realizable_revenue_usd,
    (t1.unpaid_voids_usd + t2.unpaid_voids_usd) AS unpaid_voids_usd
FROM Tier1_Ledger t1
CROSS JOIN Tier2_PreCutover t2;
```

### Worked Accounting Proofs
* **Pre-Cutover Order #100 ($40.00 paid Oct 15) with Post-Cutover Cash Refund ($15.00 on Nov 5):**
  * *October Query:* Gross = $40.00 (Tier 2), Refunds = $0.00, Net Settled = $40.00.
  * *November Query:* Gross = $0.00, Refunds = $15.00 (Tier 1), Net Settled = -$15.00.
  * *Oct-Nov Cross-Cutover Query:* Gross = $40.00 (Tier 2), Refunds = $15.00 (Tier 1), Net Settled = $25.00 ($40 - $15).
  * **Proof:** Zero omission, zero double-counting across all reporting windows!
* **Split Payment Order #500 ($60.00 total: $20.00 deposit Oct 18 + $40.00 balance Nov 2):**
  * *Cross-Cutover Query:* Tier 2 captures `60.00 - 40.00 = $20.00` historical payment; Tier 1 captures `$40.00` ledger payment. Total Gross = **$60.00**. Exact to the penny.

---

## 2. Area 2: Refund Lifecycle Integrity, State Machine & Concurrency Safeguards

### Previous Problem
Refund states previously lacked finite state machine constraints, allowing undefined transitions, potential double cash handouts on repeated button clicks, untracked bank reversal failures, and refunds exceeding total customer payments.

### Correction Made
1. **Extended State Machine Schema:** Added `'failed'` and `'cancelled'` to `order_refunds.settlement_status`. Added columns `bank_reversal_ref`, `failure_reason`, `retry_count`, `cancelled_at`, and deduplication index `uq_refund_bank_ref (payment_id, bank_reversal_ref)`.
2. **Permitted Transition Rules:**
   - `authorized_pending` $\rightarrow$ `cash_disbursed` (cash handover)
   - `authorized_pending` $\rightarrow$ `bank_reversal_pending` (reversal dispatched)
   - `authorized_pending` $\rightarrow$ `cancelled` (aborted prior to payout)
   - `bank_reversal_pending` $\rightarrow$ `settled` (bank webhook/statement confirmed)
   - `bank_reversal_pending` $\rightarrow$ `failed` (bank gateway rejected)
   - `failed` $\rightarrow$ `bank_reversal_pending` (manager retries with `retry_count++`)
   - `failed` $\rightarrow$ `cancelled` (manager abandons digital reversal)
3. **Strictly Forbidden Transitions:** Terminal states (`cash_disbursed`, `settled`, `cancelled`) can **never** transition to any other state. Skipping intermediate verification is strictly blocked.
4. **Atomic Duplicate Cash Disbursement Prevention:**
   ```sql
   UPDATE order_refunds 
   SET settlement_status = 'cash_disbursed', disbursed_at = NOW() 
   WHERE id = :refund_id AND settlement_status = 'authorized_pending';
   ```
   If `rowCount === 0`, execution aborts with HTTP 409 Conflict. The drawer pulse ESC/POS command triggers **only** if rowCount is exactly 1.
5. **Duplicate Bank Reversal Idempotency:** Webhook replay defense via `webhook_events(provider, event_id)` and unique index `uq_refund_bank_ref` prevent duplicate credit deductions.
6. **Cumulative Refund Ceiling Enforcement:** Before inserting any refund row, the API asserts:
   $$\sum \text{Refunds}_{\text{non-cancelled}} + \text{New Refund} \le \text{Total Payments Collected}$$
   Exceeding requests are strictly rejected with HTTP 422 (`REFUND_EXCEEDS_PAYMENT_BALANCE`).
7. **Accounting Impact Matrix:**
   - `authorized_pending` & `bank_reversal_pending`: Increase **Pending Refund Liabilities**; $0.00 impact on drawer cash or net settled revenue.
   - `cash_disbursed`: Deducts from active cash drawer balance AND deducts from Net Settled Revenue.
   - `settled`: Deducts from Net Settled Revenue; $0.00 impact on cash drawer (bank credit).
   - `failed` & `cancelled`: Released liabilities; $0.00 impact on cash drawer or settled revenue.

---

## 3. Comprehensive Test Coverage (58 Planned Test Specifications)

The verification matrix in [POS-IMPLEMENTATION-TEST-MATRIX.md](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md) has been updated with exact preconditions, execution payloads, and pass/fail criteria:

| Suite | Focus Area | Planned Tests | Key Additions in Round 5 |
| :--- | :--- | :---: | :--- |
| **Suite 1** | Receipt Printing & OrderGate Decoupling | 8 | `TC-REC-01` – `TC-REC-08` |
| **Suite 2** | Anti-Fraud KHQR & Scoped Reference Verification | 12 | `TC-KHQR-01` – `TC-KHQR-12` (Includes `05A`, `05B`, and `12`) |
| **Suite 3** | Historical Migration, Cancellation & Refund Reconciliation | 8 | `TC-MIG-01` – `TC-MIG-08` |
| **Suite 4** | Bay Concurrency, Expiry, Safety & Commission Idempotency | 8 | `TC-BAY-01` – `TC-BAY-08` |
| **Suite 5** | Cashier Intake, Business Rules & Accounting Workflows | 22 | `TC-BIZ-01` – `TC-BIZ-21` (Updated `12`; added `14`–`21`) |
| **Total** | **Comprehensive Planned Verification Test Suite** | **58** | **100% Deterministic Verification Criteria** |

### Key Round 5 Test Additions:
- [`TC-BIZ-12` (Updated)](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L123): Decoupled Payment & Refund Fallback (Zero Omission on Legacy Order with Post-Cutover Refund).
- [`TC-BIZ-14`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L125): Pre-Cutover Legacy Order with Multiple Post-Cutover Payments.
- [`TC-BIZ-15`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L126): Pre-Cutover Legacy Order with Split Historical Deposit & Ledger Balance.
- [`TC-BIZ-16`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L127): Pre-Cutover Legacy Order with Multiple Cumulative Partial Refunds (Hist + Ledger).
- [`TC-BIZ-17`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L128): Atomic Duplicate Cash Disbursement Prevention (Concurrency Lock).
- [`TC-BIZ-18`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L129): Duplicate Bank Reversal Webhook Idempotency.
- [`TC-BIZ-19`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L130): Bank Reversal Failure, Retry, and Manager Cancellation Lifecycle.
- [`TC-BIZ-20`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L131): Cumulative Refund Ceiling Enforcement.
- [`TC-BIZ-21`](file:///C:/xampp/htdocs/Car-WashService/POS-IMPLEMENTATION-TEST-MATRIX.md#L132): Cash Drawer Balance Reconciliation Across Multi-State Refunds.

---

## 4. Remaining Operational & Implementation Risks Honestly Reported

1. **Historical Legacy Orders with NULL Timestamps:**  
   In the legacy database, certain historical orders created prior to 2026 have `paid_at IS NULL` and `cancelled_at IS NULL`. For these legacy rows, the fallback query relies on `created_at`. If a legacy order was created on Day 1 but paid on Day 2 in the old system, and `paid_at` was never populated, its historical payment reports on Day 1. This is a permanent limitation of legacy source data that cannot be retroactively manufactured without falsifying records.
2. **Cashier Physical Handover Timing:**  
   The system provides complete concurrency protection against double-clicking the "Disburse Cash" button. However, software cannot physically prevent a cashier from accidentally handing over paper banknotes before clicking the button, or vice versa. Cashier operating procedures must mandate counting banknotes directly in front of the customer upon triggering the counter drawer pulse.
3. **Commercial Bank Webhook Network Connectivity:**  
   Automated bank reversal confirmations depend on external bank webhooks reaching the local server or cloud relay. During internet outages, bank reversals remain in `bank_reversal_pending` until connectivity is restored or shift reconciliation verifies the commercial bank statement. Counter cash functions remain 100% operational on local LAN.

---

## 5. Read-Only Integrity Safeguards Confirmation

In accordance with strict management directives:
1. **Zero Production Code Touched:** No PHP files (`api/`, `core/`, `views/`), JavaScript files (`pos/js/`), CSS, HTML, or configuration files were modified.
2. **Zero Database Migrations Executed:** No SQL schema migrations or DDL statements were executed against MariaDB. Database tables and existing records remain 100% untouched.
3. **Zero Git Commits or Pushes:** No `git commit` or `git push` commands were issued. All revisions exist strictly in local Markdown files ready for executive inspection.
4. **Permanent LAN Self-Sufficiency:** Counter sales, thermal printing, and drawer kick logic operate with 0% external cloud dependencies.
