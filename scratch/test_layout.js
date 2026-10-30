const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ headless: 'new' });
    const page = await browser.newPage();
    
    // Login as owner
    await page.goto('http://localhost/Car-WashService/index.php?action=demo_login&role=owner', { waitUntil: 'networkidle2' });
    
    const viewports = [
        { name: 'Tablet 768px', width: 768, height: 1024 },
        { name: 'Tablet 1024px', width: 1024, height: 768 },
        { name: 'HD 1366px', width: 1366, height: 768 },
        { name: 'FHD-laptop 1536px', width: 1536, height: 864 },
        { name: 'FHD 1920px', width: 1920, height: 1080 },
        { name: 'QHD 2560px', width: 2560, height: 1440 }
    ];
    
    const pages = [
        'dashboard', 'orders', 'bays_queue', 'washer_jobs', 'mechanic_jobs', 
        'vehicle_history', 'customers', 'services', 'stock_ledger', 'expenses', 
        'commissions', 'reports', 'staff', 'roles', 'settings'
    ];
    
    console.log('--- Testing Dashboard across viewports ---');
    for (const vp of viewports) {
        await page.setViewport({ width: vp.width, height: vp.height });
        await page.goto('http://localhost/Car-WashService/index.php?view=dashboard', { waitUntil: 'networkidle2' });
        
        const metrics = await page.evaluate(() => {
            const body = document.body;
            const doc = document.documentElement;
            const container = document.querySelector('.page-container');
            const sidebar = document.querySelector('.sidebar');
            const card = document.querySelector('.card');
            const table = document.querySelector('.table') || document.querySelector('.table-clean');
            
            const contRect = container ? container.getBoundingClientRect() : null;
            const sideRect = sidebar ? sidebar.getBoundingClientRect() : null;
            
            const hasHScroll = doc.scrollWidth > doc.clientWidth || body.scrollWidth > body.clientWidth;
            
            // Space from sidebar to content (left edge of container + paddingLeft - sidebar.right)
            let gapAfterMenu = 0;
            if (sideRect && contRect && window.innerWidth > 1024) {
                const padLeft = parseFloat(window.getComputedStyle(container).paddingLeft) || 0;
                gapAfterMenu = Math.round((contRect.left + padLeft) - sideRect.right);
            }
            
            // Space from content to right screen edge
            let spaceToRight = 0;
            if (contRect) {
                const padRight = parseFloat(window.getComputedStyle(container).paddingRight) || 0;
                spaceToRight = Math.round(window.innerWidth - (contRect.right - padRight));
            }
            
            return {
                viewportWidth: window.innerWidth,
                hasHorizontalScroll: hasHScroll,
                scrollWidth: doc.scrollWidth,
                clientWidth: doc.clientWidth,
                containerWidth: contRect ? Math.round(contRect.width) : null,
                containerLeft: contRect ? Math.round(contRect.left) : null,
                gapAfterMenu,
                spaceToRight,
                cardWidth: card ? Math.round(card.getBoundingClientRect().width) : null,
                tableWidth: table ? Math.round(table.getBoundingClientRect().width) : null
            };
        });
        
        console.log(`[${vp.name}] V: ${metrics.viewportWidth}px | H-Scroll: ${metrics.hasHorizontalScroll ? 'YES (OVERFLOW!)' : 'No'} | GapAfterMenu: ${metrics.gapAfterMenu}px | SpaceRight: ${metrics.spaceToRight}px | Container: ${metrics.containerWidth}px | Card: ${metrics.cardWidth}px | Table: ${metrics.tableWidth}px`);
    }
    
    console.log('\n--- Testing Sidebar Collapsed at 1920px ---');
    await page.setViewport({ width: 1920, height: 1080 });
    await page.evaluate(() => {
        document.documentElement.classList.add('sidebar-collapsed');
        const s = document.querySelector('.sidebar');
        if (s) s.classList.add('collapsed');
    });
    const colMetrics = await page.evaluate(() => {
        const container = document.querySelector('.page-container');
        const sidebar = document.querySelector('.sidebar');
        const card = document.querySelector('.card');
        const contRect = container.getBoundingClientRect();
        const sideRect = sidebar.getBoundingClientRect();
        const padLeft = parseFloat(window.getComputedStyle(container).paddingLeft) || 0;
        const padRight = parseFloat(window.getComputedStyle(container).paddingRight) || 0;
        return {
            sidebarWidth: Math.round(sideRect.width),
            gapAfterMenu: Math.round((contRect.left + padLeft) - sideRect.right),
            spaceToRight: Math.round(window.innerWidth - (contRect.right - padRight)),
            containerWidth: Math.round(contRect.width),
            cardWidth: Math.round(card.getBoundingClientRect().width)
        };
    });
    console.log(`Collapsed: SidebarWidth: ${colMetrics.sidebarWidth}px | GapAfterMenu: ${colMetrics.gapAfterMenu}px | SpaceRight: ${colMetrics.spaceToRight}px | Container: ${colMetrics.containerWidth}px | Card: ${colMetrics.cardWidth}px`);
    
    console.log('\n--- Checking all 15 back office pages for horizontal scrolling across viewports ---');
    let totalOverflows = 0;
    for (const p of pages) {
        for (const vp of viewports) {
            await page.setViewport({ width: vp.width, height: vp.height });
            await page.goto(`http://localhost/Car-WashService/index.php?view=${p}`, { waitUntil: 'networkidle2' });
            const res = await page.evaluate(() => {
                const doc = document.documentElement;
                const body = document.body;
                return {
                    scrollW: Math.max(doc.scrollWidth, body.scrollWidth),
                    clientW: Math.min(doc.clientWidth, body.clientWidth),
                    overflow: Math.max(doc.scrollWidth, body.scrollWidth) > Math.min(doc.clientWidth, body.clientWidth)
                };
            });
            if (res.overflow) {
                totalOverflows++;
                console.log(`OVERFLOW on ${p} at ${vp.width}px: scroll=${res.scrollW}, client=${res.clientW}`);
            }
        }
    }
    
    if (totalOverflows === 0) {
        console.log('SUCCESS: No sideways scrolling on any of the 15 pages across all tested screen sizes!');
    } else {
        console.log(`Found ${totalOverflows} overflow issues.`);
    }
    
    // Also check Staff Performance Leaderboard column headers
    await page.setViewport({ width: 1920, height: 1080 });
    await page.goto('http://localhost/Car-WashService/index.php?view=dashboard', { waitUntil: 'networkidle2' });
    const thMetrics = await page.evaluate(() => {
        const ths = Array.from(document.querySelectorAll('.table-clean th'));
        return ths.map(th => ({
            text: th.innerText.trim(),
            width: Math.round(th.getBoundingClientRect().width),
            left: Math.round(th.getBoundingClientRect().left),
            right: Math.round(th.getBoundingClientRect().right)
        }));
    });
    console.log('\n--- Staff Performance Leaderboard Column Headers at 1920px ---');
    console.log(thMetrics);

    await browser.close();
})();
