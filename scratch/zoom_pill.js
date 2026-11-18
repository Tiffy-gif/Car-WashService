const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 900, height: 300 } });
    
    await page.setContent(`
        <html>
        <body style="background: #ffffff; margin: 0; padding: 20px; font-family: sans-serif;">
            <div style="display: flex; gap: 30px; align-items: flex-start;">
                <div>
                    <h4 style="margin: 0 0 10px 0;">Reference (media_1794882276028_fb9d6fdc.png)</h4>
                    <div style="overflow: hidden; height: 50px; width: 180px; border: 1px solid #ccc; background: white; position: relative;">
                        <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png" 
                             style="position: absolute; top: -10px; left: -245px; width: 415px;" />
                    </div>
                </div>
                <div>
                    <h4 style="margin: 0 0 10px 0;">User Issue (media_1794885459298_e0b87505.png)</h4>
                    <div style="overflow: hidden; height: 50px; width: 180px; border: 1px solid #ccc; background: white; position: relative;">
                        <img src="file:///C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png" 
                             style="position: absolute; top: -10px; left: -215px; width: 373px;" />
                    </div>
                </div>
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(500);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/zoom_user_pill.png' });
    await browser.close();
    console.log('Saved zoom_user_pill.png');
})();
