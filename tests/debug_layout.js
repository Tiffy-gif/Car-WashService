const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
  await page.fill('#username', 'visal');
  await page.fill('#pin', '123456');
  await page.click('button[type="submit"]');
  await page.waitForNavigation();
  await page.waitForTimeout(500);
  await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
  await page.waitForTimeout(2000);

  const stats = await page.evaluate(() => {
    const sel = (s) => {
      const el = document.querySelector(s);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const cs = window.getComputedStyle(el);
      return {
        tag: el.tagName,
        classes: el.className,
        rect: { x: Math.round(r.x), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height) },
        display: cs.display,
        position: cs.position,
        visibility: cs.visibility,
        gridArea: cs.gridArea,
        childCount: el.children.length
      };
    };
    return {
      workspace: sel('.pos-workspace'),
      catalogCol: sel('.pos-catalog-column'),
      customerIntake: sel('.pos-customer-intake-card'),
      vehicleSwitcher: sel('.vehicle-type-switcher'),
      catalogBody: sel('.pos-catalog-body'),
      catalogGridWrap: sel('.catalog-grid-wrap'),
      catalogGrid: sel('#catalogGrid'),
      categorySidebar: sel('.category-vertical-sidebar'),
      cartColumn: sel('.pos-cart-column'),
      bottomDock: sel('.pos-bottom-dock')
    };
  });
  console.log(JSON.stringify(stats, null, 2));
  await browser.close();
})();

