const fs = require('fs');
const path = require('path');

// 1. Read pos/index.html
let html = fs.readFileSync('pos/index.html', 'utf8');

// Backup
fs.writeFileSync('pos/index.html.bak_gotpos', html);

// Replace Brand area to include the date/time pills
const brandAreaTarget = `<div class="brand-title">
                <h1 id="brandShopNamePrimary">My Car Wash</h1>
                <span id="brandShopNameSecondary" style="display: none;"></span>
            </div>
        </div>`;

const brandAreaReplacement = `<div class="brand-title">
                <h1 id="brandShopNamePrimary"><span class="gotpos-brand-orange">G</span>OTPOS <span class="gotpos-brand-sub">Car Wash</span></h1>
                <span id="brandShopNameSecondary" style="display: none;"></span>
            </div>
            <!-- GOTPOS Top Date & Time Pills -->
            <div class="gotpos-top-pills">
                <div class="gotpos-pill-box">
                    <i class="fa-regular fa-calendar"></i>
                    <span id="gotposLiveDate">Sat, 14 Nov 2026</span>
                </div>
                <div class="gotpos-pill-box">
                    <i class="fa-regular fa-clock"></i>
                    <span id="gotposLiveTime">09:59 AM</span>
                </div>
            </div>
        </div>`;

if (html.includes(brandAreaTarget)) {
    html = html.replace(brandAreaTarget, brandAreaReplacement);
    console.log('1. Replaced Brand area and added date/time pills.');
} else {
    console.warn('Could not find brandAreaTarget');
}

// Replace header actions to include Open Order pill and Dashboard button
const headerActionsTarget = `<div class="header-actions">
            <!-- Network Status Pill`;

const headerActionsReplacement = `<div class="header-actions">
            <!-- GOTPOS Live Open Order & Dashboard -->
            <div class="gotpos-open-order-chip">
                <span class="dot-live"></span>
                <span>Open Order</span>
                <i class="fa-solid fa-power-off"></i>
            </div>
            <button type="button" class="btn-gotpos-dash" onclick="PosApp.switchView('queue')" title="Dashboard">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Dashboard</span>
            </button>
            <!-- Network Status Pill`;

if (html.includes(headerActionsTarget)) {
    html = html.replace(headerActionsTarget, headerActionsReplacement);
    console.log('2. Replaced header actions with Open Order pill and Dashboard button.');
} else {
    console.warn('Could not find headerActionsTarget');
}

// Replace vehicle-type-switcher and catalog toolbar
const vehicleSwitcherTarget = `<!-- Vehicle Type Switcher (Row spanning same width) -->
                    <div class="vehicle-type-switcher">
                        <button type="button" class="vehicle-btn" data-vtype="motorbike">
                            <i class="fa-solid fa-motorcycle"></i>
                            <span class="v-title-km">ម៉ូតូ</span>
                            <span class="v-title-en">Motorbike</span>
                        </button>
                        <button type="button" class="vehicle-btn active" data-vtype="small_car">
                            <i class="fa-solid fa-car-side"></i>
                            <span class="v-title-km">ឡានតូច</span>
                            <span class="v-title-en">Small Car</span>
                        </button>
                        <button type="button" class="vehicle-btn" data-vtype="suv_pickup">
                            <i class="fa-solid fa-truck-pickup"></i>
                            <span class="v-title-km">ឡានធំ / SUV</span>
                            <span class="v-title-en">SUV / Pickup</span>
                        </button>
                        <button type="button" class="vehicle-btn" data-vtype="van_truck">
                            <i class="fa-solid fa-van-shuttle"></i>
                            <span class="v-title-km">ឡានដឹក / វ៉ាន់</span>
                            <span class="v-title-en">Van / Truck</span>
                        </button>
                    </div>`;

const vehicleSwitcherReplacement = `<!-- GOTPOS Toolbar: Filter + Vehicle Switcher + Search Input -->
                    <div class="gotpos-catalog-toolbar">
                        <div class="gotpos-toolbar-left">
                            <button type="button" class="btn-gotpos-filter" title="Filter"><i class="fa-solid fa-sliders"></i> <span>Filter</span></button>
                            <div class="vehicle-type-switcher gotpos-vehicle-switcher">
                                <button type="button" class="vehicle-btn" data-vtype="motorbike">
                                    <i class="fa-solid fa-motorcycle"></i>
                                    <span class="v-title-km">ម៉ូតូ</span>
                                    <span class="v-title-en">Motorbike</span>
                                </button>
                                <button type="button" class="vehicle-btn active" data-vtype="small_car">
                                    <i class="fa-solid fa-car-side"></i>
                                    <span class="v-title-km">ឡានតូច</span>
                                    <span class="v-title-en">Small Car</span>
                                </button>
                                <button type="button" class="vehicle-btn" data-vtype="suv_pickup">
                                    <i class="fa-solid fa-truck-pickup"></i>
                                    <span class="v-title-km">ឡានធំ / SUV</span>
                                    <span class="v-title-en">SUV / Pickup</span>
                                </button>
                                <button type="button" class="vehicle-btn" data-vtype="van_truck">
                                    <i class="fa-solid fa-van-shuttle"></i>
                                    <span class="v-title-km">ឡានដឹក / វ៉ាន់</span>
                                    <span class="v-title-en">Van / Truck</span>
                                </button>
                            </div>
                        </div>
                        <div class="gotpos-search-wrapper">
                            <i class="fa-solid fa-magnifying-glass gotpos-search-icon"></i>
                            <input type="text" id="posGlobalSearchInput" class="gotpos-search-input" placeholder="Search product..." autocomplete="off">
                            <button type="button" class="gotpos-barcode-btn" title="Barcode Scan"><i class="fa-solid fa-barcode"></i></button>
                        </div>
                    </div>`;

if (html.includes(vehicleSwitcherTarget)) {
    html = html.replace(vehicleSwitcherTarget, vehicleSwitcherReplacement);
    console.log('3. Replaced vehicle switcher and added search bar toolbar.');
} else {
    console.warn('Could not find vehicleSwitcherTarget');
}

// Remove old hidden posGlobalSearchInput
html = html.replace('<input type="hidden" id="posGlobalSearchInput">', '');

// Replace pos-cart-column
const cartColumnStart = '<div class="pos-cart-column" id="posCartColumn">';
const cartColumnEnd = '<!-- Mobile Floating Cart Button (Requirement 6) -->';

const startIdx = html.indexOf(cartColumnStart);
const endIdx = html.indexOf(cartColumnEnd);

if (startIdx !== -1 && endIdx !== -1) {
    const newCartColumn = `<div class="pos-cart-column" id="posCartColumn">
                    <!-- GOTPOS Order ID Header -->
                    <div class="gotpos-order-header">
                        <h6 class="gotpos-order-id">Order ID: <strong id="gotposOrderIdDisplay">#GOT1698</strong></h6>
                        <div class="gotpos-header-actions">
                            <button type="button" class="btn-gotpos-icon" id="btnGotposMore" title="More Options"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                            <button type="button" class="btn-gotpos-icon" id="btnGotposFullscreen" title="Fullscreen" onclick="PosApp.toggleFullscreen()"><i class="fa-solid fa-expand"></i></button>
                            <button type="button" class="btn-gotpos-icon btn-clear-cart-text text-danger" id="btnClearCart" title="Clear Cart"><i class="fa-regular fa-trash-can"></i></button>
                        </div>
                    </div>

                    <!-- Hidden compatibility cart-header -->
                    <div class="cart-header" style="display: none;">
                        <div class="cart-header-title">
                            <span data-i18n="cartTitle">Cart</span>
                            <span class="cart-items-count" id="cartCountBadge" style="display: none;">0</span>
                        </div>
                    </div>

                    <!-- GOTPOS Customer Profile Card -->
                    <div class="gotpos-customer-card" id="gotposCustomerCard">
                        <div class="gotpos-cust-avatar">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div class="gotpos-cust-details">
                            <h6 class="gotpos-cust-name" id="gotposCustNameDisplay">Jonathan Michael</h6>
                            <p class="gotpos-cust-tier" id="gotposCustTierDisplay">Platinum Member • 2,450 Points</p>
                        </div>
                        <button type="button" class="btn-gotpos-cust-edit" id="btnGotposEditCustomer" title="Edit Customer" onclick="document.getElementById('btnOpenNewCustomerModal')?.click()">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                    </div>

                    <!-- Order Detail Header -->
                    <div class="gotpos-order-detail-header">
                        <h6 class="gotpos-detail-title">Order Detail (<span data-i18n="items">Items</span>: <span id="gotposItemCount">0</span>)</h6>
                        <button type="button" class="btn-gotpos-clear-all" id="btnGotposClearAll" onclick="PosApp.clearCart()">Clear All</button>
                    </div>

                    <!-- GOTPOS Table Column Headers -->
                    <div class="gotpos-cart-col-headers">
                        <span class="col-head-product">Product</span>
                        <span class="col-head-qty">QTY</span>
                        <span class="col-head-subtotal">Subtotal</span>
                    </div>

                    <!-- Workflow Stage Badge (Task 2) -->
                    <div id="cartOrderWorkflowBadge" class="cart-order-workflow-badge" style="display: none;"></div>

                    <!-- Vehicle Recognition Profile (Requirement 4) -->
                    <div class="cart-vehicle-profile" id="cartVehicleProfile" style="display: none;">
                        <div class="vehicle-profile-header">
                            <div class="profile-plate-row">
                                <span class="profile-plate-chip" id="profPlateBadge">--</span>
                                <span class="profile-tier-badge" id="profTierBadge">Standard</span>
                            </div>
                            <div class="profile-cust-name" id="profCustName">Customer</div>
                        </div>
                        <div class="profile-stats-row">
                            <div class="profile-stat-item" title="Wash History">
                                <i class="fa-solid fa-car-side"></i> <span id="profWashCount">0</span> <span data-i18n="washes">washes</span>
                            </div>
                            <div class="profile-stat-item" title="Last Oil Change">
                                <i class="fa-solid fa-oil-can"></i> <span id="profOilText">No record</span>
                            </div>
                        </div>
                        <div class="profile-flags-list" id="profFlagsList"></div>
                        <div class="member-panel-box" id="profMemberPanel" style="display: none;">
                            <div class="member-panel-header">
                                <span class="member-panel-title"><i class="fa-solid fa-crown text-amber"></i> <span data-i18n="memberTier">Member Tier</span>: <strong id="memberTierName">Gold</strong></span>
                                <span class="member-visits-pill"><i class="fa-solid fa-calendar-check"></i> <strong id="memberVisitsCount">0</strong> <span data-i18n="visitsThisMonth">visits this month</span></span>
                            </div>
                            <div class="member-benefits-section" id="profBenefitsSection">
                                <div class="member-benefits-label"><i class="fa-solid fa-gift"></i> <span data-i18n="includedBenefits">Included Services</span>:</div>
                                <div class="member-benefits-chips" id="profBenefitsChips"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Loyalty Reward Prompt Banner -->
                    <div class="loyalty-reward-prompt-banner" id="cartLoyaltyRewardBanner" style="display: none;">
                        <div class="reward-prompt-left">
                            <div class="reward-prompt-icon"><i class="fa-solid fa-gift"></i></div>
                            <div class="reward-prompt-text">
                                <span class="reward-prompt-title" data-i18n="loyaltyRewardTitle">Loyalty Reward</span>
                                <span class="reward-prompt-desc" id="loyaltyRewardDesc" data-i18n="loyaltyRewardPrompt">Offer a free air freshener (loyalty reward)</span>
                            </div>
                        </div>
                        <div class="reward-prompt-actions">
                            <button type="button" class="btn btn-sm btn-success btn-reward-action" id="btnGiveLoyaltyReward">
                                <i class="fa-solid fa-check"></i> <span data-i18n="gaveReward">Gave Reward</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline btn-reward-action" id="btnSkipLoyaltyReward">
                                <span data-i18n="skipReward">Skip</span>
                            </button>
                        </div>
                    </div>
                    <div id="cartLoyaltyRewardStatusBadge" class="loyalty-reward-status-chip" style="display: none;"></div>

                    <!-- Cart Item List with Friendly Empty State -->
                    <div class="cart-items-list" id="cartList">
                        <div class="cart-empty-state">
                            <div class="cart-empty-icon-wrap">
                                <i class="fa-solid fa-cart-shopping"></i>
                            </div>
                            <h3 class="cart-empty-title" data-i18n="cartEmptyTitle">Cart is empty</h3>
                            <p class="cart-empty-subtitle" data-i18n="cartEmptySubtitle">Scan a barcode or choose an item</p>
                        </div>
                    </div>

                    <!-- Cart Footer / Summary -->
                    <div class="cart-footer">
                        <!-- Collapsible Section for Mileage & Notes -->
                        <details class="cart-details-accordion" id="cartServiceDetailsAccordion">
                            <summary class="cart-accordion-summary">
                                <span class="summary-left">
                                    <i class="fa-solid fa-sliders"></i>
                                    <span data-i18n="cartServiceDetails">Service Mileage & Details</span>
                                </span>
                                <i class="fa-solid fa-chevron-down accordion-caret"></i>
                            </summary>
                            <div class="cart-accordion-content">
                                <div style="display: none;">
                                    <select id="cartWasherSelect"><option value="">-- None --</option></select>
                                    <select id="cartMechanicSelect"><option value="">-- None --</option></select>
                                </div>
                                <div class="cart-mileage-wrap">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); white-space: nowrap;">
                                            <i class="fa-solid fa-gauge"></i> <span data-i18n="mileage">គីឡូម៉ែត្រ</span>:
                                        </span>
                                        <input type="number" id="cartMileageInput" class="form-control" placeholder="e.g. 85000">
                                    </div>
                                    <div id="cartOilReminderBox" style="display: none; margin-top: 0.35rem; padding: 0.5rem 0.6rem; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; font-size: 0.8rem; color: #065f46;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                                            <span style="font-weight: 700;"><i class="fa-solid fa-oil-can" style="color: #059669; margin-right: 4px;"></i><span data-i18n="nextOilReminder">រំលឹកប្តូរប្រេងលើកក្រោយ:</span></span>
                                            <span style="font-size: 0.72rem; color: #047857;">(Cashier can adjust)</span>
                                        </div>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem;">
                                            <div>
                                                <label style="font-size: 0.72rem; color: #065f46; display: block; margin-bottom: 2px;">Next Due Mileage (km):</label>
                                                <input type="number" id="cartNextOilMileageInput" class="form-control" style="padding: 0.25rem 0.4rem; font-size: 0.82rem; font-weight: 700; height: 30px;">
                                            </div>
                                            <div>
                                                <label style="font-size: 0.72rem; color: #065f46; display: block; margin-bottom: 2px;">Next Due Date:</label>
                                                <input type="date" id="cartNextOilDateInput" class="form-control" style="padding: 0.25rem 0.4rem; font-size: 0.82rem; font-weight: 700; height: 30px;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div style="margin-top: 0.4rem;">
                                    <input type="text" id="cartRepairNotesInput" class="form-control"
                                        placeholder="ចំណាំការជួសជុល..." data-i18n="repairNotesPlaceholder">
                                </div>
                            </div>
                        </details>

                        <!-- Cart Estimated Wait Time -->
                        <div class="cart-wait-time-row" id="cartWaitTimeRow" style="display: none;">
                            <span class="wait-time-title">
                                <i class="fa-regular fa-clock"></i> <span data-i18n="estWaitTime">Estimated Wait:</span>
                            </span>
                            <span class="cart-wait-badge" id="cartWaitTimeBadge">Wait about 25 min</span>
                        </div>

                        <!-- GOTPOS Discount Box -->
                        <div class="gotpos-discount-box" id="gotposDiscountBox">
                            <div class="gotpos-discount-info">
                                <i class="fa-solid fa-tag gotpos-disc-icon"></i>
                                <span class="gotpos-disc-label" id="gotposDiscountLabel">Discount 10%</span>
                            </div>
                            <button type="button" class="btn-gotpos-apply-disc" id="btnGotposApplyDiscount" onclick="PosApp.openDiscountModal()">Apply</button>
                        </div>

                        <!-- GOTPOS Financial Summary Breakdown -->
                        <div class="gotpos-financial-summary">
                            <div class="gotpos-summary-row">
                                <span data-i18n="subtotal">Sub Total</span>
                                <span id="cartSubtotalText">$0.00</span>
                            </div>
                            <div class="gotpos-summary-row discount-row" id="discountSummaryRow" style="display: none;">
                                <span id="cartDiscountLabel">Discount</span>
                                <span class="text-danger" id="cartDiscountText">-$0.00</span>
                            </div>
                            <div class="gotpos-summary-row">
                                <span>GST (18%)</span>
                                <span id="gotposGstText">$0.00</span>
                            </div>
                            <div class="gotpos-summary-row">
                                <span>Service Charge</span>
                                <span id="gotposServiceChargeText">$0.00</span>
                            </div>
                            <div class="gotpos-summary-total">
                                <span data-i18n="totalPayable">Total Payable</span>
                                <div class="total-amount-box text-end">
                                    <span class="gotpos-total-amount" id="cartTotalUsd">$0.00</span>
                                    <span class="gotpos-total-khr" id="cartTotalKhr" style="display: none;">0 ៛</span>
                                </div>
                            </div>
                        </div>

                        <!-- GOTPOS Select Payment Method -->
                        <div class="gotpos-select-method-wrap">
                            <h6 class="gotpos-method-heading">Select Method</h6>
                            <div class="gotpos-methods-grid">
                                <button type="button" class="btn-gotpos-method active" data-method="cash" onclick="PosApp.openPaymentWithMethod('cash')">
                                    <i class="fa-solid fa-coins"></i> Cash
                                </button>
                                <button type="button" class="btn-gotpos-method" data-method="card" onclick="PosApp.openPaymentWithMethod('card')">
                                    <i class="fa-solid fa-credit-card"></i> Card
                                </button>
                                <button type="button" class="btn-gotpos-method" data-method="khqr" onclick="PosApp.openPaymentWithMethod('khqr')">
                                    <i class="fa-solid fa-qrcode"></i> UPI / Scan
                                </button>
                                <button type="button" class="btn-gotpos-method" data-method="bank" onclick="PosApp.openPaymentWithMethod('bank')">
                                    <i class="fa-solid fa-building-columns"></i> Bank Transfer
                                </button>
                                <button type="button" class="btn-gotpos-method" data-method="split" onclick="PosApp.openPaymentWithMethod('split')">
                                    <i class="fa-solid fa-layer-group"></i> Split Payment
                                </button>
                            </div>
                        </div>

                        <!-- Primary Process to Payment Button -->
                        <div class="cart-checkout-action-wrap primary-wrap">
                            <button type="button" class="btn-checkout-primary btn-gotpos-process-payment is-disabled" id="btnOpenCheckout" disabled>
                                <span class="btn-checkout-content">
                                    <span class="checkout-btn-text" data-i18n="processToPayment">Process to Payment</span>
                                    <span class="btn-checkout-amount" id="checkoutBtnAmount" style="display: none;">$0.00</span>
                                </span>
                            </button>
                        </div>

                        <!-- Compatibility hidden Action Pills -->
                        <div class="cart-action-pills-row" style="display: none;">
                            <button type="button" class="btn-cart-pill pill-clear" id="btnCartPillClear" disabled></button>
                            <button type="button" class="btn-cart-pill pill-saved" id="btnCartPillQueue"></button>
                            <button type="button" class="btn-cart-pill pill-discount" id="btnCartPillDiscount" disabled></button>
                            <span class="cart-discount-badge" id="cartDiscountBadge" style="display: none;"></span>
                        </div>

                        <div class="cart-checkout-action-wrap secondary-wrap" style="display: none;">
                            <button type="button" class="btn-checkout-secondary" id="btnOpenCheckoutSecondary" disabled></button>
                            <div class="checkout-helper-text" id="checkoutHelperText">
                                <span data-i18n="cartEmptyCheckoutHelp">Add services or products to collect payment</span>
                            </div>
                        </div>
                    </div>

                    <!-- GOTPOS Bottom Pastel Features Bar -->
                    <div class="gotpos-advance-features">
                        <button type="button" class="btn-pastel-feature" id="btnPastelPayment" onclick="PosApp.openPaymentModal()" title="Payment">
                            <span class="pastel-circle bg-purple-subtle text-purple"><i class="fa-solid fa-coins"></i></span>
                            <span class="pastel-label">Payment</span>
                        </button>
                        <button type="button" class="btn-pastel-feature" id="btnPastelHold" onclick="document.getElementById('btnHoldOrder')?.click()" title="Hold Order">
                            <span class="pastel-circle bg-success-subtle text-success"><i class="fa-solid fa-pause"></i></span>
                            <span class="pastel-label">Hold</span>
                        </button>
                        <button type="button" class="btn-pastel-feature" id="btnPastelInvoice" onclick="PosApp.switchView('orders')" title="Invoice">
                            <span class="pastel-circle bg-pink-subtle text-pink"><i class="fa-solid fa-file-invoice"></i></span>
                            <span class="pastel-label">Invoice</span>
                        </button>
                        <button type="button" class="btn-pastel-feature" id="btnPastelPayLater" onclick="document.getElementById('btnCartPillQueue')?.click()" title="Pay Later">
                            <span class="pastel-circle bg-secondary-subtle text-secondary"><i class="fa-regular fa-clock"></i></span>
                            <span class="pastel-label">Pay Later</span>
                        </button>
                        <button type="button" class="btn-pastel-feature" id="btnPastelHistory" onclick="PosApp.switchView('orders')" title="History">
                            <span class="pastel-circle bg-danger-subtle text-danger"><i class="fa-solid fa-clock-rotate-left"></i></span>
                            <span class="pastel-label">History</span>
                        </button>
                    </div>

                </div>

            </div>

            `;
    html = html.substring(0, startIdx) + newCartColumn + html.substring(endIdx);
    console.log('4. Replaced pos-cart-column with full GOTPOS Order Panel.');
} else {
    console.warn('Could not locate pos-cart-column boundaries:', startIdx, endIdx);
}

fs.writeFileSync('pos/index.html', html);
console.log('Successfully updated pos/index.html!');

