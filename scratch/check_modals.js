const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.evaluate(() => {
        localStorage.setItem('pos_device_uid', 'POS-CAASIER');
        localStorage.setItem('pos_device_key', 'my_test_device_key_unique9');
        localStorage.setItem('pos_device_status', 'registered');
    });

    await page.reload();
    await page.waitForTimeout(500);

    const openModals = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('*')).filter(el => {
            const style = window.getComputedStyle(el);
            return style.position === 'fixed' && style.display !== 'none' && (style.backgroundColor.includes('0.') || style.zIndex > 100);
        }).map(el => ({ id: el.id, className: el.className, zIndex: window.getComputedStyle(el).zIndex }));
    });
    console.log('Open overlay elements:', openModals);
    await browser.close();
})();
