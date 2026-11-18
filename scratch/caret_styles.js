const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });
    
    // Let's create an HTML page with different chevron styles:
    // 1. Current: FontAwesome fa-solid fa-chevron-down
    // 2. Small subtle caret: font-size 9px, color #94a3b8
    // 3. SVG sleek chevron
    // 4. fa-angle-down
    await page.setContent(`
        <html>
        <head>
            <link rel="stylesheet" href="file:///c:/xampp/htdocs/Car-WashService/pos/css/font-awesome.min.css">
            <style>
                body { font-family: 'Inter', sans-serif; padding: 30px; background: #f8fafc; }
                .sample-row { display: flex; gap: 20px; align-items: center; margin-bottom: 25px; }
                .pill {
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    height: 31px;
                    padding: 0 8px;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    font-size: 11.5px;
                }
                .pill .user-info { display: flex; flex-direction: column; line-height: 1.1; }
                .pill .name { font-weight: 700; color: #0f172a; font-size: 11.5px; }
                .pill .role { font-size: 9.5px; color: #64748b; }
            </style>
        </head>
        <body>
            <h2>Current vs Improved Caret Styles</h2>
            
            <div class="sample-row">
                <div><strong>Current (Overriding 15px font-size):</strong></div>
                <div class="pill">
                    <i class="fa-solid fa-circle-user" style="color: #64748b; font-size: 15px;"></i>
                    <div class="user-info">
                        <span class="name">Shop Owner</span>
                        <span class="role">Shop Owner <i class="fa-solid fa-chevron-down" style="font-size: 15px; color: #64748b;"></i></span>
                    </div>
                </div>
            </div>

            <div class="sample-row">
                <div><strong>Option 1: Small Subtle Caret (8px, muted, vertically centered on right):</strong></div>
                <div class="pill">
                    <i class="fa-solid fa-circle-user" style="color: #64748b; font-size: 15px;"></i>
                    <div class="user-info">
                        <span class="name">Shop Owner</span>
                        <span class="role">Shop Owner</span>
                    </div>
                    <i class="fa-solid fa-chevron-down" style="font-size: 9px; color: #94a3b8; margin-left: 2px;"></i>
                </div>
            </div>

            <div class="sample-row">
                <div><strong>Option 2: Ultra-sleek SVG Caret (matching GotPOS / Apple):</strong></div>
                <div class="pill">
                    <i class="fa-solid fa-circle-user" style="color: #64748b; font-size: 15px;"></i>
                    <div class="user-info">
                        <span class="name">Shop Owner</span>
                        <span class="role">Shop Owner</span>
                    </div>
                    <svg width="10" height="6" viewBox="0 0 10 6" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-left: 2px;">
                        <path d="M1 1L5 5L9 1" stroke="#94A3B8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>

            <div class="sample-row">
                <div><strong>Option 3: fa-angle-down (narrower stroke):</strong></div>
                <div class="pill">
                    <i class="fa-solid fa-circle-user" style="color: #64748b; font-size: 15px;"></i>
                    <div class="user-info">
                        <span class="name">Shop Owner</span>
                        <span class="role">Shop Owner</span>
                    </div>
                    <i class="fa-solid fa-angle-down" style="font-size: 11px; color: #94a3b8; margin-left: 2px;"></i>
                </div>
            </div>
        </body>
        </html>
    `);
    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/caret_styles_preview.png' });
    await browser.close();
    console.log('Saved caret_styles_preview.png');
})();
