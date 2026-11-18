const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 500, height: 500 } });
    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
        <style>
            .box1 {
                width: 120px;
                height: 150px;
                overflow-y: scroll;
                margin: 20px;
                border: 1px solid black;
            }
            /* Standard CSS scrollbar-color */
            .box1 {
                scrollbar-width: thin;
                scrollbar-color: red green;
            }

            .box2 {
                width: 120px;
                height: 150px;
                overflow-y: scroll;
                margin: 20px;
                border: 1px solid black;
            }
            /* WebKit scrollbar */
            .box2::-webkit-scrollbar {
                -webkit-appearance: none;
                width: 8px;
            }
            .box2::-webkit-scrollbar-thumb {
                background-color: blue;
                border-radius: 4px;
            }
            .box2::-webkit-scrollbar-track {
                background: yellow;
            }
        </style>
        </head>
        <body>
            <div class="box1">
                ${'<p>Item</p>'.repeat(20)}
            </div>
            <div class="box2">
                ${'<p>Item</p>'.repeat(20)}
            </div>
        </body>
        </html>
    `);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/compare_box1_box2.png' });
    console.log('Saved compare_box1_box2.png');
    await browser.close();
})();
