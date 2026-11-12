const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login', { waitUntil: 'networkidle2' });
    await page.type('#username', 'owner');
    await page.type('#pin', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#washer', { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 1200));

    // Force call switchView('washer') if needed
    await page.evaluate(() => {
        if (typeof PosApp !== 'undefined') {
            PosApp.switchView('washer');
        }
    });
    await new Promise(r => setTimeout(r, 1000));

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/washer_current_view.png' });
    console.log('Saved washer_current_view.png');

    await browser.close();
})();

