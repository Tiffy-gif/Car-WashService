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

    // 1. Open Virtual Keyboard
    console.log('1. Opening virtual keyboard...');
    await page.click('#btnPosVirtualKeyboard');
    await page.waitForTimeout(400);

    const initialPos = await page.$eval('#posVirtualKeyboard', el => {
        const r = el.getBoundingClientRect();
        return { left: Math.round(r.left), top: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height) };
    });
    console.log('   Initial keyboard position:', initialPos);

    // 2. Drag keyboard to the right and up
    console.log('2. Dragging keyboard up and to the right...');
    const headerBox = await page.$eval('#posVkDragHeader', el => {
        const r = el.getBoundingClientRect();
        return { x: r.left + 50, y: r.top + 15 };
    });

    await page.mouse.move(headerBox.x, headerBox.y);
    await page.mouse.down();
    // Drag upwards by 200px and right by 100px
    await page.mouse.move(headerBox.x + 100, headerBox.y - 200, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(300);

    const movedPos = await page.$eval('#posVirtualKeyboard', el => {
        const r = el.getBoundingClientRect();
        return { left: Math.round(r.left), top: Math.round(r.top) };
    });
    console.log('   Moved keyboard position:', movedPos);
    const movedDeltaY = initialPos.top - movedPos.top;
    const movedDeltaX = movedPos.left - initialPos.left;
    console.log(`   Position delta: X=+${movedDeltaX}px, Y=-${movedDeltaY}px`);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_dragged.png` });

    // 3. Test typing in the moved position
    console.log('3. Typing "oil" in moved position...');
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
    console.log('   Search input value after typing:', `"${searchVal}"`);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_dragged_typing.png` });

    // 4. Test "Dock" reset button
    console.log('4. Clicking "Dock" button to reset position...');
    await page.click('#posVkResetPosBtn');
    await page.waitForTimeout(300);

    const resetPos = await page.$eval('#posVirtualKeyboard', el => {
        const r = el.getBoundingClientRect();
        return { left: Math.round(r.left), top: Math.round(r.top) };
    });
    console.log('   Reset keyboard position:', resetPos);
    console.log('   Is back near initial top (~620px):', Math.abs(resetPos.top - initialPos.top) < 15);
    await page.screenshot({ path: `${artifactDir}/virtual_keyboard_docked_reset.png` });

    await browser.close();
    console.log('Keyboard drag & move test completed successfully!');
})();
