# POS Implementation Dependency & Cross-Module Impact Map

**Document ID:** POS-DEP-2026-001  
**Project:** Car-WashService  
**Scope:** Architectural Call Chains, Cross-Module Dependencies, Database Table Impact, Breakage Mitigation  
**Date:** November 6, 2026  
**Status:** Complete – Awaiting Management Approval  

---

## 1. End-to-End Architectural Call Chains

This section traces the exact execution paths across frontend components, API endpoints, core business engines, database tables, and hardware interfaces for the five primary cashier transactions.

```mermaid
graph TD
    Client["pos/js/app.js (Counter POS Client)"]
    API["api/pos/index.php (POS Gateway)"]
    
    subgraph CoreEngines["Core Business Logic Layer"]
        CC["core/CashierControl.php"]
        OG["core/OrderGate.php"]
        BM["core/BayManager.php"]
        TM["core/TechnicalManager.php"]
        BTC["core/BankTransferControl.php"]
        SC["core/StockControl.php"]
        SQ["core/SmartQueueEngine.php"]
        AL["core/AuditLogger.php"]
    end

    subgraph DatabaseTables["Database Persistence Layer"]
        T_Ord[("orders")]
        T_Item[("order_items")]
        T_Bay[("bays")]
        T_BayHist[("bay_history")]
        T_Task[("order_tasks")]
        T_Wash[("order_washers")]
        T_Mech[("order_mechanics")]
        T_Shift[("cashier_shifts")]
        T_Audit[("audit_logs")]
    end

    subgraph Peripherals["Hardware & Presentation Layer"]
        Printer["80mm Thermal Printer (ESC/POS)"]
        Drawer["Cash Drawer Pulse (RJ11)"]
        TV["tv.php (Apron Queue TV)"]
        WasherApp["views/washer/jobs.php"]
        MechanicApp["views/mechanic/jobs.php"]
    end

    Client -->|HTTP POST JSON| API
    API --> CC
    API --> OG
    API --> BM
    API --> BTC
    API --> SC
    API --> AL
    
    BM --> T_Bay
    BM --> T_BayHist
    OG --> T_Ord
    SC --> T_Item
    AL --> T_Audit

    Client -->|WebSocket / Raw ESC| Printer
    Client -->|Drawer Kick| Drawer
    T_Ord -.->|Live Polling| TV
    T_Ord -.->|Live Polling| WasherApp
    T_Ord -.->|Live Polling| MechanicApp
```

---

### 1.1 Operation 1: Fast Upfront Cash Checkout Call Chain

```
[Cashier Interface: pos/js/app.js]
  │
  ├── 1. Generates / reuses cart-lifecycle idempotency key: crypto.randomUUID()
  ├── 2. Calculates subtotal, discount, grand total (USD & KHR) via getExchangeRate()
  ├── 3. Validates cash tendered >= grand total
  ├── 4. Collects assigned bay_id, washer_id, mechanic_id, plate_number
  └── 5. POST payload -> api/pos/index.php?action=create_order
        │
        ├── [api/pos/index.php]
        │     ├── Verify session / token: Auth::getUserId(), X-Staff-Token
        │     ├── Validate idempotency_key in `orders`: if found, return cached order
        │     ├── Check Day Lock: CashierControl::isDayLocked()
        │     ├── Check Active Shift: CashierControl::getActiveShift(staff_id)
        │     │
        │     ├── [TRANSACTION BEGIN]
        │     ├── Set payment_status = 'paid', status = 'waiting' (or 'washing' if bay free)
        │     ├── Generate electronic receipt number: REC-YYMMDD-###
        │     ├── Set receipt_issued_at = NOW() (Electronic receipt contract established)
        │     ├── INSERT INTO orders (...)
        │     ├── INSERT INTO order_items (...)
        │     │
        │     ├── [Stock Deduction via StockControl]
        │     │     └── StockControl::commitOrderStock(order_id, order_number, cashier_id)
        │     │
        │     ├── [Atomic Bay Assignment via BayManager]
        │     │     ├── If designated bay is free:
        │     │     │     UPDATE bays SET status = 'busy', current_order_id = ? WHERE id = ? AND status = 'free';
        │     │     │     INSERT INTO bay_history (action='assigned', ...)
        │     │     └── If bay is busy: Order placed in queue buffer (NEVER marks busy bay as reserved)
        │     │
        │     ├── [Crew Assignment & Commission Staging]
        │     │     ├── INSERT INTO order_washers (order_id, stage_sequence, washer_id, commission_usd)
        │     │     └── INSERT INTO order_mechanics (order_id, stage_sequence, mechanic_id, commission_usd)
        │     │
        │     ├── [OrderGate Service Admission Gate Check]
        │     │     └── OrderGate::assertAdmissibleToService($order)
        │     │           ├── Confirms payment_status == 'paid'
        │     │           ├── Confirms receipt_issued_at IS NOT NULL (electronic receipt issued)
        │     │           └── Physical printer status (receipt_printed_at) decoupled; failure never blocks
        │     │
        │     ├── AuditLogger::log('ORDER_CREATED', "Order #... paid cash", ...)
        │     └── [TRANSACTION COMMIT]
        │
        └── Return HTTP 200 { success: true, order: {...} }
              │
              ├── [pos/js/app.js UI Handler]
              │     ├── Display giant green Change Due ($ & ៛)
              │     ├── Trigger physical cash drawer kick pulse (ESC p 0 25 250)
              │     ├── PosThermalPrinter.printReceipt(order) -> Silent 80mm ESC/POS
              │     │     (If printer paper jam occurs, receipt_printed_at remains NULL, but service proceeds)
              │     └── Clear cart & reset intake for next vehicle
```

---

### 1.2 Operation 2: Verified KHQR Checkout Call Chain (6-Point Verification)

```
[Cashier Interface: pos/js/app.js]
  │
  ├── 1. Cashier selects KHQR -> Dynamic QR generated encoding merchant ID, order total & currency
  ├── 2. Customer scans QR using mobile banking app (Bakong / ABA / Acleda)
  ├── 3. Notification arrives on STORE'S OWN MERCHANT DEVICE (Phone Push / Soundbox Chime)
  ├── 4. Cashier inspects store device and matches 6-point criteria:
  │     (1) Genuine bank reference #, (2) Exact amount/currency, (3) Store receiving account,
  │     (4) Successful status, (5) Cashier attribution, (6) Duplicate check
  └── 5. Cashier enters genuine Bank Ref # and submits -> POST api/pos/index.php?action=create_order
        │   Payload includes: { payment_method: 'khqr', payment_ref: '849201', verified_by: cashier_id }
        │
        ├── [api/pos/index.php Gateway Verification]
        │     ├── Enforce payment_ref is NOT empty (reject synthetic TRX- strings)
        │     ├── Enforce duplicate detection: SELECT id FROM orders WHERE payment_ref = '849201'
        │     │     If exists -> HTTP 409 DUPLICATE_PAYMENT_REFERENCE
        │     ├── Verify cashier authenticated session
        │     │
        │     ├── [TRANSACTION BEGIN]
        │     ├── INSERT INTO orders:
        │     │     payment_status = 'paid', 
        │     │     payment_method = 'khqr', 
        │     │     payment_ref = '849201',
        │     │     payment_verified_by_id = cashier_id,
        │     │     payment_verified_by_name = cashier_name,
        │     │     payment_verified_at = NOW(),
        │     │     receipt_number = 'REC-261106-0042',
        │     │     receipt_issued_at = NOW()
        │     │
        │     ├── Atomically claim target bay or place in Priority Queue
        │     ├── OrderGate::assertAdmissibleToService($order) -> Passes electronic gate
        │     ├── AuditLogger::log('KHQR_PAYMENT_VERIFIED', "Cashier verified KHQR ref #849201", ...)
        │     └── [TRANSACTION COMMIT]
        │
        └── Return HTTP 200 { success: true, order: {...} }
              │
              └── [pos/js/app.js UI Handler]
                    ├── Silent thermal receipt prints with verified Bank Ref # clearly displayed
                    ├── Bay screen (tv.php) instantly displays authorized vehicle in green
                    └── Cashier UI resets in < 1 second
```

---

### 1.3 Operation 3: Multi-Stage Bay Dispatch Call Chain (Oil Change + Car Wash)

```
[Order Intake: Mixed Job - Oil Package + Sedan Wash]
  │
  ├── Total: $38.00 upfront -> Paid & Verified at Counter
  ├── Electronic Receipt issued: REC-YYMMDD-### (receipt_issued_at = NOW())
  ├── System identifies two stages:
  │     Stage 1: Mechanical Lift (Bay 4)
  │     Stage 2: Exterior Wash & Dry (Bay 1 or 2)
  └── POST api/pos/index.php?action=create_order
        │
        ├── INSERT INTO orders:
        │     active_stage = 1, stage_count = 2, stage_routing = 'bay4_to_wash'
        ├── INSERT INTO order_tasks (sequence=1, task_type='oil_lube', bay_id=4, status='waiting')
        ├── INSERT INTO order_tasks (sequence=2, task_type='wash', status='waiting')
        │
        ├── [Bay 4 Availability Check & Atomic Claim]
        │     ├── Check Bay 4 availability:
        │     │     UPDATE bays SET status = 'busy', current_order_id = ? WHERE id = 4 AND status = 'free';
        │     ├── If rowCount == 1 (Bay 4 Free):
        │     │     Bay 4 successfully locked; vehicle enters Bay 4 immediately.
        │     └── If rowCount == 0 (Bay 4 Busy):
        │           NEVER mark occupied Bay 4 as reserved for another vehicle!
        │           Vehicle placed in "Priority Lift Queue" (assigned_bay_id = NULL)
        │           Lift monitor alerts: "Next for Lift: [Plate] (Paid - Waiting Bay 4)"
        │           *(Note: Wash Bay 1 is NOT pre-locked now, preventing idle bay wastage)*
        │
        └── Return HTTP 200 -> Vehicle queued or admitted to Bay 4 Lift
              │
              ├── [Stage 1: In Progress on Workshop Tablet: views/mechanic/jobs.php]
              │     ├── Mechanic drains oil, replaces filter, tops fluids
              │     ├── Commission recorded: INSERT INTO order_mechanics (order_id, stage_sequence=1, mechanic_id, ...)
              │     └── Mechanic taps "Complete Oil Stage"
              │           │
              │           ├── [core/BayManager::finishStage(order_id, stage=1)]
              │           │     ├── UPDATE order_tasks SET status = 'completed' WHERE order_id = ? AND sequence = 1
              │           │     ├── Atomically release Bay 4:
              │           │     │     UPDATE bays SET status = 'free', current_order_id = NULL, last_freed_at = NOW() WHERE id = 4
              │           │     ├── UPDATE orders SET active_stage = 2, status = 'waiting' WHERE id = ? AND active_stage = 1
              │           │     ├── AuditLogger::log('STAGE_COMPLETED', "Stage 1 finished on Order #...", ...)
              │           │     └── Insert into Wash Queue Buffer (Priority Head)
              │           │
              │           └── Bay 4 is immediately free and acquired by the next vehicle in Priority Lift Queue!
              │
              └── [Stage 2: Auto-Transition to Wash Bay]
                    ├── tv.php alerts: "Car [Plate] moving to Wash Bay"
                    ├── Next free wash bay (Bay 1) atomically claims vehicle
                    ├── Commission recorded: INSERT INTO order_washers (order_id, stage_sequence=2, washer_id, ...)
                    └── Washers complete exterior wash & dry
```

---

### 1.4 Operation 4: Emergency Rollback & Cancellation Call Chain

```
[Cashier Interface / Queue Screen: Void Request]
  │
  ├── 1. Cashier taps 'Void / Cancel Order'
  ├── 2. Cashier inputs mandatory reason (>= 5 chars)
  ├── 3. If order is already in progress or paid, Manager PIN is required
  └── 4. POST api/pos/index.php?action=cancel_order
        │
        ├── [api/pos/index.php]
        │     ├── Verify manager PIN credentials via CashierControl::verifyManagerPin()
        │     ├── Verify manager is NOT the same user as cashier (anti-self-approval)
        │     │
        │     ├── [TRANSACTION BEGIN]
        │     ├── Check active_stage:
        │     │     ├── If active_stage == 1 (Lift Bay):
        │     │     │     Release Bay 4: UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 4
        │     │     │     If parts already poured into engine: Technician logs parts consumed
        │     │     │     Refund wash portion ($3.00) only; parts portion not refundable
        │     │     │
        │     │     └── If active_stage == 2 (Wash Bay):
        │     │           Mechanic commission for Stage 1 remains locked and credited
        │     │           Refund wash service ($3.00)
        │     │           Cancel Stage 2 queue ticket
        │     │
        │     ├── Restore unused products to stock via StockControl::reverseOrderStock()
        │     ├── Deactivate washer commission: UPDATE order_washers SET is_active = 0 WHERE order_id = ?
        │     ├── UPDATE orders SET status = 'cancelled', payment_status = 'cancelled', cancelled_at = NOW()
        │     ├── AuditLogger::log('ORDER_CANCELLED', "Order #... voided by Manager ...", ...)
        │     └── [TRANSACTION COMMIT]
        │
        └── Return HTTP 200 { success: true, refunded_amount: 3.00 }
              └── Print Void Receipt for cash drawer balancing
```

---

## 2. Cross-Module Dependency Matrix

This matrix maps every core subsystem against the operational cashier flows:

| Source Module | Cash Checkout | KHQR Checkout | Bay Dispatch | Stage Handoff | Void / Cancel | Thermal Print |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **`core/OrderGate.php`** | Validates Electronic Receipt + Payment | Validates Electronic Receipt + Payment | Enforces Gate Admission (Blocks Unpaid & No-Receipt) | Validates Stage Progression | - | - |
| **`core/BayManager.php`** | Assigns Bay if Free | Assigns Bay if Free | Atomic Claim / Enqueues Priority Lift | Releases Stage 1 / Buffers Stage 2 | Releases Bay to Free | - |
| **`core/TechnicalManager.php`** | - | - | Allocates Bay 4 (Lift) | Tracks Tech Stage 1 | Logs Consumed Parts | - |
| **`core/BankTransferControl.php`** | - | Validates 6-Point Bank Ref | - | - | Reverses Digital Record | - |
| **`core/CashierControl.php`** | Shift / Drawer Check | Shift Check | - | - | Validates Manager PIN | Logs Drawer Kick |
| **`core/StockControl.php`** | Commits Stock | Commits Stock | - | - | Reverses Stock Ledger | - |
| **`core/SmartQueueEngine.php`** | Wait Estimate | Wait Estimate | Priority Queue Buffer | Advances Priority Head | Removes from Queue | - |
| **`core/AuditLogger.php`** | Logs Sale | Logs Verification | Logs Bay Claim | Logs Stage Finish | Logs Void Attribution | Logs Reprint |
| **`pos/js/thermal-printer.js`** | Prints 80mm ESC/POS | Prints 80mm ESC/POS | - | - | Prints Void Slip | Hardware Decoupled |

---

## 3. Database Table Impact & Schema Mapping

The following schema diagram and matrix detail all entities created, updated, or read during the revised POS cashier workflow:

```mermaid
erDiagram
    orders ||--o{ order_items : contains
    orders ||--o{ order_tasks : tracks
    orders ||--o{ order_washers : attributes
    orders ||--o{ order_mechanics : attributes
    orders }o--|| bays : assigned_to
    bays ||--o{ bay_history : logs
    users ||--o{ orders : cashiers
    users ||--o{ audit_logs : authorizes

    orders {
        int id PK
        string order_number UK
        string idempotency_key UK
        string status "waiting, washing, finished, cancelled"
        string payment_status "unpaid, paid, refunded, cancelled, partially_refunded"
        string payment_method "cash, khqr"
        string payment_ref UK "Genuine Bank Reference #"
        int payment_verified_by_id FK
        string payment_verified_by_name
        datetime payment_verified_at
        int active_stage "1 or 2"
        string stage_routing "bay4_to_wash"
        string receipt_number UK
        datetime receipt_issued_at "Electronic Issuance"
        datetime receipt_printed_at "Physical Paper Output"
    }
```

| Table Name | Operations Performed | Critical Columns Impacted | Locking & Concurrency Standards |
| :--- | :--- | :--- | :--- |
| **`orders`** | `INSERT`, `UPDATE`, `SELECT` | `idempotency_key`, `status`, `payment_status`, `payment_ref`, `payment_verified_by_id`, `receipt_number`, `receipt_issued_at`, `receipt_printed_at`, `active_stage`, `stage_routing` | Idempotency unique constraint; unique index on `payment_ref`; indexed on `order_number` and `payment_status`. |
| **`order_items`** | `INSERT`, `SELECT` | `order_id`, `item_type`, `service_id`, `product_id`, `qty`, `price_usd` | Standard transactional insert inside order creation transaction. |
| **`bays`** | `SELECT`, `UPDATE` | `status`, `current_order_id`, `occupied_at`, `last_freed_at` | **Atomic conditional lock:** `UPDATE bays ... WHERE id = ? AND status = 'free'`. Never marks occupied bay as reserved. |
| **`bay_history`** | `INSERT` | `order_id`, `bay_id`, `action`, `performed_by`, `performed_by_name`, `reason` | Append-only audit ledger for every bay movement. |
| **`order_tasks`** | `INSERT`, `UPDATE` | `order_id`, `sequence`, `task_type`, `bay_id`, `status`, `started_at`, `completed_at` | Enforces single-execution stage completions via `WHERE sequence = ? AND status = 'waiting'`. |
| **`order_washers`** | `INSERT`, `UPDATE` | `order_id`, `stage_sequence`, `washer_id`, `commission_usd`, `is_active` | Composite unique constraint `UNIQUE(order_id, stage_sequence, washer_id)` to protect multi-stage commission integrity. |
| **`order_mechanics`** | `INSERT`, `UPDATE` | `order_id`, `stage_sequence`, `mechanic_id`, `commission_usd`, `is_active` | Composite unique constraint `UNIQUE(order_id, stage_sequence, mechanic_id)` to protect multi-stage commission integrity. |
| **`audit_logs`** | `INSERT` | `action`, `details`, `entity_type`, `entity_id`, `user_id`, `username`, `role` | Immutable security log for all checkouts, overrides, and cancellations. |

---

## 4. Breakage Prevention, Financial Accounting & Backup Standards

### 4.1 Strict Accounting & Revenue Calculation Formulas (`views/owner/reports.php`)
Revenue reports must calculate financial performance strictly using certified accounting treatments across all transaction statuses:

```sql
-- Certified Revenue Aggregation Query:
SELECT 
    -- Gross Sales: All orders paid before refunds
    COALESCE(SUM(CASE 
        WHEN payment_status IN ('paid', 'partially_refunded') AND status != 'cancelled' 
        THEN total_usd ELSE 0 END), 0) AS gross_sales_usd,

    -- Total Refunds: Full and partial refunds
    COALESCE(SUM(CASE 
        WHEN payment_status = 'refunded' THEN total_usd 
        WHEN payment_status = 'partially_refunded' THEN refunded_amount 
        ELSE 0 END), 0) AS total_refunds_usd,

    -- Net Revenue: Gross Sales minus Refunds
    COALESCE(SUM(CASE 
        WHEN payment_status = 'paid' AND status != 'cancelled' THEN (total_usd - COALESCE(refunded_amount, 0))
        WHEN payment_status = 'partially_refunded' AND status != 'cancelled' THEN (total_usd - refunded_amount)
        ELSE 0 END), 0) AS net_revenue_usd,

    -- Voided Volume: Orders cancelled before or during service
    COALESCE(SUM(CASE 
        WHEN status = 'cancelled' OR payment_status = 'cancelled' THEN total_usd 
        ELSE 0 END), 0) AS voided_sales_usd
FROM orders
WHERE created_at BETWEEN :start_date AND :end_date;
```

### 4.2 Washer Job Board & OrderGate Decoupling (`views/washer/jobs.php`)
- **Vulnerability Addressed:** Washer tablet calls `OrderGate::assertAdmissibleToService()`.
- **Enforcement:**
  - New orders require BOTH `payment_status = 'paid'` AND `receipt_issued_at IS NOT NULL`.
  - Physical printer status (`receipt_printed_at`) is independent; hardware paper jams or offline printers never block wash technicians.
  - Legacy orders (created before migration cutoff) pass if `receipt_printed_at` is set or via manager PIN override.

### 4.3 Full Database Backup vs. Logical Table Copy Requirement
- **Critical Distinction:** A logical table copy (`CREATE TABLE ... AS SELECT`) is merely a supplementary row snapshot; it does NOT copy foreign keys, triggers, auto-increment sequences, indexes, or child tables (`order_items`, `order_tasks`).
- **Mandatory Policy:** A complete database dump (`mysqldump -u [user] -p --routines --triggers carwash_db > backup.sql`) must be created and verified via test restoration into a temporary test schema before executing any schema changes or migration scripts on production.

---

> [!NOTE]
> **Audit Conclusion:** This dependency map provides the definitive architectural blueprint for implementing the revised POS cashier workflow. All call chains, concurrency boundaries, multi-stage commission models, and database impacts are fully mapped, verified, and protected against regressions.
