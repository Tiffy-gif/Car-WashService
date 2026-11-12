const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    const consoleLogs = [];
    page.on('console', msg => consoleLogs.push({ type: msg.type(), text: msg.text() }));
    page.on('pageerror', err => consoleLogs.push({ type: 'pageerror', text: err.toString() }));

    // 1. Login as owner (who has full access to POS and Back Office)
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login', { waitUntil: 'networkidle2' });
    await page.type('#username', 'owner');
    await page.type('#pin', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[type="submit"]')
    ]);

    console.log('Logged into backoffice. URL:', page.url());

    // 2. Open the POS app
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos', { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 2000));

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_loaded_screen.png' });
    console.log('Saved pos_loaded_screen.png. Current URL:', page.url());

    console.log('Console logs:');
    consoleLogs.forEach(l => console.log(`[${l.type}] ${l.text}`));

    await browser.close();
})();
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
    await new Promise(r => setTimeout(r, 1500));

    const activeSection = await page.evaluate(() => {
        const sections = Array.from(document.querySelectorAll('.view-section'));
        const visible = sections.filter(s => s.style.display !== 'none');
        return visible.map(s => s.id);
    });
    console.log('Visible sections:', activeSection);

    await browser.close();
})();
