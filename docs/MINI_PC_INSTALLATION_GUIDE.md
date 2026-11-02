# Mini-PC Shop Server Setup & Hardening Guide
**AquaGleam Car Wash & Detailing — Back Office Server**

This step-by-step guide is written in plain language for shop managers and technicians. It explains how to set up the Windows Mini-PC, ensure it automatically restarts and recovers after power cuts, and protect the system for secure daily operation.

---

## Table of Contents
1. [Hardware & BIOS Setup (Auto-Power-On)](#1-hardware--bios-setup-auto-power-on)
2. [Step 1: Install XAMPP](#2-step-1-install-xampp)
3. [Step 2: Auto-Restart with Windows Services & NSSM](#3-step-2-auto-restart-with-windows-services--nssm)
4. [Step 3: Set a Strong Database Password](#4-step-3-set-a-strong-database-password)
5. [Step 4: Change the Default Database Port (3307)](#5-step-4-change-the-default-database-port-3307)
6. [Step 5: Block phpMyAdmin from Public Access](#6-step-5-block-phpmyadmin-from-public-access)
7. [Step 6: Set Up a Local Secure Address (Static IP & Local Domain)](#7-step-6-set-up-a-local-secure-address)
8. [Step 7: Keep Guest Wi-Fi Separate (Network Isolation)](#8-step-7-keep-guest-wi-fi-separate)
9. [Step 8: Configure Automated 2:00 AM Daily Backups](#9-step-8-configure-automated-200-am-daily-backups)
10. [Step 9: Disaster Mode, Paper Forms & Recovery Entry](#10-step-9-disaster-mode-paper-forms--recovery-entry)
11. [Step 10: Queue TV Realtime Streaming (SSE) & Apache Worker Limits on XAMPP](#11-step-10-queue-tv-realtime-streaming-sse--apache-worker-limits-on-xampp)
12. [Step 11: Install as PWA (Progressive Web App on Tablets)](#12-step-11-install-as-pwa-progressive-web-app-on-tablets)
13. [Step 12: Configure Background Cloud Sync (Windows Task Scheduler)](#13-step-12-configure-background-cloud-sync-windows-task-scheduler)

---

## 1. Hardware & BIOS Setup (Auto-Power-On)

When a power cut happens in Cambodia, electricity will eventually return. The mini-PC must turn itself back on without anyone having to press the power button:

1. Turn on the mini-PC and immediately press **Del** or **F2** repeatedly to enter the **BIOS / UEFI Settings**.
2. Look for **Power Management** or **Chipset Settings**.
3. Find the setting named **"Restore on AC Power Loss"** (or **"After Power Loss"**).
4. Change it from *Power Off* to **"Power On"** (or **"Last State"**).
5. Press **F10** &rarr; select **Save and Exit**.

> **Battery Tip**: Connect the mini-PC and Wi-Fi router to an inexpensive 600VA&ndash;1000VA UPS battery backup. This gives 30&ndash;60 minutes of uninterrupted run time during quick power flickers.

---

## 2. Step 1: Install XAMPP

1. Download **XAMPP for Windows (PHP 8.2 or 8.1)** from [apachefriends.org](https://www.apachefriends.org).
2. Run the installer as Administrator.
3. When prompted for components, select:
   - **Apache**
   - **MySQL**
   - **PHP**
   - *(Uncheck FileZilla FTP, Mercury Mail, and Tomcat)*
4. Install to the default folder: `C:\xampp`.
5. Deploy the Car Wash Back Office files into:
   ```
   C:\xampp\htdocs\Car-WashService
   ```

---

## 3. Step 2: Auto-Restart with Windows Services & NSSM

To ensure Apache and MySQL start automatically in the background as soon as Windows boots (even before any user logs in), install them as resilient Windows Services using **NSSM (Non-Sucking Service Manager)**:

### A. Download NSSM
1. Download NSSM from [nssm.cc](https://nssm.cc/download).
2. Copy `nssm.exe` (from the `win64` folder) into `C:\Windows\System32`.

### B. Register Apache as an Auto-Restarting Service
Open **Command Prompt as Administrator** and run:
```cmd
nssm install CarWashApache "C:\xampp\apache\bin\httpd.exe"
nssm set CarWashApache AppDirectory "C:\xampp\apache"
nssm set CarWashApache Start SERVICE_AUTO_START
nssm set CarWashApache AppRestartDelay 5000
nssm start CarWashApache
```

### C. Register MySQL as an Auto-Restarting Service
In the same Administrator Command Prompt, run:
```cmd
nssm install CarWashMySQL "C:\xampp\mysql\bin\mysqld.exe" "--defaults-file=C:\xampp\mysql\bin\my.ini"
nssm set CarWashMySQL AppDirectory "C:\xampp\mysql"
nssm set CarWashMySQL Start SERVICE_AUTO_START
nssm set CarWashMySQL AppRestartDelay 5000
nssm start CarWashMySQL
```

### D. Verify Both Services
1. Press `Win + R` &rarr; type `services.msc` &rarr; press Enter.
2. Verify that **CarWashApache** and **CarWashMySQL** are listed with **Status: Running** and **Startup Type: Automatic**.
3. If the process crashes or power flickers, NSSM will automatically restart the services within 5 seconds.

---

## 4. Step 3: Set a Strong Database Password

By default, XAMPP ships with a blank MySQL root password. Change it immediately:

1. Open Command Prompt as Administrator and run:
   ```cmd
   cd C:\xampp\mysql\bin
   mysqladmin.exe -u root password "AquaGleam2026!Secure"
   ```
2. Open `C:\xampp\htdocs\Car-WashService\config\database.php` in a text editor and update the password:
   ```php
   'password' => 'AquaGleam2026!Secure',
   ```
3. Test that the site loads normally in the browser (`http://localhost/Car-WashService`).

---

## 5. Step 4: Change the Default Database Port (3307)

Changing the MySQL port from default `3306` to `3307` prevents port collisions and shields the database from automated port-scanning tools:

1. Open `C:\xampp\mysql\bin\my.ini` in Notepad.
2. Search for `port=3306` and replace both occurrences with `port=3307`:
   ```ini
   [client]
   port=3307

   [mysqld]
   port=3307
   ```
3. Save and close `my.ini`.
4. Open `C:\xampp\htdocs\Car-WashService\config\database.php` and set `'port' => 3307`:
   ```php
   'port' => 3307,
   ```
5. Restart the MySQL service:
   ```cmd
   nssm restart CarWashMySQL
   ```

---

## 6. Step 5: Block phpMyAdmin from Public Access

Tablets and cashier devices should never be able to view or tamper with phpMyAdmin. Restrict phpMyAdmin so it can only ever be opened directly on the mini-PC console:

1. Open `C:\xampp\apache\conf\extra\httpd-xampp.conf` in Notepad.
2. Scroll to the bottom to find `<Directory "C:/xampp/phpMyAdmin">`.
3. Replace the block with the following strict restriction:
   ```apache
   <Directory "C:/xampp/phpMyAdmin">
       AllowOverride AuthConfig
       Require local
       ErrorDocument 403 /Car-WashService/index.php
   </Directory>
   ```
4. Save the file and restart Apache:
   ```cmd
   nssm restart CarWashApache
   ```
5. Now, only someone sitting in front of the physical mini-PC screen can access phpMyAdmin. Tablets across the shop will receive a 403 Forbidden redirect.

---

## 7. Step 6: Set Up a Local Secure Address

### A. Assign a Static IP to the Mini-PC
1. Open Windows **Settings** &rarr; **Network & Internet** &rarr; **Ethernet** (or Wi-Fi).
2. Under IP assignment, click **Edit** &rarr; choose **Manual** &rarr; enable **IPv4**.
3. Set a static address outside your router's DHCP pool:
   - **IP Address**: `192.168.1.100`
   - **Subnet Mask**: `255.255.255.0`
   - **Gateway**: `192.168.1.1`
   - **DNS**: `1.1.1.1`, `8.8.8.8`

### B. Allow Port 80 & 443 in Windows Firewall
In Administrator Command Prompt, run:
```cmd
netsh advfirewall firewall add rule name="CarWash Web Server" dir=in action=allow protocol=TCP localport=80,443
```

### C. Friendly Local Address
On the cashier and tablet devices, edit `C:\Windows\System32\drivers\etc\hosts` (or set a router local DNS entry) mapping:
```
192.168.1.100   carwash.local
```
Staff can simply open `http://carwash.local/Car-WashService` on any tablet.

---

## 8. Step 7: Keep Guest Wi-Fi Separate (Network Isolation)

Customer smartphones on the waiting room Wi-Fi must **never** be able to communicate with the shop server or cashier tablets:

1. Log into your shop Wi-Fi Router admin page (usually `http://192.168.1.1`).
2. Create **two separate Wi-Fi networks**:
   - **Staff Wi-Fi** (`AquaGleam-Staff`):
     - WPA3 / WPA2-AES password (known only to owner/manager).
     - Connect the mini-PC, cashier tablets, washer phones, and Queue TV.
   - **Customer Wi-Fi** (`AquaGleam-Customer-Free`):
     - Enable **Guest Network Isolation** (also called *AP Isolation* or *Client Isolation*).
     - Put customers on a separate subnet (e.g. `192.168.2.x`).
3. Under router settings, ensure **"Allow guests to see local network devices"** is unchecked.
4. Test from a customer phone: try browsing to `http://192.168.1.100`. The browser must timeout and fail to connect.

---

## 9. Step 8: Configure Automated 2:00 AM Daily Backups

The system includes a built-in automated backup script that saves a complete SQL dump every morning at 2:00 AM and automatically rotates out files older than 14 days.

### Schedule the Task in Windows:
1. Open **Task Scheduler** (`Win + R` &rarr; `taskschd.msc`).
2. Click **Create Basic Task...** on the right panel.
3. Set:
   - **Name**: `Car Wash 2AM Daily Database Backup`
   - **Trigger**: `Daily` at `2:00:00 AM`
   - **Action**: `Start a program`
   - **Program/script**: `C:\xampp\htdocs\Car-WashService\bin\backup_daily.bat`
   - **Start in**: `C:\xampp\htdocs\Car-WashService`
4. In Task Properties, check **"Run whether user is logged on or not"** and **"Run with highest privileges"**.

### How to Restore a Backup (Plain Language):
If the mini-PC is ever replaced or damaged:
1. Open Command Prompt as Administrator:
   ```cmd
   cd C:\xampp\mysql\bin
   ```
2. Run the one-line restore command:
   ```cmd
   mysql.exe -u root -p carwash_db < "C:\xampp\htdocs\Car-WashService\storage\backups\CarWash_AutoBackup_YYYY-MM-DD.sql"
   ```
3. Type your MySQL password and press **Enter**. In less than 10 seconds, all sales, staff, and historical data will be restored.

---

## 10. Step 9: Disaster Mode, Paper Forms & Recovery Entry

During extended power cuts or total network blackouts, the shop server provides three built-in disaster recovery tools:

1. **Printable Job Cards & Paper Logbook** (`index.php?view=paper_forms`):
   - Print a stack of 50 blanks in advance.
   - Washers use the physical cards to record plate numbers, vehicle types, service checklists, and cash collected.
2. **60-Second Tablet Heartbeat & Manual Mode Receipts**:
   - If a tablet loses connection with the mini-PC for more than 60 seconds, a yellow alert banner appears across the top:
     > *"⚠️ Offline - Cannot reach Shop Server for >60s. Use Manual Mode."*
   - Cashiers can tap **"Manual Mode Receipt (PDF)"** to generate offline customer receipts with auto-calculated totals and print or save them as PDFs.
3. **Bulk Recovery Entry Screen** (`index.php?view=recovery_bulk_entry`):
   - When electricity returns, the cashier or manager opens this screen.
   - Rapidly type all accumulated paper slip records into a spreadsheet-style table.
   - Upon clicking **"Save & Recover Paper Records"**, every order is created with its original paper timestamps and permanently marked with the badge:
     ```
     [ entered after recovery ]
     ```
   - Records the author's name, timestamp, and an audit trail entry.

---

## 11. Step 10: Queue TV Realtime Streaming (SSE) & Apache Worker Limits on XAMPP

The customer waiting lounge and bay Queue TVs use **Server-Sent Events (SSE)** via `api/queue_stream.php` to receive instant screen updates whenever queue status or bay assignments change.

### Apache Worker Limits on Windows XAMPP:
- Windows XAMPP runs the `mpm_winnt` multi-processing module with a fixed pool of worker threads (default `ThreadsPerChild 150`).
- Unlike standard HTTP requests that terminate in milliseconds, SSE connections remain open to stream live events. Having many persistent TV connections could tie up worker threads and prevent other POS tablets from connecting.

### How the System Protects Against Worker Exhaustion:
1. **Immediate Session Release**: `api/queue_stream.php` executes `session_write_close()` before beginning its event loop, preventing session file locks from stalling concurrent POS requests.
2. **Bounded Connection Cycling (`tv_stream_max_seconds`)**:
   - Each stream connection runs for up to approximately 25 seconds (configurable via the `tv_stream_max_seconds` setting in `system_settings`), sends events when queue data changes, and sends a `: keep-alive` comment every 10 seconds.
   - At the 25-second mark, the script cleanly completes the HTTP response, releasing the Apache worker thread back to the pool.
   - The browser's native `EventSource` automatically and transparently reconnects within seconds.
3. **Automatic 3-Error Polling Fallback**:
   - If EventSource encounters three consecutive connection failures, `tv.php` automatically transitions to periodic HTTP polling (`tv.php?action=feed`) as a resilient fallback.

---

## 12. Step 11: Install as PWA (Progressive Web App on Tablets)

The Counter POS (`/pos/index.html`) is built as a lightweight Progressive Web App (PWA) designed for touch-screen Android tablets and iPads with no heavy frameworks. When installed to the tablet home screen, it launches in fullscreen standalone kiosk mode with instant offline shell loading.

### A. Prerequisite: HTTPS & Local Root CA (`mkcert`)
Modern browsers (Chrome, Edge, Safari) strictly require **HTTPS** (or localhost) to allow Service Worker registration and PWA installation to the home screen. On a local shop LAN without a public domain name, use **`mkcert`** to create a locally trusted Certificate Authority.

1. **Install `mkcert` on the Mini-PC Server**:
   - Download the Windows binary from [github.com/FiloSottile/mkcert/releases](https://github.com/FiloSottile/mkcert/releases) (or install via Chocolatey: `choco install mkcert`).
   - Open PowerShell as Administrator and initialize the local root CA:
     ```powershell
     mkcert -install
     ```
2. **Generate Apache SSL Certificate for Local IP**:
   - Run the command with your mini-PC's static LAN IP and local hostnames:
     ```powershell
     mkcert -cert-file C:\xampp\apache\conf\ssl.crt\server.crt -key-file C:\xampp\apache\conf\ssl.key\server.key 192.168.1.100 carwash.local localhost 127.0.0.1
     ```
3. **Enable SSL in XAMPP Apache**:
   - Ensure `conf/extra/httpd-ssl.conf` points to `server.crt` and `server.key`.
   - Restart Apache service via `nssm restart CarWashApache`.
4. **Install Root CA on Tablets**:
   - Locate the root CA on the mini-PC by running `mkcert -CAROOT` (usually `rootCA.pem`).
   - Transfer `rootCA.pem` to each tablet (via USB, email, or local HTTP download):
     - **Android Tablets**: Open **Settings** &rarr; **Security** (or **Security & Privacy**) &rarr; **Encryption & Credentials** &rarr; **Install a certificate** &rarr; **CA certificate** &rarr; select `rootCA.pem` and confirm.
     - **iPads (iOS/iPadOS)**: AirDrop or open `rootCA.pem` in Safari &rarr; install the configuration profile in **Settings** &rarr; **Profile Downloaded** &rarr; then navigate to **Settings** &rarr; **General** &rarr; **About** &rarr; **Certificate Trust Settings** &rarr; toggle **Enable Full Trust for Root Certificates** on.

> **Note**: While running locally on the mini-PC browser itself (`http://localhost/Car-WashService/pos/` or `http://127.0.0.1/`), the Service Worker registers automatically without HTTPS. Over plain LAN HTTP, the service worker registration is skipped silently to avoid browser security exceptions.

---

### B. Installing on Android / Chrome Tablets
1. Connect the tablet to the dedicated shop Wi-Fi network.
2. Open **Google Chrome** (or Microsoft Edge).
3. Navigate to:
   ```
   https://192.168.1.100/Car-WashService/pos/
   ```
4. Verify the green padlock icon appears in the address bar (indicating trusted HTTPS).
5. Tap the browser menu (**⋮** three dots in the top right).
6. Tap **"Add to Home screen"** or **"Install app"** (or click the install prompt in the address bar).
7. Confirm the name **"Car Wash POS"** and tap **Install**.
8. The POS icon will appear on the Android home screen. Tapping it opens the POS in fullscreen standalone kiosk mode with no browser address bar.

---

### C. Installing on iPad / Safari Tablets
1. Connect the iPad to the shop Wi-Fi network.
2. Open **Safari**.
3. Navigate to:
   ```
   https://192.168.1.100/Car-WashService/pos/
   ```
4. Tap the **Share** button (the square icon with an upward-pointing arrow).
5. Scroll down the sharing menu and tap **"Add to Home Screen"**.
6. Verify the name displays **"Car Wash POS"** and tap **Add**.
7. Launch the app from the iPad home screen. It will open as a borderless full-screen web app.

---

### D. Offline Behavior & Local Storage Queuing
- **Instant App Shell**: The service worker automatically caches HTML, CSS, JavaScript, fonts, and icon assets. If the network hiccups or drops, the user interface continues loading instantly.
- **API Exclusions**: API calls (`/api/`) and session authentication are never cached by the service worker to prevent stale data or credential leaks.
- **Offline Orders**: When offline, new customer orders are safely queued in tablet `localStorage` (`cw_pos_pending_orders`) and sync to the server when connection returns.
- **60-Second Warning**: If disconnected from the server for over 60 seconds, the top alert banner activates:
  ```
  Offline - Use Manual Mode
  ```

---

### E. Version Bumping & Automatic Tablet Updates
When new code changes are deployed to `pos/` (updates to JavaScript, CSS, or templates), release them to connected tablets using the cache-busting mechanism:

1. Open `pos/sw.js` in a text editor.
2. Locate the `POS_VERSION` constant at the top of the file:
   ```javascript
   const POS_VERSION = 'v20261030_101'; // <-- Bump this string
   ```
3. Change it to the new release tag, for example:
   ```javascript
   const POS_VERSION = 'v20261030_102';
   ```
4. Save the file.
5. When tablets next access or refresh the POS, the browser detects the updated `sw.js` in the background, pre-caches the updated files into the new cache bucket, and triggers an on-screen toast:
   > *"New version available - tap to reload"*
6. Cashiers simply tap the notification or refresh the screen to switch to the new version. The service worker automatically cleans up obsolete cache versions.

---

## 13. Step 12: Configure Background Cloud Sync (Windows Task Scheduler)

The local shop server runs offline-first and queues all operational ledgers (sales, job orders, stock deductions, shifts, and audit logs). When an internet connection is present, it transmits these records to the Cloud Master and pulls updated catalog prices, staff accounts, and rules.

To keep data synced automatically without slowing down cashier tablets, configure Windows Task Scheduler to run the dedicated CLI sync runner every 3 minutes.

### A. Dedicated Runner Scripts
- **CLI Runner**: `C:\xampp\htdocs\Car-WashService\scripts\sync_runner.php`
- **Batch Wrapper**: `C:\xampp\htdocs\Car-WashService\bin\sync_runner.bat`
- **Process Lock**: `C:\xampp\htdocs\Car-WashService\storage\sync.lock` (prevents overlapping sync executions)

### B. Option 1: Quick Setup via Command Prompt (Recommended)
Open **Command Prompt as Administrator** and run:
```cmd
schtasks /create /tn "CarWashCloudSync" /tr "C:\xampp\htdocs\Car-WashService\bin\sync_runner.bat" /sc minute /mo 3 /ru "SYSTEM" /f
```
This registers a high-priority system task that executes `bin\sync_runner.bat` every 3 minutes in the background, even when no user is logged in.

### C. Option 2: Setup via Windows Task Scheduler GUI
1. Press `Win + R`, type `taskschd.msc`, and press **Enter**.
2. In the right-hand panel, click **Create Task...** (not *Create Basic Task*).
3. **General** tab:
   - Name: `CarWashCloudSync`
   - Description: `Periodic background synchronization between Car Wash Mini-PC and Cloud Master`
   - Security options: Select **Run whether user is logged on or not** and check **Run with highest privileges**.
4. **Triggers** tab &rarr; click **New...**:
   - Begin the task: **At startup** (or *On a schedule* &rarr; Daily).
   - Advanced settings: Check **Repeat task every: 3 minutes** for a duration of: **Indefinitely**.
   - Check **Enabled** &rarr; click **OK**.
5. **Actions** tab &rarr; click **New...**:
   - Action: **Start a program**
   - Program/script: `C:\xampp\htdocs\Car-WashService\bin\sync_runner.bat`
   - Start in: `C:\xampp\htdocs\Car-WashService`
   - Click **OK**.
6. **Settings** tab:
   - Check **Allow task to be run on demand**.
   - If the running task does not end when requested: select **Stop the existing instance**.
   - Click **OK** to save the task.

### D. Manual Verification & Test
To verify the sync runner is functioning correctly from the command line:
```cmd
cd C:\xampp\htdocs\Car-WashService
php scripts\sync_runner.php
```
Expected output:
```
[2026-11-02 14:00:00] Push: 0 sent (ok), Pull: 0 received (ok, conflicts: 0), Duration: 45 ms
```
If another sync is currently running, the script will output:
```
[2026-11-02 14:00:00] Sync runner already active or lock held. Execution skipped.
```

---
*End of Mini-PC Installation & Hardening Guide. Keep this document printed in the shop office.*
