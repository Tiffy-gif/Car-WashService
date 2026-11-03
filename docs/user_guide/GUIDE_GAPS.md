# Documented System Gaps & Required Business Decisions (គម្លាតប្រព័ន្ធ និងការសម្រេចចិត្តអាជីវកម្ម)

During the comprehensive audit of the Car Wash & Detailing Management codebase against the original project brief and live operating workflows, the following **10 operational gaps, cloud handoffs, and required business decisions** were identified. Each item links to the exact code files and outlines the decision required from the shop owner.

---

## 1. Recurring Membership Card Decline Recovery (`membership_payment_recovery`)
* **Brief Requirement**: Automated dunning and payment recovery workflow for declined subscription credit cards.
* **Codebase Reality**: [MembershipManager.php](file:///C:/xampp/htdocs/Car-WashService/core/MembershipManager.php) contains membership tier assignments and point calculations, but no recurring credit card tokenization or automated banking retry engine exists on the local shop server.
* **Architecture Clarification**: Automated recurring card billing and bank retry algorithms are executed exclusively by the central Cloud Management Portal (`Car-WashCloud`). The local shop server acts as an edge node that flags the customer profile as `payment_past_due` when synced, allowing the cashier to collect payment in person via cash or ABA KHQR.
* **Business Decision Required**: Determine whether customers with past-due recurring subscriptions are allowed a 1-wash grace period at the apron while their card retry is pending, or if payment must be settled at POS before any service begins.

---

## 2. Multi-Store Chain Consolidated Overview
* **Brief Requirement**: Real-time cross-store performance comparison and consolidated revenue reporting.
* **Codebase Reality**: The local shop server [views/owner/reports.php](file:///C:/xampp/htdocs/Car-WashService/views/owner/reports.php) is engineered as an autonomous single-location server. It reports sales, device breakdowns, and bay metrics for its own shop code (e.g. `SHOP-PP-001`).
* **Architecture Clarification**: Cross-store consolidation is performed at headquarters via the Cloud Sync API ([CloudSyncManager.php](file:///C:/xampp/htdocs/Car-WashService/core/CloudSyncManager.php)), which pushes raw transactions to the cloud database.
* **Business Decision Required**: Decide whether store managers are permitted to view regional branch performance on the local screen or if cross-store benchmarking remains restricted to executive owners on the cloud portal.

---

## 3. Physical Cash Drawer Hardware Interface (ESC/POS vs Direct USB)
* **Code Reference**: [CashierControl.php](file:///C:/xampp/htdocs/Car-WashService/core/CashierControl.php#L620-L650) & [pos/js/app.js](file:///C:/xampp/htdocs/Car-WashService/pos/js/app.js)
* **Codebase Reality**: The system triggers cash drawer opening via standard ESC/POS solenoid pulse commands (`\x1B\x70\x00\x19\xFA`) sent through the receipt printer on port `1818`.
* **Hardware Requirement**: The physical cash drawer must be connected via an RJ11 telephone-style cable directly to the back of the thermal receipt printer (the `DK` port), not to a standalone USB port on the PC.
* **Business Decision Required**: Ensure shop hardware purchasing procurement specifies receipt printers with RJ11 drawer kick ports rather than standalone USB-only cash boxes.

---

## 4. Strict Manager PIN Required for All Manual Discounts
* **Code Reference**: [api/pos/index.php](file:///C:/xampp/htdocs/Car-WashService/api/pos/index.php#L1362-L1375)
* **Codebase Reality**: If a manual discount exceeds `$0.001`, the system checks:
  ```php
  if ($stf['role'] !== 'owner' && !Auth::userHasPermission($staffUserId, 'pos.discount')) {
      return ['error' => 'PERMISSION_DENIED', 'message' => 'pos.discount required'];
  }
  ```
  By default, the `cashier` role lacks `pos.discount`. Even a $0.50 courtesy discount requires calling the Shift Manager to enter their PIN.
* **Operational Impact**: During peak Saturday rush hours, cashiers may face queues while waiting for managers to approve small discounts.
* **Business Decision Required**: Decide whether to:
  1. Maintain strict 100% manager authorization (maximum fraud prevention).
  2. Create a "Senior Cashier" role granted a limited discount allowance (e.g. up to 5% or $1.00 max).

---

## 5. Strict Offline Refund Prohibition
* **Code Reference**: [api/pos/index.php](file:///C:/xampp/htdocs/Car-WashService/api/pos/index.php#L4143-L4147) & [CashierControl.php](file:///C:/xampp/htdocs/Car-WashService/core/CashierControl.php#L674-L677)
* **Codebase Reality**:
  ```php
  if ($isOffline) {
      throw new Exception("Offline refunds are strictly prohibited. If connection is lost, use the paper logbook signed by the Manager.");
  }
  ```
* **Security Rationale**: Prevents rogue staff from processing duplicate offline refunds that cannot be audited against real-time bank deposits.
* **Operational Impact**: If a customer requests a refund while the internet is temporarily disconnected, the software refuses the transaction.
* **Business Decision Required**: Formalize the shop policy: Staff must issue a handwritten **Paper Refund Voucher** signed by the Shift Manager and retain customer contact details to digitize once the network resumes.

---

## 6. Overnight Day Cutoff at 23:59:00
* **Code Reference**: [CashierControl.php](file:///C:/xampp/htdocs/Car-WashService/core/CashierControl.php#L760-L770)
* **Codebase Reality**: Exactly at 23:59:00, `CashierControl::isDayLocked()` returns `locked = true`, blocking new orders and payments until a Manager PIN generates the daily Z-report.
* **Operational Impact**: Suitable for standard day-shops (e.g. 07:30 to 20:00). If a shop introduces late-night fleet washing (operating past midnight), the register will refuse sales at 23:59:01.
* **Business Decision Required**: If operating hours extend past midnight, adjust the cutoff time in `CashierControl.php` (e.g. move cutoff to 03:00:00 AM) or mandate that the midnight shift run a Z-report at 23:50 and start a fresh day.

---

## 7. Append-Only Stock Movements & Database Triggers
* **Code Reference**: [StockControl.php](file:///C:/xampp/htdocs/Car-WashService/core/StockControl.php#L220-L260) & Database Triggers (`trg_stock_movements_before_update`, `trg_stock_movements_before_delete`)
* **Codebase Reality**: The database completely forbids `UPDATE` or `DELETE` queries on `stock_movements`. If a warehouse clerk mistakenly enters receiving 100 oil filters instead of 10, they cannot edit the record.
* **Business Decision Required**: Train warehouse staff and managers on the **Correction Line Protocol**: Mistakes are corrected by adding an offsetting line with Manager PIN and reason note (e.g. `-90 units, clerical receiving error`). Never attempt direct SQL edits.

---

## 8. Cash Discrepancy Tolerance Buffer in Spot Audits
* **Code Reference**: [CashierControl.php](file:///C:/xampp/htdocs/Car-WashService/core/CashierControl.php#L540-L560)
* **Codebase Reality**: The mathematical variance is exact to the cent:
  $$\text{Difference} = \text{Counted Cash} - \text{Expected Cash}$$
  Any difference $< \$0.00$ triggers a `SHIFT_SHORTAGE` audit log entry and flags the cashier for reimbursement.
* **Business Decision Required**: Decide whether the shop allows a tiny rounding tolerance (e.g., $\pm \$0.25$ or $\pm 1,000\text{ ៛}$) due to common small riel bill coin shortages before a formal payroll deduction is initiated.

---

## 9. Machine Learning Duration Reviews & Recalibration
* **Code Reference**: [SmartQueueEngine.php](file:///C:/xampp/htdocs/Car-WashService/core/SmartQueueEngine.php#L350-L400)
* **Codebase Reality**: When jobs take >20% longer than catalog estimates, the system creates an entry in `service_duration_reviews`. The system does **NOT** automatically alter catalog prices or base times; it requires managerial review (`adjust` vs `dismiss`).
* **Operational Impact**: If managers never review this table, catalog durations remain uncalibrated while queue estimates may drift.
* **Business Decision Required**: Mandate that the General Manager or Owner conduct a weekly 10-minute review of `service_duration_reviews` every Monday morning to keep customer wait times precise.

---

## 10. Secondary Customer-Facing Display & QR Presentation
* **Code Reference**: [pos/index.html](file:///C:/xampp/htdocs/Car-WashService/pos/index.html#L1580-L1630) & [pos/js/app.js](file:///C:/xampp/htdocs/Car-WashService/pos/js/app.js)
* **Codebase Reality**: The POS renders dynamic KHQR codes on screen inside the checkout modal.
* **Hardware Requirement**: For optimal customer experience, the cashier station should have either a secondary customer-facing LCD display (extended desktop) or a swivel tablet mount so the customer can scan the QR code without reaching over the counter.
* **Business Decision Required**: Select front counter mounting hardware: Swivel tablet mount vs dual-monitor POS terminal.
