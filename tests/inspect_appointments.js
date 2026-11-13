const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    console.log('Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    console.log('Navigating to #appointments in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(2000);

    const apptInfo = await page.evaluate(() => {
        const view = document.getElementById('viewAppointments');
        const slotsGrid = document.getElementById('apptTimeSlotsGrid');
        const list = document.getElementById('appointmentsList');
        return {
            isVisible: view ? window.getComputedStyle(view).display !== 'none' : false,
            slotButtonsCount: slotsGrid?.querySelectorAll('.slot-btn')?.length || 0,
            listItemsCount: list?.children?.length || 0,
            listHtml: list?.innerHTML
        };
    });
    console.log('APPOINTMENTS INFO:', JSON.stringify(apptInfo, null, 2));

    const screenshotPath = path.join(brainDir, 'current_appointments_page.png');
    await page.screenshot({ path: screenshotPath });
    console.log('Saved screenshot:', screenshotPath);

    await browser.close();
})();

