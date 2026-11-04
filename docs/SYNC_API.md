# SYNC_API: Cloud Master to Offline Local Shop Server Protocol

This document provides the exact, complete specification for the synchronization connection between the **Car Wash & Oil Change Offline Local Server** and the **Cloud Management Master Server**.

Any local server AI agent, POS background daemon, or programmer can follow this specification to implement seamless offline-first synchronization.

---

## 1. Architecture Overview

```
 +----------------------------------+            +----------------------------------+
 |       OFFLINE LOCAL SERVER       |            |       CLOUD MASTER SERVER        |
 |      (Local Shop / Bays / POS)   |            |      (cPanel Shared Hosting)     |
 +----------------------------------+            +----------------------------------+
 | • Runs offline continuously      |            | • Master for items, prices, users|
 | • Collects sales & job orders    |            | • Receives shifts & Z-reports    |
 | • Deducts stock on oil changes   |            | • Watches business & audits logs |
 | • Initiates sync every 2-5 mins  |  HTTP POST | • Immutable audit & ledger logs  |
 | • Signs request with HMAC-SHA256 | ---------> | • Checks Nonce & Timestamps      |
 | • Pulls master catalog & settings| <--------- | • Flags version conflicts        |
 +----------------------------------+  Response  +----------------------------------+
```

1. **Direction**: The local shop server initiates all requests via HTTP/HTTPS POST to the Cloud Master.
2. **Offline-First Resilience**: If the internet is down, the local server queues all transactions locally in its database. When internet connectivity resumes, it transmits queued records in batches.
3. **Idempotency Guarantee**: Every local transaction contains a unique UUID (`client_id`). If an unstable connection causes a payload to be re-transmitted, the cloud server automatically ignores already-recorded entries without duplicating ledger amounts.
4. **All-or-Nothing Transaction Guarantee**: Ingestion operates inside a single atomic database transaction. If any entity fails during ingestion, all changes are rolled back, and the cloud returns `success: false` with no `confirmed_ids`.
5. **Master-Authoritative with Review Queue**: Master catalog items, prices, queue rules, and staff accounts originate on the cloud. If both sides update a master record simultaneously, the cloud halts the overwrite and flags the item in `sync_conflicts` for manager review.

---

## 2. Authentication, Compression & Anti-Replay Security

### 2.1 Request Headers

Every request sent by the local server must include the following HTTP headers:

| Header Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `X-Shop-Token` | String | Yes | The unique API token assigned to the local shop (e.g., `shop_pos_token_2026_xyz99`). |
| `X-Shop-Timestamp` | Integer | Yes | Current Unix epoch time in seconds (e.g., `1793167139`). Must be within **±300 seconds** (5 minutes) of Cloud Server time. |
| `X-Shop-Nonce` | String | Yes | Unique one-time random string (min 16 characters) generated fresh for every request. Replays are rejected with HTTP 409. |
| `X-Shop-Signature` | String | Yes | HMAC-SHA256 hex digest computed over `Timestamp + "." + Nonce + "." + RawBodyAsReceived` using shared `HMAC_SECRET`. |
| `Content-Type` | String | Yes | `application/json` (or `application/octet-stream` when gzipped). |
| `Content-Encoding` | String | Optional | Set to `gzip` if the request payload body is gzipped. |

### 2.2 Gzip Compression & HMAC Verification Order

The cloud sync endpoint supports optional **gzip compression** to reduce network bandwidth over mobile/cellular POS connections:

1. **Signing Order (Client)**: If gzip is enabled, the client **first** serializes the JSON, **then compresses** it using gzip (`gzencode`). The HMAC-SHA256 signature is **strictly computed on the raw gzipped binary bytes** as transmitted over the wire:
   $$\text{Signature} = \text{HMAC-SHA256}\Big(\text{Secret}, \ \text{Timestamp} + \text{"."} + \text{Nonce} + \text{"."} + \text{RawBodyAsReceived}\Big)$$
2. **Verification Order (Cloud)**: The cloud server calls `SyncSecurity::authenticateRequest` on the raw incoming bytes **BEFORE** decompression.
3. **Decompression (Cloud)**: Only after HMAC authentication passes, if `Content-Encoding: gzip` is present, the server decompresses the payload using `gzdecode` with a **10 MB decompressed limit** (`10,485,760 bytes`).
4. **Decompression Errors**: If decompression fails or exceeds 10 MB, the server immediately returns HTTP 400: `{"status": "error", "success": false, "code": 400, "message": "Invalid gzip body", "server_time": "..."}`.
5. **Plain JSON Support**: Plain uncompressed JSON payloads without `Content-Encoding: gzip` remain fully supported.

#### Reference PHP Signing Code (Plain & Gzip):
```php
$timestamp = (string)time();
$nonce = bin2hex(random_bytes(16));
$jsonBody = json_encode($payloadArray, JSON_UNESCAPED_UNICODE);

// Optional Gzip compression:
$useGzip = true;
if ($useGzip) {
    $rawBody = gzencode($jsonBody, 9);
    $contentEncoding = 'gzip';
} else {
    $rawBody = $jsonBody;
    $contentEncoding = 'identity';
}

// Compute signature over raw bytes exactly as received by cloud
$message = $timestamp . '.' . $nonce . '.' . $rawBody;
$signature = hash_hmac('sha256', $message, $hmacSecret);

$headers = [
    'Content-Type: application/json',
    'X-Shop-Token: ' . $shopToken,
    'X-Shop-Timestamp: ' . $timestamp,
    'X-Shop-Nonce: ' . $nonce,
    'X-Shop-Signature: ' . $signature
];
if ($useGzip) {
    $headers[] = 'Content-Encoding: gzip';
}
```

---

## 3. Endpoints

### 3.1 Bidirectional Sync Endpoint
- **URL**: `POST /api/sync.php`
- **Method**: `POST`
- **Purpose**: Pushes local sales, job orders, stock movements, shifts/z-reports, audit logs, CRM events, performance metrics, timecards, and upsell logs. Pulls updated master catalog items, users, prices, queue rules, and system settings.

---

## 4. Request Payload Schema

```json
{
  "action": "bidirectional",
  "push_data": {
    "sales": [
      {
        "client_id": "sale-loc-5501",
        "invoice_number": "INV-2026-5501",
        "customer_phone": "012888999",
        "cashier_name": "Sokha Chan",
        "total_amount_usd": 42.00,
        "total_amount_khr": 172200.00,
        "tax_amount_usd": 3.80,
        "discount_amount_usd": 0.00,
        "payment_method": "khqr_bank",
        "khqr_reference": "KHQR-FTB-8839219",
        "khqr_status": "verified",
        "status": "completed",
        "local_created_at": "2026-10-28 14:30:00",
        "items": [
          {
            "item_id": 4,
            "item_name": "Motul 8100 5W-30 Full Synthetic (4L)",
            "item_type": "product",
            "quantity": 1,
            "unit_price_usd": 38.00,
            "total_price_usd": 38.00
          },
          {
            "item_id": 3,
            "item_name": "Oil Change Labor Service",
            "item_type": "service",
            "quantity": 1,
            "unit_price_usd": 4.00,
            "total_price_usd": 4.00
          }
        ]
      }
    ],
    "job_orders": [
      {
        "client_id": "job-loc-7701",
        "order_number": "JOB-26-7701",
        "customer_name": "Oung Visal",
        "vehicle_plate": "2BD-9988",
        "vehicle_model": "Lexus RX350",
        "bay_number": "Bay 3 (Lube)",
        "technician_name": "Chea Veasna",
        "service_name": "Oil Change & Synthetic 5W-30",
        "status": "completed",
        "start_time": "2026-10-28 14:00:00",
        "end_time": "2026-10-28 14:25:00",
        "duration_minutes": 25,
        "efficiency_rating": "On Time",
        "notes": "Replaced filter and reset maintenance dashboard.",
        "local_created_at": "2026-10-28 14:00:00"
      }
    ],
    "stock_movements": [
      {
        "client_id": "sm-loc-9901",
        "item_id": 4,
        "movement_type": "service_consumption",
        "quantity": -1.00,
        "balance_after": 17.00,
        "unit_cost": 26.00,
        "reference_type": "job_order",
        "reference_id": "job-loc-7701",
        "notes": "Used 1 bottle Motul 5W30 for Lexus RX350",
        "created_by": "Chea Veasna"
      }
    ],
    "shifts_and_zreports": [
      {
        "client_id": "shift-loc-8801",
        "z_report_number": "Z-20261028-02",
        "register_name": "POS-1",
        "cashier_name": "Sokha Chan",
        "shift_start": "2026-10-28 08:00:00",
        "shift_end": "2026-10-28 17:00:00",
        "opening_cash_usd": 100.00,
        "opening_cash_khr": 400000.00,
        "expected_cash_usd": 420.00,
        "expected_cash_khr": 850000.00,
        "counted_cash_usd": 420.00,
        "counted_cash_khr": 850000.00,
        "variance_usd": 0.00,
        "variance_khr": 0.00,
        "status": "balanced",
        "notes": "Day shift closed balanced."
      }
    ],
    "audit_logs": [
      {
        "client_id": "aud-loc-1101",
        "username": "sokha",
        "role": "Cashier",
        "action": "SHIFT_CLOSE",
        "entity_type": "shifts",
        "entity_id": "shift-loc-8801",
        "details": "Counted cash drawer and printed physical Z-slip.",
        "created_at": "2026-10-28 17:01:00"
      }
    ],
    "crm_events": [
      {
        "client_id": "crm-evt-3301",
        "phone": "012888999",
        "full_name": "Oung Visal",
        "vehicle_plate": "2BD-9988",
        "vehicle_make": "Lexus",
        "vehicle_model": "RX350",
        "points_added": 42,
        "spend_added": 42.00,
        "visit_date": "2026-10-28",
        "oil_change_date": "2026-10-28",
        "next_oil_due": "2027-01-28"
      },
      {
        "event_type": "card_decline_sms",
        "event_uid": "rec-evt-9901",
        "customer_phone": "012999888",
        "customer_name": "Sok Chantha",
        "token_hash": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
        "raw_token": "a1b2c3d4e5f60718293a4b5c6d7e8f90",
        "token_expires_at": "2026-11-04 14:30:00",
        "attempt_count": 1,
        "event_timestamp": "2026-10-28 14:30:00",
        "notes": "Automated recovery SMS queued after recurring renewal decline."
      }
    ],
    "performance_metrics": [
      {
        "client_id": "perf-loc-1001",
        "username": "chea_veasna",
        "role": "Technician",
        "metric_date": "2026-10-28",
        "jobs_completed": 14,
        "cars_served": 12,
        "actual_minutes": 310.00,
        "standard_minutes": 360.00,
        "efficiency_ratio": 1.16,
        "labor_minutes": 290.00,
        "upsell_offered": 9,
        "upsell_accepted": 4
      }
    ],
    "timecards": [
      {
        "client_id": "tc-loc-2001",
        "username": "chea_veasna",
        "role": "Technician",
        "clock_in": "2026-10-28 07:55:00",
        "clock_out": "2026-10-28 17:05:00",
        "total_hours": 9.17,
        "hourly_rate_usd": 3.50,
        "notes": "Standard full shift."
      }
    ],
    "upsell_logs": [
      {
        "client_id": "up-loc-3001",
        "username": "sokha",
        "customer_phone": "012888999",
        "invoice_number": "INV-2026-5501",
        "item_name": "Hydrophobic Windshield Treatment",
        "offered_price_usd": 12.00,
        "accepted": 1,
        "notes": "Customer accepted add-on during checkout."
      }
    ]
  },
  "pull_request": {
    "entities": [
      "users",
      "catalog_items",
      "settings",
      "queue_rules",
      "membership_tiers",
      "vehicle_specs",
      "reminder_rules"
    ]
  },
  "conflict_checks": [
    {
      "entity_type": "catalog_items",
      "entity_id": "cat-prd-001",
      "local_version": 1,
      "proposed_data": {
        "price_usd": 40.00,
        "price_khr": 164000.00
      }
    }
  ]
}
```

---

## 5. Exact Field Specifications for Push Entities

The cloud requires specific field names for each entity inside `push_data`. Every entity requires a unique client-side idempotency identifier (`client_id`).

### 5.1 Sales Transactions (`push_data.sales`)
Append-only sales ledger. Idempotent by `client_id`.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | **Yes** | Unique local transaction UUID (idempotency key). |
| `invoice_number` | String(50) | No | Human-readable invoice number (e.g. `INV-2026-0001`). Default: `INV-{client_id}`. |
| `customer_phone` | String(50) | No | Customer phone number (used to auto-resolve `customer_id`). |
| `cashier_name` | String(100) | No | Staff member who completed the sale. Default: `Local Cashier`. |
| `total_amount_usd` | Decimal(10,2) | No | Total USD amount. Default: `0.00`. |
| `total_amount_khr` | Decimal(12,2) | No | Total KHR amount. Default: `0.00`. |
| `tax_amount_usd` | Decimal(10,2) | No | VAT/Tax amount in USD. Default: `0.00`. |
| `discount_amount_usd` | Decimal(10,2) | No | Discount given in USD. Default: `0.00`. |
| `payment_method` | String(30) | No | Payment method (`cash_usd`, `cash_khr`, `khqr_bank`, `credit_card`). Default: `cash_usd`. |
| `khqr_reference` | String(100) | No | KHQR bank reference code if paid via KHQR. |
| `khqr_status` | String(20) | No | KHQR status (`verified`, `pending`, `na`). Default: `na`. |
| `status` | String(20) | No | Sale status (`completed`, `refunded`, `voided`). Default: `completed`. |
| `local_created_at` | Datetime | No | Local transaction creation time. Default: current time. |
| `items` | Array | No | Nested line items breakdown (see below). |

#### Sale Line Items (`sales[].items[]`):
| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `item_id` | Integer | No | Cloud `catalog_items.id` if matched. |
| `item_name` | String(150) | **Yes** | Name of the service or product sold. |
| `item_type` | Enum | No | `'service'` or `'product'`. Default: `'service'`. |
| `quantity` | Decimal(10,2) | No | Quantity sold. Default: `1.00`. |
| `unit_price_usd` | Decimal(10,2) | No | Unit price in USD. Default: `0.00`. |
| `total_price_usd` | Decimal(10,2) | No | Total price in USD. Default: `0.00`. |

---

### 5.2 Job Orders (`push_data.job_orders`)
Bay queue & technician execution tracking. Idempotent by `client_id`.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | **Yes** | Unique local job order UUID (idempotency key). |
| `order_number` | String(50) | No | Local order number (e.g. `JOB-26-001`). Default: auto-generated. |
| `customer_name` | String(100) | No | Customer full name. Default: `'Walk-in'`. |
| `vehicle_plate` | String(50) | No | Vehicle license plate. Default: `'N/A'`. |
| `vehicle_model` | String(100) | No | Vehicle make/model. Default: `'N/A'`. |
| `bay_number` | String(20) | No | Bay assignment (e.g. `'Bay 1'`, `'Bay 3 (Lube)'`). Default: `'Bay 1'`. |
| `technician_name` | String(100) | No | Assigned technician. Default: `'Unassigned'`. |
| `service_name` | String(150) | No | Name of primary service performed. Default: `'General Wash'`. |
| `status` | Enum | No | `'queue'`, `'in_progress'`, `'completed'`, `'cancelled'`. Default: `'completed'`. |
| `start_time` | Datetime | No | Job start timestamp. |
| `end_time` | Datetime | No | Job completion timestamp. |
| `duration_minutes` | Integer | No | Actual duration in minutes. Default: `30`. |
| `efficiency_rating` | String(20) | No | Rating (`'Fast'`, `'On Time'`, `'Delayed'`). Default: `'On Time'`. |
| `notes` | String | No | Technician notes or findings. |
| `local_created_at` | Datetime | No | Timestamp recorded locally. |

---

### 5.3 Stock Movements (`push_data.stock_movements`)
Inventory audit trail. Idempotent by `client_id`. Updates cloud `inventory.current_stock`.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | **Yes** | Unique movement UUID (idempotency key). |
| `item_id` | Integer | **Yes** | Target catalog item ID in `catalog_items`. |
| `movement_type` | Enum | **Yes** | `'received'`, `'sale_deduction'`, `'service_consumption'`, `'manual_adjustment'`, `'discrepancy_writeoff'`. |
| `quantity` | Decimal(10,2) | **Yes** | Quantity delta (positive for received, negative for deductions). |
| `balance_after` | Decimal(10,2) | **Yes** | Stock balance after movement. Synchronized to cloud inventory. |
| `unit_cost` | Decimal(10,2) | No | Unit cost in USD at time of movement. Default: `0.00`. |
| `reference_type` | Enum | No | `'sale'`, `'job_order'`, `'manual_adjustment'`, `'po'`. Default: `'sale'`. |
| `reference_id` | String(64) | No | Associated `client_id` of sale or job order. |
| `notes` | String | No | Reason or memo for the movement. |
| `created_by` | String(50) | No | Username of staff member who initiated movement. |

---

### 5.4 Shifts & Z-Reports (`push_data.shifts_and_zreports`)
Cash register reconciliation & end-of-day Z-reports. Idempotent by `client_id`.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | **Yes** | Unique shift UUID (idempotency key). |
| `z_report_number` | String(50) | No | Z-Report identifier (e.g. `Z-20261028-01`). |
| `register_name` | String(50) | No | Register or workstation name (e.g. `'POS-1'`). Default: `'POS-1'`. |
| `cashier_name` | String(50) | **Yes** | Username/name of cashier closing shift. |
| `shift_start` | Datetime | **Yes** | Shift opening timestamp. |
| `shift_end` | Datetime | **Yes** | Shift closing timestamp. |
| `opening_cash_usd` | Decimal(10,2) | No | Opening drawer cash in USD. Default: `0.00`. |
| `opening_cash_khr` | Decimal(12,2) | No | Opening drawer cash in KHR. Default: `0.00`. |
| `expected_cash_usd` | Decimal(10,2) | No | System-calculated expected USD cash. Default: `0.00`. |
| `expected_cash_khr` | Decimal(12,2) | No | System-calculated expected KHR cash. Default: `0.00`. |
| `counted_cash_usd` | Decimal(10,2) | No | Physical counted USD cash. Default: `0.00`. |
| `counted_cash_khr` | Decimal(12,2) | No | Physical counted KHR cash. Default: `0.00`. |
| `variance_usd` | Decimal(10,2) | No | Shortage / overage variance in USD. Default: `0.00`. |
| `variance_khr` | Decimal(12,2) | No | Shortage / overage variance in KHR. Default: `0.00`. |
| `status` | Enum | No | `'balanced'`, `'shortage'`, `'overage'`. Default: `'balanced'`. |
| `notes` | String | No | Cashier or supervisor reconciliation notes. |

---

### 5.5 Local Audit Logs (`push_data.audit_logs`)
Terminal event audit log. Ingested into cloud `audit_logs` table prefixed with `[LOCAL_POS]`.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | No | Local log UUID (tracked in `confirmed_ids`). |
| `username` | String(50) | No | Staff username. Default: `'local_staff'`. |
| `role` | String(50) | No | Staff role. Default: `'Cashier'`. |
| `action` | String(50) | **Yes** | Action name (e.g. `'SHIFT_CLOSE'`, `'REFUND_ISSUED'`). |
| `entity_type` | String(50) | No | Affected entity type. Default: `'pos'`. |
| `entity_id` | String(64) | No | Affected entity ID. |
| `details` | String/Object | No | String or JSON-serializable details payload. |
| `created_at` | Datetime | No | Local log creation timestamp. |

---

### 5.6 CRM Events & Payment Recovery (`push_data.crm_events`)
Supports both **Customer Visit/Loyalty** updates and **Membership Payment Recovery** dunning events.

#### A. Customer Visit / Loyalty Event:
| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | No | Customer UUID (tracked in `confirmed_ids`). |
| `phone` | String(50) | **Yes** | Customer phone number (lookup & match key). |
| `full_name` | String(100) | No | Customer full name. Default: `'New Customer'`. |
| `vehicle_plate` | String(50) | No | Primary vehicle plate. |
| `vehicle_make` | String(50) | No | Vehicle make (e.g. `'Toyota'`). |
| `vehicle_model` | String(50) | No | Vehicle model (e.g. `'Prius'`). |
| `points_added` | Integer | No | Points to increment. Default: `0`. |
| `spend_added` | Decimal(10,2) | No | Spending in USD to increment. Default: `0.00`. |
| `visit_date` | Date | No | Visit date (`YYYY-MM-DD`). |
| `oil_change_date` | Date | No | Date oil was changed (if applicable). |
| `next_oil_due` | Date | No | Calculated next oil change due date. |

#### B. Card Decline Recovery Event (`event_type` present):
| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `event_type` | Enum | **Yes** | `'card_decline_sms'`, `'card_decline_reminder'`, or `'cancel_downsell_offer'`. |
| `event_uid` | String(64) | **Yes** | Unique recovery event tracking ID (idempotency key). |
| `customer_phone` | String(50) | **Yes** | Customer phone number for SMS notification. |
| `customer_name` | String(100) | No | Customer name for greeting. Default: `'Valued Customer'`. |
| `customer_membership_id`| Integer | No | Associated membership ID. |
| `token_hash` | String(64) | **Yes** | SHA-256 hash of single-use recovery token. |
| `raw_token` | String | No | Raw token used to build dynamic link if custom message omitted. |
| `token_expires_at` | Datetime | No | Token expiration timestamp. Default: +7 days. |
| `attempt_count` | Integer | No | Attempt number (1, 2, or 3). |
| `event_timestamp` | Datetime | No | Exact event time (preserved in `created_at`). |
| `next_action_at` | Datetime | No | Scheduled timestamp for next escalation/cancellation. |
| `downsell_tier_id` | Integer | No | Target downgrade tier ID for `cancel_downsell_offer`. |
| `message_body` | String | No | Custom rendered SMS text. If omitted, built automatically. |
| `notes` | String | No | Decline reason or POS notes. |

---

### 5.7 Performance Metrics (`push_data.performance_metrics`)
Staff & technician daily efficiency telemetry. Idempotent by `client_id` and unique `(shop_code, username, metric_date)`.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | **Yes** | Unique performance metric UUID (idempotency key). |
| `username` | String(50) | **Yes** | Staff member username. |
| `role` | String(50) | No | Staff role. Default: `'Technician'`. |
| `metric_date` | Date | **Yes** | Recorded date (`YYYY-MM-DD`). |
| `jobs_completed` | Integer | No | Completed jobs count. Default: `0`. |
| `cars_served` | Integer | No | Unique vehicles serviced. Default: `0`. |
| `actual_minutes` | Decimal(10,2) | No | Total recorded service labor minutes. Default: `0.00`. |
| `standard_minutes` | Decimal(10,2) | No | Standard benchmark minutes for completed work. Default: `0.00`. |
| `efficiency_ratio` | Decimal(6,2) | No | Ratio of standard to actual minutes. Default: `0.00`. |
| `labor_minutes` | Decimal(10,2) | No | Logged technician clock labor minutes. Default: `0.00`. |
| `upsell_offered` | Integer | No | Count of upsell attempts offered. Default: `0`. |
| `upsell_accepted` | Integer | No | Count of upsells accepted by customers. Default: `0`. |

---

### 5.8 Staff Timecards (`push_data.timecards`)
Append-only staff attendance and shift clock records. Idempotent by `client_id`. No deletes.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | **Yes** | Unique timecard UUID (idempotency key). |
| `shop_code` | String(30) | No | Shop code identifier. Default: authenticated shop code. |
| `username` | String(50) | **Yes** | Staff username who clocked in/out. |
| `role` | String(50) | No | Staff role at time of shift. Default: `'Technician'`. |
| `clock_in` | Datetime | **Yes** | Clock-in timestamp (`YYYY-MM-DD HH:MM:SS`). |
| `clock_out` | Datetime | No | Clock-out timestamp (`YYYY-MM-DD HH:MM:SS`). Null if shift active. |
| `total_hours` | Decimal(6,2) | No | Calculated total shift hours. Default: `0.00`. |
| `hourly_rate_usd` | Decimal(10,2) | No | Hourly pay rate in USD. Default: `0.00`. |
| `notes` | String | No | Shift notes, supervisor approval, or overtime remarks. |

---

### 5.9 Upsell Logs (`push_data.upsell_logs`)
Append-only upsell pitch telemetry. Idempotent by `client_id`. No deletes.

| Field Name | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `client_id` | String(64) | **Yes** | Unique upsell pitch UUID (idempotency key). |
| `shop_code` | String(30) | No | Shop code identifier. Default: authenticated shop code. |
| `username` | String(50) | **Yes** | Staff member who offered the upsell. |
| `customer_phone` | String(50) | No | Target customer phone number. |
| `invoice_number` | String(50) | No | Related invoice number if attached to a sale. |
| `item_name` | String(150) | **Yes** | Offered product or service name. |
| `offered_price_usd`| Decimal(10,2) | **Yes** | Offered price in USD. Default: `0.00`. |
| `accepted` | Boolean/Int | **Yes** | `1` (true) if customer accepted, `0` (false) if declined. |
| `notes` | String | No | Customer objections, promotional discount, or comments. |

---

## 6. Successful Response Schema (HTTP 200)

Every JSON response includes a boolean `"success"` field next to `"status"`, and the `"server_time"` timestamp. On success, `"confirmed_ids"` lists the `client_id` of every record inserted OR ignored as an idempotent duplicate across all 9 entities.

```json
{
  "status": "success",
  "success": true,
  "code": 200,
  "message": "Sync cycle completed successfully.",
  "shop_code": "SHOP_PP_01",
  "cloud_time": "2026-10-28 14:35:10",
  "server_time": "2026-10-28 14:35:10",
  "timestamp": 1793167139,
  "execution_ms": 28,
  "stats": {
    "sales_inserted": 1,
    "sales_ignored_duplicate": 0,
    "job_orders_inserted": 1,
    "job_orders_ignored_duplicate": 0,
    "stock_movements_inserted": 1,
    "stock_movements_ignored_duplicate": 0,
    "shifts_inserted": 1,
    "shifts_ignored_duplicate": 0,
    "audit_logs_inserted": 1,
    "crm_events_processed": 2,
    "performance_metrics_upserted": 1,
    "timecards_inserted": 1,
    "timecards_ignored_duplicate": 0,
    "timecards_rejected": 0,
    "upsell_logs_inserted": 1,
    "upsell_logs_ignored_duplicate": 0,
    "upsell_logs_rejected": 0,
    "records_rejected": 0,
    "conflicts_flagged": 0,
    "conflicts_details": []
  },
  "confirmed_ids": {
    "sales": [
      "sale-loc-5501"
    ],
    "job_orders": [
      "job-loc-7701"
    ],
    "stock_movements": [
      "sm-loc-9901"
    ],
    "shifts_and_zreports": [
      "shift-loc-8801"
    ],
    "audit_logs": [
      "aud-loc-1101"
    ],
    "crm_events": [
      "crm-evt-3301",
      "rec-evt-9901"
    ],
    "performance_metrics": [
      "perf-loc-1001"
    ],
    "timecards": [
      "tc-loc-2001"
    ],
    "upsell_logs": [
      "up-loc-3001"
    ]
  },
  "rejected": [
    {
      "entity": "sales",
      "index": 1,
      "reason": "Missing or invalid client_id"
    }
  ],
  "master_data": {
    "users": [
      {
        "id": 1,
        "username": "admin",
        "full_name": "System Administrator",
        "role": "System Admin",
        "local_pin": "9999",
        "local_pin_hash": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
        "is_active": 1,
        "version": 1,
        "updated_at": "2026-10-28 08:00:00"
      },
      {
        "id": 4,
        "username": "former_cashier",
        "full_name": "Former Staff Member",
        "role": "Cashier",
        "local_pin": null,
        "local_pin_hash": "a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00",
        "is_active": 0,
        "version": 2,
        "updated_at": "2026-11-02 10:15:00"
      }
    ],
    "catalog_items": [
      {
        "id": 1,
        "client_id": "cat-srv-001",
        "sku": "SRV-WASH-STD",
        "name_en": "Standard Foam Wash",
        "name_km": "លាងជម្រះស្នោស្តង់ដារ",
        "item_type": "service",
        "price_usd": "6.00",
        "price_khr": "24600.00",
        "service_duration_min": 25,
        "is_active": 1,
        "version": 1,
        "updated_at": "2026-10-28 08:00:00"
      },
      {
        "id": 8,
        "client_id": "cat-prd-099",
        "sku": "DISCONTINUED-WAX",
        "name_en": "Discontinued Paste Wax",
        "name_km": "ក្រមួនចាស់ឈប់ប្រើ",
        "item_type": "product",
        "price_usd": "12.00",
        "price_khr": "49200.00",
        "service_duration_min": 0,
        "is_active": 0,
        "version": 3,
        "updated_at": "2026-11-01 16:30:00"
      }
    ],
    "categories": [
      {
        "id": 1,
        "name_en": "Wash Packages",
        "name_km": "កញ្ចប់លាងរថយន្ត",
        "item_type": "service",
        "is_active": 1,
        "version": 1
      }
    ],
    "settings": {
      "shop_name": { "value": "Grand Diamond Car Care & Lube Center", "version": 1 },
      "tax_pct": { "value": "10.00", "version": 1 },
      "exchange_rate_usd_khr": { "value": "4100", "version": 1 }
    },
    "membership_tiers": [
      {
        "id": 1,
        "name_en": "Silver Member",
        "name_km": "សមាជិកប្រាក់",
        "is_active": 1,
        "version": 1
      }
    ],
    "vehicle_specs": [
      {
        "id": 1,
        "make": "Toyota",
        "model": "Prius (2010-2015)",
        "is_active": 1,
        "version": 1
      }
    ],
    "reminder_rules": [
      {
        "id": 1,
        "rule_type": "oil_change",
        "name_en": "Standard Oil Due Reminder",
        "is_active": 1,
        "version": 1
      }
    ]
  }
}
```

### 6.1 Master Data Lifecycle & Deactivated Entities (`is_active = 0`)

To ensure that changes on the Cloud Master are accurately reflected in the offline POS database:
- **Inactive Entities Included**: The Cloud Master returns both active (`is_active = 1`) and inactive (`is_active = 0`) rows for `users`, `categories`, `catalog_items`, `membership_tiers`, `vehicle_specs`, and `reminder_rules`.
- **Shop Server Synchronization**: The local shop server must update its local rows using the returned `is_active` flag. When a record arrives with `is_active = 0`, the shop must mark its corresponding local record as inactive (preventing deactivated staff from clocking in or logging in, and hiding discontinued services/products from the POS catalog).
- **Settings Active-Only**: The `settings` entity retains active-only configuration behavior and does not return deleted/inactive entries.
- **Credential Protection**: For security, staff user objects in `master_data.users` **never** expose cloud login passwords or `password_hash`. Only `local_pin_hash` (and legacy plaintext PIN if set) are transmitted for local POS lockscreen verification.

### 6.2 Rejected Records Protocol (`rejected`)

When pushed items in `push_data` omit or provide an invalid `client_id` (empty string or exceeding 64 characters):
- **Item-Level Rejection**: The record is skipped from database insertion without rolling back valid batch transactions.
- **Diagnostics in Response**: The response returns a `rejected` array containing details for every rejected record:
  - `entity`: The entity collection name (e.g., `sales`, `job_orders`, `timecards`, `upsell_logs`).
  - `index`: Zero-based array index of the item within the pushed entity array.
  - `reason`: Explanation of the rejection (e.g., `Missing or invalid client_id`).
- **Telemetry & Monitoring**: Rejections increment `stats.<entity>_rejected` and `stats.records_rejected`, are recorded in `sync_logs` telemetry, and are highlighted on the Cloud **Sync Monitor** dashboard (`sync_monitor.php`).

---

## 7. Error Responses

Every error response returns HTTP status $\ge 400$, `"status": "error"`, `"success": false`, and the `"server_time"`.

### 7.1 Invalid Gzip Payload Body (HTTP 400)
Returned when `Content-Encoding: gzip` was declared, but decompression failed or exceeded 10 MB:
```json
{
  "status": "error",
  "success": false,
  "code": 400,
  "message": "Invalid gzip body",
  "server_time": "2026-10-28 14:35:10",
  "timestamp": 1793167139
}
```

### 7.2 Replay Attack Blocked (Duplicate Nonce) (HTTP 409)
Returned when an incoming nonce was already seen within the active rolling security window:
```json
{
  "status": "error",
  "success": false,
  "code": 409,
  "message": "Replay attack blocked: One-time number (nonce) 'b39d102e88a...' has already been processed.",
  "server_time": "2026-10-28 14:35:10",
  "timestamp": 1793167150
}
```

### 7.3 Timestamp Skew / Expired Request (HTTP 403)
Returned when request timestamp differs by $> 300\text{ seconds}$ from cloud server time:
```json
{
  "status": "error",
  "success": false,
  "code": 403,
  "message": "Request rejected: Timestamp is outside the allowable tolerance window (max 300s skew). Current server time: 1793167150.",
  "server_time": "2026-10-28 14:35:10",
  "timestamp": 1793167150
}
```

### 7.4 Invalid HMAC Signature (HTTP 401)
Returned when token, timestamp, nonce, secret key, or body mismatch:
```json
{
  "status": "error",
  "success": false,
  "code": 401,
  "message": "Invalid request signature. HMAC verification failed.",
  "server_time": "2026-10-28 14:35:10",
  "timestamp": 1793167150
}
```

### 7.5 Database Rollback / Internal Server Error (HTTP 500)
Returned on an uncaught SQL or execution exception. Transaction rolls back completely; `confirmed_ids` is omitted:
```json
{
  "status": "error",
  "success": false,
  "code": 500,
  "message": "Internal sync processing failure: SQLSTATE[...]: Integrity constraint violation...",
  "server_time": "2026-10-28 14:35:10",
  "timestamp": 1793167150
}
```

---

## 8. Version Conflict Protocol

When the local server submits proposed changes to master data (catalog items, users, prices):
1. The local server provides the `local_version` it based its changes on.
2. The Cloud Master checks its current database version.
3. If `cloud.version !== local_version`:
   - The Cloud **STOPS** the overwrite immediately.
   - The record is preserved in its cloud state.
   - The difference is flagged in `sync_conflicts` for human manager review.
   - The response includes the flagged conflict details:
```json
{
  "status": "success",
  "success": true,
  "stats": {
    "conflicts_flagged": 1,
    "conflicts_details": [
      {
        "entity_type": "catalog_items",
        "entity_id": "cat-prd-001",
        "status": "flagged_for_manager_review",
        "cloud_version": 2,
        "local_version": 1,
        "message": "Conflict detected: Cloud item is at v2 while local proposed changes from v1. Not overwritten."
      }
    ]
  }
}
```
Managers can then log into the Cloud site's **Sync Monitor** page (`sync_monitor.php`) and choose **"Keep Cloud"** or **"Accept Local"**.
