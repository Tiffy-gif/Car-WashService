const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });

    await context.addInitScript(() => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100,
            defaultLanguage: 'en'
        }));
        localStorage.setItem('carwash_pos_has_connected', 'true');
    });

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.waitForSelector('#viewPos', { timeout: 10000 });
    await page.waitForTimeout(1000);

    // 1. Test Washer Role: dom
    console.log('--- Testing Washer Role: dom ---');
    await page.evaluate(() => {
        const washerUser = {
            id: 2,
            username: 'dom',
            full_name: 'Dom Washer',
            role: 'washer',
            permissions: ['washer_jobs.view']
        };
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(washerUser));
        window.PosApp.currentUser = washerUser;
        window.PosApp.renderLoggedInState();
        if (window.I18nManager) window.I18nManager.applyToDOM();
    });
    await page.waitForTimeout(1000);

    const washerBtnBackDisplay = await page.evaluate(() => {
        const btn = document.getElementById('btnWasherBackToPos');
        return btn ? window.getComputedStyle(btn).display : 'null';
    });
    const washerTitleText = await page.evaluate(() => {
        return document.querySelector('#viewWasher .washer-section-title-wrap h3')?.textContent;
    });

    console.log('Washer (dom) - Back to Register button display:', washerBtnBackDisplay);
    console.log('Washer (dom) - Section Title text:', washerTitleText);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/role_washer_dom_screen.png' });

    // 2. Test Cashier Role: visal
    console.log('\n--- Testing Cashier Role: visal ---');
    await page.evaluate(() => {
        const cashierUser = {
            id: 1,
            username: 'visal',
            full_name: 'Visal Cashier',
            role: 'cashier',
            permissions: ['pos.access', 'pos.create_order', 'orders.list', 'bays.view']
        };
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(cashierUser));
        window.PosApp.currentUser = cashierUser;
        window.PosApp.renderLoggedInState();
        window.PosApp.switchView('washer');
        if (window.I18nManager) window.I18nManager.applyToDOM();
    });
    await page.waitForTimeout(1000);

    const cashierBtnBackDisplay = await page.evaluate(() => {
        const btn = document.getElementById('btnWasherBackToPos');
        return btn ? window.getComputedStyle(btn).display : 'null';
    });
    console.log('Cashier (visal) - Back to Register button display on #washer:', cashierBtnBackDisplay);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/role_cashier_on_washer_screen.png' });

    await browser.close();
})();
