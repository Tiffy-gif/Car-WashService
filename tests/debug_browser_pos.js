const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    page.on('console', msg => console.log('BROWSER CONSOLE:', msg.type(), msg.text()));

    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2500);

    const storage = await page.evaluate(() => {
        return {
            currentUser: localStorage.getItem('carwash_pos_user'),
            sessionToken: localStorage.getItem('carwash_pos_session_token'),
            config: localStorage.getItem('carwash_pos_config'),
            hasConnected: localStorage.getItem('carwash_pos_has_connected'),
            catalogUpdatedAt: localStorage.getItem('carwash_pos_catalog_updated_at')
        };
    });
    console.log('STORAGE:', storage);

    const isCatalogVisible = await page.evaluate(() => {
        const el = document.getElementById('catalogProducts');
        return el ? { childCount: el.children.length, visible: el.offsetParent !== null } : null;
    });
    console.log('CATALOG ELEMENT:', isCatalogVisible);

    const viewActive = await page.evaluate(() => {
        const active = document.querySelector('.pos-view.active');
        return active ? active.id : 'none';
    });
    console.log('ACTIVE VIEW:', viewActive);

    await browser.close();
})();
