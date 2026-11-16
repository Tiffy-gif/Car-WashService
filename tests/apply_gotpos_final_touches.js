const fs = require('fs');

// 1. Update pos/js/app.js to render GOTPOS 3-column cart row
let js = fs.readFileSync('pos/js/app.js', 'utf8');

const oldRowTemplate = `                html += \`
                    <div class="cart-item-row">
                        <div class="cart-item-main">
                            <div class="cart-item-info">
                                <span class="cart-item-title">\${this.escapeHtml(it.name_km || it.name_en)} \${freeBadge}\${upsellBadge}</span>
                                <span class="cart-item-sub">\${this.escapeHtml(it.name_en)}\${it.note ? \` • \${this.escapeHtml(it.note)}\` : ''}</span>
                            </div>
                            <div>
                                <div class="cart-item-price">\${isFree ? '$0.00' : '$' + lineTotalUsd.toFixed(2)}</div>
                                <div class="cart-item-khr">\${isFree ? '0 ៛' : lineTotalKhr.toLocaleString() + ' ៛'}</div>
                            </div>
                        </div>
                        <div class="cart-item-actions">
                            <div class="qty-control">
                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(\${idx}, -1)">-</button>
                                <span class="qty-val">\${it.qty}</span>
                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(\${idx}, 1)">+</button>
                            </div>
                            <button type="button" class="btn-remove-item" onclick="PosApp.removeCartItem(\${idx})">
                                <i class="fa-solid fa-trash-can"></i> Remove
                            </button>
                        </div>
                    </div>
                \`;`;

const newRowTemplate = `                html += \`
                    <div class="cart-item-row gotpos-cart-row">
                        <div class="gotpos-col-product">
                            <span class="gotpos-item-title">\${this.escapeHtml(it.name_km || it.name_en)} \${freeBadge}\${upsellBadge}</span>
                            <span class="gotpos-item-sub">\${this.escapeHtml(it.name_en)}\${it.note ? \` • \${this.escapeHtml(it.note)}\` : ''}</span>
                        </div>
                        <div class="gotpos-col-qty">
                            <div class="qty-control">
                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(\${idx}, -1)">-</button>
                                <span class="qty-val">\${it.qty}</span>
                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(\${idx}, 1)">+</button>
                            </div>
                        </div>
                        <div class="gotpos-col-subtotal">
                            <span class="gotpos-item-price">\${isFree ? '$0.00' : '$' + lineTotalUsd.toFixed(2)}</span>
                            <button type="button" class="gotpos-remove-x" onclick="PosApp.removeCartItem(\${idx})" title="Remove"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    </div>
                \`;`;

if (js.includes(oldRowTemplate)) {
    js = js.replace(oldRowTemplate, newRowTemplate);
    console.log('Successfully updated cart row template in pos/js/app.js (LF)');
} else {
    // Try CRLF
    const oldRowTemplateCRLF = oldRowTemplate.replace(/\n/g, '\r\n');
    const newRowTemplateCRLF = newRowTemplate.replace(/\n/g, '\r\n');
    if (js.includes(oldRowTemplateCRLF)) {
        js = js.replace(oldRowTemplateCRLF, newRowTemplateCRLF);
        console.log('Successfully updated cart row template in pos/js/app.js (CRLF)');
    } else {
        console.warn('Could not find oldRowTemplate');
    }
}

fs.writeFileSync('pos/js/app.js', js);

// 2. Append CSS refinements
let css = fs.readFileSync('pos/css/app.css', 'utf8');

const finalCss = `
/* ==========================================================================
   GOTPOS 3-COLUMN TABLE ROW & PASTEL BAR ICONS
   ========================================================================== */
.gotpos-cart-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 8px 0 !important;
    border-bottom: 1px dashed var(--gotpos-border) !important;
    gap: 4px !important;
}
.gotpos-col-product {
    flex: 1 1 50% !important;
    min-width: 0 !important;
}
.gotpos-col-qty {
    flex: 0 0 25% !important;
    display: flex !important;
    justify-content: center !important;
}
.gotpos-col-subtotal {
    flex: 0 0 25% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 6px !important;
}
.gotpos-item-title {
    font-size: 12.5px !important;
    font-weight: 600 !important;
    color: var(--gotpos-text-title) !important;
    display: block !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.gotpos-item-sub {
    font-size: 10.5px !important;
    color: var(--gotpos-text-subtle) !important;
    display: block !important;
}
.gotpos-item-price {
    font-size: 13.5px !important;
    font-weight: 700 !important;
    color: var(--gotpos-text-main) !important;
}
.gotpos-remove-x {
    border: none !important;
    background: transparent !important;
    color: #94a3b8 !important;
    font-size: 12px !important;
    cursor: pointer !important;
    padding: 2px !important;
    line-height: 1 !important;
}
.gotpos-remove-x:hover {
    color: #ef4444 !important;
}

/* Explicit colors for pastel icons */
.pastel-circle.bg-purple-subtle i { color: #9333ea !important; }
.pastel-circle.bg-success-subtle i { color: #16a34a !important; }
.pastel-circle.bg-pink-subtle i { color: #db2777 !important; }
.pastel-circle.bg-secondary-subtle i { color: #0284c7 !important; }
.pastel-circle.bg-danger-subtle i { color: #e11d48 !important; }

/* Top Navbar Styling */
.pos-header {
    height: 56px !important;
    padding: 0 16px !important;
    border-bottom: 1px solid var(--gotpos-border) !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
}
.btn-gotpos-dash {
    background: #0f2942 !important;
    color: #ffffff !important;
    padding: 4px 12px !important;
    border-radius: 20px !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
}
`;

fs.appendFileSync('pos/css/app.css', '\n' + finalCss);
console.log('Successfully appended final CSS to pos/css/app.css!');

