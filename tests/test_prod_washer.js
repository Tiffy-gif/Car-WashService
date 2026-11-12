const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();

    // Login as owner
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login', { waitUntil: 'networkidle2' });
    await page.type('#username', 'owner');
    await page.type('#pin', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[type="submit"]')
    ]);

    // Go to POS Washer view
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#washer', { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 1200));

    // Force switch to washer view if needed
    await page.evaluate(() => {
        if (typeof PosApp !== 'undefined') {
            PosApp.switchView('washer');
        }
    });
    await new Promise(r => setTimeout(r, 1200));

    const state = await page.evaluate(() => {
        return {
            baysCount: document.querySelectorAll('.bay-article').length,
            queueCount: document.querySelectorAll('.queue-item-card').length,
            baysHtmlSnippet: document.getElementById('washerBaysGrid')?.innerHTML?.slice(0, 150),
            statusCounts: document.getElementById('washerStatusCounts')?.textContent
        };
    });
    console.log('Production POS State:', state);

    await browser.close();
})();
