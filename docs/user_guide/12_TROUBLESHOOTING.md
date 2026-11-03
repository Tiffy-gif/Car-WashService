# 12. Troubleshooting & Diagnostic Guide (ការដោះស្រាយបញ្ហា និងកូដកំហុស)

This chapter provides an exhaustive diagnostic reference for every error code, hardware glitch, network disconnect, and database refusal you might encounter in daily shop operations.

---

## 1. Master System Error Reference

| Error Code | What You See on Screen | Root Cause | Step-by-Step Resolution |
|---|---|---|---|
| `DEVICE_BLOCKED` | `HTTP 403: POS Device is blocked.` | The tablet/terminal was marked `blocked` in Back Office Settings. | 1. Owner logs into Back Office.<br/>2. Open **POS Devices** (`/owner/pos_devices`).<br/>3. Locate the device and click **[Unblock Device]**.<br/>4. Refresh the tablet browser. |
| `DEVICE_KEY_REQUIRED` | `Device authentication required.` | Tablet browser cache was cleared or `X-Device-Key` local storage was lost. | 1. Open POS pairing screen (`pos/index.html#pair`).<br/>2. Enter the device pairing key from Back Office POS Devices screen.<br/>3. Tap **[Pair Device]**. |
| `UNAPPROVED_DEVICE` | `Device not recognized or key invalid.` | The device key was regenerated or does not exist in `pos_devices`. | 1. Regenerate a new device key in **POS Devices** screen.<br/>2. Re-pair the device using the fresh pairing key. |
| `DEVICE_THROTTLED` | `Too many failed attempts. Device throttled.` | Staff failed PIN entry 5+ consecutive times on this physical tablet. | 1. Wait **10 minutes** for the cooldown timer to elapse.<br/>2. Alternatively, an Owner can reset failed attempts in database. |
| `ACCOUNT_LOCKED` | `Account locked. Please try again in 10 minute(s).` | The individual user account exceeded 5 failed PIN attempts. | 1. Wait for the 10-minute security cooldown.<br/>2. Or have the Shop Manager/Owner log in and reset failed attempts in **Staff Management**. |
| `ACCOUNT_INACTIVE` | `This account has been deactivated.` | The staff member was set to `inactive` by manager or cloud sync. | 1. If staff is still employed, open **Staff Management** (`/owner/staff`).<br/>2. Change status back to `active`.<br/>3. Tap **[Save Staff]**. |
| `CUSTOMER_NO_LOGIN`| `Customer account cannot log in to POS.` | Attempted to log into the staff POS using a customer portal account. | Use a valid staff account (`cashier`, `manager`, `owner`, `washer`, `mechanic`). |
| `DEMO_DISABLED` | `Sample test account login disabled.` | Attempted to use quick demo login buttons when `demo_mode = '0'`. | Use real staff PINs, or enable Test Mode in Back Office Settings if in training. |
| `INVALID_PIN` | `Incorrect PIN. X attempt(s) remaining.` | Typo in the 6-digit staff PIN. | Carefully re-enter the correct 6-digit PIN. Verify NumLock is active. |
| `SESSION_INVALID` | `Session expired or invalid. Please log in again.` | Session timed out due to inactivity, or cloud deactivated user. | Tap **[OK]** to return to the PIN pad and re-enter your 6-digit PIN. |
| `PERMISSION_DENIED`| `Access Denied: Missing required permission.` | The logged-in role lacks the permission required for this action. | Call a Manager or Owner to perform the action or enter their Manager PIN override. |
| `DAY_LOCKED` | `Sales automatically locked at 11:59 PM.` | Time is $\ge 23:59:00$ or business day was officially closed via Z-Report. | 1. Manager must open **Shift Management &rarr; Close Day & Z-Report**.<br/>2. Generate final Z-Report to start a fresh business day.<br/>3. To unlock, Manager enters PIN with mandatory audit reason. |
| `STAFF_REQUIRED` | `Cashier staff ID is required.` | Refund or drawer event submitted without identifying the active cashier. | Ensure an active cashier shift is open and the cashier is selected. |
| `DISCOUNT_EXCEEDS_TOTAL` | `Discount cannot be negative or exceed total.` | Cashier entered a discount dollar amount greater than the cart subtotal. | Correct the discount amount so it does not exceed the order total. |
| `not_configured` | `Cloud sync not configured.` | Cloud URL, Token, or Secret is blank in Settings. | Open **Settings &rarr; Cloud Sync** tab and fill in valid cloud credentials. |
| `SQLSTATE 45000` | `Database trigger rejection: Immutable record.` | Attempted to run an `UPDATE` or `DELETE` on stock ledger or audit logs. | The ledger is append-only. Use **Add Correction Line** instead of editing past rows. |

---

## 2. Hardware & Peripheral Troubleshooting

### A. Thermal Receipt Printer Not Printing (Port 1818 Error)
* **Symptom**: Cashier clicks **[Complete Payment]**, screen spins, but no paper prints, or shows `Printer Connection Error: Port 1818 unreachable`.
* **Step-by-Step Fix**:
  1. **Check Power & Paper**: Ensure the printer power LED is solid blue/green and paper roll has not run out (no red error flash).
  2. **Check USB / LAN Cable**: Verify cable is firmly seated in the printer and mini-PC.
  3. **Restart Print Service**: If using a local thermal print bridge on port 1818:
     - Open Windows Task Manager on mini-PC.
     - Locate `ThermalPrintServer` / `PrintSpooler`.
     - Click **[Restart Service]**.
  4. **Emergency Browser Print Fallback**:
     - The POS includes an automatic fallback: If direct ESC/POS port 1818 is unreachable, it opens the browser print modal (`Ctrl+P`).
     - Click **[Print]** to output to any standard Windows printer.

---

### B. Cash Drawer Does Not Pop Open Automatically
* **Symptom**: Receipt prints normally, but the cash drawer stays locked shut.
* **Step-by-Step Fix**:
  1. **Check the RJ11 Cable**: The cash drawer connects to the **back of the receipt printer** via an RJ11 phone-style telephone jack. Ensure this cable is plugged tightly into the printer's `DK` (Drawer Kick) port, NOT the telephone wall jack.
  2. **Check Physical Key Lock**: The drawer has a 3-position physical key:
     - Position 1: Locked shut (prevents solenoid pop).
     - Position 2: Electronic release (Normal operating mode).
     - Position 3: Manual open.
     *Ensure the physical key is turned to **Position 2** (Vertical).*
  3. **Check Drawer Pulse Setting**:
     - Open Back Office &rarr; **Settings &rarr; POS Devices**.
     - Verify `drawer_kick_command` is set to standard ESC/POS `\x1B\x70\x00\x19\xFA`.

---

### C. Waiting Lounge TV Shows "Secret Code Incorrect" or Freezes
* **Symptom**: TV displays: `This queue display is currently disabled or the secret code is incorrect.`
* **Step-by-Step Fix**:
  1. Open Back Office &rarr; **Settings &rarr; General / TV Settings**.
  2. Inspect the **TV Secret Code** (e.g., `tv_secret_2026`).
  3. On the TV web browser, check the address bar URL:
     Ensure it matches: `http://<mini-pc-ip>/Car-WashService/tv.php?code=tv_secret_2026`.
  4. If the TV stream freezes (Server-Sent Events disconnects due to Wi-Fi jitter), the TV display automatically falls back to background polling every 5 seconds. If frozen completely, press `F5` on the wireless keyboard or reload the browser page.

---

### D. Tablet Screens Show "Network Disconnected" (Local Wi-Fi Drop)
* **Symptom**: Tablets in wash bays show spinning loader or disconnection toast.
* **Step-by-Step Fix**:
  1. Verify the mini-PC running the shop server has not entered sleep mode. In Windows Settings &rarr; Power & Sleep, ensure **"Sleep" is set to "Never"**.
  2. Check the shop Wi-Fi access point: Reboot the wireless router.
  3. Verify tablet Wi-Fi: Ensure the tablet is connected to the shop's private workshop network (e.g. `CarWash_Staff_5G`), NOT a guest or mobile cellular hotspot.
  4. Test mini-PC IP address: On the tablet browser, type `http://192.168.1.100/Car-WashService/pos` (replace with your server's static IP).
