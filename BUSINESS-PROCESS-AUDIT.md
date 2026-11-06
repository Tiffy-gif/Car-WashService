# Car Wash Management System – Business Process Audit & Operational Assessment

**Document ID:** AUD-OPS-2026-001  
**Project:** Car-WashService  
**Audit Scope:** Operational Workflow, Real-World Business Alignment, Code-to-Documentation Truth Verification  
**Audit Type:** Read-Only Technical & Operational Audit  
**Date:** November 2026  
**Status:** Complete – Awaiting Boss / Management Review  

---

## Executive Summary

An exhaustive audit of the `Car-WashService` software repository against real-world car wash, auto-detailing, and quick-lube operational practices was conducted. The objective was to determine whether the current software architecture and operational workflows support a practical, efficient, high-volume, and professional business.

### Key Audit Finding
While the software contains sophisticated individual technical modules—such as an idempotent POS API (`api/pos/index.php`), smart queue scoring (`core/SmartQueueEngine.php`), multi-lane bay transitions (`core/BayManager.php`), and ledger-backed stock tracking (`core/StockControl.php`)—**the operational business process is disjointed, contains critical financial blind spots, and fails to match the physical reality of running a busy car wash and service center.**

### Top Operational Vulnerabilities Identified
1. **The "Unbilled Parts" Financial Leak:** When technicians request parts or oil during a job, `TechnicalManager::approvePartsRequest()` deducts physical inventory and inserts the item into `order_items`, but **never updates `orders.subtotal_usd`, `orders.total_usd`, or `orders.total_khr`**. Customers are handed receipts that omit the approved parts, leaking inventory for zero revenue.
2. **The "Status vs. Payment" Reporting Blindspot:** In `views/owner/reports.php`, revenue queries strictly filter by `WHERE o.status = 'paid'`. If an order is finished operationally (`status = 'finished'`) and paid (`payment_status = 'paid'`), its revenue is completely excluded from management reports.
3. **Rigid "No Receipt, No Wash" Upfront Payment Failure:** Enforcing full upfront payment before any service starts paralyzes oil changes, mechanical repairs, and detailing, where actual oil volume, worn parts, or paint decontamination cannot be diagnosed until the car is on the lift or in the wash bay.
4. **Zero Intake Vehicle Accountability:** No mechanism exists to document pre-existing vehicle damage (scratches, dents, broken mirrors, dashboard error lights) upon arrival, leaving the shop exposed to false customer damage claims.
5. **Ghost Quality Control (Documentation vs. Code Discrepancy):** Existing documentation asserts a "4-point Guided Inspection Checklist on tablet for washers," yet `views/washer/jobs.php` contains **zero checklist code**. Washers click a single button to finish the car, grading their own work without supervisor sign-off.
6. **Missing Vehicle Handover & Key Control:** The system has no key tag tracking, bay exit verification, or digital gate pass. Keys are handed over informally, creating risk of vehicle theft, unauthorized release, or unpaid drive-offs.

---

## Phase 1: Code-to-Documentation Operational Audit

This section compares the claimed features in existing documentation (`docs/user_guide/04_CUSTOMER_JOURNEY.md`, `docs/BRIEF_COMPLIANCE.md`, etc.) with the actual implementation in code.

| Operational Area | Documented Workflow | Actual Code Implementation | Operational Reality & Gap |
| :--- | :--- | :--- | :--- |
| **1. Customer Registration & Vehicle Intake** | Fast plate search (`F2`), camera plate scanning, auto-populates loyalty tier, phone, model, and mileage reminder. | `views/cashier/pos.php` lines 550–650 & `api/pos/index.php` lines 1353–1424. Plate lookup works, but plate camera scanning is only a client placeholder. Phone numbers and customer names default to "Walk-in Guest" if omitted. | **High friction:** Cashier must type plate, select vehicle category, and guess vehicle type. No physical intake lane triage; customer must wait at cashier desk while vehicle idles outside. |
| **2. Vehicle Damage & Pre-Existing Condition Inspection** | Implied standard check upon vehicle arrival. | **Completely Absent.** Database tables `orders`, `vehicles`, and `customers` contain no columns for pre-existing scratch photos, fuel level, or odometer intake photos. | **Critical Risk:** If a customer claims a pre-existing bumper scratch or cracked windscreen occurred in the wash bay, the business has zero proof of prior condition. |
| **3. POS & Order Creation** | Unified order flow handling wash, mixed, and oil jobs with instant upsell alerts. | Two conflicting implementations:<br>1) Web POS (`views/cashier/pos.php` lines 236–250) creates orders with `CW-YYYYMMDD-###`, unpaid.<br>2) API POS (`api/pos/index.php` lines 2060–2095) creates orders with `ORD-YYMMDD-###`, allowing paid upfront. | **Architectural Split:** Inconsistent numbering formats, differing stock reservation vs. deduction logic, and confusion about whether payment happens during order intake or at exit. |
| **4. Payment & Receipt Processing** | Cash, ABA Pay, KHQR scan. "No Receipt, No Wash" rule. Mandatory bank slip photo. | `api/pos/index.php` lines 2027–2058. Bank slip photo upload is mandatory for ABA/KHQR (`BankTransferControl::processSlipUpload`). | **Operational Chokepoint:** Taking webcam/tablet photos of every customer bank screen causes severe apron gridlock during peak hours (5:00 PM – 7:00 PM). Cashier cannot process split payments (e.g. $5 cash + $5 ABA). |
| **5. Queue & Bay Assignment** | SmartQueueEngine dynamically scores bays and auto-assigns vehicles based on worker speed and vehicle multiplier. | `core/SmartQueueEngine.php` lines 120–280 & `core/BayManager.php` lines 500–620. Calculates wait scores, but bay assignment on the ground is manual drag/drop or button click. | **Workplace Friction:** Washers on `views/washer/jobs.php` can self-assign cars to their bay, leading to cherry-picking small, clean sedans over muddy trucks. |
| **6. Washer Workflow** | Washer receives car, washes, conducts 4-point checklist (wheels, windows, dashboard, jambs), and finishes. | `views/washer/jobs.php` lines 66–78. Washer clicks `action=finish_car`. **No checklist exists.** No photos, no checklist items, no supervisor verification. | **False Documentation:** Washers grade their own homework. Poor wash quality is caught by the angry customer rather than an internal supervisor. |
| **7. Mechanic & Oil-Change Workflow** | Wash -> Handshake -> Tech Bay -> Oil drain -> Mileage update -> Guided checklist -> Finish. | `core/BayManager.php` lines 502–510 (order tasks split) and `core/TechnicalManager.php` lines 110–160 (guided inspection). | **Workflow Disconnect:** While the multi-task split works in code, it assumes linear wash-first sequence. A customer wanting oil change first or oil change only is delayed if routed through wash bays. |
| **8. Parts Requests & Stock Control** | Mechanic requests parts; warehouse approves; stock decrements; added to customer bill. | `core/TechnicalManager.php` lines 523–612 (`approvePartsRequest`). Approval UI is only accessible to Owner in `views/owner/oil_care.php`. | **Financial Disaster:** When parts are approved, item is inserted into `order_items`, but `orders.total_usd` is **not recalculated**. Stock is depleted, but customer is billed $0.00 for the part. |
| **9. Quality Control (QC)** | Documented supervisor review before vehicle release. | Handled via single status transition `finish_car`. No dedicated QC role or inspection sign-off. | **Operational Blindspot:** Zero accountability for missed cleaning spots, wet floor mats, or oily steering wheel prints. |
| **10. Vehicle Pickup & Handover** | TV calls customer; customer retrieves keys from counter. | TV display (`tv.php`) shows "Ready for Pick-up". No key box management, no gate pass, no customer signature. | **Security Risk:** Anyone can walk up to the bay or counter, claim they own plate `2A-1234`, and drive off. No receipt verification at the exit gate. |
| **11. Cashier Shifts & Daily Close** | Shift start/end, dual-PIN handover, spot audits, Z-Report cutoff at 11:59 PM. | `core/CashierControl.php` lines 270–525, 779–940. Implemented thoroughly with immutable audit logs. | **Rigid Locking:** Hard sales cutoff prevents late-night emergency repairs or washes past midnight unless manager manually overrides. |
| **12. Refunds & Order Cancellations** | Strictly requires Manager PIN; offline refunds blocked; loyalty and commissions reversed. | `views/orders/index.php` lines 372–495 & `core/CashierControl.php` lines 666–740. Strict enforcement. | **Customer Hostility:** If a customer insists on leaving because the queue is 60 minutes long and internet is momentarily down, staff are physically blocked by software from issuing a refund. |
| **13. Reports & Management Controls** | Executive overview of revenue, commissions, expenses, and net profit. | `views/owner/reports.php` lines 67–85. Filters strictly `WHERE o.status = 'paid'`. | **Distorted Analytics:** Orders completed operationally with `status = 'finished'` and `payment_status = 'paid'` are completely omitted from revenue metrics. Net profit calculations omit shop expenses when filtering by terminal. |

---

## Phase 2: Analysis of 12 Real Business Problems

### 1. Unnecessary Steps That Slow Down Staff
* **Identified In Code:** `api/pos/index.php` lines 2028–2058 (`getSlipFileFromInput` & `BankTransferControl::processSlipUpload`).
* **The Operational Bottleneck:** The cashier must capture, convert to base64, and upload a photo of the bank transfer slip for *every single* ABA/KHQR transaction before an order can be created or marked paid.
* **Real Business Example:** On Saturday at 11:30 AM, 6 cars arrive within 5 minutes. The customer pays $8.00 via ABA KHQR. The cashier holds an iPad, tries to align the camera with the customer's phone screen through the driver's side window, struggles with screen glare, and waits 6 seconds for the image to upload over local Wi-Fi. The cars behind start honking, blocking the avenue outside. Meanwhile, the shop's ABA Merchant App soundbox already announced "Received $8.00."

### 2. Missing Operational Steps
* **Identified In Code:** Complete absence of an intake inspection or vehicle condition log in `orders` schema (`database/init.php` lines 242–282).
* **The Operational Bottleneck:** The vehicle goes straight from arrival into the washing bay without documenting pre-existing body conditions.
* **Real Business Example:** A customer brings a Lexus RX350 with a preexisting deep key scratch on the lower left rear door covered in road mud. After the high-pressure foam wash removes the mud, the scratch becomes clearly visible. At pickup, the customer accuses the wash crew of scratching the door with a dropped brush and demands a $250 repaint compensation. Because staff performed no intake inspection or walk-around photos, the owner has to pay or lose a loyal customer.

### 3. Confusing Responsibilities Between Employees
* **Identified In Code:** Both washers (`views/washer/jobs.php` line 39) and supervisors (`views/owner/bays_queue.php`) have active buttons to assign cars to bays and mark cars finished.
* **The Operational Bottleneck:** Lack of clear separation between execution (washer) and supervision/quality control (supervisor).
* **Real Business Example:** Washer Rathana opens his tablet, sees a small clean Toyota Prius (quick $3 commission) and a mud-caked Ford Ranger Raptor (exhausting 45-minute scrub). Rathana self-assigns the Prius to his bay. The Raptor sits in the queue for 40 minutes because no washer wants to claim it. When the Raptor customer complains, the cashier blames the supervisor, the supervisor blames the washers, and Rathana claims "the system let me click it."

### 4. Duplicate Data Entry
* **Identified In Code:** Mileage tracking in `views/cashier/pos.php` (line 72) vs `views/mechanic/jobs.php` (line 94).
* **The Operational Bottleneck:** The cashier asks the customer for odometer mileage at the POS desk before the car enters the bay. If the customer does not know or guesses wrong, the mechanic has to read it from the cluster and re-enter it, or worse, fails to update it.
* **Real Business Example:** The cashier asks a driver for their mileage. The driver guesses "around 85,000 km." The cashier types 85,000. In reality, the odometer is 94,210 km. The mechanic drains the oil, checks the oil filter, but never checks the POS screen. The sticker printed on the windshield and the automated WhatsApp reminder calculate the next due date based on 95,000 km instead of 104,210 km, causing the customer to return 8,000 km too early or suspect fraudulent service intervals.

### 5. Weak Customer Experience
* **Identified In Code:** POS upfront checkout requirement (`api/pos/index.php` line 1660) paired with generic TV screen (`tv.php`).
* **The Operational Bottleneck:** Customers are forced to decide on all services, pay immediately at the front gate in the sun, and then wait in an air-conditioned lounge with no individualized status updates other than watching a noisy public TV screen.
* **Real Business Example:** A customer drops off their car for detailing and an oil change, wanting to walk to a nearby cafe. The cashier insists they must pay $65 upfront before any work begins. While at the cafe, the mechanic notices the engine air filter is completely clogged with rat droppings and needs replacement ($15). There is no automated customer approval link. The mechanic stops working, the car sits idle for 1 hour, and the customer returns expecting a finished car, only to find it half-done.

### 6. Poor Queue Management
* **Identified In Code:** `core/SmartQueueEngine.php` line 247 enforces an aggressive penalty: arriving > 10 minutes late demotes an appointment from `P0` (VIP) to `P3` (Walk-in).
* **The Operational Bottleneck:** The algorithm fails to account for real-world traffic conditions in Southeast Asian metro areas (e.g. Phnom Penh peak traffic).
* **Real Business Example:** A high-value customer with a Lexus LX600 books a $120 ceramic coating maintenance appointment for 2:00 PM. Due to heavy rain and traffic on Monivong Blvd, they arrive at 2:12 PM. The software automatically strips their VIP status and relegates them behind 4 basic $4 motorcycle and tuk-tuk washes. The customer is told they must wait 55 minutes, cancels in disgust, and posts a 1-star Google review.

### 7. Payment and Cashier-Control Weaknesses
* **Identified In Code:** `orders.status` vs `orders.payment_status` duality, and lack of partial deposit support.
* **The Operational Bottleneck:** The database `orders` table only supports binary payment states (`unpaid`, `paid`, `cancelled`). It has no columns for `deposit_amount_usd`, `balance_due_usd`, or multi-tender split payments (e.g. paying $10 cash + $12 ABA on a $22 bill).
* **Real Business Example:** An engine overhaul or paint correction job costs $350. The customer wants to pay a $100 cash deposit upon drop-off and the remaining $250 via ABA KHQR upon pickup. The cashier cannot enter this into the POS. The cashier either leaves the entire order as "unpaid" (putting $100 cash in their personal drawer untracked) or marks it "paid" (leaving the business with zero leverage if the customer disputes the remaining $250 upon delivery).

### 8. Inventory and Material-Control Problems
* **Identified In Code:** `core/TechnicalManager.php` lines 587–591:
```php
$pdo->prepare("
    INSERT INTO order_items (order_id, product_id, service_name, item_type, qty, price_usd)
    VALUES (?, ?, ?, 'spare_part', ?, ?)
")->execute([$orderId, $prodId, $prod['name_en'], $qty, (float)$prod['price_usd'] * $qty]);
```
* **The Operational Bottleneck:** Approved parts are added to `order_items`, but `orders.subtotal_usd`, `orders.total_usd`, and `orders.total_khr` are **never recalculated**. Furthermore, bulk chemicals (car shampoo, wheel acid, tire shine wax) have zero consumption tracking per vehicle.
* **Real Business Example:** A mechanic requests a $45 transmission fluid top-up and a $12 cabin filter for Order #CW-20261105-014. The owner approves it on the phone. The stock room hands over the fluid and filter, and stock ledger logs "-1 bottle". But because the order total in `orders` was set at check-in ($25 wash), the cashier prints the final receipt for $25. The customer pays $25 and leaves. The business loses $57 of inventory and gross margin with zero audit red flags until month-end physical stock count.

### 9. Missing Vehicle Accountability
* **Identified In Code:** No vehicle key tag or parking stall tracking in `orders` or `bays` tables.
* **The Operational Bottleneck:** Physical keys are tossed into an open bowl or left on the vehicle dashboard.
* **Real Business Example:** After washing, washer Sophea parks a customer’s Camry in the finishing lot outside. He leaves the key on the driver seat with the window down. A sudden monsoon rainstorm starts 15 minutes later. Water drenches the electric window controls and leather upholstery. When the customer arrives, the interior is ruined. Nobody knows who parked the car there or who had custody of the key.

### 10. Missing Quality-Control Procedures
* **Identified In Code:** `views/washer/jobs.php` line 69 allows any washer to mark an order finished with no inspection gate.
* **The Operational Bottleneck:** The worker who rushed the wash has absolute power to declare it complete.
* **Real Business Example:** During a busy Sunday lunch rush, washers are incentivized by commission volume ($0.75 per car). To maximize daily payout, washer Bopha skips wiping the rear tailgate, leaves greasy tire spray on the white rear bumper, and does not vacuum under the front passenger floormat. He taps "Finish Car" and grabs the next ticket. The customer walks up, finds dirty fingerprints on the door handles, refuses to accept the car, and demands a full refund.

### 11. Inconsistent Reports and Business Status Definitions
* **Identified In Code:** `views/owner/reports.php` line 75:
```sql
SELECT COUNT(*) as total_vehicles, COALESCE(SUM(o.total_usd), 0) as gross_revenue 
FROM orders o 
WHERE o.status = 'paid' ...
```
* **The Operational Bottleneck:** Confusing operational state (`finished`) with transactional state (`paid`).
* **Real Business Example:** In the POS flow, an order paid after the wash has its `orders.status` set to `'finished'` and `orders.payment_status` set to `'paid'`. At the end of the day, the cash register drawer physically contains $1,200, but the Owner Dashboard Report shows "Gross Revenue: $780". The owner suspects the cashier of stealing $420, when in fact $420 of legitimate revenue was simply excluded by the SQL query `WHERE o.status = 'paid'`.

### 12. Processes That Fail When Internet Is Unavailable
* **Identified In Code:** `core/CashierControl.php` line 673 (`CashierControl::processRefund` throws `OFFLINE_REFUND_BLOCKED`).
* **The Operational Bottleneck:** Rigid software design completely disables core financial resolution features when the cloud link or internet provider hiccups, despite local MariaDB running perfectly on a Mini PC.
* **Real Business Example:** The shop’s fiber line drops due to road construction down the street. A customer whose car was improperly washed demands their money back. The cashier tries to click Refund, but the system displays a hard red modal: `Offline refunds are strictly prohibited. Use paper logbook`. The cashier has no physical paper logbook at hand, refuses to refund cash without a computer printout, and an angry shouting match erupts in front of other waiting customers.

---

## Phase 5: Management & Business Controls Audit

### Current vs. Recommended Business KPIs

| Control Area | Current System Metric | Practical Recommended KPI | Business Purpose & Fraud Protection |
| :--- | :--- | :--- | :--- |
| **Cash Drawer Integrity** | End-of-day expected vs counted cash (`views/cashier/shift.php`). | **Unannounced Mid-Shift Spot Check Variance ($ and %)** | Prevents cashiers from "borrowing" drawer float during the day and replacing it right before closing. |
| **Discount Leakage** | Total discount dollar sum (`reports.php`). | **Discount Rate by Cashier ID (% of Gross Sales)** | Identifies cashiers using manager override PINs excessively to give unauthorized discounts to friends. |
| **Wash Speed & Bay Turn** | Total wash time in minutes (`bay_history`). | **Takt Time & Bay Utilization Rate (Active Wash Mins / Total Shift Mins)** | Pinpoints bay bottlenecks, staff idling, and unrealistic queue wait predictions. |
| **Rework / Wash Rejection Rate** | Not tracked. | **QC Failure & Customer Rework Rate (%)** | Tracks how many vehicles fail initial supervisor inspection or require re-wash before customer release. |
| **Chemical Consumption Efficiency** | Discrete retail bottle count only. | **Chemical Cost Per Vehicle Washed ($/Vehicle)** | Detects shampoo dilution fraud, chemical spillage, or staff theft of commercial detailing supplies. |
| **Upsell Conversion Rate** | Upsell audit logs (`api/pos/index.php`). | **Upsell Acceptance Rate by Cashier / Host (%)** | Measures staff sales effectiveness and incentives commission payouts accurately. |
| **Vehicle Handover Turnaround** | `finished_at` timestamp. | **Dwell Time in Ready Stall (Minutes from Ready to Customer Exit)** | Measures lounge notification speed and prevents yard congestion. |

---

## Conclusion of Audit

The existing system provides a solid structural foundation, but operating it as-is in a high-volume shop creates **unacceptable financial risk (unbilled parts), legal vulnerability (lack of intake damage checks), staff frustration (uploading slip photos for every customer), and misleading executive reports.**

Immediate operational redesign is required to transform the codebase into a production-grade workflow. Detailed target workflows, responsibility matrices, risk registers, and business decisions are provided in the companion deliverables.
