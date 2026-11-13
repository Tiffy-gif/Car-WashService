const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForNavigation();
    await page.waitForTimeout(1000);

    console.log('2. Creating showcase waiting vehicles...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(1000);

    await page.evaluate(async () => {
        const dateStr = new Date().toISOString().split('T')[0];

        const user = PosStorage.getUser();
        const myStaffId = user?.id || 2;

        // Car 1
        const a1 = await PosApi.createAppointment({
            plate_number: '2E-8899',
            vehicle_plate: '2E-8899',
            service_id: 1,
            appointment_date: dateStr,
            appointment_time: '14:00',
            customer_name: 'Sovan Kiry',
            customer_phone: '012 889 900',
            notes: 'Showcase Car 1',
            staff_id: myStaffId
        });
        if (a1?.appointment_id) {
            await PosApi.appointmentArrived(a1.appointment_id, myStaffId);
        }

        // Car 2
        const a2 = await PosApi.createAppointment({
            plate_number: '2F-4433',
            vehicle_plate: '2F-4433',
            service_id: 1,
            appointment_date: dateStr,
            appointment_time: '14:30',
            customer_name: 'Chanthou Meas',
            customer_phone: '012 443 322',
            notes: 'Showcase Car 2',
            staff_id: myStaffId
        });
        if (a2?.appointment_id) {
            await PosApi.appointmentArrived(a2.appointment_id, myStaffId);
        }
    });

    console.log('3. Navigating to Queue Screen in Light Mode...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    const shotShowcaseLight = path.join(brainDir, 'queue_modern_cockpit_light.png');
    await page.screenshot({ path: shotShowcaseLight });
    console.log('Saved queue_modern_cockpit_light.png:', shotShowcaseLight);

    console.log('4. Navigating to Queue Screen in Dark Mode...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
    });
    await page.waitForTimeout(1000);

    const shotShowcaseDark = path.join(brainDir, 'queue_modern_cockpit_dark.png');
    await page.screenshot({ path: shotShowcaseDark });
    console.log('Saved queue_modern_cockpit_dark.png:', shotShowcaseDark);

    // Switch back to light theme
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        document.body.setAttribute('data-theme', 'light');
        localStorage.setItem('pos_theme', 'light');
    });

    console.log('Showcase generation complete!');
    await browser.close();
})();
