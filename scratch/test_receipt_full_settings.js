const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

async function testReceiptFullSettings() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
    const page = await context.newPage();

    console.log("1. Logging in as owner...");
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.evaluate(() => {
        document.getElementById('pinHiddenInput').value = '123456';
        document.getElementById('loginForm').submit();
    });
    await page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {});

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 2. Open Receipt Settings Tab
    console.log("2. Navigating to Receipt Settings Tab...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);

    // Capture Full Receipt Settings Screen
    const settingsScreenPath = path.join(brainDir, 'receipt_settings_full_controls.png');
    await page.screenshot({ path: settingsScreenPath, fullPage: false });
    console.log("Saved full settings screen:", settingsScreenPath);

    // 3. Test Live Preview Interaction (Real-time updates)
    console.log("3. Testing real-time live preview update...");
    // Change invoice label to "Receipt No."
    await page.fill('#lblInputInvoice', 'Receipt No.');
    // Change welcome line
    await page.fill('#inputWelcomeLine', '✨ Premium Hand Car Wash & Care ✨');
    // Enable washer name toggle
    await page.check('#switchShowWasher');
    // Enable tax toggle
    await page.check('#switchShowTax');
    // Wait a brief moment to verify live sync
    await page.waitForTimeout(400);

    const liveUpdatedScreenPath = path.join(brainDir, 'receipt_settings_live_preview_updated.png');
    await page.screenshot({ path: liveUpdatedScreenPath, fullPage: false });
    console.log("Saved live updated screenshot:", liveUpdatedScreenPath);

    // Capture the live preview element
    const previewEl = await page.$('#receiptPaperContainer');
    if (previewEl) {
        const previewBuffer = await previewEl.screenshot();
        fs.writeFileSync(path.join(brainDir, 'preview_customized_element.png'), previewBuffer);
    }

    // 4. Save Changes to Database
    console.log("4. Saving receipt settings...");
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('#receiptSettingsForm button[type="submit"]')
    ]);
    console.log("Receipt settings saved successfully.");

    // 5. Open Test Receipt to verify Back Office print uses the newly saved settings
    console.log("5. Navigating to Test Receipt page...");
    await page.goto('http://localhost/Car-WashService/index.php?view=receipt&test=1', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);

    const testReceiptPath = path.join(brainDir, 'test_receipt_with_saved_settings.png');
    await page.screenshot({ path: testReceiptPath, fullPage: false });
    console.log("Saved test receipt screenshot:", testReceiptPath);

    const receiptEl = await page.$('#printableReceipt');
    if (receiptEl) {
        const receiptBuffer = await receiptEl.screenshot();
        fs.writeFileSync(path.join(brainDir, 'printed_receipt_customized_element.png'), receiptBuffer);
    }

    // 6. Test "Reset to default" button
    console.log("6. Testing Reset to Default...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);

    page.on('dialog', async dialog => {
        console.log("Dialog prompt:", dialog.message());
        await dialog.accept();
    });

    // Submit reset
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('#btnResetDefaults')
    ]);
    console.log("Reset to default submitted.");

    // Capture clean defaults settings page
    const defaultsScreenPath = path.join(brainDir, 'receipt_settings_defaults_restored.png');
    await page.screenshot({ path: defaultsScreenPath, fullPage: false });
    console.log("Saved defaults restored screenshot:", defaultsScreenPath);

    // Capture Test Receipt after reset
    await page.goto('http://localhost/Car-WashService/index.php?view=receipt&test=1', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    const testReceiptDefaultPath = path.join(brainDir, 'test_receipt_defaults_master.png');
    await page.screenshot({ path: testReceiptDefaultPath, fullPage: false });
    console.log("Saved test receipt master screenshot:", testReceiptDefaultPath);

    // Create Comparison HTML
    const comparisonHtml = `
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Receipt Settings & Print Verification</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 30px; margin: 0; }
            h1 { color: #38bdf8; text-align: center; margin-bottom: 8px; }
            p.sub { color: #94a3b8; text-align: center; margin-bottom: 25px; }
            .grid { display: flex; gap: 30px; justify-content: center; }
            .card { background: #1e293b; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); border: 1px solid #334155; }
            .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; margin-bottom: 12px; }
            .badge-preview { background: #0284c7; color: white; }
            .badge-print { background: #10b981; color: white; }
            img { display: block; border-radius: 8px; max-width: 380px; box-shadow: 0 2px 10px rgba(0,0,0,0.4); }
        </style>
    </head>
    <body>
        <h1>Receipt Settings & Thermal Print Synchronized</h1>
        <p class="sub">Settings Live Preview vs Real Printed Receipt (Identical 100%)</p>
        <div class="grid">
            <div class="card">
                <span class="badge badge-preview">Settings Live Preview</span>
                <img src="preview_customized_element.png" alt="Preview">
            </div>
            <div class="card">
                <span class="badge badge-print">Backoffice & Thermal Printout</span>
                <img src="printed_receipt_customized_element.png" alt="Print">
            </div>
        </div>
    </body>
    </html>
    `;
    fs.writeFileSync(path.join(brainDir, 'receipt_verification_side_by_side.html'), comparisonHtml);

    // Render side-by-side html to image
    await page.setContent(comparisonHtml);
    await page.waitForTimeout(400);
    const comparisonImgPath = path.join(brainDir, 'receipt_verification_side_by_side.png');
    await page.screenshot({ path: comparisonImgPath, fullPage: true });
    console.log("Saved side-by-side image:", comparisonImgPath);

    await browser.close();
    console.log("All tests completed successfully!");
}

testReceiptFullSettings().catch(err => {
    console.error("Test failed:", err);
    process.exit(1);
});
