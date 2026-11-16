const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    console.log('2. Navigating to POS Register (#pos)...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Make sure cart is clean and empty first
    await page.evaluate(() => {
        PosApp.cart = [];
        PosApp.renderCart();
        document.querySelectorAll('#modalUpsell, .modal.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    });
    await page.waitForTimeout(600);

    const shotLightEmpty = path.join(brainDir, 'pos_gotpos_light_empty.png');
    await page.screenshot({ path: shotLightEmpty });
    console.log('Saved pos_gotpos_light_empty.png:', shotLightEmpty);

    console.log('3. Adding service to cart (triggering via addServiceToCart or cart array with service_id)...');
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
        document.querySelectorAll('#modalUpsell, .modal.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    });
    await page.waitForTimeout(800);

    const shotLightWithItem = path.join(brainDir, 'pos_gotpos_light_with_item.png');
    await page.screenshot({ path: shotLightWithItem });
    console.log('Saved pos_gotpos_light_with_item.png:', shotLightWithItem);

    console.log('4. Switching to Dark Mode...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
        document.querySelectorAll('#modalUpsell, .modal.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    });
    await page.waitForTimeout(600);

    const shotDark = path.join(brainDir, 'pos_gotpos_dark.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved pos_gotpos_dark.png:', shotDark);

    console.log('5. Testing English View...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        document.body.setAttribute('data-theme', 'light');
        localStorage.setItem('pos_theme', 'light');
        I18nManager.setLang('en');
        PosApp.onLanguageChanged();
        PosApp.renderCatalog();
        PosApp.renderCart();
        document.querySelectorAll('#modalUpsell, .modal.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    });
    await page.waitForTimeout(600);

    const shotEnglish = path.join(brainDir, 'pos_gotpos_english.png');
    await page.screenshot({ path: shotEnglish });
    console.log('Saved pos_gotpos_english.png:', shotEnglish);

    // Reset language back to Khmer
    await page.evaluate(() => {
        I18nManager.setLang('km');
        PosApp.onLanguageChanged();
        PosApp.renderCatalog();
        PosApp.renderCart();
    });

    console.log('Testing completed successfully!');
    await browser.close();
})();
