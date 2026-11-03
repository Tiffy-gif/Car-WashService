# Car Wash & Detailing Management System — Complete User Guide (សៀវភៅណែនាំអ្នកប្រើប្រាស់)

Welcome to the comprehensive, plain-language User Guide for the **Car Wash & Detailing Management System**. This documentation covers every stage of operating a high-volume, professional car wash and automotive care business—from first-time server initialization to end-of-month financial balancing.

---

## 📚 Complete Table of Contents

| Chapter | Document Title | Primary Audience | Core Focus |
|---|---|---|---|
| **00** | [00_FEATURE_INVENTORY.md](00_FEATURE_INVENTORY.md) | IT, Owner, Manager | Complete catalog of all 32 screens, RBAC permissions, and file links. |
| **01** | [01_OVERVIEW.md](01_OVERVIEW.md) | All Staff & Owner | System architecture, mini-PC shop server, cloud portal, 11 roles. |
| **02** | [02_FIRST_TIME_SETUP.md](02_FIRST_TIME_SETUP.md) | Owner, IT Integrator | 13-step commissioning guide: settings, prices, bays, pairing, go-live check. |
| **03** | [03_DAILY_OPENING.md](03_DAILY_OPENING.md) | All Roles | Opening routines by role: Cashier, Washer, Tech, Supervisor, Manager. |
| **04** | [04_CUSTOMER_JOURNEY.md](04_CUSTOMER_JOURNEY.md) | Cashier, Supervisor | Arrival to departure, SmartQueue, upsells, payments, "No Receipt No Wash". |
| **05** | [05_SHOP_FLOOR.md](05_SHOP_FLOOR.md) | Washer, Tech, Supervisor | Bay monitor, drag-and-drop, handshakes, parts requests, lounge TV. |
| **06** | [06_MONEY_AND_CONTROLS.md](06_MONEY_AND_CONTROLS.md) | Cashier, Manager, Owner | Blind cashiering, 2-cashier handover, shortage rules, spot audits, Z-report. |
| **07** | [07_STOCK_AND_PRODUCTS.md](07_STOCK_AND_PRODUCTS.md) | Warehouse, Tech, Manager | Immutable ledger, reservations, lubricant liters, correction lines. |
| **08** | [08_CUSTOMERS_AND_GROWTH.md](08_CUSTOMERS_AND_GROWTH.md) | CRM, Cashier, Owner | Multi-car profiles, loyalty points, tiers, oil reminders, at-risk win-backs. |
| **09** | [09_REPORTS_AND_KPIs.md](09_REPORTS_AND_KPIs.md) | Owner, Manager | Cars per labor hour, bay utilization, staff efficiency, review cadences. |
| **10** | [10_CLOUD_SYNC_AND_OFFLINE.md](10_CLOUD_SYNC_AND_OFFLINE.md) | Owner, Manager, IT | Bidirectional sync, offline mode, paper disaster forms, bulk digitization. |
| **11** | [11_MONTH_END_AND_MAINTENANCE.md](11_MONTH_END_AND_MAINTENANCE.md)| Owner, Manager | 14-day rolling backups, soft deactivations, blocking lost tablets. |
| **12** | [12_TROUBLESHOOTING.md](12_TROUBLESHOOTING.md) | All Staff, IT | Diagnostic table of all real error codes, printer port 1818, drawer fixes. |
| **13** | [13_A_DAY_IN_THE_SHOP.md](13_A_DAY_IN_THE_SHOP.md) | Training / New Staff | Hour-by-hour narrative story following 3 real customer walkthroughs. |
| **14** | [14_ROLE_QUICK_CARDS.md](14_ROLE_QUICK_CARDS.md) | All Staff Workstations | Printable 1-page quick cards: 10 things to do, 5 never to do, escalation. |
| **15** | [15_GLOSSARY.md](15_GLOSSARY.md) | All Staff | Bilingual English and Khmer terminology and UI button dictionary. |
| **GAP**| [GUIDE_GAPS.md](GUIDE_GAPS.md) | Owner, Executive Mgmt | Documented edge cases, cloud handoffs, and required business decisions. |

---

## 🧭 Role-Based Reading Roadmaps

Staff members do not need to read all 15 chapters. Use these targeted reading roadmaps to train specific employees:

### 👑 Shop Owner Roadmap (ថៅកែហាង)
*Essential reading for business oversight, cash protection, and growth:*
1. [01_OVERVIEW.md](01_OVERVIEW.md) — System building blocks and permissions
2. [02_FIRST_TIME_SETUP.md](02_FIRST_TIME_SETUP.md) — Initial commissioning and pre-flight check
3. [06_MONEY_AND_CONTROLS.md](06_MONEY_AND_CONTROLS.md) — Cash fraud prevention and blind cashiering
4. [07_STOCK_AND_PRODUCTS.md](07_STOCK_AND_PRODUCTS.md) — Warehouse ledger and loss prevention
5. [09_REPORTS_AND_KPIs.md](09_REPORTS_AND_KPIs.md) — Cars/labor hr, bay utilization, review cadences
6. [10_CLOUD_SYNC_AND_OFFLINE.md](10_CLOUD_SYNC_AND_OFFLINE.md) — Multi-store cloud sync and offline resilience
7. [11_MONTH_END_AND_MAINTENANCE.md](11_MONTH_END_AND_MAINTENANCE.md) — Backups and staff deactivations
8. [GUIDE_GAPS.md](GUIDE_GAPS.md) — Strategic business policies and decisions

### 📋 Shift Manager Roadmap (អ្នកគ្រប់គ្រងវេន)
*Essential reading for day-to-day operations and staff supervision:*
1. [03_DAILY_OPENING.md](03_DAILY_OPENING.md) — Morning opening checklists
2. [04_CUSTOMER_JOURNEY.md](04_CUSTOMER_JOURNEY.md) — Handling customer exceptions and refunds
3. [05_SHOP_FLOOR.md](05_SHOP_FLOOR.md) — Bay management, queue reordering, handshake protocol
4. [06_MONEY_AND_CONTROLS.md](06_MONEY_AND_CONTROLS.md) — Spot audits, PIN overrides, Z-report closing
5. [12_TROUBLESHOOTING.md](12_TROUBLESHOOTING.md) — Resolving daily errors and hardware issues
6. [14_ROLE_QUICK_CARDS.md](14_ROLE_QUICK_CARDS.md) — Managing staff expectations

### 💵 Cashier Roadmap (អ្នកគិតលុយ)
*Essential reading for counter operations and money handling:*
1. [03_DAILY_OPENING.md](03_DAILY_OPENING.md) — Register opening and float verification
2. [04_CUSTOMER_JOURNEY.md](04_CUSTOMER_JOURNEY.md) — Plate lookup, upsells, Cash/KHQR payment, receipts
3. [06_MONEY_AND_CONTROLS.md](06_MONEY_AND_CONTROLS.md) — Blind shift counting, shortage rules, handovers
4. [13_A_DAY_IN_THE_SHOP.md](13_A_DAY_IN_THE_SHOP.md) — Walkthrough of real transactions
5. [14_ROLE_QUICK_CARDS.md](14_ROLE_QUICK_CARDS.md) — Cashier quick reference card

### 🧼 Bay Supervisor & Washer Roadmap (ប្រធានផ្នែកលាង និងបុគ្គលិកលាង)
*Essential reading for apron and wash bay efficiency:*
1. [03_DAILY_OPENING.md](03_DAILY_OPENING.md) — Wash bay morning prep
2. [05_SHOP_FLOOR.md](05_SHOP_FLOOR.md) — Washer tablet usage, countdown timers, quality checklists
3. [14_ROLE_QUICK_CARDS.md](14_ROLE_QUICK_CARDS.md) — Washer quick reference card

### 🔧 Lead Mechanic & Technician Roadmap (ជាងជួសជុល)
*Essential reading for technical bay and vehicle maintenance:*
1. [03_DAILY_OPENING.md](03_DAILY_OPENING.md) — Lift and tool morning inspection
2. [05_SHOP_FLOOR.md](05_SHOP_FLOOR.md) — Digital checklists, oil adjustment, parts requests, handshake
3. [07_STOCK_AND_PRODUCTS.md](07_STOCK_AND_PRODUCTS.md) — Lubricants and warehouse parts requests
4. [14_ROLE_QUICK_CARDS.md](14_ROLE_QUICK_CARDS.md) — Mechanic quick reference card

### 📦 Warehouse / Stock Controller Roadmap (បុគ្គលិកឃ្លាំង)
*Essential reading for physical inventory control:*
1. [03_DAILY_OPENING.md](03_DAILY_OPENING.md) — Storage room inspection
2. [07_STOCK_AND_PRODUCTS.md](07_STOCK_AND_PRODUCTS.md) — Inbound receiving, issuing parts, correction lines
3. [14_ROLE_QUICK_CARDS.md](14_ROLE_QUICK_CARDS.md) — Stock controller quick reference card
