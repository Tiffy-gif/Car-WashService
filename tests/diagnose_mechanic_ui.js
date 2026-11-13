const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'dona');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(2000);

    const diagnostics = await page.evaluate(() => {
        const sec = document.getElementById('viewMechanic');
        const container = document.querySelector('.mechanic-screen-container');
        const grid = document.querySelector('.mechanic-workspace-grid');
        const liftCol = document.getElementById('mechanicLiftColumn');
        const rightCol = document.getElementById('mechanicRightColumn');
        const liftCard = document.querySelector('.lift-hero-card');
        const headline = document.querySelector('.section-headline');
        const countBadge = document.querySelector('.section-count-badge');
        const activePane = document.querySelector('.tab-pane[style*="display: flex"]') || document.querySelector('.tab-pane');

        const getBox = el => el ? {
            w: el.offsetWidth,
            h: el.offsetHeight,
            scrollH: el.scrollHeight,
            clientH: el.clientHeight,
            overflowY: window.getComputedStyle(el).overflowY,
            display: window.getComputedStyle(el).display,
            flexDirection: window.getComputedStyle(el).flexDirection
        } : null;

        return {
            window: { w: window.innerWidth, h: window.innerHeight },
            headline: headline ? {
                display: window.getComputedStyle(headline).display,
                w: headline.offsetWidth,
                h: headline.offsetHeight,
                titleH: headline.querySelector('.section-title')?.offsetHeight,
                badgeH: countBadge?.offsetHeight
            } : null,
            viewMechanic: getBox(sec),
            container: getBox(container),
            grid: getBox(grid),
            liftCol: getBox(liftCol),
            rightCol: getBox(rightCol),
            liftCard: getBox(liftCard),
            activeRightPane: getBox(activePane)
        };
    });

    console.log('DIAGNOSTICS:', JSON.stringify(diagnostics, null, 2));
    await browser.close();
})();

