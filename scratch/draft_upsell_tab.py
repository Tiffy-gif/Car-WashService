# Let's draft the tab_upsell HTML block
tab_upsell_html = """
<!-- ======================================================== -->
<!-- GROUP 6C: UPSELL & PROMOTION OFFERS -->
<!-- ======================================================== -->
<div id="tab_upsell" class="tab-pane" style="display: <?= ($activeTab === 'upsell') ? 'block' : 'none' ?>;">
    <!-- Stat Summary Badges -->
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div class="card" style="display: flex; align-items: center; gap: 14px; padding: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #ede9fe; color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-bullseye"></i>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Total Campaigns</div>
                <div style="font-size: 22px; font-weight: 800; color: var(--text-main); line-height: 1.2;"><?= count($allUpsellRules) ?></div>
            </div>
        </div>

        <div class="card" style="display: flex; align-items: center; gap: 14px; padding: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Active Offers</div>
                <div style="font-size: 22px; font-weight: 800; color: #059669; line-height: 1.2;"><?= (int)($upsellStats['active_rules'] ?? 0) ?></div>
            </div>
        </div>

        <div class="card" style="display: flex; align-items: center; gap: 14px; padding: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-eye"></i>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Offers Presented</div>
                <div style="font-size: 22px; font-weight: 800; color: var(--text-main); line-height: 1.2;"><?= $upsellLogStats['shown'] ?></div>
            </div>
        </div>

        <div class="card" style="display: flex; align-items: center; gap: 14px; padding: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 10px; background: #fdf2f8; color: #db2777; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                <i class="fa-solid fa-arrow-trend-up"></i>
            </div>
            <div>
                <div style="font-size: 12px; color: var(--text-muted); font-weight: 600;">Conversion Rate</div>
                <div style="font-size: 22px; font-weight: 800; color: #db2777; line-height: 1.2;"><?= $upsellLogStats['rate'] ?>%</div>
            </div>
        </div>
    </div>

    <!-- Master Switch Card -->
    <div class="card" style="margin-bottom: 20px;">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="font-size: 17px; font-weight: 700; color: var(--text-main);">
                    <i class="fa-solid fa-sliders mr-2" style="color: #8b5cf6;"></i>Global Upsell Engine Control
                </h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 3px;">
                    Control whether cashier POS checkouts display smart promotional upgrade popups.
                </p>
            </div>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_upsell_global">
            <input type="hidden" name="tab" value="upsell">

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: var(--bg-surface-alt, #f8fafc); border: 1px solid var(--border); border-radius: 8px;">
                <div>
                    <div style="font-weight: 700; font-size: 15px; color: var(--text-main); margin-bottom: 3px;">
                        Enable Smart Upsell &amp; Upgrade Popups at POS
                    </div>
                    <div style="font-size: 13px; color: var(--text-muted);">
                        When turned ON, cashiers are automatically prompted with add-on offers (e.g. Hydrophobic Wax) or upgrade packages when customers order basic wash services.
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 14px;">
                    <label class="switch" style="position: relative; display: inline-block; width: 48px; height: 26px;">
                        <input type="checkbox" name="enable_upsell_offers" value="1" <?= (($settings['enable_upsell_offers'] ?? '1') === '1') ? 'checked' : '' ?> style="opacity: 0; width: 0; height: 0;">
                        <span class="slider round" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 26px;"></span>
                    </label>
                    <button type="submit" class="btn btn-sm btn-primary" style="padding: 6px 14px;">
                        <i class="fa-solid fa-floppy-disk mr-1"></i> Save
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Active Upsell Rules Table Card -->
    <div class="card">
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="font-size: 17px; font-weight: 700; color: var(--text-main);">
                    <i class="fa-solid fa-tags mr-2" style="color: #8b5cf6;"></i>Upsell &amp; Upgrade Promotion Rules
                </h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-top: 3px;">
                    Rules that trigger when cashiers add specific services to the checkout cart.
                </p>
            </div>
            <button type="button" class="btn btn-primary" onclick="openNewUpsellModal()">
                <i class="fa-solid fa-plus mr-1"></i> Add Promotion Rule
            </button>
        </div>

        <?php if (empty($allUpsellRules)): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                <i class="fa-solid fa-bullseye" style="font-size: 38px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                <div style="font-weight: 700; font-size: 15px;">No Upsell Rules Defined</div>
                <div style="font-size: 13px; margin-top: 4px;">Click "Add Promotion Rule" above to create your first automated checkout offer.</div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-clean" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border); text-align: left; font-size: 12px; text-transform: uppercase; color: var(--text-muted);">
                            <th style="padding: 10px 12px;">Campaign / Offer Name</th>
                            <th style="padding: 10px 12px;">Trigger When Added</th>
                            <th style="padding: 10px 12px;">Action Type</th>
                            <th style="padding: 10px 12px;">Service Offered</th>
                            <th style="padding: 10px 12px;">Promo Price</th>
                            <th style="padding: 10px 12px;">Status</th>
                            <th style="padding: 10px 12px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allUpsellRules as $rule): ?>
                            <?php 
                                $isAddon = (($rule['action_type'] ?? 'add_service') === 'add_service');
                                $vType = $rule['trigger_vehicle_type'] ? ucwords(str_replace('_', ' ', $rule['trigger_vehicle_type'])) : 'All Vehicles';
                            ?>
                            <tr style="border-bottom: 1px solid var(--border); font-size: 13px;">
                                <td style="padding: 12px;">
                                    <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($rule['name']) ?></div>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px; font-family: var(--font-mono);"><?= htmlspecialchars($rule['rule_uid']) ?></div>
                                </td>
                                <td style="padding: 12px;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span class="badge" style="background: #f1f5f9; color: #334155; font-weight: 600; padding: 2px 8px; border-radius: 4px;">
                                            <i class="fa-solid fa-arrow-right-to-bracket mr-1" style="font-size: 10px;"></i>
                                            <?= htmlspecialchars($rule['trigger_service_name'] ?: 'Any Wash') ?>
                                        </span>
                                    </div>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                                        <i class="fa-solid fa-car mr-1"></i><?= htmlspecialchars($vType) ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <?php if ($isAddon): ?>
                                        <span class="badge" style="background: #ecfdf5; color: #047857; font-weight: 700; padding: 3px 8px; border-radius: 4px; border: 1px solid #a7f3d0;">
                                            <i class="fa-solid fa-plus mr-1"></i> ADD-ON (Keep Wash)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #f5f3ff; color: #6d28d9; font-weight: 700; padding: 3px 8px; border-radius: 4px; border: 1px solid #ddd6fe;">
                                            <i class="fa-solid fa-arrows-rotate mr-1"></i> UPGRADE (Replace)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;">
                                    <div style="font-weight: 600; color: var(--text-main);">
                                        <i class="fa-solid fa-sparkles mr-1" style="color: #8b5cf6;"></i>
                                        <?= htmlspecialchars($rule['offer_service_name'] ?: 'Target Service') ?>
                                    </div>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="font-weight: 800; font-family: var(--font-mono); color: #059669; font-size: 14px;">
                                        $<?= number_format((float)($rule['offer_price_usd'] ?? 5.00), 2) ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_upsell_rule">
                                        <input type="hidden" name="tab" value="upsell">
                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                        <?php if (!empty($rule['is_active'])): ?>
                                            <button type="submit" class="badge" style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; cursor: pointer; padding: 4px 8px; font-weight: 700; border-radius: 4px;" title="Click to disable">
                                                <i class="fa-solid fa-check mr-1"></i> Active
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="badge" style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; cursor: pointer; padding: 4px 8px; font-weight: 600; border-radius: 4px;" title="Click to enable">
                                                <i class="fa-solid fa-ban mr-1"></i> Inactive
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td style="padding: 12px; text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-secondary" style="padding: 4px 8px; margin-right: 4px;" onclick='openEditUpsellModal(<?= json_encode($rule, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>
                                    <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this promotion rule?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_upsell_rule">
                                        <input type="hidden" name="tab" value="upsell">
                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding: 4px 8px;">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Create / Edit Upsell Rule -->
<div id="modalUpsellRule" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: var(--bg-surface); width: 100%; max-width: 620px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <h3 id="upsellModalHeader" style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-main);">
                <i class="fa-solid fa-bullseye mr-2" style="color: #8b5cf6;"></i>Add Upsell Promotion Rule
            </h3>
            <button type="button" onclick="closeUpsellModal()" style="border: none; background: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="padding: 20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_upsell_rule">
            <input type="hidden" name="tab" value="upsell">
            <input type="hidden" id="upsell_rule_id" name="rule_id" value="0">

            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" style="font-weight: 700;">Campaign / Rule Name <span style="color: var(--danger);">*</span></label>
                <input type="text" id="upsell_name" name="rule_name" class="form-input" placeholder="e.g., Exterior Wash: Add Hydrophobic Wax" required>
            </div>

            <!-- Trigger & Vehicle Type -->
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">When Customer Chooses (Trigger)</label>
                    <select id="upsell_trigger_service_id" name="trigger_service_id" class="form-input">
                        <option value="">Any Wash Service</option>
                        <?php foreach ($allServices as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name_en']) ?> (<?= htmlspecialchars($s['name_km']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Vehicle Type Filter</label>
                    <select id="upsell_trigger_vehicle_type" name="trigger_vehicle_type" class="form-input">
                        <option value="">All Vehicle Types</option>
                        <option value="small_car">Small Car (Sedan)</option>
                        <option value="suv_pickup">SUV / Pickup</option>
                        <option value="van_truck">Van / Truck</option>
                        <option value="motorbike">Motorbike</option>
                    </select>
                </div>
            </div>

            <!-- Action Type Selection -->
            <div class="form-group" style="margin-bottom: 16px; padding: 12px; background: var(--bg-surface-alt, #f8fafc); border: 1px solid var(--border); border-radius: 8px;">
                <label class="form-label" style="font-weight: 700; margin-bottom: 8px;">Offer Type (Action at Checkout) <span style="color: var(--danger);">*</span></label>
                <div style="display: flex; gap: 20px;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                        <input type="radio" name="action_type" id="action_type_addon" value="add_service" checked style="margin-top: 3px;">
                        <div>
                            <strong style="color: #047857;">Add-On Protection (Recommended)</strong>
                            <div style="font-size: 11px; color: var(--text-muted);">Keeps the original wash in the cart and adds the extra service (e.g. Wash + Wax). Washer washes first, then applies wax.</div>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                        <input type="radio" name="action_type" id="action_type_upgrade" value="upgrade_service" style="margin-top: 3px;">
                        <div>
                            <strong style="color: #6d28d9;">Package Upgrade</strong>
                            <div style="font-size: 11px; color: var(--text-muted);">Replaces the basic wash with a premium combo package (e.g., Exterior Wash replaced by Full Wash Inside+Outside).</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Target Service & Offer Price -->
            <div class="grid" style="grid-template-columns: 2fr 1fr; gap: 14px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Service to Offer <span style="color: var(--danger);">*</span></label>
                    <select id="upsell_offer_service_id" name="offer_service_id" class="form-input" required>
                        <option value="">-- Select Service --</option>
                        <?php foreach ($allServices as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name_en']) ?> (<?= htmlspecialchars($s['name_km']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Promo Price ($ USD) <span style="color: var(--danger);">*</span></label>
                    <input type="number" step="0.25" min="0" id="upsell_offer_price_usd" name="offer_price_usd" class="form-input" style="font-weight: 700; font-family: var(--font-mono);" placeholder="5.00" value="5.00" required>
                </div>
            </div>

            <!-- Headline Texts -->
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Popup Headline (English)</label>
                    <input type="text" id="upsell_offer_headline_en" name="offer_headline_en" class="form-input" placeholder="e.g., Add Hydrophobic Wax & Polish for +$5?">
                </div>
                <div class="form-group">
                    <label class="form-label">Popup Headline (Khmer)</label>
                    <input type="text" id="upsell_offer_headline_km" name="offer_headline_km" class="form-input" placeholder="e.g., បន្ថែមបាញ់ក្រមួន Wax ត្រឹមតែ +$5 ដែរឬទេ?">
                </div>
            </div>

            <!-- Description Texts -->
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Description / Pitch (English)</label>
                    <textarea id="upsell_offer_description_en" name="offer_description_en" class="form-input" rows="2" placeholder="e.g., Keep your vehicle shining and protected with water-repellent wax coating."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Description / Pitch (Khmer)</label>
                    <textarea id="upsell_offer_description_km" name="offer_description_km" class="form-input" rows="2" placeholder="e.g., ជួយឱ្យរថយន្តភ្លឺរលោង និងការពារថ្នាំពណ៌ជាមួយស្រទាប់ក្រមួនការពារទឹក។"></textarea>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="upsell_is_active" name="is_active" value="1" checked style="width: 18px; height: 18px;">
                <label for="upsell_is_active" style="cursor: pointer; font-weight: 600; color: var(--text-main);">Rule is Active immediately</label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid var(--border); padding-top: 16px;">
                <button type="button" class="btn btn-secondary" onclick="closeUpsellModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Promotion Rule
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewUpsellModal() {
    document.getElementById('upsellModalHeader').innerHTML = '<i class="fa-solid fa-bullseye mr-2" style="color: #8b5cf6;"></i>Add Upsell Promotion Rule';
    document.getElementById('upsell_rule_id').value = '0';
    document.getElementById('upsell_name').value = '';
    document.getElementById('upsell_trigger_service_id').value = '';
    document.getElementById('upsell_trigger_vehicle_type').value = '';
    document.getElementById('action_type_addon').checked = true;
    document.getElementById('upsell_offer_service_id').value = '';
    document.getElementById('upsell_offer_price_usd').value = '5.00';
    document.getElementById('upsell_offer_headline_en').value = '';
    document.getElementById('upsell_offer_headline_km').value = '';
    document.getElementById('upsell_offer_description_en').value = '';
    document.getElementById('upsell_offer_description_km').value = '';
    document.getElementById('upsell_is_active').checked = true;
    
    const m = document.getElementById('modalUpsellRule');
    m.style.display = 'flex';
}

function openEditUpsellModal(rule) {
    document.getElementById('upsellModalHeader').innerHTML = '<i class="fa-solid fa-pen-to-square mr-2" style="color: #8b5cf6;"></i>Edit Upsell Promotion Rule';
    document.getElementById('upsell_rule_id').value = rule.id || '0';
    document.getElementById('upsell_name').value = rule.name || '';
    document.getElementById('upsell_trigger_service_id').value = rule.trigger_service_id || '';
    document.getElementById('upsell_trigger_vehicle_type').value = rule.trigger_vehicle_type || '';
    
    if ((rule.action_type || 'add_service') === 'upgrade_service') {
        document.getElementById('action_type_upgrade').checked = true;
    } else {
        document.getElementById('action_type_addon').checked = true;
    }
    
    document.getElementById('upsell_offer_service_id').value = rule.offer_service_id || '';
    document.getElementById('upsell_offer_price_usd').value = rule.offer_price_usd !== null ? rule.offer_price_usd : '5.00';
    document.getElementById('upsell_offer_headline_en').value = rule.offer_headline_en || '';
    document.getElementById('upsell_offer_headline_km').value = rule.offer_headline_km || '';
    document.getElementById('upsell_offer_description_en').value = rule.offer_description_en || '';
    document.getElementById('upsell_offer_description_km').value = rule.offer_description_km || '';
    document.getElementById('upsell_is_active').checked = (Number(rule.is_active) === 1);
    
    const m = document.getElementById('modalUpsellRule');
    m.style.display = 'flex';
}

function closeUpsellModal() {
    const m = document.getElementById('modalUpsellRule');
    m.style.display = 'none';
}

// Close modal on background click
window.addEventListener('click', function(e) {
    const m = document.getElementById('modalUpsellRule');
    if (e.target === m) {
        closeUpsellModal();
    }
});
</script>
"""
print("Draft script valid, length:", len(tab_upsell_html))
