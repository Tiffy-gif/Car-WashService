const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html');

    const rules = await page.evaluate(() => {
        const el = document.getElementById('userMenuEndShift');
        const matched = [];
        for (const sheet of document.styleSheets) {
            try {
                for (const rule of sheet.cssRules) {
                    if (rule.selectorText && el.matches(rule.selectorText)) {
                        matched.push({ selector: rule.selectorText, cssText: rule.cssText });
                    }
                }
            } catch (e) {}
        }
        return matched;
    });

    console.log('Matched CSS rules for userMenuEndShift:', JSON.stringify(rules, null, 2));
    await browser.close();
})();
