const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    // Listen to network responses
    page.on('response', async res => {
        if (res.url().includes('api/pos')) {
            const status = res.status();
            let text = '';
            try { text = await res.text(); } catch (e) {}
            console.log(`[NETWORK] ${res.request().method()} ${res.url()} => ${status}: ${text.substring(0, 150)}`);
        }
    });

    console.log('Navigating to pos/index.html#pos...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(3000);

    const state = await page.evaluate(() => {
        return {
            isLoggedIn: !!PosApp.currentUser,
            user: PosApp.currentUser?.username,
            role: PosApp.currentUser?.role,
            isOnline: PosApp.isOnline,
            hasDeviceKey: !!PosConfig.get().deviceKey,
            deviceUid: PosConfig.get().deviceUid,
            catalogServicesCount: PosStorage.getCatalog()?.services?.length
        };
    });

    console.log('POS State in Browser:', JSON.stringify(state, null, 2));

    await browser.close();
})();
