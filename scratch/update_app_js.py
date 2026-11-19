with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Add call to this.initCashierActionsListeners() inside bindEvents()
target_call = "        // Shift Badge in top bar\n        document.getElementById('posShiftStatusBadge')?.addEventListener('click',"
replacement_call = "        // Cashier Actions Listeners\n        this.initCashierActionsListeners();\n\n        // Shift Badge in top bar\n        document.getElementById('posShiftStatusBadge')?.addEventListener('click',"

if target_call in content:
    content = content.replace(target_call, replacement_call, 1)
    print("Call to initCashierActionsListeners() added to bindEvents().")
else:
    print("WARNING: target_call not found!")

# 2. Add methods right before the closing brace of class PosApp
methods_code = '''
    // ========================================================
    // CASHIER ACTIONS DASHBOARD & CONTROLS
    // ========================================================

    static initCashierActionsListeners() {
        // Open Cashier Actions Modal
        document.getElementById('btnOpenCashierActions')?.addEventListener('click', () => {
            this.showCashierActionsModal();
        });
        document.getElementById('userMenuCashierActions')?.addEventListener('click', () => {
            const dropdown = document.getElementById('userMenuDropdown');
            if (dropdown) dropdown.style.display = 'none';
            document.getElementById('userPill')?.classList.remove('active');
            this.showCashierActionsModal();
        });

        // Close Cashier Actions Modal
        const closeCashierActions = () => {
            document.getElementById('modalCashierActions')?.classList.remove('open');
        };
        document.getElementById('btnCloseCashierActions')?.addEventListener('click', closeCashierActions);
        document.getElementById('btnDismissCashierActions')?.addEventListener('click', closeCashierActions);

        // 10 Action Buttons
        document.getElementById('btnActionCashIn')?.addEventListener('click', () => {
            closeCashierActions();
            this.showCashMovementModal('cash_in');
        });
        document.getElementById('btnActionCashOut')?.addEventListener('click', () => {
            closeCashierActions();
            this.showCashMovementModal('cash_out');
        });
        document.getElementById('btnActionOpenDrawer')?.addEventListener('click', () => {
            closeCashierActions();
            this.showOpenDrawerModal();
        });
        document.getElementById('btnActionVoidReceipt')?.addEventListener('click', () => {
            closeCashierActions();
            this.showVoidReceiptModal();
        });
        document.getElementById('btnActionReprintReceipt')?.addEventListener('click', () => {
            closeCashierActions();
            this.showReprintReceiptModal();
        });
        document.getElementById('btnActionEditFloat')?.addEventListener('click', () => {
            closeCashierActions();
            this.showEditOpeningFloatModal();
        });
        document.getElementById('btnActionLockScreen')?.addEventListener('click', () => {
            closeCashierActions();
            this.handleActionLockScreen();
        });
        document.getElementById('btnActionSwitchUser')?.addEventListener('click', () => {
            closeCashierActions();
            this.handleActionSwitchUser();
        });
        document.getElementById('btnActionEndShift')?.addEventListener('click', () => {
            closeCashierActions();
            this.handleActionEndShift();
        });
        document.getElementById('btnActionEndDay')?.addEventListener('click', () => {
            closeCashierActions();
            this.handleActionEndDay();
        });

        // Cash Movement Sub-Modal
        const closeCashMovement = () => {
            document.getElementById('modalCashMovement')?.classList.remove('open');
        };
        document.getElementById('btnCloseCashMovementModal')?.addEventListener('click', closeCashMovement);
        document.getElementById('btnCancelCashMovement')?.addEventListener('click', closeCashMovement);
        document.getElementById('btnSubmitCashMovement')?.addEventListener('click', () => this.handleCashMovementSubmit());

        // Quick Preset Chips for Cash Movement
        document.querySelectorAll('.cash-preset-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                const addUsd = parseFloat(chip.dataset.usd || 0);
                const addKhr = parseInt(chip.dataset.khr || 0);
                const usdInp = document.getElementById('cashMovementUsdInput');
                const khrInp = document.getElementById('cashMovementKhrInput');
                if (addUsd > 0 && usdInp) {
                    const cur = parseFloat(usdInp.value || 0);
                    usdInp.value = (cur + addUsd).toFixed(2);
                }
                if (addKhr > 0 && khrInp) {
                    const cur = parseInt(khrInp.value || 0);
                    khrInp.value = cur + addKhr;
                }
                this.checkCashOutManagerAuthRequired();
            });
        });

        document.getElementById('cashMovementUsdInput')?.addEventListener('input', () => this.checkCashOutManagerAuthRequired());
        document.getElementById('cashMovementKhrInput')?.addEventListener('input', () => this.checkCashOutManagerAuthRequired());

        // Open Drawer Sub-Modal
        const closeOpenDrawer = () => {
            document.getElementById('modalOpenDrawerPrompt')?.classList.remove('open');
        };
        document.getElementById('btnCloseOpenDrawerModal')?.addEventListener('click', closeOpenDrawer);
        document.getElementById('btnCancelOpenDrawer')?.addEventListener('click', closeOpenDrawer);
        document.getElementById('btnSubmitOpenDrawer')?.addEventListener('click', () => this.handleOpenDrawerSubmit());

        // Void Receipt Sub-Modal
        const closeVoidModal = () => {
            document.getElementById('modalVoidPrompt')?.classList.remove('open');
        };
        document.getElementById('btnCloseVoidModal')?.addEventListener('click', closeVoidModal);
        document.getElementById('btnCancelVoidModal')?.addEventListener('click', closeVoidModal);
        document.getElementById('btnSubmitVoid')?.addEventListener('click', () => this.handleVoidSubmit());
        document.getElementById('voidOrderSearchInput')?.addEventListener('input', (e) => this.filterVoidOrdersList(e.target.value));

        // Reprint Receipt Sub-Modal
        const closeReprintModal = () => {
            document.getElementById('modalReprintPrompt')?.classList.remove('open');
        };
        document.getElementById('btnCloseReprintModal')?.addEventListener('click', closeReprintModal);
        document.getElementById('btnDismissReprintModal')?.addEventListener('click', closeReprintModal);
        document.getElementById('reprintOrderSearchInput')?.addEventListener('input', (e) => this.filterReprintOrdersList(e.target.value));

        // Edit Opening Float Sub-Modal
        const closeEditFloat = () => {
            document.getElementById('modalEditOpeningFloat')?.classList.remove('open');
        };
        document.getElementById('btnCloseEditFloatModal')?.addEventListener('click', closeEditFloat);
        document.getElementById('btnCancelEditFloat')?.addEventListener('click', closeEditFloat);
        document.getElementById('btnSubmitEditFloat')?.addEventListener('click', () => this.handleEditOpeningFloatSubmit());
    }

    static checkCashierActionAllowed() {
        if (this.shiftBadgeState === 'day_closed') {
            this.showAppAlert('Business day is closed. No cashier actions are permitted.', 'warning', 'Day Closed');
            return false;
        }
        const shift = this.activeShift || PosStorage.getCurrentShift();
        if (!shift || !shift.id || this.shiftBadgeState === 'no_shift') {
            this.showAppAlert('No active shift is open. Please open your shift first before performing cashier actions.', 'warning', 'Shift Required');
            return false;
        }
        return true;
    }

    static showCashierActionsModal() {
        const shift = this.activeShift || PosStorage.getCurrentShift();
        const statusEl = document.getElementById('cashierActionsShiftStatus');
        if (statusEl) {
            if (this.shiftBadgeState === 'day_closed') {
                statusEl.innerHTML = '<span style="color: #dc2626; font-weight: 700;"><i class="fa-solid fa-lock"></i> Day Closed (Sales Locked)</span>';
            } else if (shift && shift.id && this.shiftBadgeState !== 'no_shift') {
                const shiftId = shift.id || shift.shift_id;
                const cashierName = this.currentUser?.full_name || 'Cashier';
                statusEl.innerHTML = `<span style="color: #059669; font-weight: 700;"><i class="fa-solid fa-circle-check"></i> Shift #${shiftId} Active</span> • Cashier: ${cashierName}`;
            } else {
                statusEl.innerHTML = '<span style="color: #dc2626; font-weight: 700;"><i class="fa-solid fa-triangle-exclamation"></i> No Active Shift (Open Shift First)</span>';
            }
        }
        document.getElementById('modalCashierActions')?.classList.add('open');
    }

    // 1 & 2. Cash In & Cash Out
    static showCashMovementModal(type = 'cash_in') {
        if (!this.checkCashierActionAllowed()) return;
        this.currentCashMovementType = type;

        const header = document.getElementById('cashMovementHeader');
        const icon = document.getElementById('cashMovementHeaderIcon');
        const title = document.getElementById('cashMovementTitle');
        const subtitle = document.getElementById('cashMovementSubtitle');
        const btnText = document.getElementById('cashMovementSubmitBtnText');
        const btnSubmit = document.getElementById('btnSubmitCashMovement');

        document.getElementById('cashMovementUsdInput').value = '';
        document.getElementById('cashMovementKhrInput').value = '';
        document.getElementById('cashMovementReasonInput').value = '';
        document.getElementById('cashMovementMgrUser').value = '';
        document.getElementById('cashMovementMgrPin').value = '';
        document.getElementById('cashMovementMgrAuthBox').style.display = 'none';

        if (type === 'cash_in') {
            if (header) { header.style.background = '#f0fdf4'; header.style.borderColor = '#bbf7d0'; }
            if (icon) { icon.style.background = '#dcfce7'; icon.style.color = '#16a34a'; icon.innerHTML = '<i class="fa-solid fa-arrow-down-to-bracket"></i>'; }
            if (title) { title.textContent = 'Cash In (បង់ប្រាក់ចូលថត)'; title.style.color = '#15803d'; }
            if (subtitle) { subtitle.textContent = 'Record cash added to register drawer'; subtitle.style.color = '#166534'; }
            if (btnText) btnText.textContent = 'Confirm Cash In';
            if (btnSubmit) { btnSubmit.style.background = '#16a34a'; btnSubmit.style.borderColor = '#16a34a'; }
        } else {
            if (header) { header.style.background = '#fffbeb'; header.style.borderColor = '#fde68a'; }
            if (icon) { icon.style.background = '#fef3c7'; icon.style.color = '#d97706'; icon.innerHTML = '<i class="fa-solid fa-arrow-up-from-bracket"></i>'; }
            if (title) { title.textContent = 'Cash Out / Expense (ដកប្រាក់ចំណាយ)'; title.style.color = '#b45309'; }
            if (subtitle) { subtitle.textContent = 'Record store expense or cash payout from drawer'; subtitle.style.color = '#92400e'; }
            if (btnText) btnText.textContent = 'Confirm Cash Out';
            if (btnSubmit) { btnSubmit.style.background = '#d97706'; btnSubmit.style.borderColor = '#d97706'; }
        }

        document.getElementById('modalCashMovement')?.classList.add('open');
    }

    static checkCashOutManagerAuthRequired() {
        if (this.currentCashMovementType !== 'cash_out') {
            const box = document.getElementById('cashMovementMgrAuthBox');
            if (box) box.style.display = 'none';
            return;
        }
        const usd = parseFloat(document.getElementById('cashMovementUsdInput')?.value || 0);
        const khr = parseInt(document.getElementById('cashMovementKhrInput')?.value || 0);
        const rate = 4100;
        const totalUsd = usd + (khr > 0 ? (khr / rate) : 0);
        const box = document.getElementById('cashMovementMgrAuthBox');
        if (box) {
            box.style.display = (totalUsd > 10.00) ? 'block' : 'none';
        }
    }

    static async handleCashMovementSubmit() {
        if (!this.checkCashierActionAllowed()) return;
        const type = this.currentCashMovementType || 'cash_out';
        const usd = parseFloat(document.getElementById('cashMovementUsdInput')?.value || 0);
        const khr = parseInt(document.getElementById('cashMovementKhrInput')?.value || 0);
        const reason = document.getElementById('cashMovementReasonInput')?.value.trim();

        if (usd <= 0 && khr <= 0) {
            this.showAppAlert('Please enter an amount in USD or KHR.', 'warning', 'Amount Required');
            return;
        }
        if (!reason) {
            this.showAppAlert('Please enter a reason or description for this cash movement.', 'warning', 'Reason Required');
            return;
        }

        const rate = 4100;
        const totalUsd = usd + (khr > 0 ? (khr / rate) : 0);
        let mgrUser = '';
        let mgrPin = '';

        if (type === 'cash_out' && totalUsd > 10.00) {
            mgrUser = document.getElementById('cashMovementMgrUser')?.value.trim();
            mgrPin = document.getElementById('cashMovementMgrPin')?.value.trim();
            if (!mgrUser || !mgrPin) {
                this.showAppAlert('Manager username and PIN are required for Cash Out greater than $10.', 'warning', 'Manager PIN Required');
                return;
            }
        }

        const btn = document.getElementById('btnSubmitCashMovement');
        if (btn) btn.disabled = true;

        try {
            const shift = this.activeShift || PosStorage.getCurrentShift();
            const res = await PosApi.recordCashMovement({
                type,
                amountUsd: usd,
                amountKhr: khr,
                reason,
                cashierId: this.currentUser.id,
                managerUsername: mgrUser,
                managerPin: mgrPin
            });

            if (res && res.success) {
                document.getElementById('modalCashMovement')?.classList.remove('open');
                this.showAppAlert(res.message || 'Cash movement recorded successfully.', 'success', 'Success');

                // Print thermal voucher slip
                if (window.PosThermalPrinter && PosThermalPrinter.printCashMovementSlip) {
                    const shop = PosConfig.get()?.shop || {};
                    PosThermalPrinter.printCashMovementSlip({
                        type,
                        amount_usd: usd,
                        amount_khr: khr,
                        reason,
                        event_uid: res.event_uid,
                        shift_id: res.shift_id || shift?.id,
                        cashier_name: res.cashier_name || this.currentUser.full_name,
                        manager_name: res.manager_name || (mgrUser || null),
                        created_at: res.created_at || new Date().toISOString(),
                        shop
                    });
                }

                // Refresh shift status
                this.syncActiveShift();
            } else {
                this.showAppAlert(res?.error || 'Failed to record cash movement.', 'danger', 'Error');
            }
        } catch (err) {
            this.showAppAlert(err.message || 'Failed to record cash movement.', 'danger', 'Error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // 3. Open Drawer (No Sale)
    static showOpenDrawerModal() {
        if (!this.checkCashierActionAllowed()) return;
        document.getElementById('openDrawerReasonInput').value = '';
        document.getElementById('openDrawerMgrUser').value = '';
        document.getElementById('openDrawerMgrPin').value = '';
        document.getElementById('modalOpenDrawerPrompt')?.classList.add('open');
    }

    static async handleOpenDrawerSubmit() {
        if (!this.checkCashierActionAllowed()) return;
        const reason = document.getElementById('openDrawerReasonInput')?.value.trim();
        const mgrUser = document.getElementById('openDrawerMgrUser')?.value.trim();
        const mgrPin = document.getElementById('openDrawerMgrPin')?.value.trim();

        if (!reason) {
            this.showAppAlert('Please enter a reason for opening the drawer without a sale.', 'warning', 'Reason Required');
            return;
        }
        if (!mgrUser || !mgrPin) {
            this.showAppAlert('Manager username and PIN are required.', 'warning', 'Manager PIN Required');
            return;
        }

        const btn = document.getElementById('btnSubmitOpenDrawer');
        if (btn) btn.disabled = true;

        try {
            const res = await PosApi.openDrawerNoSale({
                reason,
                managerUsername: mgrUser,
                managerPin: mgrPin,
                cashierId: this.currentUser.id
            });

            if (res && res.success) {
                document.getElementById('modalOpenDrawerPrompt')?.classList.remove('open');
                this.showAppAlert(res.message || 'Cash drawer opened and logged.', 'success', 'Drawer Opened');

                // Send drawer kick command to thermal printer
                if (window.PosThermalPrinter) {
                    const kickBytes = new Uint8Array([0x1B, 0x70, 0x00, 0x19, 0xFA]);
                    PosThermalPrinter.sendPayload(kickBytes).catch(e => console.warn('Drawer kick signal error:', e));
                }
            } else {
                this.showAppAlert(res?.error || 'Failed to open drawer.', 'danger', 'Error');
            }
        } catch (err) {
            this.showAppAlert(err.message || 'Failed to open drawer.', 'danger', 'Error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // 4. Void Receipt
    static async showVoidReceiptModal() {
        if (!this.checkCashierActionAllowed()) return;
        this.selectedVoidOrder = null;
        document.getElementById('voidSelectedOrderInfo').style.display = 'none';
        document.getElementById('voidOrderSearchInput').value = '';
        document.getElementById('voidReasonInput').value = '';
        document.getElementById('voidMgrUser').value = '';
        document.getElementById('voidMgrPin').value = '';
        document.getElementById('modalVoidPrompt')?.classList.add('open');
        await this.loadVoidOrdersList();
    }

    static async loadVoidOrdersList() {
        const container = document.getElementById('voidOrdersListContainer');
        if (!container) return;
        container.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;"><i class="fa-solid fa-spinner fa-spin"></i> Loading today orders...</div>';

        try {
            const res = await PosApi.getTodayOrders();
            const orders = (res && res.orders) ? res.orders : (Array.isArray(res) ? res : []);
            this.cachedVoidOrders = orders.filter(o => o.status !== 'cancelled');
            this.renderVoidOrdersList(this.cachedVoidOrders);
        } catch (err) {
            container.innerHTML = `<div style="padding: 1rem; text-align: center; color: #dc2626; font-size: 0.85rem;">Failed to load orders: ${err.message}</div>`;
        }
    }

    static renderVoidOrdersList(orders) {
        const container = document.getElementById('voidOrdersListContainer');
        if (!container) return;
        if (!orders || orders.length === 0) {
            container.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">No active orders found for today.</div>';
            return;
        }

        container.innerHTML = orders.slice(0, 15).map(o => `
            <div class="void-order-row" data-order-id="${o.id}" style="padding: 8px 12px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.15s ease;">
                <div>
                    <div style="font-weight: 800; font-size: 0.88rem; color: var(--text-main);">#${o.order_number} • <span style="color: var(--primary);">${o.plate_number || 'No Plate'}</span></div>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">${o.customer_name || 'Walk-in'} • ${o.created_at ? new Date(o.created_at.replace(/-/g, '/')).toLocaleTimeString() : ''}</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-weight: 850; font-size: 0.92rem; color: #059669;">$${parseFloat(o.total_usd || 0).toFixed(2)}</div>
                    <span style="font-size: 0.7rem; padding: 1px 6px; border-radius: 4px; background: #f1f5f9; color: #475569; text-transform: uppercase;">${o.payment_method || 'Cash'}</span>
                </div>
            </div>
        `).join('');

        container.querySelectorAll('.void-order-row').forEach(row => {
            row.addEventListener('click', () => {
                const oid = parseInt(row.dataset.orderId);
                const ord = this.cachedVoidOrders.find(o => o.id === oid);
                if (ord) this.selectOrderForVoid(ord, row);
            });
        });
    }

    static selectOrderForVoid(order, selectedRow) {
        this.selectedVoidOrder = order;
        document.querySelectorAll('.void-order-row').forEach(r => r.style.background = 'transparent');
        if (selectedRow) selectedRow.style.background = '#fef2f2';

        const infoBox = document.getElementById('voidSelectedOrderInfo');
        if (infoBox) {
            infoBox.style.display = 'block';
            document.getElementById('voidSelectedOrderNum').textContent = `#${order.order_number}`;
            document.getElementById('voidSelectedOrderTotal').textContent = `$${parseFloat(order.total_usd || 0).toFixed(2)} USD`;
            document.getElementById('voidSelectedOrderDetails').textContent = `Plate: ${order.plate_number || 'N/A'} • Payment: ${order.payment_method || 'Cash'} • Items: ${order.item_names || 'Services'}`;
        }
    }

    static filterVoidOrdersList(query) {
        if (!this.cachedVoidOrders) return;
        const q = (query || '').toLowerCase().trim();
        if (!q) {
            this.renderVoidOrdersList(this.cachedVoidOrders);
            return;
        }
        const filtered = this.cachedVoidOrders.filter(o =>
            (o.order_number || '').toLowerCase().includes(q) ||
            (o.plate_number || '').toLowerCase().includes(q) ||
            (o.customer_name || '').toLowerCase().includes(q)
        );
        this.renderVoidOrdersList(filtered);
    }

    static async handleVoidSubmit() {
        if (!this.checkCashierActionAllowed()) return;
        if (!this.selectedVoidOrder) {
            this.showAppAlert('Please select an order to void.', 'warning', 'Select Order');
            return;
        }
        const reason = document.getElementById('voidReasonInput')?.value.trim();
        const mgrUser = document.getElementById('voidMgrUser')?.value.trim();
        const mgrPin = document.getElementById('voidMgrPin')?.value.trim();

        if (!reason || reason.length < 5) {
            this.showAppAlert('Reason for cancellation must be at least 5 characters.', 'warning', 'Reason Too Short');
            return;
        }
        if (!mgrUser || !mgrPin) {
            this.showAppAlert('Manager username and PIN are required to authorize voiding.', 'warning', 'Manager PIN Required');
            return;
        }

        const btn = document.getElementById('btnSubmitVoid');
        if (btn) btn.disabled = true;

        try {
            const shift = this.activeShift || PosStorage.getCurrentShift();
            const res = await PosApi.cancelOrder(
                this.selectedVoidOrder.id,
                this.currentUser.id,
                reason,
                mgrUser,
                mgrPin
            );

            if (res && res.success) {
                document.getElementById('modalVoidPrompt')?.classList.remove('open');
                this.showAppAlert(res.message || 'Order voided and stock restored successfully.', 'success', 'Order Voided');

                // Print Void Thermal Slip
                if (window.PosThermalPrinter && PosThermalPrinter.printVoidSlip) {
                    const shop = PosConfig.get()?.shop || {};
                    PosThermalPrinter.printVoidSlip({
                        order_number: this.selectedVoidOrder.order_number,
                        plate_number: this.selectedVoidOrder.plate_number,
                        total_usd: this.selectedVoidOrder.total_usd,
                        shift_id: shift?.id,
                        reason: reason,
                        cashier_name: this.currentUser.full_name,
                        manager_name: mgrUser,
                        cancelled_at: new Date().toISOString(),
                        shop
                    });
                }

                // Refresh queue and sync
                this.syncQueue();
                this.syncActiveShift();
            } else {
                this.showAppAlert(res?.error || 'Failed to void order.', 'danger', 'Error');
            }
        } catch (err) {
            this.showAppAlert(err.message || 'Failed to void order.', 'danger', 'Error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // 5. Reprint Receipt
    static async showReprintReceiptModal() {
        if (!this.checkCashierActionAllowed()) return;
        document.getElementById('reprintOrderSearchInput').value = '';
        document.getElementById('modalReprintPrompt')?.classList.add('open');
        await this.loadReprintOrdersList();
    }

    static async loadReprintOrdersList() {
        const container = document.getElementById('reprintOrdersListContainer');
        if (!container) return;
        container.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;"><i class="fa-solid fa-spinner fa-spin"></i> Loading paid orders...</div>';

        try {
            const res = await PosApi.getTodayOrders();
            const orders = (res && res.orders) ? res.orders : (Array.isArray(res) ? res : []);
            this.cachedReprintOrders = orders.filter(o => o.status === 'paid' || o.payment_status === 'paid');
            this.renderReprintOrdersList(this.cachedReprintOrders);
        } catch (err) {
            container.innerHTML = `<div style="padding: 1rem; text-align: center; color: #dc2626; font-size: 0.85rem;">Failed to load orders: ${err.message}</div>`;
        }
    }

    static renderReprintOrdersList(orders) {
        const container = document.getElementById('reprintOrdersListContainer');
        if (!container) return;
        if (!orders || orders.length === 0) {
            container.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">No paid orders found for today.</div>';
            return;
        }

        container.innerHTML = orders.slice(0, 15).map(o => `
            <div style="padding: 10px 12px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-weight: 800; font-size: 0.88rem; color: var(--text-main);">#${o.order_number} • <span style="color: var(--primary);">${o.plate_number || 'No Plate'}</span></div>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">${o.customer_name || 'Walk-in'} • $${parseFloat(o.total_usd || 0).toFixed(2)} • ${o.created_at ? new Date(o.created_at.replace(/-/g, '/')).toLocaleTimeString() : ''}</div>
                </div>
                <button type="button" class="btn btn-sm btn-primary btn-reprint-item" data-order-id="${o.id}" style="background: #0284c7; border-color: #0284c7; font-weight: 700; padding: 4px 10px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-print"></i> Reprint
                </button>
            </div>
        `).join('');

        container.querySelectorAll('.btn-reprint-item').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const oid = parseInt(btn.dataset.orderId);
                this.executeReprintOrder(oid, btn);
            });
        });
    }

    static filterReprintOrdersList(query) {
        if (!this.cachedReprintOrders) return;
        const q = (query || '').toLowerCase().trim();
        if (!q) {
            this.renderReprintOrdersList(this.cachedReprintOrders);
            return;
        }
        const filtered = this.cachedReprintOrders.filter(o =>
            (o.order_number || '').toLowerCase().includes(q) ||
            (o.plate_number || '').toLowerCase().includes(q) ||
            (o.customer_name || '').toLowerCase().includes(q)
        );
        this.renderReprintOrdersList(filtered);
    }

    static async executeReprintOrder(orderId, btn) {
        if (!this.checkCashierActionAllowed()) return;
        if (btn) btn.disabled = true;

        try {
            const res = await PosApi.reprintReceipt(orderId, '', this.currentUser.id);
            if (res && res.success && res.order) {
                // Thermal print receipt with isReprint: true
                if (window.PosThermalPrinter) {
                    await PosThermalPrinter.printReceipt(res.order, { isReprint: true });
                }
                this.showAppAlert(`Receipt for Order #${res.order.order_number} reprinted successfully.`, 'success', 'Reprint Complete');
                document.getElementById('modalReprintPrompt')?.classList.remove('open');
            } else {
                this.showAppAlert(res?.error || 'Failed to reprint receipt.', 'danger', 'Error');
            }
        } catch (err) {
            this.showAppAlert(err.message || 'Failed to reprint receipt.', 'danger', 'Error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // 6. Edit Opening Float
    static showEditOpeningFloatModal() {
        if (!this.checkCashierActionAllowed()) return;
        const shift = this.activeShift || PosStorage.getCurrentShift();
        const curUsd = parseFloat(shift?.opening_cash_usd || 0).toFixed(2);
        const curKhr = parseInt(shift?.opening_cash_khr || 0).toLocaleString();

        const curDisplay = document.getElementById('editFloatCurrentDisplay');
        if (curDisplay) curDisplay.textContent = `$${curUsd} USD / ${curKhr} ៛`;

        document.getElementById('editFloatUsdInput').value = curUsd;
        document.getElementById('editFloatKhrInput').value = shift?.opening_cash_khr || 0;
        document.getElementById('editFloatReasonInput').value = '';
        document.getElementById('editFloatMgrUser').value = '';
        document.getElementById('editFloatMgrPin').value = '';

        document.getElementById('modalEditOpeningFloat')?.classList.add('open');
    }

    static async handleEditOpeningFloatSubmit() {
        if (!this.checkCashierActionAllowed()) return;
        const usd = parseFloat(document.getElementById('editFloatUsdInput')?.value || 0);
        const khr = parseInt(document.getElementById('editFloatKhrInput')?.value || 0);
        const reason = document.getElementById('editFloatReasonInput')?.value.trim();
        const mgrUser = document.getElementById('editFloatMgrUser')?.value.trim();
        const mgrPin = document.getElementById('editFloatMgrPin')?.value.trim();

        if (usd < 0 || khr < 0) {
            this.showAppAlert('Opening float amount cannot be negative.', 'warning', 'Invalid Amount');
            return;
        }
        if (!reason) {
            this.showAppAlert('Reason for adjusting opening float is required.', 'warning', 'Reason Required');
            return;
        }
        if (!mgrUser || !mgrPin) {
            this.showAppAlert('Manager username and PIN are required.', 'warning', 'Manager PIN Required');
            return;
        }

        const btn = document.getElementById('btnSubmitEditFloat');
        if (btn) btn.disabled = true;

        try {
            const res = await PosApi.editOpeningFloat({
                openingCashUsd: usd,
                openingCashKhr: khr,
                reason,
                managerUsername: mgrUser,
                managerPin: mgrPin,
                cashierId: this.currentUser.id
            });

            if (res && res.success) {
                document.getElementById('modalEditOpeningFloat')?.classList.remove('open');
                this.showAppAlert(res.message || 'Opening float updated successfully.', 'success', 'Float Updated');

                // Update local shift
                if (this.activeShift) {
                    this.activeShift.opening_cash_usd = usd;
                    this.activeShift.opening_cash_khr = khr;
                    PosStorage.setCurrentShift(this.activeShift);
                    this.updateShiftStatusBadge('active', this.activeShift);
                }

                // Print reprint of Open Float Slip
                if (res.shift_id && window.PosThermalPrinter && PosThermalPrinter.printOpenShiftSlip) {
                    PosApi.getOpenShiftSlip(res.shift_id).then(slipRes => {
                        if (slipRes && slipRes.slip) {
                            PosThermalPrinter.printOpenShiftSlip(slipRes.slip);
                        }
                    }).catch(e => console.warn('Reprint open shift slip failed:', e));
                }
            } else {
                this.showAppAlert(res?.error || 'Failed to edit opening float.', 'danger', 'Error');
            }
        } catch (err) {
            this.showAppAlert(err.message || 'Failed to edit opening float.', 'danger', 'Error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // 7. Lock Screen
    static handleActionLockScreen() {
        this.handleLogout(true);
    }

    // 8. Switch User (Shift Handover)
    static handleActionSwitchUser() {
        if (!this.checkCashierActionAllowed()) return;
        this.showEndShiftModal();
        this.setEndShiftMode('handover');
    }

    // 9. End Shift
    static handleActionEndShift() {
        if (!this.checkCashierActionAllowed()) return;
        this.showEndShiftModal();
        this.setEndShiftMode('solo');
    }

    // 10. End Day (Manager Only)
    static handleActionEndDay() {
        const isMgr = this.currentUser && ['manager', 'owner', 'admin'].includes(this.currentUser.role);
        if (isMgr) {
            this.switchView('close');
        } else {
            this.requestManagerPin(
                "End Day Authorization",
                "Enter Manager PIN to access Daily Close & Z-Report",
                () => {
                    this.switchView('close');
                }
            );
        }
    }
'''

# Find the last closing brace of PosApp class
last_brace_pos = content.rfind('window.PosApp = PosApp')
if last_brace_pos != -1:
    class_closing_brace = content.rfind('}', 0, last_brace_pos)
    if class_closing_brace != -1:
        content = content[:class_closing_brace] + methods_code + "\n" + content[class_closing_brace:]
        print("Methods added to PosApp class.")
    else:
        print("WARNING: Could not find class closing brace!")
else:
    print("WARNING: window.PosApp = PosApp not found!")

with open('pos/js/app.js', 'w', encoding='utf-8') as f:
    f.write(content)

print("pos/js/app.js updated successfully.")
