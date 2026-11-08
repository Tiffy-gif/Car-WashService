# POS Business Rules – Executive Decisions & Technical Design Registry

**Document ID:** POS-OQ-2026-FINAL
**Project:** Car-WashService
**Scope:** Confirmed Executive Decisions, Source Harmonization, Phase 1 Technical Design Specifications, Unresolved Dependencies
**Date:** November 8, 2026 (Updated - Round 11)
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
| **4** | **Historical Orders Reconciliation** | **Preserve Intact + Exceptions Tab** | Original records are preserved untouched (no blanket overwrites). A dedicated **"Historical Unreconciled Exceptions"** tab is added to owner reports for manual review. | **Approved Executive Policy** (Accounting limits documented in UTD-1) |
| **5** | **KHQR Verification Channel** | **Store Counter Phone + Soundbox** | POS counter operates 100% on local LAN using store counter phone notifications and soundbox. No public static IP or bank webhook onboarding in initial scope. | **Approved Executive Policy** (Two-table schema & provisional format in UTD-4) |
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
|  - UTD-1: Historical split-payment accounting & reconciliation exceptions     |
|  - UTD-2: Reporting timestamp definitions & schema availability               |
|  - UTD-3: Bank reversal timeout, retry safety & unapproved waiting periods    |
|  - UTD-4: Two-table digital payment architecture & provisional KHQR format    |
+-------------------------------------------------------------------------------+
```

---

### UTD-1: Historical Split-Payment Accounting & Reconciliation Exceptions

#### Problem Statement:
The live MariaDB schema ([init.php](database/init.php#L242-L280)) possesses `total_usd`, `paid_at`, `payment_status`, and `cancelled_at`, but **does NOT contain `orders.refunded_amount`**, nor does any payment ledger exist prior to cutover.
Earlier proposals suffered from two conflicting errors:
1. **Unsafe Silent Omission:** Excluded the entire historical order simply because a subsequent ledger payment existed (`NOT EXISTS (SELECT 1 FROM order_payments ...)`).
2. **Unsafe Double-Counting Assumption:** Automatically assumed that subsequent ledger payments can be added to the full historical order total without knowing whether the subsequent payment was part of that total or additional to it.

#### Explicit Accounting Distinctions:
To prevent financial distortion, reporting queries must explicitly distinguish:
1. **Historical Order Total (`orders.total_usd`):** The static order amount stored in the legacy table.
2. **Amount Historically Recognized as Paid:** The portion of the historical total confirmed paid prior to cutover. In the legacy schema, because no ledger exists, this can only be confirmed for un-conflicted orders where `payment_status = 'paid'` and zero subsequent ledger rows exist.
3. **Subsequent Ledger Payments (`order_payments.amount_usd`):** Payments recorded after cutover with `recorded_at >= :cutover_ts`.
4. **Remaining Unreconciled Amount:** The portion of an order's value whose payment status cannot be mathematically verified from source data.
5. **Subsequent Refunds (`order_refunds.amount_usd`):** Discrete refunds recorded after cutover.

#### Binding Accounting Rules:
- **Never Automatically Add:** A subsequent ledger payment must **never** be added to the full historical order total. If an order had `total_usd = $50.00` and receives a post-cutover payment of `$20.00`, adding them to report `$70.00` would double-count revenue if the `$20.00` was merely the customer paying off a remaining balance.
- **Never Silently Exclude:** The existence of a subsequent ledger payment must **never** cause the historical order to be completely omitted, which would erase legitimate revenue collected.
- **Never Assume Full Payment:** The historical order total must **never** be assumed to equal the amount actually paid when subsequent payments exist.
- **No Invented Allocation Formulas:** The system must not invent arbitrary formulas (e.g. `$50 - $20 = $30`) without supporting source data.
- **Exceptions Tab Prevents Double-Counting:** The subsequent ledger payment is recognized as confirmed revenue in its own post-cutover period. The historical order is isolated on the **Historical Unreconciled Exceptions** tab with its historical total reported under audit. It is **never silently added to confirmed historical revenue** (preventing double-counting) and **never treated as zero** (preventing omission).

#### Realistic Historical Scenarios & Exact Handling:

##### Example 1: Fully Historical Paid Order (Unconflicted)
* **Preconditions:** Order #101 created `2026-10-15 10:00:00`, `paid_at = '2026-10-15 10:02:00'`, `total_usd = 25.00`, `payment_status = 'paid'`. Zero rows in `order_payments` or `order_refunds`.
* **October 2026 Period Report:** Confirmed Historical Payment = +$25.00. Settled Refunds = $0.00. Net Settled Revenue = +$25.00.
* **November 2026 Period Report:** Excluded by period boundary ($0.00).
* **Audit Result:** 100% confirmed; zero exceptions.

##### Example 2: Historical Order with Subsequent Partial Ledger Payment (Split Payment)
* **Preconditions:** Order #102 created `2026-10-20 14:00:00`, `paid_at = '2026-10-20 14:05:00'`, `total_usd = 50.00`, `payment_status = 'paid'`. Cutover occurs on November 1. On November 10, an additional payment of $20.00 is recorded in `order_payments` (`recorded_at = '2026-11-10 09:00:00'`).
* **Source Limitation Analysis:** The legacy database lacks a deposit ledger. The source data **cannot establish whether the $20.00 is part of the $50.00 (balance payment) or additional to it (upsell/add-on)**.
* **November 2026 Period Report:** Confirmed Ledger Payment = +$20.00 (from `order_payments`).
* **October 2026 Period Report / Lifetime Totals:**
  - The historical $50.00 is **not** included in October Confirmed Revenue (prevents potential $20 double-counting).
  - The historical $50.00 is **not** treated as $0.00 (prevents silent loss of historical revenue).
  - Order #102 is recorded as a **Historical Split-Payment Exception**:
    * Historical Order Total: $50.00
    * Post-Cutover Ledger Payments: $20.00
    * Relationship: **Indeterminate (Source data lacks deposit allocation)**
    * Unreconciled Historical Amount: $50.00 (Under Audit)
    * Action: Displayed on the Exceptions tab for owner manual reconciliation.

##### Example 3: Historical Order with Multiple Post-Cutover Partial Refunds
* **Preconditions:** Order #103 created `2026-10-05 08:00:00`, `paid_at = '2026-10-05 08:05:00'`, `total_usd = 60.00`, `payment_status = 'paid'`. Zero rows in `order_payments`. After cutover, two partial refunds are issued: Refund A for $10.00 on November 12 (`disbursed_at = '2026-11-12'`), and Refund B for $15.00 on November 15 (`disbursed_at = '2026-11-15'`).
* **October 2026 Period Report:** Confirmed Historical Payment = +$60.00. Settled Refunds = $0.00. Net Settled Revenue = +$60.00. (The subsequent refunds do NOT cause the historical payment to disappear).
* **November 2026 Period Report:** Historical Payments = $0.00. Settled Refunds = +$25.00 ($10 + $15 from `order_refunds`). Net Settled Revenue = -$25.00.
* **Audit Result:** Payments and refunds are recognized independently in their respective event periods. Zero omission, zero double-counting.

##### Example 4: Historical Cancellation with No Reconstructable Refund Amount
* **Preconditions:** Order #104 created `2026-10-18 11:00:00`, `paid_at = '2026-10-18 11:02:00'`, `total_usd = 40.00`, `payment_status = 'paid'`, `cancelled_at = '2026-10-18 11:30:00'`.
* **Source Limitation Analysis:** `orders.refunded_amount` does not exist in the database. The system cannot verify whether the customer received $40 cash back, $20 partial refund, or zero.
* **Accounting Treatment:** The system does not invent a formula. It does not assume $40 was retained and does not assume $40 was refunded.
* **Audit Result:** Excluded from Confirmed Revenue and recorded as a **Historical Unreconciled Cancellation Exception** ($40.00 under audit) for owner manual review.

#### Pseudocode: Period-Aware Financial Query Logic
```
FUNCTION GeneratePeriodReport(start_timestamp, end_timestamp, cutover_timestamp):
    // -------------------------------------------------------------
    // 1. CONFIRMED LEDGER PAYMENTS (Collected in period)
    // Bound strictly by start and end of reporting window
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
                                          // Exclude orders with subsequent ledger payments from confirmed total
                                          // to prevent double-counting indeterminate split amounts
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
    // 4. CONFIRMED NET SETTLED REVENUE FOR PERIOD
    // -------------------------------------------------------------
    gross_confirmed_revenue = confirmed_ledger_payments + confirmed_historical_payments;
    net_settled_revenue = gross_confirmed_revenue - confirmed_settled_refunds;

    // -------------------------------------------------------------
    // 5. LIFETIME RECONCILIATION EXCEPTIONS REGISTRY (Under Audit)
    // Distinct from period revenue metrics; reported on Exceptions Tab
    // -------------------------------------------------------------
    split_exceptions = SELECT o.id, o.order_number, o.total_usd AS historical_order_total,
                              'Historical Split Payment (Allocation Indeterminate)' AS exception_type,
                              (SELECT COALESCE(SUM(op.amount_usd), 0.00) FROM order_payments op WHERE op.order_id = o.id) AS post_cutover_ledger_paid
                       FROM orders o
                       WHERE o.created_at < cutover_timestamp
                         AND o.payment_status = 'paid'
                         AND EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id = o.id);

    cancellation_exceptions = SELECT o.id, o.order_number, o.total_usd AS historical_order_total,
                                     'Unreconciled Historical Cancellation' AS exception_type,
                                     0.00 AS post_cutover_ledger_paid
                              FROM orders o
                              WHERE o.created_at < cutover_timestamp
                                AND o.payment_status = 'paid'
                                AND o.cancelled_at IS NOT NULL;

    RETURN {
        confirmed_period_metrics: {
            gross_payments: gross_confirmed_revenue,
            settled_refunds: confirmed_settled_refunds,
            net_settled_revenue: net_settled_revenue
        },
        lifetime_unreconciled_exceptions: {
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

### UTD-3: Bank Reversal Outcomes, Timeout Safety & Unapproved Waiting Periods

#### Three Distinct Reversal Outcomes:
In digital payment processing, reversal requests result in one of three distinct states:
1. **Definitive Bank Rejection:** The bank clearinghouse explicitly returns a final rejection (e.g. invalid transaction ID, account closed, or original transaction already reversed).
2. **Request Timeout with Unknown Bank Outcome:** A network disconnection, HTTP 504 gateway timeout, or bank app pending status occurs where it is **unknown whether the bank processed or will process the reversal**.
3. **Confirmed Bank Settlement:** The reversal is definitively confirmed as settled and credited to the customer's account.

#### Binding Reversal Safety Rules:
- **Timeouts Are Never Rejections:** A request timeout or lack of immediate confirmation must **never** be treated as a definitive bank rejection.
- **No Automatic Retry on Timeout:** An uncertain reversal request must **never be automatically retried**. Retrying an in-flight timeout risks dispatching duplicate debit requests to the banking network, creating double-deductions.
- **Replacement Cash Strictly Blocked:** If the bank outcome is uncertain, **replacement cash payout is strictly BLOCKED**.
- **Retry Preconditions:** A retry is permitted ONLY when reliable evidence establishes that the original request was completely dropped and cannot settle, and an approved operational retry procedure exists.
- **Unapproved 72-Hour Example Clarification:** Any mention of a "72-hour waiting period" is strictly an **unapproved operational example requiring bank and owner validation**. A 72-hour delay does NOT prove bank finality and does NOT automatically permit cash replacement. Whenever the bank outcome is uncertain, the cash payout blockade remains in effect.
- **Archival Controls:** If an operational procedure is formally approved in the future, it must enforce Manager PIN entry, an atomic conditional lock, append-only event logging (`order_refund_events`), and foreign key linking via `replaced_refund_id`.

---

### UTD-4: Two-Table Digital Payment Architecture & Provisional KHQR Format

#### The MariaDB Nullable Column Vulnerability:
Under ANSI SQL and MySQL/MariaDB InnoDB rules:
- **`NULL != NULL` in UNIQUE constraints.**
- If any column in a composite unique index is `NULL`, MySQL allows multiple identical rows containing `NULL` without triggering a duplicate error.
- If cash payments share a single table and use `NULL` for digital references, any digital transaction with missing attributes also bypasses uniqueness.

#### Two-Table Normalized Schema Solution:
To enforce 100% database-level integrity, digital payments are decoupled from general tender records across two normalized tables with explicit foreign keys:

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
    CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_order_payments_order (order_id),
    INDEX idx_order_payments_recorded (recorded_at)
) ENGINE=InnoDB;

-- 2. Dedicated Digital Transaction Ledger (Strictly Non-Null Attributes)
CREATE TABLE IF NOT EXISTS order_digital_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL UNIQUE,           -- 1:1 Foreign Key to order_payments
    order_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL,            -- e.g. 'khqr_bakong', 'khqr_aba'
    merchant_account_id VARCHAR(50) NOT NULL, -- e.g. 'store_counter_01'
    payment_ref VARCHAR(100) NOT NULL,        -- Genuine bank transaction reference
    provider_event_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_digital_payments_payment FOREIGN KEY (payment_id) REFERENCES order_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_digital_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT uq_digital_bank_ref UNIQUE (provider, merchant_account_id, payment_ref),
    INDEX idx_digital_order (order_id)
) ENGINE=InnoDB;
```

#### Transaction-Level Validation & Rollback Behavior:
Database constraints alone cannot ensure that every `khqr` row in `order_payments` has a corresponding row in `order_digital_payments`, nor that cash tenders do not have one. This must be strictly enforced via application transaction logic:

```php
$pdo->beginTransaction();
try {
    // 1. Insert into general tender ledger
    $insPayment->execute([$orderId, $paymentMethod, $amountUsd, $amountKhr, $rate, $cashierId, $now]);
    $paymentId = (int)$pdo->lastInsertId();

    if ($paymentMethod === 'khqr') {
        // 2a. Digital Tender: Validate non-empty attributes
        if (empty($provider) || empty($merchantAccountId) || empty($paymentRef)) {
            throw new InvalidArgumentException("Missing mandatory digital reference attributes.");
        }
        // Insert digital detail record (enforces uq_digital_bank_ref)
        $insDigital->execute([$paymentId, $orderId, $provider, $merchantAccountId, $paymentRef, $providerEventAt, $now]);
    } elseif ($paymentMethod === 'cash') {
        // 2b. Cash Tender: Must NEVER create a row in order_digital_payments
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack(); // Atomic rollback on duplicate bank ref, null ref, or insertion failure
    throw $e;
}
```

#### Provisional KHQR Reference Format Notice:
- The description of bank references as "4 to 8 digits" is **provisional**, based on common counter observation, and is **NOT a confirmed universal bank format**.
- References are **not globally unique**; uniqueness is scoped strictly to `(provider, merchant_account_id, payment_ref)`.
- Official ABA PayWay and NBC Bakong technical documentation must be reviewed before finalizing format specifications, length constraints, character sets, or rollover cycles. No bank specifications may be invented.

---

## Sign-Off & Governance Status

* **Executive Business Decisions:** Confirmed by Business Owner (November 8, 2026)
* **Technical Implementation Plan:** Subject to Separate Executive Authorization
* **58 Test Matrix Specifications:** **Planned specifications only**; remain unexecuted until authorized testing phase.
* **Current Phase Status:** Phase 0 (Read-Only Source, Runtime & Configuration Inspection)
