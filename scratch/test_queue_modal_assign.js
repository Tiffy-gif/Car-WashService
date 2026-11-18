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
    console.log('Logging in staff...');
    const loginRes = await getStaffSession();
    if (!loginRes || !loginRes.success) {
        console.error('Login failed:', loginRes);
        process.exit(1);
    }
    console.log('Logged in as:', loginRes.user?.username);

    const browser = await chromium.launch({ 
        channel: 'chrome', 
        headless: true
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
    }, loginRes);

    const page = await context.newPage();
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));

    // 1. Navigate to POS register
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // 2. Open Queue Popup Modal
    console.log('Opening Queue Modal...');
    await page.click('#btnPastelQueue');
    await page.waitForTimeout(1500);

    const isQueueModalOpen = await page.evaluate(() => {
        const m = document.getElementById('modalQueue');
        return m && m.classList.contains('open');
    });
    console.log('Queue modal open state:', isQueueModalOpen);

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({ path: `${artifactDir}/pos_queue_modal_direct_assign.png` });
    console.log('Saved pos_queue_modal_direct_assign.png');

    // 3. Inspect waiting cars and assign bay buttons
    const assignButtons = await page.$$('.btn-modal-assign-bay');
    console.log(`Found ${assignButtons.length} "Assign Bay" buttons in waiting queue rows.`);

    const oneTapButtons = await page.$$('.btn-bay-onetap-assign');
    console.log(`Found ${oneTapButtons.length} "Assign: [Plate]" one-tap buttons on free bay cards.`);

    // 4. Test clicking "Assign Bay" if waiting button exists
    if (assignButtons.length > 0) {
        console.log('Clicking first "Assign Bay" button...');
        await assignButtons[0].click();
        await page.waitForTimeout(800);

        const isChooseBayOpen = await page.evaluate(() => {
            const m = document.getElementById('modalChooseBay');
            const q = document.getElementById('modalQueue');
            return {
                chooseBayOpen: m && m.classList.contains('open'),
                chooseBayZIndex: m ? window.getComputedStyle(m).zIndex : null,
                queueZIndex: q ? window.getComputedStyle(q).zIndex : null
            };
        });
        console.log('Choose bay status:', isChooseBayOpen);

        await page.screenshot({ path: `${artifactDir}/pos_queue_modal_choose_bay_open.png` });
        console.log('Saved pos_queue_modal_choose_bay_open.png');

        // Check free bay options inside modalChooseBay
        const bayOptions = await page.$$('#chooseBayList .choose-bay-option');
        console.log(`Found ${bayOptions.length} bay options in choose bay list.`);

        if (bayOptions.length > 0) {
            console.log('Assigning to first free bay option...');
            await bayOptions[0].click();
            await page.waitForTimeout(2000);

            await page.screenshot({ path: `${artifactDir}/pos_queue_modal_after_assign.png` });
            console.log('Saved pos_queue_modal_after_assign.png');
        }
    } else if (oneTapButtons.length > 0) {
        console.log('Clicking 1-tap assign button on free bay...');
        await oneTapButtons[0].click();
        await page.waitForTimeout(2000);

        await page.screenshot({ path: `${artifactDir}/pos_queue_modal_after_assign.png` });
        console.log('Saved pos_queue_modal_after_assign.png');
    }

    // 5. Test "Open Full Cockpit" button
    console.log('Testing "Open Full Cockpit" button...');
    await page.evaluate(() => {
        // If modal was closed, reopen it
        const m = document.getElementById('modalQueue');
        if (m && !m.classList.contains('open')) {
            m.classList.add('open');
        }
    });
    await page.waitForTimeout(500);

    const fullCockpitBtn = await page.$('#btnOpenFullQueueView');
    if (fullCockpitBtn) {
        await fullCockpitBtn.click();
        await page.waitForTimeout(1500);

        const currentHash = await page.evaluate(() => window.location.hash);
        const queueViewVisible = await page.evaluate(() => {
            const v = document.getElementById('viewQueue');
            return v && v.classList.contains('active');
        });
        console.log(`Current Hash: ${currentHash}, ViewQueue active: ${queueViewVisible}`);
    }

    await browser.close();
    console.log('Test completed successfully!');
})();
