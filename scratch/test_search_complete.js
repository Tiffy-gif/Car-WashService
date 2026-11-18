const { chromium } = require('playwright');
const http = require('http');

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
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'my_test_device_key_unique9',
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

    // Helper to count visible cards
    async function getVisibleCardCount() {
        return await page.$$eval('#catalogGrid .pos-item-card', cards => {
            return cards.filter(c => window.getComputedStyle(c).display !== 'none').length;
        });
    }

    // 1. Initial State
    const initialCount = await getVisibleCardCount();
    console.log('1. Initial visible cards:', initialCount);

    // 2. Search Khmer "ហ្វ្រាំង" (Brake)
    console.log('2. Searching Khmer "ហ្វ្រាំង"...');
    await page.fill('#posGlobalSearchInput', 'ហ្វ្រាំង');
    await page.waitForTimeout(400);
    const countKm = await getVisibleCardCount();
    console.log('   Visible cards after Khmer search:', countKm);
    await page.screenshot({ path: `${artifactDir}/search_khmer_active.png` });

    // 3. Search English "oil"
    console.log('3. Searching English "oil"...');
    await page.fill('#posGlobalSearchInput', 'oil');
    await page.waitForTimeout(400);
    const countEn = await getVisibleCardCount();
    console.log('   Visible cards after English search:', countEn);
    await page.screenshot({ path: `${artifactDir}/search_english_active.png` });

    // 4. Search non-existing query "xyz999"
    console.log('4. Searching non-existing "xyz999"...');
    await page.fill('#posGlobalSearchInput', 'xyz999');
    await page.waitForTimeout(400);
    const countNone = await getVisibleCardCount();
    const emptyStateVisible = await page.$eval('#posCatalogSearchEmpty', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    console.log('   Visible cards for "xyz999":', countNone, '| Empty state shown:', emptyStateVisible);
    await page.screenshot({ path: `${artifactDir}/search_empty_state.png` });

    // 5. Test Clear Button in Empty State
    console.log('5. Clicking empty state clear button...');
    await page.click('.pos-search-empty-btn');
    await page.waitForTimeout(400);
    const countAfterEmptyClear = await getVisibleCardCount();
    const inputValueAfterClear = await page.$eval('#posGlobalSearchInput', el => el.value);
    console.log('   Visible cards after clear button:', countAfterEmptyClear, '| Input value:', `"${inputValueAfterClear}"`);

    // 6. Test Enter Key to Add Single Matching Item
    console.log('6. Searching unique service "Engine Bay Wash" and pressing Enter...');
    await page.fill('#posGlobalSearchInput', 'Engine Bay');
    await page.waitForTimeout(400);
    const countEngine = await getVisibleCardCount();
    console.log('   Visible cards before Enter:', countEngine);

    // Initial cart items
    const cartCountBefore = await page.$$eval('#cartList .cart-item-row', rows => rows.length);
    console.log('   Cart items before Enter:', cartCountBefore);

    await page.press('#posGlobalSearchInput', 'Enter');
    await page.waitForTimeout(600);

    const cartCountAfter = await page.$$eval('#cartList .cart-item-row', rows => rows.length);
    const countAfterEnter = await getVisibleCardCount();
    console.log('   Cart items after Enter:', cartCountAfter, '| Catalog reset to full:', countAfterEnter);
    await page.screenshot({ path: `${artifactDir}/search_enter_auto_add.png` });

    // 7. Test Barcode Button Click
    console.log('7. Testing Barcode button click...');
    await page.click('#btnPosBarcodeScan');
    await page.waitForTimeout(300);
    const isInputFocused = await page.$eval('#posGlobalSearchInput', el => document.activeElement === el);
    const toastNoticeText = await page.$eval('#posNoticeAlertContent', el => el.textContent).catch(() => '');
    console.log('   Search input focused:', isInputFocused, '| Toast notice:', `"${toastNoticeText}"`);
    await page.screenshot({ path: `${artifactDir}/search_barcode_click.png` });

    // 8. Test "x" Clear button and Escape key
    console.log('8. Testing "x" clear button and Escape key...');
    await page.fill('#posGlobalSearchInput', 'tire');
    await page.waitForTimeout(300);
    const clearBtnDisplay = await page.$eval('#btnPosSearchClear', el => window.getComputedStyle(el).display);
    console.log('   "x" clear button visible:', clearBtnDisplay !== 'none');
    await page.click('#btnPosSearchClear');
    await page.waitForTimeout(300);
    const inputAfterX = await page.$eval('#posGlobalSearchInput', el => el.value);
    console.log('   Input after clicking "x":', `"${inputAfterX}"`);

    await page.fill('#posGlobalSearchInput', 'polish');
    await page.waitForTimeout(300);
    await page.press('#posGlobalSearchInput', 'Escape');
    await page.waitForTimeout(300);
    const inputAfterEsc = await page.$eval('#posGlobalSearchInput', el => el.value);
    console.log('   Input after pressing Escape:', `"${inputAfterEsc}"`);

    await browser.close();
    console.log('All search bar tests completed successfully!');
})();
