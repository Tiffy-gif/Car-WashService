const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 700, height: 250 } });
    
    // Zoom in on user pill of reference image: media_1794882276028_fb9d6fdc.png
    await page.setContent(`
        <html>
        <body style="background: white; margin: 0; padding: 20px;">
            <div style="overflow: hidden; width: 350px; height: 100px; border: 2px solid red; position: relative;">
                <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png" 
                     style="position: absolute; top: -5px; left: -240px; width: 500px;" />
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/zoom_ref_pill.png' });
    await browser.close();
    console.log('Saved zoom_ref_pill.png');
})();
