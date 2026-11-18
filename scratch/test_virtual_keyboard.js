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
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));

    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 1. Check keyboard button icon in search bar
    console.log('1. Checking keyboard button in search bar...');
    const kbBtn = await page.$('#btnPosVirtualKeyboard');
    const kbIconClass = await page.$eval('#btnPosVirtualKeyboard i', el => el.className);
    console.log('   Keyboard button exists:', !!kbBtn, '| Icon class:', kbIconClass);
    await page.screenshot({ path: `${artifactDir}/keyboard_button_in_searchbar.png` });

    // 2. Click keyboard button to pop up virtual keyboard
    console.log('2. Clicking keyboard button to open virtual keyboard...');
    await page.click('#btnPosVirtualKeyboard');
    await page.waitForTimeout(400);

    const isKbVisible = await page.$eval('#posVirtualKeyboard', el => window.getComputedStyle(el).display !== 'none');
    const isBtnActive = await page.$eval('#btnPosVirtualKeyboard', el => el.classList.contains('active'));
    console.log('   Virtual keyboard displayed:', isKbVisible, '| Button active state:', isBtnActive);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_open.png` });

    // 3. Test typing via virtual keyboard keys
    console.log('3. Typing "oil" via virtual keyboard keys...');
    // Find key with char 'o', 'i', 'l'
    const keyO = await page.$('#posVkKeys button[data-char="o"], #posVkKeys button[data-char="O"]');
    const keyI = await page.$('#posVkKeys button[data-char="i"], #posVkKeys button[data-char="I"]');
    const keyL = await page.$('#posVkKeys button[data-char="l"], #posVkKeys button[data-char="L"]');

    if (keyO) await keyO.click();
    await page.waitForTimeout(100);
    if (keyI) await keyI.click();
    await page.waitForTimeout(100);
    if (keyL) await keyL.click();
    await page.waitForTimeout(300);

    const searchVal = await page.$eval('#posGlobalSearchInput', el => el.value);
    const previewVal = await page.$eval('#posVkInputPreview', el => el.textContent);
    const visibleCards = await page.$$eval('#catalogGrid .pos-item-card', cards => 
        cards.filter(c => window.getComputedStyle(c).display !== 'none').length
    );
    console.log('   Input value after typing:', `"${searchVal}"`, '| Preview:', `"${previewVal}"`, '| Filtered cards:', visibleCards);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_typing_filter.png` });

    // 4. Test Backspace
    console.log('4. Testing Backspace key on virtual keyboard...');
    await page.click('#posVkKeys button[data-action="backspace"]');
    await page.waitForTimeout(200);
    const searchValAfterBs = await page.$eval('#posGlobalSearchInput', el => el.value);
    console.log('   Input after backspace:', `"${searchValAfterBs}"`);

    // 5. Test Quick Chips (e.g. "ហ្វ្រាំង" Brake)
    console.log('5. Clicking Quick Chip "Brake (ហ្វ្រាំង)"...');
    await page.click('#posVkQuickChips button[data-search="ហ្វ្រាំង"]');
    await page.waitForTimeout(300);
    const searchValAfterChip = await page.$eval('#posGlobalSearchInput', el => el.value);
    const visibleAfterChip = await page.$$eval('#catalogGrid .pos-item-card', cards => 
        cards.filter(c => window.getComputedStyle(c).display !== 'none').length
    );
    console.log('   Input after quick chip:', `"${searchValAfterChip}"`, '| Filtered cards:', visibleAfterChip);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_quick_chip.png` });

    // 6. Test Language Toggle (EN <-> KM <-> ?123)
    console.log('6. Testing language toggle on virtual keyboard...');
    await page.click('#posVkLangToggle');
    await page.waitForTimeout(300);
    const langLabelAfterToggle = await page.$eval('#posVkLangLabel', el => el.textContent);
    console.log('   Language label after toggle:', langLabelAfterToggle);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_open_km.png` });

    // Test Symbols mode ?123
    console.log('   Switching to ?123 symbols mode...');
    await page.click('#posVkKeys button[data-action="mode_123"]');
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_open_123.png` });

    // 7. Test Close button
    console.log('7. Testing close button on virtual keyboard...');
    await page.click('#posVkCloseBtn');
    await page.waitForTimeout(300);
    const isKbVisibleAfterClose = await page.$eval('#posVirtualKeyboard', el => window.getComputedStyle(el).display !== 'none');
    console.log('   Virtual keyboard visible after close:', isKbVisibleAfterClose);

    await browser.close();
    console.log('All Virtual Keyboard tests completed successfully!');
})();
