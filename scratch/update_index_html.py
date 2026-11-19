import re

with open('pos/index.html', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Insert header button right after posShiftStatusBadge
header_target = '''            <!-- POS Shift Status Badge -->
            <button type="button" class="pos-shift-badge-btn" id="posShiftStatusBadge" style="display: none; align-items: center; gap: 7px; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; border: 1px solid #fecaca; background: #fef2f2; color: #dc2626; cursor: pointer; transition: all 0.2s ease;">
                <i class="fa-solid fa-cash-register" id="posShiftStatusIcon"></i>
                <span id="posShiftStatusText">No Active Shift - Click to Open</span>
            </button>'''

header_replacement = '''            <!-- POS Shift Status Badge -->
            <button type="button" class="pos-shift-badge-btn" id="posShiftStatusBadge" style="display: none; align-items: center; gap: 7px; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; border: 1px solid #fecaca; background: #fef2f2; color: #dc2626; cursor: pointer; transition: all 0.2s ease;">
                <i class="fa-solid fa-cash-register" id="posShiftStatusIcon"></i>
                <span id="posShiftStatusText">No Active Shift - Click to Open</span>
            </button>

            <!-- Cashier Actions Button -->
            <button type="button" class="btn-cashier-actions" id="btnOpenCashierActions" title="Cashier Actions / សកម្មភាពអ្នកគិតលុយ" style="display: inline-flex; align-items: center; gap: 7px; padding: 5px 13px; border-radius: 999px; font-size: 12px; font-weight: 750; border: 1.5px solid #fed7aa; background: #fffaf5; color: #ea580c; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(234, 88, 12, 0.08);">
                <i class="fa-solid fa-sliders" style="font-size: 13px;"></i>
                <span data-i18n="cashierActions">Cashier Actions</span>
            </button>'''

if header_target in content:
    content = content.replace(header_target, header_replacement, 1)
    print("Header button inserted successfully.")
else:
    print("WARNING: Header target not found!")

# 2. Insert user menu shortcut
menu_target = '''                    <button type="button" class="header-menu-item" id="userMenuEndShift" style="display: none;">
                        <i class="fa-solid fa-handshake" style="color: #f59e0b;"></i>
                        <span data-i18n="endShiftMenu">បញ្ចប់វេន (End Shift)</span>
                    </button>'''

menu_replacement = '''                    <button type="button" class="header-menu-item" id="userMenuCashierActions">
                        <i class="fa-solid fa-sliders" style="color: #ea580c;"></i>
                        <span data-i18n="cashierActions">សកម្មភាពអ្នកគិតលុយ (Cashier Actions)</span>
                    </button>
                    <button type="button" class="header-menu-item" id="userMenuEndShift" style="display: none;">
                        <i class="fa-solid fa-handshake" style="color: #f59e0b;"></i>
                        <span data-i18n="endShiftMenu">បញ្ចប់វេន (End Shift)</span>
                    </button>'''

if menu_target in content:
    content = content.replace(menu_target, menu_replacement, 1)
    print("User menu item inserted successfully.")
else:
    print("WARNING: User menu target not found!")

# 3. Insert Modals right before <!-- Modal: Device Locked / Terminal Not Authorized -->
modals_html = '''    <!-- ======================================================== -->
    <!-- CASHIER ACTIONS POPUP & SUB-MODALS                        -->
    <!-- ======================================================== -->

    <!-- Modal: Cashier Actions (Main 10-Button Dashboard) -->
    <div class="modal-backdrop" id="modalCashierActions" style="z-index: 10002;">
        <div class="modal-content cashier-actions-modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%); border-bottom: 1.5px solid #fed7aa; padding: 1.1rem 1.5rem;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(234, 88, 12, 0.12); color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.2rem; font-weight: 850; color: var(--text-main); margin: 0; line-height: 1.2;">
                            <span data-i18n="cashierActionsTitle">សកម្មភាពអ្នកគិតលុយ (Cashier Actions)</span>
                        </h3>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                            <span id="cashierActionsShiftStatus">Shift #-- • Cashier</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm btn-icon" id="btnCloseCashierActions">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="cashier-actions-grid">
                <!-- 1. Cash In -->
                <div class="action-tile-btn tile-emerald" id="btnActionCashIn" role="button" tabindex="0">
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-arrow-down-to-bracket"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionCashIn">Cash In</div>
                    <div class="action-tile-subtitle">បង់ប្រាក់ចូលថត</div>
                </div>

                <!-- 2. Cash Out -->
                <div class="action-tile-btn tile-amber" id="btnActionCashOut" role="button" tabindex="0">
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-arrow-up-from-bracket"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionCashOut">Cash Out</div>
                    <div class="action-tile-subtitle">ដកប្រាក់ចំណាយ</div>
                </div>

                <!-- 3. Open Drawer -->
                <div class="action-tile-btn tile-indigo" id="btnActionOpenDrawer" role="button" tabindex="0">
                    <span class="action-tile-badge-mgr"><i class="fa-solid fa-shield-halved"></i> MGR</span>
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-vault"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionOpenDrawer">Open Drawer</div>
                    <div class="action-tile-subtitle">បើកថតលុយ (No Sale)</div>
                </div>

                <!-- 4. Void Receipt -->
                <div class="action-tile-btn tile-rose" id="btnActionVoidReceipt" role="button" tabindex="0">
                    <span class="action-tile-badge-mgr"><i class="fa-solid fa-shield-halved"></i> MGR</span>
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionVoidReceipt">Void Receipt</div>
                    <div class="action-tile-subtitle">លុបវិក្កយបត្រ</div>
                </div>

                <!-- 5. Reprint Receipt -->
                <div class="action-tile-btn tile-sky" id="btnActionReprintReceipt" role="button" tabindex="0">
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-print"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionReprintReceipt">Reprint Receipt</div>
                    <div class="action-tile-subtitle">បោះពុម្ពឡើងវិញ</div>
                </div>

                <!-- 6. Edit Opening Float -->
                <div class="action-tile-btn tile-teal" id="btnActionEditFloat" role="button" tabindex="0">
                    <span class="action-tile-badge-mgr"><i class="fa-solid fa-shield-halved"></i> MGR</span>
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionEditFloat">Edit Initial Cash</div>
                    <div class="action-tile-subtitle">កែប្រាក់ដើមគ្រា</div>
                </div>

                <!-- 7. Lock Screen -->
                <div class="action-tile-btn tile-purple" id="btnActionLockScreen" role="button" tabindex="0">
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-lock"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionLockScreen">Lock Screen</div>
                    <div class="action-tile-subtitle">ចាក់សោអេក្រង់</div>
                </div>

                <!-- 8. Switch User (Handover) -->
                <div class="action-tile-btn tile-blue" id="btnActionSwitchUser" role="button" tabindex="0">
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-users-viewfinder"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionSwitchUser">Switch User</div>
                    <div class="action-tile-subtitle">ប្ដូរវេន Handover</div>
                </div>

                <!-- 9. End Shift -->
                <div class="action-tile-btn tile-orange" id="btnActionEndShift" role="button" tabindex="0">
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-door-open"></i>
                    </div>
                    <div class="action-tile-title" data-i18n="actionEndShift">End Shift</div>
                    <div class="action-tile-subtitle">បិទវេនធ្វើការ</div>
                </div>

                <!-- 10. End Day -->
                <div class="action-tile-btn tile-crimson" id="btnActionEndDay" role="button" tabindex="0">
                    <span class="action-tile-badge-mgr"><i class="fa-solid fa-shield-halved"></i> MGR</span>
                    <div class="action-tile-icon-box">
                        <i class="fa-solid fa-power-off"></i>
                    </div>
                    <div class="action-tile-title" style="color: #dc2626;" data-i18n="actionEndDay">End Day</div>
                    <div class="action-tile-subtitle">បិទបញ្ជីថ្ងៃ (Z-Report)</div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 0.85rem 1.5rem; background: var(--bg-hover, #f8fafc); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-shield-halved text-primary"></i> <span data-i18n="actionsSecurityNotice">All actions logged with Cashier ID &amp; Shift</span>
                </div>
                <button type="button" class="btn btn-outline" id="btnDismissCashierActions">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Cash Movement (Cash In & Cash Out) -->
    <div class="modal-backdrop" id="modalCashMovement" style="z-index: 10003;">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header" id="cashMovementHeader" style="background: #f0fdf4; border-bottom: 1.5px solid #bbf7d0;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div id="cashMovementHeaderIcon" style="width: 34px; height: 34px; border-radius: 8px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                        <i class="fa-solid fa-arrow-down-to-bracket"></i>
                    </div>
                    <div>
                        <h3 id="cashMovementTitle" style="font-size: 1.15rem; font-weight: 800; color: #15803d; margin: 0;">Cash In (បង់ប្រាក់ចូលថត)</h3>
                        <div id="cashMovementSubtitle" style="font-size: 0.76rem; color: #166534;">Record cash added to register drawer</div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm btn-icon" id="btnCloseCashMovementModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.25rem 1.5rem;">
                <!-- Amount Inputs -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 0.85rem;">
                    <div>
                        <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">
                            Amount USD ($)
                        </label>
                        <div style="position: relative;">
                            <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--text-muted);">$</span>
                            <input type="number" id="cashMovementUsdInput" class="form-control" placeholder="0.00" step="0.25" min="0" style="padding-left: 24px; font-weight: 800; font-size: 1.1rem;">
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">
                            Amount KHR (៛)
                        </label>
                        <div style="position: relative;">
                            <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--text-muted);">៛</span>
                            <input type="number" id="cashMovementKhrInput" class="form-control" placeholder="0" step="500" min="0" style="padding-left: 24px; font-weight: 800; font-size: 1.1rem;">
                        </div>
                    </div>
                </div>

                <!-- Quick Presets -->
                <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 1.1rem;" id="cashMovementPresetChips">
                    <button type="button" class="btn btn-xs btn-outline cash-preset-chip" data-usd="5">+$5</button>
                    <button type="button" class="btn btn-xs btn-outline cash-preset-chip" data-usd="10">+$10</button>
                    <button type="button" class="btn btn-xs btn-outline cash-preset-chip" data-usd="20">+$20</button>
                    <button type="button" class="btn btn-xs btn-outline cash-preset-chip" data-usd="50">+$50</button>
                    <button type="button" class="btn btn-xs btn-outline cash-preset-chip" data-khr="20000">+20,000៛</button>
                    <button type="button" class="btn btn-xs btn-outline cash-preset-chip" data-khr="40000">+40,000៛</button>
                </div>

                <!-- Reason Input -->
                <div style="margin-bottom: 1.1rem;">
                    <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">
                        Reason / Description *
                    </label>
                    <input type="text" id="cashMovementReasonInput" class="form-control" placeholder="e.g. Change replenishment, Drinking water, Ice, Supplies..." style="font-size: 0.9rem;">
                </div>

                <!-- Manager Authorization Section (Visible if Cash Out > $10) -->
                <div id="cashMovementMgrAuthBox" style="display: none; background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 10px; padding: 0.9rem 1rem; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 7px; color: #b45309; font-weight: 800; font-size: 0.85rem; margin-bottom: 0.6rem;">
                        <i class="fa-solid fa-shield-halved"></i> Manager Authorization Required (Amount &gt; $10)
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: #92400e; display: block; margin-bottom: 2px;">Manager Username</label>
                            <input type="text" id="cashMovementMgrUser" class="form-control form-control-sm" placeholder="Manager username">
                        </div>
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: #92400e; display: block; margin-bottom: 2px;">Manager 4-Digit PIN</label>
                            <input type="password" id="cashMovementMgrPin" maxlength="6" class="form-control form-control-sm" placeholder="****">
                        </div>
                    </div>
                </div>

                <div style="font-size: 0.78rem; color: var(--text-muted); display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-print"></i> A thermal voucher slip will print automatically upon confirmation.
                </div>
            </div>
            <div class="modal-footer" style="justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" id="btnCancelCashMovement">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSubmitCashMovement" style="background: #16a34a; border-color: #16a34a; font-weight: 800;">
                    <i class="fa-solid fa-check"></i> <span id="cashMovementSubmitBtnText">Confirm Cash In</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Open Drawer (No Sale) -->
    <div class="modal-backdrop" id="modalOpenDrawerPrompt" style="z-index: 10003;">
        <div class="modal-content" style="max-width: 440px;">
            <div class="modal-header" style="background: #eef2ff; border-bottom: 1.5px solid #c7d2fe;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 34px; height: 34px; border-radius: 8px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                        <i class="fa-solid fa-vault"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #3730a3; margin: 0;">Open Cash Drawer</h3>
                        <div style="font-size: 0.76rem; color: #4338ca;">No-Sale Drawer Kick (Requires Manager PIN)</div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm btn-icon" id="btnCloseOpenDrawerModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.25rem 1.5rem;">
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">
                        Reason for Opening Drawer *
                    </label>
                    <input type="text" id="openDrawerReasonInput" class="form-control" placeholder="e.g. Giving change, inspection, receipt paper roll...">
                </div>
                <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 0.9rem 1rem; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 7px; color: #475569; font-weight: 800; font-size: 0.82rem; margin-bottom: 0.6rem;">
                        <i class="fa-solid fa-user-shield text-primary"></i> Manager Sign-off
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">Manager Username</label>
                            <input type="text" id="openDrawerMgrUser" class="form-control form-control-sm" placeholder="Manager user">
                        </div>
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">Manager PIN</label>
                            <input type="password" id="openDrawerMgrPin" maxlength="6" class="form-control form-control-sm" placeholder="****">
                        </div>
                    </div>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted);">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> Event is strictly recorded with timestamp, cashier, and manager in audit logs.
                </div>
            </div>
            <div class="modal-footer" style="justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" id="btnCancelOpenDrawer">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSubmitOpenDrawer" style="background: #4f46e5; border-color: #4f46e5; font-weight: 800;">
                    <i class="fa-solid fa-key"></i> Authorize &amp; Open
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Void Receipt -->
    <div class="modal-backdrop" id="modalVoidPrompt" style="z-index: 10003;">
        <div class="modal-content" style="max-width: 540px;">
            <div class="modal-header" style="background: #fff1f2; border-bottom: 1.5px solid #fecdd3;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 34px; height: 34px; border-radius: 8px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #be123c; margin: 0;">Void Receipt (លុបវិក្កយបត្រ)</h3>
                        <div style="font-size: 0.76rem; color: #9f1239;">Cancel order, restore inventory, reverse loyalty</div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm btn-icon" id="btnCloseVoidModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.25rem 1.5rem;">
                <!-- Search or Select Order -->
                <div style="margin-bottom: 0.85rem;">
                    <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">Select Order to Void</label>
                    <input type="text" id="voidOrderSearchInput" class="form-control" placeholder="Search plate # or order # (e.g. 261119-001)...">
                </div>
                <!-- Orders List -->
                <div id="voidOrdersListContainer" style="max-height: 160px; overflow-y: auto; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 1rem; background: var(--bg-surface);">
                    <!-- Loaded dynamically via JS -->
                </div>
                <!-- Selected Order Box -->
                <div id="voidSelectedOrderInfo" style="display: none; background: #fff5f5; border: 1.5px solid #fecdd3; border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1rem;">
                    <div style="font-weight: 800; color: #b91c1c; font-size: 0.95rem; display: flex; justify-content: space-between;">
                        <span id="voidSelectedOrderNum">#ORD-XXXX</span>
                        <span id="voidSelectedOrderTotal">$0.00</span>
                    </div>
                    <div style="font-size: 0.8rem; color: #7f1d1d; margin-top: 3px;" id="voidSelectedOrderDetails">
                        Plate: -- • Cashier: -- • Cash
                    </div>
                </div>
                <!-- Reason (min 5 chars) -->
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">
                        Reason for Cancellation * (at least 5 characters)
                    </label>
                    <input type="text" id="voidReasonInput" class="form-control" placeholder="e.g. Customer cancelled before wash, wrong item entered...">
                </div>
                <!-- Manager PIN Authorization -->
                <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 7px; color: #475569; font-weight: 800; font-size: 0.82rem; margin-bottom: 0.6rem;">
                        <i class="fa-solid fa-shield-halved text-rose-500"></i> Manager Authorization Required
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">Manager Username</label>
                            <input type="text" id="voidMgrUser" class="form-control form-control-sm" placeholder="Manager user">
                        </div>
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">Manager PIN</label>
                            <input type="password" id="voidMgrPin" maxlength="6" class="form-control form-control-sm" placeholder="****">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" id="btnCancelVoidModal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSubmitVoid" style="background: #dc2626; border-color: #dc2626; font-weight: 800;">
                    <i class="fa-solid fa-trash-can"></i> Confirm Void &amp; Print Slip
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Reprint Receipt -->
    <div class="modal-backdrop" id="modalReprintPrompt" style="z-index: 10003;">
        <div class="modal-content" style="max-width: 540px;">
            <div class="modal-header" style="background: #f0f9ff; border-bottom: 1.5px solid #bae6fd;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 34px; height: 34px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                        <i class="fa-solid fa-print"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #0369a1; margin: 0;">Reprint Receipt (បោះពុម្ពឡើងវិញ)</h3>
                        <div style="font-size: 0.76rem; color: #075985;">Prints marked with *** REPRINT / COPY *** and audit logged</div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm btn-icon" id="btnCloseReprintModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.25rem 1.5rem;">
                <div style="margin-bottom: 0.85rem;">
                    <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">Find Paid Order</label>
                    <input type="text" id="reprintOrderSearchInput" class="form-control" placeholder="Search plate # or order # (e.g. 261119-001)...">
                </div>
                <div id="reprintOrdersListContainer" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 1rem; background: var(--bg-surface);">
                    <!-- Loaded dynamically via JS -->
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-circle-info text-primary"></i> Receipt will print directly on your configured thermal printer with REPRINT banner.
                </div>
            </div>
            <div class="modal-footer" style="justify-content: flex-end;">
                <button type="button" class="btn btn-outline" id="btnDismissReprintModal">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal: Edit Opening Float -->
    <div class="modal-backdrop" id="modalEditOpeningFloat" style="z-index: 10003;">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header" style="background: #f0fdfa; border-bottom: 1.5px solid #99f6e4;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 34px; height: 34px; border-radius: 8px; background: #ccfbf1; color: #0d9488; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f766e; margin: 0;">Edit Initial Cash (កែប្រាក់ដើមគ្រា)</h3>
                        <div style="font-size: 0.76rem; color: #115e59;">Adjust registered float for current shift</div>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm btn-icon" id="btnCloseEditFloatModal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 1.25rem 1.5rem;">
                <!-- Current Float Badge -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted);">Current Float:</span>
                    <span style="font-weight: 850; font-size: 1rem; color: #0f766e;" id="editFloatCurrentDisplay">$0.00 / 0 ៛</span>
                </div>
                <!-- New Amount Inputs -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 1rem;">
                    <div>
                        <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">New USD ($)</label>
                        <input type="number" id="editFloatUsdInput" class="form-control" placeholder="0.00" step="0.25" min="0" style="font-weight: 800;">
                    </div>
                    <div>
                        <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">New KHR (៛)</label>
                        <input type="number" id="editFloatKhrInput" class="form-control" placeholder="0" step="500" min="0" style="font-weight: 800;">
                    </div>
                </div>
                <!-- Reason (required) -->
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.8rem; font-weight: 750; color: var(--text-main); display: block; margin-bottom: 4px;">Reason for Adjustment *</label>
                    <input type="text" id="editFloatReasonInput" class="form-control" placeholder="e.g. Cashier miscounted initial envelope...">
                </div>
                <!-- Manager Authorization -->
                <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 7px; color: #475569; font-weight: 800; font-size: 0.82rem; margin-bottom: 0.6rem;">
                        <i class="fa-solid fa-shield-halved text-teal-600"></i> Manager Authorization Required
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">Manager Username</label>
                            <input type="text" id="editFloatMgrUser" class="form-control form-control-sm" placeholder="Manager user">
                        </div>
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 2px;">Manager PIN</label>
                            <input type="password" id="editFloatMgrPin" maxlength="6" class="form-control form-control-sm" placeholder="****">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline" id="btnCancelEditFloat">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSubmitEditFloat" style="background: #0d9488; border-color: #0d9488; font-weight: 800;">
                    <i class="fa-solid fa-check"></i> Save New Float
                </button>
            </div>
        </div>
    </div>
'''

modals_target = '    <!-- Modal: Device Locked / Terminal Not Authorized -->'
if modals_target in content:
    content = content.replace(modals_target, modals_html + '\n' + modals_target, 1)
    print("Modals inserted successfully.")
else:
    print("WARNING: Modals target not found!")

with open('pos/index.html', 'w', encoding='utf-8') as f:
    f.write(content)

print("pos/index.html updated successfully.")
