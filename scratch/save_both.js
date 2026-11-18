const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 900, height: 600 } });
    
    await page.setContent(`
        <html>
        <body style="background: #111; color: white; padding: 20px;">
            <h2>Reference Image Topbar:</h2>
            <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png" style="width: 415px; border: 1px solid white;" />
            <h2>User Image Topbar:</h2>
            <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png" style="width: 373px; border: 1px solid white;" />
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/both_topbars.png', fullPage: true });
    await browser.close();
    console.log('Saved both_topbars.png');
})();
