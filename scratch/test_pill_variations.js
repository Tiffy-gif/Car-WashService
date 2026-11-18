const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const imgGoal = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png').toString('base64');
    const imgCurrent = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png').toString('base64');

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1000, height: 900 } });

    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="stylesheet" href="file:///c:/xampp/htdocs/Car-WashService/pos/css/font-awesome.min.css">
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Kantumruy+Pro:wght@400;600;700&display=swap');
                body {
                    background: #f1f5f9;
                    font-family: 'Inter', 'Kantumruy Pro', sans-serif;
                    padding: 30px;
                    color: #0f172a;
                }
                .section {
                    background: #fff;
                    border-radius: 12px;
                    padding: 20px;
                    margin-bottom: 24px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                }
                .row {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                    margin-top: 10px;
                }
                
                /* Base user pill style */
                .user-pill {
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    height: 31px;
                    padding: 0 8px;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    font-size: 11.5px;
                    cursor: pointer;
                }
                .user-avatar-icon {
                    color: #64748b;
                    font-size: 15px;
                }
                .user-info {
                    display: flex;
                    flex-direction: column;
                    line-height: 1.1;
                    text-align: left;
                }
                .user-fullname {
                    font-weight: 700;
                    color: #0f172a;
                    font-size: 11.5px;
                    line-height: 1.1;
                }
                .user-role-badge {
                    font-size: 9.5px;
                    color: #64748b;
                    line-height: 1.1;
                    display: inline-flex;
                    align-items: center;
                }
                
                /* Zoom containers for reference */
                .crop-ref {
                    width: 250px;
                    height: 50px;
                    overflow: hidden;
                    border: 2px solid #10b981;
                    position: relative;
                    border-radius: 6px;
                }
                .crop-ref img {
                    position: absolute;
                    top: -10px;
                    left: -480px;
                    width: 900px;
                }
                .crop-cur {
                    width: 250px;
                    height: 50px;
                    overflow: hidden;
                    border: 2px solid #ef4444;
                    position: relative;
                    border-radius: 6px;
                }
                .crop-cur img {
                    position: absolute;
                    top: -10px;
                    left: -480px;
                    width: 900px;
                }
            </style>
        </head>
        <body>
            <div class="section">
                <h3>Original Reference vs Broken Current</h3>
                <div class="row">
                    <div>
                        <div style="font-size: 12px; font-weight: 600; color: #10b981; margin-bottom: 4px;">Goal Target (from user):</div>
                        <div class="crop-ref"><img src="data:image/png;base64,${imgGoal}"></div>
                    </div>
                    <div>
                        <div style="font-size: 12px; font-weight: 600; color: #ef4444; margin-bottom: 4px;">Current Broken (red box):</div>
                        <div class="crop-cur"><img src="data:image/png;base64,${imgCurrent}"></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>Variations to Match Goal</h3>
                
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">
                        Variation 1: FontAwesome fa-chevron-down inside role badge (Khmer), sized 7.5px, color #64748b
                    </div>
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">ម្ចាស់ហាង <i class="fa-solid fa-chevron-down" style="font-size: 7.5px; color: #64748b; margin-left: 3px; transform: translateY(-0.5px);"></i></span>
                        </div>
                    </div>
                    &nbsp;&nbsp;
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">Shop Owner <i class="fa-solid fa-chevron-down" style="font-size: 7.5px; color: #64748b; margin-left: 3px; transform: translateY(-0.5px);"></i></span>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">
                        Variation 2: Clean SVG Chevron inside role badge (exact 8x5 stroke), matching Goal pixel-for-pixel
                    </div>
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">ម្ចាស់ហាង 
                                <svg width="8" height="5" viewBox="0 0 8 5" fill="none" style="margin-left: 3px; vertical-align: middle;">
                                    <path d="M1 1.25L4 3.75L7 1.25" stroke="#64748B" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                        </div>
                    </div>
                    &nbsp;&nbsp;
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">Shop Owner 
                                <svg width="8" height="5" viewBox="0 0 8 5" fill="none" style="margin-left: 3px; vertical-align: middle;">
                                    <path d="M1 1.25L4 3.75L7 1.25" stroke="#64748B" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">
                        Variation 3: FontAwesome fa-angle-down (lighter stroke weight than chevron)
                    </div>
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">ម្ចាស់ហាង <i class="fa-solid fa-angle-down" style="font-size: 9px; color: #64748b; margin-left: 3px;"></i></span>
                        </div>
                    </div>
                    &nbsp;&nbsp;
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">Shop Owner <i class="fa-solid fa-angle-down" style="font-size: 9px; color: #64748b; margin-left: 3px;"></i></span>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">
                        Variation 4: Vertically centered on the right of the entire pill (Pill-level Caret)
                    </div>
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">ម្ចាស់ហាង</span>
                        </div>
                        <i class="fa-solid fa-chevron-down" style="font-size: 8px; color: #94a3b8; margin-left: 4px;"></i>
                    </div>
                    &nbsp;&nbsp;
                    <div class="user-pill">
                        <i class="fa-solid fa-circle-user user-avatar-icon"></i>
                        <div class="user-info">
                            <span class="user-fullname">Shop Owner</span>
                            <span class="user-role-badge">Shop Owner</span>
                        </div>
                        <i class="fa-solid fa-chevron-down" style="font-size: 8px; color: #94a3b8; margin-left: 4px;"></i>
                    </div>
                </div>

            </div>
        </body>
        </html>
    `);

    await page.waitForTimeout(500);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/test_pill_variations.png' });
    await browser.close();
    console.log('Saved test_pill_variations.png');
})();
