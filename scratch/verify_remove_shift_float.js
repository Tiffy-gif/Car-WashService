const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  
  const consoleErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', err => {
    consoleErrors.push(err.message);
  });

  // Login via API
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
  await page.waitForTimeout(1000);

  // Check if posShiftStatusBadge exists
  const badgeExists = await page.$('#posShiftStatusBadge');
  console.log('posShiftStatusBadge exists in DOM:', badgeExists !== null);

  // Take screenshot of header
  const header = await page.$('.header');
  if (header) {
    await header.screenshot({ path: 'scratch/header_no_shift_float.png' });
    console.log('Saved header_no_shift_float.png');
  }

  // Full page screenshot
  await page.screenshot({ path: 'scratch/pos_screen_no_shift_float.png' });
  console.log('Saved pos_screen_no_shift_float.png');

  console.log('Console errors:', consoleErrors);
  await browser.close();
  console.log('Done!');
})();
