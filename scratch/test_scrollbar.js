const { chromium } = require('playwright');
const path = require('path');

async function testTransparentScrollbar() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=upsell');
    await page.waitForTimeout(500);

    // Open Edit modal
    await page.click('table tbody tr:first-child button[title="Edit Rule"]');
    await page.waitForTimeout(500);

    // Verify computed styles on scrollbar
    const scrollbarStyles = await page.evaluate(() => {
        const modalBody = document.querySelector('#modalUpsellRule .modal-body');
        const computed = window.getComputedStyle(modalBody);
        return {
            scrollbarWidth: computed.scrollbarWidth,
            scrollbarColor: computed.scrollbarColor,
            hasVerticalScroll: modalBody.scrollHeight > modalBody.clientHeight,
            scrollHeight: modalBody.scrollHeight,
            clientHeight: modalBody.clientHeight
        };
    });

    console.log("Modal Scrollbar Properties:", scrollbarStyles);

    // Scroll modal slightly to make thumb visible
    await page.evaluate(() => {
        const body = document.querySelector('#modalUpsellRule .modal-body');
        if (body) body.scrollTop = 120;
    });
    await page.waitForTimeout(300);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_modal_transparent_scrollbar.png'),
        fullPage: false
    });

    // Also capture dark mode to verify dark transparent scrollbars
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
    });
    await page.waitForTimeout(300);
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_modal_transparent_scrollbar_dark.png'),
        fullPage: false
    });

    await browser.close();
    console.log("Screenshots captured successfully!");
}

testTransparentScrollbar().catch(err => {
    console.error(err);
    process.exit(1);
});
