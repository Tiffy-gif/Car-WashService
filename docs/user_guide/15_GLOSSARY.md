# 15. Bilingual Terminology & UI Glossary (វចនានុក្រមពាក្យបច្ចេកទេស និងប៊ូតុង)

This glossary provides plain-language definitions and the exact English and Khmer terms used throughout the system screens and menus.

---

## 1. Core Operational Concepts

| English Term | Khmer Term (ភាសាខ្មែរ) | Business Explanation & Context |
|---|---|---|
| **SmartQueue** | ជួរឆ្លាតវៃ | The intelligent scheduling engine that calculates wait times based on vehicle size, staff speed, and bay availability. |
| **Blind Cashiering** | ការគិតលុយបិទភ្នែក | Hiding the expected cash total from the cashier during their shift to prevent skimming and force an honest physical count. |
| **Opening Float** | មូលនិធិដើមវេន | The base change fund (e.g. $50 + 200,000៛) placed in the cash drawer at the start of a shift to give change to customers. |
| **Shift Handover** | ការផ្ទេរវេន | The two-person sign-off procedure when one cashier hands the cash drawer to the next, verified by both staff PINs. |
| **Cash Variance** | គម្លាតសាច់ប្រាក់ | The mathematical difference between Counted Cash and System Expected Cash ($\text{Counted} - \text{Expected}$). |
| **Cash Shortage** | ខ្វះលុយ | A negative variance where physical cash is less than expected. The deficit is owed by the outgoing cashier. |
| **Spot Audit** | អធិការកិច្ចភ្លាមៗ | An unannounced surprise cash count conducted by a Manager to detect theft during peak operating hours. |
| **Z-Report** | របាយការណ៍ Z | The final daily financial report generated at day close that locks daily sales and resets daily transaction counters. |
| **Day Lock** | ការចាក់សោថ្ងៃ | The automated cutoff (at 23:59:00 or after Z-report) that blocks registers from taking new sales until authorized. |
| **No-Sale Open** | បើកថតមិនលក់ | Popping open the cash drawer without a sale. Strictly requires a Manager PIN and a recorded justification. |
| **Handshake Protocol**| ពិធីសារផ្ទេរការងារ | The formal digital transfer of a vehicle from the Wash Bay team to the Technical/Mechanic team on mixed orders. |
| **Immutable Ledger** | បញ្ជីទំនិញមិនអាចកែប្រែបាន | The append-only warehouse stock ledger (`stock_movements`) protected by database triggers against updates or deletions. |
| **Correction Line** | បន្ទាត់កែតម្រូវ | An offsetting ledger entry added by a Manager to correct physical stock counts without altering past audit rows. |
| **Reorder Level** | កម្រិតបញ្ជាទិញឡើងវិញ | The minimum threshold of a product in stock that triggers a yellow warning badge and replenishment order. |
| **Vehicle Multiplier** | មេគុណទំហំរថយន្ត | A numerical multiplier (e.g. SUV = 1.25x) adjusting wash price and bay duration for larger vehicles. |
| **VIP P0 Priority** | អាទិភាព VIP P0 | The top queue ranking given to customers arriving within $\pm 10$ minutes of their scheduled appointment. |
| **Disaster Paper Mode**| របៀបក្រដាសសង្គ្រោះបន្ទាន់| Operating the car wash using pre-printed paper job cards and logbooks during total power or hardware blackouts. |

---

## 2. Navigation Menus & Screens

| Menu / Screen | English Label | Khmer Label (ខ្មែរ) | Code Path |
|---|---|---|---|
| **Dashboard** | Dashboard | ផ្ទាំងគ្រប់គ្រង | `views/owner/dashboard.php` |
| **POS Register** | Point of Sale | កន្លែងគិតលុយ / POS | `pos/index.html#pos` |
| **Cashier Shifts** | Shift Management | គ្រប់គ្រងវេនគិតលុយ | `views/cashier/shift.php` |
| **Bays & Queue** | Bays & Queue Monitor | តាមដានកន្លែងលាង និងជួរ | `views/owner/bays_queue.php` |
| **Washer Screen** | Washer Bay Screen | ផ្ទាំងបុគ្គលិកលាង | `views/washer/jobs.php` |
| **Mechanic Screen** | Technical Workshop | ផ្ទាំងជាងជួសជុល | `views/mechanic/jobs.php` |
| **Stock Ledger** | Stock Ledger & History | បញ្ជីទំនិញ និងឃ្លាំង | `views/owner/stock_ledger.php` |
| **Customers** | Customers & Vehicles | អតិថិជន និងរថយន្ត | `views/owner/customers.php` |
| **Vehicle History** | Vehicle Service History | ប្រវត្តិនៃការជួសជុល | `views/owner/vehicle_history.php` |
| **Oil Care** | Oil & Reminders | ប្រេង និងការរំលឹក | `views/owner/oil_care.php` |
| **Commissions** | Staff Commissions | កម្រៃជើងសារបុគ្គលិក | `views/owner/commissions.php` |
| **Expenses** | Shop Expenses | ចំណាយប្រចាំថ្ងៃ | `views/owner/expenses.php` |
| **Reports** | Reports & Analytics | របាយការណ៍ និងស្ថិតិ | `views/owner/reports.php` |
| **POS Devices** | POS Devices & Terminals | ឧបករណ៍ POS | `views/owner/pos_devices.php` |
| **Sync Monitor** | Cloud Sync Monitor | តាមដានសមកាលកម្មក្លោដ | `views/owner/sync_monitor.php` |
| **Disaster Recovery**| Paper Forms & Bulk Entry | ទម្រង់ក្រដាសសង្គ្រោះបន្ទាន់ | `views/recovery/bulk_entry.php` |
| **Waiting Lounge TV**| Live Customer TV Display| កញ្ចក់ទូរទស្សន៍អតិថិជន | `tv.php` |

---

## 3. UI Buttons & Action Commands

| Button Label | Action Performed | Permission Required |
|---|---|---|
| **[Start Shift]** | Opens cashier register with counted opening float | `shifts.open` (Cashier) |
| **[End Shift]** | Performs blind cash count and two-cashier handover | `shifts.close` (Cashier) |
| **[Charge] / [Checkout]** | Opens payment modal (Cash, ABA, KHQR) | `pos.access` (Cashier) |
| **[Open Drawer / No Sale]**| Pops cash drawer without ringing up a sale | `cashier.open_drawer_no_sale` (Manager PIN) |
| **[Spot Audit]** | Initiates surprise unannounced drawer count | `shifts.spot_audit` (Manager PIN) |
| **[Apply Discount]** | Reduces order subtotal by dollar or percent | `pos.discount` (Manager PIN) |
| **[Cancel Order]** | Voids an order before service commences | `pos.cancel_order` (Manager PIN) |
| **[Process Refund]** | Refunds a paid order and returns funds | `pos.cancel_order` (Manager PIN, Online only) |
| **[Start Job]** | Starts active timer on assigned bay card | `bays.assign` (Washer/Supervisor) |
| **[Finish Car]** | Marks job complete; triggers TV chime | `bays.finish` (Washer/Mechanic) |
| **[Receive From Wash]** | Handshake: Accepts washed car into Tech Bay | `mechanic_jobs.view` (Mechanic) |
| **[Request Parts]** | Mechanic submits digital oil/filter request | `mechanic_jobs.view` (Mechanic) |
| **[Approve & Issue]** | Warehouse confirms stock release to mechanic | `stock.manage` (Stock Control) |
| **[Add Correction Line]** | Adds adjusting row to immutable stock ledger | `stock.adjust` (Manager PIN) |
| **[Block Device]** | Instantly revokes lost or stolen tablet token | `pos_devices.block` (Owner) |
| **[Close Day (Z-Report)]**| Closes business day and locks registers | `cashier.close_day` (Manager PIN) |
