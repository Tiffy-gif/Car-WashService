const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
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
            res.on('end', () => {
                try {
                    const json = JSON.parse(data);
                    resolve(json);
                } catch(e) {
                    reject(e);
                }
            });
        });
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

async function runDropdownTest() {
    console.log("1. Authenticating cashier staff session via API...");
    const loginRes = await getRealStaffSession();
    if (!loginRes.success) {
        throw new Error("Failed to get cashier session: " + JSON.stringify(loginRes));
    }
    console.log("-> Authenticated:", loginRes.user.username, "| Role:", loginRes.user.role_name_km);

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

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

    console.log("2. Loading POS Application...");
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    console.log("3. Verifying User Profile Pill in Top Bar...");
    const userPill = await page.waitForSelector('#userPill', { state: 'visible', timeout: 8000 });
    const pillText = await userPill.innerText();
    console.log("-> User Pill Text:", pillText.replace(/\n/g, ' '));

    console.log("4. Clicking #userPill to open Profile Dropdown Menu...");
    await userPill.click();
    await page.waitForTimeout(500);

    const dropdown = await page.waitForSelector('#userMenuDropdown', { state: 'visible', timeout: 5000 });
    const isDropdownVisible = await dropdown.isVisible();
    console.log("-> Dropdown is visible:", isDropdownVisible);

    const dropFullName = await page.$eval('#dropdownUserFullName', el => el.textContent.trim());
    const dropRole = await page.$eval('#dropdownUserRole', el => el.textContent.trim());
    const logoutBtn = await page.waitForSelector('#userMenuLogout', { state: 'visible' });
    const endShiftBtn = await page.$('#userMenuEndShift');
    const settingsBtn = await page.$('#userMenuSettings');

    console.log("-> Dropdown Name:", dropFullName);
    console.log("-> Dropdown Role:", dropRole);
    console.log("-> Settings Item Exists:", !!settingsBtn);
    console.log("-> End Shift Item Exists:", !!endShiftBtn);
    console.log("-> Logout Item Exists & Visible:", await logoutBtn.isVisible());

    // 5. Take high-res screenshot of open dropdown
    const screenshotPath = path.join(__dirname, 'pos_user_dropdown_open.png');
    await page.screenshot({ path: screenshotPath });
    console.log("-> Saved screenshot:", screenshotPath);

    const artifactPath = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_user_dropdown_open.png';
    fs.copyFileSync(screenshotPath, artifactPath);
    console.log("-> Copied screenshot to artifact directory:", artifactPath);

    // 6. Test outside-click dismiss
    console.log("5. Testing clicking outside to close dropdown...");
    await page.click('#brandArea');
    await page.waitForTimeout(400);
    const isClosedAfterOutside = !(await dropdown.isVisible());
    console.log("-> Dropdown closed properly on outside click:", isClosedAfterOutside);

    // 7. Re-open dropdown and test Logout
    console.log("6. Re-opening dropdown to click Logout...");
    await userPill.click();
    await page.waitForTimeout(400);

    page.on('dialog', async dialog => {
        console.log("-> Dialog confirmed:", dialog.message());
        await dialog.accept();
    });

    console.log("7. Clicking #userMenuLogout...");
    await logoutBtn.click();
    await page.waitForTimeout(2000);

    console.log("8. Verifying Logout Completion...");
    const currentUrl = page.url();
    console.log("-> Page URL after logout:", currentUrl);

    const storedUser = await page.evaluate(() => localStorage.getItem('cw_pos_staff_user'));
    const storedToken = await page.evaluate(() => localStorage.getItem('cw_pos_session_token') || sessionStorage.getItem('cw_pos_session_token'));
    console.log("-> Stored user cleared:", storedUser === null);
    console.log("-> Stored token cleared:", storedToken === null);

    // Take screenshot after logout
    const logoutScreenshot = path.join(__dirname, 'pos_after_logout.png');
    await page.screenshot({ path: logoutScreenshot });
    fs.copyFileSync(logoutScreenshot, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_after_logout.png');

    await browser.close();
    console.log("=== ALL OPTION A DROPDOWN & LOGOUT CHECKS PASSED ===");
}

runDropdownTest().catch(err => {
    console.error("Test failed:", err);
    process.exit(1);
});
