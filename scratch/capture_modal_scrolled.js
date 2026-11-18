const { chromium } = require('playwright');
const path = require('path');

async function captureModalScrolled() {
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

    // Scroll modal body down to show live preview
    await page.evaluate(() => {
        const body = document.querySelector('#modalUpsellRule .modal-body');
        if (body) {
            body.scrollTop = body.scrollHeight;
        }
    });
    await page.waitForTimeout(300);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_modal_scrolled_preview.png'),
        fullPage: false
    });

    await browser.close();
    console.log("Captured modal scrolled preview!");
}

captureModalScrolled();
