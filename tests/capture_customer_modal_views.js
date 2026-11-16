const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    console.log('Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Switch to English for clean reference
    await page.evaluate(() => {
        I18nManager.setLang('en');
        document.querySelectorAll('#modalUpsellPrompt, #modalUpsell, .modal.open, .modal-backdrop.open').forEach(m => {
            m.classList.remove('open');
            m.style.display = 'none';
        });
    });
    await page.waitForTimeout(400);

    // 1. Initial POS with Walk-in Guest
    await page.screenshot({ path: path.join(brainDir, 'pos_gotpos_customer_walkin_en.png') });

    // 2. Click Customer Card to open Customer Directory Modal
    await page.click('#gotposCustomerCard');
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(brainDir, 'pos_gotpos_customer_modal_en.png') });

    // 3. Select customer 'Paid Token Test Guest' via vehicle chip
    const firstVeh = await page.$('.cust-vehicle-chip');
    if (firstVeh) {
        await firstVeh.click();
    } else {
        await page.click('.saved-cust-card');
    }
    await page.waitForTimeout(600);

    // Add an item to cart to show full order with customer
    await page.evaluate(() => {
        const catalog = PosStorage.getCatalog();
        const firstSvc = catalog?.services?.[0];
        if (firstSvc) {
            PosApp.cart = [{
                id: firstSvc.id,
                service_id: firstSvc.id,
                type: 'service',
                name_km: firstSvc.name_km || firstSvc.name_en,
                name_en: firstSvc.name_en,
                price_usd: 8.50,
                price_khr: 34850,
                qty: 1
            }];
            PosApp.renderCart();
        }
        document.querySelectorAll('#modalUpsellPrompt, #modalUpsell, .modal.open, .modal-backdrop.open').forEach(m => {
            m.classList.remove('open');
            m.style.display = 'none';
        });
    });
    await page.waitForTimeout(600);

    // Screenshot POS with customer selected + order item in cart
    await page.screenshot({ path: path.join(brainDir, 'pos_gotpos_customer_selected_with_cart.png') });

    // 4. Dark Mode Screenshot with Customer Modal
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.querySelectorAll('#modalUpsellPrompt, #modalUpsell').forEach(m => {
            m.classList.remove('open');
            m.style.display = 'none';
        });
        PosApp.openCustomerSelectModal();
    });
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(brainDir, 'pos_gotpos_customer_modal_dark.png') });

    // 5. Dark Mode Full Screen with Customer Selected
    await page.evaluate(() => {
        PosApp.closeCustomerSelectModal();
        document.querySelectorAll('#modalUpsellPrompt, #modalUpsell, .modal.open, .modal-backdrop.open').forEach(m => {
            m.classList.remove('open');
            m.style.display = 'none';
        });
    });
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(brainDir, 'pos_gotpos_customer_selected_dark.png') });

    await browser.close();
    console.log('Captured all customer screenshots successfully!');
})();
