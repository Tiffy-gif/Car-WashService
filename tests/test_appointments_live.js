const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    console.log('2. Navigating to #appointments...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(2000);

    // Scroll viewAppointments to top
    await page.evaluate(() => {
        const v = document.getElementById('viewAppointments');
        if (v) v.scrollTop = 0;
    });
    await page.waitForTimeout(300);

    // Capture Light Mode Full Cockpit
    const shotLight = path.join(brainDir, 'appointments_light_full_cockpit.png');
    await page.screenshot({ path: shotLight });
    console.log('Saved light cockpit screenshot:', shotLight);

    // Test Dark Mode Full Cockpit
    console.log('3. Capturing Dark Mode Cockpit...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        const v = document.getElementById('viewAppointments');
        if (v) v.scrollTop = 0;
    });
    await page.waitForTimeout(400);
    const shotDark = path.join(brainDir, 'appointments_dark_full_cockpit.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved dark cockpit screenshot:', shotDark);

    // Reset back to light
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        document.body.setAttribute('data-theme', 'light');
    });

    // 4. Test Customer Arrived button
    console.log('4. Clicking Customer Arrived button...');
    const arriveBtn = page.locator('.btn-appt-arrived').first();
    if (await arriveBtn.count() > 0) {
        await arriveBtn.click();
        await page.waitForTimeout(2000);

        // Check if toast or view switched
        const currentUrl = page.url();
        console.log('Current URL after arrived click:', currentUrl);

        // If returned or stayed on appointments, take screenshot
        await page.evaluate(() => {
            const v = document.getElementById('viewAppointments');
            if (v) v.scrollTop = 0;
        });
        const shotArrived = path.join(brainDir, 'appointments_after_arrived_action.png');
        await page.screenshot({ path: shotArrived });
        console.log('Saved arrived action screenshot:', shotArrived);
    }

    console.log('TEST COMPLETE.');
    await browser.close();
})();
