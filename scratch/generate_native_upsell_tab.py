# Draft native UI that follows this project's UI strictly
native_upsell_html = """<!-- ======================================================== -->
<!-- GROUP 6C: UPSELL & PROMOTION OFFERS STUDIO -->
<!-- ======================================================== -->
<div id="tab_upsell" class="tab-pane" style="display: <?= ($activeTab === 'upsell') ? 'block' : 'none' ?>;">

    <!-- Card 1: Header, Global Toggle Control & KPI Metrics -->
    <div class="card" style="margin-bottom: 20px;">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main);">
                    <i class="fa-solid fa-bullseye mr-2" style="color: var(--primary);"></i>Smart Upsell &amp; Promotion Rules
                </h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                    Automated checkout add-on offers (e.g. Wash + Wax combo) and tier upgrades presented to cashiers during checkout.
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm" onclick="openNewUpsellModal()">
                    <i class="fa-solid fa-plus mr-1"></i> Add Promotion Rule
                </button>
            </div>
        </div>

        <!-- Global Engine Switch Row -->
        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin-bottom: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_upsell_global">
            <input type="hidden" name="tab" value="upsell">
            <?php $isUpsellEnabled = (($settings['enable_upsell_offers'] ?? '1') === '1'); ?>
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="stat-icon <?= $isUpsellEnabled ? 'green' : 'slate' ?>" style="width: 40px; height: 40px; font-size: 18px; border-radius: var(--radius-md);">
                        <i class="fa-solid <?= $isUpsellEnabled ? 'fa-sliders' : 'fa-pause' ?>"></i>
                    </div>
                    <div>
                        <div style="font-weight: 700; font-size: 14px; color: var(--text-main);">
                            Global Checkout Upsell Engine
                            <span class="badge <?= $isUpsellEnabled ? 'badge-success' : 'badge-slate' ?>" style="margin-left: 8px;">
                                <i class="fa-solid <?= $isUpsellEnabled ? 'fa-circle-check' : 'fa-pause' ?> mr-1"></i>
                                <?= $isUpsellEnabled ? 'Active at POS' : 'Paused' ?>
                            </span>
                        </div>
                        <div class="text-xs text-muted" style="margin-top: 2px;">
                            When enabled, cashiers see smart prompt dialogs (like Wax add-on) when customers order basic wash packages.
                        </div>
                    </div>
                </div>
                <div>
                    <input type="hidden" name="enable_upsell_offers" value="<?= $isUpsellEnabled ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-sm <?= $isUpsellEnabled ? 'btn-secondary' : 'btn-primary' ?>">
                        <i class="fa-solid <?= $isUpsellEnabled ? 'fa-pause' : 'fa-play' ?> mr-1"></i>
                        <?= $isUpsellEnabled ? 'Pause Global Engine' : 'Enable Global Engine' ?>
                    </button>
                </div>
            </div>
        </form>

        <!-- KPI Metric Cards (Using Project's Native stat-card) -->
        <div class="grid grid-cols-4" style="gap: 16px; margin-top: 20px;">
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fa-solid fa-layer-group"></i></div>
                <div>
                    <div class="stat-label">Total Campaigns</div>
                    <div class="stat-value"><?= count($allUpsellRules) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="stat-label">Active Rules</div>
                    <div class="stat-value" style="color: var(--success);"><?= (int)($upsellStats['active_rules'] ?? 0) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fa-solid fa-cash-register"></i></div>
                <div>
                    <div class="stat-label">Offers Presented</div>
                    <div class="stat-value"><?= (int)($upsellLogStats['shown'] ?? 0) ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon amber"><i class="fa-solid fa-arrow-trend-up"></i></div>
                <div>
                    <div class="stat-label">Acceptance Rate</div>
                    <div class="stat-value" style="color: var(--warning);"><?= (float)($upsellLogStats['rate'] ?? 0) ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 2: Promotion Rules Table -->
    <div class="card">
        <div class="card-header" style="margin-bottom: 16px;">
            <div class="card-title">
                <i class="fa-solid fa-list-check" style="color: var(--primary);"></i>
                Checkout Promotion Rules (<?= count($allUpsellRules) ?>)
            </div>
            <span class="text-xs text-muted">Triggers and pricing configured for cashier POS</span>
        </div>

        <div class="table-responsive">
            <table class="table table-clean">
                <thead>
                    <tr>
                        <th style="min-width: 240px;">Campaign &amp; Rule</th>
                        <th style="min-width: 190px;">When Ordered (Trigger)</th>
                        <th style="min-width: 160px;">Offer Action Type</th>
                        <th style="min-width: 200px;">Offer Service &amp; Promo Price</th>
                        <th style="min-width: 110px;">Status</th>
                        <th style="text-align: right; min-width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allUpsellRules)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                                <div style="font-size: 32px; margin-bottom: 8px; color: var(--text-light);"><i class="fa-solid fa-bullseye"></i></div>
                                <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">No Promotion Rules Configured</div>
                                <div class="text-xs text-muted" style="margin-top: 4px; margin-bottom: 16px;">
                                    Create automated add-on offers (e.g. Wash + Wax) or package upgrades to increase ticket sizes.
                                </div>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openNewUpsellModal()">
                                    <i class="fa-solid fa-plus mr-1"></i> Add First Rule
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allUpsellRules as $rule): ?>
                            <?php 
                                $isAddon = (($rule['action_type'] ?? 'add_service') === 'add_service');
                                $isActive = !empty($rule['is_active']);
                                $vType = $rule['trigger_vehicle_type'] ? ucwords(str_replace('_', ' ', $rule['trigger_vehicle_type'])) : 'All Vehicles';
                                $promoPrice = (float)($rule['offer_price_usd'] ?? 5.00);
                                $rateKhr = 4100;
                                $promoKhr = round($promoPrice * $rateKhr);
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700; font-size: 14px; color: var(--text-main);">
                                        <?= htmlspecialchars($rule['name']) ?>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px;">
                                        <span class="badge badge-slate" style="font-family: var(--font-mono); font-size: 10px;">
                                            <?= htmlspecialchars($rule['rule_uid']) ?>
                                        </span>
                                        <?php if (!empty($rule['offer_headline_km'])): ?>
                                            <span class="text-xs text-muted" style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= htmlspecialchars($rule['offer_headline_km']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div class="stat-icon blue" style="width: 32px; height: 32px; font-size: 13px; border-radius: var(--radius-sm); flex-shrink: 0;">
                                            <i class="fa-solid fa-car"></i>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; font-size: 13px; color: var(--text-main);">
                                                <?= htmlspecialchars($rule['trigger_service_name'] ?: 'Any Wash Service') ?>
                                            </div>
                                            <div class="text-xs text-muted">
                                                <?= htmlspecialchars($vType) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isAddon): ?>
                                        <span class="badge badge-success">
                                            <i class="fa-solid fa-plus mr-1"></i> Add-On Combo
                                        </span>
                                        <div class="text-xs text-muted" style="margin-top: 3px;">Keeps wash in cart</div>
                                    <?php else: ?>
                                        <span class="badge badge-purple">
                                            <i class="fa-solid fa-arrows-rotate mr-1"></i> Tier Upgrade
                                        </span>
                                        <div class="text-xs text-muted" style="margin-top: 3px;">Replaces with combo</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div class="stat-icon <?= $isAddon ? 'green' : 'purple' ?>" style="width: 32px; height: 32px; font-size: 13px; border-radius: var(--radius-sm); flex-shrink: 0;">
                                            <i class="fa-solid <?= $isAddon ? 'fa-spray-can-sparkles' : 'fa-crown' ?>"></i>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; font-size: 13px; color: var(--text-main);">
                                                <?= htmlspecialchars($rule['offer_service_name'] ?: 'Target Service') ?>
                                            </div>
                                            <div style="font-weight: 700; color: var(--success); font-family: var(--font-mono); font-size: 13px;">
                                                <?= $isAddon ? '+$' : '$' ?><?= number_format($promoPrice, 2) ?>
                                                <span class="text-xs text-muted" style="font-weight: normal;">(<?= number_format($promoKhr) ?> ៛)</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_upsell_rule">
                                        <input type="hidden" name="tab" value="upsell">
                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                        <button type="submit" class="badge <?= $isActive ? 'badge-success' : 'badge-slate' ?>" style="cursor: pointer; border: none; padding: 4px 10px; font-family: inherit;" title="Click to toggle status">
                                            <i class="fa-solid <?= $isActive ? 'fa-circle-check' : 'fa-pause' ?> mr-1"></i>
                                            <?= $isActive ? 'Active' : 'Paused' ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditUpsellModal(<?= json_encode($rule, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit Rule">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin: 0;" onsubmit="return confirm('Delete this promotion rule?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_upsell_rule">
                                            <input type="hidden" name="tab" value="upsell">
                                            <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete Rule">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: ADD / EDIT UPSELL PROMOTION RULE -->
<!-- ======================================================== -->
<div class="modal-backdrop" id="modalUpsellRule">
    <div class="modal" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fa-solid fa-bullseye mr-2" style="color: var(--primary);"></i>
                <span id="upsellModalTitle">Add Promotion Rule</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal('modalUpsellRule')">&times;</button>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" id="formUpsellRule">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_upsell_rule">
            <input type="hidden" name="tab" value="upsell">
            <input type="hidden" id="upsell_rule_id" name="rule_id" value="0">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Campaign / Rule Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="upsell_name" name="rule_name" class="form-input" placeholder="e.g. Exterior Wash: Add Hydrophobic Wax" oninput="updateLivePreview()" required>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px; margin-bottom: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Trigger When Customer Orders</label>
                        <select id="upsell_trigger_service_id" name="trigger_service_id" class="form-select" onchange="updateLivePreview()">
                            <option value="">Any Wash Service</option>
                            <?php foreach ($allServices as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" data-name-en="<?= htmlspecialchars($s['name_en']) ?>" data-name-km="<?= htmlspecialchars($s['name_km']) ?>">
                                    <?= htmlspecialchars($s['name_en']) ?> (<?= htmlspecialchars($s['name_km']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Vehicle Type Filter</label>
                        <select id="upsell_trigger_vehicle_type" name="trigger_vehicle_type" class="form-select" onchange="updateLivePreview()">
                            <option value="">All Vehicles</option>
                            <option value="small_car">Small Car (Sedan)</option>
                            <option value="suv_pickup">SUV / Pickup</option>
                            <option value="van_truck">Van / Truck</option>
                            <option value="motorbike">Motorbike</option>
                        </select>
                    </div>
                </div>

                <!-- Action Type Selection -->
                <div class="form-group">
                    <label class="form-label">Offer Action Type <span style="color: var(--danger);">*</span></label>
                    <div class="grid grid-cols-2" style="gap: 12px;">
                        <label id="label_addon" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: var(--bg-subtle); border: 2px solid var(--primary); border-radius: var(--radius-md); cursor: pointer;" onclick="setActionType('add_service')">
                            <input type="radio" name="action_type" id="action_type_addon" value="add_service" checked style="margin-top: 3px;" onchange="updateActionTypeSelection(); updateLivePreview();">
                            <div>
                                <div style="font-weight: 700; font-size: 13px; color: var(--text-main);">+ Add-On (Combo)</div>
                                <div class="text-xs text-muted" style="margin-top: 2px;">Keeps wash in cart &amp; adds wax on top. Washer washes first, then applies wax.</div>
                            </div>
                        </label>
                        <label id="label_upgrade" style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: var(--bg-subtle); border: 1.5px solid var(--border); border-radius: var(--radius-md); cursor: pointer;" onclick="setActionType('upgrade_service')">
                            <input type="radio" name="action_type" id="action_type_upgrade" value="upgrade_service" style="margin-top: 3px;" onchange="updateActionTypeSelection(); updateLivePreview();">
                            <div>
                                <div style="font-weight: 700; font-size: 13px; color: var(--text-main);">⟳ Tier Upgrade</div>
                                <div class="text-xs text-muted" style="margin-top: 2px;">Replaces basic service with a premium package (e.g. Wash upgraded to Full Wash).</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2" style="gap: 16px; margin-bottom: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Service to Offer <span style="color: var(--danger);">*</span></label>
                        <select id="upsell_offer_service_id" name="offer_service_id" class="form-select" onchange="updateLivePreview()" required>
                            <option value="">-- Select Service --</option>
                            <?php foreach ($allServices as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" data-name-en="<?= htmlspecialchars($s['name_en']) ?>" data-name-km="<?= htmlspecialchars($s['name_km']) ?>">
                                    <?= htmlspecialchars($s['name_en']) ?> (<?= htmlspecialchars($s['name_km']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Promo Bundle Price ($ USD) <span style="color: var(--danger);">*</span></label>
                        <input type="number" step="0.25" min="0" id="upsell_offer_price_usd" name="offer_price_usd" class="form-input" style="font-weight: 700; font-family: var(--font-mono); color: var(--success);" placeholder="5.00" value="5.00" oninput="updateLivePreview()" required>
                    </div>
                </div>

                <!-- Bilingual Headlines -->
                <div class="grid grid-cols-2" style="gap: 16px; margin-bottom: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Headline (English)</label>
                        <input type="text" id="upsell_offer_headline_en" name="offer_headline_en" class="form-input" placeholder="e.g. Add Hydrophobic Wax &amp; Polish for +$5.00?" oninput="updateLivePreview()">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Headline (Khmer)</label>
                        <input type="text" id="upsell_offer_headline_km" name="offer_headline_km" class="form-input" placeholder="e.g. បន្ថែមបាញ់ក្រមួន Wax &amp; ប៉ូលាពន្លឺ ត្រឹមតែ +$5.00 ដែរឬទេ?" oninput="updateLivePreview()">
                    </div>
                </div>

                <!-- Bilingual Pitch Descriptions -->
                <div class="grid grid-cols-2" style="gap: 16px; margin-bottom: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Pitch Description (English)</label>
                        <textarea id="upsell_offer_description_en" name="offer_description_en" class="form-textarea" rows="2" placeholder="e.g. Add water-repellent protective shine to your exterior wash." oninput="updateLivePreview()"></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Pitch Description (Khmer)</label>
                        <textarea id="upsell_offer_description_km" name="offer_description_km" class="form-textarea" rows="2" placeholder="e.g. បន្ថែមស្រទាប់ក្រមួនការពារទឹក និងបង្កើនពន្លឺលើការលាងក្រៅ។" oninput="updateLivePreview()"></textarea>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 18px; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="upsell_is_active" name="is_active" value="1" checked style="width: 18px; height: 18px; cursor: pointer;">
                    <label for="upsell_is_active" style="cursor: pointer; font-weight: 600; font-size: 13px; color: var(--text-main); margin-bottom: 0;">
                        Enable this promotion rule immediately upon saving
                    </label>
                </div>

                <!-- Integrated Live POS Preview Box (Styled in harmony with project) -->
                <div style="background: var(--bg-subtle); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-tablet-button" style="color: var(--primary);"></i> Live Cashier POS Dialog Preview
                        </div>
                        <div style="display: flex; gap: 4px; background: var(--bg-surface); padding: 2px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                            <button type="button" id="prevLangKm" class="btn btn-sm" style="padding: 2px 8px; font-size: 11px; font-weight: 700; background: var(--primary); color: #fff; border: none; border-radius: var(--radius-sm);" onclick="setPreviewLang('km')">
                                ភាសាខ្មែរ
                            </button>
                            <button type="button" id="prevLangEn" class="btn btn-sm" style="padding: 2px 8px; font-size: 11px; font-weight: 700; background: transparent; color: var(--text-muted); border: none; border-radius: var(--radius-sm);" onclick="setPreviewLang('en')">
                                English
                            </button>
                        </div>
                    </div>

                    <!-- Mini Preview Card matching POS theme -->
                    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; box-shadow: var(--shadow-sm);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                            <span id="previewTag" class="badge badge-purple" style="font-size: 11px;">
                                ✨ ការផ្តល់ជូនបន្ថែមពិសេស
                            </span>
                            <span class="text-xs text-muted">Preview</span>
                        </div>
                        <h4 id="previewHeadline" style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; line-height: 1.3;">
                            បន្ថែមបាញ់ក្រមួន Wax &amp; ប៉ូលាពន្លឺ ត្រឹមតែ +$5.00 ដែរឬទេ?
                        </h4>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; background: var(--bg-subtle); border-radius: var(--radius-sm); margin-bottom: 10px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div id="previewIconBox" class="stat-icon green" style="width: 36px; height: 36px; font-size: 16px; border-radius: var(--radius-sm); flex-shrink: 0;">
                                    <i class="fa-solid fa-spray-can-sparkles" id="previewIcon"></i>
                                </div>
                                <div>
                                    <div id="previewTargetName" style="font-weight: 700; font-size: 13px; color: var(--text-main);">
                                        បាញ់ក្រមួន Wax &amp; ប៉ូលាពន្លឺ
                                    </div>
                                    <div id="previewDesc" class="text-xs text-muted" style="margin-top: 2px;">
                                        បន្ថែមស្រទាប់ក្រមួនការពារទឹក និងបង្កើនពន្លឺលើការលាងក្រៅ។
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: right; flex-shrink: 0;">
                                <div id="previewPrice" style="font-weight: 700; font-family: var(--font-mono); color: var(--success); font-size: 14px;">
                                    +$5.00 <span class="text-xs text-muted">(20,500 ៛)</span>
                                </div>
                                <span id="previewBadge" class="badge badge-success" style="font-size: 10px;">+ បន្ថែមសេវា</span>
                            </div>
                        </div>

                        <div id="previewHint" style="padding: 6px 10px; background: var(--info-bg); border-radius: var(--radius-sm); font-size: 11.5px; color: var(--info); display: flex; align-items: center; gap: 6px; margin-bottom: 10px;">
                            <i class="fa-solid fa-circle-info"></i>
                            <span id="previewHintText">លាងរថយន្តខាងក្រៅមុន រួចបាញ់ក្រមួន Wax &amp; ប៉ូលា។ សេវាទាំងពីរស្ថិតក្នុងកន្ត្រកតែមួយ។</span>
                        </div>

                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <span id="previewBtnDismiss" class="btn btn-secondary btn-sm" style="pointer-events: none; opacity: 0.85;">ទុកពេលក្រោយ</span>
                            <span id="previewBtnAccept" class="btn btn-primary btn-sm" style="pointer-events: none;">✓ យល់ព្រមបន្ថែម (+$5.00)</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUpsellRule')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check mr-1"></i> Save Promotion Rule
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentPreviewLang = 'km';

function setPreviewLang(lang) {
    currentPreviewLang = lang;
    const btnKm = document.getElementById('prevLangKm');
    const btnEn = document.getElementById('prevLangEn');
    if (!btnKm || !btnEn) return;

    if (lang === 'km') {
        btnKm.style.background = 'var(--primary)';
        btnKm.style.color = '#fff';
        btnEn.style.background = 'transparent';
        btnEn.style.color = 'var(--text-muted)';
    } else {
        btnEn.style.background = 'var(--primary)';
        btnEn.style.color = '#fff';
        btnKm.style.background = 'transparent';
        btnKm.style.color = 'var(--text-muted)';
    }
    updateLivePreview();
}

function setActionType(type) {
    const isAddon = (type === 'add_service');
    const rAddon = document.getElementById('action_type_addon');
    const rUpgrade = document.getElementById('action_type_upgrade');
    if (rAddon) rAddon.checked = isAddon;
    if (rUpgrade) rUpgrade.checked = !isAddon;
    updateActionTypeSelection();
    updateLivePreview();
}

function updateActionTypeSelection() {
    const rAddon = document.getElementById('action_type_addon');
    const isAddon = rAddon ? rAddon.checked : true;
    const lAddon = document.getElementById('label_addon');
    const lUpgrade = document.getElementById('label_upgrade');
    if (lAddon && lUpgrade) {
        if (isAddon) {
            lAddon.style.borderColor = 'var(--primary)';
            lUpgrade.style.borderColor = 'var(--border)';
        } else {
            lAddon.style.borderColor = 'var(--border)';
            lUpgrade.style.borderColor = 'var(--primary)';
        }
    }
}

function updateLivePreview() {
    const isKm = (currentPreviewLang === 'km');
    const rAddon = document.getElementById('action_type_addon');
    const isAddon = rAddon ? rAddon.checked : true;
    const offerSvcSelect = document.getElementById('upsell_offer_service_id');
    const selectedOpt = offerSvcSelect ? offerSvcSelect.options[offerSvcSelect.selectedIndex] : null;
    const offerNameEn = selectedOpt ? (selectedOpt.getAttribute('data-name-en') || '') : '';
    const offerNameKm = selectedOpt ? (selectedOpt.getAttribute('data-name-km') || '') : '';
    
    const priceInput = document.getElementById('upsell_offer_price_usd');
    const priceVal = priceInput ? (parseFloat(priceInput.value) || 5.00) : 5.00;
    const priceKhr = Math.round(priceVal * 4100);

    const headEn = document.getElementById('upsell_offer_headline_en') ? document.getElementById('upsell_offer_headline_en').value.trim() : '';
    const headKm = document.getElementById('upsell_offer_headline_km') ? document.getElementById('upsell_offer_headline_km').value.trim() : '';
    const descEn = document.getElementById('upsell_offer_description_en') ? document.getElementById('upsell_offer_description_en').value.trim() : '';
    const descKm = document.getElementById('upsell_offer_description_km') ? document.getElementById('upsell_offer_description_km').value.trim() : '';

    const elTag = document.getElementById('previewTag');
    const elHeadline = document.getElementById('previewHeadline');
    const elTargetName = document.getElementById('previewTargetName');
    const elBadge = document.getElementById('previewBadge');
    const elDesc = document.getElementById('previewDesc');
    const elPrice = document.getElementById('previewPrice');
    const elHintText = document.getElementById('previewHintText');
    const elBtnAccept = document.getElementById('previewBtnAccept');
    const elBtnDismiss = document.getElementById('previewBtnDismiss');
    const elIcon = document.getElementById('previewIcon');
    const elIconBox = document.getElementById('previewIconBox');

    if (elBtnDismiss) {
        elBtnDismiss.textContent = isKm ? 'ទុកពេលក្រោយ' : 'Not now';
    }

    if (isAddon) {
        if (elTag) elTag.textContent = isKm ? '✨ ការផ្តល់ជូនបន្ថែមពិសេស' : '✨ Special Add-On Offer';
        if (elBadge) {
            elBadge.textContent = isKm ? '+ បន្ថែមសេវា' : '+ ADD-ON';
            elBadge.className = 'badge badge-success';
        }
        if (elIcon) elIcon.className = 'fa-solid fa-spray-can-sparkles';
        if (elIconBox) elIconBox.className = 'stat-icon green';
        if (elHintText) elHintText.textContent = isKm ? 'លាងរថយន្តខាងក្រៅមុន រួចបាញ់ក្រមួន Wax & ប៉ូលា។ សេវាទាំងពីរស្ថិតក្នុងកន្ត្រកតែមួយ។' : 'Wash exterior first, then crew applies wax & polish. Both items stay in order.';
        if (elBtnAccept) elBtnAccept.textContent = isKm ? `✓ យល់ព្រមបន្ថែម (+$${priceVal.toFixed(2)})` : `✓ Add to Cart (+$${priceVal.toFixed(2)})`;
    } else {
        if (elTag) elTag.textContent = isKm ? '✨ កញ្ចប់ដំឡើងពិសេស' : '✨ Special Upgrade Offer';
        if (elBadge) {
            elBadge.textContent = isKm ? '⟳ ដំឡើងកញ្ចប់' : '⟳ UPGRADE';
            elBadge.className = 'badge badge-purple';
        }
        if (elIcon) elIcon.className = 'fa-solid fa-crown';
        if (elIconBox) elIconBox.className = 'stat-icon purple';
        if (elHintText) elHintText.textContent = isKm ? 'ដំឡើងសេវាក្នុងកន្ត្រកទៅកញ្ចប់ពេញលេញក្នុងតម្លៃពិសេស។' : 'Replaces the basic item in your cart with this full premium package.';
        if (elBtnAccept) elBtnAccept.textContent = isKm ? `✓ យល់ព្រមដំឡើង ($${priceVal.toFixed(2)})` : `✓ Upgrade ($${priceVal.toFixed(2)})`;
    }

    const defaultHeadKm = isAddon ? `បន្ថែម${offerNameKm || 'សេវាបន្ថែម'} ត្រឹមតែ +$${priceVal.toFixed(2)} ដែរឬទេ?` : `ដំឡើងទៅ ${offerNameKm || 'កញ្ចប់ពិសេស'} ត្រឹមតែ $${priceVal.toFixed(2)} ដែរឬទេ?`;
    const defaultHeadEn = isAddon ? `Add ${offerNameEn || 'Add-On'} for +$${priceVal.toFixed(2)}?` : `Upgrade to ${offerNameEn || 'Premium'} for $${priceVal.toFixed(2)}?`;

    if (elHeadline) elHeadline.textContent = isKm ? (headKm || defaultHeadKm) : (headEn || defaultHeadEn);
    if (elTargetName) elTargetName.textContent = isKm ? (offerNameKm || 'ឈ្មោះសេវា') : (offerNameEn || 'Target Service');
    if (elDesc) elDesc.textContent = isKm ? (descKm || 'ការផ្តល់ជូនពិសេសពីហាង AquaGleam') : (descEn || 'Special promotional offer from AquaGleam.');
    if (elPrice) elPrice.innerHTML = `${isAddon ? '+$' : '$'}${priceVal.toFixed(2)} <span class="text-xs text-muted" style="font-weight: normal;">(${priceKhr.toLocaleString()} ៛)</span>`;
}

function openNewUpsellModal() {
    const title = document.getElementById('upsellModalTitle');
    if (title) title.textContent = 'Add Promotion Rule';
    document.getElementById('upsell_rule_id').value = '0';
    document.getElementById('upsell_name').value = '';
    document.getElementById('upsell_trigger_service_id').value = '';
    document.getElementById('upsell_trigger_vehicle_type').value = '';
    setActionType('add_service');
    document.getElementById('upsell_offer_service_id').value = '';
    document.getElementById('upsell_offer_price_usd').value = '5.00';
    document.getElementById('upsell_offer_headline_en').value = '';
    document.getElementById('upsell_offer_headline_km').value = '';
    document.getElementById('upsell_offer_description_en').value = '';
    document.getElementById('upsell_offer_description_km').value = '';
    document.getElementById('upsell_is_active').checked = true;
    updateLivePreview();
    openModal('modalUpsellRule');
}

function openEditUpsellModal(rule) {
    const title = document.getElementById('upsellModalTitle');
    if (title) title.textContent = 'Edit Promotion Rule';
    document.getElementById('upsell_rule_id').value = rule.id || '0';
    document.getElementById('upsell_name').value = rule.name || '';
    document.getElementById('upsell_trigger_service_id').value = rule.trigger_service_id || '';
    document.getElementById('upsell_trigger_vehicle_type').value = rule.trigger_vehicle_type || '';
    setActionType(rule.action_type || 'add_service');
    document.getElementById('upsell_offer_service_id').value = rule.offer_service_id || '';
    document.getElementById('upsell_offer_price_usd').value = rule.offer_price_usd !== null ? rule.offer_price_usd : '5.00';
    document.getElementById('upsell_offer_headline_en').value = rule.offer_headline_en || '';
    document.getElementById('upsell_offer_headline_km').value = rule.offer_headline_km || '';
    document.getElementById('upsell_offer_description_en').value = rule.offer_description_en || '';
    document.getElementById('upsell_offer_description_km').value = rule.offer_description_km || '';
    document.getElementById('upsell_is_active').checked = (Number(rule.is_active) === 1);
    updateLivePreview();
    openModal('modalUpsellRule');
}

function closeUpsellModal() {
    closeModal('modalUpsellRule');
}
</script>
"""

with open('scratch/native_upsell_snippet.html', 'w', encoding='utf-8') as f:
    f.write(native_upsell_html)

print("Generated native_upsell_snippet.html successfully! Length:", len(native_upsell_html))
