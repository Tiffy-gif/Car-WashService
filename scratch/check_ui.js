const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  
  // Login via API first
  const loginRes = await page.request.post('http://localhost/Car-WashService/api/pos/index.php?action=staff_login', {
    headers: { 'Content-Type': 'application/json', 'X-Device-Key': 'my_test_device_key_unique9' },
    data: { username: 'visal', pin: '123456' }
  });
  const loginData = await loginRes.json();

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
  await page.waitForTimeout(600);

  // Dismiss start shift modal if open
  await page.evaluate(() => {
    document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    PosApp.shiftBadgeState = 'active';
    PosApp.activeShift = { id: 14, shift_uid: 'SFT-20261119-5CAD6985', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 11:32:32', status: 'open' };
    PosApp.updateShiftStatusBadge('active', PosApp.activeShift);
  });

  // 1. Open User Dropdown
  await page.locator('#userPill').click();
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'scratch/actual_user_dropdown.png' });
  console.log('Saved actual_user_dropdown.png');
  
  await page.locator('#userPill').click(); // close dropdown
  await page.waitForTimeout(200);

  // 2. Open Cashier Actions -> End Shift
  await page.locator('#btnOpenCashierActions').click();
  await page.waitForTimeout(300);
  await page.locator('#btnActionEndShift').click();
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'scratch/actual_end_shift_modal.png' });
  console.log('Saved actual_end_shift_modal.png');
  
  await page.locator('#btnCloseEndShiftModal').click();
  await page.waitForTimeout(300);

  // 3. Open Cashier Actions -> Switch User
  await page.locator('#btnOpenCashierActions').click();
  await page.waitForTimeout(300);
  await page.locator('#btnActionSwitchUser').click();
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'scratch/actual_switch_user_modal.png' });
  console.log('Saved actual_switch_user_modal.png');

  await browser.close();
  console.log('Completed all checks successfully!');
})();
