const fs = require('fs');

let css = fs.readFileSync('pos/css/app.css', 'utf8');

const tightCss = `
/* ==========================================================================
   GOTPOS PERFECT FIT COMPACT LAYOUT (NO OVERFLOW)
   ========================================================================== */

/* Workspace Full Height */
.pos-workspace {
    height: calc(100vh - 58px) !important;
    padding: 10px 14px !important;
    gap: 12px !important;
}

/* Hide legacy redundant rows in right panel */
.pos-cart-column .cart-details-accordion,
.pos-cart-column .cart-wait-time-row,
.pos-cart-column .cart-action-pills-row,
.pos-cart-column .cart-checkout-action-wrap.secondary-wrap {
    display: none !important;
}

/* Right Cart Column Layout */
.pos-cart-column {
    display: flex !important;
    flex-direction: column !important;
    height: 100% !important;
    padding: 12px 14px !important;
    overflow-y: hidden !important;
    justify-content: flex-start !important;
}

/* Header */
.gotpos-order-header {
    padding-bottom: 6px !important;
    margin-bottom: 6px !important;
    border-bottom: 1px solid var(--gotpos-border) !important;
}
.gotpos-order-id {
    font-size: 14px !important;
}
.btn-gotpos-icon {
    width: 28px !important;
    height: 28px !important;
}

/* Customer Card */
.gotpos-customer-card {
    padding: 6px 10px !important;
    margin-bottom: 8px !important;
    border-radius: 8px !important;
}
.gotpos-cust-avatar {
    width: 32px !important;
    height: 32px !important;
    font-size: 14px !important;
}
.gotpos-cust-name {
    font-size: 12.5px !important;
}
.gotpos-cust-tier {
    font-size: 10.5px !important;
}

/* Order Detail Header & Columns */
.gotpos-order-detail-header {
    margin-bottom: 4px !important;
}
.gotpos-detail-title {
    font-size: 12px !important;
}
.gotpos-cart-col-headers {
    font-size: 10px !important;
    padding-bottom: 4px !important;
    margin-bottom: 4px !important;
}

/* Cart Item List */
.cart-items-list {
    flex: 1 1 auto !important;
    min-height: 80px !important;
    max-height: 160px !important;
    overflow-y: auto !important;
    margin-bottom: 6px !important;
}
.cart-empty-state {
    padding: 16px 8px !important;
}
.cart-empty-icon-wrap {
    width: 48px !important;
    height: 48px !important;
    margin-bottom: 6px !important;
}
.cart-empty-icon-wrap::after {
    width: 44px !important;
    height: 44px !important;
}
.cart-empty-title {
    font-size: 12.5px !important;
}
.cart-empty-subtitle {
    font-size: 11px !important;
}

/* Horizontal 3-Column Cart Row */
.pos-cart-column .cart-item-row,
.pos-cart-column .gotpos-cart-row {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: space-between !important;
    background: transparent !important;
    border: none !important;
    border-bottom: 1px dashed var(--gotpos-border) !important;
    border-radius: 0 !important;
    padding: 6px 0 !important;
    box-shadow: none !important;
    gap: 4px !important;
    width: 100% !important;
}
.pos-cart-column .gotpos-col-product {
    flex: 1 1 50% !important;
    min-width: 0 !important;
    text-align: left !important;
}
.pos-cart-column .gotpos-col-qty {
    flex: 0 0 25% !important;
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
}
.pos-cart-column .gotpos-col-subtotal {
    flex: 0 0 25% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 6px !important;
}
.gotpos-item-title {
    font-size: 12px !important;
}
.gotpos-item-sub {
    font-size: 10px !important;
}
.gotpos-item-price {
    font-size: 13px !important;
}
.qty-control {
    padding: 1px !important;
}
.qty-btn {
    width: 18px !important;
    height: 18px !important;
    font-size: 11px !important;
}
.qty-val {
    font-size: 11px !important;
    padding: 0 4px !important;
}

/* Discount Box */
.gotpos-discount-box {
    padding: 6px 10px !important;
    margin-bottom: 6px !important;
}
.gotpos-discount-info {
    font-size: 11px !important;
}
.btn-gotpos-apply-disc {
    padding: 2px 8px !important;
    font-size: 10.5px !important;
}

/* Financial Summary */
.gotpos-financial-summary {
    padding-bottom: 6px !important;
    margin-bottom: 6px !important;
}
.gotpos-summary-row {
    font-size: 11.5px !important;
    padding: 1px 0 !important;
}
.gotpos-summary-total {
    font-size: 13px !important;
    padding-top: 4px !important;
    margin-top: 2px !important;
}
.gotpos-total-amount {
    font-size: 16px !important;
}

/* Select Method */
.gotpos-select-method-wrap {
    margin-bottom: 8px !important;
}
.gotpos-method-heading {
    font-size: 11px !important;
    margin-bottom: 4px !important;
}
.gotpos-methods-grid {
    gap: 4px !important;
}
.btn-gotpos-method {
    padding: 3px 7px !important;
    font-size: 10.5px !important;
    border-radius: 5px !important;
}

/* Process to Payment */
.btn-gotpos-process-payment,
#btnOpenCheckout.btn-gotpos-process-payment {
    padding: 9px !important;
    font-size: 13.5px !important;
    margin-bottom: 8px !important;
}

/* Pastel Advance Features Bar */
.gotpos-advance-features {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding-top: 6px !important;
    border-top: 1px solid var(--gotpos-border) !important;
    margin-top: auto !important;
}
.btn-pastel-feature {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    flex: 1 !important;
}
.pastel-circle {
    width: 32px !important;
    height: 32px !important;
    font-size: 12px !important;
    margin-bottom: 2px !important;
}
.pastel-circle i {
    font-size: 13px !important;
}
.pastel-label {
    font-size: 9.5px !important;
}

/* Hide Bottom Dock in POS View since GOTPOS already has full controls */
.pos-bottom-dock {
    display: none !important;
}
`;

fs.appendFileSync('pos/css/app.css', '\n' + tightCss);
console.log('Successfully appended compact layout CSS!');

