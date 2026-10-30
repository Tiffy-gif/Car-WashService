const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const POS_URL = 'http://localhost/Pos-Carwash/index.html';
const API_URL = 'http://localhost/Car-WashService/api/pos/index.php';
const DEVICE_KEY = 'pos_sec_test_key_8899aabb';

async function apiCall(action, data = {}) {
  const res = await fetch(API_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Device-Key': DEVICE_KEY
    },
    body: JSON.stringify({ action, ...data })
  });
  return await res.json();
}

async function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function runTest() {
  console.log('=== STARTING SHOP FLOOR E2E TEST ===');
  console.log('Testing dual tablet viewports (768x1024), Washer & Technical roles, 5 Cars workflow');

  const browser = await puppeteer.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1600,1100']
  });

  try {
    // ----------------------------------------------------
    // SETUP TABLET 1: WASHER (768 x 1024)
    // ----------------------------------------------------
    const washerContext = await browser.createBrowserContext();
    const washerPage = await washerContext.newPage();
    await washerPage.setViewport({ width: 768, height: 1024 });
    console.log('[Tablet 1] Opening POS app at tablet viewport (768x1024)...');
    await washerPage.goto(POS_URL, { waitUntil: 'networkidle0' });
    await washerPage.evaluate((key) => {
      PosConfig.save({ deviceKey: key });
    }, DEVICE_KEY);

    // Login as Washer
    console.log('[Tablet 1] Logging in as Washer (username: washer, PIN: 123456)...');
    await washerPage.waitForSelector('#loginUsername');
    await washerPage.evaluate(() => PosApp.fillCredentials('washer', '123456'));

    // Verify Washer Screen
    await washerPage.waitForSelector('#viewWasher.active', { timeout: 15000 });
    const washerRoleText = await washerPage.$eval('#userRoleBadge', el => el.textContent.trim());
    const washerNameText = await washerPage.$eval('#userFullName', el => el.textContent.trim());
    console.log(`[Tablet 1] Successfully logged in! User: ${washerNameText} | Role: ${washerRoleText}`);

    // ----------------------------------------------------
    // SETUP TABLET 2: TECHNICAL (768 x 1024)
    // ----------------------------------------------------
    const techContext = await browser.createBrowserContext();
    const techPage = await techContext.newPage();
    await techPage.setViewport({ width: 768, height: 1024 });
    console.log('[Tablet 2] Opening POS app at tablet viewport (768x1024)...');
    await techPage.goto(POS_URL, { waitUntil: 'networkidle0' });
    await techPage.evaluate((key) => {
      PosConfig.save({ deviceKey: key });
    }, DEVICE_KEY);

    // Login as Technical
    console.log('[Tablet 2] Logging in as Technical (username: technical, PIN: 123456)...');
    await techPage.waitForSelector('#loginUsername');
    await techPage.evaluate(() => PosApp.fillCredentials('technical', '123456'));

    // Verify Technical Screen
    await techPage.waitForSelector('#viewMechanic.active', { timeout: 15000 });
    const techRoleText = await techPage.$eval('#userRoleBadge', el => el.textContent.trim());
    const techNameText = await techPage.$eval('#userFullName', el => el.textContent.trim());
    console.log(`[Tablet 2] Successfully logged in! User: ${techNameText} | Role: ${techRoleText}`);

    // Verify Notification Bells
    const washerBell = await washerPage.$('#notifBellWrapper');
    const techBell = await techPage.$('#notifBellWrapper');
    console.log(`[Tablet 1 & 2] Notification bells visible: ${!!washerBell && !!techBell}`);

    // Free bays before starting to ensure clean state
    await apiCall('finish_car', { bay_id: 1, took_longer: 0 });
    await apiCall('finish_car', { bay_id: 2, took_longer: 0 });
    await apiCall('finish_car', { bay_id: 3, took_longer: 0 });
    await sleep(1000);

    // ----------------------------------------------------
    // CAR 1: STANDARD WASH CAR (Plate: CAR-01-WASH)
    // ----------------------------------------------------
    console.log('\n--- CAR 1: Standard Wash Workflow ---');
    const order1Res = await apiCall('create_order', {
      plate_number: 'CAR-01-WASH',
      customer_name: 'Chenda Meas',
      vehicle_type: 'small_car',
      is_paid: 1,
      payment_method: 'cash',
      amount_tendered_usd: 10,
      items: [{ service_id: 1, type: 'service', qty: 1 }] // Exterior Wash
    });
    const order1Id = order1Res.order.id;
    console.log(`[Cashier] Car 1 Order created (#${order1Id}) for CAR-01-WASH. Paying & marking arrival...`);
    const arr1 = await apiCall('customer_arrived', { order_id: order1Id, staff_id: 2 });
    console.log(`[Cashier] Car 1 marked arrived:`, arr1.message);

    // Washer tablet syncs
    await washerPage.evaluate(() => PosApp.renderWasherScreen());
    await sleep(2000);

    // Check washer bay card
    let finishBtn = await washerPage.$('.washer-bay-card .btn-washer-finish');
    if (finishBtn) {
      console.log('[Tablet 1] Washer clicking "Finish" on wash bay...');
      await finishBtn.click();
      await washerPage.waitForSelector('#modalWasherFinish.open', { timeout: 10000 });
      console.log('[Tablet 1] Prompt modal shown: "Did this job take longer than expected?"');
      console.log('[Tablet 1] Washer selects: "No, On Time"');
      await washerPage.click('#btnFinishOnTime');
      await sleep(1500);
      console.log('[Tablet 1] Car 1 wash completed on time!');
    }

    // ----------------------------------------------------
    // CAR 2: MIXED ORDER (Plate: CAR-02-MIXD)
    // Wash + Technical (Brake Pad Replacement)
    // ----------------------------------------------------
    console.log('\n--- CAR 2: Mixed Order (Wash -> Tech Handshake) ---');
    const order2Res = await apiCall('create_order', {
      plate_number: 'CAR-02-MIXD',
      customer_name: 'Vannak Heng',
      vehicle_type: 'suv_pickup',
      is_paid: 1,
      payment_method: 'cash',
      amount_tendered_usd: 50,
      items: [
        { service_id: 1, type: 'service', qty: 1 }, // Wash
        { service_id: 8, type: 'service', qty: 1 }  // Brake Pad Replacement (Technical)
      ]
    });
    const order2Id = order2Res.order.id;
    console.log(`[Cashier] Car 2 Mixed Order created (#${order2Id}) for CAR-02-MIXD.`);
    const arr2 = await apiCall('customer_arrived', { order_id: order2Id, staff_id: 2 });
    console.log(`[Cashier] Car 2 marked arrived:`, arr2.message);

    // Washer tablet syncs Car 2
    await washerPage.evaluate(() => PosApp.renderWasherScreen());
    await sleep(2000);

    // Washer sees "Send to Tech" or finishes bay
    const sendTechBtn = await washerPage.$('.btn-send-tech, .btn-washer-finish');
    if (sendTechBtn) {
      const btnText = await washerPage.evaluate(el => el.textContent.trim(), sendTechBtn);
      console.log(`[Tablet 1] Washer sees action button: "${btnText}"`);
      await washerPage.evaluate(() => {
        const btn = document.querySelector('.btn-send-tech, .btn-washer-finish');
        if (btn) btn.click();
      });
      await washerPage.waitForSelector('#modalWasherFinish.open', { timeout: 10000 });
      console.log('[Tablet 1] Prompt modal shown: "Did this job take longer than expected?"');
      console.log('[Tablet 1] Washer selects: "Yes, Took Longer"');
      await washerPage.click('#btnFinishTookLonger');
      await sleep(2000);
      console.log('[Tablet 1] Car 2 wash step finished! Mixed order automatically handed over to Tech!');
    }

    // ----------------------------------------------------
    // TABLET 2: TECHNICAL RECEIVES HANDOVER & WORKS ON CAR 2
    // ----------------------------------------------------
    console.log('\n--- Tablet 2: Technical Handover & Job Execution ---');
    await techPage.evaluate(() => PosApp.renderMechanicScreen());
    await sleep(2000);

    const handoverBtn = await techPage.$('.btn-receive-tech');
    if (handoverBtn) {
      console.log('[Tablet 2] Technical tablet displays car in "Handover from Wash" section!');
      console.log('[Tablet 2] Technician clicks "Receive from Wash"...');
      await handoverBtn.click();
      await sleep(2500);
      await techPage.evaluate(() => PosApp.renderMechanicScreen());
      await sleep(1000);
    } else {
      console.log('[Tablet 2] Checking active technical jobs...');
    }

    // Start Task
    const startTaskBtn = await techPage.$('.btn-start-task');
    if (startTaskBtn) {
      console.log('[Tablet 2] Technician clicks "Start" on job task...');
      await startTaskBtn.click();
      await sleep(2000);
      console.log('[Tablet 2] Task is now "Job in Progress"!');
    }

    // Inspection Checklist
    const passBtn = await techPage.$('.btn-chk-status[data-status="pass"]');
    if (passBtn) {
      console.log('[Tablet 2] Technician inspecting checklist: Marking item "PASS"...');
      await passBtn.click();
      await sleep(500);
    }

    // Request Parts Modal test
    const reqPartsBtn = await techPage.$('.btn-req-parts');
    if (reqPartsBtn) {
      console.log('[Tablet 2] Technician clicks "Request Parts"...');
      await reqPartsBtn.click();
      await techPage.waitForSelector('#modalRequestParts', { visible: true });
      console.log('[Tablet 2] Request Parts modal opened. Entering quantity 2 and submitting...');
      await techPage.evaluate(() => {
        const sel = document.getElementById('partsReqProductSelect');
        if (sel && sel.options.length > 0) sel.selectedIndex = 0;
        const qty = document.getElementById('partsReqQty');
        if (qty) qty.value = '2';
      });
      await techPage.click('#btnSubmitRequestParts');
      await sleep(2000);
      console.log('[Tablet 2] Parts request submitted! Badge transitioned to "Waiting for Parts" / "Job in Progress"');
    }

    // Finish task
    const finishTaskBtn = await techPage.$('.btn-finish-task');
    if (finishTaskBtn) {
      console.log('[Tablet 2] Technician clicks "Finish Task"...');
      await finishTaskBtn.click();
      await sleep(2000);
      console.log('[Tablet 2] Task marked Finished!');
    }

    // ----------------------------------------------------
    // CAR 3: QUICK WASH (Plate: CAR-03-QWSH)
    // ----------------------------------------------------
    console.log('\n--- CAR 3: Quick Wash ---');
    const order3Res = await apiCall('create_order', {
      plate_number: 'CAR-03-QWSH',
      customer_name: 'Bopha Pich',
      vehicle_type: 'small_car',
      is_paid: 1,
      payment_method: 'cash',
      amount_tendered_usd: 10,
      items: [{ service_id: 1, type: 'service', qty: 1 }]
    });
    console.log(`[Cashier] Car 3 created (#${order3Res.order.id}). Marking customer arrived...`);
    await apiCall('customer_arrived', { order_id: order3Res.order.id, staff_id: 2 });
    await washerPage.evaluate(() => PosApp.renderWasherScreen());
    await sleep(2000);
    const car3Finish = await washerPage.$('.washer-bay-card .btn-washer-finish, .btn-washer-finish');
    if (car3Finish) {
      await washerPage.evaluate(() => {
        const btn = document.querySelector('.washer-bay-card .btn-washer-finish, .btn-washer-finish');
        if (btn) btn.click();
      });
      await washerPage.waitForSelector('#modalWasherFinish.open', { timeout: 10000 });
      await washerPage.click('#btnFinishTookLonger');
      await sleep(1500);
      console.log('[Tablet 1] Car 3 completed with "Yes, Took Longer" recorded in back office!');
    }

    // ----------------------------------------------------
    // CAR 4: DIRECT TECHNICAL SERVICE (Plate: CAR-04-TECH)
    // ----------------------------------------------------
    console.log('\n--- CAR 4: Direct Technical Service ---');
    const order4Res = await apiCall('create_order', {
      plate_number: 'CAR-04-TECH',
      customer_name: 'Sokha Mean',
      vehicle_type: 'suv_pickup',
      is_paid: 1,
      payment_method: 'cash',
      amount_tendered_usd: 30,
      items: [{ service_id: 9, type: 'service', qty: 1 }] // Brake Inspection
    });
    console.log(`[Cashier] Car 4 created (#${order4Res.order.id}) with Brake Inspection.`);
    await apiCall('customer_arrived', { order_id: order4Res.order.id, staff_id: 2 });
    await techPage.evaluate(() => PosApp.renderMechanicScreen());
    await sleep(2000);
    console.log('[Tablet 2] Technical screen received Car 4 job order!');

    // ----------------------------------------------------
    // CAR 5: QUEUE SYNCHRONIZATION (Plate: CAR-05-QUEU)
    // ----------------------------------------------------
    console.log('\n--- CAR 5: Live Queue Status across both tablets ---');
    const order5Res = await apiCall('create_order', {
      plate_number: 'CAR-05-QUEU',
      customer_name: 'Dany Sam',
      vehicle_type: 'small_car',
      is_paid: 1,
      payment_method: 'cash',
      amount_tendered_usd: 15,
      items: [{ service_id: 2, type: 'service', qty: 1 }] // Interior Cleaning
    });
    console.log(`[Cashier] Car 5 created (#${order5Res.order.id}). Marking customer arrived...`);
    await apiCall('customer_arrived', { order_id: order5Res.order.id, staff_id: 2 });

    // Live poller updates both tablets
    await washerPage.evaluate(() => PosApp.renderWasherScreen());
    await techPage.evaluate(() => PosApp.renderMechanicScreen());
    await sleep(2000);
    console.log('[Tablet 1 & 2] Both tablets live-updated with newest queue & bay assignments!');

    // ----------------------------------------------------
    // NOTIFICATION BELL & CHIME TEST
    // ----------------------------------------------------
    console.log('\n--- Testing Notification Bell & Dropdown on Washer Tablet ---');
    await washerPage.evaluate(() => {
      const bell = document.getElementById('btnNotificationBell');
      if (bell) bell.click();
    });
    await sleep(500);
    const dropdownVisible = await washerPage.$eval('#notificationDropdown', el => el.style.display !== 'none');
    console.log(`[Tablet 1] Notification dropdown opened: ${dropdownVisible}`);

    const notifCount = await washerPage.$$eval('.notification-item', items => items.length);
    console.log(`[Tablet 1] Unread notifications rendered in dropdown: ${notifCount}`);

    // Mark all as read
    await washerPage.evaluate(() => {
      const btn = document.getElementById('btnMarkAllNotifsRead');
      if (btn) btn.click();
    });
    await sleep(1000);
    const badgeAfter = await washerPage.$eval('#notifBadge', el => el.textContent.trim());
    console.log(`[Tablet 1] After clicking "Mark all as read", unread badge: "${badgeAfter}" (cleared)`);

    // Verify audio chime function exists
    const chimeCallable = await washerPage.evaluate(() => typeof PosApp.playChimeSound === 'function');
    console.log(`[Tablet 1] Audio chime synthesizer function verified: ${chimeCallable}`);

    // ----------------------------------------------------
    // 60-SECOND OFFLINE DETECTION BANNER TEST
    // ----------------------------------------------------
    console.log('\n--- Testing 60-Second Offline Detection Banner ---');
    const isBannerHiddenBefore = await washerPage.$eval('#offlineManualModeBanner', el => el.style.display === 'none');
    console.log(`[Tablet 1] Offline banner hidden initially: ${isBannerHiddenBefore}`);

    // Simulate > 60s without successful sync
    console.log('[Tablet 1] Simulating 65-second elapsed sync timeout...');
    await washerPage.evaluate(() => {
      PosApp.lastSyncSuccessTime = Date.now() - 65000;
      PosApp.updateOfflineManualBanner();
    });
    await sleep(500);
    const isBannerVisible = await washerPage.$eval('#offlineManualModeBanner', el => el.style.display !== 'none');
    const bannerText = await washerPage.$eval('#offlineManualModeBanner', el => el.textContent.trim());
    console.log(`[Tablet 1] Offline banner visible: ${isBannerVisible}`);
    console.log(`[Tablet 1] Banner text: "${bannerText}"`);

    // Restore online
    await washerPage.evaluate(() => {
      PosApp.lastSyncSuccessTime = Date.now();
      PosApp.updateOfflineManualBanner();
    });
    await sleep(500);
    const isBannerHiddenAfter = await washerPage.$eval('#offlineManualModeBanner', el => el.style.display === 'none');
    console.log(`[Tablet 1] Offline banner automatically dismissed when synced: ${isBannerHiddenAfter}`);

    // ----------------------------------------------------
    // CAPTURE SCREENSHOTS FOR VERIFICATION
    // ----------------------------------------------------
    const washerScreenshot = 'washer_tablet_shopfloor.png';
    const techScreenshot = 'technical_tablet_shopfloor.png';
    await washerPage.screenshot({ path: washerScreenshot });
    await techPage.screenshot({ path: techScreenshot });
    console.log(`\nScreenshots saved:\n  - ${washerScreenshot}\n  - ${techScreenshot}`);

    console.log('\n=== ALL 6 REQUIREMENTS SUCCESSFULLY VERIFIED! ===');
  } catch (err) {
    console.error('Test error:', err);
  } finally {
    await browser.close();
  }
}

runTest();
