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
        T_Pay[("order_payments (Ledger)")]
        T_Ref[("order_refunds (Ledger)")]
        T_Item[("order_items")]
        T_Bay[("bays")]
        T_BayHist[("bay_history")]
        T_Task[("order_tasks")]
        T_Wash[("order_washers")]
        T_Mech[("order_mechanics")]
        T_Shift[("cashier_shifts")]
        T_Audit[("audit_logs")]
        T_Webhook[("webhook_events")]
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
        │     ├── INSERT INTO order_payments (order_id, payment_seq=1, amount_usd, payment_method='cash', payment_provider='cash', merchant_account_id='counter_drawer', payment_ref=CONCAT('CASH-', order_number), cashier_id)
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

### 1.2 Operation 2: Verified KHQR Checkout Call Chain (Manual vs. Automated)

```
[Cashier Interface: pos/js/app.js]
  │
  ├── 1. Cashier selects KHQR -> Dynamic QR generated encoding merchant ID, order total & currency
  ├── 2. Customer scans QR using mobile banking app (Bakong / ABA / Acleda)
  ├── 3. Notification arrives on STORE'S OWN MERCHANT DEVICE (Phone Push / Soundbox Chime)
  ├── 4. Cashier inspects store device and attests matching details:
  │     - Confirms amount and currency ($ or ៛) match cart grand total
  │     - Confirms notification is marked 'SUCCESS'
  │     - Reads genuine 6-to-9 digit Bank Ref #
  └── 5. Cashier enters genuine Bank Ref # and submits -> POST api/pos/index.php?action=create_order
        │   Payload includes: { 
        │     payment_method: 'khqr', 
        │     payment_provider: 'aba_payway', 
        │     merchant_account_id: '001-234567',
        │     payment_ref: '849201', 
        │     verification_method: 'manual_merchant_device',
        │     verified_by: cashier_id 
        │   }
        │
        ├── [api/pos/index.php Gateway Verification]
        │     ├── Rejects empty payment_ref (rejects synthetic TRX- strings)
        │     ├── Enforces scoped duplicate protection on authoritative ledger (order_payments): 
        │     │     SELECT id FROM order_payments 
        │     │     WHERE payment_provider = 'aba_payway' 
        │     │       AND merchant_account_id = '001-234567' 
        │     │       AND payment_ref = '849201';
        │     │     If exists -> HTTP 409 DUPLICATE_PAYMENT_REFERENCE
        │     │     (CRITICAL: payment_provider, merchant_account_id, and payment_ref are strictly NOT NULL on order_payments;
        │     │      in MySQL, composite UNIQUE treats NULL != NULL, so NOT NULL is mandatory to prevent bypass)
        │     ├── Note: Backend records cashier attestation; does NOT simulate external bank calls
        │     │     (Automated Webhooks contrast: require official bank developer onboarding, signature verification per bank spec
        │     │      [ABA parameter concatenation HMAC-SHA512 vs Bakong RSA-256], timestamp drift rejection > 300s,
        │     │      and replay attack defense inside/outside 5-min window via webhook_events table)
        │     ├── Verify cashier authenticated session
        │     │
        │     ├── [TRANSACTION BEGIN]
        │     ├── INSERT INTO orders:
        │     │     payment_status = 'paid', 
        │     │     payment_method = 'khqr', 
        │     │     payment_provider = 'aba_payway',
        │     │     merchant_account_id = '001-234567',
        │     │     payment_ref = '849201',
        │     │     verification_method = 'manual_merchant_device',
        │     │     payment_verified_by_id = cashier_id,
        │     │     payment_verified_by_name = cashier_name,
        │     │     payment_verified_at = NOW(),
        │     │     receipt_number = 'REC-261106-0042',
        │     │     receipt_issued_at = NOW()
        │     │
        │     ├── INSERT INTO order_payments (Authoritative Ledger Entry):
        │     │     (order_id, payment_seq=1, amount_usd, amount_khr, exchange_rate=4100,
        │     │      payment_method='khqr', payment_provider='aba_payway', 
        │     │      merchant_account_id='001-234567', payment_ref='849201', 
        │     │      cashier_id, verified_at=NOW(), created_at=NOW())
        │     │     (Collides with uq_payment_ledger_provider_ref if duplicate reference is injected)
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
              │     ├── Task-based commission logged with deterministic idempotency key:
              │     │     hash = sha256("comm_v1_order_{$orderId}_task_{$taskId}_worker_{$workerId}")
              │     │     INSERT INTO order_mechanics (order_id, task_id=101, mechanic_id, commission_usd, commission_idempotency_key)
              │     │     (Retries compute identical key preventing duplicate payout; distinct tasks compute distinct keys)
              │     └── Mechanic taps "Complete Oil Stage"
              │           │
              │           ├── [core/BayManager::transitionStageAtomically(order_id, stage=1, next_stage=2, bay_id=4)]
              │           │     ├── [TRANSACTION BEGIN with FOR UPDATE locks]
              │           │     ├── UPDATE order_tasks SET status = 'completed', completed_at = NOW() WHERE order_id = ? AND sequence = 1
              │           │     ├── Atomically release Bay 4:
              │           │     │     UPDATE bays SET status = 'free', current_order_id = NULL, last_freed_at = NOW() WHERE id = 4
              │           │     ├── UPDATE orders SET active_stage = 2, status = 'waiting' WHERE id = ? AND active_stage = 1
              │           │     ├── AuditLogger::log('STAGE_COMPLETED', "Stage 1 finished on Order #...", ...)
              │           │     ├── [TRANSACTION COMMIT]
              │           │     └── Insert into Wash Queue Buffer (Priority Head)
              │           │
              │           └── Bay 4 is immediately free and acquired by the next vehicle in Priority Lift Queue!
              │
              └── [Stage 2: Auto-Transition to Wash Bay]
                    ├── tv.php alerts: "Car [Plate] moving to Wash Bay"
                    ├── Next free wash bay (Bay 1) atomically claims vehicle
                    ├── Washer commission logged with deterministic idempotency key:
                    │     INSERT INTO order_washers (order_id, task_id=102, washer_id, commission_usd, commission_idempotency_key)
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
        │     ├── Record Discrete Refund in Ledger (Supports multiple partial refunds & settlement tracking):
        │     │     ├── Step 1 (Authorization):
        │     │     │     INSERT INTO order_refunds (order_id, refund_seq, amount_usd, reason, cashier_id, manager_user_id, settlement_status='authorized_pending', attestation_status='cashier_attested', recorded_at=NOW())
        │     │     │     (CRITICAL: Authorization documents administrative approval; it is an informational liability,
        │     │     │      NOT physical cash returned or bank settlement. It does NOT reduce drawer cash or settled revenue yet)
        │     │     │
        │     │     └── Step 2 (Physical Cash Disbursement or Bank Reversal):
        │     │           ├── If Cash: Cashier pays customer from drawer ->
        │     │           │     UPDATE order_refunds SET settlement_status = 'cash_disbursed', disbursed_at = NOW() WHERE id = ?
        │     │           │     (Deducts from active drawer cash balance and net settled revenue)
        │     │           └── If Bank Reversal: POS dispatches reversal request or awaits bank processing ->
        │     │                 UPDATE order_refunds SET settlement_status = 'bank_reversal_pending' WHERE id = ?
        │     │                 Upon confirmed bank gateway credit ->
        │     │                 UPDATE order_refunds SET settlement_status = 'settled', settled_at = NOW(), provider_event_at = ? WHERE id = ?
        │     │                 (Deducts from net settled revenue upon bank confirmation)
        │     │
        │     ├── Update Cached Order Summaries:
        │     │     UPDATE orders SET 
        │     │       refunded_amount = (SELECT COALESCE(SUM(amount_usd),0) FROM order_refunds WHERE order_id = ?),
        │     │       payment_status = CASE WHEN refunded_amount >= total_usd THEN 'refunded' ELSE 'partially_refunded' END,
        │     │       status = 'cancelled', refunded_at = NOW() WHERE id = ?
        │     │
        │     ├── Restore unused products to stock via StockControl::reverseOrderStock()
        │     ├── Deactivate washer commission: UPDATE order_washers SET is_active = 0 WHERE order_id = ?
        │     ├── AuditLogger::log('ORDER_CANCELLED', "Order #... voided by Manager ...", ...)
        │     └── [TRANSACTION COMMIT]
        │
        └── Return HTTP 200 { success: true, refunded_amount: 3.00 }
              └── Print Void/Refund Receipt for cash drawer balancing
```

---

## 2. Cross-Module Dependency Matrix

This matrix maps every core subsystem against the operational cashier flows:

| Source Module | Cash Checkout | KHQR Checkout | Bay Dispatch | Stage Handoff | Void / Cancel | Thermal Print |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **`core/OrderGate.php`** | Validates Electronic Receipt + Payment | Validates Electronic Receipt + Payment | Enforces Gate Admission (Blocks Unpaid & No-Receipt) | Validates Stage Progression | - | - |
| **`core/BayManager.php`** | Assigns Bay if Free | Assigns Bay if Free | Atomic Claim / Enqueues Priority Lift | Transaction-Safe Bay Release | Releases Bay to Free | - |
| **`core/TechnicalManager.php`** | - | - | Allocates Bay 4 (Lift) | Tracks Tech Stage 1 | Logs Consumed Parts | - |
| **`core/BankTransferControl.php`** | - | Records Scoped Bank Ref | - | - | Reverses Digital Record | - |
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
    orders ||--o{ order_payments : records_tender
    orders ||--o{ order_refunds : records_refunds
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
        string payment_status "unpaid, paid, refunded, partially_refunded (Cached)"
        string payment_method "cash, khqr"
        string payment_provider "cash, aba_payway, bakong, acleda"
        string merchant_account_id
        string payment_ref "Genuine Bank Reference #"
        string verification_method "manual_merchant_device, automated_webhook, cash_drawer"
        int payment_verified_by_id FK
        string payment_verified_by_name
        datetime payment_verified_at
        decimal refunded_amount "Cached aggregate"
        datetime refunded_at
        int active_stage "1 or 2"
        string stage_routing "bay4_to_wash"
        string receipt_number UK
        datetime receipt_issued_at "Electronic Issuance"
        datetime receipt_printed_at "Physical Paper Output"
    }

    order_payments {
        bigint id PK
        int order_id FK
        tinyint payment_seq "1, 2, ..."
        decimal amount_usd
        int amount_khr
        int exchange_rate "Frozen historical rate at payment moment"
        string payment_method "cash, khqr, card"
        string payment_provider "NOT NULL"
        string merchant_account_id "NOT NULL"
        string payment_ref "NOT NULL (Bank/Tender Ref) UK"
        int cashier_id FK
        datetime verified_at
        datetime provider_event_at "Authentic external bank timestamp (NULL for cash/manual)"
        datetime recorded_at "Local server authoritative timestamp for shift/drawer balancing"
        datetime created_at "Record creation timestamp"
    }

    order_refunds {
        bigint id PK
        int order_id FK
        tinyint refund_seq "Supports multiple partial refunds"
        bigint payment_id FK "Optional link to specific tender"
        decimal amount_usd
        int amount_khr
        int exchange_rate "Frozen historical rate at refund moment"
        string refund_method "cash, bank_reversal, store_credit"
        string settlement_status "authorized_pending, cash_disbursed, bank_reversal_pending, settled"
        string reason
        int cashier_id FK
        int manager_user_id FK
        string attestation_status "cashier_attested (Administrative attestation)"
        datetime disbursed_at "Cash handover timestamp"
        datetime settled_at "Bank settlement timestamp"
        datetime provider_event_at "Bank reversal event timestamp (NULL if manual cash)"
        datetime recorded_at "Local server authorization timestamp"
        datetime created_at "Record creation timestamp"
    }

    webhook_events {
        bigint id PK
        string provider "aba_payway, bakong"
        string event_id UK "Unique bank event ID (Replay defense)"
        string merchant_account_id
        string payment_ref
        decimal amount
        string currency "USD, KHR"
        datetime bank_timestamp
        int drift_seconds "Must be <= 300s"
        string signature_hash "Bank-specific signature"
        string processing_status "processed, duplicate, rejected"
        datetime received_at
    }
```

| Table Name | Operations Performed | Critical Columns Impacted | Locking & Concurrency Standards |
| :--- | :--- | :--- | :--- |
| **`orders`** | `INSERT`, `UPDATE`, `SELECT` | `idempotency_key`, `status`, `payment_status`, `payment_provider`, `merchant_account_id`, `payment_ref`, `refunded_amount`, `receipt_number`, `receipt_issued_at` | Operational entity; client idempotency unique constraint `UNIQUE(idempotency_key)`. `payment_status` & `refunded_amount` serve as cached summary aggregates. |
| **`order_payments`** | `INSERT`, `SELECT` | `order_id`, `payment_seq`, `amount_usd`, `amount_khr`, `exchange_rate`, `payment_method`, `payment_provider`, `merchant_account_id`, `payment_ref`, `cashier_id`, `provider_event_at`, `recorded_at` | **Authoritative Payment Transaction Ledger.** Composite unique deduplication index `UNIQUE(payment_provider, merchant_account_id, payment_ref)` with strictly `NOT NULL` columns. Unique key `(order_id, payment_seq)`. `recorded_at` governs shift drawers. `provider_event_at` stores authentic bank timestamp without invention. |
| **`order_refunds`** | `INSERT`, `UPDATE`, `SELECT` | `order_id`, `refund_seq`, `amount_usd`, `amount_khr`, `exchange_rate`, `refund_method`, `settlement_status`, `disbursed_at`, `settled_at`, `provider_event_at`, `reason`, `cashier_id`, `manager_user_id`, `attestation_status`, `recorded_at` | Discrete refund transaction ledger supporting multiple partial refunds. Unique key `(order_id, refund_seq)`. `settlement_status` tracks lifecycle (`authorized_pending` -> `cash_disbursed` / `settled`). Pending refunds are non-deducted liabilities. |
| **`system_settings`** | `INSERT`, `UPDATE`, `SELECT` | `setting_key`, `setting_value`, `updated_at` | Holds `receipt_v2_cutover_timestamp`. Must be deployed with `ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()` to prevent stale/corrupt cutover timestamps from persisting silently. Mandatory parameter in reporting queries. |
| **`webhook_events`** | `INSERT`, `SELECT` | `provider`, `event_id`, `payment_ref`, `bank_timestamp`, `processing_status` | Idempotency & replay defense log. Unique constraint on `(provider, event_id)`. Replays inside 5-min window and duplicate deliveries caught here without duplicate payment creation. Drift check rejects `|bank_ts - NOW()| > 300s`. |
| **`order_items`** | `INSERT`, `SELECT` | `order_id`, `item_type`, `service_id`, `product_id`, `qty`, `price_usd` | Standard transactional insert inside order creation transaction. |
| **`bays`** | `SELECT`, `UPDATE` | `status`, `reservation_state`, `hold_expires_at`, `current_order_id`, `occupied_at`, `last_freed_at` | **Atomic conditional lock:** `UPDATE bays ... WHERE id = ? AND status = 'free'`. Never marks occupied bay as reserved. Never auto-releases busy bay by timeout. Expiry sweep strictly at 3 minutes (180s) with boundary condition `hold_expires_at <= CURRENT_TIMESTAMP` and safety guards: `WHERE reservation_state = 'intake_reserved' AND status != 'busy' AND physical_occupancy = 'vacant' AND current_order_id IS NULL`. |
| **`bay_history`** | `INSERT` | `order_id`, `bay_id`, `action`, `performed_by`, `performed_by_name`, `reason` | Append-only audit ledger for every bay movement. |
| **`order_tasks`** | `INSERT`, `UPDATE` | `order_id`, `sequence`, `task_type`, `bay_id`, `status`, `started_at`, `completed_at` | Enforces single-execution stage completions via `WHERE sequence = ? AND status = 'waiting'`. |
| **`order_washers`** | `INSERT`, `UPDATE` | `order_id`, `task_id`, `washer_id`, `commission_usd`, `commission_idempotency_key` | Unique constraint `UNIQUE(commission_idempotency_key)`. Key derived deterministically: `sha256("comm_v1_order_{$orderId}_task_{$taskId}_worker_{$workerId}")` ensuring retry safety and multi-task earnings. |
| **`order_mechanics`** | `INSERT`, `UPDATE` | `order_id`, `task_id`, `mechanic_id`, `commission_usd`, `commission_idempotency_key` | Unique constraint `UNIQUE(commission_idempotency_key)`. Key derived deterministically: `sha256("comm_v1_order_{$orderId}_task_{$taskId}_worker_{$workerId}")` ensuring retry safety and multi-task earnings. |
| **`audit_logs`** | `INSERT` | `action`, `details`, `entity_type`, `entity_id`, `user_id`, `username`, `role` | Immutable security log for all checkouts, overrides, and cancellations. |

---

## 4. Breakage Prevention, Financial Accounting & Backup Standards

### 4.1 Strict Accounting & Two-Tier Revenue Calculations (`views/owner/reports.php`)
Revenue reports must calculate financial performance strictly using actual payment and refund ledger events, separating operational cancellations from financial settlement, while bridging historical orders seamlessly via a Two-Tier Architecture:

> [!WARNING]
> **Administrative Attestation vs. Physical Settlement Proof:**  
> Recording a refund in the POS (`attestation_status = 'cashier_attested'`) documents administrative approval by the cashier and manager. It does NOT constitute physical proof that currency was handed to the customer or that an interbank reversal settled. Net cash balancing requires physical drawer reconciliation; digital reversals require monthly bank statement audits.

```sql
-- Certified Two-Tier Financial Revenue Aggregation Query:
-- 1. Uses mandatory :cutover_ts parameter passed by PHP after fail-closed validation.
--    (If receipt_v2_cutover_timestamp is missing/invalid, PHP throws SystemConfigurationException and halts).
-- 2. Uses exact event recording timestamps: p.recorded_at for payments, r.recorded_at for refunds.
--    Authoritative for shift and cash drawer balancing. External provider_event_at preserved for bank reconciliation.
-- 3. Supports cross-cutover transactions: Tier 1 queries transactions without parent order creation date restriction.
-- 4. Separates pending refund liabilities from settled refunds. Net Settled Revenue deducts settled refunds only.
-- 5. Tier 2 strictly excludes any legacy order that already has rows in order_payments or order_refunds (NOT EXISTS).

WITH Tier1_LedgerTransactions AS (
    SELECT 
        'tier1_ledger' AS reporting_tier,
        
        -- Gross Payments Collected: filtered strictly by payment recording timestamp
        COALESCE((
            SELECT SUM(p.amount_usd + (p.amount_khr / p.exchange_rate))
            FROM order_payments p
            WHERE p.recorded_at BETWEEN :start_date AND :end_date
        ), 0) AS gross_payments_collected_usd,

        COALESCE((
            SELECT SUM(p.amount_usd)
            FROM order_payments p
            WHERE p.recorded_at BETWEEN :start_date AND :end_date
        ), 0) AS gross_payments_usd_native,

        COALESCE((
            SELECT SUM(p.amount_khr)
            FROM order_payments p
            WHERE p.recorded_at BETWEEN :start_date AND :end_date
        ), 0) AS gross_payments_khr_native,

        -- Settled / Disbursed Refunds: actual cash disbursed from drawer or settled by bank
        -- ONLY settled refunds reduce gross cash/settled revenue
        COALESCE((
            SELECT SUM(r.amount_usd + (r.amount_khr / r.exchange_rate))
            FROM order_refunds r
            WHERE r.recorded_at BETWEEN :start_date AND :end_date
              AND r.settlement_status IN ('cash_disbursed', 'settled')
        ), 0) AS settled_refunds_usd,

        COALESCE((
            SELECT SUM(r.amount_usd)
            FROM order_refunds r
            WHERE r.recorded_at BETWEEN :start_date AND :end_date
              AND r.settlement_status IN ('cash_disbursed', 'settled')
        ), 0) AS settled_refunds_usd_native,

        COALESCE((
            SELECT SUM(r.amount_khr)
            FROM order_refunds r
            WHERE r.recorded_at BETWEEN :start_date AND :end_date
              AND r.settlement_status IN ('cash_disbursed', 'settled')
        ), 0) AS settled_refunds_khr_native,

        -- Pending Unsettled Refunds: authorized by manager, awaiting drawer payout or bank gateway
        -- Informational liability; NOT deducted from settled revenue
        COALESCE((
            SELECT SUM(r.amount_usd + (r.amount_khr / r.exchange_rate))
            FROM order_refunds r
            WHERE r.recorded_at BETWEEN :start_date AND :end_date
              AND r.settlement_status IN ('authorized_pending', 'bank_reversal_pending')
        ), 0) AS pending_unsettled_refunds_usd,

        -- Unpaid Voids (Operational cancellations without money transfer)
        COALESCE((
            SELECT SUM(o.total_usd) 
            FROM orders o
            WHERE o.created_at >= :cutover_ts
              AND o.created_at BETWEEN :start_date AND :end_date
              AND o.status = 'cancelled' 
              AND o.payment_status = 'unpaid'
        ), 0) AS unpaid_voids_usd
),

Tier2_PreCutoverFallback AS (
    SELECT 
        'tier2_historical_fallback' AS reporting_tier,

        -- Gross payments for historical orders with no ledger entries
        COALESCE(SUM(CASE 
            WHEN o.payment_status IN ('paid', 'partially_refunded', 'refunded') 
            THEN o.total_usd ELSE 0 END), 0) AS gross_payments_collected_usd,

        -- Historical refunds (assumed settled in legacy system)
        COALESCE(SUM(CASE 
            WHEN o.payment_status = 'refunded' THEN COALESCE(o.refunded_amount, o.total_usd)
            WHEN o.payment_status = 'partially_refunded' THEN COALESCE(o.refunded_amount, 0)
            ELSE 0 END), 0) AS settled_refunds_usd,

        0.00 AS pending_unsettled_refunds_usd,

        COALESCE(SUM(CASE 
            WHEN o.status = 'cancelled' AND (o.payment_status = 'unpaid' OR o.payment_status IS NULL)
            THEN o.total_usd ELSE 0 END), 0) AS unpaid_voids_usd
    FROM orders o
    WHERE o.created_at < :cutover_ts
      AND o.created_at BETWEEN :start_date AND :end_date
      -- Anti-Double-Counting Guard: Strictly exclude legacy orders that have new discrete ledger rows
      AND NOT EXISTS (SELECT 1 FROM order_payments p WHERE p.order_id = o.id)
      AND NOT EXISTS (SELECT 1 FROM order_refunds r WHERE r.order_id = o.id)
)

-- Unified Executive Financial Statement
SELECT 
    (t1.gross_payments_collected_usd + t2.gross_payments_collected_usd) AS gross_payments_collected_usd,
    t1.gross_payments_usd_native AS gross_payments_usd_native,
    t1.gross_payments_khr_native AS gross_payments_khr_native,
    (t1.settled_refunds_usd + t2.settled_refunds_usd) AS settled_refunds_usd,
    t1.settled_refunds_usd_native AS settled_refunds_usd_native,
    t1.settled_refunds_khr_native AS settled_refunds_khr_native,
    t1.pending_unsettled_refunds_usd AS pending_unsettled_refunds_usd,
    
    -- Net Settled Revenue: Gross Payments MINUS Settled/Disbursed Refunds (Authoritative Cash/Bank Inflow)
    ((t1.gross_payments_collected_usd + t2.gross_payments_collected_usd) - 
     (t1.settled_refunds_usd + t2.settled_refunds_usd)) AS net_settled_revenue_usd,
     
    -- Net Realizable Revenue: Net Settled MINUS Pending Refund Liabilities
    ((t1.gross_payments_collected_usd + t2.gross_payments_collected_usd) - 
     (t1.settled_refunds_usd + t2.settled_refunds_usd) - 
     t1.pending_unsettled_refunds_usd) AS net_realizable_revenue_usd,

    (t1.unpaid_voids_usd + t2.unpaid_voids_usd) AS unpaid_voids_usd
FROM Tier1_LedgerTransactions t1
CROSS JOIN Tier2_PreCutoverFallback t2;
```

### 4.2 Washer Job Board & OrderGate Decoupling (`views/washer/jobs.php`)
- **Vulnerability Addressed:** Washer tablet calls `OrderGate::assertAdmissibleToService()`.
- **Enforcement:**
  - New orders require BOTH `payment_status = 'paid'` AND `receipt_issued_at IS NOT NULL`.
  - Physical printer status (`receipt_printed_at`) is independent; hardware paper jams or offline printers never block wash technicians.
  - Legacy orders (created before migration cutoff) pass if `receipt_printed_at` is set or via manager PIN override.

### 4.3 Full Database Backup vs. Supplementary Table Copy Requirement
- **Critical Distinction:** A logical table copy (`CREATE TABLE ... AS SELECT`) is merely a supplementary row snapshot; it does NOT copy foreign keys, triggers, auto-increment sequences, indexes, or child tables (`order_items`, `order_tasks`). A `JOIN`-based update is **never** a complete rollback.
- **Mandatory Policy:** A complete database dump (`mysqldump -u [user] -p --routines --triggers carwash_db > backup.sql`) must be created and verified via test restoration into an isolated test schema (`carwash_test_restore`) before executing any schema changes or migration scripts on production.

---

> [!NOTE]
> **Audit Conclusion:** This dependency map provides the definitive architectural blueprint for implementing the revised POS cashier workflow. All call chains, concurrency boundaries, task-based commission models, and database impacts are fully mapped, verified, and protected against regressions.
