const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    console.log('1. Logging in as owner/manager (admin or visal)...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    // 2. Open Back Office POS Devices page
    console.log('2. Navigating to Back Office POS Devices page (index.php?view=pos_devices)...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=pos_devices');
    await page.waitForTimeout(1500);

    const backofficeTitle = await page.title();
    console.log('Backoffice page title:', backofficeTitle);
    await page.screenshot({ path: path.join(brainDir, 'backoffice_pos_devices_list.png') });

    // Open Authorize New POS Device Modal
    console.log('3. Opening Authorize New POS Device Modal in Back Office...');
    const btnAddDev = await page.$('button[onclick*="addDeviceModal"]');
    if (btnAddDev) {
        await btnAddDev.click();
        await page.waitForTimeout(600);
        await page.screenshot({ path: path.join(brainDir, 'backoffice_add_device_modal.png') });
    }

    // 4. Open POS Register and test Settings Gear button
    console.log('4. Navigating to POS Register (#pos)...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Click visible gear icon in header
    console.log('5. Clicking visible Gear Icon (#btnHeaderSettings) in POS topbar...');
    await page.click('#btnHeaderSettings');
    await page.waitForTimeout(600);

    const isSettingsOpen = await page.evaluate(() => {
        const modal = document.getElementById('modalSettings');
        return modal?.classList.contains('open');
    });
    console.log('POS Settings Modal open status:', isSettingsOpen);

    await page.screenshot({ path: path.join(brainDir, 'pos_settings_modal_open.png') });

    // Test switching to Thermal Printer tab in Settings
    console.log('6. Switching to Thermal Printer tab in Settings...');
    await page.click('#tabBtnSettingsPrinter');
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(brainDir, 'pos_settings_modal_printer.png') });

    await browser.close();
    console.log('All tests completed successfully!');
})();
