const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true, ignoreDefaultArgs: ['--hide-scrollbars'] });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.setContent(`
        <style>
            .scroll-box {
                width: 150px;
                height: 150px;
                overflow-y: auto;
                border: 1px solid #ccc;
                padding: 10px;
            }
            .scroll-box::-webkit-scrollbar {
                width: 5px;
                display: block;
            }
            .scroll-box::-webkit-scrollbar-track {
                background: transparent;
                border-radius: 9999px;
                margin: 4px 0;
                transition: background-color 0.3s ease;
            }
            .scroll-box::-webkit-scrollbar-thumb {
                background: transparent;
                border-radius: 9999px;
                transition: background-color 0.3s ease;
            }
            .scroll-box.is-scrolling::-webkit-scrollbar-track {
                background: rgba(0, 0, 0, 0.04);
            }
            .scroll-box.is-scrolling::-webkit-scrollbar-thumb {
                background: #cbd5e1;
            }
            .scroll-box::-webkit-scrollbar-thumb:hover {
                background: #94a3b8;
            }
        </style>
        <div style="display:flex; gap: 50px; padding: 20px;">
            <div id="box-idle" class="scroll-box">
                <div style="height: 400px;">Idle Box (transparent scrollbar)</div>
            </div>
            <div id="box-scrolling" class="scroll-box is-scrolling">
                <div style="height: 400px;">Scrolling Box (visible 5px light-gray scrollbar)</div>
            </div>
        </div>
    `);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/autohide_test_box.png' });
    console.log('Saved autohide_test_box.png');
    await browser.close();
})();
