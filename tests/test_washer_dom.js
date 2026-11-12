const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    page.on('console', msg => console.log('BROWSER CONSOLE:', msg.type(), msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.toString()));
    page.on('response', async res => {
        if (res.status() >= 400 && res.url().includes('api/pos/index.php')) {
            const req = res.request();
            console.log(`HTTP ${res.status()}: ${req.method()} ${res.url()} -> postData: ${req.postData()}`);
            try {
                const body = await res.text();
                console.log(`Response body: ${body}`);
            } catch (e) {}
        }
    });

    console.log('Logging in as washer dom...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'dom');
    await page.fill('#pin', '123456');
    await Promise.all([
        page.waitForNavigation(),
        page.click('button[type="submit"]')
    ]);
    console.log('Post login URL:', page.url());

    console.log('Navigating to pos/index.html...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html');
    await page.waitForTimeout(2000);

    const info = await page.evaluate(() => {
        return {
            url: window.location.href,
            hash: window.location.hash,
            activeView: document.querySelector('.view-section.active')?.id,
            washerNavDisplay: document.getElementById('washerNav')?.style.display,
            mainNavDisplay: document.getElementById('mainNav')?.style.display,
            user: localStorage.getItem('cw_pos_staff_user'),
            baysCount: document.querySelectorAll('.bay-article').length,
            queueCount: document.querySelectorAll('.queue-item-card').length,
            baysHtml: document.getElementById('washerBaysGrid')?.innerHTML?.slice(0, 300)
        };
    });
    console.log('Washer dom info:', JSON.stringify(info, null, 2));

    await page.screenshot({ path: path.join(brainDir, 'washer_dom_view.png') });

    // Switch to English and screenshot
    await page.evaluate(() => {
        I18nManager.setLang('en');
        PosApp.renderWasherScreen();
    });
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(brainDir, 'washer_dom_en_view.png') });
    console.log('Saved both Khmer and English screenshots');

    await browser.close();
})();
