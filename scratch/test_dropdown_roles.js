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

    const rolesToTest = [
        { username: 'dona', pin: '123456', label: 'Mechanic' },
        { username: 'dom', pin: '123456', label: 'Washer' },
        { username: 'visal', pin: '123456', label: 'Cashier' },
        { username: 'owner', pin: '123456', label: 'Owner' }
    ];

    for (const item of rolesToTest) {
        const login = await loginUser(item.username, item.pin);
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
            // Active shift simulating front-desk open register
            localStorage.setItem('cw_pos_current_shift', JSON.stringify({ id: 1, cashier_name: 'visal', status: 'open' }));
            localStorage.setItem('cw_pos_active_shift', JSON.stringify({ id: 1, cashier_name: 'visal', status: 'open' }));
        }, login);

        const page = await context.newPage();
        const startUrl = item.username === 'dona' ? 'http://localhost/Car-WashService/pos/index.html#mechanic'
            : (item.username === 'dom' ? 'http://localhost/Car-WashService/pos/index.html#washer' : 'http://localhost/Car-WashService/pos/index.html');
        
        await page.goto(startUrl);
        await page.waitForTimeout(2000);

        // Click user pill
        await page.click('#userPill');
        await page.waitForTimeout(300);

        const state = await page.evaluate(() => {
            const isVisible = (el) => {
                if (!el) return false;
                const style = window.getComputedStyle(el);
                return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
            };
            return {
                endShift: isVisible(document.getElementById('userMenuEndShift')),
                spotAudit: isVisible(document.getElementById('userMenuSpotAudit')),
                settings: isVisible(document.getElementById('userMenuSettings')),
                backoffice: isVisible(document.getElementById('userMenuBackoffice')),
                logout: isVisible(document.getElementById('userMenuLogout'))
            };
        });

        console.log(`=== ${item.label} (${item.username}) ===`);
        console.log(`  End Shift:  ${state.endShift ? '✅ Visible' : '🚫 Hidden'}`);
        console.log(`  Spot Audit: ${state.spotAudit ? '✅ Visible' : '🚫 Hidden'}`);
        console.log(`  Settings:   ${state.settings ? '✅ Visible' : '🚫 Hidden'}`);
        console.log(`  Backoffice: ${state.backoffice ? '✅ Visible' : '🚫 Hidden'}`);
        console.log(`  Log Out:    ${state.logout ? '✅ Visible' : '🚫 Hidden'}`);

        await page.screenshot({ 
            path: `C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/dropdown_${item.username}.png` 
        });

        await context.close();
    }

    await browser.close();
})();
