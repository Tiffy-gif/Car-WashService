# First-Start Commissioning Checklist (បញ្ជីត្រួតពិនិត្យការចាប់ផ្តើមអាជីវកម្មដំបូង)

This checklist is the step-by-step commissioning path for a shop owner setting up a brand-new car wash and detailing workshop from scratch. Follow these steps in order to configure your shop parameters, set your prices, enroll your staff, pair your tablets, and execute a practice business day.

---

## 1. First Login & Browser Preparation

### Where to click:
1. Open Google Chrome on your computer.
2. Press **`F12`** &rarr; Select the **Application** tab &rarr; Click **Storage** on the left &rarr; Click **[Clear site data]**. *(This purges old test session tokens and unregisters any stale service workers).*
3. Navigate to: `http://localhost/Car-WashService/index.php?view=login`

### What to enter:
* **Username**: `owner`
* **6-digit PIN**: The 6-digit PIN you created during the installation reset.
* Click **[Sign In]** (ចូលប្រព័ន្ធ).

### Why it matters:
Logging in as the primary Owner grants access to all 32 configuration menus and master settings.

### You know it worked when:
The Back Office dashboard opens with the welcome banner showing your shop name and role badge **Owner**.

---

## 2. Master Back Office Settings (Tab-by-Tab Configuration)

Navigate to **Settings &rarr; System Settings** (`views/owner/settings.php`). Configure each tab in order:

### Tab 1: Shop Info (ព័ត៌មានហាង)
* **Where to click**: Tab **Shop Info**
* **What to enter**:
  - **Shop Name (English)**: Your business name (e.g. `Diamond Sparkle Car Wash & Detailing`).
  - **Shop Name (Khmer)**: e.g. `ដាយមិន សេវាកម្មលាងរថយន្ត`.
  - **Shop Address**: Physical street address (e.g. `No. 45, St. 2004, Sangkat Kakab, Phnom Penh`).
  - **Shop Phone Numbers**: Customer support contact numbers (e.g. `012 888 999 / 097 555 666`).
  - **Shop Logo**: Upload your high-resolution PNG/JPG brand logo.
* **Why it matters**: These details print directly at the top of every customer thermal receipt and appear on the waiting lounge TV board.
* **You know it worked when**: You click **[Save Settings]** and the header logo and shop title update across the navigation bar.

---

### Tab 2: Receipt Settings (ការកំណត់វិក្កយបត្រ)
* **Where to click**: Tab **Receipt**
* **What to enter**:
  - **Receipt Header & Footer**: e.g. `Thank you for your visit! / សូមអរគុណ! សូមអញ្ជើញមកម្តងទៀត`.
  - **Receipt Language**: Select `both` (Bilingual English & Khmer) or `km` / `en`.
  - **Show Logo on Receipt**: Toggle `ON` (if your thermal printer supports graphic logos).
  - **Require Receipt Before Wash**: Toggle **`ON`** (enforces the anti-theft *"No Receipt, No Wash"* rule).
* **Why it matters**: Protects cash inflows by ensuring no washer touches a vehicle without an official system receipt sitting on the dashboard.
* **You know it worked when**: Receipts print with bilingual headers and footers.

---

### Tab 3: Money & Currency Exchange (រូបិយប័ណ្ណ និងអត្រាប្តូរប្រាក់)
* **Where to click**: Tab **Money & Rate**
* **What to enter**:
  - **Exchange Rate (1 USD = KHR)**: e.g. `4100` (or your local market rate).
  - **Timezone**: Ensure `Asia/Phnom_Penh` is selected.
* **Why it matters**: The POS automatically calculates dual-currency totals and change due in both USD and Khmer Riel.
* **You know it worked when**: POS checkout displays accurate Riel conversions (e.g. $10.00 = 41,000 ៛).

---

### Tab 4: ABA Pay & KHQR Stand (ការទូទាត់តាមធនាគារ)
* **Where to click**: Tab **ABA & Bank**
* **What to enter**:
  - **ABA Merchant Account Name**: The exact name on your business bank account (e.g. `DIAMOND CAR CARE CO., LTD`).
  - **ABA Merchant Account Number**: Your 9-digit account number (e.g. `001 888 999`).
  - **KHQR Stand Image**: Upload a high-contrast image of your Bakong/ABA KHQR merchant stand.
* **Why it matters**: Enables the ABA Pay and KHQR tabs in the POS payment modal. If left blank, the POS displays a warning banner and blocks digital bank payments.
* **You know it worked when**: The POS checkout modal displays your ABA account details and QR scan options.

---

### Tab 5: Bays & Queue Timing (កន្លែងលាង និងជួរ)
* **Where to click**: Tab **Bays & Queue**
* **What to enter**:
  - **Estimate Buffer Percentage**: Set to `10%` or `15%` (adds a safety cushion to wait time estimates).
  - **Delay Reasons List**: Review standard delay reasons (e.g. *Heavy mud underbody scrub*, *Interior stain extraction*, *Stuck oil bolt*).
  - **TV Display Secret Code**: Verify the auto-generated 16-character code (used to connect the waiting room TV).
* **Why it matters**: Powers the `SmartQueueEngine` calculations and ensures public TV boards only connect with authorized tokens.
* **You know it worked when**: The waiting lounge TV displays live queue numbers without error.

---

### Tab 6: Oil & Lubricant Care Reminders (ការរំលឹកប្រេង)
* **Where to click**: Tab **Oil Reminders**
* **What to enter**:
  - **Default Oil Due Mileage**: e.g. `7000` km (semi-synthetic) or `10000` km (full synthetic).
  - **Default Oil Due Months**: e.g. `6` months.
* **Why it matters**: When mechanics complete an oil change, the system automatically calculates the vehicle's next maintenance date using these baselines.
* **You know it worked when**: The vehicle service sticker and oil care monitor flag vehicles due for maintenance.

---

### Tab 7: Loyalty Program & Membership Tiers (ភាពស្មោះត្រង់)
* **Where to click**: Tab **Loyalty & Tiers**
* **What to enter**:
  - **Earn Rate**: `1` point per $1.00 spent.
  - **Redeem Rate**: `10` points = $1.00 credit discount.
  - **Gold Tier Threshold**: `200` points (1.5x multiplier, 10% detailing discount).
  - **Platinum Tier Threshold**: `500` points (2.0x multiplier, 15% detailing discount).
* **Why it matters**: Automatically rewards repeat customers and encourages enrollment in detailing services.
* **You know it worked when**: Customers earn points at POS and see their tier badge upgrade automatically.

---

### Tab 8: Operational Performance Targets (គោលដៅប្រតិបត្តិការ)
* **Where to click**: Tab **Performance**
* **What to enter**:
  - **Cars Per Labor Hour Target**: Set to `2.0` (standard industry efficiency benchmark).
  - **Shop Operating Hours Per Day**: e.g. `10.0` hours (e.g. 07:30 to 17:30).
  - **Enable Staff Leaderboard**: Toggle `ON` to motivate bay teams.
* **Why it matters**: Used in `PerformanceAnalytics.php` to calculate workshop utilization and staff productivity scores.
* **You know it worked when**: The Manager Dashboard displays green/red productivity variance gauges.

---

### Tab 9: Telegram Notification Alerts (ការជូនដំណឹងតេឡេក្រាម)
* **Where to click**: Tab **Telegram**
* **What to enter**:
  - **Telegram Bot Token**: (Optional) Enter your Telegram Bot API token.
  - **Telegram Chat ID**: (Optional) Enter your private owner channel ID for instant daily close alerts.
* **Why it matters**: Delivers real-time notifications when a cashier closes their shift or reports a cash shortage.

---

### Tab 10: Cloud Synchronization (សមកាលកម្មក្លោដ)
* **Where to click**: Tab **Cloud Sync**
* **Action**:
  - **Leave empty / disabled** for initial single-shop standalone setup.
  - When your headquarters management portal issues your credentials, enter the **Cloud API URL**, **Shop Token**, and **Shop Secret**.
* **You know it worked when**: The Cloud Sync Monitor displays `Status: Not Configured (Local Standalone Mode)`.

---

## 3. Categories & Vehicle Pricing Matrix

### Step A: Verify Categories
* **Where**: **Catalog & Stock &rarr; Categories** (`views/owner/categories.php`)
* **Verify**: Ensure the core categories exist: `Washing Services (wash)`, `Basic Maintenance (repair)`, `Engine Oils (engine_oil)`, `Oil Filters (oil_filter)`, `Fluids & Coolants (fluid)`.

### Step B: Services & Vehicle Size Prices
* **Where**: **Catalog & Stock &rarr; Services & Pricing** (`views/owner/services.php`)
* **Actions**:
  1. For each service (e.g. *Premium Foam Wash*), click **[Edit Prices]**.
  2. Set prices per vehicle size:
     - **Small Car (Sedan / Vios / Morning)**: e.g. `$4.00`
     - **Medium Car (Camry / Civic)**: e.g. `$5.00`
     - **SUV / Crossover (Highlander / RX350)**: e.g. `$7.00`
     - **Large Pickup / 4x4 (Ford Ranger / Hilux)**: e.g. `$8.00`
     - **Van / Minibus (Starex / Alphard)**: e.g. `$10.00`
     - **Motorbike**: e.g. `$2.00`
  3. Set **Base Duration**: e.g. `20` minutes.
* **You know it worked when**: Selecting an SUV on the POS immediately populates the correct $7.00 price.

---

## 4. Warehouse Opening Stock Intake

* **Where**: **Catalog & Stock &rarr; Stock Ledger** (`views/owner/stock_ledger.php`)
* **Actions**:
  1. Under the **"Receive Supplier Stock"** panel:
  2. Select your bulk lubricants and filters (e.g. *Total Quartz 9000 5W-30 (4L)*).
  3. Enter physical quantity on shelves (e.g. `24` units).
  4. Type Supplier Invoice Ref: `INITIAL-STOCKTAKE`.
  5. Click **[Confirm & Receive Stock]**.
* **You know it worked when**: The Stock Ledger adds an immutable row `Type: received | Qty: +24` and the POS product tile turns from red (Out of Stock) to active green.

---

## 5. Physical Bays & Skills Setup

* **Where**: **Settings &rarr; Bays & Queue** or **Bays & Queue Monitor** (`views/owner/bays_queue.php`)
* **Actions**:
  1. Verify physical bays match your shop floor layout:
     - **Bay 1**: Wash & Foam Lane
     - **Bay 2**: Wash & Foam Lane
     - **Bay 3**: Detailing & Drying Lane
     - **Bay 4**: Technical Lift & Oil Change Lane (tagged with `Skill: mechanical`)
  2. Ensure all bays show status **Available / Free**.

---

## 6. Staff Onboarding & Roles

Navigate to **Staff & Permissions &rarr; Staff Management** (`views/owner/staff.php`). Create accounts for your real team:

1. **Shift Manager**:
   - Username: `manager1` | Role: `Manager` | 6-digit PIN: `[Private]`
2. **Cashier**:
   - Username: `cashier1` | Role: `Cashier` | 6-digit PIN: `[Private]`
3. **Wash Bay Team**:
   - Username: `washer1` | Role: `Washer` | Commission: `$0.50 per wash`
   - Username: `washer2` | Role: `Washer` | Commission: `$0.50 per wash`
4. **Lead Mechanic**:
   - Username: `mechanic1` | Role: `Mechanic` | Commission: `$2.00 per oil service`
5. **Warehouse Controller**:
   - Username: `stock1` | Role: `Stock Control`

> [!TIP]
> Instruct each staff member to log in and immediately change their default PIN to their own private PIN via **Change PIN** (`views/auth/change_pin.php`).

---

## 7. Pairing Tablets & Customer Lounge TV

### A. Pairing Counter & Bay Tablets:
1. Open **Settings &rarr; POS Devices** (`views/owner/pos_devices.php`).
2. Click **[+ Add New Device]**:
   - Device Name: `Counter Cashier iPad`
   - Location: `Front Counter`
3. Copy the generated **Device Pairing Key**.
4. On the tablet browser, navigate to: `http://<server-ip>/Car-WashService/pos/index.html#pair`
5. Paste the pairing key and tap **[Pair Device]**. The tablet is now cryptographically linked.

### B. Connecting Customer Waiting Lounge TV:
1. On the lounge Smart TV or TV mini-PC browser, open:
   `http://<server-ip>/Car-WashService/tv.php?code=<your_tv_secret_code>`
2. Press **`F11`** for full-screen display.
3. The TV displays the live bay grid, waiting queue, and customer pickup board.

---

## 8. Practice Business Day (Dry Run Workflow)

Before opening to paying customers, perform one complete test loop:

1. **Open Cashier Shift**:
   - Log in as `cashier1`. Open **Shift Management** (`/shift`).
   - Enter opening float: `$50.00` and `200,000៛`. Tap **[Start Shift]**.
2. **Ring Up a Walk-in Order**:
   - Open POS. Press `F2` and enter test plate `2A-9999`.
   - Register customer *"Test Customer"* (SUV).
   - Add *Premium Foam Wash* ($7.00). Offer *Tire Wax* ($2.00).
   - Checkout with Cash ($10.00 tendered &rarr; $1.00 change).
   - Print receipt **#Q-001**.
3. **Execute Wash in Bay 1**:
   - Log into Bay 1 tablet as `washer1`.
   - Tap **[Start Job]**. Watch timer turn green.
   - Tap **[Finish Car]**. Listen for TV lounge audio chime!
4. **Ring Up a Mixed Order (Wash + Oil)**:
   - Create order for Sedan `2B-8888`: *Basic Wash* ($4.00) + *Total Synthetic Oil* ($35.00).
   - Complete payment. System splits into Wash Task and Tech Task.
   - Washer finishes wash &rarr; Handshake alert flashes at Bay 4.
   - Mechanic taps **[Receive From Wash]** &rarr; Requests oil filter &rarr; Records odometer &rarr; Taps **[Finish Task]**.
5. **End Shift & Close Business Day**:
   - Cashier opens **End Shift**. Performs blind count. Incoming cashier signs off.
   - Manager opens **Close Day (Z-Report)**. Enters safe cash and types Manager PIN.
   - Daily Z-report prints on thermal paper. Day lock engages automatically.
6. **Review Reports**:
   - Open **Reports & Analytics** (`/reports`) and **Audit Logs** (`/audit_logs`) to verify all sales, drawer events, and commission balances recorded accurately.

**Congratulations! Your shop is fully commissioned and ready for live commercial operation.**
