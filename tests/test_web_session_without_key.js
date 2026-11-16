const { chromium } = require('playwright');
const { execSync } = require('child_process');

(async () => {
    // 1. Create 1 active device in DB
    const newDevOutput = execSync('php tests/create_test_device.php').toString();
    const newDev = JSON.parse(newDevOutput);
    console.log('Active device exists in DB:', newDev.apiKey);

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();

    const page = await context.newPage();

    // Login to backoffice as owner
    console.log('Logging in to Back Office as Owner...');
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    console.log('Logged into Back Office. URL:', page.url());

    // 2. In the SAME browser context (with Back Office cookie), open POS where deviceKey is empty!
    console.log('Navigating to POS in the same browser session without Device Key configured in POS...');
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2500);

    const posState = await page.evaluate(() => {
        const modal = document.getElementById('modalDeviceLocked');
        const code = document.getElementById('deviceLockedCode');
        const banner = document.getElementById('posDeviceLockedBanner');
        return {
            modalIsOpen: modal ? modal.classList.contains('open') : false,
            codeText: code ? code.textContent.trim() : '',
            bannerDisplay: banner ? window.getComputedStyle(banner).display : 'none',
            isAuthorized: typeof PosApp !== 'undefined' ? PosApp.isDeviceAuthorized : null
        };
    });
    console.log('POS STATE WITHOUT KEY (WITH BACK OFFICE COOKIE):', JSON.stringify(posState, null, 2));

    // Clean up
    execSync('php tests/delete_test_device.php');
    await browser.close();
})();
