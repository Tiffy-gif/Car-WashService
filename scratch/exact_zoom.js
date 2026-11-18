const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const img1 = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png').toString('base64');
    const img2 = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png').toString('base64');

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 900, height: 700 } });
    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { background: #0f172a; color: #f8fafc; font-family: system-ui, sans-serif; padding: 30px; }
                .box {
                    width: 380px;
                    height: 90px;
                    overflow: hidden;
                    border: 2px solid #38bdf8;
                    position: relative;
                    background: #fff;
                    margin-bottom: 30px;
                }
                .box img {
                    position: absolute;
                    top: -10px;
                    left: -480px;
                    width: 900px;
                }
                .mag {
                    width: 500px;
                    height: 150px;
                    overflow: hidden;
                    border: 2px solid #f59e0b;
                    position: relative;
                    background: #fff;
                    margin-bottom: 30px;
                }
                .mag img {
                    position: absolute;
                    top: -20px;
                    left: -960px;
                    width: 1800px;
                    image-rendering: pixelated;
                }
            </style>
        </head>
        <body>
            <h2>Goal (media_1794882276028_fb9d6fdc.png)</h2>
            <div class="box">
                <img src="data:image/png;base64,${img1}">
            </div>
            <div class="mag">
                <img src="data:image/png;base64,${img1}">
            </div>

            <h2>Current with Red Box (media_1794885459298_e0b87505.png)</h2>
            <div class="box">
                <img src="data:image/png;base64,${img2}">
            </div>
            <div class="mag">
                <img src="data:image/png;base64,${img2}">
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(500);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/exact_zoom.png' });
    await browser.close();
    console.log('Saved exact_zoom.png');
})();
