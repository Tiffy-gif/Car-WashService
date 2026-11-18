const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true, ignoreDefaultArgs: ['--hide-scrollbars'] });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.setContent(`
        <style>
            #box1::-webkit-scrollbar { width: 5px; }
            #box1::-webkit-scrollbar-thumb { background: red; border-radius: 9999px; }
            #box1::-webkit-scrollbar-track { background: transparent; }

            #box2 { scrollbar-width: thin; scrollbar-color: red transparent; }
            #box2::-webkit-scrollbar { width: 5px; }
            #box2::-webkit-scrollbar-thumb { background: red; border-radius: 9999px; }
            #box2::-webkit-scrollbar-track { background: transparent; }
        </style>
        <div style="display:flex; gap: 50px; padding: 20px;">
            <div id="box1" style="width: 100px; height: 100px; overflow-y: scroll; border: 1px solid black;">
                <div style="height: 300px;">Box 1 (webkit only)</div>
            </div>
            <div id="box2" style="width: 100px; height: 100px; overflow-y: scroll; border: 1px solid black;">
                <div style="height: 300px;">Box 2 (scrollbar-color + webkit)</div>
            </div>
        </div>
    `);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/webkit_vs_standard.png' });
    console.log('Saved webkit_vs_standard.png');
    await browser.close();
})();
