# Car Wash Management System - Feature & Screen Inventory
**Document Reference**: `docs/user_guide/00_FEATURE_INVENTORY.md`  
**System Version**: Car-WashService v1.0.0 (Local Autonomous Shop Server)

This document provides a comprehensive inventory of every view, screen, interface, and specialized endpoint in the Car Wash & Detailing Management System. It maps each interface directly to its backend source file, required permission key, and authorized user roles based on the actual system codebase.

---

## 1. System Role Definitions

The system operates on an 11-role Granular Role-Based Access Control (RBAC) model:

| Role Code | Role Name (English) | Role Name (Khmer) | Primary Operational Scope |
| :--- | :--- | :--- | :--- |
| `owner` | Shop Owner | ម្ចាស់ហាង | Full administrative access across operations, finances, settings, reports, staff, and audit trails. |
| `manager` | General Manager | អ្នកចាត់ការទូទៅ | Daily shop floor oversight, queue reordering, override authorizations, spot audits, staff timecards, and operational reports. Strictly restricted from payroll, expenses, and security audit logs. |
| `cashier` | Cashier | បេឡាករ | Front-desk customer intake, POS checkout, cash drawer reconciliation, shift handover, receipt printing, and disaster recovery paperwork. Cannot cancel orders or issue refunds. |
| `washer` | Car Washer | បុគ្គលិកលាង | Dedicated washing bay screen, vehicle cleaning task timers, and personal washing commission tracking. |
| `wash_supervisor` | Wash Supervisor | ប្រធានត្រួតពិនិត្យការលាង | Washing bay assignment, queue reordering, crew assignment adjustments, and job bottleneck oversight. |
| `mechanic` | Mechanic & Technician | ជាងជួសជុល & បច្ចេកទេស | Service bay screen, repair tasks, 2-lane technical inspections, oil fluid adjustments, and warehouse parts requests. |
| `technical` | Technical Staff | ជាងបច្ចេកទេស | Workshop inspection checklists, job notes, vehicle service history, and parts requests. |
| `stock_control` | Stock Control (Warehouse) | អ្នកគ្រប់គ្រងស្តុក | Warehouse inventory, parts request approvals, physical stock discrepancy logs, and immutable stock movement ledger. |
| `crm` | CRM Coordinator | អ្នកសម្របសម្រួល CRM | Customer contact database, vehicle registry, service intervals, and oil reminder schedules. |
| `system_admin` | System Administrator | អ្នកគ្រប់គ្រងប្រព័ន្ធ | Technical IT maintenance: hardware POS devices, staff credential management, role permissions, daily backups, and security audit logs. Strictly blocked from financial figures and sales reports. |
| `customer` | Customer (TV Queue Only) | អតិថិជន (អេក្រង់ TV) | Read-only public waiting room TV display (`tv.php`). Blocked from back-office login. |

---

## 2. Back-Office Navigation & Screen Inventory

All back-office screens are dispatched through the centralized router `index.php?view=<view_key>`.

| View Key | Screen Label (English / Khmer) | Source File | Required Permission / Guard | Authorized Roles | Screen Purpose & Key Functions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `login` | Login<br>*(ចូលប្រើប្រព័ន្ធ)* | `views/auth/login.php` | Public / Unauthenticated | All staff | 6-digit PIN and password authentication screen. Blocks customer accounts, deactivated staff, and accounts locked after 5 failed attempts. |
| `change_pin` | Change PIN<br>*(ប្តូរលេខកូដសម្ងាត់)* | `views/auth/change_pin.php` | `must_change_pin = 1` | All staff flagged for PIN change | Mandatory PIN update screen for new accounts or temporary PIN resets. Enforces distinct 6-digit numerical PINs. |
| `dashboard` | Dashboard<br>*(ផ្ទាំងគ្រប់គ្រង)* | `views/owner/dashboard.php` | `dashboard.view` | `owner` | Executive business command center: live revenue (USD/KHR), active bays, today's customer volume, revenue breakdown charts, and low stock warnings. |
| `bays_queue` | Bays & Queue Board<br>*(បញ្ជីជួររង់ចាំ & បន្ទប់លាង)* | `views/owner/bays_queue.php` | `bays.view` or `orders.list` | `owner`, `manager`, `wash_supervisor`, `cashier`, `washer`, `mechanic` | Live shop floor board: visual bay occupancy, drag-and-drop queue reordering, vehicle arrival tagging, and wash/tech handshakes. |
| `pos` | POS Register<br>*(ផ្ទាំងលក់ / POS)* | `pos/index.html#pos`<br>*(Legacy fallback: `views/cashier/pos.php`)* | `pos.access` | `owner`, `manager`, `cashier` | Primary point-of-sale terminal: license plate lookup, service selection, vehicle size multiplier, wait-time estimate, upsell prompts, cash/KHQR payment, and receipt generation. |
| `shift` | Shifts & Cash Control<br>*(វេនការងារ និងផ្ទេរប្រាក់)* | `views/cashier/shift.php` | `pos.access` | `owner`, `manager`, `cashier` | Cash drawer control: opening change float (USD/KHR), blind shift closing cash count, variance/shortage calculation, and two-cashier handover. |
| `close_day` | Day Close & Z-Report<br>*(បិទបញ្ជីប្រចាំថ្ងៃ & របាយការណ៍ Z)* | `views/cashier/shift.php`<br>*(Tab: Close Day)* | `daily_close.close` | `owner`, `manager`, `cashier` | End-of-day reconciliation: formal Z-report generation, day-locking mechanism (preventing back-dated order tampering), and manager reopen overrides. |
| `orders` | Orders & Queue<br>*(បញ្ជីការងារ & ជួររង់ចាំ)* | `views/orders/index.php` | `orders.list` | `owner`, `manager`, `cashier`, `wash_supervisor` | Comprehensive order ledger: order status tracking (`waiting`, `washing`, `ready_for_tech`, `in_bay`, `ready_to_pay`, `completed`, `cancelled`), invoice reprints, and cancellation audit logs. |
| `receipt` | Order Receipt<br>*(វិក្កយបត្រ)* | `views/orders/receipt.php` | `pos.receipt` | `owner`, `manager`, `cashier` | Thermal 80mm receipt generation and reprinting interface displaying shop header, vehicle plate, itemized charges, dual-currency totals, and QR codes. |
| `manual_receipt` | Manual Emergency Receipt<br>*(វិក្កយបត្រក្រដាសអាសន្ន)* | `views/orders/manual_receipt.php` | Logged-in Staff | `owner`, `manager`, `cashier` | Standalone emergency printable ticket template for offline power outages, handwritten tracking, and manual disaster recovery. |
| `washer_jobs` | Washer Jobs<br>*(ការងារអ្នកលាង)* | `views/washer/jobs.php` | `washer_jobs.view` | `owner`, `manager`, `wash_supervisor`, `washer` | Tablet touch screen for wash bay crews: vehicle queue list, "Start Wash" and "Finish Wash" timers, and assigned wash team tracking. |
| `mechanic_jobs` | Mechanic Jobs<br>*(ការងារជាងជួសជុល)* | `views/mechanic/jobs.php` | `mechanic_jobs.view` | `owner`, `manager`, `wash_supervisor`, `mechanic`, `technical` | Tablet touch screen for technical bay: mechanical checklist, oil capacity and viscosity specs, actual oil quantity adjustments, and warehouse parts requests. |
| `oil_care` | Oil & Car Care<br>*(ប្តូរប្រេង & ថែទាំរថយន្ត)* | `views/owner/oil_care.php` | `oil_care.list` | `owner`, `manager`, `stock_control`, `cashier`, `crm` | Technical engine database: lookup vehicle make, model, year, oil viscosity (e.g. 0W-20), oil capacity in liters, and OEM filter reference numbers. |
| `stock_ledger` | Stock Ledger & History<br>*(សៀវភៅតាមដានស្តុកទំនិញ)* | `views/owner/stock_ledger.php` | Role check (`owner`, `manager`, `stock_control`, `system_admin`) | `owner`, `manager`, `stock_control`, `system_admin` | Immutable stock audit log: tracks all received goods, bay usages, sales deductions, manual stock adjustments, and physical discrepancy logs. |
| `extras` | Products & Inventory<br>*(មុខងារបន្ថែម & ស្តុក)* | `views/owner/extras.php` | `extras.list` | `owner`, `manager`, `stock_control`, `cashier` | Product and merchandise catalog: retail accessories, drinks, car care fluids, pricing, stock quantities, and low-stock reorder thresholds. |
| `services` | Services & Prices<br>*(សេវាកម្ម & តម្លៃ)* | `views/owner/services.php` | `services.list` | `owner`, `manager` | Master service catalog: labor duration in minutes, service descriptions, and tiered pricing matrix across Motorbike, Small Car, SUV/Pickup, and Van/Truck. |
| `categories` | Categories Control<br>*(ការគ្រប់គ្រងប្រភេទ)* | `views/owner/categories.php` | `categories.list` | `owner`, `manager`, `stock_control` | Category hierarchy: organizing catalog items into Wash, Repair, Oil Care, Products, and Expense categories with icons and color tags. |
| `customers` | Customers & Vehicles<br>*(អតិថិជន & យានយន្ត)* | `views/owner/customers.php` | `customers.list` | `owner`, `manager`, `cashier`, `crm` | Customer relationship database: customer profiles, phone numbers, registered license plates, membership tiers, loyalty points, and reminder preferences. |
| `vehicle_history` | Vehicle History<br>*(ប្រវត្តិជួសជុលយានយន្ត)* | `views/owner/vehicle_history.php` | `vehicle_history.list` | `owner`, `manager`, `wash_supervisor`, `mechanic`, `technical`, `cashier`, `crm` | Longitudinal vehicle service record: past visit dates, odometer readings, performed washes, oil change specifications, and technician notes. |
| `commissions` | Crew Commissions<br>*(កម្រៃជើងសារបុគ្គលិក)* | `views/owner/commissions.php` | `commissions.list` | `owner` | Payroll and incentive dashboard: calculates individual commission shares for washers and mechanics based on completed jobs and commission rates. |
| `expenses` | Daily Expenses<br>*(ចំណាយប្រចាំថ្ងៃ)* | `views/owner/expenses.php` | `expenses.list` | `owner` | Operational expense tracking: shop supplies, utilities, soap/wax chemicals, staff food, and petty cash payouts deducted from net profit. |
| `reports` | Reports & Profits<br>*(របាយការណ៍ & ប្រាក់ចំណេញ)* | `views/owner/reports.php` | `reports.view` | `owner`, `manager` | Analytics hub: Sales Summary, P&L, Cars per Labor Hour KPI, Bay Utilization KPI, Technician Efficiency Leaderboard, and Staff Timecards. |
| `staff` | Manage Staff<br>*(គ្រប់គ្រងបុគ្គលិក)* | `views/owner/staff.php` | `staff.list` | `owner`, `manager`, `system_admin` | Employee directory: adding staff, assigning roles, setting commission types (percentage/fixed), resetting PINs, and soft-deactivating accounts. |
| `roles` | Roles & Permissions<br>*(តួនាទី & សិទ្ធិ)* | `views/owner/roles.php` | `roles.list` | `owner`, `system_admin` | Security RBAC administration: granular matrix toggling permissions for sales, operations, warehouse, finance, and system settings per role. |
| `pos_devices` | POS Devices & API<br>*(ឧបករណ៍ POS & API)* | `views/owner/pos_devices.php` | `pos_devices.list` | `owner`, `system_admin` | Tablet hardware terminal management: pairing tablets, issuing SHA-256 API keys, monitoring active terminals, and blocking lost/stolen devices. |
| `settings` | Shop Settings<br>*(ការកំណត់ហាង)* | `views/owner/settings.php` | `settings.view` | `owner`, `system_admin` | Master system settings with 12 tabs: Shop Info, Receipt Header/Footer, Money/Currencies, Payment/KHQR, Bays, Oil Specs, Loyalty, Performance, Telegram, Cloud Sync, System/Backup, and Audit History. |
| `sync_monitor` | Cloud Sync Monitor<br>*(ផ្ទាំងត្រួតពិនិត្យ Sync)* | `views/owner/sync_monitor.php` | `sync.view` | `owner`, `manager` | Cloud synchronization dashboard: live push/pull telemetry, unsynced record counts, offline mode toggle, force sync trigger, and conflict resolution queue. |
| `audit_logs` | Audit History<br>*(កំណត់ត្រាសវនកម្ម)* | `views/owner/audit_logs.php` | `audit.view` | `owner`, `system_admin` | Immutable security audit trail: logs all login attempts, lockouts, order voids, price changes, drawer opens without sale, and system overrides. |
| `paper_forms` | Paper Disaster Forms<br>*(ទម្រង់ក្រដាសគ្រោះអាសន្ន)* | `views/recovery/paper_forms.php` | `recovery.view` | `owner`, `manager`, `cashier` | Printable physical paper job tickets for continuous shop operations during total electrical or local computer hardware failure. |
| `recovery_bulk_entry` | Recovery Bulk Entry<br>*(បញ្ចូលទិន្នន័យក្រដាស)* | `views/recovery/bulk_entry.php` | `recovery.create` | `owner`, `manager`, `cashier` | Fast batch data entry screen to digitize handwritten recovery job cards and reconcile ledger records after power or hardware restoration. |

---

## 3. Specialized Frontends & Auxiliary Interfaces

| Interface | URL Path / Access Mode | Guard / Security Requirement | Target Audience | Key Capabilities |
| :--- | :--- | :--- | :--- | :--- |
| **PWA POS Tablet App** | `/pos/index.html#pos` | Hardware Device Key (`X-Device-Key`) + Staff Session PIN (`X-Staff-Token`) | Cashier / Front-Desk Staff | Offline-first tablet app with service worker caching, fast category touch grids, customer quick search, appointment check-in, and local offline queue storage. |
| **Waiting Room Queue TV** | `/tv.php`<br>*(Optional: `?code=<tv_display_code>`)* | LAN Display Access / Optional TV Pairing Code | Customers in Waiting Lounge | High-contrast TV display showing currently washing vehicles, assigned bay numbers, ready-for-pickup alerts, estimated wait times, and promotional banners. Auto-refreshes via Server-Sent Events (SSE). |
| **ESC/POS Printer Agent** | Local Node daemon (`scripts/counter-printer-service.js` or `api/printer.php`) | Port `1818` / Localhost USB printer driver | Thermal Receipt Printer & Cash Drawer | Formats raw ESC/POS binary buffers for 80mm receipts and dispatches hardware kick pulses (`\x1B\x70`) to open cash drawer upon checkout. |
| **Background Sync Runner** | `scripts/sync_runner.php`<br>`scripts/run_sync.bat` | CLI execution with file lock (`storage/sync.lock`) | Windows Task Scheduler / Scheduled Background Process | Automatically wakes every 2 minutes to push queued local sales, timecards, and stock movements to Cloud Master, pulling updated catalog items and settings. |
| **Daily Backup Daemon** | `scripts/backup_daily.php`<br>`scripts/backup_daily.bat` | CLI execution | Windows Task Scheduler / Maintenance Cron | Executes mysqldump/sqlite dump, compresses archive into `storage/backups/`, and automatically prunes backups older than 14 days. |

---

## 4. Role Permission Assignment Matrix

Based on actual `role_permissions` database mapping:

| Module / Permission Key | `owner` | `manager` | `cashier` | `wash_sup` | `washer` | `mech` | `tech` | `stock` | `crm` | `admin` |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Dashboard** (`dashboard.view`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | NO |
| **POS Register Access** (`pos.access`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Create Order** (`pos.create_order`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Apply Discount** (`pos.discount`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Pay Order** (`pos.pay_order`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Pay Later / Debt** (`pos.pay_later`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Cancel Order** (`pos.cancel_order` / `orders.cancel`) | YES | YES | **NO** | NO | NO | NO | NO | NO | NO | NO |
| **Reprint Receipt** (`pos.receipt`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Order List** (`orders.list`) | YES | YES | YES | YES | NO | NO | NO | NO | NO | NO |
| **Change Crew Assignment** (`orders.change_crew`) | YES | YES | YES | YES | NO | NO | NO | NO | NO | NO |
| **Change Order Status** (`orders.change_status`) | YES | YES | YES | YES | NO | NO | NO | NO | NO | NO |
| **Bays View** (`bays.view`) | YES | YES | YES | YES | YES | YES | NO | NO | NO | NO |
| **Bays Assign Vehicle** (`bays.assign`) | YES | YES | YES | YES | YES | YES | NO | NO | NO | NO |
| **Bays Finish Vehicle** (`bays.finish`) | YES | YES | YES | YES | YES | YES | NO | NO | NO | NO |
| **Move Queue Order** (`bays.move_queue` / `bays.manage`) | YES | YES | NO | YES | NO | NO | NO | NO | NO | NO |
| **Washer Jobs View** (`washer_jobs.view`) | YES | YES | NO | YES | YES | NO | NO | NO | NO | NO |
| **Mechanic Jobs View** (`mechanic_jobs.view`) | YES | YES | NO | YES | NO | YES | YES | NO | NO | NO |
| **Mechanic Job Notes & Update** (`mechanic_jobs.*`) | YES | NO | NO | NO | NO | YES | YES | NO | NO | NO |
| **Guided Safety Inspection** (`inspection.manage`) | YES | YES | NO | NO | NO | YES | YES | NO | NO | NO |
| **Request Parts from Bay** (`parts_requests.create`) | YES | NO | NO | NO | NO | YES | YES | NO | NO | NO |
| **Approve Parts Request** (`parts_requests.approve`) | YES | YES | NO | NO | NO | NO | NO | YES | NO | NO |
| **Warehouse Products Catalog** (`extras.list`) | YES | YES | YES | NO | NO | NO | NO | YES | NO | NO |
| **Adjust Stock Quantity** (`extras.adjust_stock`) | YES | NO | NO | NO | NO | NO | NO | YES | NO | NO |
| **Log Stock Discrepancy** (`discrepancy.manage`) | YES | YES | NO | NO | NO | NO | NO | YES | NO | NO |
| **Oil & Car Care Specs** (`oil_care.list`) | YES | YES | YES | NO | NO | NO | NO | YES | YES | NO |
| **Customer List & Profiles** (`customers.list`) | YES | YES | YES | NO | NO | NO | NO | NO | YES | NO |
| **Customer Add / Edit** (`customers.add`, `edit`) | YES | YES | YES | NO | NO | NO | NO | NO | YES | NO |
| **Delete Customer** (`customers.delete`) | YES | NO | NO | NO | NO | NO | NO | NO | YES | NO |
| **Vehicle Repair History** (`vehicle_history.list`) | YES | YES | YES | YES | NO | YES | YES | NO | YES | NO |
| **Membership Management** (`memberships.manage`) | YES | YES | NO | NO | NO | NO | NO | NO | NO | NO |
| **Shift Start & Cash Float** (`pos.access`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Shift End & Blind Count** (`pos.access`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Day Close Z-Report** (`daily_close.close`) | YES | NO | YES | NO | NO | NO | NO | NO | NO | NO |
| **View Shift & Day Close Status** (`daily_close.view`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Crew Commissions Payroll** (`commissions.list`, `pay`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | NO |
| **Daily Expenses Ledger** (`expenses.list`, `add`, `del`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | NO |
| **Financial & Operational Reports** (`reports.view`) | YES | YES | NO | NO | NO | NO | NO | NO | NO | NO |
| **Export Reports to CSV/Excel** (`reports.export`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | NO |
| **Staff Management** (`staff.list`, `add`, `edit`, `del`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | YES |
| **Reset Staff PIN** (`staff.reset_password`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | YES |
| **Role Permissions Editor** (`roles.list`, `edit`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | YES |
| **POS Hardware Device Pairing** (`pos_devices.*`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | YES |
| **Shop Settings Configuration** (`settings.view`, `edit`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | YES |
| **Cloud Sync Monitor & Diagnostics** (`sync.view`, `manage`)| YES | YES | NO | NO | NO | NO | NO | NO | NO | NO |
| **Security Audit Logs** (`audit.view`) | YES | NO | NO | NO | NO | NO | NO | NO | NO | YES |
| **Database Backup Download** (`backup.manage` / `reports.backup`) | YES | YES | NO | NO | NO | NO | NO | NO | NO | YES |
| **Disaster Recovery Paper Forms** (`recovery.view`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
| **Digitize Paper Forms Batch** (`recovery.create`) | YES | YES | YES | NO | NO | NO | NO | NO | NO | NO |
