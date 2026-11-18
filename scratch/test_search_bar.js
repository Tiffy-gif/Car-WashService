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
        localStorage.setItem('cw_pos_language', 'km');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 1. Initial State
    const totalCards = await page.$$eval('#catalogGrid .pos-item-card', cards => cards.length);
    console.log('Total cards in grid initially:', totalCards);

    // 2. Type "ហ្វ្រាំង" (Brake) in search
    console.log('Typing "ហ្វ្រាំង" in search input...');
    await page.fill('#posGlobalSearchInput', 'ហ្វ្រាំង');
    await page.waitForTimeout(500);

    const visibleAfterKm = await page.$$eval('#catalogGrid .pos-item-card', cards => 
        cards.filter(c => c.style.display !== 'none').length
    );
    console.log('Visible cards after Khmer search "ហ្វ្រាំង":', visibleAfterKm);
    await page.screenshot({ path: `${artifactDir}/test_search_km.png` });

    // 3. Type "oil" in search
    console.log('Typing "oil" in search input...');
    await page.fill('#posGlobalSearchInput', 'oil');
    await page.waitForTimeout(500);

    const visibleAfterEn = await page.$$eval('#catalogGrid .pos-item-card', cards => 
        cards.filter(c => c.style.display !== 'none').length
    );
    console.log('Visible cards after English search "oil":', visibleAfterEn);
    await page.screenshot({ path: `${artifactDir}/test_search_en.png` });

    // 4. Test Barcode button click
    console.log('Testing barcode button...');
    const barcodeBtn = await page.$('.gotpos-barcode-btn');
    if (barcodeBtn) {
        console.log('Barcode button exists. Clicking...');
        await barcodeBtn.click();
        await page.waitForTimeout(500);
    } else {
        console.log('Barcode button NOT found!');
    }

    // 5. Test search when non-existing word is typed
    console.log('Typing non-existing query "xyz999"...');
    await page.fill('#posGlobalSearchInput', 'xyz999');
    await page.waitForTimeout(500);

    const visibleAfterNone = await page.$$eval('#catalogGrid .pos-item-card', cards => 
        cards.filter(c => c.style.display !== 'none').length
    );
    console.log('Visible cards for "xyz999":', visibleAfterNone);
    await page.screenshot({ path: `${artifactDir}/test_search_none.png` });

    await browser.close();
})();
