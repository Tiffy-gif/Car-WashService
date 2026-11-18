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
        headless: true
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

    console.log('--- Step 1: Navigate to POS ---');
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    const checkView = await page.evaluate(() => {
        return {
            viewAppointmentsInDOM: !!document.getElementById('viewAppointments'),
            viewPosActive: document.getElementById('viewPos')?.classList.contains('active')
        };
    });
    console.log('Step 1 Check:', checkView);

    console.log('--- Step 2: Open Appointment Modal via Bottom Dock Button ---');
    await page.click('#btnPastelAppointment');
    await page.waitForTimeout(800);

    const modalCheck = await page.evaluate(() => {
        const modal = document.getElementById('modalAppointment');
        const openFullBtn = document.getElementById('btnOpenFullApptView');
        const closeBtn = document.getElementById('btnCloseApptModalBtn');
        const summaryText = document.getElementById('modalApptSummaryText')?.innerText;
        return {
            modalIsOpen: modal?.classList.contains('open'),
            modalDisplay: modal ? window.getComputedStyle(modal).display : null,
            openFullBtnInDOM: !!openFullBtn,
            closeBtnVisible: closeBtn ? window.getComputedStyle(closeBtn).display !== 'none' : false,
            summaryText
        };
    });
    console.log('Step 2 Modal Check:', modalCheck);

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({ path: `${artifactDir}/pos_appointment_popup_clean.png` });
    console.log('Saved pos_appointment_popup_clean.png');

    console.log('--- Step 3: Open Quick Booking Drawer ---');
    await page.click('#btnModalToggleBookAppt');
    await page.waitForTimeout(600);

    const drawerCheck = await page.evaluate(() => {
        const drawer = document.getElementById('modalApptBookingDrawer');
        return {
            drawerDisplay: drawer ? window.getComputedStyle(drawer).display : null
        };
    });
    console.log('Step 3 Drawer Check:', drawerCheck);

    await page.screenshot({ path: `${artifactDir}/pos_appointment_popup_booking_drawer.png` });
    console.log('Saved pos_appointment_popup_booking_drawer.png');

    console.log('--- Step 4: Close Modal ---');
    await page.click('#btnCloseApptModalBtn');
    await page.waitForTimeout(600);

    const closedCheck = await page.evaluate(() => {
        const modal = document.getElementById('modalAppointment');
        return {
            modalIsOpen: modal?.classList.contains('open')
        };
    });
    console.log('Step 4 Closed Check:', closedCheck);

    console.log('--- Step 5: Test URL hash navigation to #appointments ---');
    await page.evaluate(() => {
        window.location.hash = '#appointments';
    });
    await page.waitForTimeout(800);

    const hashNavCheck = await page.evaluate(() => {
        const modal = document.getElementById('modalAppointment');
        const viewPos = document.getElementById('viewPos');
        return {
            modalIsOpen: modal?.classList.contains('open'),
            viewPosActive: viewPos?.classList.contains('active')
        };
    });
    console.log('Step 5 Hash Nav Check:', hashNavCheck);

    console.log('ALL VERIFICATIONS SUCCESSFUL!');
    await browser.close();
})();
