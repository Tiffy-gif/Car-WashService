const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();

    // 1. Create a new active POS device in DB to get a valid pos_sec_ key
    const newDevOutput = execSync('php tests/create_test_device.php').toString();
    const newDev = JSON.parse(newDevOutput);
    console.log('Created new valid device key:', newDev.apiKey);

    // 2. Start browser with an invalid key to see it locked
    await context.addInitScript(() => {
        localStorage.setItem('carwash_pos_user', JSON.stringify({
            id: 1,
            username: 'visal',
            full_name: 'Visal (Shop Owner)',
            role: 'owner',
            permissions: ['*']
        }));
        localStorage.setItem('carwash_pos_session_token', 'st_stale_token_test');
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'CASHIER',
            deviceName: 'POS-Cashier',
            deviceKey: 'pos_sec_old_invalid_key'
        }));
        localStorage.setItem('carwash_pos_has_connected', 'true');
    });

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2500);

    const isLockedInitially = await page.evaluate(() => {
        const modal = document.getElementById('modalDeviceLocked');
        return modal && modal.classList.contains('open');
    });
    console.log('Is locked initially with invalid key:', isLockedInitially);

    // 3. Open settings from the locked screen
    await page.click('#btnDeviceLockedSettings');
    await page.waitForTimeout(500);

    // 4. Input the new valid device key & UID
    await page.fill('#cfgDeviceUid', 'POS-MAIN-01');
    await page.fill('#cfgDeviceKey', newDev.apiKey);

    // Click Save & Apply Settings
    await page.click('#btnSaveSettings');
    await page.waitForTimeout(2500);

    // 5. Verify that modalDeviceLocked is closed and POS is authorized
    const stateAfterSave = await page.evaluate(() => {
        const modal = document.getElementById('modalDeviceLocked');
        const banner = document.getElementById('posDeviceLockedBanner');
        return {
            modalIsOpen: modal ? modal.classList.contains('open') : false,
            bannerDisplay: banner ? window.getComputedStyle(banner).display : 'none',
            isAuthorized: typeof PosApp !== 'undefined' ? PosApp.isDeviceAuthorized : null,
            isOnline: typeof PosApp !== 'undefined' ? PosApp.isOnline : null
        };
    });
    console.log('STATE AFTER SAVING VALID KEY:', JSON.stringify(stateAfterSave, null, 2));

    const screenshotUnlockedPath = path.resolve(__dirname, '../tests/pos_unlocked_success.png');
    await page.screenshot({ path: screenshotUnlockedPath, fullPage: true });
    console.log('Screenshot unlocked saved to:', screenshotUnlockedPath);

    // Clean up test device from DB
    execSync('php tests/delete_test_device.php');
    console.log('Cleaned up test device.');

    await browser.close();
})();
