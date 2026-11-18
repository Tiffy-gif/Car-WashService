const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 900, height: 400 } });
    
    await page.setContent(`
        <html>
        <body style="background: white; padding: 20px; font-family: sans-serif;">
            <div style="display: flex; gap: 40px;">
                <div>
                    <h3>Reference (media_1794882276028_fb9d6fdc.png)</h3>
                    <div style="width: 320px; height: 120px; overflow: hidden; position: relative; border: 2px solid green;">
                        <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png" 
                             style="position: absolute; top: -5px; left: -240px; width: 500px;" />
                    </div>
                </div>
                <div>
                    <h3>User Issue (media_1794885459298_e0b87505.png)</h3>
                    <div style="width: 320px; height: 120px; overflow: hidden; position: relative; border: 2px solid red;">
                        <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png" 
                             style="position: absolute; top: -5px; left: -210px; width: 450px;" />
                    </div>
                </div>
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pixel_zoom_compare.png' });
    await browser.close();
    console.log('Saved pixel_zoom_compare.png');
})();
