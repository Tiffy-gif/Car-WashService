const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    page.on('console', msg => {
        const text = msg.text();
        if (!text.includes('404')) {
            console.log(`[PAGE ${msg.type()}]:`, text);
        }
    });

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForNavigation();
    await page.waitForTimeout(1000);

    console.log('2. Navigating to Appointments screen...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(2000);

    // Light Mode Full Screenshot
    const shotLight = path.join(brainDir, 'appointments_modern_light.png');
    await page.screenshot({ path: shotLight });
    console.log('Saved appointments_modern_light.png:', shotLight);

    // 3. Test English View
    console.log('3. Testing English View...');
    await page.evaluate(() => {
        I18nManager.setLang('en');
        PosApp.renderAppointmentsScreen();
    });
    await page.waitForTimeout(1000);

    const shotEnglish = path.join(brainDir, 'appointments_modern_english.png');
    await page.screenshot({ path: shotEnglish });
    console.log('Saved appointments_modern_english.png:', shotEnglish);

    // 4. Test Search Filter
    console.log('4. Testing Live Search Filter...');
    const searchInput = page.locator('#apptSearchFilterInput');
    await searchInput.fill('Dara');
    await page.waitForTimeout(500);

    const shotSearch = path.join(brainDir, 'appointments_modern_search.png');
    await page.screenshot({ path: shotSearch });
    console.log('Saved appointments_modern_search.png:', shotSearch);

    // Clear search
    await page.click('#btnClearApptSearch');
    await page.waitForTimeout(500);

    // 5. Test Dark Mode
    console.log('5. Testing Dark Mode...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
        I18nManager.setLang('km'); // back to Khmer
        PosApp.renderAppointmentsScreen();
    });
    await page.waitForTimeout(1000);

    const shotDark = path.join(brainDir, 'appointments_modern_dark.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved appointments_modern_dark.png:', shotDark);

    // Reset back to light theme
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        document.body.setAttribute('data-theme', 'light');
        localStorage.setItem('pos_theme', 'light');
    });

    console.log('All verification screenshots captured successfully!');
    await browser.close();
})();

