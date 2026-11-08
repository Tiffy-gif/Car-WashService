# Car Wash POS Final Business Rules & Operational Specifications

**Document ID:** POS-BR-2026-FINAL  
**Project:** Car-WashService  
**Scope:** Counter Cashier Workflow, Payment Governance, Refund State Machine, Accounting & Reporting Standards, Queue & Bay Management  
**Date:** November 8, 2026 (Updated with Confirmed Executive Decisions)  
**Status:** Authoritative Specifications – Executive Decisions Confirmed by Business Owner  

---

## Executive Summary

This document defines the authoritative, finalized business rules for the Car-WashService Point-of-Sale (POS) counter system. It integrates the **six business decisions confirmed by the business owner on November 8, 2026**, establishing a simple, high-speed cashier workflow, positive payment verification before service admission ("Payment Before Service"), 100% local area network (LAN) self-sufficiency, and mathematically certified accounting separation between cash revenue and operating profit.

---

## 1. Core Architectural Standards & Workflows

### 1.1 Local Area Network (LAN) Self-Sufficiency Standard
1. **Zero External Cloud Dependencies for Counter Intake:**
   - Counter checkout, catalog pricing, cash tendering, cash drawer kick pulses, ESC/POS 80mm thermal printing, and bay dispatching must operate completely autonomously within the shop's local Wi-Fi / Ethernet LAN.
   - All fonts (`Kantumruy Pro`, `Inter`), typography assets, icon sets (`FontAwesome`), CSS stylesheets, and JavaScript files must be hosted locally on the counter server (`/pos/webfonts/`, `/pos/css/`, `/pos/js/`). External CDNs and cloud endpoints are strictly prohibited during normal intake operations.
2. **Offline Data Storage & Sync:**
   - In the event of LAN router disruption or counter client disconnect, checkout requests are safely staged in browser IndexedDB (`PosStorage`).
   - Upon reconnection, transactions are replayed sequentially using their original client-generated `idempotency_key`.

### 1.2 Single-Page Checkout Interface Layout
The cashier interface operates on a unified single-page layout divided into three distinct operational zones:
- **Zone 1 (Intake & Customer/Vehicle):** Quick search by license plate or phone; walk-in guest intake; vehicle category selection.
- **Zone 2 (Catalog & Selection):** Touch-friendly service packages, mechanical repair tasks, and retail car care products organized in clean category tabs.
- **Zone 3 (Cart Summary, Tender & Dispatch):** Live line-item review, automated dual-currency totals ($ USD and ៛ KHR), manager discount authorization gate, tender selection (Cash vs. KHQR), change calculation, and bay assignment.

---

## 2. Cashier Workflow Rules

### Rule 2.1: Optional Customer Registration & Walk-In Intake
1. **Customer Registration is Strictly Optional:**
   - Cashiers must **never** be forced to open a customer registration modal or require customer name/phone number before starting an order.
   - Walk-in customers who do not wish to provide personal details are processed simply by entering their vehicle license plate number.
2. **Mandatory License Plate Capture:**
   - A valid license plate string (`plate_number`) is mandatory for all vehicle service orders to identify the car on the apron and digital job boards.
   - A vehicle category (`vehicle_type`) must be selected (`small_car`, `suv_pickup`, `van_truck`, `motorbike`) to establish accurate service pricing per vehicle dimensions.
   - No unapproved mandatory fields (such as VIN or customer phone number) may be introduced.
3. **Database Schema Preservation (Owner Decision 3):**
   - The database schema `customer_name VARCHAR(100) NOT NULL` (in `database/init.php:251`) is preserved without high-risk `ALTER TABLE` schema modifications.
   - If customer name is omitted by the cashier, the backend automatically records `customer_name = 'Walk-in Guest'`, satisfying schema constraints while honoring optional registration.
   - If an existing vehicle plate is found in the database, the customer profile is linked automatically without requiring manual re-entry.

### Rule 2.2: Mandatory Upfront Payment ("Payment Before Service")
1. **Strict Upfront Payment Policy:**
   - Every vehicle must have confirmed payment at the counter before being admitted into the service queue or work bay.
   - "Pay Later", "Pay at Exit", and unpaid post-service dispatch are **permanently removed** from the standard cashier workflow.
2. **Service Admission Gate (`core/OrderGate`):**
   - The system strictly enforces `orders.payment_status = 'paid'` as an immutable prerequisite before a vehicle appears on washer/mechanic job boards or can be assigned to a bay.
   - Unpaid orders are blocked with HTTP 422 (`PAYMENT_REQUIRED`).

### Rule 2.3: Idempotency & Duplicate Order Prevention
1. **Cart Idempotency Key:**
   - A unique UUID v4 `idempotency_key` is established when the cart session begins.
   - Rapid double-clicking, browser refreshes, or network retry attempts transmit the identical token.
2. **Backend Duplicate Handling:**
   - If an incoming `idempotency_key` matches an existing order, the backend does **not** insert a second row; it returns the existing order payload with HTTP 200 and `'is_duplicate' => true`.
3. **Button Debounce:**
   - The checkout button immediately disables and displays a spinner upon the first click, preventing accidental duplicate tender submissions.

### Rule 2.4: Printing Independence & Presentation Decoupling (Owner Decision 2)
1. **Physical Printing is Strictly Advisory:**
   - Printing a physical paper receipt is an asynchronous presentation action.
   - If the counter thermal printer runs out of paper, jams, has its print head open, or is powered off, the order is **not rolled back**, payment is **not duplicated**, and the car is **not blocked** from entering the service apron.
2. **Electronic Receipt Service Gate:**
   - Confirmed payment immediately generates an official electronic receipt number (`orders.receipt_number`) and timestamps electronic issuance (`orders.receipt_issued_at = NOW()`).
   - The electronic receipt issuance and confirmed payment constitute the complete service admission pass.
   - The job ticket is transmitted digitally to the apron queue display (`tv.php`) and worker tablets.
3. **Read-Only Reprint Safety:**
   - Clicking "Reprint Receipt" is strictly read-only (`GET /api/pos/index.php?action=print_receipt`). It executes zero database mutations and prints with a clear `*** DUPLICATE RECEIPT ***` header watermark.

---

## 3. Payment Governance & Verification Standards

### Rule 3.1: Cash Tender & Dual-Currency Change Calculation
1. **Sufficient Cash Tender Requirement:**
   - Cash payment is confirmed only after the cashier counts and enters physical currency equal to or greater than the order total.
   - The POS interface provides "Exact Cash" and Quick-Tender denominations ($5, $10, $20, $50, $100 and equivalent KHR).
2. **Accurate Dual-Currency Change Display:**
   - The system automatically calculates change due in both currencies:
     $$\text{Change USD} = \text{Tendered USD} - \text{Total USD}$$
     $$\text{Change KHR} = \text{Change USD} \times \text{Exchange Rate}$$
   - Change is displayed in high-contrast, large-font typography (minimum 24pt bold green) to eliminate cashier calculation errors.
3. **Cash Drawer Pulse:**
   - Upon cash confirmation, an ESC/POS hardware kick pulse (`ESC p 0 25 250`) is dispatched to open the counter cash drawer.

### Rule 3.2: Anti-Fraud KHQR & Digital Bank Verification (Owner Decision 5)
1. **Positive Verification Requirement:**
   - A customer's smartphone screen showing a "successful transfer" screenshot is **strictly rejected as proof of payment**. Screenshots can be doctored, reused from previous visits, or cancelled before broadcast.
   - Dynamic QR generation is **not proof of payment**; it is merely an invitation to pay.
   - Blind cashier button clicks without positive evidence are strictly prohibited.
2. **Approved Verification Channels (Local LAN Scope):**
   Payment is confirmed ONLY when authentic evidence arrives on the business's own merchant devices:
   - **Channel A (Store Counter Phone App / Push):** Official bank mobile push notification or SMS alert displaying matching amount, currency, and customer name.
   - **Channel B (IoT Merchant Soundbox):** Counter soundbox chimes audible confirmation: *"Received [Amount] via KHQR"*.
   - *Soundbox Ambiguity Safeguard:* An audible chime alone alerts the cashier, but if multiple customers are paying simultaneously or amounts are identical, the soundbox chime alone is insufficient; the cashier **must match the transaction on the counter merchant phone**.
3. **Genuine Bank Transaction References (No Synthetic TRX):**
   - The backend must **never** generate fake reference strings (e.g., `TRX-` via `uniqid()`) to represent bank transactions.
   - The cashier must capture the genuine 4-to-8 digit bank transaction reference code (`payment_ref`) from the merchant bank notification.
   - Database enforces duplicate reference prevention: `UNIQUE INDEX uq_payment_bank_ref (payment_method, payment_ref)`.
4. **Unconfirmed Transfers Hold Protocol:**
   - If a customer claims payment was sent but no merchant alert arrives within 60 seconds (interbank latency), the vehicle is **held at the counter apron**. The car is never dispatched to a wash bay until funds are confirmed.

### Rule 3.3: Dual-Currency Accounting & Frozen Exchange Rates
1. **Discrete Currency Staging:**
   - All transactions store both `amount_usd` and `amount_khr`.
   - The exchange rate active at the exact moment of checkout is permanently frozen in the transaction row (`order_payments.exchange_rate`).
2. **Immunity to Rate Drift:**
   - Financial reports never recalculate historical transactions using live exchange rates. Historical revenue remains fixed and auditable.

### Rule 3.4: Discount & Price Override Governance
1. **Existing Permission Enforcement:**
   - Discounts directly reduce revenue and must strictly adhere to the existing permission `pos.discount`.
   - Cashiers lacking `pos.discount` must prompt for an authorized Manager PIN inline.
2. **Anti-Self-Approval:**
   - A manager acting as the counter cashier cannot enter their own PIN to approve discounts on their own checkout (`manager_user_id != cashier_id`).
3. **Attribution & Audit Logging:**
   - Every discount records `discount_usd`, `discount_approved_by`, and a mandatory reason (`Promotion`, `Loyalty Reward`, `Service Recovery`).

---

## 4. Refund Lifecycle & Concurrency Integrity

### Rule 4.1: Discrete Refund State Machine
Refunds are governed by a finite state machine ensuring strict separation between administrative approval, physical currency disbursement, and bank reconciliation:

```mermaid
stateDiagram-v2
    [*] --> authorized_pending: Manager PIN Authorized

    authorized_pending --> cash_disbursed: Cashier Pays Cash (disbursed_at)
    authorized_pending --> bank_reversal_pending: Reversal Sent to Bank
    authorized_pending --> cancelled: Cancelled Prior to Payout

    bank_reversal_pending --> settled: Bank Statement/Webhook Confirmed (settled_at)
    bank_reversal_pending --> failed: Bank Rejection / Timeout

    failed --> bank_reversal_pending: Manager Retries Reversal (retry_count++)
    failed --> cancelled: Manager Cancels Failed Reversal (Allows Cash Resolution)

    cash_disbursed --> [*]: Terminal (Cash Drawer Reduced)
    settled --> [*]: Terminal (Bank Account Reduced)
    cancelled --> [*]: Terminal (Zero Financial Impact)
```

### Rule 4.2: Permitted & Terminal Transitions
1. **Permitted Status Transitions:**
   - `authorized_pending` $\rightarrow$ `cash_disbursed`: Cashier hands cash from till to customer.
   - `authorized_pending` $\rightarrow$ `bank_reversal_pending`: POS registers digital reversal request.
   - `authorized_pending` $\rightarrow$ `cancelled`: Void aborted before currency leaves the till.
   - `bank_reversal_pending` $\rightarrow$ `settled`: Confirmed credit reversal from bank statement/reconciliation.
   - `bank_reversal_pending` $\rightarrow$ `failed`: Bank rejects reversal (account closed, invalid card, timeout).
   - `failed` $\rightarrow$ `bank_reversal_pending`: Manager retries with incremented `retry_count`.
   - `failed` $\rightarrow$ `cancelled`: Manager aborts digital attempt to allow alternate resolution.
2. **Terminal States (Strictly Irreversible):**
   - `cash_disbursed`: Terminal. Banknotes handed to customer cannot be digitally retracted.
   - `settled`: Terminal. Bank credit cannot be reversed inside the POS.
   - `cancelled`: Terminal. Once voided, a refund cannot be revived; a new refund must be created.
   - Skipping intermediate verification (`authorized_pending` $\rightarrow$ `settled`) is strictly forbidden.

### Rule 4.3: Concurrency Protection & Failed Reversal Protocol
1. **Atomic Conditional Lock for Cash Disbursement:**
   ```sql
   UPDATE order_refunds 
   SET settlement_status = 'cash_disbursed', disbursed_at = NOW() 
   WHERE id = :refund_id AND settlement_status = 'authorized_pending';
   ```
   - If `rowCount === 0`, execution is aborted with HTTP 409 Conflict (`REFUND_ALREADY_DISBURSED_OR_CANCELLED`).
   - The hardware drawer kick pulse fires **only** if `rowCount === 1`. Rapid double-tapping cannot dispense money twice.
2. **Failed Bank Reversal Resolution (Dual-Payout Prevention):**
   - If a bank reversal fails (`settlement_status = 'failed'`), the system **never automatically pays cash**.
   - To refund in cash after a failed digital reversal, the manager must explicitly execute **"Cancel Reversal & Switch to Cash"**.
   - This atomically cancels the digital refund row (`settlement_status = 'cancelled'`, `cancelled_reason = 'Reversal failed; manual cash replacement authorized'`) and generates a new cash refund row in `authorized_pending`.
   - This eliminates any risk of a customer receiving both a delayed bank reversal and cash from the drawer.

### Rule 4.4: Cumulative Refund Ceiling & Multi-Refunds
1. **Payment Balance Ceiling:**
   - The sum of all non-cancelled refunds on an order must never exceed the total confirmed payments collected on that order:
     $$\sum \text{Refunds}_{\text{non-cancelled}} + \text{New Refund} \le \text{Total Payments Collected}$$
   - Any attempt to disburse more than the original payment balance is rejected with HTTP 422 (`REFUND_EXCEEDS_PAYMENT_BALANCE`).
2. **Multiple Partial Refunds:**
   - An order supports multiple partial refunds (e.g., cancelling an unused detailing upgrade while retaining basic wash), each maintaining its own discrete ledger record and sequence number (`refund_seq = 1, 2, ...`).

---

## 5. Revenue, Reporting & Accounting Definitions

### Rule 5.1: Strict Financial Terminology Standards (Owner Decision 6)
To prevent executive misinterpretation, financial reports must strictly differentiate cash flow metrics from accounting profitability:
1. **Gross Confirmed Payments:** Total monetary inflow collected across all confirmed tenders ($ USD equivalent) during the reporting window.
2. **Settled Refunds:** Total money physically returned to customers (cash disbursed from drawer or cleared bank debit credits). **Only settled refunds reduce gross confirmed payments.**
3. **Pending Refund Liabilities:** Administratively authorized refunds awaiting physical cash disbursement or bank gateway clearing. **Reported as an informational liability; never subtracted from settled revenue.**
4. **Net Settled Revenue:** Gross Confirmed Payments minus Settled Refunds. Represents authentic net cash/bank inflow retained in the business.
5. **Net Operating Profit:**
   $$\text{Net Operating Profit} = \text{Net Settled Revenue} - (\text{COGS} + \text{Washer Commissions} + \text{Mechanic Commissions} + \text{Logged Expenses})$$
6. **Untracked Cost Safeguard (Missing COGS Rule):**
   - If services or parts lack cost records in the database, Net Operating Profit **must not assume $0.00 COGS**.
   - The report must flag an **"Untracked Cost Alert"** indicating the number of line items (`untracked_cost_count`) and volume of sales lacking COGS data.

### Rule 5.2: Accounting Treatment Table
| Refund Lifecycle State | Gross Payments Collected | Settled Refunds | Counter Cash Drawer Till | Net Settled Revenue | Pending Liabilities | Net Realizable Revenue |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| `authorized_pending` | Unchanged | **$0.00** | **Unchanged** | **Unchanged** | **+$Amount** | **-$Amount** |
| `cash_disbursed` | Unchanged | **+$Amount** | **-$Amount** | **-$Amount** | $0.00 | **-$Amount** |
| `bank_reversal_pending` | Unchanged | **$0.00** | **Unchanged** | **Unchanged** | **+$Amount** | **-$Amount** |
| `settled` | Unchanged | **+$Amount** | **Unchanged** | **-$Amount** | $0.00 | **-$Amount** |
| `failed` | Unchanged | **$0.00** | **Unchanged** | **Unchanged** | **$0.00** (Alert) | Unchanged |
| `cancelled` | Unchanged | **$0.00** | **Unchanged** | **Unchanged** | **$0.00** | Unchanged |

### Rule 5.3: Decoupled Two-Tier Reporting & Reporting Period Bounding
1. **Decoupled Fallback Formulation:**
   - Historical orders created prior to cutover (`created_at < :cutover_ts`) must decouple payment fallback from refund fallback.
   - The presence of a post-cutover refund in `order_refunds` must **never** cause Tier 2 to omit the original historical payment.
2. **Reporting Period Bounding (Future Event Isolation):**
   - Subqueries offsetting historical sums must restrict ledger lookups to `recorded_at <= :end_date` (and `disbursed_at <= :end_date` / `settled_at <= :end_date`).
   - Transactions occurring in subsequent months (e.g. November) must **never** be subtracted from past reporting periods (e.g. October).
3. **Historical Unreconciled Exceptions Tab (Owner Decision 4):**
   - Historical records with contradictory or missing timestamps are preserved untouched.
   - An informational **"Historical Unreconciled Exceptions"** tab in `views/owner/reports.php` provides executive visibility into test and pre-cutover anomalies without distorting active financial ledgers.

---

## 6. Service Admission & OrderGate Policy

### Rule 6.1: Service Gate Resolution (Owner Decision 2)
1. **Financial Hard Gate ("Payment Before Service"):**
   - Service admission is strictly gated on confirmed payment (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at IS NOT NULL`).
2. **Physical Printing Advisory Standard (Default):**
   - Physical paper deposition is decoupled. Hardware printer failure displays an advisory warning on the POS counter screen but **does not block** apron technicians from starting work.
3. **Optional Hard Paper Gate Setting:**
   - For facilities employing dedicated gate security personnel who physically inspect paper slips, an explicit configuration toggle `require_receipt_before_wash` is maintained in `settings`.
   - If enabled by management, physical paper printing remains a mandatory hard block (with manager PIN override). If disabled (recommended default), the electronic receipt acts as the definitive admission ticket.

---

## 7. Bay Assignment & Queue Management

### Rule 7.1: Atomic Bay Reservation & State Checking
1. **No Presumption of Availability:**
   - A bay is never assumed to be free based on cached client state.
   - Assignment executes an atomic conditional lock:
     ```sql
     UPDATE bays 
     SET status = 'busy', current_order_id = ?, occupied_at = NOW() 
     WHERE id = ? AND status = 'free' AND is_active = 1;
     ```
   - If `rowCount === 0`, the vehicle is redirected to the Priority Queue buffer without data corruption.
2. **Intake Hold TTL & Occupied Bay Safety:**
   - Cashier intake holds on bays expire strictly at 180 seconds (3 minutes).
   - The expiry cleanup sweep verifies `physical_occupancy = 'vacant'` and `current_order_id IS NULL`. A bay actively occupied by a vehicle is **never auto-released by a timer**.

### Rule 7.2: Multi-Stage Bay Dispatch Sequence (Owner Decision 1)
1. **Mixed Service Order Sequencing (Lift First $\rightarrow$ Wash Second):**
   - For orders containing both mechanical lube work and exterior washing, the physical routing sequence is standardized:
     **Step 1: Mechanical Lift (Bay 4) $\rightarrow$ Step 2: Wash & Dry (Bay 1 or 2)**.
   - Hot fluid drainage, oil filter changes, and dirty undercarriage repairs finish first. The car receives a complete exterior wash, hand wipe-down, and tire shine as the final step before customer handover.
2. **Staged Reservation Rule:**
   - Stage 1 (Mechanical Lift) is claimed at checkout.
   - Stage 2 (Wash Bay) is **never pre-locked** at checkout, preventing empty wash bays from sitting idle while cars undergo 45-minute mechanical services. Stage 2 is automatically queued at the head of the wash queue upon Stage 1 completion.
