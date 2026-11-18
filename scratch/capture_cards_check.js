const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function getStaffSession() {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username: 'visal', pin: '123456' });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': DEVICE_UID,
                'X-Device-Key': DEVICE_KEY,
                'Content-Length': Buffer.byteLength(payload)
            }
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.write(payload);
        req.end();
    });
}

(async () => {
    const loginRes = await getStaffSession();
    console.log('Login success:', loginRes.success);

    const browser = await chromium.launch({ 
        channel: 'chrome', 
        headless: true,
        ignoreDefaultArgs: ['--hide-scrollbars']
    });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
        // also set picture mode preference if any
        localStorage.setItem('pos_catalog_display_mode', 'picture');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Close any modal if open
    await page.evaluate(() => {
        document.querySelectorAll('.modal.open, .pos-modal.open').forEach(m => m.classList.remove('open'));
    });

    // Refresh catalog from back office
    await page.evaluate(async () => {
        if (window.PosApp && typeof PosApp.syncCatalog === 'function') {
            await PosApp.syncCatalog(true);
        }
    });
    await page.waitForTimeout(2500);

    // Click 'All' category if available
    await page.evaluate(() => {
        const allBtn = Array.from(document.querySelectorAll('.cat-btn, [data-category]')).find(el => 
            el.innerText.includes('All') || el.innerText.includes('???????') || el.getAttribute('data-category') === 'all'
        );
        if (allBtn) allBtn.click();
    });
    await page.waitForTimeout(1500);

    // Inspect first 8 cards
    const cardStats = await page.evaluate(() => {
        const cards = Array.from(document.querySelectorAll('.pos-item-card')).slice(0, 8);
        return cards.map((c, idx) => {
            const media = c.querySelector('.item-card-media-box');
            const img = c.querySelector('.item-card-img');
            const fallback = c.querySelector('.item-card-fallback-icon');
            const icon = fallback?.querySelector('i');
            const titleKm = c.querySelector('.item-name-km')?.innerText;
            const titleEn = c.querySelector('.item-name-en')?.innerText;
            const price = c.querySelector('.item-price-usd')?.innerText;
            const btnAdd = c.querySelector('.btn-card-add');

            return {
                idx,
                titleEn,
                titleKm,
                price,
                hasImgTag: !!img,
                imgDisplay: img ? window.getComputedStyle(img).display : null,
                imgSrc: img ? img.src : null,
                imgObjFit: img ? window.getComputedStyle(img).objectFit : null,
                imgNaturalWidth: img ? img.naturalWidth : null,
                imgNaturalHeight: img ? img.naturalHeight : null,
                imgClientWidth: img ? img.clientWidth : null,
                imgClientHeight: img ? img.clientHeight : null,
                mediaWidth: media ? window.getComputedStyle(media).width : null,
                mediaHeight: media ? window.getComputedStyle(media).height : null,
                mediaBorderRadius: media ? window.getComputedStyle(media).borderRadius : null,
                mediaOverflow: media ? window.getComputedStyle(media).overflow : null,
                fallbackDisplay: fallback ? window.getComputedStyle(fallback).display : null,
                iconFontSize: icon ? window.getComputedStyle(icon).fontSize : null,
                iconColor: icon ? window.getComputedStyle(icon).color : null
            };
        });
    });

    console.log('Card count:', cardStats.length);
    console.log('Card stats:', JSON.stringify(cardStats, null, 2));

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';
    
    // Full screen capture
    await page.screenshot({ path: `${artifactDir}/pos_cards_edge_to_edge_pictures.png` });
    console.log('Saved pos_cards_edge_to_edge_pictures.png');

    // Catalog grid crop
    const catalogGrid = await page.$('#catalogGrid');
    if (catalogGrid) {
        await catalogGrid.screenshot({ path: `${artifactDir}/pos_cards_edge_to_edge_crop.png` });
        console.log('Saved pos_cards_edge_to_edge_crop.png');
    }

    await browser.close();
})();
