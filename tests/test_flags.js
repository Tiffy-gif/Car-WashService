const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ 
        channel: 'chrome', 
        headless: true,
        ignoreDefaultArgs: ['--hide-scrollbars']
    });
    const page = await browser.newPage({ viewport: { width: 500, height: 500 } });
    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
        <style>
            .box2 {
                width: 150px;
                height: 150px;
                overflow-y: scroll;
                margin: 20px;
                border: 1px solid black;
            }
            .box2::-webkit-scrollbar {
                width: 8px;
            }
            .box2::-webkit-scrollbar-thumb {
                background: blue;
                border-radius: 4px;
            }
            .box2::-webkit-scrollbar-track {
                background: yellow;
            }
        </style>
        </head>
        <body>
            <div class="box2">
                ${'<p>Item</p>'.repeat(20)}
            </div>
        </body>
        </html>
    `);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/flag_test_no_hide.png' });
    console.log('Saved flag_test_no_hide.png');
    await browser.close();
})();
