const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 500, height: 200 } });
    
    // Zoom in 3x on the user pill area of media_1794885459298_e0b87505.png
    await page.setContent(`
        <html>
        <body style="background: white; margin: 0; padding: 20px;">
            <div style="overflow: hidden; width: 300px; height: 100px; border: 2px solid blue; position: relative;">
                <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png" 
                     style="position: absolute; top: -10px; left: -210px; width: 450px;" />
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/zoom_user_issue.png' });
    await browser.close();
    console.log('Saved zoom_user_issue.png');
})();
