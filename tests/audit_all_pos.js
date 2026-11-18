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
    const consoleLogs = [];
    const pageErrors = [];
    const auditFindings = [];

    try {
        const loginRes = await getRealStaffSession();
        console.log('Login Response:', loginRes.success ? 'SUCCESS' : 'FAILED');
        if (!loginRes.success) throw new Error('Failed to authenticate');

        const browser = await chromium.launch({ channel: 'chrome', headless: true });
        const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });

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

        page.on('console', msg => {
            const text = msg.text();
            const type = msg.type();
            if (type === 'error' || text.toLowerCase().includes('uncaught') || text.toLowerCase().includes('error')) {
                consoleLogs.push({ type, text });
            }
        });

        page.on('pageerror', err => {
            pageErrors.push(err.message);
        });

        console.log('--- 1. Navigating to POS ---');
        await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2500);

        // Check if day locked or shift modal appeared
        const dayLocked = await page.evaluate(() => {
            return document.getElementById('viewPos')?.style.display !== 'none';
        });
        console.log('POS view active:', dayLocked);

        // --- 2. Check Catalog and Categories ---
        console.log('--- 2. Auditing Catalog & Categories ---');
        const catalogStats = await page.evaluate(() => {
            const tabs = Array.from(document.querySelectorAll('.cat-tab')).map(t => t.textContent.trim());
            const items = Array.from(document.querySelectorAll('.item-card')).map(c => ({
                id: c.getAttribute('data-id'),
                name: c.querySelector('.item-name')?.textContent.trim(),
                price: c.querySelector('.item-price')?.textContent.trim(),
                visible: c.style.display !== 'none'
            }));
            return { tabsCount: tabs.length, tabs, itemsCount: items.length, visibleCount: items.filter(i => i.visible).length };
        });
        console.log('Catalog stats:', catalogStats);
        if (catalogStats.itemsCount === 0) {
            auditFindings.push('Catalog has 0 items loaded!');
        }

        // Test clicking each category tab
        const tabList = await page.$$('.cat-tab');
        for (let i = 0; i < tabList.length && i < 4; i++) {
            await tabList[i].click();
            await page.waitForTimeout(300);
        }
        // Return to All tab
        if (tabList.length > 0) await tabList[0].click();
        await page.waitForTimeout(300);

        // --- 3. Auditing Vehicle Type Buttons ---
        console.log('--- 3. Auditing Vehicle Type Selection ---');
        const vTypes = await page.evaluate(() => {
            const pills = Array.from(document.querySelectorAll('.vtype-pill')).map(p => ({
                type: p.getAttribute('data-type'),
                active: p.classList.contains('active'),
                text: p.textContent.trim()
            }));
            return pills;
        });
        console.log('Vehicle types found:', vTypes);

        // --- 4. Auditing Cart Operations ---
        console.log('--- 4. Auditing Cart Item Add, Qty, Price Updates ---');
        // Click first item card
        const firstCard = await page.$('.item-card');
        if (firstCard) {
            await firstCard.click();
            await page.waitForTimeout(500);
        }

        const cartState1 = await page.evaluate(() => {
            const items = Array.from(document.querySelectorAll('#cartList .gotpos-cart-item')).map(it => ({
                name: it.querySelector('.gotpos-item-title')?.textContent.trim(),
                qty: it.querySelector('.gotpos-qty-val')?.textContent.trim(),
                price: it.querySelector('.gotpos-item-subtotal')?.textContent.trim()
            }));
            const subtotal = document.getElementById('cartSubtotalUsd')?.textContent.trim();
            const total = document.getElementById('cartTotalUsd')?.textContent.trim();
            return { items, subtotal, total };
        });
        console.log('Cart State after 1 item:', cartState1);
        if (cartState1.items.length === 0) {
            auditFindings.push('Clicking item card did not add item to cart!');
        }

        // Test Qty Increment
        const btnPlus = await page.$('.gotpos-qty-btn.plus');
        if (btnPlus) {
            await btnPlus.click();
            await page.waitForTimeout(400);
            const qtyAfterPlus = await page.evaluate(() => document.querySelector('.gotpos-qty-val')?.textContent.trim());
            console.log('Qty after + click:', qtyAfterPlus);
            if (qtyAfterPlus !== '2') {
                auditFindings.push(`Qty after clicking + expected '2' but got '${qtyAfterPlus}'`);
            }
        }

        // --- 5. Auditing 4 Pastel Dock Buttons ---
        console.log('--- 5. Auditing Bottom Dock Pastel Buttons ---');
        // 5a. Queue Modal
        await page.click('#btnPastelQueue');
        await page.waitForTimeout(600);
        const queueModalOpen = await page.evaluate(() => document.getElementById('modalQueue')?.classList.contains('open'));
        console.log('Queue modal opened:', queueModalOpen);
        if (!queueModalOpen) auditFindings.push('Queue modal failed to open from #btnPastelQueue');
        await page.click('#btnCloseModalQueue');
        await page.waitForTimeout(400);

        // 5b. Appointment Modal
        await page.click('#btnPastelAppointment');
        await page.waitForTimeout(600);
        const apptModalOpen = await page.evaluate(() => document.getElementById('modalAppointment')?.classList.contains('open'));
        console.log('Appointment modal opened:', apptModalOpen);
        if (!apptModalOpen) auditFindings.push('Appointment modal failed to open from #btnPastelAppointment');
        await page.click('#btnCloseApptModalBtn');
        await page.waitForTimeout(400);

        // 5c. Discount Modal
        await page.click('#btnPastelDiscount');
        await page.waitForTimeout(600);
        const discModalOpen = await page.evaluate(() => document.getElementById('modalDiscount')?.classList.contains('open'));
        console.log('Discount modal opened:', discModalOpen);
        if (!discModalOpen) auditFindings.push('Discount modal failed to open from #btnPastelDiscount');
        await page.click('#btnCancelDiscountModal');
        await page.waitForTimeout(400);

        // 5d. Billing History Modal
        await page.click('#btnPastelHistory');
        await page.waitForTimeout(600);
        const histModalOpen = await page.evaluate(() => document.getElementById('modalBillingHistory')?.classList.contains('open'));
        console.log('Billing History modal opened:', histModalOpen);
        if (!histModalOpen) auditFindings.push('Billing History modal failed to open from #btnPastelHistory');
        await page.click('#btnCloseBillingHistoryModal');
        await page.waitForTimeout(400);

        // --- 6. Auditing Payment Flow ---
        console.log('--- 6. Auditing Payment Flow ---');
        await page.click('#btnGotposProcessPayment');
        await page.waitForTimeout(800);
        const payModalOpen = await page.evaluate(() => document.getElementById('modalPayment')?.classList.contains('open'));
        console.log('Payment modal opened:', payModalOpen);
        if (!payModalOpen) auditFindings.push('Payment modal failed to open from #btnGotposProcessPayment');

        // Check payment methods in modal
        const payMethods = await page.evaluate(() => {
            const tabs = Array.from(document.querySelectorAll('.pay-method-tab')).map(t => ({
                method: t.getAttribute('data-method'),
                text: t.textContent.trim(),
                active: t.classList.contains('active')
            }));
            const totalDue = document.getElementById('payTotalDueUsd')?.textContent.trim();
            const totalKhr = document.getElementById('payTotalDueKhr')?.textContent.trim();
            return { tabs, totalDue, totalKhr };
        });
        console.log('Payment Methods & Totals:', payMethods);

        // Close payment modal
        await page.click('#btnCancelPayment');
        await page.waitForTimeout(500);

        // --- 7. Auditing Hold & Recall ---
        console.log('--- 7. Auditing Hold & Recall Cart ---');
        const holdBtn = await page.$('#btnGotposHoldOrder');
        if (holdBtn) {
            await holdBtn.click();
            await page.waitForTimeout(600);
            const heldCartState = await page.evaluate(() => {
                const cartCount = document.querySelectorAll('#cartList .gotpos-cart-item').length;
                const heldCountBadge = document.getElementById('heldOrdersBadge')?.textContent.trim();
                return { cartCount, heldCountBadge };
            });
            console.log('Held Cart State:', heldCartState);
        }

        // --- 8. Auditing Bilingual Localization (EN <-> KM) ---
        console.log('--- 8. Auditing Language Switching ---');
        await page.evaluate(() => {
            if (window.I18nManager) window.I18nManager.setLang('km');
            if (window.PosApp) window.PosApp.onLanguageChanged();
        });
        await page.waitForTimeout(500);

        const kmCheck = await page.evaluate(() => {
            const untranslated = [];
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                const txt = el.textContent.trim();
                if (!txt || txt === key) {
                    untranslated.push(key);
                }
            });
            return { untranslatedCount: untranslated.length, untranslated: untranslated.slice(0, 10) };
        });
        console.log('Khmer Untranslated keys:', kmCheck);

        // Switch back to EN
        await page.evaluate(() => {
            if (window.I18nManager) window.I18nManager.setLang('en');
            if (window.PosApp) window.PosApp.onLanguageChanged();
        });
        await page.waitForTimeout(400);

        // --- 9. Check All View Switches ---
        console.log('--- 9. Auditing Full View Switches ---');
        const viewList = ['queue', 'appointments', 'orders', 'close', 'pos'];
        for (const v of viewList) {
            await page.evaluate((viewName) => {
                if (window.PosApp) window.PosApp.switchView(viewName);
            }, v);
            await page.waitForTimeout(500);
            const activeView = await page.evaluate(() => window.PosApp?.currentView);
            console.log(`Switched to view '${v}' -> currentView:`, activeView);
        }

        // Final screenshot
        await page.screenshot({
            path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_full_audit_clean.png'
        });
        console.log('Saved pos_full_audit_clean.png');

        console.log('\n=== AUDIT SUMMARY ===');
        console.log('Console Errors/Warnings:', consoleLogs.length);
        consoleLogs.forEach(c => console.log(`  [${c.type}] ${c.text}`));
        console.log('Page Errors:', pageErrors.length);
        pageErrors.forEach(e => console.log(`  [ERROR] ${e}`));
        console.log('Audit Findings:', auditFindings);

        await browser.close();
        process.exit(0);
    } catch (err) {
        console.error('Audit Script Error:', err);
        process.exit(1);
    }
})();
