const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const imgPos = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png').toString('base64');
    const imgQueue = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794886985477_ed8e88e8.jpg').toString('base64');

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 800 } });

    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { background: #0f172a; color: #fff; font-family: system-ui; padding: 20px; }
                .row { display: flex; gap: 20px; }
                .col { flex: 1; }
                img { width: 100%; border-radius: 8px; border: 2px solid #334155; }
                h3 { margin-top: 0; }
            </style>
        </head>
        <body>
            <div class="row">
                <div class="col">
                    <h3 style="color: #38bdf8;">POS Design Reference (media_1794882276028_fb9d6fdc.png)</h3>
                    <img src="data:image/png;base64,${imgPos}">
                </div>
                <div class="col">
                    <h3 style="color: #fb923c;">Current Queue Page (media_1794886985477_ed8e88e8.jpg)</h3>
                    <img src="data:image/jpeg;base64,${imgQueue}">
                </div>
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_vs_queue_compare.png' });
    await browser.close();
    console.log('Saved pos_vs_queue_compare.png');
})();
