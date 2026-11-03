# 13. A Day in the Shop: A Practical Narrative (ដំណើរការជាក់ស្តែងក្នុងមួយថ្ងៃ)

To see how all the pieces of the system connect in real life, follow a typical operational day at **AquaGleam Car Wash & Detailing** in Phnom Penh.

---

## The Cast of Characters
* **Panha** — Shop Owner (ថៅកែហាង)
* **Kosal** — Shift Manager (អ្នកគ្រប់គ្រងវេន)
* **Sokha** — Morning Cashier (អ្នកគិតលុយវេនព្រឹក)
* **Chanthou** — Evening Cashier (អ្នកគិតលុយវេនល្ងាច)
* **Rathana & Kiri** — Wash Bay Team (បុគ្គលិកលាង)
* **Dara** — Lead Mechanic (ប្រធានជាងជួសជុល)
* **Sophea Chan** — Customer 1 (Walk-in SUV, Basic Wash)
* **Vannak Meas** — Customer 2 (Gold Member with 10:00 AM Appointment)
* **Bopha Kim** — Customer 3 (Mixed Order: Foam Wash + Full Synthetic Oil Change)

---

## 07:30 AM — Shop Opening & Morning Shift Initialization

1. **Kosal (Manager)** arrives, unlocks the apron, turns on the mini-PC server, powers on the waiting lounge TV, and verifies that the water booster pumps and air compressor have reached full pressure.
2. The waiting lounge TV (`tv.php`) displays the shop logo, date, and "No vehicles in queue".
3. **Sokha (Cashier)** clocks in on the staff terminal (`views/auth/login.php`) using her 6-digit PIN.
4. Sokha opens **Shift Management** (`views/cashier/shift.php`).
5. She counts the physical opening cash float in the cash drawer: `$50.00 USD` and `200,000 KHR`.
6. She enters `$50.00` and `200,000៛`, adds a note: *"Drawer clean, receipt roll full"*, and taps **[Start Shift]**. The system records `SHIFT_START` in the immutable audit log.
7. **Rathana** and **Dara** log into their respective bay tablets in Wash Bay 1 and Technical Bay 4.

---

## 08:15 AM — Customer 1: Walk-in Basic Wash (Cash Payment)

* **Vehicle**: Toyota Highlander (SUV) | License Plate: `2A-1234`
* **Customer**: Sophea Chan (Walk-in)

1. Sophea pulls up to the apron. Sokha presses `F2` on the POS keyboard and types `2A-1234`.
2. The system checks the database: No previous visit found.
3. Sokha clicks **[+ New Customer]**, enters *"Sophea Chan"*, phone *"012 345 678"*, and selects **SUV**.
4. The SmartQueueEngine estimates the wait: `Est. Wait: 0 mins (0 cars ahead)`.
5. In the services grid, Sokha selects **Premium Foam Wash** (auto-priced at `$8.00` for SUV).
6. **Smart Upsell Prompt**: The POS alerts: *"Offer Premium Tire Wax for +$2.00"*.
   - Sokha asks: *"Would you like our deep-shine tire wax today for just $2 extra?"*
   - Sophea smiles: *"Sure, go ahead."*
   - Sokha taps the upsell pill. Cart total updates to `$10.00` (41,000 ៛).
7. Sokha clicks **[Charge]** (`#btnOpenCheckout`). Sophea hands over a `$20.00` bill.
8. Sokha clicks the `$20` quick cash button. The screen immediately displays:
   `Change Due: $10.00 (or 41,000 ៛)`.
9. Tap **[Complete Cash Payment]**. The cash drawer pops with an audible ping. Sokha hands Sophea his `$10` change and receipt **#Q-001**.
10. **"No Receipt, No Wash"**: Sokha clips the receipt under Sophea's wiper blade.
11. The waiting lounge TV plays a pleasant chime and displays:
    `Q-001 | 2A-•••4 | Highlander | In Progress (Bay 1)`.
12. Rathana in Bay 1 taps **[Start Job]** on his waterproof tablet. The countdown timer begins.
13. At 08:35 AM, Rathana performs the 4-point dry inspection, checks the clean wheels, and taps **[Finish Car]**.
14. The TV chime chimes again: `Q-001 | 2A-•••4 | Ready for Pick-up`. Sophea collects his keys and drives away satisfied.

---

## 10:00 AM — Customer 2: Gold Member VIP Appointment (KHQR Scan)

* **Vehicle**: Ford Ranger (Pickup) | License Plate: `2B-9876`
* **Customer**: Vannak Meas (Gold Member)

1. Vannak pulls in at 09:58 AM (exactly 2 minutes before his 10:00 AM appointment).
2. Sokha types `2B-9876`.
3. The system instantly loads Vannak's profile with a shiny **Gold Tier Badge** and **240 Loyalty Points**.
4. **SmartQueue Priority**: Because Vannak arrived within 10 minutes of his scheduled slot, the system automatically assigns him **Priority P0 (VIP Appointment)**, moving him to the front of the queue.
5. Vannak orders an **Interior Deep Cleaning & Wash** (`$18.00`).
6. Because he is Gold Tier, `Loyalty.php` automatically applies a **10% detailing discount** (`-$1.80`). Total payable: `$16.20` (66,400 ៛).
7. Sokha asks: *"You have 240 loyalty points ($24 credit). Would you like to use points today?"*
   - Vannak says: *"No, keep saving them for my next ceramic coat."*
8. Vannak chooses **KHQR Scan**.
9. Sokha selects the **KHQR** tab. A high-contrast Bakong QR code appears on the customer display.
10. Vannak opens his ABA Mobile app, scans the code, and taps confirm for `$16.20`.
11. Sokha verifies the incoming transaction alert, taps the camera icon on the POS tablet, snaps a quick photo of Vannak's phone confirmation screen, and taps **[Complete KHQR Payment]**.
12. Order receipt prints with VIP Queue Number **#Q-004**, and the car is steered into Bay 2.

---

## 01:30 PM — Customer 3: Mixed Order (Wash + Oil Change with Handshake)

* **Vehicle**: Lexus RX350 (SUV) | License Plate: `1AB-5544`
* **Customer**: Bopha Kim

1. Bopha arrives requesting a full wash and an engine oil service.
2. Sokha enters the order:
   - **Service 1**: Full Exterior Wash ($8.00)
   - **Service 2**: Total Quartz 9000 5W-30 Full Synthetic Oil Change (4.5L @ $45.00)
   - **Service 3**: Genuine Toyota/Lexus Oil Filter ($8.00)
   - **Total Payable**: `$61.00`.
3. Because both wash and mechanical categories are present, the system automatically flags `orders.is_mixed = 1` and creates two linked child tasks:
   - *Task 1: Wash Bay Task*
   - *Task 2: Technical Bay Task*
4. Bopha pays via ABA Bank Transfer and enters the customer lounge to enjoy a cold beverage.
5. **Phase 1 (Washing)**: Car enters Wash Bay 1. Rathana finishes washing the exterior and wheels, then taps **[Finish Wash Task]**.
6. **The Handshake Protocol**:
   - The car does NOT turn to "Ready for Pick-up".
   - The Technical Bay terminal at Bay 4 flashes a bright blue notification banner:
     `🔔 Handshake Alert: Vehicle 1AB-5544 wash finished. Ready to receive in Technical Bay.`
   - Dara (Lead Mechanic) drives the Lexus onto the hydraulic lift in Bay 4 and clicks **[Receive From Wash]**.
7. **Phase 2 (Mechanical Service & Parts Request)**:
   - Dara taps **[Request Parts]** on his tablet: `Oil Filter #OF-90915` and `4.5L Total 5W-30`.
   - Warehouse controller approves the request on the stock monitor. The immutable stock ledger decrements stock by 4.5L and 1 filter unit.
   - Dara performs the digital 12-point inspection, fills the crankcase, and records:
     - Odometer: `112,400 km`
     - Next Oil Due: `122,400 km` (or 6 months ahead).
   - Dara taps **[Finish Task]**.
8. Master order completes! The waiting lounge TV chimes and alerts Bopha that her Lexus is ready.

---

## 02:00 PM — Unannounced Surprise Spot Audit

1. **Kosal (Manager)** walks up to the cashier counter: *"Sokha, time for a spot cash audit."*
2. Sokha steps back and counts all cash currently in the drawer:
   - She counts: `$188.00 USD` and `82,000 ៛`.
3. Kosal opens **Shift Management &rarr; Spot Audit** (`views/cashier/shift.php`).
4. Kosal enters Sokha's counted figures, types his manager username `kosal_mgr`, and enters his **Manager PIN**.
5. The system performs the blind check behind the scenes:
   - Opening float: `$50.00`
   - Plus Cash Sales today: `$138.00`
   - Minus Drawer Expenses: `$0.00`
   - **System Expected**: `$188.00`
   - **Counted Cash**: `$188.00`
   - **Discrepancy**: **`$0.00`** (Balanced!)
6. Kosal taps **[Sign Off Spot Audit]**. A clean audit record is permanently stored.

---

## 03:30 PM — Shift Handover (Sokha &rarr; Chanthou)

1. Sokha's morning shift ends. Chanthou arrives for the evening shift.
2. Both cashiers stand together at the counter.
3. Sokha opens **End Shift**.
4. Sokha enters her final blind cash count: `$230.00 USD` and `120,000 KHR` and enters her PIN.
5. Chanthou verifies the physical bills and enters her PIN to sign off.
6. System checks the math: Total variance is `$0.00`.
7. Sokha's shift closes cleanly. Chanthou opens her evening shift with the `$50.00` base float. The remaining cash is locked into the shop drop-safe.

---

## 08:00 PM — Day Close & Daily Z-Report

1. The last car leaves the apron. All bays are wiped and closed.
2. Kosal verifies all orders in the system are either `paid` or `cancelled`.
3. Kosal opens **Close Day (Z-Report)**.
4. He enters the counted safe cash, types his **Manager PIN**, and clicks **[Generate Final Z-Report & Lock Day]**.
5. The thermal printer prints the daily financial summary:
   - **Gross Revenue**: `$842.00`
   - **Cash Collected**: `$320.00`
   - **ABA Pay & KHQR**: `$522.00`
   - **Discounts Approved**: `$18.20`
   - **Petty Cash Expenses**: `$12.00` (Ice and drinking water for staff)
   - **Cash Variance**: `$0.00`
6. The CloudSyncManager performs its final evening push, transmitting today's Z-report, stock movements, and staff timecards to headquarters.
7. Kosal turns off the lights and locks the shop. At 23:59:00, the system automatically engages the overnight day lock.
