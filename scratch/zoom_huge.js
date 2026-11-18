const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const img1 = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png').toString('base64');
    const img2 = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png').toString('base64');

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 800, height: 600 } });
    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { background: #fff; padding: 20px; font-family: system-ui, sans-serif; }
                .zoom-huge {
                    width: 320px;
                    height: 120px;
                    overflow: hidden;
                    border: 2px solid #000;
                    position: relative;
                    margin-bottom: 20px;
                }
                .zoom-huge img {
                    position: absolute;
                    top: -40px;
                    left: -900px;
                    width: 1400px;
                    image-rendering: pixelated;
                }
            </style>
        </head>
        <body>
            <h3>Goal (Zoom 5x - Chevron)</h3>
            <div class="zoom-huge">
                <img src="data:image/png;base64,${img1}">
            </div>

            <h3>Current (Zoom 5x - Chevron)</h3>
            <div class="zoom-huge">
                <img src="data:image/png;base64,${img2}">
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/zoom_huge_compare.png' });
    await browser.close();
    console.log('Saved zoom_huge_compare.png');
})();
