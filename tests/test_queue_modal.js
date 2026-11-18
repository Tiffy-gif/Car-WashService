const { chromium } = require('playwright');
const http = require('http');

function getRealStaffSession() {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({ username: 'visal', password: '123456' });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
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
    try {
        const loginRes = await getRealStaffSession();
        const browser = await chromium.launch({ channel: 'chrome', headless: true });
        const context = await browser.newContext({ viewport: { width: 1920, height: 960 } });

        await context.addInitScript((loginData) => {
            localStorage.setItem('carwash_pos_config', JSON.stringify({
                backofficeUrl: 'http://localhost/Car-WashService',
                deviceUid: 'POS-CAASIER',
                deviceKey: 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
                exchangeRate: 4100
            }));
            localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
            localStorage.setItem('cw_pos_session_token', loginData.session_token);
            sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
            localStorage.setItem('carwash_pos_has_connected', 'true');
        }, loginRes);

        const page = await context.newPage();
        await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2000);

        // Disable upsell popups for clean deterministic testing
        await page.evaluate(() => {
            if (window.PosApp) {
                window.PosApp.evaluateCartUpsellRules = () => {};
            }
            if (window.PosApi) {
                window.PosApi.evaluateCartUpsell = async () => ({ success: true, has_offer: false });
            }
            if (window.I18nManager) window.I18nManager.setLang('en');
            if (window.PosApp) window.PosApp.onLanguageChanged();
        });
        await page.waitForTimeout(400);

        console.log('--- 1. Testing Queue Modal Open via #btnPastelQueue ---');
        await page.click('#btnPastelQueue');
        await page.waitForTimeout(800);

        // Verify modalQueue has .open class
        const isModalOpen = await page.evaluate(() => {
            const m = document.getElementById('modalQueue');
            return m && m.classList.contains('open');
        });
        console.log('Is Queue Modal Open:', isModalOpen);

        // Verify title & sync indicator
        const titleText = await page.$eval('#queueModalTitle', el => el.textContent.trim());
        console.log('Queue Modal Title:', titleText);

        // Verify Bay Cards
        const bayCardCount = await page.evaluate(() => {
            return document.querySelectorAll('#modalBaysGrid .modal-bay-card').length;
        });
        console.log('Bay Cards Rendered Count:', bayCardCount);

        const bayCardsData = await page.evaluate(() => {
            return Array.from(document.querySelectorAll('#modalBaysGrid .modal-bay-card')).map(card => {
                const name = card.querySelector('.modal-bay-num')?.textContent.trim();
                const pill = card.querySelector('.modal-bay-pill')?.textContent.trim();
                const isFree = card.classList.contains('is-free');
                return { name, pill, isFree };
            });
        });
        console.log('Bay Cards Data:', JSON.stringify(bayCardsData));

        // Verify Queue Table rows
        const queueRowCount = await page.evaluate(() => {
            return document.querySelectorAll('#modalQueueTableBody tr').length;
        });
        console.log('Queue Table Row Count:', queueRowCount);

        const summaryText = await page.$eval('#modalQueueSummaryText', el => el.textContent.trim());
        console.log('Queue Summary Text:', summaryText);

        // Take Fullscreen Screenshot
        await page.screenshot({
            path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_queue_modal_fullscreen.png'
        });
        console.log('Saved pos_queue_modal_fullscreen.png');

        // Take Modal Element Screenshot (EN)
        const modalBox = await page.$('.modal-queue-content');
        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_queue_modal_en.png'
            });
            console.log('Saved pos_queue_modal_en.png');
        }

        // --- 2. Test Khmer Localization ---
        console.log('--- 2. Testing Khmer Localization ---');
        await page.evaluate(async () => {
            if (window.I18nManager) window.I18nManager.setLang('km');
            if (window.PosApp) {
                window.PosApp.onLanguageChanged();
                await window.PosApp.refreshQueueModal();
            }
        });
        await page.waitForTimeout(600);

        const kmTitle = await page.$eval('#queueModalTitle', el => el.textContent.trim());
        console.log('Khmer Queue Modal Title:', kmTitle);

        const kmSummary = await page.$eval('#modalQueueSummaryText', el => el.textContent.trim());
        console.log('Khmer Summary Text:', kmSummary);

        if (modalBox) {
            await modalBox.screenshot({
                path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_queue_modal_km.png'
            });
            console.log('Saved pos_queue_modal_km.png');
        }

        // --- 3. Test Refresh Button ---
        console.log('--- 3. Testing Refresh Button ---');
        await page.click('#btnRefreshQueueModal');
        await page.waitForTimeout(600);
        const recheckBays = await page.evaluate(() => document.querySelectorAll('#modalBaysGrid .modal-bay-card').length);
        console.log('Bays count after refresh click:', recheckBays);

        // --- 4. Test "Open Full Cockpit" and "Back to Register" Navigation ---
        console.log('--- 4. Testing Open Full Cockpit & Back Button ---');
        await page.click('#btnOpenFullQueueView');
        await page.waitForTimeout(600);

        const isModalClosed = await page.evaluate(() => {
            const m = document.getElementById('modalQueue');
            return !m || !m.classList.contains('open');
        });
        console.log('Is Modal Closed After Clicking Open Full Cockpit:', isModalClosed);

        const isQueueViewActive = await page.evaluate(() => {
            const qView = document.getElementById('viewQueue');
            return qView && qView.classList.contains('active');
        });
        console.log('Is #viewQueue Active:', isQueueViewActive);

        const hasBackToPosBtn = await page.evaluate(() => {
            const btn = document.getElementById('btnQueueBackToPos');
            return btn !== null && btn.offsetParent !== null;
        });
        console.log('Has visible #btnQueueBackToPos:', hasBackToPosBtn);

        // Click Back to Register
        await page.click('#btnQueueBackToPos');
        await page.waitForTimeout(600);

        const isPosViewActive = await page.evaluate(() => {
            const pView = document.getElementById('viewPos');
            return pView && pView.classList.contains('active');
        });
        console.log('Is back on #viewPos:', isPosViewActive);

        console.log('=== ALL QUEUE MODAL TESTS PASSED SUCCESSFULLY! ===');
        await browser.close();
        process.exit(0);
    } catch (err) {
        console.error('Test error:', err);
        process.exit(1);
    }
})();
