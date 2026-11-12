const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
    const page = await browser.newPage();
    const failedUrls = [];
    page.on('response', resp => {
        if (resp.status() === 404) failedUrls.push(resp.url());
    });
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login', { waitUntil: 'networkidle2' });
    await page.type('#username', 'owner');
    await page.type('#pin', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[type="submit"]')
    ]);
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos', { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 1000));
    console.log('404 URLs:', Array.from(new Set(failedUrls)));
    await browser.close();
})();
