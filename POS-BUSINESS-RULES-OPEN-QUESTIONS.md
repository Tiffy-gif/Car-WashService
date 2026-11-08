# POS Business Rules – Executive Decisions & Technical Design Registry

**Document ID:** POS-OQ-2026-FINAL
**Project:** Car-WashService
**Scope:** Confirmed Executive Decisions, Source Harmonization, Phase 1 Technical Design Specifications, Unresolved Dependencies
**Date:** November 8, 2026 (Updated - Round 10)
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
| **5** | **KHQR Verification Channel** | **Store Counter Phone + Soundbox** | POS counter operates 100% on local LAN using store counter phone notifications and soundbox. No public static IP or bank webhook onboarding in initial scope. | **Approved Executive Policy** (Two-table schema & reference format remain UTD-4) |
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
|  - UTD-4: Two-table digital constraint schema & unverified reference formats  |
+-------------------------------------------------------------------------------+
```

---

### UTD-1: Historical Financial Reporting, Schema Limits & Reconciliation Exceptions

#### Problem Statement:
Earlier proposals contained two fatal flaws:
1. **Unsafe Fallback Omission:** If an order had both a historical payment record and a subsequent post-cutover ledger payment, earlier logic completely omitted the historical order from historical reports. This resulted in silent revenue loss during reporting periods.
2. **Unsupported Schema Assumptions:** The live MariaDB schema ([init.php](database/init.php#L242-L280)) possesses `total_usd`, `paid_at`, `payment_status`, and `cancelled_at`, but **does NOT contain `orders.refunded_amount`**, nor does any payment ledger exist prior to cutover. Inventing unverified formulas or assuming historical refunds can be calculated from non-existent columns creates false financial reports.

#### Reporting Principles for Historical Financials:
1. **Independent Evaluation of Payments and Refunds:**
   Payment recognition and refund recognition are evaluated completely independently. A post-cutover refund in `order_refunds` never causes a historical payment in `orders` to disappear, and a payment never suppresses a refund.
2. **Strict Half-Open Period Boundaries:**
   Every event is bounded strictly by `[:start_timestamp, :end_timestamp)`. Events occurring before the start date or on/after the end date are excluded from the period.
3. **Confirmed vs. Unreconciled Exception Distinction:**
   - **Confirmed Revenue:** Includes all new ledger payments in `order_payments` within the period, plus un-conflicted pre-cutover orders where `payment_status = 'paid'` and no subsequent ledger adjustments exist.
   - **Unreconciled Historical Exceptions:** If a pre-cutover order has subsequent ledger payments or an unrecorded cancellation refund, the unreconstructable amount is **NEVER silently included in confirmed revenue** (which would risk double-counting) and **NEVER treated as zero or discarded** (which would understate assets). It is isolated into an explicit **"Unreconciled Historical Amount (Under Audit)"** metric and listed line-by-line on the Exceptions tab.

#### Realistic Historical Scenarios & Exact Handling:

##### Example 1: Fully Historical Paid Order (Unconflicted)
* **Preconditions:** Order #101 created `2026-10-15 10:00:00`, `paid_at = '2026-10-15 10:02:00'`, `total_usd = 25.00`, `payment_status = 'paid'`. No records exist in `order_payments` or `order_refunds`.
* **October 2026 Report:** Confirmed Historical Payment = +$25.00. Settled Refunds = $0.00. Net Settled Revenue = +$25.00.
* **November 2026 Report:** Excluded by period boundary ($0.00).
* **Audit Result:** 100% confirmed; zero exceptions.

##### Example 2: Historical Order with Subsequent Partial Ledger Payment (Split Payment)
* **Preconditions:** Order #102 created `2026-10-20 14:00:00`, `paid_at = '2026-10-20 14:05:00'`, `total_usd = 50.00`, `payment_status = 'paid'`. Cutover occurs on November 1. On November 10, an additional payment of $20.00 is recorded in `order_payments` (`recorded_at = '2026-11-10 09:00:00'`).
* **Source Limitation:** The legacy schema has no deposit column. Was $50 the full total and $20 a late balance, or was $30 paid in October and $20 in November? The schema cannot prove this.
* **November 2026 Report:** Confirmed Ledger Payment = +$20.00 (from `order_payments`).
* **October 2026 Report / Lifetime Reconciliation:**
  - Confirmed Revenue does NOT include the full $50 (prevents double-counting).
  - Confirmed Revenue does NOT treat the historical payment as $0.00 (prevents silent omission).
  - Order #102 is flagged as a **Historical Split-Payment Exception**: Confirmed Ledger = $20.00, Unreconciled Historical Amount = $50.00 (Under Audit). Displayed prominently on the Exceptions tab for executive write-off or confirmation.

##### Example 3: Historical Order with Multiple Post-Cutover Partial Refunds
* **Preconditions:** Order #103 created `2026-10-05 08:00:00`, `paid_at = '2026-10-05 08:05:00'`, `total_usd = 60.00`, `payment_status = 'paid'`. Zero rows in `order_payments`. After cutover, two partial refunds are issued: Refund A for $10.00 on November 12 (`disbursed_at = '2026-11-12'`), and Refund B for $15.00 on November 15 (`disbursed_at = '2026-11-15'`).
* **October 2026 Report:** Confirmed Historical Payment = +$60.00. Settled Refunds = $0.00. Net Settled Revenue = +$60.00. (The subsequent refunds do NOT cause the historical payment to disappear).
* **November 2026 Report:** Historical Payments = $0.00. Settled Refunds = +$25.00 ($10 + $15 from `order_refunds`). Net Settled Revenue = -$25.00.
* **Audit Result:** Payments and refunds are recognized independently in their respective event periods. Zero omission, zero double-counting.

##### Example 4: Historical Cancellation with No Reconstructable Refund Amount
* **Preconditions:** Order #104 created `2026-10-18 11:00:00`, `paid_at = '2026-10-18 11:02:00'`, `total_usd = 40.00`, `payment_status = 'paid'`, `cancelled_at = '2026-10-18 11:30:00'`.
* **Source Limitation:** `orders.refunded_amount` does not exist in the database. The system cannot verify whether the customer received $40 cash back, $20 partial refund, or zero.
* **Accounting Treatment:** The system **does not invent a formula**. It does not assume $40 was retained (overstating revenue) and does not assume $40 was refunded (understating revenue).
* **Audit Result:** Order #104 is excluded from Confirmed Revenue and recorded as a **Historical Unreconciled Cancellation Exception** with $40.00 flagged under audit for owner manual review.

#### Pseudocode: Period-Aware Financial Query Logic
```
FUNCTION GeneratePeriodReport(start_timestamp, end_timestamp, cutover_timestamp):
    // -------------------------------------------------------------
    // 1. CONFIRMED LEDGER PAYMENTS (Collected in period)
    // -------------------------------------------------------------
    confirmed_ledger_payments = SELECT COALESCE(SUM(amount_usd), 0.00)
                                FROM order_payments
                                WHERE recorded_at >= start_timestamp
                                  AND recorded_at < end_timestamp;

    // -------------------------------------------------------------
    // 2. CONFIRMED UNCONFLICTED HISTORICAL PAYMENTS (Pre-cutover)
    // Evaluated only if the reporting window covers pre-cutover dates
    // -------------------------------------------------------------
    confirmed_historical_payments = 0.00;
    IF start_timestamp < cutover_timestamp THEN
        historical_effective_end = MIN(end_timestamp, cutover_timestamp);

        confirmed_historical_payments = SELECT COALESCE(SUM(total_usd), 0.00)
                                        FROM orders o
                                        WHERE o.created_at < cutover_timestamp
                                          AND o.payment_status = 'paid'
                                          AND o.cancelled_at IS NULL
                                          AND COALESCE(o.paid_at, o.created_at) >= start_timestamp
                                          AND COALESCE(o.paid_at, o.created_at) < historical_effective_end
                                          // Exclude split orders with post-cutover ledger payments to prevent double-counting
                                          AND NOT EXISTS (
                                              SELECT 1 FROM order_payments op
                                              WHERE op.order_id = o.id
                                          );
    END IF;

    // -------------------------------------------------------------
    // 3. CONFIRMED SETTLED REFUNDS (Disbursed/Settled in period)
    // -------------------------------------------------------------
    confirmed_settled_refunds = SELECT COALESCE(SUM(amount_usd), 0.00)
                                FROM order_refunds
                                WHERE ((settlement_status = 'cash_disbursed' AND disbursed_at >= start_timestamp AND disbursed_at < end_timestamp)
                                    OR (settlement_status = 'settled' AND settled_at >= start_timestamp AND settled_at < end_timestamp));

    // -------------------------------------------------------------
    // 4. CONFIRMED NET SETTLED REVENUE
    // -------------------------------------------------------------
    gross_confirmed_revenue = confirmed_ledger_payments + confirmed_historical_payments;
    net_settled_revenue = gross_confirmed_revenue - confirmed_settled_refunds;

    // -------------------------------------------------------------
    // 5. UNRECONCILED HISTORICAL EXCEPTIONS (Flagged for Review Tab)
    // -------------------------------------------------------------
    split_exceptions = SELECT o.id, o.order_number, o.total_usd,
                              'Historical Split Payment' AS exception_type,
                              (SELECT COALESCE(SUM(op.amount_usd), 0.00) FROM order_payments op WHERE op.order_id = o.id) AS post_cutover_ledger_paid
                       FROM orders o
                       WHERE o.created_at < cutover_timestamp
                         AND o.payment_status = 'paid'
                         AND EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id = o.id);

    cancellation_exceptions = SELECT o.id, o.order_number, o.total_usd,
                                     'Unreconciled Historical Cancellation' AS exception_type,
                                     0.00 AS post_cutover_ledger_paid
                              FROM orders o
                              WHERE o.created_at < cutover_timestamp
                                AND o.payment_status = 'paid'
                                AND o.cancelled_at IS NOT NULL;

    RETURN {
        confirmed_metrics: {
            gross_payments: gross_confirmed_revenue,
            settled_refunds: confirmed_settled_refunds,
            net_settled_revenue: net_settled_revenue
        },
        unreconciled_exceptions: {
            split_orders: split_exceptions,
            cancellations: cancellation_exceptions
        }
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
- In retail banking and clearing networks (e.g. Bakong / ABA PayWay), an electronic reversal request may be delayed in clearing queues.
- Checking a mobile banking app at 2:00 PM and observing no debit **does not prove that the reversal cannot settle at 6:00 PM or the next business day**.
- A local database status update to `'cancelled'` has zero influence over banking clearinghouse execution.

#### Unresolved Replacement Cash Process:
Because the counter operates on an offline local LAN without real-time bank API integrations:
- **No Automated Finality Mechanism Exists:** The POS cannot independently verify whether a bank has permanently dropped a pending reversal.
- **Replacement Cash Payout is Strictly BLOCKED:** The POS cashier interface **must not provide an active replacement-cash payout button or automated workflow** while the bank outcome remains uncertain.
- **Process Remains Unresolved:** The replacement cash refund workflow is classified as an **Unresolved Technical Dependency**. It requires an approved business operational procedure (e.g., mandatory 72-hour waiting period or signed bank clearance) before any technical implementation can be designed.

#### Safe Operational Rules for Event Logging:
If an operational procedure is formally approved by management in the future, the following architectural controls must be enforced:
1. **Preservation of Original Reversal Attempt:** The original failed reversal record must **never** be deleted or overwritten.
2. **Append-Only Event History:** All state transitions and manager notes are recorded in an append-only audit log (`order_refund_events`).
3. **Never Treat Local Status as Proof:** A local database status of `'cancelled'` or `'failed'` is never evidence of bank-side cancellation.
4. **Manager Authorization & Atomic Concurrency Lock:** Replacement payouts must require explicit Manager PIN entry and an atomic conditional lock to prevent concurrent double-payouts.
5. **Traceability Link:** Any replacement refund row must maintain an explicit foreign key link `replaced_refund_id` pointing directly to the original failed reversal row.

---

### UTD-4: Two-Table Digital Constraint Schema & Scoped Reference Uniqueness

#### The MariaDB Nullable Column Vulnerability:
Earlier single-table proposals attempted to use:
`UNIQUE (provider, merchant_account_id, payment_ref)`
Under ANSI SQL and MySQL/MariaDB InnoDB rules:
- **`NULL != NULL` in UNIQUE constraints.**
- If `payment_ref` or `merchant_account_id` allow `NULL`, multiple identical rows with `NULL` bypass duplicate protection completely.
- If cash payments share the table and use `NULL` for digital references, they avoid collisions, but any digital transaction with missing data also bypasses uniqueness.

#### Two-Table Architectural Solution:
To enforce 100% database-level integrity without nullable loopholes, digital payments are decoupled from cash payments across two normalized tables:

```sql
-- 1. General Tender Ledger (Cash & Digital Payments)
CREATE TABLE IF NOT EXISTS order_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method ENUM('cash','khqr') NOT NULL,
    amount_usd DECIMAL(10,2) NOT NULL,
    amount_khr INT NOT NULL,
    exchange_rate INT NOT NULL,
    cashier_id INT NOT NULL,
    recorded_at DATETIME NOT NULL,
    INDEX idx_order_payments_order (order_id),
    INDEX idx_order_payments_recorded (recorded_at)
) ENGINE=InnoDB;

-- 2. Dedicated Digital Transaction Ledger (Strictly Non-Null Attributes)
CREATE TABLE IF NOT EXISTS order_digital_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL UNIQUE,           -- 1:1 Foreign Key to order_payments
    order_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL,            -- e.g. 'khqr_bakong', 'khqr_aba'
    merchant_account_id VARCHAR(50) NOT NULL, -- e.g. 'counter_phone_01'
    payment_ref VARCHAR(100) NOT NULL,        -- Genuine bank transaction reference
    provider_event_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref),
    INDEX idx_digital_order (order_id)
) ENGINE=InnoDB;
```

#### Why This Resolves the Contradiction:
1. **Cash Transactions:** Inserted into `order_payments` with `payment_method = 'cash'`. They do not create rows in `order_digital_payments`, eliminating any requirement for fake sentinels or digital references.
2. **Verified Digital Transactions:** Inserted into both `order_payments` and `order_digital_payments`. Because `provider`, `merchant_account_id`, and `payment_ref` are all **strictly `NOT NULL`**, the unique constraint `uq_digital_bank_ref` is 100% airtight and cannot be bypassed.
3. **No Assumption of Global 4-8 Digit Uniqueness:** A 4-to-8 digit reference code is **not globally unique**. Uniqueness is scoped strictly to `(provider, merchant_account_id, payment_ref)`.

> [!WARNING]
> **Status of UTD-4:**
> The exact reference format, character set, length, and rollover cycle have not been verified against official NBC Bakong / ABA PayWay technical documentation. Scoped digital reference uniqueness and validation rules remain an **Unresolved Technical Dependency** pending merchant bank documentation.

---

## Sign-Off & Governance Status

* **Executive Business Decisions:** Confirmed by Business Owner (November 8, 2026)
* **Technical Implementation Plan:** Subject to Separate Executive Authorization
* **58 Test Matrix Specifications:** **Planned specifications only**; remain unexecuted until authorized testing phase.
* **Current Phase Status:** Phase 0 (Read-Only Source, Runtime & Configuration Inspection)
