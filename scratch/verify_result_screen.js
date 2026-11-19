const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
  });

  const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

  const consoleErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', err => {
    consoleErrors.push(err.message);
  });

  // 1. Login via API
  const loginRes = await page.request.post('http://localhost/Car-WashService/api/pos/index.php?action=staff_login', {
    headers: { 'Content-Type': 'application/json', 'X-Device-Key': 'my_test_device_key_unique9' },
    data: { username: 'visal', pin: '123456' }
  });
  const loginData = await loginRes.json();
  console.log('Login success:', loginData.success);

  await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
  await page.evaluate((data) => {
    localStorage.setItem('carwash_pos_has_connected', 'true');
    const cfg = { backofficeUrl: 'http://localhost/Car-WashService', deviceKey: 'my_test_device_key_unique9', printerDirectEnabled: true };
    localStorage.setItem('carwash_pos_config', JSON.stringify(cfg));
    localStorage.setItem('pos_config', JSON.stringify(cfg));
    localStorage.setItem('cw_pos_staff_user', JSON.stringify(data.user));
    sessionStorage.setItem('cw_pos_staff_user', JSON.stringify(data.user));
    localStorage.setItem('cw_pos_session_token', data.session_token);
    sessionStorage.setItem('cw_pos_session_token', data.session_token);
    const shift = { id: 14, shift_uid: 'SFT-20261119-5CAD6985', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 11:32:32', status: 'open' };
    localStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
    sessionStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
  }, loginData);

  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForTimeout(800);

  // Set active shift in app state
  await page.evaluate(() => {
    document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    PosApp.activeShift = { id: 14, shift_uid: 'SFT-20261119-5CAD6985', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 11:32:32', status: 'open' };
    PosApp.shiftBadgeState = 'active';
  });

  // Test 1: Open Cashier Actions -> Click "មើលលទ្ធផលបិទវេន និងបិទបញ្ជី (Summaries)"
  console.log('Testing Cashier Actions -> Summaries lookup button...');
  await page.locator('#btnOpenCashierActions').click();
  await page.waitForTimeout(400);

  const btnSummaries = page.locator('#btnOpenShiftSummaryModal');
  const isSummariesBtnVisible = await btnSummaries.isVisible();
  console.log('Summaries lookup button visible in Cashier Actions:', isSummariesBtnVisible);
  await btnSummaries.click();
  await page.waitForTimeout(600);

  // Verify #modalShiftResult is open
  const isModalResultOpen = await page.evaluate(() => {
    const m = document.getElementById('modalShiftResult');
    return m ? m.classList.contains('open') : false;
  });
  console.log('modalShiftResult opened:', isModalResultOpen);

  // Test 2: Trigger historical filter
  console.log('Testing Date Filter in modalShiftResult...');
  await page.locator('#btnApplyResultFilter').click();
  await page.waitForTimeout(1000);

  const optionsCount = await page.locator('#selectHistoricalResult option').count();
  console.log('Historical options count in dropdown:', optionsCount);

  // Screenshot of the Result Screen modal with historical filter
  await page.screenshot({ path: 'scratch/result_screen_lookup.png' });
  console.log('Saved scratch/result_screen_lookup.png');

  // Test 3: Test direct call to showShiftResultScreen for End Shift
  console.log('Simulating successful End Shift result screen...');
  await page.evaluate(() => {
    const mockShiftRes = {
      success: true,
      is_solo: true,
      shift_id: 14,
      shift_uid: 'SFT-20261119-5CAD6985',
      cashier_name: 'visal',
      started_at: '2026-11-19 11:32:32',
      ended_at: '2026-11-19 14:20:00',
      opening_cash_usd: 70.00,
      opening_cash_khr: 0,
      exchange_rate: 4100,
      cash_received_usd: 120.00,
      cash_received_khr: 0,
      change_given_usd: 15.00,
      change_given_khr: 0,
      cash_in_usd: 10.00,
      cash_in_khr: 0,
      cash_out_usd: 5.00,
      cash_out_khr: 0,
      cash_sales_usd: 105.00,
      cash_sales_count: 8,
      bank_sales_usd: 45.00,
      bank_sales_count: 3,
      total_sales_usd: 150.00,
      total_orders_count: 11,
      expected_cash_usd: 180.00,
      expected_cash_khr: 0,
      counted_cash_usd: 180.00,
      counted_cash_khr: 0,
      total_difference_usd: 0.00,
      notes: 'End of afternoon shift. All cash balanced perfectly.'
    };
    PosApp.showShiftResultScreen('shift', mockShiftRes);
  });
  await page.waitForTimeout(500);

  // Take screenshot of End Shift Result Screen
  await page.screenshot({ path: 'scratch/result_screen_end_shift.png' });
  console.log('Saved scratch/result_screen_end_shift.png');

  // Verify texts on End Shift screen
  const shiftTitle = await page.locator('#shiftResultHeaderTitle').innerText();
  const shiftSales = await page.locator('#shiftResultKpiSales').innerText();
  const shiftCounted = await page.locator('#shiftResultKpiCounted').innerText();
  const shiftDiff = await page.locator('#shiftResultKpiDiff').innerText();
  console.log('Shift Header Title:', shiftTitle);
  console.log('Shift Net Sales:', shiftSales);
  console.log('Shift Counted:', shiftCounted);
  console.log('Shift Difference:', shiftDiff);

  // Test 4: Simulate successful Daily Close (Z-Report) result screen
  console.log('Simulating successful Daily Close (Z-Report) result screen...');
  await page.evaluate(() => {
    const mockDayRes = {
      success: true,
      close_date: '2026-11-19',
      z_report_number: 'ZREP-20261119-001',
      closed_by_name: 'visal',
      manager_name: 'visal',
      closed_at: '2026-11-19 14:30:00',
      total_sales_usd: 485.50,
      total_sales_khr: 25000,
      paid_orders_count: 34,
      cash_sales_usd: 280.00,
      cash_sales_count: 22,
      bank_sales_usd: 205.50,
      bank_sales_count: 12,
      aba_sales_usd: 150.00,
      khqr_sales_usd: 55.50,
      opening_cash_usd: 100.00,
      opening_cash_khr: 400000,
      cash_received_usd: 350.00,
      cash_received_khr: 0,
      change_given_usd: 70.00,
      change_given_khr: 0,
      cash_in_usd: 20.00,
      cash_in_khr: 0,
      cash_out_usd: 15.00,
      cash_out_khr: 0,
      discount_total_usd: 12.00,
      voids_count: 1,
      voids_total_usd: 8.50,
      expected_cash_usd: 385.00,
      expected_cash_khr: 400000,
      counted_cash_usd: 385.00,
      counted_cash_khr: 400000,
      total_difference_usd: 0.00,
      notes: 'Final day close. Cash matched POS record. Registered locked.'
    };
    PosApp.showShiftResultScreen('day', mockDayRes);
  });
  await page.waitForTimeout(500);

  // Take screenshot of Day Close Result Screen
  await page.screenshot({ path: 'scratch/result_screen_day_close.png' });
  console.log('Saved scratch/result_screen_day_close.png');

  const dayTitle = await page.locator('#shiftResultHeaderTitle').innerText();
  const dayStatus = await page.locator('#shiftResultStatusPillText').innerText();
  const daySales = await page.locator('#shiftResultKpiSales').innerText();
  console.log('Day Header Title:', dayTitle);
  console.log('Day Status:', dayStatus);
  console.log('Day Sales:', daySales);

  // Test 5: Tablet responsiveness (1024x768)
  console.log('Testing tablet viewport responsiveness (1024x768)...');
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'scratch/result_screen_tablet.png' });
  console.log('Saved scratch/result_screen_tablet.png');

  // Check console errors
  console.log('Console Errors:', consoleErrors.filter(e => !e.includes('404')));
  await browser.close();
  console.log('Verification finished successfully!');
})();
