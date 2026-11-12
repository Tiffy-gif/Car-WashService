const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    // 1. Login as owner
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login', { waitUntil: 'networkidle2' });
    await page.type('#username', 'owner');
    await page.type('#pin', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[type="submit"]')
    ]);

    // 2. Open POS
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos', { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 1500));

    // 3. Switch Language to English to test multilingual toggle
    const langSelector = await page.$('#selectLanguage, #langSelector, select[id*="lang"]');
    if (langSelector) {
        await page.select('#selectLanguage, #langSelector, select[id*="lang"]', 'en');
        await new Promise(r => setTimeout(r, 400));
    }

    // 4. Fill Plate Number
    const plateInput = await page.$('#inputPlateNumber, #txtPlateNumber, input[placeholder*="2BC-1234"]');
    if (plateInput) {
        await plateInput.click({ clickCount: 3 });
        await plateInput.type('2BQ-8899');
    }

    // 5. Select SUV vehicle type
    const suvBtn = await page.$('button[data-vehicle="suv"], .vehicle-btn[data-type="suv"], button[onclick*="suv"]');
    if (suvBtn) {
        await suvBtn.click();
        await new Promise(r => setTimeout(r, 300));
    }

    // 6. Click Add on first service card
    const addBtns = await page.$$('.service-card .btn-add, .service-card button, .card-service button, button[onclick*="addToCart"]');
    if (addBtns.length > 0) {
        await addBtns[0].click();
        await new Promise(r => setTimeout(r, 400));
    }

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_item_added.png' });
    console.log('Saved pos_item_added.png');

    // 7. Click Collect Payment
    const payBtn = await page.$('#btnCheckout, #btnPay, button[onclick*="checkout"], .btn-pay, button[id*="Pay"]');
    if (payBtn) {
        await payBtn.click();
        await new Promise(r => setTimeout(r, 600));
        await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_checkout_modal.png' });
        console.log('Saved pos_checkout_modal.png');
    }

    await browser.close();
})();

