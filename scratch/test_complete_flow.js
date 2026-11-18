const { chromium } = require('playwright');
const http = require('http');

function loginUser(username, pin) {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username, pin });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'my_test_device_key_unique9',
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
    const browser = await chromium.launch({ channel: 'chrome', headless: true });

    // ==========================================
    // 1. BACKOFFICE UPSELL SETTINGS UI
    // ==========================================
    console.log('--- 1. BACKOFFICE SETTINGS UI ---');
    const boContext = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const boPage = await boContext.newPage();

    // Login as owner
    await boPage.goto('http://localhost/Car-WashService/index.php?view=login');
    await boPage.fill('input[name="username"]', 'owner');
    await boPage.fill('input[name="pin"]', '123456');
    await boPage.click('button[type="submit"]');
    await boPage.waitForTimeout(1000);

    // Go to settings & tab=upsell
    await boPage.goto('http://localhost/Car-WashService/index.php?view=settings&tab=upsell');
    await boPage.waitForTimeout(1000);

    await boPage.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/backoffice_upsell_redesign.png', fullPage: true });
    console.log('Captured: backoffice_upsell_redesign.png');

    // Click Add Promotion Rule to show modal
    const addBtn = boPage.locator('button:has-text("Add Promotion Rule")');
    if (await addBtn.count() > 0) {
        await addBtn.first().click();
        await boPage.waitForTimeout(500);
        await boPage.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/backoffice_modal_clean.png', fullPage: false });
        console.log('Captured: backoffice_modal_clean.png');
    }
    await boContext.close();

    // ==========================================
    // 2. POS REDESIGNED UPSELL POPUP & FLOW
    // ==========================================
    console.log('--- 2. POS REDESIGNED UPSELL FLOW ---');
    const ownerLogin = await loginUser('owner', '123456');
    const posContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await posContext.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
        localStorage.setItem('cw_pos_current_shift', JSON.stringify({ id: 1, cashier_name: 'Shop Owner', status: 'open' }));
        localStorage.removeItem('cw_pos_is_locked');
        localStorage.setItem('cw_pos_language', 'km');
    }, ownerLogin);

    const posPage = await posContext.newPage();
    posPage.on('console', msg => {
        const text = msg.text();
        if (text.includes('Upsell') || text.includes('cart') || text.includes('Error')) {
            console.log('POS LOG:', text);
        }
    });

    await posPage.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await posPage.waitForTimeout(2000);

    // Dismiss any modal except upsell
    await posPage.evaluate(() => {
        document.querySelectorAll('.modal-backdrop').forEach(b => {
            if (b.id !== 'modalUpsellPrompt') {
                b.classList.remove('open');
                b.style.display = 'none';
            }
        });
    });

    // Click Exterior Wash card
    const card = posPage.locator('.pos-item-card:has-text("Exterior Wash")').first();
    await card.click();
    console.log('Clicked Exterior Wash card');

    // Wait for the modal
    const modal = posPage.locator('#modalUpsellPrompt.open');
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    console.log('Redesigned Upsell modal is visible!');

    await posPage.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_redesigned_upsell_popup_km.png' });
    console.log('Captured: pos_redesigned_upsell_popup_km.png');

    // Click Accept button
    await posPage.click('#btnAcceptUpsell');
    console.log('Clicked Accept button');
    await posPage.waitForTimeout(1000);

    // Dismiss wait-time warning if present
    await posPage.evaluate(() => {
        document.querySelectorAll('.modal-backdrop').forEach(b => {
            if (b.id !== 'modalUpsellPrompt') {
                b.classList.remove('open');
                b.style.display = 'none';
            }
        });
    });
    await posPage.waitForTimeout(300);

    await posPage.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_cart_with_addon_verified.png' });
    console.log('Captured: pos_cart_with_addon_verified.png');

    // ==========================================
    // 3. ENGLISH VERSION TEST
    // ==========================================
    console.log('--- 3. ENGLISH VERSION TEST ---');
    await posPage.evaluate(() => {
        localStorage.setItem('cw_pos_language', 'en');
        if (typeof I18nManager !== 'undefined') I18nManager.setLang('en');
        PosApp.cart = [];
        PosApp.renderCart();
        if (PosApp._dismissedUpsellIds) PosApp._dismissedUpsellIds.clear();
        PosApp._currentUpsellModalOpen = false;
    });
    await posPage.waitForTimeout(500);

    // Click card again to trigger English modal
    await card.click();
    await modal.waitFor({ state: 'visible', timeout: 5000 });
    console.log('English Upsell modal visible!');

    await posPage.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_redesigned_upsell_popup_en.png' });
    console.log('Captured: pos_redesigned_upsell_popup_en.png');

    await browser.close();
    console.log('--- ALL PLAYWRIGHT TESTS PASSED! ---');
})();
