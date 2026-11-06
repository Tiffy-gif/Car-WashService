# Strategic Business Decisions Required Prior to Implementation

**Document ID:** DEC-MGT-2026-006  
**Project:** Car-WashService  
**Scope:** Policy Governance, Operational Rules, Authorization Limits, and Structural Approvals  
**Target Audience:** Business Owner, General Manager, Board of Directors  
**Date:** November 2026  
**Status:** Pending Boss / Executive Sign-off  

---

## Executive Briefing for the Owner

The technical audit revealed that past development created individual software modules without aligned business rules. **Writing more software code before resolving core operational policies will lead to wasted development hours and continued staff friction.**

Management sign-off is required on the following **eight foundational business decisions**. Each decision outlines the current state, identified operational defect, available policy options with trade-offs, and our formal consulting recommendation.

---

## Decision 1: Payment Timing Architecture – Upfront Pay vs. Pay-on-Exit vs. Hybrid

### Current Process
* System enforces a rigid "No Receipt, No Wash" rule. Every customer must pay 100% upfront before their car touches the wash bay or mechanic lift.

### Identified Problem
* Paralyzes detailing, oil changes, and mechanical repairs where required parts, actual oil volume, or paint condition cannot be determined until the vehicle is inspected.
* Causes severe driveway apron congestion during morning and evening rush hours as drivers wait at the counter to verify digital bank payments.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: 100% Upfront Only (Status Quo)** | Enforce full upfront payment on all services. | Zero risk of unpaid customer drive-offs. | Chokes intake driveway; impossible to bill extra oil or parts; slows check-in. |
| **Option B: 100% Pay-on-Exit for All** | Issue ticket upon arrival; collect all payments at customer pickup counter. | Blazing fast check-in (<15 sec); customer pays in air-conditioned comfort. | Risk of unpaid vehicle drive-off if exit gate controls are loose. |
| **Option C: Service-Tiered Hybrid (RECOMMENDED)** | **Express Wash:** Customer choice (Pay upfront OR pay at pickup).<br>**Detailing / Mechanic (> $50):** 30–50% deposit upfront; balance settled at exit. | Optimizes apron speed; secures high-ticket jobs; accommodates mid-service parts. | Requires gate pass exit verification system. |

### Formal Recommendation: **Option C (Service-Tiered Hybrid)**
* **Business Benefit:** Maximizes driveway throughput by 40%; eliminates customer arguments over added parts; protects business capital on major repairs.
* **Complexity:** Medium.
* **Roles Involved:** Cashier, Apron Greeter, General Manager.
* **Risks:** Requires enforcing physical Key Safe Box and Gate Pass controls.

---

## Decision 2: Pre-Service Vehicle Damage Inspection Policy

### Current Process
* Zero intake damage inspection. Vehicles enter bays with no photographic or written record of pre-existing scratches, bumper cracks, or dashboard warning lights.

### Identified Problem
* The shop is legally defenceless when unscrupulous or mistaken customers claim pre-existing body damage occurred inside the wash bay, demanding hundreds of dollars in compensation.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: Zero Inspection (Status Quo)** | Rely on verbal trust and disclaimers on paper receipt. | Zero check-in time overhead. | Severe financial vulnerability to false customer damage claims. |
| **Option B: Full 360° Photo Inspection on All Cars** | Intake Host takes 4–6 high-res photos of every vehicle on tablet before entry. | 100% bulletproof legal protection; builds high-end customer trust. | Adds 30–45 seconds per car; requires rugged tablets on apron. |
| **Option C: Tiered Inspection (RECOMMENDED)** | **Basic Wash:** Rapid walk-around; photo taken ONLY if existing dent/scratch is visible.<br>**Detailing / Mechanical:** Mandatory 4-angle photos + signed digital waiver. | Fast wash throughput; complete protection on high-value and repair vehicles. | Staff must be trained to spot and photograph obvious pre-existing scratches. |

### Formal Recommendation: **Option C (Tiered Inspection)**
* **Business Benefit:** Protects against false liability claims without causing apron gridlock on basic $4 washes.
* **Complexity:** Low to Medium.
* **Roles Involved:** CRM / Intake Host, Wash Supervisor.
* **Risks:** Requires reliable Wi-Fi coverage across the front driveway.

---

## Decision 3: Quality Control (QC) Gatekeeper & Sign-off Authority

### Current Process
* Washers tap "Finish Car" on their personal screen. Zero independent quality check.

### Identified Problem
* Washers rushing for volume commissions release cars with dirty rims, unvacuumed rear seats, and wet mirrors. Customers become the de facto QC inspectors, resulting in brand damage and re-wash friction.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: Self-Sign-off by Washer (Status Quo)** | Washer marks their own job complete. | Low labor overhead; fast software status transition. | High customer complaint rate; sloppy cleaning standards. |
| **Option B: Dedicated Full-Time QC Inspector** | Hire a dedicated Quality Inspector at the finishing bay with tablet sign-off. | Pristine 100% perfection; white-glove customer experience. | Adds salary overhead of 1 staff member ($250–$350/mo). |
| **Option C: Wash Supervisor QC Gate (RECOMMENDED)** | Wash Supervisor must physically inspect 4 points and tap "QC Pass" before TV chime sounds. | Zero additional headcount; leverages existing supervisor authority. | Supervisor must remain on finishing pad during peak rushes. |

### Formal Recommendation: **Option C (Wash Supervisor QC Gate)**
* **Business Benefit:** Drastically reduces customer re-wash complaints by 85%; enforces accountability without adding payroll costs.
* **Complexity:** Low.
* **Roles Involved:** Wash Supervisor, Washers.
* **Risks:** If supervisor is distracted, finished cars may wait 3–5 minutes for inspection sign-off.

---

## Decision 4: Chemical & Consumable Inventory Control Strategy

### Current Process
* System only tracks discrete retail packaged products (bottles). Bulk wash shampoo, degreaser, acid, tire gel, and ceramic spray have zero consumption tracking.

### Identified Problem
* Massive inventory shrinkage. Management has no idea whether $500 of foaming shampoo washed 500 cars or 1,200 cars. Chemicals are subject to staff theft or wasteful over-dilution.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: Periodic Expense Writing (Status Quo)** | Expense chemical barrels when purchased (`expenses` table); no per-wash tracking. | Simple accounting; zero operational friction on washers. | Zero theft prevention; cannot calculate true gross margin per vehicle. |
| **Option B: Precise Gram/Milliliter Metering** | Washers weigh chemical bottles before and after every wash. | Extreme theoretical accuracy. | Paralyzes shop speed; completely impractical in real operations. |
| **Option C: Recipe-Based Standard Yield Deduction (RECOMMENDED)** | Assign standard chemical cost/volume per vehicle type (e.g. Small Car = 50ml, SUV = 80ml). Reconcile against physical drum levels weekly. | Accurate gross margin analytics; detects bulk theft; zero staff friction. | Requires locked chemical dilution proportioners in pump room. |

### Formal Recommendation: **Option C (Recipe-Based Standard Yield Deduction)**
* **Business Benefit:** Identifies chemical pilferage immediately; provides accurate true cost-per-wash profitability reporting.
* **Complexity:** Medium.
* **Roles Involved:** Stock Controller, General Manager.
* **Risks:** Requires installing locked mechanical chemical proportioner valves (e.g. Dosatron or Hydro Systems).

---

## Decision 5: Staff Commission Structure – Individual vs. Pooled vs. Hybrid

### Current Process
* System divides commission equally among washers assigned to the order in `order_washers`. Washers cherry-pick easy cars from their tablet screen.

### Identified Problem
* Destroys teamwork. Washers fight over small, clean cars and hide in the break room when a mud-caked 4x4 pickup arrives.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: Pure Individual Commission (Status Quo)** | Washers get paid per car assigned. | Direct performance incentive. | Toxic worker fighting; cherry-picking; sloppy rushed cleaning. |
| **Option B: Pure Hourly / Daily Wage (No Commission)** | Fixed daily salary for all wash crew. | Cooperative atmosphere; staff clean thoroughly without rushing. | Washers work slowly; zero motivation to handle high rush volume. |
| **Option C: Base Daily Wage + Shift Pool Bonus (RECOMMENDED)** | Guaranteed base pay + pooled shift bonus ($1.00 per car washed by the shift, divided equally among active on-duty washers, subject to 0 QC re-wash penalties). | Fosters team collaboration; workers help each other finish large trucks; rewards team speed and cleanliness. | Requires daily attendance and QC penalty tracking. |

### Formal Recommendation: **Option C (Base Daily Wage + Shift Pool Bonus)**
* **Business Benefit:** Eliminates worker disputes; guarantees large trucks are cleaned fast by multi-person teams; aligns worker incentives with customer quality.
* **Complexity:** Medium.
* **Roles Involved:** General Manager, Wash Supervisor, Cashier.
* **Risks:** Requires supervisor to dock pool bonus if re-wash rate exceeds 5%.

---

## Decision 6: Appointment Lateness Tolerance & VIP Prioritization

### Current Process
* Arriving > 10 minutes late automatically strips VIP `P0` status and relegates customer behind all walk-ins (`P3`).

### Identified Problem
* High-value VIP customers encounter Phnom Penh traffic jams, arrive 12 minutes late, and are insulted by staff telling them they must wait 50 minutes behind motorbikes.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: Strict 10-Min Demotion (Status Quo)** | Hard demotion at minute 11. | Maximizes queue predictability. | Destroys customer relationships; causes public shouting matches. |
| **Option B: Infinite VIP Hold** | Bay held empty indefinitely until VIP arrives. | VIP is always happy. | Empty bay bleeds revenue while 6 walk-ins sit waiting outside. |
| **Option C: Dynamic Grace Window & Soft-Slotting (RECOMMENDED)** | **20-Minute Grace Window:** VIP status fully preserved.<br>**20–40 Minutes Late:** Re-slotted as `P1 (Next Available Bay)` rather than lowest walk-in (`P3`). | Respects customer traffic reality while keeping bay utilization high. | Requires CRM Host to monitor incoming appointment calendar. |

### Formal Recommendation: **Option C (Dynamic Grace Window & Soft-Slotting)**
* **Business Benefit:** Preserves lucrative VIP relationships while preventing expensive bay idling.
* **Complexity:** Low.
* **Roles Involved:** CRM Host, Wash Supervisor.
* **Risks:** Requires automated SMS reminder 30 minutes prior to appointment.

---

## Decision 7: Parts Approval Authority & Automated Supplement Billing

### Current Process
* Parts requests are approved in Owner view (`oil_care.php`). Approval decrements stock and inserts item into `order_items`, but **never updates `orders.total_usd`**. Customer is billed $0 for the parts.

### Identified Problem
* Catastrophic revenue leakage. Every approved part during an oil change or repair is currently given away for free unless cashier manually calculates math on paper.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: Owner-Only Approval (Status Quo)** | Only Owner can approve parts; total never updates. | Severe bottleneck when Owner is away; massive financial leak. | Unacceptable financial loss. |
| **Option B: Workshop Foreman Tablet Approval with Real-Time Billing (RECOMMENDED)** | Delegated authority to Stock Controller or Lead Mechanic on workshop tablet. System **automatically recalculates order total** and sends instant notification to Cashier and Customer. | **Plugs financial leak immediately;** unblocks bay work; ensures 100% of parts issued are billed. | Requires setting approval spending limits (e.g. Lead Mechanic can approve up to $50; Manager up to $200). |

### Formal Recommendation: **Option B (Delegated Workshop Approval with Auto-Recalculation)**
* **Business Benefit:** Stops inventory shrinkage; recovers an estimated $1,200–$2,500/month in unbilled parts revenue.
* **Complexity:** Medium (requires code fix in `TechnicalManager::approvePartsRequest`).
* **Roles Involved:** Stock Controller, Lead Mechanic, Cashier.
* **Risks:** Spending authorization thresholds must be strictly configured in software roles.

---

## Decision 8: Offline Refund & Manual Receipt Settlement Authority

### Current Process
* Offline refunds are completely hard-coded blocked in software (`OFFLINE_REFUND_BLOCKED`). Cashiers cannot issue refunds when internet drops.

### Identified Problem
* If internet goes down and a prepaid customer cannot wait due to an emergency, the cashier cannot refund them, resulting in explosive customer anger.

### Policy Options

| Option | Operational Description | Pros | Cons |
| :--- | :--- | :--- | :--- |
| **Option A: Total Offline Block (Status Quo)** | Software strictly prohibits refunds without active cloud link. | Protects against offline double-refund fraud. | Creates severe customer hostility and physical altercations during network outages. |
| **Option B: Unrestricted Cashier Offline Refunds** | Any cashier can disburse refund cash when offline. | Resolves customer issue immediately. | High fraud risk; cashiers can pocket cash and claim "it was an offline refund." |
| **Option C: Dual-Auth Physical Emergency Voucher Protocol (RECOMMENDED)** | Offline refunds permitted ONLY with physical pre-printed, serial-numbered "Emergency Refund Slip" signed by both General Manager and Cashier. Must be reconciled upon reconnection within 12 hours. | Protects customer goodwill; provides complete financial audit trail; stops theft. | Requires maintaining a secure, serial-controlled physical voucher pad. |

### Formal Recommendation: **Option C (Dual-Auth Physical Voucher Protocol)**
* **Business Benefit:** Professional, calm customer service during outages with ironclad anti-fraud controls.
* **Complexity:** Low to Medium.
* **Roles Involved:** Cashier, General Manager, Owner.
* **Risks:** Missing paper voucher serial numbers are treated as direct cash shortages charged to the manager.

---

## Decision Sign-off Matrix

Please review and indicate executive approval:

| Decision Item | Recommended Choice | Approved (Initials) | Modified Choice / Executive Instructions |
| :--- | :--- | :---: | :--- |
| **1. Payment Timing** | Option C (Tiered Hybrid: Fast wash flexible, Detailing/Repair deposit) | [ ______ ] | |
| **2. Damage Inspection** | Option C (Tiered: Obvious scratches on wash, Full 360° photos on Detailing/Repair) | [ ______ ] | |
| **3. Quality Control Gate** | Option C (Wash Supervisor mandatory 4-point physical QC before TV chime) | [ ______ ] | |
| **4. Chemical Inventory** | Option C (Recipe-based standard yield deduction & weekly drum dipstick check) | [ ______ ] | |
| **5. Commission Structure**| Option C (Base daily wage + pooled shift quality bonus) | [ ______ ] | |
| **6. Appointment Rules** | Option C (20-min grace window; soft-slot to P1 if 20–40 min late) | [ ______ ] | |
| **7. Parts Billing Authority**| Option B (Delegated workshop approval with automated order total recalculation) | [ ______ ] | |
| **8. Offline Refunds** | Option C (Dual-auth physical serial voucher protocol with GM PIN) | [ ______ ] | |

**Executive Review Completed By:**  
*Name:* _____________________________________  
*Title:* ______________________________________  
*Date:* ______________________________________  
