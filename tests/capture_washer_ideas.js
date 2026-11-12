const puppeteer = require('puppeteer');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 920 });

    const targets = [
        { url: 'http://127.0.0.1/Car-WashService/pos/preview_washer_idea1.html', file: 'washer_idea1_tactical_command.png' },
        { url: 'http://127.0.0.1/Car-WashService/pos/preview_washer_idea2.html', file: 'washer_idea2_stage_checklist.png' },
        { url: 'http://127.0.0.1/Car-WashService/pos/preview_washer_idea3.html', file: 'washer_idea3_rugged_cockpit.png' },
        { url: 'http://127.0.0.1/Car-WashService/pos/preview_washer_showcase.html', file: 'washer_showcase_master.png' }
    ];

    for (const item of targets) {
        console.log(`Loading ${item.url}...`);
        await page.goto(item.url, { waitUntil: 'networkidle2' });
        await new Promise(r => setTimeout(r, 600));
        const outPath = path.join(brainDir, item.file);
        await page.screenshot({ path: outPath });
        console.log(`Saved screenshot to ${outPath}`);
    }

    await browser.close();
    console.log('All screenshots captured successfully!');
})();

