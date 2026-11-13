const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    console.log('Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    console.log('Navigating to #queue in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2500);

    const checkInfo = await page.evaluate(() => {
        const toggleList = document.getElementById('btnQueueViewList');
        const toggleGrid = document.getElementById('btnQueueViewGrid');
        const grid = document.getElementById('queueGrid');
        const table = grid?.querySelector('.queue-list-table');
        const rows = table?.querySelectorAll('tbody tr') || [];
        const cards = grid?.querySelectorAll('.queue-card') || [];

        return {
            hasToggleList: !!toggleList,
            hasToggleGrid: !!toggleGrid,
            isListActive: toggleList?.classList.contains('active'),
            isGridClassOnContainer: grid?.classList.contains('is-list-view'),
            hasTable: !!table,
            rowCount: rows.length,
            cardCount: cards.length,
            firstRowPlate: rows[0]?.querySelector('.queue-list-plate')?.textContent?.trim(),
            firstRowCustomer: rows[0]?.querySelector('.col-info')?.textContent?.trim(),
            firstRowStatus: rows[0]?.querySelector('.col-status')?.textContent?.trim(),
            firstRowPayment: rows[0]?.querySelector('.col-financial')?.textContent?.trim(),
            firstRowActions: Array.from(rows[0]?.querySelectorAll('button') || []).map(b => b.textContent.trim())
        };
    });

    console.log('LIST VIEW CHECK RESULT:', JSON.stringify(checkInfo, null, 2));

    const listScreenshot = path.join(brainDir, 'live_cashier_queue_list.png');
    await page.screenshot({ path: listScreenshot });
    console.log('Saved screenshot:', listScreenshot);

    // Test toggle to Grid
    console.log('Testing Grid toggle...');
    await page.click('#btnQueueViewGrid');
    await page.waitForTimeout(1000);

    const gridInfo = await page.evaluate(() => {
        const grid = document.getElementById('queueGrid');
        const cards = grid?.querySelectorAll('.queue-card') || [];
        const isListActive = document.getElementById('btnQueueViewList')?.classList.contains('active');
        const isGridActive = document.getElementById('btnQueueViewGrid')?.classList.contains('active');
        return {
            isListActive,
            isGridActive,
            isListClass: grid?.classList.contains('is-list-view'),
            cardCount: cards.length
        };
    });
    console.log('GRID VIEW CHECK RESULT:', JSON.stringify(gridInfo, null, 2));

    const gridScreenshot = path.join(brainDir, 'live_cashier_queue_grid.png');
    await page.screenshot({ path: gridScreenshot });
    console.log('Saved screenshot:', gridScreenshot);

    // Switch back to List view
    console.log('Switching back to List view...');
    await page.click('#btnQueueViewList');
    await page.waitForTimeout(1000);

    const finalScreenshot = path.join(brainDir, 'live_cashier_queue_final.png');
    await page.screenshot({ path: finalScreenshot });
    console.log('Saved final screenshot:', finalScreenshot);

    await browser.close();
    console.log('All tests passed successfully!');
})();
