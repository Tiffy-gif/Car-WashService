const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
    });
    const page = await browser.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos', { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
        localStorage.setItem('carwash_pos_has_connected', 'true');
        const cfg = {
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceKey: 'my_test_device_key_unique9',
            printerDirectEnabled: true
        };
        localStorage.setItem('carwash_pos_config', JSON.stringify(cfg));
    });
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    const openModals = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('.modal-backdrop.open')).map(el => el.id);
    });
    console.log('Open modals on page load:', openModals);

    await browser.close();
})();
