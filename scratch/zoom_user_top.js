const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const imgUser = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794886985477_ed8e88e8.jpg').toString('base64');

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1000, height: 600 } });

    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <body style="background: #111; padding: 20px;">
            <div style="border: 2px solid cyan; width: 600px; height: 180px; overflow: hidden; position: relative;">
                <img src="data:image/jpeg;base64,${imgUser}" style="position: absolute; top: 0px; left: 0px; width: 1000px;">
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/zoom_user_top.png' });
    await browser.close();
    console.log('Saved zoom_user_top.png');
})();
