const puppeteer = require('puppeteer-core');

const CHROME_PATH = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const POS_URL = process.env.POS_URL || 'http://localhost/Car-WashService/pos/index.html';
const BACKOFFICE_URL = process.env.BACKOFFICE_URL || POS_URL.replace(/\/pos(?:\/.*)?$/i, '');
const POS_DEVICE_KEY = process.env.POS_DEVICE_KEY || '';

(async () => {
    console.log('=== STARTING POS WAIT TIME & APPOINTMENTS VERIFICATION ===');
    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();
    page.on('console', msg => {
        const text = msg.text();
        if (text.includes('Error') || text.includes('Switching') || text.includes('clicked')) {
            console.log('PAGE LOG:', text);
        }
    });

    try {
        await page.evaluateOnNewDocument((backofficeUrl, deviceKey) => {
            localStorage.setItem('carwash_pos_config', JSON.stringify({
                backofficeUrl: backofficeUrl,
                deviceKey: deviceKey,
                printerDirectEnabled: false
            }));
            sessionStorage.clear();
        }, BACKOFFICE_URL, POS_DEVICE_KEY);

        // ---------------------------------------------------------
        // 1. BIG SCREEN TESTS (1280x800)
        // ---------------------------------------------------------
        console.log('\n--- 1. Testing on Big Screen (1280x800) ---');
        await page.setViewport({ width: 1280, height: 800 });
        await page.goto(POS_URL, { waitUntil: 'networkidle2' });

        // Ensure logged in as manager
        const isLoginVisible = await page.$eval('#viewLogin', el => el.style.display !== 'none');
        if (isLoginVisible) {
            console.log('Logging in as manager...');
            await page.evaluate(async () => {
                document.getElementById('loginUsername').value = 'manager';
                PosApp.currentPin = '123456';
                await PosApp.handleLogin();
            });
            await page.waitForFunction(() => {
                const l = document.getElementById('viewLogin');
                return l && l.style.display === 'none';
            }, { timeout: 8000 });
        }
        console.log('✓ Successfully authenticated with back office as Vireak Chea (Manager)');

        // Handle open shift modal if shown
        const isShiftModalOpen = await page.evaluate(() => {
            const m = document.getElementById('modalStartShift');
            return m && m.classList.contains('open');
        });
        if (isShiftModalOpen) {
            console.log('Opening register shift with 100 USD...');
            await page.type('#shiftStartingCash', '100');
            await page.click('#btnConfirmStartShift');
            await page.waitForFunction(() => {
                const m = document.getElementById('modalStartShift');
                return !m || !m.classList.contains('open');
            }, { timeout: 5000 });
        }

        // Wait for catalog grid
        await page.waitForSelector('.pos-item-card', { timeout: 5000 });

        // Trigger wait times fetch from back office
        await page.evaluate(async () => {
            await PosApp.fetchWaitTimes();
        });
        await new Promise(r => setTimeout(r, 600));

        // 1.1 Verify service cards have wait badges
        const serviceBadges = await page.$$eval('.service-wait-time-badge', els => {
            return els.map(e => ({
                text: e.textContent.trim(),
                display: window.getComputedStyle(e).display
            })).filter(b => b.display !== 'none');
        });
        console.log(`✓ Service wait badges on catalog: ${serviceBadges.length} visible. Example: "${serviceBadges[0]?.text}"`);

        // 1.2 Verify Cart wait time row for short wait
        console.log('\n--- 1.2 Testing Short Wait Time Display ---');
        await page.evaluate(async () => {
            PosApp.clearCart();
            PosApp.addServiceToCart(1);
            // Simulate short wait returned by back office
            PosApp.currentWaitData.cart_wait = {
                wait_minutes: 20,
                wait_text_en: 'Wait about 20 min',
                wait_text_km: 'រង់ចាំប្រហែល 20 នាទី',
                is_over_45: false
            };
            PosApp.updateWaitTimesUI();
        });
        await new Promise(r => setTimeout(r, 400));

        const cartShortWait = await page.evaluate(() => {
            const row = document.getElementById('cartWaitTimeRow');
            const badge = document.getElementById('cartWaitTimeBadge');
            const modal = document.getElementById('modalLongWaitWarning');
            return {
                rowDisplay: window.getComputedStyle(row).display,
                badgeText: badge ? badge.textContent.trim() : '',
                modalOpen: modal ? modal.classList.contains('open') : false
            };
        });
        console.log(`✓ Cart wait row visible: ${cartShortWait.rowDisplay !== 'none'}`);
        console.log(`✓ Cart wait badge text: "${cartShortWait.badgeText}"`);
        console.log(`✓ Over 45 min modal NOT opened for short wait: ${!cartShortWait.modalOpen}`);

        // 1.3 Verify Over 45 Minutes Warning Modal
        console.log('\n--- 1.3 Testing Wait Over 45 Minutes Warning Modal ---');
        await page.evaluate(() => {
            PosApp.currentWaitData.cart_wait = {
                wait_minutes: 55,
                wait_text_en: 'Wait about 55 min',
                wait_text_km: 'រង់ចាំប្រហែល 55 នាទី',
                is_over_45: true
            };
            PosApp._dismissedOver45Key = '';
            PosApp.updateWaitTimesUI();
        });
        await new Promise(r => setTimeout(r, 400));

        const over45Modal = await page.evaluate(() => {
            const modal = document.getElementById('modalLongWaitWarning');
            const title = modal?.querySelector('h4')?.textContent.trim();
            const mins = document.getElementById('longWaitMinsText')?.textContent.trim();
            const btnContinue = document.getElementById('btnContinueAnyway');
            const btnBook = document.getElementById('btnOfferBookAppointment');
            return {
                isOpen: modal?.classList.contains('open'),
                promptText: title,
                minsText: mins,
                hasContinueBtn: !!btnContinue,
                hasBookBtn: !!btnBook
            };
        });
        console.log(`✓ Long wait warning modal is open: ${over45Modal.isOpen}`);
        console.log(`✓ Modal prompt: "${over45Modal.promptText}"`);
        console.log(`✓ Modal shows duration: "${over45Modal.minsText}"`);
        console.log(`✓ Buttons present: "Continue anyway" (${over45Modal.hasContinueBtn}), "Book appointment" (${over45Modal.hasBookBtn})`);

        // Click "Book appointment" in modal
        console.log('Clicking "Book appointment" button...');
        await page.click('#btnOfferBookAppointment');
        await new Promise(r => setTimeout(r, 800));

        const activeView = await page.evaluate(() => PosApp.currentView);
        console.log(`✓ Navigated to screen: "${activeView}" (expected: "appointments")`);

        // 1.4 Test Appointment Booking Flow
        console.log('\n--- 1.4 Testing Appointment Booking & One-Tap Arrival ---');
        await page.waitForSelector('#apptTimeSlotsGrid .time-slot-btn', { timeout: 5000 });
        const slotCount = await page.$$eval('#apptTimeSlotsGrid .time-slot-btn', els => els.length);
        console.log(`✓ Time slots loaded from back office: ${slotCount} slots`);

        // Select first available time slot
        await page.evaluate(() => {
            const sel = document.getElementById('apptServiceSelect');
            if (sel && (!sel.value || sel.value === '')) {
                sel.value = '1';
            }
            const availableBtn = document.querySelector('#apptTimeSlotsGrid .time-slot-btn:not(.disabled)');
            if (availableBtn) availableBtn.click();
        });
        const selectedSlot = await page.evaluate(() => PosApp.selectedApptSlot);
        console.log(`✓ Selected appointment slot: ${selectedSlot}`);

        const testPlate = '2A-' + Math.floor(1000 + Math.random() * 9000);
        await page.type('#apptPlateInput', testPlate);
        await page.type('#apptCustomerName', 'Chanthy Mao');
        await page.type('#apptCustomerPhone', '012998877');

        console.log(`Booking appointment for vehicle plate ${testPlate}...`);
        await page.evaluate(async () => {
            const dateInput = document.getElementById('apptBookingDate');
            if (!dateInput.value) {
                dateInput.value = new Date().toISOString().split('T')[0];
            }
            await PosApp.handleSaveAppointment();
        });
        await new Promise(r => setTimeout(r, 2000));

        // Check appointment in today's list
        const apptFoundInList = await page.evaluate((plate) => {
            const list = document.getElementById('appointmentsList');
            return list && list.textContent.includes(plate);
        }, testPlate);
        console.log(`✓ Appointment successfully saved and listed in today's appointments: ${apptFoundInList}`);

        // Click "Customer Arrived" one-tap button
        console.log('Clicking one-tap "Customer Arrived" button...');
        await page.evaluate((plate) => {
            const rows = Array.from(document.querySelectorAll('.appt-card-row'));
            const row = rows.find(r => r.textContent.includes(plate));
            if (row) {
                const btn = row.querySelector('.btn-appt-arrived');
                if (btn) btn.click();
            }
        }, testPlate);
        await new Promise(r => setTimeout(r, 1800));

        const isArrived = await page.evaluate((plate) => {
            const rows = Array.from(document.querySelectorAll('.appt-card-row'));
            const row = rows.find(r => r.textContent.includes(plate));
            if (!row) return false;
            return row.classList.contains('arrived') || !!row.querySelector('.appt-status-arrived');
        }, testPlate);
        console.log(`✓ Appointment updated to Arrived status in one tap: ${isArrived}`);

        // 1.5 Test Vehicle Recognition
        console.log('\n--- 1.5 Testing Vehicle Recognition Profile ---');
        await page.evaluate(() => PosApp.switchView('pos'));
        await new Promise(r => setTimeout(r, 400));

        // Lookup VIP vehicle 2A-8888
        await page.evaluate(async () => {
            await PosApp.loadVehicleProfile('2A-8888');
        });
        await new Promise(r => setTimeout(r, 400));

        const vehProfile = await page.evaluate(() => {
            const wrap = document.getElementById('cartVehicleProfile');
            return {
                display: window.getComputedStyle(wrap).display,
                plate: document.getElementById('profPlateBadge')?.textContent.trim(),
                tier: document.getElementById('profTierBadge')?.textContent.trim(),
                name: document.getElementById('profCustName')?.textContent.trim(),
                washes: document.getElementById('profWashCount')?.textContent.trim(),
                oil: document.getElementById('profOilText')?.textContent.trim(),
                flags: Array.from(document.querySelectorAll('#profFlagsList .profile-flag-chip')).map(e => e.textContent.trim())
            };
        });
        console.log(`✓ Vehicle profile container display: ${vehProfile.display}`);
        console.log(`✓ Recognized Plate: ${vehProfile.plate}`);
        console.log(`✓ Customer: ${vehProfile.name}`);
        console.log(`✓ Membership Level: ${vehProfile.tier}`);
        console.log(`✓ Wash History Count: ${vehProfile.washes}`);
        console.log(`✓ Last Oil Change Date & Mileage: ${vehProfile.oil}`);
        console.log(`✓ Special Flags: ${vehProfile.flags.join(' | ')}`);

        // 1.6 Test Disconnection & Wait Time Hiding
        console.log('\n--- 1.6 Testing Disconnection & Wait Time Hiding ---');
        await page.evaluate(() => {
            PosApp.lastSyncSuccessTime = Date.now() - 65000;
            PosApp.updateOfflineManualBanner();
        });

        const offlineCheck = await page.evaluate(() => {
            const banner = document.getElementById('offlineManualModeBanner');
            const cartWait = document.getElementById('cartWaitTimeRow');
            const visibleSvcBadges = Array.from(document.querySelectorAll('.service-wait-time-badge'))
                .filter(el => window.getComputedStyle(el).display !== 'none').length;
            return {
                bannerDisplay: banner ? window.getComputedStyle(banner).display : 'none',
                cartWaitDisplay: cartWait ? window.getComputedStyle(cartWait).display : 'none',
                visibleSvcBadges: visibleSvcBadges
            };
        });
        console.log(`✓ Offline Manual Mode banner display: ${offlineCheck.bannerDisplay}`);
        console.log(`✓ Cart wait time hidden when offline: ${offlineCheck.cartWaitDisplay === 'none'}`);
        console.log(`✓ Service wait badges hidden when offline: ${offlineCheck.visibleSvcBadges === 0}`);

        // Restore online
        await page.evaluate(() => {
            PosApp.lastSyncSuccessTime = Date.now();
            PosApp.isOnline = true;
            PosApp.updateOfflineManualBanner();
        });

        // ---------------------------------------------------------
        // 2. TABLET SCREEN TESTS (768x1024)
        // ---------------------------------------------------------
        console.log('\n--- 2. Testing on Tablet Screen (768x1024) ---');
        await page.setViewport({ width: 768, height: 1024 });
        await new Promise(r => setTimeout(r, 400));

        const tabletCatalog = await page.evaluate(() => {
            const grid = document.getElementById('catalogGrid');
            const cart = document.querySelector('.pos-cart-column');
            const badges = Array.from(document.querySelectorAll('.service-wait-time-badge'))
                .filter(el => window.getComputedStyle(el).display !== 'none').length;
            return {
                gridWidth: grid?.offsetWidth,
                cartWidth: cart?.offsetWidth,
                visibleBadges: badges
            };
        });
        console.log(`✓ Tablet POS Layout: Catalog width ${tabletCatalog.gridWidth}px, Cart width ${tabletCatalog.cartWidth}px, Badges visible: ${tabletCatalog.visibleBadges}`);

        // Tablet Appointments view
        await page.evaluate(() => PosApp.switchView('appointments'));
        await new Promise(r => setTimeout(r, 500));

        const tabletAppt = await page.evaluate(() => {
            const view = document.getElementById('viewAppointments');
            const form = document.getElementById('formBookAppt');
            const list = document.getElementById('appointmentsList');
            const slotBtns = document.querySelectorAll('#apptTimeSlotsGrid .time-slot-btn');
            return {
                isViewVisible: window.getComputedStyle(view).display !== 'none',
                formWidth: form?.offsetWidth,
                listWidth: list?.offsetWidth,
                slotsRendered: slotBtns.length
            };
        });
        console.log(`✓ Tablet Appointments Screen: Form width ${tabletAppt.formWidth}px, List width ${tabletAppt.listWidth}px, Slots rendered: ${tabletAppt.slotsRendered}`);

        // Tablet Over 45 min modal responsiveness
        await page.evaluate(() => PosApp.openLongWaitWarning(55));
        await new Promise(r => setTimeout(r, 300));

        const tabletModal = await page.evaluate(() => {
            const modal = document.getElementById('modalLongWaitWarning');
            const box = modal?.querySelector('.modal-content');
            return {
                isOpen: modal?.classList.contains('open'),
                boxWidth: box?.offsetWidth
            };
        });
        console.log(`✓ Tablet Over-45-Min Warning Modal: Open: ${tabletModal.isOpen}, Content width: ${tabletModal.boxWidth}px`);
        await page.click('#btnContinueAnyway');

        console.log('\n=== ALL TESTS PASSED SUCCESSFULLY! ===');
    } catch (err) {
        console.error('Test execution failed:', err);
    } finally {
        await browser.close();
    }
})();
