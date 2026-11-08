# POS Business Rules – Executive Decisions & Technical Design Registry

**Document ID:** POS-OQ-2026-FINAL
**Project:** Car-WashService
**Scope:** Confirmed Executive Decisions, Source Harmonization, Phase 1 Technical Design Specifications, Unresolved Dependencies
**Date:** November 8, 2026 (Updated - Round 8)
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
| **4** | **Historical Orders Reconciliation** | **Preserve Intact + Exceptions Tab** | Original records are preserved untouched (no blanket overwrites). A dedicated **"Historical Unreconciled Exceptions"** tab is added to owner reports for manual review. | **Approved Executive Policy** |
| **5** | **KHQR Verification Channel** | **Store Counter Phone + Soundbox** | POS counter operates 100% on local LAN using store counter phone notifications and soundbox. No public static IP or bank webhook onboarding in initial scope. | **Approved Executive Policy** (Reference format & uniqueness rules remain technical dependencies) |
| **6** | **Financial Reporting Separation** | **Net Settled Revenue $\ne$ Net Operating Profit** | Reports strictly distinguish **Net Settled Revenue** (Cash tender inflow minus settled refunds) from **Net Operating Profit**. Missing COGS flagged, never assumed $0.00. | **Approved Executive Policy** (Fallback queries & timestamp schemas remain technical design items) |

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
|  - UTD-1: Period-aware decoupled historical payment/refund fallback logic    |
|  - UTD-2: Reporting timestamp definitions & schema availability               |
|  - UTD-3: Verification-gated bank reversal resolution (dual-payout safety)    |
|  - UTD-4: Scoped provider/merchant bank reference uniqueness rules            |
+-------------------------------------------------------------------------------+
```

---

### UTD-1: Historical Payment and Refund Reporting Fallback Architecture

#### Problem Statement:
Earlier proposals relied on "all-or-nothing" fallback logic (e.g. if any ledger payment exists for an order, historical fallback is disabled; or if a refund ledger row exists, historical refund fallback is ignored). This produces severe reporting anomalies:
1. An order created before cutover with an initial historical payment of $10 recorded on `orders` and a post-cutover split payment of $15 recorded in `order_payments` would omit the $10 historical payment if fallback is all-or-nothing.
2. A pre-cutover order with an original payment and a post-cutover partial refund would drop the historical payment entirely under coupled fallback logic.
3. In the live source schema ([init.php](database/init.php#L242-L280)), `orders` has `total_usd`, `paid_at`, `payment_status`, and `cancelled_at`, but **does NOT have a `refunded_amount` column**, nor does a historical payment ledger exist.

#### Period-Aware Independent Evaluation Requirements:
Payment recognition and refund recognition must be calculated **independently** and **strictly within the reporting period boundaries `[start_date, end_date)`**:

- **Pre-Cutover Orders (`created_at < cutover_timestamp`):**
  - **Payment Recognition:**
    - If the order has an original historical payment (`payment_status = 'paid'` or `paid_at IS NOT NULL`) and its recognition timestamp (`paid_at`, or `created_at` if `paid_at` is null) falls within `[start_date, end_date)`, it is recognized in that historical period.
    - If a subsequent payment is recorded in `order_payments` after cutover, that payment is recognized in the post-cutover period of its `recorded_at` timestamp.
    - *Anti-Double-Counting Safeguard:* Because historical `orders.total_usd` represents the full order total rather than a discrete deposit ledger, a post-cutover split payment recorded against a pre-cutover order risks double-counting if `total_usd` is also counted.
  - **Refund Recognition:**
    - Any post-cutover refund in `order_refunds` is recognized in the period of its `disbursed_at` (cash) or `settled_at` (digital) timestamp.
    - A post-cutover refund on a pre-cutover order must **never** be subtracted from the pre-cutover period's gross receipts.
    - Multiple partial refunds each appear in their respective disbursement/settlement periods.

#### Conceptual Pseudocode for Period-Aware Recognition:
```
FUNCTION CalculatePeriodFinancials(start_timestamp, end_timestamp, cutover_timestamp):
    // 1. New Ledger Payments within period
    ledger_payments = SUM(order_payments.amount_usd)
                      WHERE recorded_at >= start_timestamp
                        AND recorded_at < end_timestamp

    // 2. Historical Payments within period (Pre-cutover orders only)
    // Note: Evaluated ONLY if reporting period intersects pre-cutover era
    historical_payments = 0
    IF start_timestamp < cutover_timestamp:
        historical_payments = SUM(orders.total_usd)
                              WHERE orders.created_at < cutover_timestamp
                                AND COALESCE(orders.paid_at, orders.created_at) >= start_timestamp
                                AND COALESCE(orders.paid_at, orders.created_at) < MIN(end_timestamp, cutover_timestamp)
                                AND orders.payment_status = 'paid'
                                // Exclude orders that have complete ledger migration
                                AND orders.id NOT IN (SELECT order_id FROM order_payments WHERE is_initial_migration = 1)

    // 3. New Ledger Settled Refunds within period
    settled_refunds = SUM(order_refunds.amount_usd)
                      WHERE ((settlement_status = 'cash_disbursed' AND disbursed_at >= start_timestamp AND disbursed_at < end_timestamp)
                          OR (settlement_status = 'settled' AND settled_at >= start_timestamp AND settled_at < end_timestamp))

    // Gross Retained Revenue for Period
    gross_period_payments = ledger_payments + historical_payments
    net_settled_revenue = gross_period_payments - settled_refunds

    RETURN { gross_payments: gross_period_payments, settled_refunds: settled_refunds, net_revenue: net_settled_revenue }
```

> [!WARNING]
> **Unresolved Technical Design Status (UTD-1):**
> Because the current MariaDB schema lacks `orders.refunded_amount` and lacks a pre-cutover payment ledger, the exact mathematical reconciliation for pre-cutover split payments and historical cancellations cannot be established without a formally defined schema migration specification. This issue is **NOT solved** and must be finalized in Phase 1 before migration scripts are authored.

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

### UTD-3: Verification-Gated Bank Reversal Safety & Dual-Payout Prevention

#### Problem Statement:
Earlier drafts claimed that updating a failed reversal row to `'cancelled'` eliminates all risk of a delayed bank settlement. **This claim was incorrect.**
A local status update in a local MariaDB table has zero control over external banking clearinghouses. If an electronic reversal request was transmitted, the bank may asynchronously process and settle the debit hours later regardless of local POS status.

#### Safe Resolution Workflow Requirements:
1. **Preservation of Failed Attempt:** The original failed reversal record must never be overwritten, deleted, or mutated into a cash record.
2. **Append-Only State Transition History:** Every state change, rejection reason, and retry must be recorded in an append-only audit log (`order_refund_events`).
3. **External Bank Verification Gate:** Before any replacement cash payout can be authorized, the cashier/manager **must verify the definitive bank outcome** (e.g. reviewing the merchant bank statement or bank admin portal to verify that no funds were debited).
4. **Mandatory Blockade if Ambiguous:** If the bank's final outcome cannot be verified or remains pending/ambiguous, **replacement cash payout must remain strictly BLOCKED**.
5. **Manager Authorization & Atomic Concurrency Lock:** Once verified as permanently failed by the bank, a replacement cash refund requires Manager PIN authorization and an atomic conditional lock.
6. **Explicit Traceability Link:** The replacement cash refund row must maintain a foreign key / reference `replaced_refund_id` pointing directly to the original failed reversal row.
7. **No Invented Bank API Behavior:** The local system cannot query bank reversal status automatically on an offline LAN counter. Bank reconciliation relies on verified merchant device statements.

---

### UTD-4: Scoped Provider/Merchant Bank Reference Uniqueness

#### Problem Statement:
Earlier proposals introduced a global uniqueness constraint:
`UNIQUE (payment_method, payment_ref)`
This constraint is fundamentally flawed:
1. Cash transactions do not have a bank transaction reference; imposing uniqueness across `payment_method = 'cash'` causes conflicts on NULL / empty strings across database engines.
2. A 4-to-8 digit bank reference code (e.g. terminal trace or short transfer code) is **not globally unique** across different banks, different merchant accounts, or over extended time horizons.

#### Refined Scoping Specification:
- Digital reference uniqueness must be scoped to the actual payment provider and merchant receiving account:
  `UNIQUE (provider, merchant_account_id, payment_ref)`
- Cash payments must **not** require a digital reference and must not be constrained by bank uniqueness indexes.
- In the interim on a local LAN counter without formal bank API documentation, counter duplication defense relies on:
  1. Cart session `idempotency_key`.
  2. Cashier counter verification and cashier user attribution (`cashier_id`).
  3. Visual reference cross-check on the counter merchant phone.

> [!WARNING]
> **Unresolved Technical Dependency (UTD-4):**
> The exact format, character set, length, and uniqueness guarantees of ABA PayWay and NBC Bakong KHQR reference codes must be verified against official bank documentation or confirmed merchant-bank procedures before final database indexes are created.

---

## Sign-Off & Governance Status

* **Executive Business Decisions:** Confirmed by Business Owner (November 8, 2026)
* **Technical Implementation Plan:** Subject to Separate Executive Authorization
* **58 Test Matrix Specifications:** **Planned specifications only**; remain unexecuted until authorized testing phase.
* **Current Phase Status:** Phase 0 (Read-Only Source, Runtime & Configuration Inspection)
