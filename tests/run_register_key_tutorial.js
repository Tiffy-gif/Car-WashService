const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    console.log('1. Logging in as owner...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    console.log('2. Navigating to POS Devices page...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=pos_devices');
    await page.waitForTimeout(1500);

    // Step 1 Screenshot: Back office devices table
    await page.screenshot({ path: path.join(brainDir, 'tutorial_step1_devices_page.png') });

    console.log('3. Clicking Authorize New POS Device...');
    await page.click('button[onclick*="addDeviceModal"]');
    await page.waitForTimeout(600);

    // Step 2 Screenshot: Modal with filled form
    const sampleUid = 'POS-FRONT-' + Math.floor(100 + Math.random() * 900);
    await page.fill('input[name="device_name"]', 'Front Counter POS');
    await page.fill('input[name="device_uid"]', sampleUid);
    await page.fill('input[name="location"]', 'Main Entrance Counter');
    await page.waitForTimeout(400);

    await page.screenshot({ path: path.join(brainDir, 'tutorial_step2_modal_form.png') });

    console.log('4. Submitting registration form...');
    await page.click('#addDeviceModal form button[type="submit"]');
    await page.waitForTimeout(1500);

    // Step 3 Screenshot: Yellow card with newly generated API key
    await page.screenshot({ path: path.join(brainDir, 'tutorial_step3_key_generated.png') });

    // Extract the generated key
    const generatedKey = await page.inputValue('#newGeneratedApiKeyInput').catch(() => null);
    console.log('Generated Key:', generatedKey);

    if (generatedKey) {
        console.log('5. Navigating to POS register...');
        await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2000);

        // Click Settings Gear Icon
        console.log('6. Opening POS Settings modal...');
        await page.click('#btnHeaderSettings');
        await page.waitForTimeout(600);

        // Fill in the new UID and Key
        await page.fill('#cfgDeviceUid', sampleUid);
        await page.fill('#cfgDeviceKey', generatedKey);
        await page.waitForTimeout(400);

        await page.screenshot({ path: path.join(brainDir, 'tutorial_step4_paste_key.png') });

        // Click Test Link
        console.log('7. Clicking Test Link...');
        await page.click('#btnTestConnection');
        await page.waitForTimeout(1500);

        await page.screenshot({ path: path.join(brainDir, 'tutorial_step5_test_link_verified.png') });

        // Save Settings
        await page.click('#btnSaveSettings');
        await page.waitForTimeout(800);

        await page.screenshot({ path: path.join(brainDir, 'tutorial_step6_settings_saved.png') });
    }

    await browser.close();
    console.log('Registration walkthrough completed successfully!');
})();
