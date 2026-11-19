const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

async function captureComparison() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();

    console.log("Logging in as owner...");
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.evaluate(() => {
        document.getElementById('pinHiddenInput').value = '123456';
        document.getElementById('loginForm').submit();
    });
    await page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {});

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 1. Capture Settings Receipt Tab with Preview
    console.log("1. Capturing Receipt Settings with live preview...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);

    const previewSettingsPath = path.join(brainDir, 'photo_1_settings_receipt_preview.png');
    await page.screenshot({ path: previewSettingsPath, fullPage: false });
    console.log("Saved photo 1:", previewSettingsPath);

    // Also get the preview card element screenshot
    const previewEl = await page.$('#receiptPaperContainer');
    let previewCardBuffer = null;
    if (previewEl) {
        previewCardBuffer = await previewEl.screenshot();
        fs.writeFileSync(path.join(brainDir, 'preview_element_only.png'), previewCardBuffer);
    }

    // 2. Capture Backoffice Actual Printed Receipt screen
    console.log("2. Capturing Backoffice 80mm Printed Receipt...");
    await page.goto('http://localhost/Car-WashService/index.php?view=receipt&test=1', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);

    const actualReceiptPath = path.join(brainDir, 'photo_2_backoffice_printed_receipt.png');
    await page.screenshot({ path: actualReceiptPath, fullPage: false });
    console.log("Saved photo 2:", actualReceiptPath);

    const receiptEl = await page.$('#printableReceipt');
    let receiptCardBuffer = null;
    if (receiptEl) {
        receiptCardBuffer = await receiptEl.screenshot();
        fs.writeFileSync(path.join(brainDir, 'receipt_element_only.png'), receiptCardBuffer);
    }

    // 3. Create Side-by-Side Comparison HTML Page
    console.log("3. Rendering Side-by-Side Comparison...");
    const sideBySideHtml = `
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <link rel="stylesheet" href="http://localhost/Car-WashService/public/css/font-awesome.min.css">
        <link rel="stylesheet" href="http://localhost/Car-WashService/public/css/receipt-unified.css">
        <style>
            body {
                background: #0f172a;
                color: #ffffff;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                margin: 0;
                padding: 30px;
                display: flex;
                flex-direction: column;
                align-items: center;
            }
            .header-banner {
                text-align: center;
                margin-bottom: 24px;
            }
            .header-banner h1 {
                font-size: 22px;
                margin: 0 0 6px 0;
                color: #38bdf8;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
            }
            .header-banner p {
                font-size: 14px;
                color: #94a3b8;
                margin: 0;
            }
            .comparison-grid {
                display: flex;
                gap: 40px;
                justify-content: center;
                align-items: flex-start;
            }
            .column-wrap {
                display: flex;
                flex-direction: column;
                align-items: center;
            }
            .badge-title {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #1e293b;
                border: 1px solid #334155;
                padding: 8px 16px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 13px;
                margin-bottom: 14px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            }
            .badge-preview { color: #38bdf8; border-color: #0284c7; }
            .badge-printed { color: #4ade80; border-color: #16a34a; }
            .card-container {
                box-shadow: 0 8px 30px rgba(0,0,0,0.4);
                border-radius: 6px;
                overflow: hidden;
            }
        </style>
    </head>
    <body>
        <div class="header-banner">
            <h1><i class="fa-solid fa-code-compare"></i> Side-by-Side Customer Receipt Verification</h1>
            <p>Unified Pure Black & Slightly Bold 80mm Drawing: Settings Preview (Left) vs. Printed Receipt (Right)</p>
        </div>
        <div class="comparison-grid">
            <div class="column-wrap">
                <div class="badge-title badge-preview">
                    <i class="fa-solid fa-eye"></i> 1. LIVE PREVIEW (Settings Tab)
                </div>
                <div class="card-container">
                    <img src="preview_element_only.png" style="display: block; max-width: 310px;">
                </div>
            </div>
            <div class="column-wrap">
                <div class="badge-title badge-printed">
                    <i class="fa-solid fa-print"></i> 2. SYSTEM RECEIPT (Backoffice & Test)
                </div>
                <div class="card-container">
                    <img src="receipt_element_only.png" style="display: block; max-width: 310px;">
                </div>
            </div>
            <div class="column-wrap">
                <div class="badge-title" style="color: #fbbf24; border-color: #d97706;">
                    <i class="fa-solid fa-receipt"></i> 3. REAL PHYSICAL PRINTOUT (Photo)
                </div>
                <div class="card-container">
                    <img src=".user_uploaded/media_1794983557448_ec73e6b3.jpg" style="display: block; max-width: 310px; border-radius: 4px;">
                </div>
            </div>
        </div>
    </body>
    </html>
    `;

    const sideBySidePath = path.join(brainDir, 'side_by_side_render.html');
    fs.writeFileSync(sideBySidePath, sideBySideHtml);

    const compPage = await context.newPage();
    await compPage.setViewportSize({ width: 1180, height: 1100 });
    await compPage.goto(`file://${sideBySidePath.replace(/\\/g, '/')}`, { waitUntil: 'load' });
    await compPage.waitForTimeout(400);

    const finalSideBySidePath = path.join(brainDir, 'photo_side_by_side_comparison.png');
    await compPage.screenshot({ path: finalSideBySidePath, fullPage: true });
    console.log("Saved photo side-by-side:", finalSideBySidePath);

    await browser.close();
    console.log("All photos taken successfully!");
}

captureComparison().catch(console.error);
