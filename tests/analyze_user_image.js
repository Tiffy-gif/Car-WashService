const { chromium } = require('playwright');
const path = require('path');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();
    const absPath = path.resolve('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794841216130_c06372f2.png');
    const fileUrl = 'file:///' + absPath.replace(/\\/g, '/');

    await page.goto(fileUrl);
    await page.waitForTimeout(500);

    const result = await page.evaluate(() => {
        const img = document.querySelector('img');
        const cvs = document.createElement('canvas');
        cvs.width = img.naturalWidth;
        cvs.height = img.naturalHeight;
        const ctx = cvs.getContext('2d');
        ctx.drawImage(img, 0, 0);

        // Let's inspect the vertical strip on the right side of the category cards
        // Cards are around width ~86px. The total width of the image:
        const w = img.naturalWidth;
        const h = img.naturalHeight;

        // Sample across multiple Y levels (e.g. y = 100, y = 300, y = 500, y = 700)
        const samples = {};
        [150, 300, 450, 600, 750].forEach(y => {
            const pixels = [];
            for (let x = w - 50; x < w; x++) {
                const d = ctx.getImageData(x, y, 1, 1).data;
                pixels.push({
                    x,
                    hex: '#' + [d[0], d[1], d[2]].map(v => v.toString(16).padStart(2, '0')).join('')
                });
            }
            samples['y_' + y] = pixels;
        });

        return {
            dimensions: { width: w, height: h },
            samples
        };
    });

    console.log('Image dimensions:', result.dimensions);
    // Find unique colors in the strip from x = w - 30 to w - 10
    const w = result.dimensions.width;
    console.log('Strip analysis (x between ' + (w - 35) + ' and ' + (w - 10) + '):');
    Object.keys(result.samples).forEach(key => {
        const slice = result.samples[key].filter(p => p.x >= w - 35 && p.x <= w - 10);
        console.log(key, slice.map(s => s.x + ':' + s.hex).join(' '));
    });

    await browser.close();
})();
