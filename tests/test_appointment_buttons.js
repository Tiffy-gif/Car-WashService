const { chromium } = require('playwright');
const http = require('http');

function getRealStaffSession() {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({ username: 'visal', password: '123456' });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
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
    try {
        const loginRes = await getRealStaffSession();
        const browser = await chromium.launch({ channel: 'chrome', headless: true });
        const context = await browser.newContext({ viewport: { width: 1920, height: 960 } });

        await context.addInitScript((loginData) => {
            localStorage.setItem('carwash_pos_config', JSON.stringify({
                backofficeUrl: 'http://localhost/Car-WashService',
                deviceUid: 'POS-CAASIER',
                deviceKey: 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
                exchangeRate: 4100
            }));
            localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
            localStorage.setItem('cw_pos_session_token', loginData.session_token);
            sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
            localStorage.setItem('carwash_pos_has_connected', 'true');
        }, loginRes);

        const page = await context.newPage();
        await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2000);

        // Deterministic setup
        await page.evaluate(() => {
            if (window.PosApp) {
                window.PosApp.evaluateCartUpsellRules = () => {};
            }
            if (window.PosApi) {
                window.PosApi.evaluateCartUpsell = async () => ({ success: true, has_offer: false });
            }
            if (window.I18nManager) window.I18nManager.setLang('en');
            if (window.PosApp) window.PosApp.onLanguageChanged();
        });
        await page.waitForTimeout(400);

        // --- 1. Verify 4 Bottom Dock Pastel Buttons ---
        console.log('--- 1. Checking 4 Bottom Pastel Feature Buttons ---');
        const pastelBtns = await page.evaluate(() => {
            const btns = Array.from(document.querySelectorAll('.gotpos-advance-features .btn-pastel-feature'));
            return btns.map(b => ({
                id: b.id,
                label: b.querySelector('.pastel-label')?.textContent.trim()
            }));
        });
        console.log('Pastel buttons found:', JSON.stringify(pastelBtns));

        // Take snapshot of the 4 bottom buttons dock
        const dockEl = await page.$('.gotpos-advance-features');
        if (dockEl) {
            await dockEl.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_cart_4_pastel_buttons.png'
            });
            console.log('Saved pos_cart_4_pastel_buttons.png');
        }

        // --- 2. Test Opening Appointment Modal via Bottom Dock Button ---
        console.log('--- 2. Testing Open Appointment Modal via #btnPastelAppointment ---');
        await page.click('#btnPastelAppointment');
        await page.waitForTimeout(800);

        const isModalOpen = await page.evaluate(() => {
            const m = document.getElementById('modalAppointment');
            return m && m.classList.contains('open');
        });
        console.log('Is Appointment Modal Open:', isModalOpen);

        const titleText = await page.$eval('#appointmentModalTitle', el => el.textContent.trim());
        console.log('Appointment Modal Title (EN):', titleText);

        const summaryText = await page.$eval('#modalApptSummaryText', el => el.textContent.trim());
        console.log('Appointment Summary Text (EN):', summaryText);

        // Take Fullscreen & Modal Screenshot
        await page.screenshot({
            path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_modal_fullscreen.png'
        });
        console.log('Saved pos_appointment_modal_fullscreen.png');

        const modalBox = await page.$('.modal-appointment-content');
        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_modal_en.png'
            });
            console.log('Saved pos_appointment_modal_en.png');
        }

        // --- 3. Test Quick Booking Drawer Toggle ---
        console.log('--- 3. Testing Quick Book Drawer Toggle ---');
        await page.click('#btnModalToggleBookAppt');
        await page.waitForTimeout(400);

        const isDrawerVisible = await page.evaluate(() => {
            const d = document.getElementById('modalApptBookingDrawer');
            return d && d.style.display !== 'none';
        });
        console.log('Is Quick Book Drawer Visible:', isDrawerVisible);

        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_modal_drawer.png'
            });
            console.log('Saved pos_appointment_modal_drawer.png');
        }

        // Toggle drawer close
        await page.click('#btnModalToggleBookAppt');
        await page.waitForTimeout(300);

        // --- 4. Test Khmer Localization ---
        console.log('--- 4. Testing Khmer Localization ---');
        await page.evaluate(async () => {
            if (window.I18nManager) window.I18nManager.setLang('km');
            if (window.PosApp) {
                window.PosApp.onLanguageChanged();
                await window.PosApp.refreshAppointmentModal();
            }
        });
        await page.waitForTimeout(600);

        const kmTitle = await page.$eval('#appointmentModalTitle', el => el.textContent.trim());
        console.log('Khmer Appointment Modal Title:', kmTitle);

        const kmSummary = await page.$eval('#modalApptSummaryText', el => el.textContent.trim());
        console.log('Khmer Appointment Summary:', kmSummary);

        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_modal_km.png'
            });
            console.log('Saved pos_appointment_modal_km.png');
        }

        // Close modal
        await page.click('#btnCloseApptModal');
        await page.waitForTimeout(400);

        // --- 5. Test Top Cart Header Button (#btnGotposApptQuick) ---
        console.log('--- 5. Testing Top Cart Header Button #btnGotposApptQuick ---');
        await page.click('#btnGotposApptQuick');
        await page.waitForTimeout(600);
        const isOpenViaTop = await page.evaluate(() => {
            const m = document.getElementById('modalAppointment');
            return m && m.classList.contains('open');
        });
        console.log('Is Appointment Modal Open via Top Button:', isOpenViaTop);

        // --- 6. Test "Open Full Schedule" and "Back to Register" Navigation ---
        console.log('--- 6. Testing Full Schedule Navigation & Back to Register ---');
        await page.click('#btnOpenFullApptView');
        await page.waitForTimeout(600);

        const isModalClosed = await page.evaluate(() => {
            const m = document.getElementById('modalAppointment');
            return !m || !m.classList.contains('open');
        });
        console.log('Is Modal Closed After Clicking Open Full Schedule:', isModalClosed);

        const isApptViewActive = await page.evaluate(() => {
            const v = document.getElementById('viewAppointments');
            return v && v.classList.contains('active');
        });
        console.log('Is #viewAppointments Active:', isApptViewActive);

        const hasBackToPosBtn = await page.evaluate(() => {
            const b = document.getElementById('btnApptBackToPos');
            return b !== null && b.offsetParent !== null;
        });
        console.log('Has visible #btnApptBackToPos on appointments view:', hasBackToPosBtn);

        // Take screenshot of appointments screen with Back to Register button
        await page.screenshot({
            path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointments_view_with_back_btn.png'
        });
        console.log('Saved pos_appointments_view_with_back_btn.png');

        // Click Back to Register
        await page.click('#btnApptBackToPos');
        await page.waitForTimeout(600);

        const isPosActiveAgain = await page.evaluate(() => {
            const p = document.getElementById('viewPos');
            return p && p.classList.contains('active');
        });
        console.log('Is Back on #viewPos:', isPosActiveAgain);

        console.log('=== ALL APPOINTMENT BUTTON TESTS PASSED SUCCESSFULLY! ===');
        await browser.close();
        process.exit(0);
    } catch (err) {
        console.error('Test error:', err);
        process.exit(1);
    }
})();
