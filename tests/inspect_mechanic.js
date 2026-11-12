const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    page.on('console', msg => console.log('BROWSER:', msg.type(), msg.text()));

    console.log('Logging in as mechanic dona...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'dona');
    await page.fill('#pin', '123456');
    await Promise.all([
        page.waitForNavigation(),
        page.click('button[type="submit"]')
    ]);

    console.log('Post login URL:', page.url());
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(2000);

    const info = await page.evaluate(() => {
        return {
            url: window.location.href,
            activeView: document.querySelector('.view-section.active')?.id,
            handoverHtml: document.getElementById('techHandoverList')?.innerHTML?.trim()?.slice(0, 300),
            jobsHtml: document.getElementById('mechanicJobsGrid')?.innerHTML?.trim()?.slice(0, 300)
        };
    });
    console.log('Mechanic state:', info);

    await page.screenshot({ path: path.join(brainDir, 'current_mechanic_screen.png') });
    await browser.close();
})();

