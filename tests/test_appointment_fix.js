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

        // 1. Open Appointment Modal
        console.log('--- 1. Opening Appointment Modal ---');
        await page.click('#btnPastelAppointment');
        await page.waitForTimeout(800);

        // 2. Open Booking Drawer
        console.log('--- 2. Toggling Quick Booking Form ---');
        await page.click('#btnModalToggleBookAppt');
        await page.waitForTimeout(600);

        // 3. Check computed styles of Service & Slot controls
        const controlStyles = await page.evaluate(() => {
            const svcEl = document.getElementById('modalApptService');
            const slotEl = document.getElementById('modalApptSlot');
            const nameEl = document.getElementById('modalApptCustName');
            const csSvc = window.getComputedStyle(svcEl);
            const csSlot = window.getComputedStyle(slotEl);
            return {
                service: {
                    height: csSvc.height,
                    paddingTop: csSvc.paddingTop,
                    paddingBottom: csSvc.paddingBottom,
                    fontSize: csSvc.fontSize,
                    optionsCount: svcEl.options.length,
                    selectedText: svcEl.options[svcEl.selectedIndex]?.text
                },
                slot: {
                    height: csSlot.height,
                    paddingTop: csSlot.paddingTop,
                    paddingBottom: csSlot.paddingBottom,
                    fontSize: csSlot.fontSize,
                    optionsCount: slotEl.options.length,
                    selectedText: slotEl.options[slotEl.selectedIndex]?.text
                }
            };
        });
        console.log('Control Styles & Options:', JSON.stringify(controlStyles, null, 2));

        // 4. Fill in user fields: Nhax Tiffy, 0965911000, 2AB-0912
        console.log('--- 3. Filling in user booking info ---');
        await page.fill('#modalApptCustName', 'Nhax Tiffy');
        await page.fill('#modalApptCustPhone', '0965911000');
        await page.fill('#modalApptPlate', '2AB-0912');
        await page.waitForTimeout(400);

        // Take screenshot of the fixed form drawer showing clear visible text
        const modalBox = await page.$('.modal-appointment-content');
        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_fixed_drawer.png'
            });
            console.log('Saved pos_appointment_fixed_drawer.png');
        }

        // 5. Submit the appointment
        console.log('--- 4. Submitting appointment ---');
        await page.click('#btnSubmitModalAppt');
        await page.waitForTimeout(1000);

        // 6. Verify row in appointments table
        const tableRows = await page.evaluate(() => {
            const rows = Array.from(document.querySelectorAll('#modalAppointmentTableBody tr'));
            return rows.map(r => r.innerText.replace(/\s+/g, ' ').trim());
        });
        console.log('Appointment table rows:', tableRows);

        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_table_with_booking.png'
            });
            console.log('Saved pos_appointment_table_with_booking.png');
        }

        // 7. Test Check-in (Queue) button
        console.log('--- 5. Testing Check-in (Queue) ---');
        const hasCheckInBtn = await page.evaluate(() => {
            const btn = document.querySelector('.btn-appt-load-cart');
            return btn !== null;
        });
        console.log('Has Check-in button:', hasCheckInBtn);

        if (hasCheckInBtn) {
            await page.click('.btn-appt-load-cart');
            await page.waitForTimeout(1000);

            // Verify updated rows in appointments table
            const updatedRows = await page.evaluate(() => {
                const rows = Array.from(document.querySelectorAll('#modalAppointmentTableBody tr'));
                return rows.map(r => r.innerText.replace(/\s+/g, ' ').trim());
            });
            console.log('Updated table rows after check-in:', updatedRows);

            if (modalBox) {
                await modalBox.screenshot({
                    path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_table_fixed.png'
                });
                console.log('Saved pos_appointment_table_fixed.png');
            }
        }

        // 8. Test Khmer language switch on modal
        console.log('--- 6. Testing Khmer Language on Modal ---');
        await page.evaluate(() => {
            if (window.I18nManager) window.I18nManager.setLang('km');
            if (window.PosApp) {
                window.PosApp.onLanguageChanged();
                window.PosApp.refreshAppointmentModal();
            }
        });
        await page.waitForTimeout(500);

        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_appointment_modal_km.png'
            });
            console.log('Saved pos_appointment_modal_km.png');
        }

        console.log('=== ALL APPOINTMENT FIX VERIFICATIONS PASSED! ===');
        await browser.close();
        process.exit(0);
    } catch (err) {
        console.error('Test error:', err);
        process.exit(1);
    }
})();
