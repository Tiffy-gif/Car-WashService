const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.error('PAGE ERROR:', err.message));

    console.log('Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    console.log('Navigating to #queue in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2500);

    // 1. Check Tabs & Waiting Tab Content
    const tabInfo = await page.evaluate(() => {
        const countWaiting = document.getElementById('tabCountWaiting')?.textContent;
        const countReady = document.getElementById('tabCountReady')?.textContent;
        const countAll = document.getElementById('tabCountAll')?.textContent;
        const rows = Array.from(document.querySelectorAll('.queue-list-table tbody tr'));
        const rowPlates = rows.map(r => ({
            plate: r.querySelector('.queue-list-plate')?.textContent?.trim(),
            status: r.querySelector('.col-status')?.textContent?.trim(),
            actions: Array.from(r.querySelectorAll('button')).map(b => b.textContent.trim())
        }));

        return {
            countWaiting,
            countReady,
            countAll,
            activeTab: document.querySelector('.btn-queue-tab.active')?.getAttribute('data-tab'),
            rowCount: rows.length,
            rowPlates
        };
    });

    console.log('WAITING TAB INFO:', JSON.stringify(tabInfo, null, 2));

    const waitingScreenshot = path.join(brainDir, 'live_cashier_tab_waiting.png');
    await page.screenshot({ path: waitingScreenshot });
    console.log('Saved waiting screenshot:', waitingScreenshot);

    // 2. Click "Ready to Pick Up" Tab
    console.log('Clicking Ready to Pick Up Tab...');
    await page.click('#tabQueueReady');
    await page.waitForTimeout(1000);

    const readyInfo = await page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('.queue-list-table tbody tr'));
        const rowDetails = rows.map(r => ({
            plate: r.querySelector('.queue-list-plate')?.textContent?.trim(),
            status: r.querySelector('.col-status')?.textContent?.trim(),
            hasHandoverBtn: !!r.querySelector('button[data-action="complete-pickup"]'),
            actions: Array.from(r.querySelectorAll('button')).map(b => b.textContent.trim())
        }));

        return {
            activeTab: document.querySelector('.btn-queue-tab.active')?.getAttribute('data-tab'),
            rowCount: rows.length,
            rowDetails
        };
    });

    console.log('READY TAB INFO:', JSON.stringify(readyInfo, null, 2));

    const readyScreenshot = path.join(brainDir, 'live_cashier_tab_ready.png');
    await page.screenshot({ path: readyScreenshot });
    console.log('Saved ready screenshot:', readyScreenshot);

    // 3. Click "All" Tab
    console.log('Clicking All Jobs Tab...');
    await page.click('#tabQueueAll');
    await page.waitForTimeout(1000);

    const allInfo = await page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('.queue-list-table tbody tr'));
        return {
            activeTab: document.querySelector('.btn-queue-tab.active')?.getAttribute('data-tab'),
            rowCount: rows.length
        };
    });
    console.log('ALL TAB INFO:', JSON.stringify(allInfo, null, 2));

    const allScreenshot = path.join(brainDir, 'live_cashier_tab_all.png');
    await page.screenshot({ path: allScreenshot });
    console.log('Saved all screenshot:', allScreenshot);

    // 4. Switch back to Waiting Tab for default cashier experience
    console.log('Switching back to Waiting Tab...');
    await page.click('#tabQueueWaiting');
    await page.waitForTimeout(600);

    await browser.close();
    console.log('Test completed successfully!');
})();

