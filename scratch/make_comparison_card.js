const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const imgGoal = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794882276028_fb9d6fdc.png').toString('base64');
    const imgProblem = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/.user_uploaded/media_1794885459298_e0b87505.png').toString('base64');
    const imgFixedKm = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_user_pill_km.png').toString('base64');
    const imgFixedEn = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_user_pill_en.png').toString('base64');
    const imgFixedOpen = fs.readFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_user_pill_open.png').toString('base64');

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 960, height: 620 } });

    await page.setContent(`
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
                body {
                    background: #0f172a;
                    color: #f8fafc;
                    font-family: 'Inter', system-ui, sans-serif;
                    padding: 30px;
                    margin: 0;
                    box-sizing: border-box;
                }
                .grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 20px;
                    margin-top: 20px;
                }
                .card {
                    background: #1e293b;
                    border-radius: 12px;
                    padding: 20px;
                    border: 1px solid #334155;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    text-align: center;
                }
                .tag {
                    font-size: 11px;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    padding: 4px 10px;
                    border-radius: 9999px;
                    margin-bottom: 14px;
                }
                .tag-target { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
                .tag-problem { background: rgba(239, 68, 68, 0.2); color: #f87171; }
                .tag-fixed { background: rgba(34, 197, 94, 0.2); color: #4ade80; }

                .crop-view {
                    width: 200px;
                    height: 56px;
                    border-radius: 8px;
                    overflow: hidden;
                    position: relative;
                    background: #fff;
                    border: 2px solid rgba(255,255,255,0.1);
                    margin: 10px 0;
                }
                .crop-view.ref img {
                    position: absolute;
                    top: -12px;
                    left: -295px;
                    width: 500px;
                }
                .crop-view.prob img {
                    position: absolute;
                    top: -10px;
                    left: -290px;
                    width: 500px;
                }

                .pill-preview {
                    margin: 12px 0 6px 0;
                    padding: 2px;
                    background: #fff;
                    border-radius: 8px;
                    display: inline-flex;
                }
                .pill-preview img {
                    height: 31px;
                    display: block;
                    border-radius: 8px;
                }
                .desc {
                    font-size: 12px;
                    color: #94a3b8;
                    margin-top: 10px;
                    line-height: 1.4;
                }
            </style>
        </head>
        <body>
            <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #fff;">User Profile Chevron (fa-chevron-down) Fix Verification</h2>
            <p style="margin: 6px 0 0 0; font-size: 13px; color: #94a3b8;">Comparison of original reference target, broken oversized state, and verified refined implementation.</p>

            <div class="grid">
                <!-- Col 1: Goal -->
                <div class="card">
                    <span class="tag tag-target">Reference Target</span>
                    <div class="crop-view ref">
                        <img src="data:image/png;base64,${imgGoal}">
                    </div>
                    <div class="desc">
                        Original design: Delicate, small chevron right next to the role name on Line 2.
                    </div>
                </div>

                <!-- Col 2: Problem -->
                <div class="card" style="border-color: rgba(239, 68, 68, 0.4);">
                    <span class="tag tag-problem">Broken (Reported)</span>
                    <div class="crop-view prob">
                        <img src="data:image/png;base64,${imgProblem}">
                    </div>
                    <div class="desc">
                        Overriding <code>font-size: 15px !important</code> blew up the chevron into a giant, distorted <code>V</code> shape.
                    </div>
                </div>

                <!-- Col 3: Fixed -->
                <div class="card" style="border-color: rgba(34, 197, 94, 0.4);">
                    <span class="tag tag-fixed">Fixed & Verified</span>
                    <div class="pill-preview">
                        <img src="data:image/png;base64,${imgFixedKm}">
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 6px; align-items: center;">
                        <span style="font-size: 10px; color: #94a3b8; font-weight: 600;">EN:</span>
                        <img src="data:image/png;base64,${imgFixedEn}" style="height: 24px; border-radius: 4px;">
                        <span style="font-size: 10px; color: #94a3b8; font-weight: 600; margin-left: 2px;">Open:</span>
                        <img src="data:image/png;base64,${imgFixedOpen}" style="height: 24px; border-radius: 4px;">
                    </div>
                    <div class="desc">
                        Clean 7.5px font-size, subtle <code>#64748b</code> color, aligned baseline, with 180° rotation on dropdown toggle.
                    </div>
                </div>
            </div>
        </body>
        </html>
    `);

    await page.waitForTimeout(400);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/final_chevron_comparison.png' });
    await browser.close();
    console.log('Saved final_chevron_comparison.png');
})();
