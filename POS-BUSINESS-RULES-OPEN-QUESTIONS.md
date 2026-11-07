# POS Business Rules – Open Questions & Executive Decision Registry

**Document ID:** POS-OQ-2026-FINAL  
**Project:** Car-WashService  
**Scope:** Policy Conflicts, Source Code Contradictions, Missing Information, Executive Sign-Off Items  
**Date:** November 7, 2026  
**Status:** Awaiting Business Owner Confirmation Before Implementation  

---

## Executive Overview

While the core POS cashier workflow, payment security, and financial accounting formulations are fully stabilized, **six operational and technical policy decisions** require explicit confirmation from the business owner (the boss) before implementation begins.

Proceeding with implementation without executive alignment on these six items introduces operational friction or requires rework.

---

## Decision 1: Multi-Stage Service Routing Sequence Conflict

### The Conflict:
* **Current PHP Source Implementation (`core/BayManager.php:503-509`, line 917):**
  The existing codebase strictly hardcodes and enforces **Step 1 (Car Wash) $\rightarrow$ Step 2 (Oil Change)**:
  ```php
  // Task 1: Wash (Active / Waiting)
  $insTask->execute([$orderId, 'wash', 'Exterior & Interior Wash (Step 1)', 1, 'waiting']);
  // Task 2: Oil Change (Pending prerequisite)
  $insTask->execute([$orderId, 'oil_change', 'Engine Oil Change & Inspection (Step 2)', 2, 'pending_prereq']);
  ```
  Line 917 explicitly blocks technicians with the error message:  
  `"Sequence Rule: Step 1 (Car Wash) must be finished before Step 2 (Oil Change) can begin. Nobody skips a step."`
* **Proposed Workflow Design (`POS-PAYMENT-BUSINESS-RULES.md:164-167`):**
  Proposes **Step 1: Mechanical Lift (Bay 4) $\rightarrow$ Step 2: Wash Bay (Bay 1 or 2)**, arguing that greasy undercarriage work and hot oil drainage should occur before exterior washing and detailing.

### Trade-Offs & Real-World Operational Reality:
| Option | Operational Advantages | Practical Disadvantages |
| :--- | :--- | :--- |
| **Option A (Wash First, as coded in PHP):** | High-pressure wash cleans road mud, grease, and grime from wheel wells and chassis, making the underside clean and pleasant for the mechanic working under the lift. | Draining dark motor oil, changing filters, or handling dirty fluids *after* a fresh wash risks grease smudges on clean fenders or engine bay splashes. |
| **Option B (Lift First, as proposed in design):** | All dirty fluid work, filter extraction, and undercarriage repairs finish first. The car receives a complete exterior wash, wipe-down, and tire shine as the final step before customer handover. | Mechanics work on dirty, road-grimed vehicles on the lift; muddy water can drip on technicians during rainy season. |

### Business Owner Decision Required:
* **Question:** Which workflow sequence should the shop standardize for mixed wash + mechanical orders?
  - **Choice A (Recommended for Customer Presentation):** Re-route code to **Mechanical Lift First $\rightarrow$ Wash Second** so the customer receives a spotless, freshly dried car at handover.
  - **Choice B (Preserve Existing PHP Code):** Maintain **Wash First $\rightarrow$ Mechanical Lift Second**.
  - **Choice C (Dynamic Cashier Choice):** Allow the cashier at intake to select the sequence based on car condition.
* **Risk if Delayed:** Technicians on tablets and bay dispatchers will experience sequence locking errors, and bay screen routing logic will be misaligned.

---

## Decision 2: Receipt Printing Gate Policy (Advisory Print vs. Hard Physical Blockade)

### The Conflict:
* **Current PHP Source Implementation (`core/OrderGate.php:87-124`):**
  Strictly enforces `"No printed paper, no service"`. If `receipt_printed_at` is empty, vehicle admission is rejected with HTTP 422 `ReceiptRequiredException` unless a manager enters a PIN override.
* **Proposed Workflow Design (`POS-FINAL-BUSINESS-RULES.md: Rule 2.4 & 6.1`):**
  Decouples mechanical printing from service admission. Payment confirmation (`payment_status = 'paid'`) and electronic receipt issuance (`receipt_issued_at IS NOT NULL`) constitute the hard financial gate. Physical thermal printing is advisory; if the printer jams or runs out of paper, the car is NOT blocked from the wash bay.

### Business Owner Decision Required:
* **Question:** How strictly should the counter enforce physical paper receipts before a car enters the wash apron?
  - **Choice A (Recommended - Advisory Printing):** Default to electronic receipt as the service gate pass. Paper printing is attempted automatically; if offline, an alert shows on counter, but the vehicle proceeds to washing.
  - **Choice B (Hard Physical Gate):** Keep existing strict code behavior where physical paper printing is mandatory, and paper jams require a Manager PIN override to move any car.
* **Risk if Delayed:** A thermal printer running out of 80mm paper during peak Saturday rush will halt all apron operations and create a car lineup out onto the street.

---

## Decision 3: Walk-In Customer Identity & Database Schema Alignment

### The Conflict:
* **Current Database Schema (`database/init.php:251`):**  
  `customer_name VARCHAR(100) NOT NULL`
* **Current API Implementation (`api/pos/index.php:1374`):**  
  `if ($customerName === '') { $customerName = 'Walk-in Guest'; }`
* **Confirmed Management Directive:**  
  Customer registration is strictly optional; cashier needs only license plate number.

### Business Owner Decision Required:
* **Question:** Should anonymous walk-ins be formally represented in the database schema or handled via application defaulting?
  - **Choice A (Recommended - Zero Migration Risk):** Maintain `customer_name VARCHAR(100) NOT NULL` and standardize `'Walk-in Guest'` as the official corporate system default when customer name is blank.
  - **Choice B (Schema Change):** Execute an `ALTER TABLE orders MODIFY customer_name VARCHAR(100) NULL` to allow true database-level NULLs for anonymous walk-ins.
* **Risk if Delayed:** Inconsistent reporting when filtering repeat customers vs. anonymous walk-ins.

---

## Decision 4: Legacy Unpaid Finished Orders Migration Policy

### The Conflict:
* The production database contains historical records from earlier development and testing phases with contradictory timestamps:
  - Records with `status = 'finished'` and `payment_status = 'unpaid'` (Web POS intake).
  - Records with `status = 'paid'` and `cancelled_at IS NOT NULL`, but `refunded_amount = 0` (cancellation with unknown refund).
* Under strict audit rules, migration scripts must **never** automatically mark unpaid historical orders as paid, nor erase collected payments.

### Business Owner Decision Required:
* **Question:** How should historical ambiguous orders be treated in executive lifetime financial reports?
  - **Choice A (Recommended):** Keep historical records in their authentic state and display an informational **"Historical Unreconciled Exceptions"** tab in reports for owner review.
  - **Choice B:** Owner authorizes an administrative one-time cleanup SQL script to write off uncollected test tickets prior to the cutover date.
* **Risk if Delayed:** Historical lifetime revenue totals may show discrepancies with actual bank deposits from 2025/2026 test periods.

---

## Decision 5: Commercial Bank Webhook Onboarding & Hardware Infrastructure

### The Conflict:
* Automated KHQR webhooks (`api/webhook/khqr_callback.php`) require:
  1. A formal commercial merchant agreement with ABA Bank (PayWay) or National Bank of Cambodia (Bakong).
  2. A public, static IP address or publicly accessible HTTPS domain with valid SSL certificate to receive inbound webhook HTTP POSTs.
  3. API merchant credentials, hash keys, and public keys.
* Most local car wash stations operate behind standard residential/commercial ISP dynamic IPs without public domain routing.

### Business Owner Decision Required:
* **Question:** What is the shop's target verification infrastructure for KHQR?
  - **Choice A (Recommended for Local Counter):** Rely on **Merchant Device Positive Verification (Store Counter Phone Notification + Soundbox Chime)**, which operates 100% reliably on local LAN with zero public IP or API onboarding requirements.
  - **Choice B (Full Automated Bank API):** Shop owner registers for official ABA PayWay Merchant Gateway and configures a reverse proxy or cloud sync endpoint for automated webhook callbacks.
* **Risk if Delayed:** Attempting automated bank webhook verification on an isolated local LAN counter without public DNS will result in timeouts and unconfirmed payments.

---

## 6. Decision 6: Financial Report Metrics – Cash Flow vs. Net Profit Terminology

### The Conflict:
* Early draft wireframes used the label "Net Profit" to describe `Gross Payments - Refunds`.
* Technically, `Gross Payments - Settled Refunds` represents **Net Settled Cash Revenue (Tender Inflow)**, NOT accounting profit.
* Calculating true accounting profit requires subtracting:
  1. Cost of Goods Sold (chemical detergents, motor oils, filters, air fresheners).
  2. Washer labor commissions (`order_washers`).
  3. Mechanic labor commissions (`order_mechanics`).
  4. Facility fixed operating expenses (monthly rent, water utility, electric power, equipment depreciation).

### Business Owner Decision Required:
* **Question:** How should the executive dashboard represent counter earnings?
  - **Choice A (Recommended - Clear Accounting Separation):** Display **"Net Settled Revenue"** for cash drawer/bank collections, and provide a separate **"Net Operating Profit"** card that deducts COGS, worker commissions, and logged expenses.
  - **Choice B:** Keep single card labeled "Net Revenue" and display commissions on a secondary tab.
* **Risk if Delayed:** Shop owner confuses counter gross cash with take-home business profit, leading to tax and dividend calculation errors.

---

## Summary of Decisions Awaiting Owner Sign-Off

| # | Decision Item | Recommended Option | Operational Impact |
| :-: | :--- | :--- | :--- |
| **1** | Multi-Stage Routing Sequence | **Lift First $\rightarrow$ Wash Second** | Customer receives freshly washed/dried vehicle at final handover. |
| **2** | Receipt Printing Service Gate | **Advisory Paper (Electronic Gate)** | Printer paper jams never block cars from entering wash bay. |
| **3** | Walk-In Customer Identity | **Default to `'Walk-in Guest'`** | Zero database schema migration required; fully backward compatible. |
| **4** | Historical Unreconciled Orders | **Isolate in Reconciliation Tab** | Preserves historical audit integrity without falsifying records. |
| **5** | KHQR Verification Channel | **Merchant Phone / Soundbox** | 100% operational on local LAN without bank merchant onboarding. |
| **6** | Financial Report Terminology | **Net Settled Revenue $\ne$ Profit** | Prevents confusing cash collections with true accounting net profit. |
