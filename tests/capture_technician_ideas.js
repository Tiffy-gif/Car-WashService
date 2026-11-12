const { chromium } = require('playwright');
const path = require('path');

const ARTIFACT_DIR = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

async function capture() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  
  const files = [
    { name: 'tech_idea1', url: 'http://localhost/Car-WashService/pos/preview_technician_idea1.html' },
    { name: 'tech_idea2', url: 'http://localhost/Car-WashService/pos/preview_technician_idea2.html' },
    { name: 'tech_idea3', url: 'http://localhost/Car-WashService/pos/preview_technician_idea3.html' },
    { name: 'tech_showcase', url: 'http://localhost/Car-WashService/pos/preview_technician_showcase.html' },
  ];

  for (const item of files) {
    // Desktop Viewport (1440x900)
    const contextDesk = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const pageDesk = await contextDesk.newPage();
    await pageDesk.goto(item.url, { waitUntil: 'networkidle' });
    await pageDesk.waitForTimeout(500);
    const deskPath = path.join(ARTIFACT_DIR, `${item.name}_desktop.png`);
    await pageDesk.screenshot({ path: deskPath, fullPage: false });
    console.log(`Saved desktop: ${deskPath}`);
    await contextDesk.close();

    // Tablet Viewport (1024x768)
    const contextTab = await browser.newContext({ viewport: { width: 1024, height: 768 } });
    const pageTab = await contextTab.newPage();
    await pageTab.goto(item.url, { waitUntil: 'networkidle' });
    await pageTab.waitForTimeout(500);
    const tabPath = path.join(ARTIFACT_DIR, `${item.name}_tablet.png`);
    await pageTab.screenshot({ path: tabPath, fullPage: false });
    console.log(`Saved tablet: ${tabPath}`);
    await contextTab.close();
  }

  await browser.close();
  console.log('All screenshots captured successfully.');
}

capture().catch(err => {
  console.error(err);
  process.exit(1);
});
