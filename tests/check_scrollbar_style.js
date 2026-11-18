const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.waitForTimeout(1000);

    const info = await page.evaluate(() => {
        const grid = document.querySelector('.catalog-grid-wrap');
        grid.classList.add('is-scrolling');
        const style = window.getComputedStyle(grid);
        return {
            scrollbarWidth: style.scrollbarWidth,
            scrollbarColor: style.scrollbarColor,
            direction: style.direction,
            classList: Array.from(grid.classList)
        };
    });
    console.log('Scrollbar info:', info);
    await browser.close();
})();
