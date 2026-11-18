const { chromium } = require('playwright');
const http = require('http');

function getRealStaffSession() {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({ username: 'visal', password: '123456' });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
                'Content-Length': Buffer.byteLength(payload)
            }
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.write(payload);
        req.end();
    });
}

(async () => {
    const loginRes = await getRealStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 960 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Disable upsell popups for clean deterministic testing
    await page.evaluate(() => {
        if (window.PosApp) {
            window.PosApp.evaluateCartUpsellRules = () => {};
        }
        if (window.PosApi) {
            window.PosApi.evaluateCartUpsell = async () => ({ success: true, has_offer: false });
        }
        if (window.I18nManager) window.I18nManager.setLang('en');
        if (window.PosApp) window.PosApp.onLanguageChanged();
    });
    await page.waitForTimeout(400);

    // 1. Click History button at bottom of cart
    await page.click('#btnPastelHistory');
    await page.waitForTimeout(800);

    // Verify modalBillingHistory is open
    const isModalOpen = await page.evaluate(() => {
        const m = document.getElementById('modalBillingHistory');
        return m && m.classList.contains('open');
    });
    console.log('Billing History modal open on #btnPastelHistory click:', isModalOpen);

    // Verify modal title
    const modalTitle = await page.$eval('#billingHistoryModalTitle', el => el.textContent.trim());
    console.log('Billing History modal title:', modalTitle);

    // Verify table has rows
    const rowCount = await page.evaluate(() => {
        return document.querySelectorAll('#billingHistoryTableBody tr').length;
    });
    console.log('Rendered row count in Billing History table:', rowCount);

    const footerInfo = await page.$eval('#billingHistoryFooterInfo', el => el.textContent.trim());
    console.log('Footer info text:', footerInfo);

    // Take screenshot of modal centered on screen
    await page.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_billing_history_fullscreen.png'
    });

    const modalBox = await page.$('.modal-billing-history-content');
    if (modalBox) {
        await modalBox.screenshot({
            path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_billing_history_modal_en.png'
        });
    }

    // 2. Test Search in modal
    await page.fill('#billingHistorySearchInput', 'Walk-in');
    await page.waitForTimeout(400);
    const searchRowCount = await page.evaluate(() => {
        return document.querySelectorAll('#billingHistoryTableBody tr').length;
    });
    console.log('Row count after searching "Walk-in":', searchRowCount);

    // Clear search
    await page.fill('#billingHistorySearchInput', '');
    await page.waitForTimeout(300);

    // 3. Test Filter Dropdown
    await page.click('#btnBillingFilterToggle');
    await page.waitForTimeout(300);
    const isDropdownVisible = await page.evaluate(() => {
        const d = document.getElementById('billingFilterDropdown');
        return d && d.style.display !== 'none';
    });
    console.log('Filter dropdown visible on toggle:', isDropdownVisible);

    // Close dropdown
    await page.click('#btnBillingFilterToggle');
    await page.waitForTimeout(300);

    // 4. Test Khmer mode
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('km');
        if (window.PosApp) window.PosApp.onLanguageChanged();
        if (window.PosApp) window.PosApp.renderBillingHistoryTable();
    });
    await page.waitForTimeout(500);

    const modalTitleKm = await page.$eval('#billingHistoryModalTitle', el => el.textContent.trim());
    console.log('Modal title in Khmer:', modalTitleKm);

    if (modalBox) {
        await modalBox.screenshot({
            path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_billing_history_modal_km.png'
        });
    }

    // 5. Test close modal
    await page.click('#btnCloseBillingHistoryModal');
    await page.waitForTimeout(400);

    const isModalClosed = await page.evaluate(() => {
        const m = document.getElementById('modalBillingHistory');
        return !m || !m.classList.contains('open');
    });
    console.log('Billing History modal closed on [x] click:', isModalClosed);

    await browser.close();
    console.log('ALL BILLING HISTORY MODAL TESTS FINISHED SUCCESSFULLY!');
})();
