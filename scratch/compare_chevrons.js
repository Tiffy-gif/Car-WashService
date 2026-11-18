const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const img1 = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png').toString('base64');
    const img2 = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png').toString('base64');

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1000, height: 700 } });
    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { background: #1e293b; color: #fff; font-family: system-ui, sans-serif; padding: 20px; }
                .card { background: #0f172a; padding: 16px; border-radius: 8px; margin-bottom: 20px; }
                .crop-box {
                    width: 480px;
                    height: 60px;
                    overflow: hidden;
                    border: 2px solid #38bdf8;
                    position: relative;
                    background: #fff;
                    border-radius: 6px;
                }
                .crop-box img {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 480px;
                }
                .zoom-box {
                    margin-top: 10px;
                    width: 250px;
                    height: 60px;
                    overflow: hidden;
                    border: 2px solid #a855f7;
                    position: relative;
                    background: #fff;
                    border-radius: 6px;
                }
                .zoom-box img {
                    position: absolute;
                    top: -5px;
                    left: -270px;
                    width: 600px;
                }
            </style>
        </head>
        <body>
            <div class="card">
                <h3 style="color: #4ade80; margin-top: 0;">Goal (media_1794882276028_fb9d6fdc.png - User Reference)</h3>
                <div class="crop-box">
                    <img src="data:image/png;base64,${img1}">
                </div>
                <div class="zoom-box">
                    <img src="data:image/png;base64,${img1}">
                </div>
            </div>

            <div class="card">
                <h3 style="color: #f87171; margin-top: 0;">Current (media_1794885459298_e0b87505.png - Red Box on Chevron)</h3>
                <div class="crop-box">
                    <img src="data:image/png;base64,${img2}">
                </div>
                <div class="zoom-box">
                    <img src="data:image/png;base64,${img2}">
                </div>
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(500);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/crop_compare.png' });
    await browser.close();
    console.log('Successfully saved crop_compare.png');
})();
