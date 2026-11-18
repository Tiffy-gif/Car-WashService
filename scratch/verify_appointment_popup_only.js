const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function getStaffSession() {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username: 'visal', pin: '123456' });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': DEVICE_UID,
                'X-Device-Key': DEVICE_KEY,
                'Content-Length': Buffer.byteLength(payload)
            }
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.write(payload);
        req.end();
    });
}

(async () => {
    const loginRes = await getStaffSession();

    const browser = await chromium.launch({ 
        channel: 'chrome', 
        headless: true,
        ignoreDefaultArgs: ['--hide-scrollbars']
    });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, loginRes);

    const page = await context.newPage();

    const consoleErrors = [];
    page.on('console', msg => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', err => {
        consoleErrors.push(err.toString());
    });

    // Test 1: Direct navigation to #appointments URL
    console.log('--- Test 1: Navigation to #appointments ---');
    await page.goto('http://localhost/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(2000);

    const test1State = await page.evaluate(() => {
        const modal = document.getElementById('modalAppointment');
        const viewAppointments = document.getElementById('viewAppointments');
        const viewPos = document.getElementById('viewPos');
        const openFullBtn = document.getElementById('btnOpenFullApptView');
        return {
            urlHash: window.location.hash,
            modalIsOpen: modal?.classList.contains('open') || false,
            viewAppointmentsExists: !!viewAppointments,
            viewPosIsActive: viewPos?.classList.contains('active') || false,
            openFullBtnExists: !!openFullBtn
        };
    });
    console.log('Test 1 State:', test1State);

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({ path: `${artifactDir}/pos_popup_only_modal_open.png` });
    console.log('Saved pos_popup_only_modal_open.png');

    // Test 2: Close modal and verify we are cleanly on #pos
    console.log('--- Test 2: Close Modal ---');
    await page.click('#btnCloseApptModalBtn');
    await page.waitForTimeout(1000);

    const test2State = await page.evaluate(() => {
        const modal = document.getElementById('modalAppointment');
        const viewPos = document.getElementById('viewPos');
        return {
            modalIsOpen: modal?.classList.contains('open') || false,
            viewPosIsActive: viewPos?.classList.contains('active') || false
        };
    });
    console.log('Test 2 State:', test2State);

    // Test 3: Open via bottom dock Appointment button
    console.log('--- Test 3: Open via bottom dock button ---');
    await page.click('#btnPastelAppointment');
    await page.waitForTimeout(1000);

    const test3State = await page.evaluate(() => {
        const modal = document.getElementById('modalAppointment');
        return {
            modalIsOpen: modal?.classList.contains('open') || false
        };
    });
    console.log('Test 3 State:', test3State);

    // Test 4: Open quick book drawer
    console.log('--- Test 4: Toggle Booking Drawer ---');
    await page.click('#btnModalToggleBookAppt');
    await page.waitForTimeout(1000);

    await page.screenshot({ path: `${artifactDir}/pos_popup_only_booking_drawer.png` });
    console.log('Saved pos_popup_only_booking_drawer.png');

    console.log('Console Errors:', consoleErrors);

    await browser.close();
})();
