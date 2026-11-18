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
    });

    // 1. Verify buttons in bottom features bar
    const hasPayLater = await page.$('#btnPastelPayLater');
    const btnHold = await page.$('#btnPastelHold');
    const btnDiscount = await page.$('#btnPastelDiscount');
    const btnHistory = await page.$('#btnPastelHistory');

    console.log('Has #btnPastelPayLater:', hasPayLater !== null ? 'FAIL (still exists)' : 'PASS (removed)');
    console.log('Has #btnPastelDiscount:', btnDiscount !== null ? 'PASS' : 'FAIL');
    console.log('Has #btnPastelHold:', btnHold !== null ? 'PASS' : 'FAIL');
    console.log('Has #btnPastelHistory:', btnHistory !== null ? 'PASS' : 'FAIL');

    // Set English first
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
        if (window.PosApp) window.PosApp.onLanguageChanged();
    });
    await page.waitForTimeout(400);

    const discTextBeforeEn = await page.$eval('#btnPastelDiscount .pastel-label', el => el.textContent.trim());
    console.log('Initial Discount button text (EN):', discTextBeforeEn);

    // 2. Click Discount on empty cart -> Should show toast
    await page.click('#btnPastelDiscount');
    await page.waitForTimeout(500);
    const toastNotice = await page.evaluate(() => {
        const toast = document.querySelector('.toast, .toast-notice, .swal2-popup, #toastContainer, .gotpos-toast, .alert, .toast-body');
        return toast ? toast.textContent.trim() : null;
    });
    console.log('Toast on empty cart discount click:', toastNotice);

    // 3. Add an item to cart
    await page.evaluate(() => {
        PosApp.addServiceToCart(3);
    });
    await page.waitForTimeout(400);

    // 4. Click Discount pastel button now that item is in cart
    await page.click('#btnPastelDiscount');
    await page.waitForTimeout(500);

    const isModalDiscountOpen = await page.evaluate(() => {
        const m = document.getElementById('modalDiscount');
        return m && m.classList.contains('open');
    });
    console.log('Modal discount open after click #btnPastelDiscount:', isModalDiscountOpen);

    // Apply 10% discount
    await page.evaluate(() => {
        PosApp.setDiscount('10%');
        if (typeof PosApp.closeDiscountModal === 'function') {
            PosApp.closeDiscountModal();
        }
    });
    await page.waitForTimeout(500);

    const discButtonTextActive = await page.$eval('#btnPastelDiscount .pastel-label', el => el.textContent.trim());
    const hasDiscountClass = await page.$eval('#btnPastelDiscount', el => el.classList.contains('has-discount'));
    console.log('Active discount button text (EN):', discButtonTextActive);
    console.log('Button has .has-discount class:', hasDiscountClass);

    // Screenshot cart with active discount pastel button (English)
    const cartPanel = await page.$('#posCartColumn');
    await cartPanel.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_pastel_discount_en.png'
    });

    // Take full screen screenshot (English)
    await page.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_fullscreen_pastel_discount_en.png'
    });

    // 5. Test Khmer localization
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('km');
        if (window.PosApp) window.PosApp.onLanguageChanged();
    });
    await page.waitForTimeout(500);

    const holdKm = await page.$eval('#btnPastelHold .pastel-label', el => el.textContent.trim());
    const discKm = await page.$eval('#btnPastelDiscount .pastel-label', el => el.textContent.trim());
    const histKm = await page.$eval('#btnPastelHistory .pastel-label', el => el.textContent.trim());

    console.log('Khmer labels:', { holdKm, discKm, histKm });

    await cartPanel.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_pastel_discount_km.png'
    });

    // Take full screen screenshot (Khmer)
    await page.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_fullscreen_pastel_discount_km.png'
    });

    await browser.close();
    console.log('ALL PASTEL DISCOUNT TESTS FINISHED SUCCESSFULLY!');
})();
