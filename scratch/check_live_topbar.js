const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.evaluate(() => {
        localStorage.setItem('pos_device_uid', 'POS-CAASIER');
        localStorage.setItem('pos_device_key', 'my_test_device_key_unique9');
        localStorage.setItem('pos_device_status', 'registered');
    });

    await page.reload();
    await page.waitForTimeout(1000);

    // Let's trigger staff login or mock currentUser
    await page.evaluate(() => {
        if (window.PosApp) {
            window.PosApp.currentUser = {
                id: 1,
                username: 'admin',
                full_name: 'Shop Owner',
                role: 'admin',
                role_name_en: 'Shop Owner',
                role_name_km: 'ម្ចាស់ហាង'
            };
            window.PosApp.updateUserHeaderInfo();
        }
    });

    await page.waitForTimeout(500);

    const userPill = await page.$('#userPill');
    if (userPill) {
        await userPill.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/current_live_user_pill.png' });
        
        const styles = await page.evaluate(() => {
            const pill = document.getElementById('userPill');
            const icon = pill.querySelector('.fa-chevron-down');
            const role = pill.querySelector('#userRoleBadge');
            return {
                roleHTML: role ? role.innerHTML : null,
                pillHTML: pill ? pill.innerHTML : null,
                iconComputed: icon ? {
                    fontSize: window.getComputedStyle(icon).fontSize,
                    color: window.getComputedStyle(icon).color,
                    width: window.getComputedStyle(icon).width,
                    height: window.getComputedStyle(icon).height,
                    lineHeight: window.getComputedStyle(icon).lineHeight
                } : null
            };
        });
        console.log('Computed styles:', JSON.stringify(styles, null, 2));
    }

    // Also take screenshot in English
    await page.evaluate(() => {
        if (window.I18nManager) {
            window.I18nManager.setLang('en');
        }
        if (window.PosApp) {
            window.PosApp.updateUserHeaderInfo();
        }
    });
    await page.waitForTimeout(300);
    if (userPill) {
        await userPill.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/current_live_user_pill_en.png' });
    }

    await browser.close();
})();
