const fs = require('fs');

let js = fs.readFileSync('pos/js/app.js', 'utf8');

// 1. Add methods
const methodTarget = 'static openPaymentModal() {';
const methodCode = [
    'static openPaymentWithMethod(method) {',
    '        if (!this.cart || this.cart.length === 0) {',
    '            this.showCartToast("Cart is empty. Add services or products first.");',
    '            return;',
    '        }',
    '        this.openPaymentModal();',
    '        if (method) {',
    '            setTimeout(() => {',
    '                this.switchPaymentMethod(method);',
    '            }, 60);',
    '        }',
    '    }',
    '',
    '    static toggleFullscreen() {',
    '        if (!document.fullscreenElement) {',
    '            document.documentElement.requestFullscreen().catch(() => {});',
    '        } else {',
    '            document.exitFullscreen().catch(() => {});',
    '        }',
    '    }',
    '',
    '    static openPaymentModal() {'
].join('\n');

if (js.includes(methodTarget)) {
    js = js.replace(methodTarget, methodCode);
    console.log('1. Added openPaymentWithMethod & toggleFullscreen.');
}

// 2. Update startLiveClock
const clockTarget = "dateEl.textContent = dateStr;\n                timeEl.textContent = timeStr;";
const clockCode = [
    'dateEl.textContent = dateStr;',
    '                timeEl.textContent = timeStr;',
    '                const gotDateEl = document.getElementById("gotposLiveDate");',
    '                const gotTimeEl = document.getElementById("gotposLiveTime");',
    '                if (gotDateEl) {',
    '                    try {',
    '                        gotDateEl.textContent = now.toLocaleDateString("en-US", { weekday: "short", day: "numeric", month: "short", year: "numeric" });',
    '                    } catch(e) { gotDateEl.textContent = now.toDateString(); }',
    '                }',
    '                if (gotTimeEl) {',
    '                    try {',
    '                        gotTimeEl.textContent = now.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", hour12: true });',
    '                    } catch(e) { gotTimeEl.textContent = now.toTimeString().slice(0,5); }',
    '                }'
].join('\n');

if (js.includes(clockTarget)) {
    js = js.replace(clockTarget, clockCode);
    console.log('2. Updated startLiveClock.');
}

// 3. Update allBtn
const allBtnTarget = "allBtn.innerHTML = `<span>${allText}</span>`;";
const allBtnCode = 'allBtn.innerHTML = `<i class="fa-solid fa-shapes"></i><span class="pill-text">${allText}</span>`;';
if (js.includes(allBtnTarget)) {
    js = js.replace(allBtnTarget, allBtnCode);
    console.log('3. Updated allBtn with icon.');
}

// 4. Update countBadge in renderCart
const countTarget = "countBadge.style.display = totalQty > 0 ? 'inline-flex' : 'none';\n        }";
const countCode = [
    "countBadge.style.display = totalQty > 0 ? 'inline-flex' : 'none';",
    "            const gotCountBadge = document.getElementById('gotposItemCount');",
    "            if (gotCountBadge) gotCountBadge.textContent = totalQty;",
    "        }"
].join('\n');

if (js.includes(countTarget)) {
    js = js.replace(countTarget, countCode);
    console.log('4. Updated countBadge in renderCart.');
}

// 5. Replace cart-item-row template with 3-column gotpos-cart-row using pure concatenation
const oldCartItemTarget = '                    <div class="cart-item-row">';
const oldCartItemEnd = '                    </div>\n                `;';
const startIdx = js.indexOf(oldCartItemTarget);
const endIdx = js.indexOf(oldCartItemEnd, startIdx);

if (startIdx !== -1 && endIdx !== -1) {
    const newCartItem = [
        '                    <div class="cart-item-row gotpos-cart-row">',
        '                        <div class="gotpos-col-product">',
        '                            <span class="gotpos-item-title">${this.escapeHtml(it.name_km || it.name_en)} ${freeBadge}${upsellBadge}</span>',
        '                            <span class="gotpos-item-sub">${this.escapeHtml(it.name_en)}${it.note ? " • " + this.escapeHtml(it.note) : ""}</span>',
        '                        </div>',
        '                        <div class="gotpos-col-qty">',
        '                            <div class="qty-control">',
        '                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(${idx}, -1)">-</button>',
        '                                <span class="qty-val">${it.qty}</span>',
        '                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(${idx}, 1)">+</button>',
        '                            </div>',
        '                        </div>',
        '                        <div class="gotpos-col-subtotal">',
        '                            <span class="gotpos-item-price">${isFree ? "$0.00" : "$" + lineTotalUsd.toFixed(2)}</span>',
        '                            <button type="button" class="gotpos-remove-x" onclick="PosApp.removeCartItem(${idx})" title="Remove"><i class="fa-solid fa-xmark"></i></button>',
        '                        </div>',
        '                    </div>'
    ].join('\n');

    js = js.substring(0, startIdx) + newCartItem + js.substring(endIdx + 26);
    console.log('5. Replaced cart item template with 3-column gotpos-cart-row.');
} else {
    console.warn('Could not locate oldCartItemTarget');
}

// 6. Sync GOTPOS Customer & In-Cart badges at end of calculateTotals in renderCart
const syncTarget = "if (checkoutAmount) {\n            checkoutAmount.textContent = `$${totals.totalUsd.toFixed(2)}`;";
const syncCode = [
    "// Sync GOTPOS Customer & In-Cart badges",
    "        const nameInp = document.getElementById('custNameInput');",
    "        const plateInp = document.getElementById('custPlateInput');",
    "        const custNameEl = document.getElementById('gotposCustNameDisplay');",
    "        const custTierEl = document.getElementById('gotposCustTierDisplay');",
    "        const custVal = nameInp?.value?.trim() || (this.currentCustomer?.name && this.currentCustomer.name !== 'Customer' ? this.currentCustomer.name : 'Jonathan Michael');",
    "        const plateVal = plateInp?.value?.trim()?.toUpperCase() || this.currentCustomer?.plate || '';",
    "        if (custNameEl) custNameEl.textContent = custVal;",
    "        if (custTierEl) {",
    "            custTierEl.textContent = plateVal ? plateVal + ' • Platinum Member • 2,450 Points' : 'Platinum Member • 2,450 Points';",
    "        }",
    "",
    "        // Toggle in-cart class on catalog cards",
    "        const inCartSvcIds = new Set(this.cart.filter(it => it.type === 'service').map(it => String(it.id)));",
    "        const inCartProdIds = new Set(this.cart.filter(it => it.type === 'product').map(it => String(it.id)));",
    "        document.querySelectorAll('#catalogGrid .pos-item-card').forEach(card => {",
    "            const svcId = card.getAttribute('data-svc-id');",
    "            const prodId = card.getAttribute('data-prod-id');",
    "            const isInCart = (svcId && inCartSvcIds.has(String(svcId))) || (prodId && inCartProdIds.has(String(prodId)));",
    "            if (isInCart) {",
    "                card.classList.add('in-cart');",
    "            } else {",
    "                card.classList.remove('in-cart');",
    "            }",
    "        });",
    "",
    "        if (checkoutAmount) {",
    "            checkoutAmount.textContent = `$${totals.totalUsd.toFixed(2)}`;"
].join('\n');

if (js.includes(syncTarget)) {
    js = js.replace(syncTarget, syncCode);
    console.log('6. Added customer sync & in-cart toggle.');
}

fs.writeFileSync('pos/js/app.js', js);
console.log('Done writing pos/js/app.js!');
