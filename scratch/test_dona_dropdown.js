const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function loginUser(username, pin) {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username, pin });
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
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const donaLogin = await loginUser('dona', '123456');
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
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
        // Let's set a mock active shift like the user's browser had
        localStorage.setItem('carwash_pos_current_shift', JSON.stringify({ id: 1, cashier_name: 'visal', status: 'open' }));
    }, donaLogin);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(2000);

    // Click user pill to open dropdown
    await page.click('#userPill');
    await page.waitForTimeout(500);

    const dropdownState = await page.evaluate(() => {
        return {
            currentUserRole: window.PosApp?.currentUser?.role,
            userMenuDropdownDisplay: document.getElementById('userMenuDropdown')?.style.display,
            endShift: {
                display: document.getElementById('userMenuEndShift')?.style.display,
                computed: window.getComputedStyle(document.getElementById('userMenuEndShift')).display
            },
            spotAudit: {
                display: document.getElementById('userMenuSpotAudit')?.style.display,
                computed: window.getComputedStyle(document.getElementById('userMenuSpotAudit')).display
            },
            settings: {
                display: document.getElementById('userMenuSettings')?.style.display,
                computed: window.getComputedStyle(document.getElementById('userMenuSettings')).display
            },
            backoffice: {
                display: document.getElementById('userMenuBackoffice')?.style.display,
                computed: window.getComputedStyle(document.getElementById('userMenuBackoffice')).display
            },
            logout: {
                display: document.getElementById('userMenuLogout')?.style.display,
                computed: window.getComputedStyle(document.getElementById('userMenuLogout')).display
            }
        };
    });

    console.log('Dropdown state for Dona:', JSON.stringify(dropdownState, null, 2));

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/dona_dropdown_current.png' });

    await context.close();
    await browser.close();
})();
