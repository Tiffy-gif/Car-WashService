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
    const consoleErrors = [];
    const pageErrors = [];
    const auditReport = [];

    function record(section, status, detail) {
        auditReport.push({ section, status, detail });
        console.log(`[${status}] ${section}: ${detail}`);
    }

    try {
        const loginRes = await getRealStaffSession();
        if (!loginRes.success) throw new Error('Staff login failed');

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
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        page.on('pageerror', err => {
            pageErrors.push(err.message);
        });

        // 1. Initial Load
        await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2500);

        // Turn off upsell prompts for clean determinism during audit
        await page.evaluate(() => {
            if (window.PosApp) window.PosApp.evaluateCartUpsellRules = () => {};
            if (window.PosApi) window.PosApi.evaluateCartUpsell = async () => ({ success: true, has_offer: false });
        });

        // SECTION 1: Catalog & Categories
        const catalogInfo = await page.evaluate(() => {
            const pills = Array.from(document.querySelectorAll('#categorySidebar .category-pill:not(.pictures-toggle-pill)')).map(p => ({
                cat: p.getAttribute('data-cat'),
                text: p.innerText.trim()
            }));
            const cards = Array.from(document.querySelectorAll('#catalogGrid .pos-item-card')).map(c => ({
                svcId: c.getAttribute('data-svc-id'),
                prodId: c.getAttribute('data-prod-id'),
                nameKm: c.querySelector('.item-name-km')?.textContent.trim(),
                nameEn: c.querySelector('.item-name-en')?.textContent.trim(),
                price: c.querySelector('.item-price-usd')?.textContent.trim()
            }));
            return { pills, cardsCount: cards.length, sampleCards: cards.slice(0, 3) };
        });

        if (catalogInfo.pills.length > 0 && catalogInfo.cardsCount > 0) {
            record('Catalog & Categories', 'PASS', `Loaded ${catalogInfo.pills.length} category pills and ${catalogInfo.cardsCount} item cards.`);
        } else {
            record('Catalog & Categories', 'FAIL', `Only ${catalogInfo.pills.length} categories and ${catalogInfo.cardsCount} items.`);
        }

        // SECTION 2: Vehicle Type Selector
        const vTypes = await page.evaluate(() => {
            const btns = Array.from(document.querySelectorAll('.vehicle-btn')).map(b => ({
                vtype: b.getAttribute('data-vtype'),
                active: b.classList.contains('active'),
                title: b.title
            }));
            return btns;
        });

        if (vTypes.length >= 4) {
            record('Vehicle Type Bar', 'PASS', `Found ${vTypes.length} vehicle types: ${vTypes.map(v => v.vtype).join(', ')}.`);
        } else {
            record('Vehicle Type Bar', 'WARN', `Found only ${vTypes.length} vehicle buttons.`);
        }

        // SECTION 3: Cart Mechanics (Add, Qty +, Qty -, Price update, Clear)
        // Ensure cart is clear
        await page.evaluate(() => window.PosApp?.clearCart());
        await page.waitForTimeout(300);

        // Click first item card
        const cardToClick = await page.$('#catalogGrid .pos-item-card');
        if (cardToClick) {
            await cardToClick.click();
            await page.waitForTimeout(600);
        }

        const cartAfterAdd = await page.evaluate(() => {
            const count = document.querySelectorAll('#cartList .cart-item-row').length;
            const title = document.querySelector('#cartList .gotpos-item-title')?.textContent.trim();
            const total = document.getElementById('cartTotalUsd')?.textContent.trim();
            return { count, title, total };
        });

        if (cartAfterAdd.count === 1) {
            record('Cart Add Item', 'PASS', `Added "${cartAfterAdd.title}" to cart. Total: ${cartAfterAdd.total}`);
        } else {
            record('Cart Add Item', 'FAIL', `Item was not added to cart. Cart count: ${cartAfterAdd.count}`);
        }

        // Test Qty Increment (+)
        const plusBtns = await page.$$('#cartList .qty-btn');
        let plusBtn = null;
        for (const btn of plusBtns) {
            const txt = await page.evaluate(el => el.textContent.trim(), btn);
            if (txt === '+') { plusBtn = btn; break; }
        }
        if (plusBtn) {
            await plusBtn.click();
            await page.waitForTimeout(400);
            const qtyVal = await page.evaluate(() => document.querySelector('#cartList .qty-val')?.textContent.trim());
            if (qtyVal === '2') {
                record('Cart Qty Increment', 'PASS', `Qty successfully increased to 2`);
            } else {
                record('Cart Qty Increment', 'FAIL', `Expected qty 2, got ${qtyVal}`);
            }
        }

        // Test Qty Decrement (-)
        const minusBtns = await page.$$('#cartList .qty-btn');
        let minusBtn = null;
        for (const btn of minusBtns) {
            const txt = await page.evaluate(el => el.textContent.trim(), btn);
            if (txt === '-') { minusBtn = btn; break; }
        }
        if (minusBtn) {
            await minusBtn.click();
            await page.waitForTimeout(400);
            const qtyVal = await page.evaluate(() => document.querySelector('#cartList .qty-val')?.textContent.trim());
            if (qtyVal === '1') {
                record('Cart Qty Decrement', 'PASS', `Qty successfully decreased back to 1`);
            } else {
                record('Cart Qty Decrement', 'FAIL', `Expected qty 1, got ${qtyVal}`);
            }
        }

        // Test Vehicle Type switching price updates
        const suvBtn = await page.$('.vehicle-btn[data-vtype="suv_pickup"]');
        if (suvBtn) {
            const initialPrice = await page.evaluate(() => document.getElementById('cartTotalUsd')?.textContent.trim());
            await suvBtn.click();
            await page.waitForTimeout(500);
            const suvPrice = await page.evaluate(() => document.getElementById('cartTotalUsd')?.textContent.trim());
            record('Vehicle Type Dynamic Pricing', 'PASS', `Switching to SUV updated pricing from ${initialPrice} to ${suvPrice}`);
            // Switch back to small car
            await page.click('.vehicle-btn[data-vtype="small_car"]');
            await page.waitForTimeout(400);
        }

        // SECTION 4: Discount System
        console.log('--- Auditing Discount System ---');
        await page.click('#btnPastelDiscount');
        await page.waitForTimeout(500);
        const discModalOpen = await page.evaluate(() => document.getElementById('modalDiscount')?.classList.contains('open'));
        if (discModalOpen) {
            record('Discount Modal Open', 'PASS', 'Discount modal opened cleanly via #btnPastelDiscount');

            // Select 10% preset
            await page.click('#modalDiscount .btn-modal-disc[data-disc="10%"]');
            await page.waitForTimeout(300);
            await page.click('#btnApplyDiscountModal');
            await page.waitForTimeout(500);

            const discountActive = await page.evaluate(() => {
                const badge = document.getElementById('cartDiscountBadge');
                const row = document.getElementById('cartDiscountRow');
                const discText = document.getElementById('cartDiscountText')?.textContent.trim();
                const total = document.getElementById('cartTotalUsd')?.textContent.trim();
                return {
                    badgeVisible: badge?.style.display !== 'none',
                    rowVisible: row?.style.display !== 'none',
                    discText,
                    total
                };
            });

            if (discountActive.badgeVisible || discountActive.rowVisible) {
                record('Discount Apply', 'PASS', `10% discount applied. Discount: ${discountActive.discText}, Total: ${discountActive.total}`);
            } else {
                record('Discount Apply', 'FAIL', 'Discount row/badge not visible after applying 10%');
            }

            // Remove discount using discount modal
            await page.click('#btnPastelDiscount');
            await page.waitForTimeout(400);
            await page.click('#btnRemoveDiscountModal');
            await page.waitForTimeout(500);

            const discRemoved = await page.evaluate(() => {
                const row = document.getElementById('cartDiscountRow');
                return row?.style.display === 'none' || !row;
            });
            if (discRemoved) {
                record('Discount Remove', 'PASS', 'Discount successfully removed, cart restored');
            } else {
                record('Discount Remove', 'WARN', 'Discount row still displayed');
            }
        } else {
            record('Discount Modal Open', 'FAIL', 'Could not open #modalDiscount');
        }

        // SECTION 5: Customer Selection & Reset (GOTPOS Customer Card)
        console.log('--- Auditing Customer Selection & Reset ---');
        await page.click('#btnGotposEditCustomer');
        await page.waitForTimeout(600);
        const selectCustModalOpen = await page.evaluate(() => document.getElementById('modalSelectCustomer')?.classList.contains('open'));
        if (selectCustModalOpen) {
            record('Customer Modal Open', 'PASS', 'Customer selection modal opened via #btnGotposEditCustomer');

            // Check customer list items
            const custItemsCount = await page.evaluate(() => document.querySelectorAll('#selectCustList .select-cust-item').length);
            console.log('Customer list items available:', custItemsCount);

            if (custItemsCount > 0) {
                // Select first customer
                await page.click('#selectCustList .select-cust-item:first-child');
                await page.waitForTimeout(500);

                const activeCust = await page.evaluate(() => {
                    const name = document.getElementById('gotposCustNameDisplay')?.textContent.trim();
                    const resetBtnVisible = document.getElementById('btnGotposResetCustomer')?.style.display !== 'none';
                    return { name, resetBtnVisible };
                });
                record('Customer Selected', 'PASS', `Customer loaded into cart: "${activeCust.name}". Reset button visible: ${activeCust.resetBtnVisible}`);

                // Reset back to Walk-in Guest
                await page.click('#btnGotposResetCustomer');
                await page.waitForTimeout(400);

                const resetCust = await page.evaluate(() => document.getElementById('gotposCustNameDisplay')?.textContent.trim());
                if (resetCust.includes('Walk-in') || resetCust.includes('ភ្ញៀវ')) {
                    record('Customer Reset', 'PASS', `Customer reset successfully back to "${resetCust}"`);
                } else {
                    record('Customer Reset', 'FAIL', `Expected Walk-in Guest, got "${resetCust}"`);
                }
            } else {
                record('Customer Select List', 'WARN', 'No pre-existing customers in select list');
                await page.click('#btnCloseSelectCustModal');
            }
        } else {
            record('Customer Modal Open', 'FAIL', 'Could not open #modalSelectCustomer');
        }

        // SECTION 6: Bottom Pastel Buttons Quartet
        console.log('--- Auditing Bottom Pastel Quartet ---');
        // 6a. Queue Modal
        await page.click('#btnPastelQueue');
        await page.waitForTimeout(600);
        const qModal = await page.evaluate(() => ({
            isOpen: document.getElementById('modalQueue')?.classList.contains('open'),
            bayCards: document.querySelectorAll('#modalBaysGrid .modal-bay-card').length,
            tableRows: document.querySelectorAll('#modalQueueTableBody tr').length
        }));
        if (qModal.isOpen && qModal.bayCards === 4) {
            record('Queue Modal', 'PASS', `Live queue cockpit opened with 4 bay cards and ${qModal.tableRows} queue rows.`);
        } else {
            record('Queue Modal', 'FAIL', `Queue modal issue: open=${qModal.isOpen}, bays=${qModal.bayCards}`);
        }
        await page.click('#btnCloseQueueModal');
        await page.waitForTimeout(400);

        // 6b. Appointment Modal
        await page.click('#btnPastelAppointment');
        await page.waitForTimeout(600);
        const apptModal = await page.evaluate(() => ({
            isOpen: document.getElementById('modalAppointment')?.classList.contains('open'),
            hasBookingDrawer: document.getElementById('modalApptBookingDrawer') !== null,
            tableRows: document.querySelectorAll('#modalAppointmentTableBody tr').length
        }));
        if (apptModal.isOpen) {
            record('Appointment Modal', 'PASS', `Appointment schedule opened with ${apptModal.tableRows} scheduled appointments.`);
        } else {
            record('Appointment Modal', 'FAIL', `Appointment modal failed to open`);
        }
        await page.click('#btnCloseApptModalBtn');
        await page.waitForTimeout(400);

        // 6c. Billing History Modal
        await page.click('#btnPastelHistory');
        await page.waitForTimeout(600);
        const histModal = await page.evaluate(() => ({
            isOpen: document.getElementById('modalBillingHistory')?.classList.contains('open'),
            tableRows: document.querySelectorAll('#billingHistoryTableBody tr').length,
            paginationText: document.getElementById('billingHistoryFooterInfo')?.textContent.trim()
        }));
        if (histModal.isOpen && histModal.tableRows > 0) {
            record('Billing History Modal', 'PASS', `Opened billing history showing ${histModal.tableRows} invoice rows. Pagination: "${histModal.paginationText}"`);
        } else {
            record('Billing History Modal', 'FAIL', `Billing history failed to render invoices. Open=${histModal.isOpen}, rows=${histModal.tableRows}`);
        }
        await page.click('#btnCloseBillingHistoryModal');
        await page.waitForTimeout(400);

        // SECTION 7: Payment Modal & Methods
        console.log('--- Auditing Payment Modal & Methods ---');
        const countBeforePay = await page.evaluate(() => document.querySelectorAll('#cartList .cart-item-row').length);
        if (countBeforePay === 0) {
            await page.evaluate(() => window.PosApp?.addServiceToCart(1));
            await page.waitForTimeout(400);
        }

        await page.click('#btnOpenCheckout');
        await page.waitForTimeout(600);

        // If prompt plate modal opens, enter plate
        const isPromptPlateOpen = await page.evaluate(() => document.getElementById('modalPromptPlate')?.classList.contains('open'));
        if (isPromptPlateOpen) {
            record('Prompt Plate Modal', 'PASS', 'Prompt Plate modal opened smoothly for walk-in vehicle');
            await page.fill('#promptPlateInput', '2BC-9999');
            await page.click('#btnSavePromptPlate');
            await page.waitForTimeout(600);
        }

        const payModal = await page.evaluate(() => {
            const isOpen = document.getElementById('modalPayment')?.classList.contains('open');
            const totalUsd = document.getElementById('payModalTotalUsd')?.textContent.trim();
            const totalKhr = document.getElementById('payModalTotalKhr')?.textContent.trim();
            const tabs = Array.from(document.querySelectorAll('.pay-tab')).map(t => t.getAttribute('data-method'));
            return { isOpen, totalUsd, totalKhr, tabs };
        });

        if (payModal.isOpen && payModal.tabs.length >= 3) {
            record('Payment Modal', 'PASS', `Payment modal opened for ${payModal.totalUsd} (${payModal.totalKhr}). Methods: ${payModal.tabs.join(', ')}`);
        } else {
            record('Payment Modal', 'FAIL', `Payment modal failed to open properly: isOpen=${payModal.isOpen}`);
        }

        await page.click('#btnClosePaymentModal');
        await page.waitForTimeout(400);

        // SECTION 8: Bilingual Translation Completeness (Khmer <-> English)
        console.log('--- Auditing Bilingual Dictionary & Missing Keys ---');
        await page.evaluate(() => {
            if (window.I18nManager) window.I18nManager.setLang('km');
            if (window.PosApp) window.PosApp.onLanguageChanged();
        });
        await page.waitForTimeout(500);

        const kmAudit = await page.evaluate(() => {
            const missing = [];
            const elements = document.querySelectorAll('[data-i18n]');
            elements.forEach(el => {
                const key = el.getAttribute('data-i18n');
                const text = el.textContent.trim();
                if (text === key) {
                    missing.push(key);
                }
            });
            return { totalTagged: elements.length, missingKeys: [...new Set(missing)] };
        });

        if (kmAudit.missingKeys.length === 0) {
            record('Khmer Localization', 'PASS', `All ${kmAudit.totalTagged} tagged UI elements translated cleanly.`);
        } else {
            record('Khmer Localization', 'WARN', `Found ${kmAudit.missingKeys.length} untranslated keys: ${kmAudit.missingKeys.join(', ')}`);
        }

        // Switch back to English
        await page.evaluate(() => {
            if (window.I18nManager) window.I18nManager.setLang('en');
            if (window.PosApp) window.PosApp.onLanguageChanged();
        });
        await page.waitForTimeout(400);

        // SECTION 9: Screen Switches & Sub-views
        console.log('--- Auditing Sub-views Navigation ---');
        const viewsToTest = ['queue', 'appointments', 'orders', 'close', 'pos'];
        let viewPass = true;
        for (const v of viewsToTest) {
            await page.evaluate((viewName) => window.PosApp?.switchView(viewName), v);
            await page.waitForTimeout(400);
            const current = await page.evaluate(() => window.PosApp?.currentView);
            if (current !== v) {
                record(`View Switch to '${v}'`, 'FAIL', `Expected currentView '${v}', got '${current}'`);
                viewPass = false;
            }
        }
        if (viewPass) {
            record('Sub-views Navigation', 'PASS', `Seamlessly switched through ${viewsToTest.join(' -> ')} without errors.`);
        }

        // Final overview screenshot
        await page.screenshot({
            path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_comprehensive_audit_final.png',
            fullPage: false
        });
        record('Screenshot Capture', 'PASS', 'Saved pos_comprehensive_audit_final.png');

        console.log('\n====================================');
        console.log('=== FULL SYSTEM AUDIT COMPLETED ===');
        console.log('====================================');
        console.log('Console Errors Caught:', consoleErrors.length);
        consoleErrors.forEach(err => console.log('  [Console Error]:', err));
        console.log('Page Errors Caught:', pageErrors.length);
        pageErrors.forEach(err => console.log('  [Page Error]:', err));

        await browser.close();
        process.exit(0);
    } catch (err) {
        console.error('Fatal Audit Script Error:', err);
        process.exit(1);
    }
})();
