# Employee Responsibility Matrix & Operational Accountability

**Document ID:** RACI-OPS-2026-003  
**Project:** Car-WashService  
**Scope:** Role Definitions, Authorization Hierarchies, Handover Protocols, and Liability Assignments  
**Date:** November 2026  
**Status:** Complete – Awaiting Boss / Management Approval  

---

## 1. Core Organizational Roles & Job Definitions

To eliminate confusion between staff members, the business must establish eight clearly delineated roles. Each role has specific daily duties, software permissions, authorization thresholds, and clear liability when operational failures occur.

```
┌────────────────────────────────────────────────────────────────────────┐
│                                 OWNER                                  │
│              Strategic Governance, Capital, Financial Audit            │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│                            GENERAL MANAGER                             │
│         Daily Operations, Staff Rostering, Escalations, Z-Reports       │
└───────────┬───────────────────────┬─────────────────────────┬──────────┘
            │                       │                         │
┌───────────▼──────────┐ ┌──────────▼──────────┐   ┌──────────▼──────────┐
│   CASHIER COUNTER    │ │   WASH SUPERVISOR   │   │   LEAD TECHNICIAN   │
│ Front Desk, Payments,│ │ Queue Flow, QC Gate,│   │ Mechanical, Lube,   │
│ Shifts, Gate Passes  │ │ Bay Crew Allocation │   │ Workshop Safety     │
└───────────┬──────────┘ └──────────┬──────────┘   └──────────┬──────────┘
            │                       │                         │
┌───────────▼──────────┐ ┌──────────▼──────────┐   ┌──────────▼──────────┐
│   CRM COORDINATOR    │ │    WASH CREW        │   │  STOCK CONTROLLER   │
│ Intake Triage, VIPs, │ │ Washers, Detailers, │   │ Parts Requisitions, │
│ Customer Lounge Host │ │ Dryers, Vacuums     │   │ Drums, Shelf Audit  │
└──────────────────────┘ └─────────────────────┘   └─────────────────────┘
```

---

### Detailed Role Descriptions

#### 1. Business Owner (`owner`)
* **Primary Mission:** Overall business solvency, profitability, brand reputation, and capital protection.
* **Daily Responsibilities:**
  - Review executive revenue, margin, and net profit summaries.
  - Review weekly stock variance and chemical consumption reports.
  - Authorize price list changes, employee commissions, and major capital expenditures (> $200).
* **Software Permissions:** Unrestricted (`*`).
* **Ultimate Liability:** Loss of operating license, major legal disputes, business insolvency.

#### 2. General Manager / Shift Manager (`manager`)
* **Primary Mission:** On-the-ground operational execution, line flow, customer dispute resolution, and shift reconciliation.
* **Daily Responsibilities:**
  - Conduct morning team briefing and inspect bay cleanliness.
  - Review and sign off on Cashier Daily Z-Reports and register drawer handovers.
  - Approve manager-override discounts, zero-stock sales, and customer refunds.
  - Resolve escalated customer complaints and approve rework jobs.
* **Software Permissions:** Full operational back-office, reports, role management, audit logs, cashier overrides, refunds.
* **Ultimate Liability:** Daily cash shortages, unexcused bay bottlenecks, unresolved customer escalations.

#### 3. Cashier (`cashier`)
* **Primary Mission:** Fast, error-free order entry, tender collection, change dispensing, and receipt/gate pass issuance.
* **Daily Responsibilities:**
  - Count drawer float at shift open and verify balance against opening record.
  - Process cash, ABA Pay, and KHQR transactions accurately.
  - Reconcile physical cash and digital bank settlements at shift handover.
  - Issue exit gate passes upon payment verification.
* **Software Permissions:** `pos.access`, `pos.create_order`, `pos.pay_order`, `pos.shift_open`, `pos.shift_close`, `orders.view`. (Strictly prohibited from manual discounts, order cancellations, or refunds without manager PIN).
* **Ultimate Liability:** End-of-shift register drawer cash shortages; unverified or fake bank transfer slips.

#### 4. Wash Supervisor / QC Lead (`wash_supervisor`)
* **Primary Mission:** Maximum throughput across wash bays and uncompromising 100% vehicle cleaning quality control.
* **Daily Responsibilities:**
  - Direct traffic onto the apron and allocate vehicles to optimal bays.
  - Balance workload across washing teams to avoid commission disputes.
  - Inspect every vehicle against the 4-Point Quality Checklist before customer release.
  - Direct re-wash / touch-ups immediately if standards are missed.
* **Software Permissions:** `bays.view`, `bays.assign`, `bays.qc_signoff`, `bays.move_queue`, `washer_jobs.view`.
* **Ultimate Liability:** Customer complaints regarding dirty vehicles, missed cleaning spots, damaged vehicle trims, or excessive bay idle time.

#### 5. Washer / Detailer (`washer`)
* **Primary Mission:** High-efficiency, scratch-free exterior washing, vacuuming, drying, and interior wiping.
* **Daily Responsibilities:**
  - Wash vehicles using two-bucket method, high-pressure rinse, and foam cannon.
  - Clean wheels, wheel wells, and apply tire dressing evenly.
  - Vacuum interior carpets, floor mats, and trunk; wipe dashboard and door jambs.
  - Notify Wash Supervisor immediately upon job completion for inspection.
* **Software Permissions:** `washer_jobs.view` (View assigned cars only; tap "Ready for QC").
* **Ultimate Liability:** Scratches caused by dropped wash mitts, vehicle water spots, wet seats, or broken interior accessory clips.

#### 6. Mechanic / Automotive Technician (`mechanic`)
* **Primary Mission:** Accurate mechanical diagnostics, safe oil/filter servicing, and proper technical installations.
* **Daily Responsibilities:**
  - Position vehicles safely on hydraulic lifts using certified lift points.
  - Perform engine oil drains, replace oil filters, and verify drain plug torque specs.
  - Conduct guided vehicle safety inspections (brake pads, tire tread, battery health, fluid leaks) and upload digital photos.
  - Request required replacement parts from stock control.
  - Apply windshield mileage reminder sticker and reset dashboard service lights.
* **Software Permissions:** `mechanic_jobs.view`, `mechanic_jobs.update`, `inspection.update`, `parts_requests.create`.
* **Ultimate Liability:** Loose oil drain plugs, stripped oil pan threads, engine oil leaks, unperformed inspection checklist items.

#### 7. Stock Controller / Parts Custodian (`stock_control`)
* **Primary Mission:** Safeguarding physical parts, bulk lubricants, and detailing chemicals against leakage, spoilage, and theft.
* **Daily Responsibilities:**
  - Receive parts deliveries from suppliers, check invoices against physical counts, and log into stock ledger.
  - Fulfill mechanic parts requests and issue parts to work orders in software.
  - Monitor bulk engine oil barrels and detailing chemical dilutions.
  - Conduct weekly physical cycle counts and report stock variances to General Manager.
* **Software Permissions:** `inventory.view`, `inventory.manage`, `parts_requests.approve`, `stock_ledger.view`.
* **Ultimate Liability:** Unaccounted stock shrinkage, missing spare parts, depleted oil barrels during peak shifts.

#### 8. CRM Coordinator / Lounge Host (`crm_host`)
* **Primary Mission:** Exceptional customer hospitality, appointment scheduling, intake triage, and post-service follow-up.
* **Daily Responsibilities:**
  - Greet customers upon apron arrival; record vehicle plate and intake condition photos.
  - Guide customers to the air-conditioned waiting lounge and offer beverages.
  - Explain inspection findings, present upsell recommendations, and obtain customer approval.
  - Contact customers 3 days after service for satisfaction surveys and manage oil-due reminder calls.
* **Software Permissions:** `pos.access`, `customers.manage`, `crm.reminders`, `appointments.manage`, `inspection.view`.
* **Ultimate Liability:** Negative customer lounge experience, uncontacted overdue service reminders, missed appointment slots.

---

## 2. Complete RACI Matrix Across Operational Stages

### RACI Definitions:
* **R (Responsible):** The role that executes the task.
* **A (Accountable):** The single role with ultimate veto power and liability for success or failure.
* **C (Consulted):** The role that provides two-way input, data, or specialized expertise.
* **I (Informed):** The role kept updated on progress, status changes, or completion.

| Operational Process Stage | Owner | General Manager | Cashier | Wash Supervisor | Washer | Mechanic | Stock Controller | CRM Host |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **1. Customer Greeting & Apron Triage** | I | I | I | C | I | I | I | **R / A** |
| **2. Intake Damage Inspection & Key Tagging** | I | C | I | A | I | C | I | **R** |
| **3. POS Order Entry & Pricing** | I | C | **R / A** | I | I | C | C | C |
| **4. Manual Discount Authorization** | C | **A** | R | I | I | I | I | I |
| **5. Upfront Tender Collection (Cash/Bank)** | I | C | **R / A** | I | I | I | I | I |
| **6. Bank Transfer Slip Verification** | I | C | **R / A** | I | I | I | I | I |
| **7. Queue Prioritization & Bay Allocation** | I | C | I | **R / A** | I | C | I | I |
| **8. Wash & Vacuum Execution** | I | I | I | A | **R** | I | I | I |
| **9. Mechanical & Oil-Change Execution** | I | I | I | I | I | **R / A** | C | I |
| **10. Guided Technical Inspection (Photos)** | I | C | I | I | I | **R / A** | I | C |
| **11. Parts Requisition & Workshop Dispensing** | I | C | I | I | I | R | **A** | I |
| **12. Order Total Recalculation for Parts** | I | I | C | I | I | I | **R / A** | I |
| **13. Quality Inspection (QC Gate Sign-off)** | I | C | I | **R / A** | C | C | I | I |
| **14. Ready Notification (TV / SMS / Chime)** | I | I | I | A | I | I | I | **R** |
| **15. Final Payment Settlement (Post-Pay)** | I | C | **R / A** | I | I | I | I | I |
| **16. Exit Gate Pass Issuance** | I | I | **R / A** | I | I | I | I | I |
| **17. Key Handover & Customer Departure** | I | I | C | C | I | I | I | **R / A** |
| **18. Refund & Dispute Settlement** | C | **A** | R | C | I | I | I | C |
| **19. Register Drawer Shift Handover** | I | **A** | **R** | I | I | I | I | I |
| **20. Daily Z-Report & Sales Closing** | C | **A** | R | I | I | I | C | I |
| **21. Weekly Physical Stock Cycle Count** | C | A | I | I | I | C | **R** | I |
| **22. CRM Satisfaction & Overdue Follow-up** | I | C | I | I | I | I | I | **R / A** |

---

## 3. Operational Accountability & Failure Scenarios

When something breaks on the shop floor, management must immediately identify who is accountable rather than allowing staff to cross-blame.

| Operational Breakdown | Who Performed Task | Who Approved It | Who Is Liable / Responsible | Mandatory Corrective Action |
| :--- | :--- | :--- | :--- | :--- |
| **Customer claims scratch on car after wash** | Washer | Wash Supervisor | **CRM Host / Intake Host** (if intake photos missed) OR **Wash Supervisor** (if QC passed damaged vehicle) | Check intake photos. If intake photo proves pre-existing scratch, claim is rejected. If intake photo is missing, shop covers repair cost and penalizes responsible staff. |
| **Register drawer cash shortage ($25 missing)** | Cashier | General Manager | **Cashier** | Shortage deducted from cashier shift allowance; audit log reviewed for unrecorded `open_drawer_no_sale` actions. |
| **Car engine seized due to oil leak 5 km away** | Mechanic | Lead Technician | **Mechanic** | Review mandatory underbody inspection photo. If photo is missing or plug was loose, mechanic is held legally liable and disciplinary action is taken. |
| **$50 of brake pads missing from stock room** | Stock Controller | General Manager | **Stock Controller** | Review ledger vs parts requests. If parts were issued without an approved order ticket, stock controller reimburses loss. |
| **Customer drove away without paying $30 bill** | Cashier | Wash Supervisor | **Exit Gate Handover / Cashier** | Cashier and gate host receive formal warning; gate pass verification policy re-enforced. |
| **Bay blocked for 40 mins due to missing parts** | Mechanic | Stock Controller | **Stock Controller** | Urgent local supplier purchase; stock reorder thresholds updated for that part number. |
| **Customer waited 60 mins despite VIP appointment** | Wash Supervisor | CRM Host | **Wash Supervisor** | Apologize to customer, issue 50% discount voucher on next wash, adjust bay allocation priorities. |

---

## 4. Formal Inter-Role Handover Protocols

A core operational failure in the current system is informal handoffs (e.g. shouting across the yard, leaving keys on the windshield). Three mandatory handover protocols must be enforced:

### Protocol 1: Front Intake to Wash Bay (Apron to Supervisor)
1. **Intake Host** scans license plate, clips heavy-duty acrylic Barcode Key Tag (e.g. `TAG-04`) to the customer's keyring, and parks car in Intake Lane 1.
2. Intake Host hands Key Tag stub to customer and places the vehicle key in the **Apron Intake Key Rack** under slot `04`.
3. System status changes to `Waiting (Intake Complete)`.
4. **Wash Supervisor** inspects the queue monitor, takes the physical key from slot `04`, hands key to assigned Washer, and assigns vehicle to Bay 2.

### Protocol 2: Wash Bay to Technical Bay (Inter-Departmental Transfer)
1. When wash task is complete, Washer dries vehicle exterior and moves car to **Transition Staging Stall T1**.
2. Washer places vehicle keys in **Tech Transfer Drop Box** and taps `"Ready for Tech"` on tablet.
3. System sends visual/audio chime to Technical Bay monitor: `Vehicle 2A-1234 ready for Transfer`.
4. Assigned **Mechanic** takes keys from Drop Box, places paper floor mats and plastic steering wheel protector in car, drives onto Lift 4, and taps `"Tech Received"`.

### Protocol 3: Service Completion to Customer Release (QC to Exit Handover)
1. Upon finishing work, Washer/Mechanic taps `"Submit for QC"`.
2. **Wash Supervisor** conducts 4-point physical inspection:
   - Wheels, tires, and wheel wells.
   - Streak-free glass (front, rear, mirrors).
   - Interior vacuuming and dust-free dashboard.
   - Clean door jambs, sills, and trunk rim.
3. Supervisor checks off items on tablet and taps `"QC Approved"`.
4. Supervisor parks car in **Ready Staging Stall (R1–R6)** and places keys in **Secure Key Safe Box behind Cashier Counter** in slot matching Key Tag #.
5. System triggers customer TV chime and SMS: *"Vehicle Ready in Stall R2"*.
6. Customer presents Key Tag stub to **Cashier**.
7. Cashier verifies payment status:
   - If unpaid: Collects balance, records tender, and prints **Exit Gate Pass**.
   - If prepaid: Scans Key Tag stub, verifies payment timestamp, and prints **Exit Gate Pass**.
8. Cashier retrieves keys from Safe Box and hands keys + Gate Pass to customer.
9. **Exit Gate Guard** collects Gate Pass at driveway exit, verifies plate number matches paper pass, and opens barrier arm.
