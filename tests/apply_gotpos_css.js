const fs = require('fs');

// 1. Update pos/js/app.js
let js = fs.readFileSync('pos/js/app.js', 'utf8');

// Backup app.js
fs.writeFileSync('pos/js/app.js.bak_gotpos', js);

// Add openPaymentWithMethod and toggleFullscreen if not present
if (!js.includes('openPaymentWithMethod(')) {
    const target = 'static openPaymentModal() {';
    const addition = `static openPaymentWithMethod(method) {
        if (!this.cart || this.cart.length === 0) {
            this.showCartToast('Cart is empty. Add services or products first.');
            return;
        }
        this.openPaymentModal();
        if (method) {
            setTimeout(() => {
                this.switchPaymentMethod(method);
            }, 60);
        }
    }

    static toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(() => {});
        } else {
            document.exitFullscreen().catch(() => {});
        }
    }

    static openPaymentModal() {`;
    js = js.replace(target, addition);
    console.log('Added openPaymentWithMethod and toggleFullscreen to pos/js/app.js');
}

// In startLiveClock, update gotposLiveDate and gotposLiveTime
if (!js.includes('gotposLiveDate')) {
    const clockTarget = "dateEl.textContent = dateStr;\r\n                timeEl.textContent = timeStr;";
    const altClockTarget = "dateEl.textContent = dateStr;\n                timeEl.textContent = timeStr;";
    const clockAddition = `dateEl.textContent = dateStr;
                timeEl.textContent = timeStr;
                const gotDateEl = document.getElementById('gotposLiveDate');
                const gotTimeEl = document.getElementById('gotposLiveTime');
                if (gotDateEl) {
                    try {
                        gotDateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
                    } catch(e) { gotDateEl.textContent = now.toDateString(); }
                }
                if (gotTimeEl) {
                    try {
                        gotTimeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
                    } catch(e) { gotTimeEl.textContent = now.toTimeString().slice(0,5); }
                }`;
    if (js.includes(clockTarget)) {
        js = js.replace(clockTarget, clockAddition);
        console.log('Updated startLiveClock in pos/js/app.js (CRLF)');
    } else if (js.includes(altClockTarget)) {
        js = js.replace(altClockTarget, clockAddition);
        console.log('Updated startLiveClock in pos/js/app.js (LF)');
    } else {
        console.warn('Could not locate startLiveClock snippet in pos/js/app.js');
    }
}

// In renderCart, update gotpos customer & items & in-cart card indicator
if (!js.includes('gotposItemCount')) {
    const cartTarget = "const countBadge = document.getElementById('cartCountBadge');";
    const cartAddition = `const countBadge = document.getElementById('cartCountBadge');
        const gotCountBadge = document.getElementById('gotposItemCount');`;
    js = js.replace(cartTarget, cartAddition);

    const badgeUpdateTarget = "countBadge.style.display = totalQty > 0 ? 'inline-flex' : 'none';\r\n        }";
    const altBadgeUpdateTarget = "countBadge.style.display = totalQty > 0 ? 'inline-flex' : 'none';\n        }";
    const badgeAddition = `countBadge.style.display = totalQty > 0 ? 'inline-flex' : 'none';
            if (gotCountBadge) gotCountBadge.textContent = totalQty;
        }`;
    if (js.includes(badgeUpdateTarget)) {
        js = js.replace(badgeUpdateTarget, badgeAddition);
    } else if (js.includes(altBadgeUpdateTarget)) {
        js = js.replace(altBadgeUpdateTarget, badgeAddition);
    }

    // Customer Card sync at end of calculateTotals in renderCart
    const totalsSyncTarget = "if (checkoutAmount) {\r\n            checkoutAmount.textContent = `$${totals.totalUsd.toFixed(2)}`;";
    const altTotalsSyncTarget = "if (checkoutAmount) {\n            checkoutAmount.textContent = `$${totals.totalUsd.toFixed(2)}`;";
    const customerSyncAddition = `// Sync GOTPOS Customer & In-Cart badges
        const nameInp = document.getElementById('custNameInput');
        const plateInp = document.getElementById('custPlateInput');
        const custNameEl = document.getElementById('gotposCustNameDisplay');
        const custTierEl = document.getElementById('gotposCustTierDisplay');
        const custVal = nameInp?.value?.trim() || (this.currentCustomer?.name && this.currentCustomer.name !== 'Customer' ? this.currentCustomer.name : 'Jonathan Michael');
        const plateVal = plateInp?.value?.trim()?.toUpperCase() || this.currentCustomer?.plate || '';
        if (custNameEl) custNameEl.textContent = custVal;
        if (custTierEl) {
            custTierEl.textContent = plateVal ? \`\${plateVal} • Platinum Member • 2,450 Points\` : 'Platinum Member • 2,450 Points';
        }

        // Toggle in-cart class on catalog cards
        const inCartSvcIds = new Set(this.cart.filter(it => it.type === 'service').map(it => String(it.id)));
        const inCartProdIds = new Set(this.cart.filter(it => it.type === 'product').map(it => String(it.id)));
        document.querySelectorAll('#catalogGrid .pos-item-card').forEach(card => {
            const svcId = card.getAttribute('data-svc-id');
            const prodId = card.getAttribute('data-prod-id');
            const isInCart = (svcId && inCartSvcIds.has(String(svcId))) || (prodId && inCartProdIds.has(String(prodId)));
            if (isInCart) {
                card.classList.add('in-cart');
            } else {
                card.classList.remove('in-cart');
            }
        });

        if (checkoutAmount) {
            checkoutAmount.textContent = \`$\${totals.totalUsd.toFixed(2)}\`;`;
    if (js.includes(totalsSyncTarget)) {
        js = js.replace(totalsSyncTarget, customerSyncAddition);
        console.log('Added Customer sync & in-cart card indicator to renderCart (CRLF)');
    } else if (js.includes(altTotalsSyncTarget)) {
        js = js.replace(altTotalsSyncTarget, customerSyncAddition);
        console.log('Added Customer sync & in-cart card indicator to renderCart (LF)');
    }
}

fs.writeFileSync('pos/js/app.js', js);
console.log('Successfully updated pos/js/app.js!');

// 2. Append GOTPOS CSS to pos/css/app.css
let css = fs.readFileSync('pos/css/app.css', 'utf8');

const gotposThemeMarker = '/* ==========================================================================\r\n   GOTPOS THEME SYSTEM SPECIFICATIONS\r\n   ========================================================================== */';
const altMarker = '/* ==========================================================================\n   GOTPOS THEME SYSTEM SPECIFICATIONS\n   ========================================================================== */';

if (css.includes('GOTPOS THEME SYSTEM SPECIFICATIONS')) {
    // Cut off existing GOTPOS block and replace with full updated block
    const cutIdx = css.indexOf('/* ==========================================================================\r\n   GOTPOS THEME');
    const cutIdxAlt = css.indexOf('/* ==========================================================================\n   GOTPOS THEME');
    const finalCut = cutIdx !== -1 ? cutIdx : cutIdxAlt;
    css = css.substring(0, finalCut);
}

const gotposCss = `
/* ==========================================================================
   GOTPOS THEME SYSTEM SPECIFICATIONS
   Clean, Modern Retail POS Screen (https://srbthemes.kcubeinfotech.com/gotpos/html/ltr/pos.html)
   ========================================================================== */

:root {
    --gotpos-primary: #ff762d;
    --gotpos-primary-hover: #ea580c;
    --gotpos-primary-subtle: #fff7ed;
    --gotpos-primary-border: #fed7aa;
    --gotpos-dark: #0f2942;
    --gotpos-success: #10b981;
    --gotpos-success-subtle: #e8f8f0;
    --gotpos-text-main: #0f172a;
    --gotpos-text-title: #1e293b;
    --gotpos-text-muted: #64748b;
    --gotpos-text-subtle: #94a3b8;
    --gotpos-bg: #f5f6fa;
    --gotpos-card-bg: #ffffff;
    --gotpos-media-bg: #f4f5f7;
    --gotpos-border: #eaedf1;
    --gotpos-radius: 12px;
    --gotpos-radius-sm: 8px;
    --gotpos-radius-pill: 20px;
}

[data-theme="dark"] {
    --gotpos-bg: #0b111e;
    --gotpos-card-bg: #131d2e;
    --gotpos-media-bg: #192438;
    --gotpos-border: #1e293b;
    --gotpos-text-main: #f8fafc;
    --gotpos-text-title: #f1f5f9;
    --gotpos-text-muted: #94a3b8;
    --gotpos-text-subtle: #64748b;
    --gotpos-primary-subtle: rgba(255, 118, 45, 0.15);
    --gotpos-primary-border: rgba(255, 118, 45, 0.35);
}

/* Base Body & Workspace */
body {
    background-color: var(--gotpos-bg) !important;
    font-family: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}

#viewPos {
    background-color: var(--gotpos-bg) !important;
}

.pos-workspace {
    display: flex !important;
    flex-direction: row !important;
    gap: 16px !important;
    padding: 12px 18px !important;
    background: var(--gotpos-bg) !important;
    height: calc(100vh - 64px) !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}

/* Top Navbar GOTPOS Styling */
.brand-title h1 {
    font-size: 19px !important;
    font-weight: 800 !important;
    color: var(--gotpos-text-main) !important;
    letter-spacing: -0.3px !important;
}
.gotpos-brand-orange {
    color: var(--gotpos-primary) !important;
}
.gotpos-brand-sub {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: var(--gotpos-text-subtle) !important;
    margin-left: 4px !important;
}

.gotpos-top-pills {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-left: 14px !important;
}
.gotpos-pill-box {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius-pill) !important;
    padding: 4px 12px !important;
    font-size: 11.5px !important;
    color: var(--gotpos-text-muted) !important;
    font-weight: 500 !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
}
.gotpos-pill-box i {
    color: var(--gotpos-text-subtle) !important;
    font-size: 11px !important;
}

.gotpos-open-order-chip {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: var(--gotpos-success-subtle) !important;
    border: 1px solid rgba(16, 185, 129, 0.3) !important;
    border-radius: var(--gotpos-radius-pill) !important;
    padding: 5px 12px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    color: var(--gotpos-success) !important;
}
.gotpos-open-order-chip .dot-live {
    width: 7px !important;
    height: 7px !important;
    border-radius: 50% !important;
    background: var(--gotpos-success) !important;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25) !important;
}
.btn-gotpos-dash {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: var(--gotpos-dark) !important;
    border: 1px solid var(--gotpos-dark) !important;
    color: #ffffff !important;
    border-radius: var(--gotpos-radius-pill) !important;
    padding: 5px 14px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    margin-left: 6px !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08) !important;
}

/* POS Left/Center Column */
.pos-catalog-column {
    flex: 1 1 0% !important;
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 12px !important;
    height: 100% !important;
    overflow: hidden !important;
}

/* Customer Intake Header */
.pos-customer-intake-card {
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius) !important;
    padding: 8px 12px !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
    flex-shrink: 0 !important;
}
.intake-inputs-row {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    width: 100% !important;
}
.intake-field {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 2px !important;
}
.intake-label {
    font-size: 11px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-muted) !important;
    display: flex !important;
    align-items: center !important;
    gap: 4px !important;
}
.intake-input {
    height: 34px !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius-sm) !important;
    padding: 0 10px !important;
    font-size: 12.5px !important;
    background: var(--gotpos-card-bg) !important;
    color: var(--gotpos-text-main) !important;
}
.intake-plate-field .intake-input {
    font-weight: 700 !important;
    letter-spacing: 0.5px !important;
    text-transform: uppercase !important;
}
.btn-intake-action#btnResetCustomer {
    height: 34px !important;
    padding: 0 12px !important;
    border-radius: var(--gotpos-radius-sm) !important;
    border: 1px solid var(--gotpos-border) !important;
    background: var(--gotpos-card-bg) !important;
    color: var(--gotpos-text-muted) !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
}
.btn-intake-action#btnOpenNewCustomerModal,
#btnOpenNewCustomerModal {
    height: 34px !important;
    padding: 0 14px !important;
    border-radius: var(--gotpos-radius-sm) !important;
    border: 1px solid var(--gotpos-primary) !important;
    background: var(--gotpos-primary) !important;
    color: #ffffff !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    box-shadow: 0 2px 6px rgba(255, 118, 45, 0.25) !important;
}

/* GOTPOS Catalog Toolbar (Filter + Vehicles + Search) */
.gotpos-catalog-toolbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    flex-shrink: 0 !important;
}
.gotpos-toolbar-left {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.btn-gotpos-filter {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius-sm) !important;
    padding: 7px 12px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-muted) !important;
    cursor: pointer !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
}
.gotpos-vehicle-switcher {
    display: flex !important;
    gap: 6px !important;
}
.gotpos-vehicle-switcher .vehicle-btn {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 7px 14px !important;
    border-radius: var(--gotpos-radius-sm) !important;
    font-size: 12px !important;
    font-weight: 500 !important;
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    color: var(--gotpos-text-muted) !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
}
.gotpos-vehicle-switcher .vehicle-btn.active {
    background: var(--gotpos-primary) !important;
    border-color: var(--gotpos-primary) !important;
    color: #ffffff !important;
    font-weight: 600 !important;
    box-shadow: 0 2px 6px rgba(255, 118, 45, 0.25) !important;
}
.gotpos-search-wrapper {
    display: flex !important;
    align-items: center !important;
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius-sm) !important;
    padding: 0 10px !important;
    height: 36px !important;
    min-width: 250px !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
}
.gotpos-search-icon {
    color: var(--gotpos-text-subtle) !important;
    font-size: 13px !important;
    margin-right: 8px !important;
}
.gotpos-search-input {
    border: none !important;
    outline: none !important;
    background: transparent !important;
    font-size: 12.5px !important;
    color: var(--gotpos-text-main) !important;
    flex: 1 !important;
}
.gotpos-barcode-btn {
    border: none !important;
    background: transparent !important;
    color: var(--gotpos-text-muted) !important;
    cursor: pointer !important;
    padding: 4px !important;
    font-size: 14px !important;
}

/* Two Columns: Category Rail + Catalog Grid */
.pos-catalog-body {
    display: flex !important;
    flex-direction: row !important;
    gap: 12px !important;
    flex: 1 1 0% !important;
    height: 100% !important;
    overflow: hidden !important;
}

/* Category Vertical Sidebar (Left 80px) */
.category-vertical-sidebar {
    width: 80px !important;
    min-width: 80px !important;
    max-width: 80px !important;
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius) !important;
    padding: 8px 6px !important;
    order: -1 !important;
    overflow-y: auto !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
    flex-shrink: 0 !important;
}
.category-vertical-sidebar .category-list-scroll {
    display: flex !important;
    flex-direction: column !important;
    gap: 6px !important;
}
.category-vertical-sidebar .category-pill,
.category-pill {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    padding: 8px 4px !important;
    border-radius: var(--gotpos-radius-sm) !important;
    background: #f8fafc !important;
    border: 1.5px solid transparent !important;
    color: var(--gotpos-text-muted) !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
}
[data-theme="dark"] .category-pill {
    background: #192438 !important;
}
.category-vertical-sidebar .category-pill.active,
.category-pill.active {
    background: var(--gotpos-card-bg) !important;
    border-color: var(--gotpos-primary) !important;
    color: var(--gotpos-primary) !important;
    box-shadow: 0 2px 8px rgba(255, 118, 45, 0.15) !important;
}
.category-pill i {
    font-size: 17px !important;
    margin-bottom: 5px !important;
}
.category-pill .pill-text {
    font-size: 10.5px !important;
    font-weight: 600 !important;
    text-align: center !important;
    line-height: 1.2 !important;
}

/* Center Catalog Grid (4 Columns) */
.catalog-grid-wrap {
    flex: 1 1 0% !important;
    height: 100% !important;
    overflow-y: auto !important;
    padding-right: 2px !important;
}
.catalog-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 12px !important;
    padding-bottom: 24px !important;
}
@media (max-width: 1300px) {
    .catalog-grid {
        grid-template-columns: repeat(3, 1fr) !important;
    }
}

/* Product & Service Cards */
.pos-item-card {
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius) !important;
    padding: 10px !important;
    display: flex !important;
    flex-direction: column !important;
    position: relative !important;
    cursor: pointer !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
    transition: all 0.2s ease !important;
}
.pos-item-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 14px rgba(0,0,0,0.06) !important;
    border-color: #cbd5e1 !important;
}
.item-card-media-box {
    background: var(--gotpos-media-bg) !important;
    border-radius: var(--gotpos-radius-sm) !important;
    height: 145px !important;
    width: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    overflow: hidden !important;
    position: relative !important;
}
.item-card-img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
    padding: 10px !important;
}
.item-card-fallback-icon i {
    font-size: 42px !important;
    color: var(--gotpos-primary) !important;
    opacity: 0.85 !important;
}
.card-tag-type {
    position: absolute !important;
    top: 16px !important;
    left: 16px !important;
    font-size: 9.5px !important;
    font-weight: 700 !important;
    border-radius: 4px !important;
    padding: 2px 6px !important;
    background: rgba(255, 255, 255, 0.9) !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
    z-index: 2 !important;
}
.card-tag-type.wash { color: #0284c7 !important; }
.card-tag-type.mechanical { color: #d97706 !important; }

.card-tag-status.duration {
    position: absolute !important;
    top: 16px !important;
    right: 16px !important;
    font-size: 9.5px !important;
    font-weight: 600 !important;
    border-radius: 4px !important;
    padding: 2px 6px !important;
    background: rgba(255, 255, 255, 0.9) !important;
    color: #475569 !important;
    z-index: 2 !important;
}
.service-wait-time-badge {
    display: none !important;
}

.item-titles {
    margin-top: 8px !important;
    margin-bottom: 4px !important;
}
.item-name-km, .item-name-en {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-title) !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    margin: 0 !important;
}
.item-name-en {
    font-size: 11px !important;
    color: var(--gotpos-text-subtle) !important;
    font-weight: 400 !important;
    margin-top: 2px !important;
}
.item-meta-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-top: auto !important;
    padding-top: 6px !important;
}
.item-price-usd {
    font-size: 15.5px !important;
    font-weight: 700 !important;
    color: var(--gotpos-text-main) !important;
}
.btn-card-add {
    width: 28px !important;
    height: 28px !important;
    border-radius: 50% !important;
    background: var(--gotpos-primary) !important;
    color: #ffffff !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    border: none !important;
    cursor: pointer !important;
    transition: transform 0.15s, background 0.15s !important;
    line-height: 1 !important;
}
.btn-card-add:hover {
    transform: scale(1.1) !important;
}
.pos-item-card.in-cart .btn-card-add {
    background: var(--gotpos-success) !important;
}
.pos-item-card.in-cart .btn-card-add::before {
    content: "✓" !important;
}

/* ==========================================================================
   GOTPOS RIGHT ORDER PANEL (410px)
   ========================================================================== */
.pos-cart-column {
    width: 410px !important;
    min-width: 410px !important;
    max-width: 410px !important;
    background: var(--gotpos-card-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: var(--gotpos-radius) !important;
    padding: 14px 16px !important;
    display: flex !important;
    flex-direction: column !important;
    height: 100% !important;
    box-sizing: border-box !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
    overflow-y: auto !important;
    flex-shrink: 0 !important;
}

/* Order ID Header */
.gotpos-order-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding-bottom: 10px !important;
}
.gotpos-order-id {
    font-size: 15px !important;
    font-weight: 700 !important;
    color: var(--gotpos-text-main) !important;
    margin: 0 !important;
}
.gotpos-header-actions {
    display: flex !important;
    gap: 6px !important;
}
.btn-gotpos-icon {
    width: 30px !important;
    height: 30px !important;
    border-radius: 6px !important;
    background: var(--gotpos-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    color: var(--gotpos-text-muted) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    font-size: 13px !important;
}
.btn-gotpos-icon.text-danger:hover {
    color: #ef4444 !important;
    border-color: #fca5a5 !important;
}

/* Customer Card */
.gotpos-customer-card {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    background: var(--gotpos-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: 10px !important;
    padding: 8px 12px !important;
    margin-bottom: 12px !important;
}
.gotpos-cust-avatar {
    width: 36px !important;
    height: 36px !important;
    border-radius: 50% !important;
    background: #e2e8f0 !important;
    color: #64748b !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 16px !important;
    flex-shrink: 0 !important;
}
[data-theme="dark"] .gotpos-cust-avatar {
    background: #1e293b !important;
    color: #94a3b8 !important;
}
.gotpos-cust-details {
    flex: 1 !important;
    min-width: 0 !important;
}
.gotpos-cust-name {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: var(--gotpos-text-title) !important;
    margin: 0 0 2px 0 !important;
}
.gotpos-cust-tier {
    font-size: 11px !important;
    color: var(--gotpos-text-muted) !important;
    margin: 0 !important;
}
.btn-gotpos-cust-edit {
    border: none !important;
    background: transparent !important;
    color: var(--gotpos-text-subtle) !important;
    cursor: pointer !important;
    font-size: 12px !important;
}

/* Order Detail Header */
.gotpos-order-detail-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-bottom: 6px !important;
}
.gotpos-detail-title {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-title) !important;
    margin: 0 !important;
}
.btn-gotpos-clear-all {
    background: transparent !important;
    border: none !important;
    color: var(--gotpos-text-subtle) !important;
    font-size: 11px !important;
    text-decoration: underline !important;
    cursor: pointer !important;
}

/* Table Column Headers */
.gotpos-cart-col-headers {
    display: flex !important;
    justify-content: space-between !important;
    font-size: 10.5px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-subtle) !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    padding-bottom: 6px !important;
    border-bottom: 1px solid var(--gotpos-border) !important;
    margin-bottom: 6px !important;
}
.col-head-product { flex: 1 1 50% !important; }
.col-head-qty { flex: 0 0 25% !important; text-align: center !important; }
.col-head-subtotal { flex: 0 0 25% !important; text-align: right !important; }

/* Cart Item List */
.cart-items-list {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    max-height: 210px !important;
    margin-bottom: 8px !important;
    padding-right: 2px !important;
}
.cart-empty-state {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 24px 12px !important;
    text-align: center !important;
}
.cart-empty-icon-wrap {
    width: 60px !important;
    height: 60px !important;
    margin-bottom: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.cart-empty-icon-wrap i {
    display: none !important;
}
.cart-empty-icon-wrap::after {
    content: "" !important;
    display: block !important;
    width: 54px !important;
    height: 54px !important;
    background: url('../icons/no-order.svg') center/contain no-repeat !important;
}
.cart-empty-title {
    font-size: 13.5px !important;
    font-weight: 700 !important;
    color: var(--gotpos-text-title) !important;
    margin: 0 0 4px 0 !important;
}
.cart-empty-subtitle {
    font-size: 11.5px !important;
    color: var(--gotpos-text-muted) !important;
    margin: 0 !important;
}

.cart-item-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 7px 0 !important;
    border-bottom: 1px dashed var(--gotpos-border) !important;
}
.cart-item-main {
    display: flex !important;
    flex: 1 !important;
    align-items: center !important;
    justify-content: space-between !important;
}
.cart-item-info {
    flex: 1 1 50% !important;
    min-width: 0 !important;
}
.cart-item-title {
    font-size: 12.5px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-title) !important;
    display: block !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.cart-item-sub {
    font-size: 10.5px !important;
    color: var(--gotpos-text-subtle) !important;
    display: block !important;
}
.cart-item-actions {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.qty-control {
    display: inline-flex !important;
    align-items: center !important;
    background: var(--gotpos-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: 6px !important;
    padding: 2px !important;
}
.qty-btn {
    width: 20px !important;
    height: 20px !important;
    background: transparent !important;
    border: none !important;
    color: var(--gotpos-text-muted) !important;
    font-weight: 700 !important;
    cursor: pointer !important;
}
.qty-val {
    font-size: 11.5px !important;
    font-weight: 600 !important;
    padding: 0 6px !important;
    color: var(--gotpos-text-main) !important;
}
.cart-item-price {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: var(--gotpos-text-main) !important;
    text-align: right !important;
}
.cart-item-khr {
    font-size: 9.5px !important;
    color: var(--gotpos-text-subtle) !important;
    text-align: right !important;
}
.btn-remove-item {
    background: transparent !important;
    border: none !important;
    color: #ef4444 !important;
    font-size: 10.5px !important;
    cursor: pointer !important;
}

/* GOTPOS Discount Box */
.gotpos-discount-box {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    background: var(--gotpos-primary-subtle) !important;
    border: 1px dashed var(--gotpos-primary-border) !important;
    border-radius: 8px !important;
    padding: 7px 10px !important;
    margin-bottom: 10px !important;
}
.gotpos-discount-info {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    color: var(--gotpos-primary) !important;
}
.btn-gotpos-apply-disc {
    background: #ffffff !important;
    border: 1px solid var(--gotpos-primary-border) !important;
    color: var(--gotpos-primary) !important;
    border-radius: 6px !important;
    padding: 3px 8px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
}

/* GOTPOS Financial Summary */
.gotpos-financial-summary {
    padding-bottom: 8px !important;
    border-bottom: 1px solid var(--gotpos-border) !important;
    margin-bottom: 10px !important;
}
.gotpos-summary-row {
    display: flex !important;
    justify-content: space-between !important;
    font-size: 12px !important;
    color: var(--gotpos-text-muted) !important;
    padding: 2px 0 !important;
}
.gotpos-summary-total {
    display: flex !important;
    justify-content: space-between !important;
    align-items: baseline !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    color: var(--gotpos-text-main) !important;
    padding-top: 5px !important;
    margin-top: 3px !important;
    border-top: 1px dashed var(--gotpos-border) !important;
}
.gotpos-total-amount {
    font-size: 17px !important;
    font-weight: 800 !important;
    color: var(--gotpos-text-main) !important;
}

/* GOTPOS Select Method */
.gotpos-select-method-wrap {
    margin-bottom: 10px !important;
}
.gotpos-method-heading {
    font-size: 11.5px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-title) !important;
    margin: 0 0 5px 0 !important;
}
.gotpos-methods-grid {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 5px !important;
}
.btn-gotpos-method {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    background: var(--gotpos-bg) !important;
    border: 1px solid var(--gotpos-border) !important;
    border-radius: 6px !important;
    padding: 4px 8px !important;
    font-size: 11px !important;
    color: var(--gotpos-text-muted) !important;
    font-weight: 500 !important;
    cursor: pointer !important;
}
.btn-gotpos-method.active, .btn-gotpos-method:hover {
    background: var(--gotpos-card-bg) !important;
    border-color: var(--gotpos-primary) !important;
    color: var(--gotpos-primary) !important;
}

/* Process to Payment Button */
.btn-gotpos-process-payment,
#btnOpenCheckout.btn-gotpos-process-payment {
    width: 100% !important;
    background: var(--gotpos-primary) !important;
    border: 1px solid var(--gotpos-primary) !important;
    color: #ffffff !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    padding: 10px !important;
    border-radius: var(--gotpos-radius-sm) !important;
    cursor: pointer !important;
    box-shadow: 0 3px 10px rgba(255, 118, 45, 0.25) !important;
    transition: all 0.15s ease !important;
    margin-bottom: 10px !important;
    display: block !important;
}
.btn-gotpos-process-payment:hover:not(:disabled) {
    background: var(--gotpos-primary-hover) !important;
    border-color: var(--gotpos-primary-hover) !important;
}
.btn-gotpos-process-payment:disabled {
    opacity: 0.5 !important;
    cursor: not-allowed !important;
    box-shadow: none !important;
}

/* GOTPOS Bottom Pastel Features Bar */
.gotpos-advance-features {
    display: flex !important;
    justify-content: space-between !important;
    padding-top: 8px !important;
    border-top: 1px solid var(--gotpos-border) !important;
    margin-top: auto !important;
}
.btn-pastel-feature {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    background: transparent !important;
    border: none !important;
    cursor: pointer !important;
    padding: 0 !important;
}
.pastel-circle {
    width: 34px !important;
    height: 34px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 13px !important;
    margin-bottom: 3px !important;
    transition: transform 0.15s !important;
}
.btn-pastel-feature:hover .pastel-circle {
    transform: scale(1.08) !important;
}
.pastel-label {
    font-size: 10px !important;
    color: var(--gotpos-text-muted) !important;
    font-weight: 500 !important;
}
.bg-purple-subtle { background: #f3e8ff !important; }
.text-purple { color: #9333ea !important; }
.bg-success-subtle { background: #dcfce7 !important; }
.text-success { color: #16a34a !important; }
.bg-pink-subtle { background: #fce7f3 !important; }
.text-pink { color: #db2777 !important; }
.bg-secondary-subtle { background: #e0f2fe !important; }
.text-secondary { color: #0284c7 !important; }
.bg-danger-subtle { background: #ffe4e6 !important; }
.text-danger { color: #e11d48 !important; }

[data-theme="dark"] .bg-purple-subtle { background: rgba(147, 51, 234, 0.2) !important; }
[data-theme="dark"] .bg-success-subtle { background: rgba(22, 163, 74, 0.2) !important; }
[data-theme="dark"] .bg-pink-subtle { background: rgba(219, 39, 119, 0.2) !important; }
[data-theme="dark"] .bg-secondary-subtle { background: rgba(2, 132, 199, 0.2) !important; }
[data-theme="dark"] .bg-danger-subtle { background: rgba(225, 29, 72, 0.2) !important; }
`;

fs.writeFileSync('pos/css/app.css', css + '\n' + gotposCss);
console.log('Successfully updated pos/css/app.css with GOTPOS styles!');

