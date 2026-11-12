const puppeteer = require('puppeteer');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    const page = await browser.newPage();

    // 1. Desktop / Large Tablet: 1440 x 900
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto('http://127.0.0.1/Car-WashService/pos/preview_washer_idea1.html', { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 500));
    await page.screenshot({ path: path.join(brainDir, 'washer_idea1_clean_1440x900.png') });
    console.log('Saved washer_idea1_clean_1440x900.png');

    // 2. Standard Tablet Landscape: 1024 x 768
    await page.setViewport({ width: 1024, height: 768 });
    await page.goto('http://127.0.0.1/Car-WashService/pos/preview_washer_idea1.html', { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 500));
    await page.screenshot({ path: path.join(brainDir, 'washer_idea1_clean_1024x768.png') });
    console.log('Saved washer_idea1_clean_1024x768.png');

    await browser.close();
})();

