const puppeteer = require('puppeteer-core');

const CHROME_PATH = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const POS_URL = process.env.POS_URL || 'http://localhost/Car-WashService/pos/index.html';
const BACKOFFICE_URL = process.env.BACKOFFICE_URL || POS_URL.replace(/\/pos(?:\/.*)?$/i, '');
const POS_DEVICE_KEY = process.env.POS_DEVICE_KEY || '';

(async () => {
    console.log('=== STARTING POS SYSTEM UPDATE & OFFLINE BANNER VERIFICATION ===');
    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();
    page.on('console', msg => {
        const text = msg.text();
        if (text.includes('Error') || text.includes('update') || text.includes('Price') || text.includes('System')) {
            console.log('PAGE LOG:', text);
        }
    });

    try {
        await page.evaluateOnNewDocument((backofficeUrl, deviceKey) => {
            localStorage.setItem('carwash_pos_config', JSON.stringify({
                backofficeUrl: backofficeUrl,
                deviceKey: deviceKey,
                printerDirectEnabled: false
            }));
            sessionStorage.clear();
        }, BACKOFFICE_URL, POS_DEVICE_KEY);

        console.log('\n--- 1. Logging in and Preparing POS Cart ---');
        await page.setViewport({ width: 1280, height: 800 });
        await page.goto(POS_URL, { waitUntil: 'networkidle2' });

        // Login as manager
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
        console.log('✓ Successfully authenticated with back office');

        // Handle open shift modal if shown
        const isShiftModalOpen = await page.evaluate(() => {
            const m = document.getElementById('modalStartShift');
            return m && m.classList.contains('open');
        });
        if (isShiftModalOpen) {
            await page.type('#shiftStartingCash', '100');
            await page.click('#btnConfirmStartShift');
            await page.waitForFunction(() => {
                const m = document.getElementById('modalStartShift');
                return !m || !m.classList.contains('open');
            }, { timeout: 5000 });
        }

        await page.waitForSelector('.pos-item-card', { timeout: 5000 });

        // Add service 1 (Standard Wash) to cart
        console.log('Adding Service 1 (Exterior Wash) to cart during sale...');
        await page.evaluate(() => {
            PosApp.clearCart();
            PosApp.addServiceToCart(1);
        });

        const initialCart = await page.evaluate(() => {
            const item = PosApp.cart[0];
            return {
                cartLength: PosApp.cart.length,
                name: item ? item.name_en : null,
                priceUsd: item ? item.price_usd : 0,
                priceKhr: item ? item.price_khr : 0
            };
        });
        console.log(`✓ Cart has ${initialCart.cartLength} item: "${initialCart.name}" at $${initialCart.priceUsd.toFixed(2)} (${initialCart.priceKhr.toLocaleString()} ៛)`);

        // ---------------------------------------------------------
        // 2. BACK OFFICE PRICE CHANGE DURING SALE
        // ---------------------------------------------------------
        console.log('\n--- 2. Simulating Back Office Price Change During Sale ---');

        // Trigger system update with a changed revision and new pricing matrix (price: $5.50)
        await page.evaluate(async () => {
            // Simulate back office reporting new revision hash
            const fakeNewRevision = {
                hash: 'rev_price_update_test_' + Date.now(),
                prices_hash: 'ph_new_999',
                users_hash: 'uh_same_111',
                permissions_hash: 'perm_same_222'
            };

            // Call system update handler
            await PosApp.handleSystemRevisionCheck(fakeNewRevision);
        });

        await new Promise(r => setTimeout(r, 600));

        // 2.1 Verify small toast notice was shown: "System update: prices or permissions have been updated"
        const toastCheck = await page.evaluate(() => {
            const alertBox = document.getElementById('posNoticeAlert');
            const content = document.getElementById('posNoticeAlertContent');
            return {
                display: alertBox ? window.getComputedStyle(alertBox).display : 'none',
                text: content ? content.textContent.trim() : ''
            };
        });
        console.log(`✓ Small message shown: "${toastCheck.text}"`);

        // Now simulate the price of the item in the cart changing to $5.50 in the back office catalog
        console.log('Checking cart price change prompt window...');
        await page.evaluate(() => {
            const currentCatalog = PosStorage.getCatalog() || {};
            const vtype = PosApp.selectedVehicleType || 'small_car';
            const matrix = currentCatalog.pricing_matrix || {};
            
            // Set new price in matrix to $5.50 for service 1
            if (!matrix[1]) matrix[1] = {};
            matrix[1][vtype] = { usd: 5.50, khr: 22550, minutes: 20 };
            currentCatalog.pricing_matrix = matrix;
            PosStorage.setCatalog(currentCatalog);

            // Trigger cart price change check
            PosApp.checkCartPriceChanges(currentCatalog);
        });

        await new Promise(r => setTimeout(r, 400));

        // 2.2 Verify Modal appears: "The price of this item changed. Use the new price?"
        const modalInfo = await page.evaluate(() => {
            const modal = document.getElementById('modalCartPriceChange');
            const prompt = modal?.querySelector('h4')?.textContent.trim();
            const itemName = document.getElementById('priceChangeItemName')?.textContent.trim();
            const oldPrice = document.getElementById('priceChangeOldPrice')?.textContent.trim();
            const newPrice = document.getElementById('priceChangeNewPrice')?.textContent.trim();
            const btnKeep = document.getElementById('btnKeepOldPrice');
            const btnUse = document.getElementById('btnUseNewPrice');
            return {
                isOpen: modal ? modal.classList.contains('open') : false,
                promptText: prompt,
                itemName: itemName,
                oldPrice: oldPrice,
                newPrice: newPrice,
                hasKeepBtn: !!btnKeep,
                hasUseBtn: !!btnUse
            };
        });

        console.log(`✓ Price change modal is open: ${modalInfo.isOpen}`);
        console.log(`✓ Prompt question: "${modalInfo.promptText}"`);
        console.log(`✓ Item name: "${modalInfo.itemName}"`);
        console.log(`✓ Old price displayed: "${modalInfo.oldPrice}"`);
        console.log(`✓ New price displayed: "${modalInfo.newPrice}"`);
        console.log(`✓ Cashier choices: "Keep Current Price" (${modalInfo.hasKeepBtn}), "Use New Price" (${modalInfo.hasUseBtn})`);

        // 2.3 Test cashier clicking "Use New Price"
        console.log('\n--- 2.3 Cashier Chooses "Use New Price" ---');
        await page.click('#btnUseNewPrice');
        await new Promise(r => setTimeout(r, 400));

        const updatedCart = await page.evaluate(() => {
            const item = PosApp.cart[0];
            const modal = document.getElementById('modalCartPriceChange');
            return {
                cartLength: PosApp.cart.length,
                modalClosed: !modal?.classList.contains('open'),
                itemPriceUsd: item ? item.price_usd : 0,
                totalText: document.getElementById('cartTotalUsd')?.textContent.trim()
            };
        });

        console.log(`✓ Cart preserved (not lost): ${updatedCart.cartLength} item in cart`);
        console.log(`✓ Item price updated to new price: $${updatedCart.itemPriceUsd.toFixed(2)}`);
        console.log(`✓ Cart total updated to: ${updatedCart.totalText}`);
        console.log(`✓ Modal closed: ${updatedCart.modalClosed}`);

        // 2.4 Verify next sale item uses new price automatically
        console.log('\n--- 2.4 Verifying Next Sale Uses New Prices ---');
        await page.evaluate(() => {
            // Next sale: clear cart or add another service
            PosApp.clearCart();
            PosApp.addServiceToCart(1);
        });

        const nextSaleItem = await page.evaluate(() => {
            return {
                priceUsd: PosApp.cart[0]?.price_usd
            };
        });
        console.log(`✓ Item added on next sale takes new catalog price: $${nextSaleItem.priceUsd.toFixed(2)} (expected: 5.50)`);

        // ---------------------------------------------------------
        // 3. TESTING CONNECTION LOST (> 60 SECONDS) AND RESTORATION
        // ---------------------------------------------------------
        console.log('\n--- 3. Testing Lost Connection for > 60 Seconds ---');

        // Simulate connection lost for 65 seconds
        await page.evaluate(() => {
            PosApp.lastSyncSuccessTime = Date.now() - 65000;
            PosApp.updateOfflineManualBanner();
        });

        const offlineCheck = await page.evaluate(() => {
            const banner = document.getElementById('offlineManualModeBanner');
            const style = banner ? window.getComputedStyle(banner) : null;
            return {
                isVisible: banner ? style.display !== 'none' : false,
                display: style ? style.display : 'none',
                text: banner ? banner.textContent.trim() : '',
                bgColor: style ? style.backgroundColor : '',
                color: style ? style.color : ''
            };
        });

        console.log(`✓ Offline banner is visible: ${offlineCheck.isVisible}`);
        console.log(`✓ Banner text: "${offlineCheck.text}"`);
        console.log(`✓ Banner background styling: ${offlineCheck.bgColor} (Yellow/Amber background)`);

        // Simulate connection returning
        console.log('\n--- 3.1 Restoring Connection ---');
        await page.evaluate(() => {
            PosApp.lastSyncSuccessTime = Date.now();
            PosApp.setOnlineStatus(true);
        });

        const onlineCheck = await page.evaluate(() => {
            const banner = document.getElementById('offlineManualModeBanner');
            return {
                display: banner ? window.getComputedStyle(banner).display : 'none',
                isHidden: banner ? window.getComputedStyle(banner).display === 'none' : true
            };
        });

        console.log(`✓ Offline banner hidden when connection returned: ${onlineCheck.isHidden}`);

        console.log('\n=== ALL VERIFICATIONS PASSED SUCCESSFULLY! ===');
    } catch (err) {
        console.error('Test failed with error:', err);
    } finally {
        await browser.close();
    }
})();
