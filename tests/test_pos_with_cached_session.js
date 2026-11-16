const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();

    await context.addInitScript(() => {
        localStorage.setItem('carwash_pos_user', JSON.stringify({
            id: 1,
            username: 'owner',
            full_name: 'Visal (Owner)',
            role: 'owner',
            permissions: ['*']
        }));
        localStorage.setItem('carwash_pos_session_token', 'st_sample_token_12345');
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'CASHIER',
            deviceName: 'POS-Cashier',
            deviceKey: 'pos_sec_deleted_key_test'
        }));
        localStorage.setItem('carwash_pos_has_connected', 'true');
        localStorage.setItem('carwash_pos_catalog', JSON.stringify({
            products: [{ id: 1, name: 'Sample Wash', price: 5, category_id: 1 }],
            categories: [{ id: 1, name: 'Wash' }],
            services: []
        }));
    });

    const page = await context.newPage();
    page.on('console', msg => console.log('BROWSER CONSOLE:', msg.type(), msg.text()));

    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(3000);

    const isCatalogVisible = await page.evaluate(() => {
        const el = document.getElementById('catalogProducts');
        const view = document.getElementById('viewPos');
        return {
            catalogChildren: el ? el.children.length : 0,
            viewPosDisplay: view ? window.getComputedStyle(view).display : 'none',
            viewPosClass: view ? view.className : ''
        };
    });
    console.log('CATALOG VISIBILITY WHEN CACHED:', isCatalogVisible);

    const networkPill = await page.evaluate(() => {
        const pill = document.getElementById('networkPill');
        const text = document.getElementById('networkText');
        return {
            className: pill ? pill.className : '',
            text: text ? text.textContent : ''
        };
    });
    console.log('NETWORK PILL:', networkPill);

    await browser.close();
})();
