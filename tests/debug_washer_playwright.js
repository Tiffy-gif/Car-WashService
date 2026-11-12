const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    const consoleMessages = [];
    const pageErrors = [];

    page.on('console', msg => consoleMessages.push({ type: msg.type(), text: msg.text() }));
    page.on('pageerror', err => pageErrors.push(err.toString()));
    page.on('response', res => {
        if (res.status() >= 400) {
            console.log(`HTTP ${res.status()}: ${res.url()}`);
        }
    });

    console.log('=== Scenario 1: Opening pos/index.html#washer directly (no session) ===');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#washer', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    const s1Info = await page.evaluate(() => {
        return {
            url: window.location.href,
            hash: window.location.hash,
            loginVisible: document.getElementById('viewLogin')?.style.display !== 'none',
            posVisible: document.getElementById('viewPos')?.classList.contains('active'),
            washerVisible: document.getElementById('viewWasher')?.classList.contains('active'),
            activeSection: document.querySelector('.view-section.active')?.id,
            baysInnerHtml: document.getElementById('washerBaysGrid')?.innerHTML?.trim()?.slice(0, 200),
            queueInnerHtml: document.getElementById('washerJobsGrid')?.innerHTML?.trim()?.slice(0, 200)
        };
    });
    console.log('Scenario 1 state:', JSON.stringify(s1Info, null, 2));
    await page.screenshot({ path: path.join(brainDir, 'pw_scenario1_direct.png') });

    console.log('=== Scenario 2: Logging in via Back Office first, then navigating to pos/index.html#washer ===');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login', { waitUntil: 'networkidle' });
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#washer', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);

    const s2Info = await page.evaluate(() => {
        return {
            url: window.location.href,
            hash: window.location.hash,
            currentUser: localStorage.getItem('carwash_pos_user'),
            currentSession: sessionStorage.getItem('cw_pos_session_token') || localStorage.getItem('cw_pos_session_token'),
            activeSection: document.querySelector('.view-section.active')?.id,
            washerActive: document.getElementById('viewWasher')?.classList.contains('active'),
            baysCount: document.querySelectorAll('.bay-article').length,
            legacyBaysCount: document.querySelectorAll('.bay-card').length,
            baysHtml: document.getElementById('washerBaysGrid')?.innerHTML,
            queueCount: document.querySelectorAll('.queue-item-card').length,
            legacyQueueCount: document.querySelectorAll('.queue-card').length,
            queueHtml: document.getElementById('washerJobsGrid')?.innerHTML
        };
    });
    console.log('Scenario 2 state:', JSON.stringify(s2Info, null, 2));
    await page.screenshot({ path: path.join(brainDir, 'pw_scenario2_after_login.png') });

    console.log('Console Errors/Warnings:', consoleMessages.filter(m => m.type === 'error' || m.type === 'warning'));
    console.log('Page Errors:', pageErrors);

    await browser.close();
})();
