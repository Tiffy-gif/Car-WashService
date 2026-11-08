# POS Business Rules – Executive Decisions & Technical Design Registry

**Document ID:** POS-OQ-2026-FINAL
**Project:** Car-WashService
**Scope:** Confirmed Executive Decisions, Source Harmonization, Phase 1 Technical Design Specifications, Unresolved Dependencies
**Date:** November 8, 2026 (Updated - Round 9)
**Status:** Executive Decisions Confirmed by Business Owner – Technical Design & Dependencies Documented for Engineering Planning (Awaiting Separate Executive Authorization)

---

## Executive Confirmation Summary (November 8, 2026)

On November 8, 2026, the business owner formally reviewed and confirmed all six core operational business decisions required to finalize the Car-WashService Point-of-Sale (POS) counter workflow.

All six business policy questions are now **CLOSED** as executive policy. These confirmed decisions are distinct from subsequent technical implementation proposals and unresolved technical dependencies, which remain subject to separate executive authorization prior to implementation.

| # | Operational Domain | Confirmed Executive Decision (November 8, 2026) | Operational & Technical Impact | Policy vs. Technical Status |
| :-: | :--- | :--- | :--- | :--- |
| **1** | **Mixed Services Sequencing** | **Lift First $\rightarrow$ Wash Second** | Vehicles requiring both mechanical lube work and car wash undergo Bay 4 lift work first, followed by wash/dry, ensuring a clean vehicle at customer handover. | **Approved Executive Policy** |
| **2** | **Receipt Printing Gate** | **Advisory Printing (Electronic Gate)** | Electronic receipt issuance (`receipt_issued_at`) and confirmed payment (`payment_status = 'paid'`) are the sole service gate pass. Thermal printer paper jams never block service. | **Approved Executive Policy** (Any physical-printing gate is an unapproved proposal) |
| **3** | **Walk-In Identity & Schema** | **Default to `'Walk-in Guest'`** | Retains existing `customer_name VARCHAR(100) NOT NULL` in MariaDB schema without high-risk `ALTER TABLE`. Plate and vehicle category captured via existing cashier flow. | **Approved Executive Policy** |
| **4** | **Historical Orders Reconciliation** | **Preserve Intact + Exceptions Tab** | Original records are preserved untouched (no blanket overwrites). A dedicated **"Historical Unreconciled Exceptions"** tab is added to owner reports for manual review. | **Approved Executive Policy** (Algorithm & schema limitations documented in UTD-1) |
| **5** | **KHQR Verification Channel** | **Store Counter Phone + Soundbox** | POS counter operates 100% on local LAN using store counter phone notifications and soundbox. No public static IP or bank webhook onboarding in initial scope. | **Approved Executive Policy** (Reference uniqueness & format remain UTD-4) |
| **6** | **Financial Reporting Separation** | **Net Settled Revenue $\ne$ Net Operating Profit** | Reports strictly distinguish **Net Settled Revenue** (Cash tender inflow minus settled refunds) from **Net Operating Profit**. Missing COGS flagged, never assumed $0.00. | **Approved Executive Policy** (Timestamps & fallbacks documented in UTD-2 & UTD-1) |

---

## Detailed Confirmed Decisions & Harmonization

### Decision 1: Mixed Services Sequencing (Option Lettering Clarification)

#### Clarification of Option Lettering:
In earlier audit drafts, a lettering ambiguity arose where Option A was described in one document as the existing PHP code behavior (*Wash First $\rightarrow$ Lube Second*) and in another draft as the newly recommended design (*Lift First $\rightarrow$ Wash Second*).

**The owner's decision is definitive and absolute regardless of historical letter labeling:**
* **Confirmed Standard:** **Lift First $\rightarrow$ Wash Second (Bay 4 Mechanical Lift First $\rightarrow$ Bay 1/2 Wash & Dry Second)**.

#### Technical Harmonization:
* **Current PHP Source (`core/BayManager.php:503-509`, line 917):**
  Currently hardcodes:
  ```php
  // Task 1: Wash (Step 1)
  $insTask->execute([$orderId, 'wash', 'Exterior & Interior Wash (Step 1)', 1, 'waiting']);
  // Task 2: Oil Change (Step 2)
  $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Inspection (Step 2)', 2, 'pending_prereq']);
  ```
  And line 917 asserts: `"Sequence Rule: Step 1 (Car Wash) must be finished before Step 2 (Oil Change) can begin."`
* **Implementation Requirement (Work Package WP-2):**
  Refactor `ensureOrderTasks()` and `assignCarToBay()` in [BayManager.php](core/BayManager.php) so that when an order contains both mechanical items (`item_type = 'service'` / mechanical) and wash packages (`item_type = 'package'` / wash):
  - Task 1 (Step 1): Mechanical Lift Service (Bay 4) $\rightarrow$ Initial state: `'waiting'` / active.
  - Task 2 (Step 2): Exterior Wash & Detailing (Bay 1 or 2) $\rightarrow$ Initial state: `'pending_prereq'`.
  - Wash bay reservation for Step 2 is deferred until Step 1 reaches `'completed'`, preventing empty wash bays from sitting idle during long mechanical inspections.

---

### Decision 2: Receipt Printing Service Gate Policy

#### The Decision:
* **Physical thermal paper printing is advisory.**
* The mandatory service admission gate ([OrderGate.php](core/OrderGate.php)) requires confirmed payment (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at IS NOT NULL`).
* Physical thermal printer failures (paper out, head open, cutter jam, USB disconnect) display an advisory toast on the cashier UI and log an event, but **must never block vehicle admission into the wash apron**.
* **Strict Policy Boundary:** An optional `require_receipt_before_wash` setting is **not an approved policy**. Any physical printing blockade is an **unapproved proposal requiring a separate owner decision**. The confirmed operational policy is that electronic receipt issuance alone gates service.

#### Technical Harmonization:
* Refactor [OrderGate.php](core/OrderGate.php):
  ```php
  public static function assertServiceAdmissionAllowed(int $orderId): bool {
      // 1. Payment must be confirmed (payment_status = 'paid')
      // 2. Electronic receipt must be issued (receipt_issued_at IS NOT NULL)
      // 3. Physical paper printing is advisory and NOT required for service admission
  }
  ```
* Physical receipt reprint remains completely read-only (`GET /api/pos/index.php?action=print_receipt`), performs zero database writes, and marks the slip with `*** DUPLICATE RECEIPT ***`.

---

### Decision 3: Walk-In Customer Identity & Schema Integrity

#### The Decision:
* Preserve database schema integrity without modifying table structures:
  `orders.customer_name VARCHAR(100) NOT NULL` (as defined in [init.php](database/init.php#L251)).
* When a customer declines to register or provide personal information, the cashier enters only the license plate and vehicle type; the application automatically records `'Walk-in Guest'` as `customer_name`.
* No mandatory customer phone, vehicle VIN, or unapproved database-level constraints are added. Vehicle category selection (`small_car`, `suv_pickup`, `van_truck`, `motorbike`) is captured per existing cashier flow to calculate correct package pricing.

---

### Decision 4: Historical Unreconciled Orders Migration Policy

#### The Decision:
* Original historical records must be preserved intact. No automated script may bulk-overwrite ambiguous records (e.g., historical orders with `status = 'paid'` but `cancelled_at IS NOT NULL`, or orders with `status = 'finished'` and `payment_status = 'unpaid'`).
* Financial reports in [reports.php](views/owner/reports.php) will incorporate a dedicated **"Historical Unreconciled Exceptions"** review tab.
* This tab isolates pre-cutover tickets that have conflicting operational and financial timestamps for owner inspection and manual write-off authorization, preventing historical test data from distorting active ledger reporting.

---

### Decision 5: KHQR Verification Channel & Local LAN Self-Sufficiency

#### The Decision:
* KHQR payment verification relies on the store's counter merchant devices:
  1. The business's official merchant smartphone bank push notification / SMS alert.
  2. The counter IoT soundbox audible chime (*"Received $XX.XX via KHQR"*).
* No commercial bank webhook onboarding, public static IP, external SSL domain, or cloud callback relays are required in the initial release scope.
* The local POS terminal maintains 100% LAN self-sufficiency.

---

### Decision 6: Financial Reporting Terminology & Cost Transparency

#### The Decision:
* Explicitly separate cash flow metrics from true business profitability:
  1. **Net Settled Revenue:** Gross Confirmed Payments minus Settled Refunds (`disbursed_at` or `settled_at`). Represents actual tender collected and retained in the drawer and merchant bank account.
  2. **Net Operating Profit:** Net Settled Revenue minus (Recorded COGS + Washer Commissions + Mechanic Commissions + Facility Expenses).
* **Missing Cost Rule:** If services or parts lack cost tracking in the legacy database, Net Operating Profit **must not assume $0.00 COGS**. Instead, the report must display an **"Untracked Cost Alert"** indicating the count and volume of items with unrecorded COGS.

---

## Phase 1 Technical Design & Unresolved Technical Dependencies

While executive business decisions are confirmed, several technical design areas cannot be finalized from the existing source schema alone and are registered as **Unresolved Technical Dependencies (UTD)**.

```
+-------------------------------------------------------------------------------+
|                      EXECUTIVE BUSINESS POLICIES (CLOSED)                     |
|  - Mixed Sequencing: Lift First -> Wash Second                                |
|  - Receipt Gate: Electronic issuance only (paper advisory)                    |
|  - Walk-In Identity: Default 'Walk-in Guest', preserve schema                 |
|  - Historical Data: Preserve intact + Exceptions Tab                          |
|  - KHQR Channel: Merchant counter phone + soundbox on local LAN               |
|  - Financial Separation: Net Settled Revenue != Net Operating Profit          |
+-------------------------------------------------------------------------------+
                                      |
                                      v
+-------------------------------------------------------------------------------+
|                 UNRESOLVED TECHNICAL DESIGN DEPENDENCIES (OPEN)               |
|  - UTD-1: Historical reporting limitations, period boundaries & exceptions    |
|  - UTD-2: Reporting timestamp definitions & schema availability               |
|  - UTD-3: Bank reversal finality & lack of automated external proof           |
|  - UTD-4: Scoped digital reference uniqueness & nullable column risks         |
+-------------------------------------------------------------------------------+
```

---

### UTD-1: Historical Financial Reporting, Schema Limits & Reconciliation Exceptions

#### Problem Statement:
Earlier proposals suffered from two critical defects:
1. **Reliance on Non-Existent Source Fields:** The live MariaDB schema ([init.php](database/init.php#L242-L280)) does **NOT contain an `orders.refunded_amount` column**, nor does an `is_initial_migration` flag exist. Inventing these fields in reporting queries creates fatal SQL runtime errors.
2. **Coupled All-or-Nothing Fallbacks:** Assuming that the presence of a post-cutover ledger row means historical fields can be ignored—or vice versa—causes valid transactions to disappear. For example, dropping a historical payment because a post-cutover partial refund exists, or omitting a historical payment because an additional post-cutover balance payment was recorded.

#### Analysis of Actual Source Fields ([init.php](database/init.php#L242-L280)):
The existing `orders` table contains only:
- `total_usd` (DECIMAL 10,2): The static total price of the order.
- `payment_status` (VARCHAR 20): Status string (`'unpaid'`, `'paid'`, `'cancelled'`).
- `paid_at` (DATETIME): Timestamp when marked paid.
- `created_at` (DATETIME): Timestamp when order was created.
- `cancelled_at` (DATETIME): Timestamp when order was cancelled.

Notice what is **missing** from the source schema:
- No discrete payment ledger exists prior to cutover (no historical payment transaction rows).
- No historical refund amount column exists (`refunded_amount` is absent).
- No record of historical cash disbursement vs. digital credit exists.

#### Architectural Principles for Period-Aware Reporting:

1. **Independent Evaluation of Payments and Refunds:**
   Payment recognition and refund recognition are completely decoupled. A refund recorded in `order_refunds` never alters, suppresses, or replaces an order's payment recognition, and a payment never suppresses a refund.

2. **Strict Period Boundaries:**
   Every event is evaluated strictly within a half-open interval `[:start_timestamp, :end_timestamp)`. Events occurring before `:start_timestamp` or on/after `:end_timestamp` are strictly excluded from the period's receipts.

3. **Pre-Cutover Original Payments with No Ledger Records:**
   For orders created before cutover (`orders.created_at < :cutover_ts`), if `orders.payment_status = 'paid'` and no records exist in `order_payments` for that order, the payment is recognized in the period matching `COALESCE(orders.paid_at, orders.created_at)`.

4. **Post-Cutover Payments on Pre-Cutover Orders (Split Payments):**
   If an order created before cutover receives a payment after cutover, that payment is recorded as a discrete row in `order_payments` with `recorded_at >= :cutover_ts`. This payment belongs strictly to the reporting period of its `recorded_at`.
   - *Double-Counting Safeguard & Exception Trigger:* If `orders.payment_status = 'paid'` was *already* set historically on that order, counting both historical `orders.total_usd` AND the new `order_payments.amount_usd` would double-count revenue across lifetime reports if `total_usd` already represented the full order value. Conversely, suppressing one would miss legitimately collected funds if `total_usd` was unpaid or partial.
   - *Resolution:* Because the legacy schema cannot prove whether historical `total_usd` was partially or fully collected, any pre-cutover order with `payment_status = 'paid'` that receives subsequent payments in `order_payments` is flagged as an **Ambiguous Historical Split-Payment Exception** on the Exceptions tab for owner review, rather than guessing a mathematical formula.

5. **Post-Cutover Refunds on Pre-Cutover Orders:**
   A refund issued after cutover against a pre-cutover order is recorded in `order_refunds` with its own `disbursed_at` (cash) or `settled_at` (digital). It is recognized strictly in the post-cutover period of its disbursement/settlement timestamp. The pre-cutover payment remains intact in its historical period.

6. **Multiple Partial Refunds:**
   Each partial refund is a discrete row in `order_refunds`. Each is recognized independently in the period containing its respective `disbursed_at` or `settled_at`.

7. **Historical Pre-Cutover Cancellations:**
   For pre-cutover orders with `cancelled_at IS NOT NULL` and `payment_status = 'paid'`, the actual money returned cannot be reconstructed because `refunded_amount` does not exist in the schema. The system **does not invent a refund value of $0.00 or total_usd**. These orders are classified as **Historical Unreconciled Cancellation Exceptions** and displayed on the Exceptions tab.

#### Pseudocode: Period-Aware Financial Query Logic
```
FUNCTION GeneratePeriodReport(start_timestamp, end_timestamp, cutover_timestamp):
    // -------------------------------------------------------------
    // 1. NEW LEDGER PAYMENTS (Tender collected within period)
    // -------------------------------------------------------------
    ledger_payments = SELECT SUM(amount_usd) FROM order_payments
                      WHERE recorded_at >= start_timestamp
                        AND recorded_at < end_timestamp;

    // -------------------------------------------------------------
    // 2. HISTORICAL PAYMENTS (Pre-cutover orders without ledger rows)
    // Evaluated only if the reporting window covers pre-cutover dates
    // -------------------------------------------------------------
    historical_payments = 0;
    IF start_timestamp < cutover_timestamp THEN
        historical_effective_end = MIN(end_timestamp, cutover_timestamp);

        historical_payments = SELECT SUM(total_usd) FROM orders o
                              WHERE o.created_at < cutover_timestamp
                                AND o.payment_status = 'paid'
                                AND COALESCE(o.paid_at, o.created_at) >= start_timestamp
                                AND COALESCE(o.paid_at, o.created_at) < historical_effective_end
                                // Exclude orders with post-cutover ledger payments to prevent double-counting
                                AND NOT EXISTS (
                                    SELECT 1 FROM order_payments op
                                    WHERE op.order_id = o.id
                                );
    END IF;

    // -------------------------------------------------------------
    // 3. SETTLED REFUNDS (Money disbursed or settled within period)
    // -------------------------------------------------------------
    settled_refunds = SELECT SUM(amount_usd) FROM order_refunds
                      WHERE ((settlement_status = 'cash_disbursed' AND disbursed_at >= start_timestamp AND disbursed_at < end_timestamp)
                          OR (settlement_status = 'settled' AND settled_at >= start_timestamp AND settled_at < end_timestamp));

    // -------------------------------------------------------------
    // 4. NET SETTLED REVENUE FOR PERIOD
    // -------------------------------------------------------------
    gross_period_revenue = ledger_payments + historical_payments;
    net_settled_revenue = gross_period_revenue - settled_refunds;

    // -------------------------------------------------------------
    // 5. IDENTIFY RECONCILIATION EXCEPTIONS (Flagged for Owner Tab)
    // -------------------------------------------------------------
    historical_exceptions = SELECT o.id, o.order_number, o.total_usd, 'Historical Split Payment' AS reason
                            FROM orders o
                            WHERE o.created_at < cutover_timestamp
                              AND o.payment_status = 'paid'
                              AND EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id = o.id);

    RETURN {
        gross_revenue: gross_period_revenue,
        settled_refunds: settled_refunds,
        net_settled_revenue: net_settled_revenue,
        exceptions: historical_exceptions
    };
```

> [!WARNING]
> **Status of UTD-1:**
> The reporting algorithm is **not complete** until demonstrated against actual production data and realistic multi-currency test examples during Phase 2. Legacy pre-cutover cancellations and split payments cannot be reconstructed from the schema without explicit executive write-off or manual reconciliation.

---

### UTD-2: Reporting Timestamp Definitions & Schema Availability

#### Required Timestamp Distinctions:
Financial reporting must distinguish three discrete event timestamps:
1. `provider_event_at`: The timestamp reported by the external payment provider / bank indicating when funds moved.
2. `recorded_at`: The timestamp when the local counter POS system recorded the transaction in its local ledger.
3. `disbursed_at` / `settled_at`: The timestamp when currency physically left the drawer (`disbursed_at` for cash refunds) or when a digital refund settled on the bank account (`settled_at`).

#### Recognition Matrix by Tender & Event Type:

| Tender / Event Type | Reporting Recognition Timestamp | Justification |
| :--- | :--- | :--- |
| **Cash Payment** | `recorded_at` | The physical cash entered the till and was recorded in the active shift drawer at this moment. |
| **Manually Verified KHQR** | `recorded_at` | Verified at the counter terminal by the cashier. `provider_event_at` is preserved for audit if available, but `recorded_at` prevents delayed entry from altering closed shifts. |
| **Cash Refund** | `disbursed_at` | Cash physically departed the drawer till. |
| **Settled Digital Refund** | `settled_at` | Digital credit confirmed settled by bank statement / notification. |
| **Authorized Pending Refund** | None (Informational only) | Does NOT affect revenue. Displayed as a pending liability card. |

#### Period Boundaries & Delayed Notification Handling:
- **Strict Half-Open Interval:** All reporting queries must bound **both** start and end:
  `event_timestamp >= :start_timestamp AND event_timestamp < :end_timestamp`
- **Delayed Notifications:** If a payment occurred at 23:55 on October 31 (`provider_event_at`) but was recorded by the counter cashier at 00:05 on November 1 (`recorded_at`), recognizing on `recorded_at` ensures the closed October report is not silently rewritten. The transaction appears in November's receipts.

> [!NOTE]
> **Design Dependency Notice (UTD-2):**
> `provider_event_at`, `recorded_at`, `disbursed_at`, and `settled_at` do not exist in the current `orders` schema ([init.php](database/init.php)). They are planned columns for the future `order_payments` and `order_refunds` tables. Their introduction depends on the Phase 2 schema design.

---

### UTD-3: Bank Reversal Finality & Limitations of Local Verification

#### Problem Statement:
Earlier proposals claimed that a manager verifying a bank statement snapshot or updating a local row to `'cancelled'` eliminates the risk of a delayed bank settlement. **This claim was false.**
- In retail banking and clearing networks (e.g. Bakong / ABA PayWay), an electronic reversal request may be delayed in clearing queues.
- Checking a mobile banking app at 2:00 PM and observing no debit **does not prove that the reversal cannot settle at 6:00 PM or the next business day**.
- A local database status update to `'cancelled'` has zero influence over banking clearinghouse execution.

#### Safe Operational Rules for Bank Reversals:
1. **Preservation of Original Reversal Attempt:** The original failed reversal record must **never** be deleted or overwritten.
2. **Append-Only Event History:** All state transitions, retry attempts, and manager notes are recorded in an append-only audit log (`order_refund_events`).
3. **Never Treat Local Status as Proof:** A local database status of `'cancelled'` or `'failed'` is never evidence of bank-side cancellation.
4. **Reliable External Evidence Required:** Before a replacement cash payout can even be considered, the manager must review and record definitive evidence of bank outcome:
   - Official bank gateway reversal rejection code (e.g., `"Original transaction expired"` or `"Reversal rejected by clearinghouse"`), OR
   - Formal written statement / confirmation from the merchant acquiring bank verifying that the reversal was discarded and will not settle.
5. **Mandatory Payout Blockade if Uncertain:** If the external bank outcome cannot be verified definitively, or if the reversal status remains pending in clearing, **the replacement cash payout must remain strictly BLOCKED**.
6. **Manager Authorization & Atomic Concurrency Lock:** When authorized, a replacement cash refund requires Manager PIN entry and an atomic conditional lock to prevent concurrent double-payouts.
7. **Traceability Link:** The replacement cash refund row must maintain an explicit link `replaced_refund_id` pointing directly to the original failed reversal row.

> [!CAUTION]
> **Status of UTD-3:**
> On an offline or local LAN counter operating without direct bank gateway API integrations, **no automated mechanism exists to guarantee bank reversal finality**. Therefore, the replacement cash refund workflow remains **unresolved and requires an approved business operational procedure** (such as mandatory holding periods or formal merchant bank clearance) before implementation.

---

### UTD-4: Scoped KHQR Reference Uniqueness & Nullable Column Vulnerabilities

#### The Nullable Column Problem in MariaDB/MySQL:
Earlier proposals recommended:
`UNIQUE KEY uq_digital_payment_ref (provider, merchant_account_id, payment_ref)`
Under ANSI SQL standards and MySQL/MariaDB InnoDB implementation:
- **`NULL != NULL` in UNIQUE constraints.**
- If any column in a composite unique index is `NULL`, MySQL allows **multiple rows with identical values**!
- If `merchant_account_id` is nullable, multiple payments with `provider = 'khqr'` and `payment_ref = '123456'` can be inserted without triggering a duplicate error.
- If cash payments share the same table and have `payment_ref = NULL`, they do not collide, but any digital payment with missing fields silently bypasses duplicate protection.

#### Architectural Requirements for Digital Payment Uniqueness:
1. **Mandatory Fields for Verified Digital Payments:**
   For all digital transactions, the following three attributes must be strictly `NOT NULL`:
   - `provider` (VARCHAR 50 NOT NULL): Specific acquiring channel (e.g. `'khqr_bakong'`, `'khqr_aba'`).
   - `merchant_account_id` (VARCHAR 50 NOT NULL): Receiving merchant identifier (e.g. `'store_counter_01'`).
   - `payment_ref` (VARCHAR 100 NOT NULL): Genuine bank reference captured from the bank notification.
2. **Separation of Cash Payments:**
   Cash transactions must **never** be subjected to bank reference validation. Cash payments must either:
   - Reside in a separate tender table, or
   - Use a dedicated digital payment ledger table (`order_digital_payments`) where `(provider, merchant_account_id, payment_ref)` are strictly `NOT NULL` and enforced by a database `UNIQUE` index.
3. **No Assumption of Global 4-8 Digit Uniqueness:**
   A 4-to-8 digit bank reference code (often an internal bank trace or rollover transaction sequence) is **not globally unique**. It can repeat across different days, different merchant accounts, or different banking institutions.
4. **Idempotency Safeguards on Local LAN:**
   Because official bank reference formats and rollover rules vary between ABA PayWay and NBC Bakong, the counter terminal enforces multi-layered idempotency:
   - Cart session `idempotency_key` (UUID v4) prevents duplicate submissions from cashier double-clicks.
   - Scoped database uniqueness `(provider, merchant_account_id, payment_ref)` where applicable.
   - Cashier attribution (`cashier_id`) and counter merchant phone visual matching.

> [!WARNING]
> **Status of UTD-4:**
> The exact bank reference structure, length, character set, and rollover cycle have not been verified against official NBC Bakong / ABA PayWay technical documentation. Scoped digital reference uniqueness and validation rules remain an **Unresolved Technical Dependency** pending merchant bank documentation.

---

## Sign-Off & Governance Status

* **Executive Business Decisions:** Confirmed by Business Owner (November 8, 2026)
* **Technical Implementation Plan:** Subject to Separate Executive Authorization
* **58 Test Matrix Specifications:** **Planned specifications only**; remain unexecuted until authorized testing phase.
* **Current Phase Status:** Phase 0 (Read-Only Source, Runtime & Configuration Inspection)
