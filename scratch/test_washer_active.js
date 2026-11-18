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
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100,
            defaultLanguage: 'en'
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#washer');
    await page.waitForTimeout(1000);

    const mockData = {
        success: true,
        bays: [
            {
                id: 1,
                bay_name: 'Bay 1 Premium',
                bay_type: 'wash',
                status: 'busy',
                in_bay_minutes: 14,
                plate_number: '2F-4433',
                customer_name: 'Chanthou Meas',
                vehicle_type: 'Sedan',
                service_names: 'Full Body Foam Wash + Underbody Spray'
            },
            {
                id: 2,
                bay_name: 'Bay 2',
                bay_type: 'wash',
                status: 'free',
                in_bay_minutes: 0,
                plate_number: null
            },
            {
                id: 3,
                bay_name: 'Bay 3',
                bay_type: 'wash',
                status: 'busy',
                is_mixed: 1,
                in_bay_minutes: 25,
                plate_number: '2AB-0912',
                customer_name: 'Nhax Tiffy',
                vehicle_type: 'SUV',
                service_names: 'Express Wash + Oil Change Inspection'
            }
        ],
        wash_queue: [
            {
                position: 1,
                order_id: 101,
                plate_number: '2BB-3359',
                customer_name: 'Walk-in Guest',
                vehicle_type: 'small_car',
                wait_minutes: 6,
                service_names: 'Exterior Foam Wash'
            },
            {
                position: 2,
                order_id: 102,
                plate_number: 'TEST-098',
                customer_name: 'Bona Keo',
                vehicle_type: 'pickup',
                wait_minutes: 15,
                service_names: 'Premium Wash + Wax Polish'
            }
        ]
    };

    // First render in English
    await page.evaluate((data) => {
        window.PosApi.getBaysAndQueue = async () => data;
        if (window.I18nManager) window.I18nManager.setLang('en');
        window.PosApp.switchView('washer');
    }, mockData);
    await page.waitForTimeout(1000);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/redesigned_washer_active_en.png' });
    console.log('Saved redesigned_washer_active_en.png');

    // Switch to Khmer
    await page.evaluate(async (data) => {
        window.PosApi.getBaysAndQueue = async () => data;
        if (window.I18nManager) window.I18nManager.setLang('km');
        await window.PosApp.fetchBaysAndQueue(true);
        await window.PosApp.renderWasherScreen();
    }, mockData);
    await page.waitForTimeout(1000);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/redesigned_washer_active_km.png' });
    console.log('Saved redesigned_washer_active_km.png');

    await browser.close();
})();
