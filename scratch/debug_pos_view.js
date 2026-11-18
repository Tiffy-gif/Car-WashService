const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.waitForTimeout(2000);

    const bodyHtml = await page.evaluate(() => {
        return {
            url: window.location.href,
            hash: window.location.hash,
            activeModals: Array.from(document.querySelectorAll('.modal.open, .pos-modal.open')).map(m => m.id),
            views: Array.from(document.querySelectorAll('.pos-view')).map(v => ({ id: v.id, display: window.getComputedStyle(v).display })),
            catalogCount: document.querySelectorAll('.pos-item-card').length,
            session: localStorage.getItem('carwash_pos_session'),
            config: localStorage.getItem('carwash_pos_config')
        };
    });

    console.log('DOM info:', JSON.stringify(bodyHtml, null, 2));
    await browser.close();
})();
