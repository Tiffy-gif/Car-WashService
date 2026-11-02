# SYNC_MAPPING: Cloud Master Data to Local Shop Schema

This document details the exact field-by-field mapping between the **Car-WashCloud Master Data** and the **Car-WashService Local Shop Database**.

---

## 1. Catalog Items Mapping (`catalog_items` -> `services` / `service_prices` / `products`)

Cloud provides a unified `catalog_items` master catalog with an `item_type` discriminator (`service`, `product`, `repair`, `spare_part`).

| Cloud Field (`catalog_items`) | Local Table & Field | Data Type | Notes |
| :--- | :--- | :--- | :--- |
| `id` | (Reference ID / Cloud SKU) | INT | Stored as SKU / tracking reference. |
| `sku` | `services.description` / `products.brand` | VARCHAR | Used for unique item identification. |
| `name_en` | `services.name_en` / `products.name_en` | VARCHAR(100) | Primary English title. |
| `name_km` | `services.name_km` / `products.name_km` | VARCHAR(100) | Primary Khmer title. |
| `category_name` | `services.category` / `products.category` | VARCHAR(50) | Slug/category name. |
| `category_id` | `services.category_id` / `products.category_id` | INT | Resolved from local `categories.id`. |
| `item_type` | Table router | ENUM | `'service'` -> `services`; `'product'`/`'spare_part'` -> `products`. |
| `price_usd` | `service_prices.price_usd` / `products.price_usd` | DECIMAL(10,2) | For services: baseline small car price. |
| `price_khr` | `service_prices.price_khr` / `products.price_khr` | INT | KHR converted price. |
| `cost_usd` | `products.cost_price_usd` | DECIMAL(10,2) | Product wholesale unit cost. |
| `service_duration_min` | `services.base_duration_minutes` | INT | Standard labor minutes for service. |
| `is_active` | `services.is_active` / `products.is_active` | TINYINT(1) | **Deactivate only (never hard delete).** |
| `version` | Version conflict check | INT | Checked against local version for conflict detection. |

> [!NOTE]
> Local `products.stock_qty` and `products.reserved_qty` are strictly managed by local inventory stock movements and are **NEVER** overwritten by catalog pulls.

---

## 2. Staff Accounts Mapping (`users` -> `users`)

| Cloud Field (`users`) | Local Field (`users`) | Data Type | Rules & Sanitization |
| :--- | :--- | :--- | :--- |
| `id` | (Cloud User ID) | INT | Reference only. |
| `username` | `users.username` | VARCHAR(50) | Unique key lookup. |
| `full_name` | `users.full_name` | VARCHAR(100) | Display name. |
| `role` | `users.role` + `users.role_id` | VARCHAR / INT | Role string is mapped to local `roles.id` and `roles.code`. |
| `local_pin_hash` | `users.local_pin_hash` + `users.password_hash` | VARCHAR(255) | **Cryptographic hash ONLY. Plaintext PINs are NEVER accepted or stored.** |
| `is_active` | `users.status` | ENUM | `1` maps to `'active'`; `0` maps to `'inactive'` (never delete). |
| `version` | Conflict verification | INT | Checked against local version. |

### Role Resolution Strategy:
- Look up matching role in `roles` table: `WHERE LOWER(code) = LOWER(:role) OR LOWER(name_en) = LOWER(:role)`.
- Standard role aliases: `'System Admin'` -> `'admin'`, `'Manager'` -> `'manager'`, `'Cashier'` -> `'cashier'`, `'Technician'` / `'Lead Tech'` -> `'mechanic'`, `'Washer'` -> `'washer'`.

---

## 3. Settings Mapping & Security Whitelist (`settings` -> `settings`)

To ensure shop security and local hardware independence, settings are strictly segregated into **Whitelisted Sync Keys** and **Strictly Forbidden Local Hardware Keys**.

### Whitelisted Keys (Synchronized from Cloud):
- `shop_name_en` / `shop_name`
- `shop_name_km`
- `shop_phone`
- `shop_address`
- `shop_email`
- `tax_pct` / `tax_rate_percent`
- `exchange_rate` / `exchange_rate_usd_khr`
- `receipt_header_en`, `receipt_header_km`
- `receipt_footer_en`, `receipt_footer_km`
- `default_oil_mileage`, `default_oil_months`
- `labor_hourly_rate_usd`
- `queue_rules` (bay routing and wash/oil duration allocations)
- Vehicle multipliers (`multiplier_small_car`, `multiplier_suv_pickup`, `multiplier_van_truck`, `multiplier_motorbike`)

### FORBIDDEN Keys (NEVER Overwritten by Cloud):
- `cloud_api_*` (`cloud_api_url`, `cloud_api_token`, `cloud_api_secret`, `cloud_shop_id`, `cloud_offline_mode`, `cloud_allow_insecure_tls`, `cloud_sync_enabled`, `cloud_sync_interval_mins`)
- `telegram_*` (`telegram_bot_token`, `telegram_chat_id`, `telegram_notifications_enabled`, etc.)
- Device keys & tokens (`pos_device_*`, `device_key`, `tv_display_code`)
- `demo_mode`
- Security secrets (`jwt_secret`, `session_*`, `cookie_*`)

---

## 4. Membership Tiers Mapping (`membership_tiers` -> `membership_tiers`)

| Cloud Field (`membership_tiers`) | Local Field (`membership_tiers`) | Data Type | Notes |
| :--- | :--- | :--- | :--- |
| `id` | `membership_tiers.id` / `tier_code` | INT / VARCHAR | Unique lookup. |
| `name_en` | `membership_tiers.name_en` | VARCHAR(100) | English tier title. |
| `monthly_fee_usd` | `membership_tiers.monthly_fee_usd` | DECIMAL(10,2) | Recurring monthly price. |
| `discount_pct` | `membership_tiers.discount_percent` | DECIMAL(5,2) | Automatic POS discount rate. |
| `included_wash_package` | `membership_tiers.included_services` | TEXT / JSON | Included service bundle. |
| `is_active` | `membership_tiers.is_active` | TINYINT(1) | Soft deactivation. |

---

## 5. Vehicle Technical Specs (`vehicle_specs` -> `vehicle_specs`)

| Cloud Field (`vehicle_specs`) | Local Field (`vehicle_specs`) | Data Type | Notes |
| :--- | :--- | :--- | :--- |
| `make` | `vehicle_specs.make` | VARCHAR(50) | Vehicle manufacturer (e.g. Toyota, Lexus). |
| `model` | `vehicle_specs.model` | VARCHAR(50) | Model and generation (e.g. Prius 2010-2015). |
| `engine_type` | `vehicle_specs.engine_type` | VARCHAR(50) | Engine specification. |
| `oil_viscosity` | `vehicle_specs.oil_viscosity` | VARCHAR(30) | Recommended grade (e.g. 0W-20, 5W-30). |
| `oil_type` | `vehicle_specs.oil_type` | VARCHAR(50) | Full synthetic, semi-synthetic, conventional. |
| `oil_capacity_liters` | `vehicle_specs.oil_capacity_liters` | DECIMAL(4,2) | Sump capacity for lube counter technicians. |
| `oil_filter_code` | `vehicle_specs.oil_filter_code` | VARCHAR(50) | OEM / aftermarket filter part number. |
| `other_fluids` | `vehicle_specs.other_fluids` | TEXT | Transmission, coolant, differential specs. |
| `is_active` | `vehicle_specs.is_active` | TINYINT(1) | Soft deactivation flag. |

---

## 6. Reminder Rules (`reminder_rules` -> `reminder_rules`)

| Cloud Field (`reminder_rules`) | Local Field (`reminder_rules`) | Data Type | Notes |
| :--- | :--- | :--- | :--- |
| `rule_type` | `reminder_rules.rule_type` | VARCHAR(50) | `'oil_change'`, `'wash_retention'`. |
| `trigger_mileage_before` | `reminder_rules.trigger_mileage_before`| INT | Km / miles before due threshold. |
| `trigger_days_before` | `reminder_rules.trigger_days_before` | INT | Days ahead notification window. |
| `trigger_days_inactive` | `reminder_rules.trigger_days_inactive` | INT | Customer retention inactivity trigger. |
| `message_template_en` | `reminder_rules.message_template_en` | TEXT | Template string with merge tags. |
| `message_template_km` | `reminder_rules.message_template_km` | TEXT | Khmer language template string. |
| `is_active` | `reminder_rules.is_active` | TINYINT(1) | Soft deactivation. |

---

## 7. Conflict Handling & Concurrency Rules

1. **Transaction Integrity**: The entire pull ingestion is wrapped in a database transaction (`$pdo->beginTransaction()` / `$pdo->commit()`). Any database error triggers a full rollback (`$pdo->rollBack()`).
2. **Never Delete**: Obsolete or removed cloud records are never deleted with `DELETE`; they are deactivated (`is_active = 0` or `status = 'inactive'`).
3. **Local Edit Protection**: If a local record was modified after the last pull cycle (`local_updated_at > last_pull_at`), the cloud version does **NOT** overwrite the local change. Instead:
   - A row is created in `sync_conflicts` with `status = 'pending'`, capturing `local_data` and `cloud_data`.
   - The local record remains untouched until a manager reviews the conflict on the Sync Monitor screen.
