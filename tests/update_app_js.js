const fs = require('fs');
let code = fs.readFileSync('pos/js/app.js', 'utf8');

// 1. Add GOTPOS customer card listeners in init()
const initMarker = '        // Initialize Saved Customers Directory Panel\n        this.initSavedCustomersPanel();';
const newInitCode = `        // Initialize Saved Customers Directory Panel
        this.initSavedCustomersPanel();

        // GOTPOS Customer Card Click & Reset
        const custCard = document.getElementById('gotposCustomerCard');
        if (custCard) {
            custCard.addEventListener('click', (e) => {
                if (e.target.closest('#btnGotposResetCustomer')) {
                    e.stopPropagation();
                    this.resetCustomer();
                    return;
                }
                this.openCustomerSelectModal();
            });
        }
        const btnEditCust = document.getElementById('btnGotposEditCustomer');
        if (btnEditCust) {
            btnEditCust.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openCustomerSelectModal();
            });
        }
        const btnResetCustCard = document.getElementById('btnGotposResetCustomer');
        if (btnResetCustCard) {
            btnResetCustCard.addEventListener('click', (e) => {
                e.stopPropagation();
                this.resetCustomer();
            });
        }
        this.updateGotposCustomerCard();`;

if (code.includes(initMarker)) {
    code = code.replace(initMarker, newInitCode);
    console.log('1. Added gotpos customer card listeners to init()');
} else {
    console.error('1. FAILED to find initMarker');
}

// 2. Update selectCustomer to call updateGotposCustomerCard and close modalSelectCustomer
const selectMarker = '        // Update UI & Intake Inputs (Task 1)\n        document.getElementById(\'custDropdown\')?.classList.remove(\'open\');\n        this.closeSavedCustomersPanel();';
const newSelectCode = `        // Update UI & Intake Inputs (Task 1)
        document.getElementById('custDropdown')?.classList.remove('open');
        document.getElementById('modalSelectCustomer')?.classList.remove('open');
        this.closeSavedCustomersPanel();`;

if (code.includes(selectMarker)) {
    code = code.replace(selectMarker, newSelectCode);
    console.log('2. Added modalSelectCustomer close to selectCustomer()');
} else {
    console.error('2. FAILED to find selectMarker');
}

// And call updateGotposCustomerCard at end of selectCustomer
const endSelectMarker = '        // Vehicle Recognition - Load Customer & Vehicle Profile (Requirement 4)\n        this.loadVehicleProfile(cleanPlate);\n    }';
const newEndSelectCode = `        // Vehicle Recognition - Load Customer & Vehicle Profile (Requirement 4)
        this.loadVehicleProfile(cleanPlate);

        // Update GOTPOS Customer Card Header
        this.updateGotposCustomerCard();
    }`;

if (code.includes(endSelectMarker)) {
    code = code.replace(endSelectMarker, newEndSelectCode);
    console.log('3. Added updateGotposCustomerCard to selectCustomer()');
} else {
    console.error('3. FAILED to find endSelectMarker');
}

// 3. Update resetCustomer to call updateGotposCustomerCard
const resetMarker = '        document.getElementById(\'profMemberPanel\')?.setAttribute(\'style\', \'display: none;\');\n    }';
const newResetCode = `        document.getElementById('profMemberPanel')?.setAttribute('style', 'display: none;');

        // Reset GOTPOS Customer Card Header
        this.updateGotposCustomerCard();
    }`;

if (code.includes(resetMarker)) {
    code = code.replace(resetMarker, newResetCode);
    console.log('4. Added updateGotposCustomerCard to resetCustomer()');
} else {
    console.error('4. FAILED to find resetMarker');
}

// 4. Update initSavedCustomersPanel
const initPanelOld = `    static initSavedCustomersPanel() {
        const btnOpen = document.getElementById('btnOpenSavedCustomers');
        if (btnOpen) {
            btnOpen.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleSavedCustomersPanel();
            });
        }`;

const initPanelNew = `    static initSavedCustomersPanel() {
        // Search Input in Customer Select Modal
        const searchInp = document.getElementById('selectCustSearchInput');
        const btnClear = document.getElementById('btnClearCustSearch');
        if (searchInp) {
            searchInp.addEventListener('input', (e) => {
                const val = e.target.value || '';
                if (btnClear) btnClear.style.display = val.length > 0 ? 'inline-flex' : 'none';
                this.handleSavedCustomersSearch(val);
            });
        }
        if (btnClear) {
            btnClear.addEventListener('click', () => {
                if (searchInp) {
                    searchInp.value = '';
                    searchInp.focus();
                }
                btnClear.style.display = 'none';
                this.handleSavedCustomersSearch('');
            });
        }

        // Close Button in Customer Select Modal
        const btnCloseModal = document.getElementById('btnCloseSelectCustModal');
        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', () => this.closeCustomerSelectModal());
        }

        // Backdrop Click
        const modalSelect = document.getElementById('modalSelectCustomer');
        if (modalSelect) {
            modalSelect.addEventListener('click', (e) => {
                if (e.target === modalSelect) this.closeCustomerSelectModal();
            });
        }

        // Quick Walk-In Button
        const btnQuickWalkIn = document.getElementById('btnQuickWalkIn');
        if (btnQuickWalkIn) {
            btnQuickWalkIn.addEventListener('click', () => {
                this.setCustomerToWalkIn();
                this.closeCustomerSelectModal();
            });
        }

        // Quick Add Customer Button
        const btnQuickAdd = document.getElementById('btnQuickAddCustomer');
        if (btnQuickAdd) {
            btnQuickAdd.addEventListener('click', () => {
                this.closeCustomerSelectModal();
                document.getElementById('btnOpenNewCustomerModal')?.click();
            });
        }

        // Tab Filter Buttons
        const tabBtns = document.querySelectorAll('.cust-tab-btn');
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const tab = btn.getAttribute('data-cust-tab');
                this.setSavedCustomersTab(tab);
            });
        });

        // No Match Quick Actions
        const btnUseSearchPlate = document.getElementById('btnUseSearchAsPlate');
        if (btnUseSearchPlate) {
            btnUseSearchPlate.addEventListener('click', () => {
                const q = (this.savedCustQuery || '').trim().toUpperCase();
                if (q) {
                    this.selectCustomer(q, 'Walk-in Guest', '', this.selectedVehicleType);
                    this.closeCustomerSelectModal();
                }
            });
        }

        const btnRegisterFromNoMatch = document.getElementById('btnRegisterFromNoMatch');
        if (btnRegisterFromNoMatch) {
            btnRegisterFromNoMatch.addEventListener('click', () => {
                const q = (this.savedCustQuery || '').trim();
                this.closeCustomerSelectModal();
                document.getElementById('btnOpenNewCustomerModal')?.click();
                if (/^\\d+$/.test(q)) {
                    const phoneEl = document.getElementById('newCustPhone');
                    if (phoneEl) phoneEl.value = q;
                } else if (q) {
                    const plateEl = document.getElementById('newCustPlate');
                    if (plateEl) plateEl.value = q.toUpperCase();
                }
            });
        }

        const btnOpen = document.getElementById('btnOpenSavedCustomers');
        if (btnOpen) {
            btnOpen.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openCustomerSelectModal();
            });
        }`;

if (code.includes(initPanelOld)) {
    code = code.replace(initPanelOld, initPanelNew);
    console.log('5. Updated initSavedCustomersPanel() with modal listeners');
} else {
    console.error('5. FAILED to find initPanelOld');
}

// 5. Update openSavedCustomersPanel and closeSavedCustomersPanel
const openPanelOld = `    static openSavedCustomersPanel() {
        if (!this.canAccessCustomerList()) {
            this.showToast('warning', I18nManager.t('accessRestricted') || 'Access restricted');
            return;
        }

        const panel = document.getElementById('panelSavedCustomers');
        const iconBtn = document.getElementById('btnOpenSavedCustomers');
        if (!panel) return;

        // Hide autocomplete dropdown so they do not collide
        document.getElementById('custDropdown')?.classList.remove('open');

        panel.style.display = 'flex';
        if (iconBtn) {
            iconBtn.classList.add('active');
            if (!iconBtn.dataset.origTitle && iconBtn.hasAttribute('title')) {
                iconBtn.dataset.origTitle = iconBtn.getAttribute('title');
            }
            iconBtn.removeAttribute('title');
            iconBtn.blur();
        }

        // Search using main search bar value
        const mainInput = document.getElementById('custSearchInput');
        this.savedCustQuery = (mainInput?.value || '').trim().toLowerCase();

        this.renderSavedCustomersList(true);

        if (mainInput) {
            mainInput.focus();
        }

        const data = PosStorage.getCustomers();
        const isOffline = !navigator.onLine || (typeof this.isOnline !== 'undefined' && !this.isOnline);
        if ((!data.customers || data.customers.length === 0) && !isOffline) {
            this.refreshSavedCustomersList(false);
        }
    }

    static closeSavedCustomersPanel() {
        const panel = document.getElementById('panelSavedCustomers');
        if (panel) panel.style.display = 'none';
        const iconBtn = document.getElementById('btnOpenSavedCustomers');
        if (iconBtn) {
            iconBtn.classList.remove('active');
            if (iconBtn.dataset.origTitle) {
                iconBtn.setAttribute('title', iconBtn.dataset.origTitle);
            }
        }
    }`;

const openPanelNew = `    static openCustomerSelectModal() {
        if (!this.canAccessCustomerList()) {
            this.showToast('warning', I18nManager.t('accessRestricted') || 'Access restricted');
            return;
        }

        const modal = document.getElementById('modalSelectCustomer');
        if (!modal) return;

        // Hide autocomplete dropdown so they do not collide
        document.getElementById('custDropdown')?.classList.remove('open');

        modal.classList.add('open');

        const searchInp = document.getElementById('selectCustSearchInput');
        if (searchInp) {
            searchInp.value = '';
            setTimeout(() => searchInp.focus(), 80);
        }
        const btnClear = document.getElementById('btnClearCustSearch');
        if (btnClear) btnClear.style.display = 'none';

        this.savedCustQuery = '';
        this.renderSavedCustomersList(true);

        const data = PosStorage.getCustomers();
        const isOffline = !navigator.onLine || (typeof this.isOnline !== 'undefined' && !this.isOnline);
        if ((!data.customers || data.customers.length === 0) && !isOffline) {
            this.refreshSavedCustomersList(false);
        }
    }

    static closeCustomerSelectModal() {
        const modal = document.getElementById('modalSelectCustomer');
        if (modal) modal.classList.remove('open');
        this.closeSavedCustomersPanel();
    }

    static openSavedCustomersPanel() {
        this.openCustomerSelectModal();
    }

    static closeSavedCustomersPanel() {
        const modal = document.getElementById('modalSelectCustomer');
        if (modal) modal.classList.remove('open');
        const panel = document.getElementById('panelSavedCustomers');
        if (panel) panel.style.display = 'none';
        const iconBtn = document.getElementById('btnOpenSavedCustomers');
        if (iconBtn) {
            iconBtn.classList.remove('active');
            if (iconBtn.dataset.origTitle) {
                iconBtn.setAttribute('title', iconBtn.dataset.origTitle);
            }
        }
    }

    static updateGotposCustomerCard() {
        const nameDisplay = document.getElementById('gotposCustNameDisplay');
        const tierDisplay = document.getElementById('gotposCustTierDisplay');
        const avatarEl = document.getElementById('gotposCustAvatar');
        const btnReset = document.getElementById('btnGotposResetCustomer');
        const isKm = (I18nManager.getLang() === 'km');

        if (!this.currentCustomer || !this.currentCustomer.name || this.currentCustomer.name === 'Walk-in Guest') {
            if (nameDisplay) nameDisplay.textContent = isKm ? 'ភ្ញៀវទូទៅ' : 'Walk-in Guest';
            if (tierDisplay) {
                const plateStr = this.currentCustomer?.plate ? \` • \${this.currentCustomer.plate}\` : '';
                tierDisplay.textContent = (isKm ? 'អតិថិជនធម្មតា' : 'Standard Guest') + plateStr;
            }
            if (avatarEl) {
                avatarEl.innerHTML = '<i class="fa-solid fa-user"></i>';
                avatarEl.style.backgroundColor = '';
                avatarEl.style.color = '';
            }
            if (btnReset) btnReset.style.display = 'none';
            return;
        }

        const cust = this.currentCustomer;
        if (nameDisplay) nameDisplay.textContent = cust.name;

        let tier = 'Standard';
        let points = 0;
        if (cust.customer_id) {
            const allCusts = PosStorage.getCustomers()?.customers || [];
            const found = allCusts.find(c => c.id === cust.customer_id);
            if (found) {
                tier = found.membership_level || 'Standard';
                points = parseInt(found.loyalty_points || 0);
            }
        }

        const tierUpper = tier.charAt(0).toUpperCase() + tier.slice(1).toLowerCase();
        const plateStr = cust.plate ? \` • \${cust.plate}\` : '';
        const ptsStr = points > 0 ? \` • \${points} Pts\` : '';

        if (tierDisplay) {
            tierDisplay.textContent = \`\${tierUpper} Member\${plateStr}\${ptsStr}\`;
        }

        if (avatarEl) {
            const initials = this.getCustomerInitials(cust.name);
            avatarEl.textContent = initials;
            const lvl = tier.toLowerCase();
            if (lvl === 'vip' || lvl === 'gold') {
                avatarEl.style.backgroundColor = '#fef3c7';
                avatarEl.style.color = '#d97706';
            } else if (lvl === 'platinum') {
                avatarEl.style.backgroundColor = '#ede9fe';
                avatarEl.style.color = '#7c3aed';
            } else if (lvl === 'silver') {
                avatarEl.style.backgroundColor = '#f1f5f9';
                avatarEl.style.color = '#475569';
            } else {
                avatarEl.style.backgroundColor = '#e0f2fe';
                avatarEl.style.color = '#0284c7';
            }
        }

        if (btnReset) btnReset.style.display = 'inline-flex';
    }`;

if (code.includes(openPanelOld)) {
    code = code.replace(openPanelOld, openPanelNew);
    console.log('6. Replaced openSavedCustomersPanel with openCustomerSelectModal & updateGotposCustomerCard');
} else {
    console.error('6. FAILED to find openPanelOld');
}

// 6. Update filterAndSortCustomers to support tabs
const filterOld = `        const q = this.savedCustQuery;
        if (q) {
            enriched = enriched.filter(c => {
                const matchName = (c.name || '').toLowerCase().includes(q);
                const matchPhone = (c.phone || '').toLowerCase().includes(q);
                const matchVehicle = c.vehicles.some(v =>
                    (v.plate_number || '').toLowerCase().includes(q) ||
                    (v.make_model || '').toLowerCase().includes(q) ||
                    (v.vehicle_type || '').toLowerCase().includes(q)
                );
                return matchName || matchPhone || matchVehicle;
            });
        }`;

const filterNew = `        const q = this.savedCustQuery;
        if (q) {
            enriched = enriched.filter(c => {
                const matchName = (c.name || '').toLowerCase().includes(q);
                const matchPhone = (c.phone || '').toLowerCase().includes(q);
                const matchVehicle = c.vehicles.some(v =>
                    (v.plate_number || '').toLowerCase().includes(q) ||
                    (v.make_model || '').toLowerCase().includes(q) ||
                    (v.vehicle_type || '').toLowerCase().includes(q)
                );
                return matchName || matchPhone || matchVehicle;
            });
        }

        const tab = this.savedCustActiveTab || 'all';
        if (tab === 'vip') {
            enriched = enriched.filter(c => {
                const lvl = (c.membership_level || '').toLowerCase();
                return lvl === 'vip' || lvl === 'gold' || lvl === 'platinum' || (c.name || '').toLowerCase().includes('vip');
            });
        } else if (tab === 'silver') {
            enriched = enriched.filter(c => (c.membership_level || '').toLowerCase() === 'silver');
        } else if (tab === 'standard') {
            enriched = enriched.filter(c => (c.membership_level || '').toLowerCase() === 'standard');
        } else if (tab === 'vehicles') {
            enriched = enriched.filter(c => c.vehicles && c.vehicles.length > 0);
        }`;

if (code.includes(filterOld)) {
    code = code.replace(filterOld, filterNew);
    console.log('7. Updated filterAndSortCustomers with tab filtering');
} else {
    console.error('7. FAILED to find filterOld');
}

// 7. Update renderSavedCustomersList for tab counts & no match quick actions
const renderListOld = `        const filtered = this.filterAndSortCustomers();

        if (filtered.length === 0) {
            if (noMatch) {
                noMatch.style.display = 'block';
                noMatch.textContent = I18nManager.t('noSavedCustomers') || 'No saved customers found';
            }
            if (itemsBox) itemsBox.style.display = 'none';
            if (loadMoreIndicator) loadMoreIndicator.style.display = 'none';
            return;
        } else {
            if (noMatch) noMatch.style.display = 'none';
            if (itemsBox) itemsBox.style.display = 'flex';
        }`;

const renderListNew = `        // Update Tab Counts
        const allCusts = (data.customers || []);
        const countAllEl = document.getElementById('countCustAll');
        if (countAllEl) countAllEl.textContent = allCusts.length;
        const countVipEl = document.getElementById('countCustVip');
        if (countVipEl) {
            countVipEl.textContent = allCusts.filter(c => {
                const lvl = (c.membership_level || '').toLowerCase();
                return lvl === 'vip' || lvl === 'gold' || lvl === 'platinum' || (c.name || '').toLowerCase().includes('vip');
            }).length;
        }
        const countSilverEl = document.getElementById('countCustSilver');
        if (countSilverEl) {
            countSilverEl.textContent = allCusts.filter(c => (c.membership_level || '').toLowerCase() === 'silver').length;
        }

        const filtered = this.filterAndSortCustomers();

        if (filtered.length === 0) {
            if (noMatch) {
                noMatch.style.display = 'block';
                const noCustActions = document.getElementById('noMatchQuickActions');
                const lblPlate = document.getElementById('lblUseAsPlate');
                const lblReg = document.getElementById('lblRegisterNewPlate');
                if (this.savedCustQuery && this.savedCustQuery.length >= 2) {
                    if (noCustActions) noCustActions.style.display = 'flex';
                    if (lblPlate) lblPlate.textContent = \`Use "\${this.savedCustQuery.toUpperCase()}" as Walk-in\`;
                    if (lblReg) lblReg.textContent = \`+ Register "\${this.savedCustQuery}"\`;
                } else {
                    if (noCustActions) noCustActions.style.display = 'none';
                }
            }
            if (itemsBox) itemsBox.style.display = 'none';
            if (loadMoreIndicator) loadMoreIndicator.style.display = 'none';
            return;
        } else {
            if (noMatch) noMatch.style.display = 'none';
            if (itemsBox) itemsBox.style.display = 'flex';
        }`;

if (code.includes(renderListOld)) {
    code = code.replace(renderListOld, renderListNew);
    console.log('8. Updated renderSavedCustomersList with counts & no match actions');
} else {
    console.error('8. FAILED to find renderListOld');
}

fs.writeFileSync('pos/js/app.js', code, 'utf8');
console.log('Successfully written updated pos/js/app.js');
