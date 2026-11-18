const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();
    await page.setContent('<div class="wrap"><div style="height:2000px">Content</div></div>');
    await page.addStyleTag({
        content: `
            .wrap { width: 200px; height: 200px; overflow-y: auto; border: 1px solid black; }
            .wrap::-webkit-scrollbar { width: 12px; }
            .wrap::-webkit-scrollbar-thumb { background: red; }
        `
    });
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pure_webkit_test.png' });
    console.log('Saved pure_webkit_test.png');
    await browser.close();
})();
