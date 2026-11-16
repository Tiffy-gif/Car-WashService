const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    const info = await page.evaluate(() => {
        const modalLocked = document.getElementById('modalDeviceLocked');
        const modalLogin = document.getElementById('modalPinLock') || document.getElementById('modalStaffLogin');
        const viewPos = document.getElementById('viewPos');
        return {
            url: window.location.href,
            lockedIsOpen: modalLocked ? modalLocked.classList.contains('open') : false,
            isDeviceAuthorized: typeof PosApp !== 'undefined' ? PosApp.isDeviceAuthorized : null,
            currentUser: typeof PosApp !== 'undefined' ? PosApp.currentUser : null
        };
    });
    console.log('FRESH CONTEXT INFO:', info);

    await browser.close();
})();
