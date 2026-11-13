const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(1500);

    // Switch language to English via UI select or button
    console.log('Switching to EN...');
    const langSelect = page.locator('#langSelect');
    if (await langSelect.count() > 0) {
        await langSelect.selectOption('en');
    } else {
        await page.evaluate(() => {
            I18nManager.setLang('en');
            PosApp.onLanguageChanged();
        });
    }
    await page.waitForTimeout(800);

    await page.evaluate(() => {
        const v = document.getElementById('viewAppointments');
        if (v) v.scrollTop = 0;
    });

    const shotEn = path.join(brainDir, 'appointments_english_view.png');
    await page.screenshot({ path: shotEn });
    console.log('Saved English view screenshot:', shotEn);

    // Switch back to Khmer for standard production setting
    console.log('Switching back to KM...');
    if (await langSelect.count() > 0) {
        await langSelect.selectOption('km');
    } else {
        await page.evaluate(() => {
            I18nManager.setLang('km');
            PosApp.onLanguageChanged();
        });
    }
    await page.waitForTimeout(800);

    await browser.close();
})();
