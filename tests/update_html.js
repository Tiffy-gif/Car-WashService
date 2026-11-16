const fs = require('fs');
let html = fs.readFileSync('pos/index.html', 'utf8');

// 1. Remove old panelSavedCustomers from inside posCustomerIntakeCard
const oldPanelRegex = /<!-- Saved Customers Dropdown Panel -->[\s\S]*?<!-- Backward compatibility hidden wrapper for selectedCustBadgeWrap -->/;
if (oldPanelRegex.test(html)) {
    html = html.replace(oldPanelRegex, '<!-- Backward compatibility hidden wrapper for selectedCustBadgeWrap -->');
    console.log('Removed old panelSavedCustomers');
} else {
    console.log('Could not find old panelSavedCustomers pattern');
}

// 2. Add modalSelectCustomer right before modalCustomer
const modalSelectCustHtml = `    <!-- Modal: Select Saved Customer (GOTPOS Modern UI) -->
    <div class="modal-backdrop" id="modalSelectCustomer">
        <div class="modal-content modal-customer-select-content">
            <div class="modal-header">
                <div class="modal-title-with-icon" style="display: flex; align-items: center; gap: 0.65rem;">
                    <i class="fa-solid fa-users text-primary" style="font-size: 1.25rem; color: #ff5b1a;"></i>
                    <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700;" data-i18n="savedCustomersTitle">Select Customer</h3>
                </div>
                <button type="button" class="btn btn-outline btn-sm btn-icon" id="btnCloseSelectCustModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body p-0">
                <!-- Search & Quick Action Header -->
                <div class="select-cust-top-bar">
                    <div class="select-cust-search-wrap">
                        <i class="fa-solid fa-magnifying-glass select-cust-search-icon"></i>
                        <input type="text" id="selectCustSearchInput" class="select-cust-search-input"
                            placeholder="Search name, phone, or license plate..." autocomplete="off">
                        <button type="button" id="btnClearCustSearch" class="btn-clear-search" style="display: none;">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div class="select-cust-actions-row">
                        <button type="button" class="btn btn-outline btn-walkin-quick" id="btnQuickWalkIn">
                            <i class="fa-solid fa-person-walking"></i>
                            <span data-i18n="walkInGuest">Walk-in</span>
                        </button>
                        <button type="button" class="btn btn-primary btn-add-cust-quick" id="btnQuickAddCustomer">
                            <i class="fa-solid fa-user-plus"></i>
                            <span data-i18n="addCustomer">+ New</span>
                        </button>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="select-cust-tabs-row">
                    <button type="button" class="cust-tab-btn active" data-cust-tab="all">
                        <span>All</span> <span class="cust-tab-count" id="countCustAll">0</span>
                    </button>
                    <button type="button" class="cust-tab-btn" data-cust-tab="vip">
                        <i class="fa-solid fa-crown text-amber"></i> <span>VIP / Gold</span> <span class="cust-tab-count" id="countCustVip">0</span>
                    </button>
                    <button type="button" class="cust-tab-btn" data-cust-tab="silver">
                        <i class="fa-solid fa-medal text-slate"></i> <span>Silver</span> <span class="cust-tab-count" id="countCustSilver">0</span>
                    </button>
                    <button type="button" class="cust-tab-btn" data-cust-tab="standard">
                        <i class="fa-solid fa-shield-halved"></i> <span>Standard</span>
                    </button>
                    <button type="button" class="cust-tab-btn" data-cust-tab="vehicles">
                        <i class="fa-solid fa-car"></i> <span>Has Vehicles</span>
                    </button>
                </div>

                <!-- Customer Directory List -->
                <div class="select-cust-list-wrap" id="savedCustListWrap">
                    <div id="savedCustLoading" style="display: none;" class="saved-cust-state-msg">
                        <i class="fa-solid fa-circle-notch fa-spin mr-1"></i> <span data-i18n="loadingCustomers">Loading...</span>
                    </div>
                    <div id="savedCustNoMatch" style="display: none;" class="saved-cust-state-msg" data-i18n="noSavedCustomers">
                        <div class="no-match-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                        <div class="no-match-text">No saved customer matches your search.</div>
                        <div class="no-match-actions" id="noMatchQuickActions" style="display: none; margin-top: 0.75rem; gap: 0.5rem; justify-content: center;">
                            <button type="button" class="btn btn-outline btn-sm" id="btnUseSearchAsPlate">
                                <i class="fa-solid fa-car"></i> <span id="lblUseAsPlate">Use as Walk-in Plate</span>
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" id="btnRegisterFromNoMatch">
                                <i class="fa-solid fa-user-plus"></i> <span id="lblRegisterNewPlate">+ Register Customer</span>
                            </button>
                        </div>
                    </div>
                    <div class="saved-cust-items" id="savedCustItems"></div>
                    <div class="saved-cust-load-more" id="savedCustLoadMore" style="display: none;">
                        <i class="fa-solid fa-spinner fa-spin mr-1"></i> <span data-i18n="loadingMore">Loading more...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>\n\n`;

const targetModalCustomer = '    <!-- Modal 1: Quick Add Customer & Vehicle -->';
if (html.includes(targetModalCustomer)) {
    html = html.replace(targetModalCustomer, modalSelectCustHtml + targetModalCustomer);
    console.log('Added modalSelectCustomer');
} else {
    console.log('Could not find targetModalCustomer');
}

fs.writeFileSync('pos/index.html', html, 'utf8');
console.log('Successfully updated pos/index.html');
