# 14. Role-Based Printable Quick Reference Cards (ប័ណ្ណសង្ខេបតួនាទី)

These 1-page quick cards are designed to be printed and laminated at each workstation. They provide the clear **10 Things to Do**, **5 Things NEVER to Do**, and **Escalation Path** for every role in the business.

---

## Quick Card 1: Cashier (អ្នកគិតលុយ)
*Workstation: Front Counter POS*

### 10 Things You Must Do:
1. **Count Opening Float**: Physically verify every dollar and riel bill before tapping [Start Shift].
2. **Scan/Type Plate First**: Always begin order intake by searching the vehicle plate number (`F2`).
3. **Select Vehicle Type Accurately**: Never default to Small Car if the car is an SUV or Pickup.
4. **Offer Smart Upsells**: Verbally offer the suggested upsell (tire wax, engine flush) on every ticket.
5. **No Receipt, No Wash**: Always print the official receipt and place it under the windshield wiper.
6. **Upload Bank Slips**: Always capture a clear photo of customer payment confirmation on ABA/KHQR transfers.
7. **Type Transfer Ref #**: Enter the reference number into the POS payment modal on digital sales.
8. **Blind Shift Count**: Physically count all cash in the drawer at shift end before typing numbers.
9. **Two-Cashier Handover**: Ensure the incoming cashier enters their PIN to sign off on your drawer balance.
10. **Report Equipment Errors Immediately**: If the receipt printer or drawer fails, notify the Manager.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER touch a vehicle without a printed receipt.**
2. ❌ **NEVER give discounts without Manager PIN authorization.**
3. ❌ **NEVER pop open the cash drawer without an authorized reason.**
4. ❌ **NEVER process refunds while offline (internet down).**
5. ❌ **NEVER share your 6-digit login PIN with any other staff member.**

* **Who to Call**: Call **Shift Manager** for discounts, cancellations, refunds, or hardware issues.

---

## Quick Card 2: Washer & Detailer (បុគ្គលិកលាង)
*Workstation: Wash Bays 1, 2, 3*

### 10 Things You Must Do:
1. **Check Dashboard Receipt**: Ensure the car has a printed queue receipt before spraying water.
2. **Verify Vehicle Type**: Match the car size with the job card on your bay tablet.
3. **Tap [Start Job]**: Tap the button the second the car enters your bay so the timer starts.
4. **Follow the Wash Standard**: High-pressure rinse &rarr; Foam soak &rarr; Microfiber mitt &rarr; Rinse &rarr; Blow dry.
5. **Clean Wheels Thoroughly**: Degrease rims and apply tire shine dressing evenly.
6. **Vacuum Carpets & Crevices**: Remove floor mats and vacuum under seats.
7. **Wipe Windows Streak-Free**: Use clean dry glass towels inside and out.
8. **Check Off Inspection**: Verify the 4-point quality check before releasing.
9. **Tap [Finish Car]**: Tap the button immediately when done so the TV chimes for the customer.
10. **Handshake Alert**: If the ticket is a mixed order, alert the mechanic that wash is complete.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER wash an unregistered car without a system receipt.**
2. ❌ **NEVER use dirty dropped towels on car paint (prevents swirl scratches).**
3. ❌ **NEVER spray high-pressure water closer than 30 cm from car paint or emblems.**
4. ❌ **NEVER leave keys inside a locked vehicle.**
5. ❌ **NEVER spray water directly into open electrical fuse boxes under the hood.**

* **Who to Call**: Call **Wash Supervisor** if equipment leaks or a customer car has pre-existing body damage.

---

## Quick Card 3: Mechanic / Technician (ជាងជួសជុល)
*Workstation: Technical Bay 4*

### 10 Things You Must Do:
1. **Verify Digital Job Card**: Inspect ordered oil specifications and filter part numbers on your tablet.
2. **Accept Handshake**: Tap [Receive From Wash] when a mixed-order car arrives from the wash bay.
3. **Digital 12-Point Inspection**: Check fluids, tire pressure, brake pads, battery, and suspension.
4. **Digital Parts Request**: Always request oil and filters through the tablet parts request button.
5. **Verify New Filter Match**: Compare old filter threads and O-ring with the new replacement filter.
6. **Use Torque Wrench**: Tighten drain plugs to manufacturer torque spec; replace crush washers.
7. **Record Exact Dispensed Volume**: Record exact oil liters used (e.g. 4.2L) in `adjust_oil`.
8. **Reset Maintenance Light**: Reset vehicle dashboard service interval reminder.
9. **Apply Next Service Sticker**: Print or write the next oil mileage sticker on the top corner of the windshield.
10. **Tap [Finish Task]**: Sign off the job digitally so the cashier can release the vehicle.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER take oils or filters from warehouse shelves without submitting a parts request.**
2. ❌ **NEVER start an engine without double-checking the dipstick oil level.**
3. ❌ **NEVER release a car with an active oil leak or loose undertray bolts.**
4. ❌ **NEVER guess oil viscosity (always check manufacturer vehicle specs).**
5. ❌ **NEVER test drive a customer car without manager authorization.**

* **Who to Call**: Call **Technical Supervisor / Manager** for unexpected mechanical damage or seized drain plugs.

---

## Quick Card 4: Warehouse / Stock Controller (បុគ្គលិកឃ្លាំង)
*Workstation: Parts Room & Stock Ledger Terminal*

### 10 Things You Must Do:
1. **Verify Inbound Deliveries**: Match supplier delivery slips against physical cartons before receiving.
2. **Log Stock Receipts Immediately**: Enter received shipments into `views/owner/stock_ledger.php`.
3. **Approve Mechanic Requests**: Verify stock availability before dispensing filters and oils.
4. **Follow FIFO (First-In, First-Out)**: Rotate older oil bottles to the front of the shelf.
5. **Monitor Low Stock Alerts**: Report any items falling below minimum `reorder_level` to the manager.
6. **Log Drum Spillage**: Record fractional spillage or dispensing hose leaks with clear audit notes.
7. **Perform Weekly Spot Counts**: Physically count high-value synthetic oils every Monday morning.
8. **Keep Storage Room Locked**: Ensure unauthorized staff do not wander into lubricant storage.
9. **Use Correction Lines for Discrepancies**: Never attempt to tamper with or hide ledger variances.
10. **Archive Supplier Invoices**: File all physical delivery slips sequentially in the supplier binder.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER hand out parts without an approved digital parts request in the system.**
2. ❌ **NEVER accept open or leaking chemical drums from delivery drivers.**
3. ❌ **NEVER modify past ledger records (the database triggers will block it anyway).**
4. ❌ **NEVER borrow stock from storage without immediate digital logging.**
5. ❌ **NEVER leave lubricant drums unsealed or exposed to rain/sun.**

* **Who to Call**: Call **Shop Manager** if supplier invoices don't match delivered counts.

---

## Quick Card 5: Wash Supervisor (ប្រធានផ្នែកលាង)
*Workstation: Apron Desk & Bay Monitor*

### 10 Things You Must Do:
1. **Direct Apron Traffic**: Guide incoming cars into orderly lanes without blocking the main street.
2. **Monitor SmartQueue**: Keep an eye on estimated wait times; intervene if queue exceeds 45 mins.
3. **Assign Vehicles Efficiently**: Balance workload fairly across all active wash bays.
4. **Enforce "No Receipt, No Wash"**: Halt work immediately on any vehicle lacking a valid POS receipt.
5. **Supervise Bay Speed**: Keep basic wash turnaround under 20 minutes per bay.
6. **Log Delay Reasons**: If a car requires heavy mud scrubbing, set a delay reason on the monitor.
7. **Inspect Quality Randomly**: Walk bays to inspect window clarity, wheel arches, and drying quality.
8. **Coordinate Handshakes**: Ensure smooth transfer of mixed-order vehicles from Wash to Tech Bay.
9. **Maintain Equipment**: Inspect pressure washers, foam cannons, and chemical injectors daily.
10. **Enforce Safety Gear**: Ensure staff wear rubber boots, waterproof aprons, and eye protection.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER let cars skip the queue unless they hold an on-time VIP P0 appointment.**
2. ❌ **NEVER allow staff to horseplay or race customer vehicles on the apron.**
3. ❌ **NEVER ignore customer complaints; inspect the vehicle with the customer immediately.**
4. ❌ **NEVER allow cars to leave drying bays with wet streaks or dripping mirrors.**
5. ❌ **NEVER override SmartQueue bay recommendations without a valid operational reason.**

* **Who to Call**: Call **Shift Manager** if an apron dispute arises or equipment suffers electrical failure.

---

## Quick Card 6: Shift Manager (អ្នកគ្រប់គ្រងវេន)
*Workstation: Manager Terminal & Apron*

### 10 Things You Must Do:
1. **Conduct Shift Opening Check**: Verify all terminals, TV boards, and compressors are online by 07:30.
2. **Perform Unannounced Spot Audits**: Audit cashier drawer cash at unpredictable times daily.
3. **Authorize Overrides Personally**: Inspect the order reason before keying in your Manager PIN.
4. **Resolve Queue Bottlenecks**: Help apron staff during peak noon and evening rush hours.
5. **Enforce Blind Cashiering**: Never reveal expected system cash totals to cashiers before they count.
6. **Investigate Cash Shortages**: Address any negative shift variance immediately with the cashier.
7. **Authorize Parts Overrides**: Review and approve out-of-stock overrides or urgent purchases.
8. **Handle Customer Claims**: Inspect and settle scratch or damage claims professionally.
9. **Close Business Day via Z-Report**: Generate the official daily Z-report and lock the register.
10. **Review Audit Logs Daily**: Scan `audit_logs` every evening for unauthorized actions.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER share your Manager PIN with cashiers or wash staff.**
2. ❌ **NEVER approve discounts or refunds without physically inspecting the ticket.**
3. ❌ **NEVER approve offline refunds (strictly against company policy).**
4. ❌ **NEVER ignore a cash discrepancy on a shift handover.**
5. ❌ **NEVER leave the shop unattended without appointing a designated acting supervisor.**

* **Who to Call**: Call **Shop Owner** for critical equipment breakdown, police reports, or server outages.

---

## Quick Card 7: Shop Owner (ថៅកែហាង)
*Workstation: Cloud Management Portal & Executive Office*

### 10 Things You Must Do:
1. **Morning Security Audit**: Spend 3 minutes scanning `audit_logs` for refunds and drawer opens.
2. **Bank Reconciliation**: Compare daily Z-report totals against actual bank deposits every morning.
3. **Check Cloud Sync Monitor**: Ensure the local shop is pushing records with zero pending backlogs.
4. **Review Weekly Bay Utilization**: Ensure shop utilization stays in the profitable 65%–80% range.
5. **Calibrate Base Durations**: Review `service_duration_reviews` to keep wait times accurate.
6. **Approve Staff Commissions**: Review itemized commission calculations before monthly payroll.
7. **Perform Weekly Offsite Backups**: Copy the latest database backup to a private USB flash drive.
8. **Inspect Warehouse Margins**: Review lubricant purchasing costs and calibrate service prices.
9. **Audit Staff Permissions**: Ensure no staff member has unauthorized manager privileges.
10. **Execute Win-Back Campaigns**: Target at-risk customer segments with retention incentives.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER deactivate the last active Owner account on the system.**
2. ❌ **NEVER delete historical user accounts or past database records.**
3. ❌ **NEVER leave the default cloud synchronization tokens or secrets exposed.**
4. ❌ **NEVER bypass physical spot audits because "you trust your staff".**
5. ❌ **NEVER ignore negative cash shortages in cashier shift handovers.**

* **Who to Call**: Contact **IT System Administrator** for server hardware failures or software upgrades.

---

## Quick Card 8: CRM & Front Desk Specialist (ទំនាក់ទំនងអតិថិជន)
*Workstation: Customer Lounge & Reception Desk*

### 10 Things You Must Do:
1. **Greet Arriving Customers**: Welcome every customer warmly at the apron or waiting lounge door.
2. **Explain the Lounge TV**: Show customers where their queue number appears on the screen.
3. **Promote Membership Benefits**: Explain how earning 200 points unlocks Gold Tier detailing discounts.
4. **Offer Complimentary Refreshments**: Direct customers to free water, Wi-Fi, and coffee amenities.
5. **Monitor Customer Wait Times**: Check in on customers if their vehicle wait exceeds 30 minutes.
6. **Send Oil Service Reminders**: Follow up on "Due Soon" customers via Telegram / phone.
7. **Book Future Appointments**: Reserve VIP P0 slots for busy professionals using `/appointments`.
8. **Collect Customer Feedback**: Ask departing customers about wash quality and record suggestions.
9. **Follow Up on At-Risk Customers**: Send friendly win-back messages to customers inactive for 60 days.
10. **Maintain Lounge Presentation**: Ensure the waiting area, restrooms, and Wi-Fi are spotless.

### 5 Things You Must NEVER Do:
1. ❌ **NEVER promise unrealistic wash completion times during peak rush hours.**
2. ❌ **NEVER disclose other customers' phone numbers or private details.**
3. ❌ **NEVER let an unhappy customer leave without introducing them to the Shift Manager.**
4. ❌ **NEVER ignore the waiting lounge TV when it chimes for a completed vehicle.**
5. ❌ **NEVER alter customer loyalty point balances without manager authorization.**

* **Who to Call**: Call **Shift Manager** if a customer is upset about wait time or wash quality.
