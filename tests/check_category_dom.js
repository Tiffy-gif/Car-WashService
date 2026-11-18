const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'pos_sec_5924af8669fa53d7a7941439e66926ec';
const DEVICE_UID = 'POS-CAASIER';

function getRealStaffSession() {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username: 'visal', password: '123456' });
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
    const loginRes = await getRealStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'pos_sec_5924af8669fa53d7a7941439e66926ec',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.waitForTimeout(2000);

    const domInfo = await page.evaluate(() => {
        const sidebar = document.getElementById('categorySidebar');
        const listWrap = document.getElementById('categoryListWrap');
        const sbStyle = window.getComputedStyle(sidebar);
        const lwStyle = window.getComputedStyle(listWrap);
        return {
            sidebar: {
                box: sidebar ? sidebar.getBoundingClientRect() : null,
                scrollHeight: sidebar ? sidebar.scrollHeight : null,
                clientHeight: sidebar ? sidebar.clientHeight : null,
                overflowY: sbStyle ? sbStyle.overflowY : null,
                scrollbarWidth: sbStyle ? sbStyle.scrollbarWidth : null,
                direction: sbStyle ? sbStyle.direction : null
            },
            listWrap: {
                box: listWrap ? listWrap.getBoundingClientRect() : null,
                scrollHeight: listWrap ? listWrap.scrollHeight : null,
                clientHeight: listWrap ? listWrap.clientHeight : null,
                overflowY: lwStyle ? lwStyle.overflowY : null,
                scrollbarWidth: lwStyle ? lwStyle.scrollbarWidth : null,
                direction: lwStyle ? lwStyle.direction : null
            }
        };
    });
    console.log(JSON.stringify(domInfo, null, 2));
    await browser.close();
})();
