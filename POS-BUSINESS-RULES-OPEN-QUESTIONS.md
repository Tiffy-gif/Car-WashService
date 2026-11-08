# POS Business Rules – Executive Decisions & Technical Design Registry

**Document ID:** POS-OQ-2026-FINAL  
**Project:** Car-WashService  
**Scope:** Policy Conflicts, Source Code Harmonization, Confirmed Executive Decisions, Phase 1 Technical Design Specifications  
**Date:** November 8, 2026  
**Status:** Executive Decisions Confirmed by Business Owner – Technical Design Registered for Implementation Planning  

---

## Executive Confirmation Summary (November 8, 2026)

On November 8, 2026, the business owner formally reviewed and confirmed all six outstanding business decisions required to finalize the Car-WashService Point-of-Sale (POS) counter workflow and system architecture.

All six business policy items are now **CLOSED**. The confirmed decisions are documented below, including the explicit clarification of the mixed-services sequencing terminology and the shift of remaining engineering edge cases into Phase 1 technical design specifications.

| # | Operational Domain | Confirmed Decision (November 8, 2026) | Operational & Technical Impact |
| :-: | :--- | :--- | :--- |
| **1** | **Mixed Services Sequencing** | **Lift First $\rightarrow$ Wash Second** | Vehicles needing both mechanical lube and wash undergo Bay 4 lift work first, followed by wash/dry, ensuring a clean vehicle at handover. |
| **2** | **Receipt Printing Gate** | **Advisory Printing (Electronic Gate)** | Electronic receipt issuance (`receipt_issued_at`) and confirmed payment (`payment_status = 'paid'`) form the gate pass. Thermal printer paper jams never block service. |
| **3** | **Walk-In Identity & Schema** | **Default to `'Walk-in Guest'`** | Retains existing `customer_name VARCHAR(100) NOT NULL` in MariaDB schema without risky `ALTER TABLE`. Plate and vehicle category captured via existing flow. |
| **4** | **Historical Orders Reconciliation** | **Preserve Intact + Exceptions Tab** | Original records are preserved untouched (no blanket overwrites). A dedicated **"Historical Unreconciled Exceptions"** tab is added to owner reports. |
| **5** | **KHQR Verification Infrastructure** | **Store Counter Phone + Soundbox** | POS counter operates 100% on local LAN without requiring public static IP, external domain, or commercial bank webhook onboarding in initial scope. |
| **6** | **Financial Reporting Separation** | **Net Settled Revenue $\ne$ Net Operating Profit** | Reports strictly distinguish **Net Settled Revenue** (Cash tender inflow minus settled refunds) from **Net Operating Profit**. Missing COGS flagged, never assumed $0.00. |

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
* **Implementation Plan Requirement (Work Package WP-2):**
  Refactor `ensureOrderTasks()` and `assignCarToBay()` in `core/BayManager.php` so that when an order contains both mechanical items (`item_type = 'service'` / mechanical) and wash packages (`item_type = 'package'` / wash):
  - Task 1 (Step 1): Mechanical Lift Service (Bay 4) $\rightarrow$ Initial state: `'waiting'` / active.
  - Task 2 (Step 2): Exterior Wash & Detailing (Bay 1 or 2) $\rightarrow$ Initial state: `'pending_prereq'`.
  - Wash bay reservation for Step 2 is deferred until Step 1 reaches `'completed'`, preventing empty wash bays from sitting idle during long mechanical inspections.

---

### Decision 2: Receipt Printing Service Gate Policy

#### The Decision:
* **Physical thermal paper printing is advisory.**
* The mandatory service admission gate (`core/OrderGate.php`) requires confirmed payment (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at IS NOT NULL`).
* Physical thermal printer failures (paper out, head open, cutter jam, USB disconnect) display an advisory toast on the cashier UI and log an event, but **must never block vehicle admission into the wash apron**.

#### Technical Harmonization:
* Refactor `core/OrderGate.php`:
  ```php
  public static function assertServiceAdmissionAllowed(int $orderId): bool {
      // 1. Payment must be confirmed
      // 2. Electronic receipt must be issued
      // 3. Physical printing is NOT required for service admission
  }
  ```
* Physical receipt reprint remains completely read-only (`GET /api/pos/index.php?action=print_receipt`), performs zero database writes, and marks the slip with `*** DUPLICATE RECEIPT ***`.

---

### Decision 3: Walk-In Customer Identity & Schema Integrity

#### The Decision:
* Preserve database schema integrity without modifying table structures:
  `orders.customer_name VARCHAR(100) NOT NULL` (as defined in `database/init.php:251`).
* When a customer declines to register or provide personal information, the cashier enters only the license plate and vehicle type; the application automatically records `'Walk-in Guest'` as `customer_name`.
* No mandatory customer phone, vehicle VIN, or unapproved database-level constraints are added. Vehicle category selection (`small_car`, `suv_pickup`, `van_truck`, `motorbike`) is captured per existing cashier flow to calculate correct package pricing.

---

### Decision 4: Historical Unreconciled Orders Migration Policy

#### The Decision:
* Original historical records must be preserved intact. No automated script may bulk-overwrite ambiguous records (e.g., historical orders with `status = 'paid'` but `cancelled_at IS NOT NULL`, or orders with `status = 'finished'` and `payment_status = 'unpaid'`).
* Financial reports in `views/owner/reports.php` will incorporate a dedicated **"Historical Unreconciled Exceptions"** review tab.
* This tab isolates pre-cutover tickets that have conflicting operational and financial timestamps for owner inspection and manual write-off authorization, preventing historical test data from distorting active ledger reporting.

---

### Decision 5: KHQR Verification Channel & Local LAN Self-Sufficiency

#### The Decision:
* KHQR payment verification relies on the store's counter merchant device:
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

## Phase 1 Technical Design & Edge-Case Specifications

With executive business policies locked, the following technical edge cases are registered for detailed handling in Phase 1 technical design:

### TD-1: KHQR Positive Matching Protocol (Soundbox Ambiguity Handling)
* **Problem:** During busy rush hours, an IoT soundbox chime announces an amount (*"Received $5.00"*), but does not state the vehicle plate or customer name. If two customers are paying $5.00 simultaneously, audio alone is ambiguous.
* **Technical Design Protocol:**
  1. An audible chime alone is sufficient to alert the cashier, but **in the event of simultaneous payments or unmatchable amounts, the cashier must check the merchant smartphone app**.
  2. The cashier must verify the exact transaction amount and input the genuine 4-to-8 digit bank transaction reference code (`payment_ref`) from the bank notification.
  3. The system enforces a unique constraint `UNIQUE INDEX uq_payment_bank_ref (payment_method, payment_ref)` on KHQR transactions to prevent duplicate usage of the same bank transfer for multiple cars.
  4. The system never generates synthetic references (e.g., `TRX-` via `uniqid()`) to represent bank payments.

### TD-2: Failed Bank Reversal Resolution Protocol (Dual-Payout Prevention)
* **Problem:** If a customer paid via KHQR and requests a refund, but an attempted bank reversal fails (e.g., destination account locked, interbank network timeout), how does the shop refund the customer without risking dual payouts?
* **Technical Design Protocol:**
  1. When a bank reversal fails, the refund status transitions to `'failed'`.
  2. The refund is **never automatically paid out in cash** while a digital reversal is pending or uncancelled.
  3. To resolve a failed bank reversal with physical cash, the manager must explicitly execute a **"Cancel Bank Reversal & Switch to Cash"** action in the POS.
  4. This action atomically marks the digital refund record as `'cancelled'` (`cancelled_reason = 'Reversal failed; manual cash replacement authorized'`) and creates a new linked refund record with `payment_method = 'cash'`, `settlement_status = 'authorized_pending'`, and `replaced_refund_id = [failed_id]`.
  5. The cashier can then disburse cash with full audit logging, eliminating any possibility of both a bank credit and cash payout occurring for the same claim.

### TD-3: Reporting Period Bounding (Future Event Isolation)
* **Problem:** If an order was created in October and refunded in November, running an October revenue report must not subtract the November refund retroactively from October's gross receipts.
* **Technical Design Protocol:**
  1. All ledger subqueries for payments and refunds must strictly enforce `recorded_at <= :end_date` (or `disbursed_at <= :end_date` / `settled_at <= :end_date`).
  2. Event-based reporting places the gross payment in the month it was received and the refund deduction in the month it was disbursed/settled.

### TD-4: Historical Split & Multi-Refund Accounting Integrity
* **Problem:** A pre-cutover historical order may have multiple post-cutover payments or multiple post-cutover partial refunds.
* **Technical Design Protocol:**
  1. The Decoupled Two-Tier reporting query independently evaluates historical fallbacks:
     - Payment Tier: If new records exist in `order_payments` for an order, sum those records; if none exist, fall back to historical `orders.total_price` (if `payment_status = 'paid'`).
     - Refund Tier: If new records exist in `order_refunds`, sum all settled refunds; if none exist, fall back to historical `orders.refunded_amount`.
  2. This guarantees that an order with a pre-cutover payment and two separate post-cutover partial refunds is accurately accounted for without missing payments or double-deducting refunds.

---

## Sign-Off Verification

* **Executive Business Owner:** Confirmed & Approved (November 8, 2026)  
* **Lead Systems Architect:** Documented & Registered for Implementation (November 8, 2026)  
* **Next Implementation Milestone:** Phase 0 (Read-Only Environment & Baseline Revalidation)
