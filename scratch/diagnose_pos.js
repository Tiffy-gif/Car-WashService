const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

    const errors = [];
    const consoleLogs = [];

    page.on('pageerror', err => errors.push(err.toString()));
    page.on('console', msg => {
        consoleLogs.push(`[${msg.type()}] ${msg.text()}`);
        if (msg.type() === 'error') errors.push(msg.text());
    });

    // Login as Cashier Visal
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

    const screenshotPath = 'scratch/pos_current_state.png';
    await page.screenshot({ path: screenshotPath, fullPage: true });

    const ARTIFACT_DIR = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';
    fs.copyFileSync(screenshotPath, path.join(ARTIFACT_DIR, 'pos_current_state.png'));

    console.log('Errors:', JSON.stringify(errors, null, 2));
    console.log('Recent Console Logs:', JSON.stringify(consoleLogs.slice(-25), null, 2));

    const htmlStatus = await page.evaluate(() => {
        return {
            currentView: PosApp.currentView,
            activeSections: Array.from(document.querySelectorAll('.view-section.active')).map(s => s.id),
            allSections: Array.from(document.querySelectorAll('.view-section')).map(s => ({ id: s.id, display: window.getComputedStyle(s).display, active: s.classList.contains('active') })),
            navTabs: Array.from(document.querySelectorAll('.nav-tab')).map(t => ({ view: t.getAttribute('data-view'), active: t.classList.contains('active'), display: window.getComputedStyle(t).display })),
            catalogItemCount: document.querySelectorAll('#catalogGrid .pos-product-card').length,
            isDayLocked: PosApp.isDayLocked,
            isDayClosed: PosApp.isDayClosed
        };
    });

    console.log('HTML Status:', JSON.stringify(htmlStatus, null, 2));

    await browser.close();
})();
