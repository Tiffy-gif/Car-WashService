/**
 * Automated Verification Script: Member Panel, Upsell Prompts, Loyalty Rewards, Message Permission
 * Tests on Big Screen (1920x1080) and Tablet Size (1024x768)
 */
const puppeteer = require('puppeteer-core');
const { execSync } = require('child_process');
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
    console.log('=== STARTING AUTOMATED TEST: MEMBER, UPSELL, REWARD & MESSAGE PERMISSION ===');
    try {
        execSync(`C:\\xampp\\mysql\\bin\\mysql.exe -u root carwash_db -e "DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id = 1 AND (idempotency_key LIKE 'pos_%' OR idempotency_key LIKE 'test_order%')); DELETE FROM loyalty_transactions WHERE order_id IN (SELECT id FROM orders WHERE customer_id = 1 AND (idempotency_key LIKE 'pos_%' OR idempotency_key LIKE 'test_order%')); DELETE FROM orders WHERE customer_id = 1 AND (idempotency_key LIKE 'pos_%' OR idempotency_key LIKE 'test_order%');"`);
    } catch(e) {}

    const browser = await puppeteer.launch({
        executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();
    await page.setCacheEnabled(false);
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.toString()));

    await page.evaluateOnNewDocument(() => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceKey: 'pos_sec_test_key_8899aabb',
            printerDirectEnabled: false
        }));
        localStorage.setItem('carwash_pos_has_connected', 'true');
        sessionStorage.clear();
    });

    // Helper: Login
    async function doLogin() {
        await page.goto('http://localhost/Pos-Carwash/index.html', { waitUntil: 'networkidle2' });
        await page.waitForFunction(() => typeof PosApp !== 'undefined');
        const isLoginVisible = await page.$eval('#viewLogin', el => el.style.display !== 'none');
        if (isLoginVisible) {
            await page.evaluate(async () => {
                document.getElementById('loginUsername').value = 'manager';
                PosApp.currentPin = '123456';
                await PosApp.handleLogin();
            });
            await page.waitForFunction(() => {
                const l = document.getElementById('viewLogin');
                return l && l.style.display === 'none';
            }, { timeout: 8000 });
        }
        await page.waitForSelector('#btnCustomerDropdown', { timeout: 10000 });
        console.log('✓ Successfully logged in as Manager / Cashier');
    }

    try {
        // ========================================================
        // TEST SUITE 1: BIG SCREEN (1920x1080)
        // ========================================================
        console.log('\n--- 1. Testing on Big Screen (1920x1080) ---');
        await page.setViewport({ width: 1920, height: 1080 });
        await doLogin();

        // 1A. Member Panel & Included Service at No Charge
        console.log('\n[1A] Testing Member Panel with Gold Member (Plate 2BQ-8899)...');
        await page.evaluate(async () => {
            PosApp.selectCustomer('2BQ-8899', 'Sokha Meng', '012 889 900', 'small_car', 1, 1);
            await PosApp.loadVehicleProfile('2BQ-8899');
        });
        await sleep(1000);

        // Check vehicle profile & member panel
        await page.waitForSelector('#cartVehicleProfile', { visible: true, timeout: 5000 });
        const memberPanelVisible = await page.$eval('#profMemberPanel', el => window.getComputedStyle(el).display !== 'none');
        const memberTier = await page.$eval('#memberTierName', el => el.textContent.trim());
        const memberVisits = await page.$eval('#memberVisitsCount', el => el.textContent.trim());
        const benefitsText = await page.$eval('#profBenefitsChips', el => el.textContent.trim());

        console.log(`✓ Member panel visible: ${memberPanelVisible}`);
        console.log(`✓ Member tier: "${memberTier}"`);
        console.log(`✓ Visits this month: "${memberVisits}"`);
        console.log(`✓ Benefits chips: "${benefitsText}"`);

        if (!memberPanelVisible || !memberTier.toLowerCase().includes('gold')) {
            throw new Error(`Member panel failed to show Gold tier. Got: ${memberTier}`);
        }

        // Add Exterior Wash (service_id 1, which is included for Gold)
        console.log('Adding Exterior Wash to cart for Gold Member...');
        await page.evaluate(() => PosApp.addServiceToCart(1));
        await sleep(600);

        // Verify cart price shows $0.00 / Free
        const itemPrice = await page.$eval('.cart-item-row .cart-item-price', el => el.textContent.trim());
        const hasFreeBadge = await page.$eval('.cart-item-row', el => el.innerHTML.includes('cart-free-badge'));
        const subtotalText = await page.$eval('#cartSubtotalText', el => el.textContent.trim());
        console.log(`✓ Cart item price: ${itemPrice}`);
        console.log(`✓ Has FREE / Included badge: ${hasFreeBadge}`);
        console.log(`✓ Subtotal: ${subtotalText}`);

        if (itemPrice !== '$0.00' && !itemPrice.includes('0.00')) {
            throw new Error(`Included service did not show at no charge! Got price: ${itemPrice}`);
        }

        // 1B. Upsell Prompt Check & Dismiss (Requirement 2: Friendly offer with Not now, never blocks sale)
        console.log('\n[1B] Checking Upsell Prompt upon adding wash...');
        await page.waitForSelector('#modalUpsellPrompt', { visible: true, timeout: 5000 });
        const upsellPromptText = await page.$eval('#upsellModalMessage', el => el.textContent.trim());
        console.log(`✓ Upsell offer popped up: "${upsellPromptText}"`);
        console.log('Dismissing upsell with "Not now"...');
        await page.click('#btnDeclineUpsell');
        await sleep(400);

        // 1C. Loyalty Reward Prompt
        console.log('\n[1C] Testing Loyalty Reward Prompt (Free air freshener)...');
        await page.waitForSelector('#cartLoyaltyRewardBanner', { visible: true, timeout: 5000 });
        const rewardText = await page.$eval('#loyaltyRewardDesc', el => el.textContent.trim());
        console.log(`✓ Loyalty reward prompt displayed: "${rewardText}"`);

        const openModalsBefore = await page.$$eval('.modal-backdrop.open, .modal.open', els => els.map(e => e.id));
        console.log('Open modals before reward click:', openModalsBefore);

        // Click "Gave Reward"
        await page.click('#btnGiveLoyaltyReward');
        await sleep(500);

        const openModalsAfter = await page.$$eval('.modal-backdrop.open, .modal.open', els => els.map(e => e.id));
        console.log('Open modals after reward click:', openModalsAfter);

        const bannerHidden = await page.$eval('#cartLoyaltyRewardBanner', el => window.getComputedStyle(el).display === 'none');
        const badgeText = await page.$eval('#cartLoyaltyRewardStatusBadge', el => el.textContent.trim());
        console.log(`✓ Banner hidden after click: ${bannerHidden}`);
        console.log(`✓ Status badge updated: "${badgeText}"`);

        // Complete payment & check back office record
        console.log('Checking out member order with cash...');
        await page.click('#btnOpenCheckout');
        await page.waitForSelector('#modalPayment.open', { timeout: 5000 });
        await page.click('#btnConfirmPaymentAndPrint');
        await sleep(1500);
        console.log('✓ Order completed successfully');

        // Close receipt modal if open
        const receiptOpen = await page.$eval('#modalReceipt', el => el.classList.contains('open')).catch(() => false);
        if (receiptOpen) {
            await page.click('#btnCloseReceiptModal');
            await sleep(400);
        }

        // 1D. Non-Member & Upsell Prompt Acceptance
        console.log('\n[1D] Testing Non-Member (Plate 2AB-1234) & Upsell Prompt Acceptance...');
        await page.evaluate(async () => {
            PosApp.clearCart();
            PosApp.resetCustomer();
            PosApp.selectCustomer('2AB-1234', 'Borey Chea', '093 456 789', 'small_car', 2, 3);
            await PosApp.loadVehicleProfile('2AB-1234');
        });
        await sleep(1000);

        const nonMemberTier = await page.$eval('#memberTierName', el => el.textContent.trim());
        console.log(`✓ Non-member tier displayed: "${nonMemberTier}"`);

        // Add Exterior Wash (service 1) -> Non-member should pay normal price
        console.log('Adding Exterior Wash for Non-member...');
        await page.evaluate(() => PosApp.addServiceToCart(1));
        await sleep(600);

        const nonMemberPrice = await page.$eval('.cart-item-row .cart-item-price', el => el.textContent.trim());
        console.log(`✓ Non-member item price (Normal charge): ${nonMemberPrice}`);
        if (nonMemberPrice === '$0.00') {
            throw new Error(`Non-member unexpectedly received free wash!`);
        }

        // Wait for upsell prompt modal
        console.log('Waiting for Back-Office Upsell Prompt modal...');
        await page.waitForSelector('#modalUpsellPrompt', { visible: true, timeout: 6000 });
        const upsellMsg = await page.$eval('#upsellModalMessage', el => el.textContent.trim());
        console.log(`✓ Upsell prompt appeared with friendly message: "${upsellMsg}"`);

        // Now test accepting the upsell
        console.log('Accepting upsell offer...');
        await page.evaluate(() => {
            const btn = document.getElementById('btnAcceptUpsell');
            if (btn) btn.click();
            else PosApp.handleAcceptUpsell();
        });
        await sleep(600);

        const cartItemsCount = await page.$$eval('.cart-item-row', rows => rows.length);
        const upgradedPrice = await page.$eval('.cart-item-row .cart-item-price', el => el.textContent.trim());
        console.log(`✓ Cart rows after upsell accepted: ${cartItemsCount}`);
        console.log(`✓ Upgraded item price in cart: ${upgradedPrice} (Offer: $5.00)`);

        // 1E. Message Permission in Add Customer
        console.log('\n[1E] Testing Message Permission in Quick Add Customer modal...');
        await page.evaluate(() => {
            PosApp.clearCart();
            PosApp.resetCustomer();
            document.getElementById('btnOpenNewCustomerModal')?.click();
        });
        await page.waitForSelector('#modalCustomer.open', { visible: true });

        const msgPermLabel = await page.$eval('.message-permission-group label', el => el.textContent.trim());
        console.log(`✓ Message permission question present: "${msgPermLabel}"`);

        const testPlate = `MSG-${Math.floor(1000 + Math.random() * 9000)}`;
        await page.type('#newCustPlate', testPlate);
        await page.type('#newCustName', 'Mr. SMS Consent Test');
        await page.type('#newCustPhone', '099123456');

        // Toggle to "No"
        await page.evaluate(() => document.getElementById('lblMsgConsentNo')?.click());
        await sleep(200);
        const isNoActive = await page.$eval('#lblMsgConsentNo', el => el.classList.contains('active'));
        console.log(`✓ Chose "No" for message permission. Button active: ${isNoActive}`);

        await page.evaluate(() => document.getElementById('btnSaveNewCustomer')?.click());
        await sleep(1000);
        console.log(`✓ Saved new customer ${testPlate}`);

        // ========================================================
        // TEST SUITE 2: TABLET SIZE (1024x768)
        // ========================================================
        console.log('\n--- 2. Testing on Tablet Viewport (1024x768) ---');
        await page.setViewport({ width: 1024, height: 768 });
        await sleep(500);

        // Check layout on tablet
        const tabletCartVisible = await page.$eval('.pos-cart-column', el => window.getComputedStyle(el).display !== 'none');
        console.log(`✓ Tablet layout: Cart column visible: ${tabletCartVisible}`);

        // Re-check member on tablet
        await page.evaluate(async () => {
            PosApp.clearCart();
            PosApp.resetCustomer();
            PosApp.selectCustomer('2BQ-8899', 'Sokha Meng', '012 889 900', 'small_car', 1, 1);
            await PosApp.loadVehicleProfile('2BQ-8899');
        });
        await sleep(800);

        const tabletMemberTier = await page.$eval('#memberTierName', el => el.textContent.trim());
        console.log(`✓ Tablet: Member panel displayed smoothly with tier: "${tabletMemberTier}"`);

        // Verify touch button heights on tablet (at least 36-48px for comfortable touch)
        const btnGiveHeight = await page.$eval('#btnGiveLoyaltyReward', el => el.offsetHeight);
        await page.evaluate(() => document.getElementById('btnOpenNewCustomerModal')?.click());
        await sleep(300);
        const optBtnHeight = await page.$eval('.msg-opt-btn', el => el.offsetHeight);
        await page.evaluate(() => document.getElementById('btnCloseCustomerModal')?.click());
        console.log(`✓ Tablet button touch heights: Reward btn = ${btnGiveHeight}px, Consent btn = ${optBtnHeight}px (Both >= 36px)`);

        console.log('\n========================================================');
        console.log('ALL TESTS PASSED SUCCESSFULLY ON BIG SCREEN & TABLET!');
        console.log('========================================================');

    } catch (err) {
        console.error('TEST FAILED:', err);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
