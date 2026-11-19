import re

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Part 1: Replace from top header to tab_receipt start
header_start_token = "require_once dirname(__DIR__) . '/layouts/header.php';\n?>"
receipt_tab_token = "<!-- ========================================================\n<!-- GROUP 2: RECEIPT SETTINGS -->"
if receipt_tab_token not in content:
    # try with exact match
    idx_receipt = content.find('id="tab_receipt"')
    # find comment preceding it
    idx_comm = content.rfind('<!-- ========================================================', 0, idx_receipt)
else:
    idx_comm = content.find(receipt_tab_token)

idx_header = content.find(header_start_token)
if idx_header == -1 or idx_comm == -1:
    print("ERROR finding anchors!", idx_header, idx_comm)
    exit(1)

part1_start = idx_header + len(header_start_token)
part1_end = idx_comm

replacement_part1 = """

<!-- Top Settings Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0; letter-spacing: -0.02em;">System Settings</h1>
        <p style="font-size: 13.5px; color: var(--text-muted); margin: 0;">Shop profile, currency, hardware and security controls</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="<?= APP_URL ?>/index.php?view=receipt&test=1" target="_blank" class="btn btn-secondary" style="background: var(--bg-surface, #fff); border: 1px solid var(--border, #cbd5e1); color: var(--text-main, #334155); font-weight: 500; font-size: 13px; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <i class="fa-solid fa-print text-muted"></i> Test receipt (80mm)
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-radius: var(--radius-md); font-weight: 600;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : ($messageType === 'danger' ? 'fa-circle-xmark' : 'fa-circle-info') ?>" style="font-size: 18px;"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 18px; cursor: pointer; color: inherit; line-height: 1;">&times;</button>
    </div>
<?php endif; ?>

<?php if (!empty($settings['migration_last_error'])): ?>
    <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #f87171; border-radius: var(--radius-md); padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626; font-size: 22px;"></i>
            <div>
                <strong style="color: #991b1b; font-size: 14px;">Database Migration Warning (Owner Notice)</strong>
                <div style="color: #b91c1c; font-size: 13px; margin-top: 2px;"><?= htmlspecialchars($settings['migration_last_error']) ?></div>
                <div class="text-xs text-muted" style="margin-top: 2px;">Technical details have been written to storage/logs/migration_error.log.</div>
            </div>
        </div>
        <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" style="margin: 0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="run_migrations">
            <input type="hidden" name="tab" value="system">
            <button type="submit" class="btn btn-sm btn-primary" style="background: #dc2626; border-color: #dc2626;">
                <i class="fa-solid fa-rotate mr-1"></i> Check &amp; Retry Migrations
            </button>
        </form>
    </div>
<?php endif; ?>

<style>
.settings-layout-grid {
    display: grid;
    grid-template-columns: 270px 1fr;
    gap: 24px;
    align-items: start;
    margin-top: 8px;
}
@media (max-width: 992px) {
    .settings-layout-grid {
        grid-template-columns: 1fr;
    }
}
.settings-sidebar-col {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.settings-nav-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 14px;
    padding: 12px 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.settings-nav-group-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted, #94a3b8);
    padding: 10px 12px 4px;
    margin: 0;
}
.settings-nav-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-muted, #475569);
    background: transparent;
    border: none;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s ease;
    text-decoration: none;
    margin-bottom: 2px;
}
.settings-nav-item:hover {
    background: var(--bg-hover, #f8fafc);
    color: var(--text-main, #0f172a);
}
.settings-nav-item.active {
    background: #e0f2fe;
    color: #0284c7;
    font-weight: 600;
}
[data-theme="dark"] .settings-nav-item.active {
    background: rgba(14, 165, 233, 0.18);
    color: #38bdf8;
}
.settings-nav-item-content {
    display: flex;
    align-items: center;
    gap: 10px;
}
.settings-nav-item i {
    font-size: 14px;
    width: 18px;
    text-align: center;
    color: #64748b;
}
.settings-nav-item.active i {
    color: #0284c7;
}
[data-theme="dark"] .settings-nav-item.active i {
    color: #38bdf8;
}
.settings-receipt-preview-card {
    background: #f1f5f9;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 14px;
    padding: 16px;
}
[data-theme="dark"] .settings-receipt-preview-card {
    background: #1e293b;
}
.settings-content-col {
    min-width: 0;
}
.settings-content-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 16px;
    padding: 28px 32px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
@media (max-width: 640px) {
    .settings-content-card {
        padding: 20px 16px;
    }
}
</style>

<div class="settings-layout-grid">
    <!-- LEFT COLUMN: Navigation & Receipt Preview -->
    <div class="settings-sidebar-col">
        <!-- Navigation Card -->
        <div class="settings-nav-card">
            <div class="settings-nav-group-title">GENERAL</div>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'shop') ? 'active' : '' ?>" data-tab="shop" onclick="switchTab('shop')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-store"></i>
                    <span>Shop Info</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'receipt') ? 'active' : '' ?>" data-tab="receipt" onclick="switchTab('receipt')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-receipt"></i>
                    <span>Receipt</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'system') ? 'active' : '' ?>" data-tab="system" onclick="switchTab('system')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-sliders"></i>
                    <span>System</span>
                </span>
            </button>

            <div class="settings-nav-group-title" style="margin-top: 10px;">SALES &amp; PRICING</div>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'money') ? 'active' : '' ?>" data-tab="money" onclick="switchTab('money')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-dollar-sign"></i>
                    <span>Money &amp; Rate</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'payment') ? 'active' : '' ?>" data-tab="payment" onclick="switchTab('payment')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-qrcode"></i>
                    <span>ABA &amp; KHQR</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'loyalty') ? 'active' : '' ?>" data-tab="loyalty" onclick="switchTab('loyalty')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-award" style="color: #f59e0b;"></i>
                    <span>Loyalty &amp; Tiers</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'upsell') ? 'active' : '' ?>" data-tab="upsell" onclick="switchTab('upsell')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-gift" style="color: #8b5cf6;"></i>
                    <span>Upsell &amp; Offers</span>
                </span>
            </button>

            <div class="settings-nav-group-title" style="margin-top: 10px;">OPERATIONS</div>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'bays') ? 'active' : '' ?>" data-tab="bays" onclick="switchTab('bays')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-display"></i>
                    <span>Live Bays &amp; Queue</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'oil') ? 'active' : '' ?>" data-tab="oil" onclick="switchTab('oil')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-droplet"></i>
                    <span>Oil Reminders</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'performance') ? 'active' : '' ?>" data-tab="performance" onclick="switchTab('performance')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-gauge-high" style="color: #10b981;"></i>
                    <span>Performance</span>
                </span>
            </button>

            <div class="settings-nav-group-title" style="margin-top: 10px;">CONNECTIONS</div>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'telegram') ? 'active' : '' ?>" data-tab="telegram" onclick="switchTab('telegram')">
                <span class="settings-nav-item-content">
                    <i class="fa-brands fa-telegram" style="color: #0284c7;"></i>
                    <span>Telegram</span>
                </span>
            </button>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'sync') ? 'active' : '' ?>" data-tab="sync" onclick="switchTab('sync')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-cloud-arrow-up" style="color: #6366f1;"></i>
                    <span>Cloud Sync</span>
                </span>
                <?php if (empty($settings['cloud_api_url']) || empty($settings['cloud_api_token'])): ?>
                    <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 11px; padding: 2px 7px; border-radius: 999px; font-weight: 600;">Paused</span>
                <?php else: ?>
                    <span class="badge" style="background: #dcfce7; color: #15803d; font-size: 11px; padding: 2px 7px; border-radius: 999px; font-weight: 600;">Active</span>
                <?php endif; ?>
            </button>

            <div class="settings-nav-group-title" style="margin-top: 10px;">HISTORY</div>
            <button type="button" class="settings-nav-item <?= ($activeTab === 'history') ? 'active' : '' ?>" data-tab="history" onclick="switchTab('history')">
                <span class="settings-nav-item-content">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Change History</span>
                </span>
                <span class="badge" style="background: #f1f5f9; color: #64748b; font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 600;"><?= count($historyList) ?></span>
            </button>
        </div>

        <!-- Receipt Preview Card (shown on Shop Info & Receipt tabs) -->
        <div class="settings-receipt-preview-card" id="settingsReceiptPreview" style="display: <?= in_array($activeTab, ['shop', 'receipt']) ? 'block' : 'none' ?>;">
            <div style="font-weight: 700; font-size: 14px; color: var(--text-main, #0f172a); margin-bottom: 2px;">Receipt preview</div>
            <div style="font-size: 12px; color: var(--text-muted, #64748b); margin-bottom: 16px;">How the header looks on 80mm paper</div>

            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px 16px 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); text-align: center;">
                <div id="receiptPreviewLogoWrap" style="margin: 0 auto 12px; width: 44px; height: 44px; border-radius: 10px; background: #e0f2fe; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                    <?php if (!empty($settings['shop_logo'])): ?>
                        <img id="receiptPreviewLogoImg" src="<?= APP_URL ?>/<?= htmlspecialchars($settings['shop_logo']) ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                        <i id="receiptPreviewLogoIcon" class="fa-solid fa-droplet" style="display: none; color: #0284c7; font-size: 20px;"></i>
                    <?php else: ?>
                        <i id="receiptPreviewLogoIcon" class="fa-solid fa-droplet" style="color: #0284c7; font-size: 20px;"></i>
                        <img id="receiptPreviewLogoImg" src="" style="display: none; max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php endif; ?>
                </div>

                <div id="receiptPreviewNameKm" style="font-family: 'Kantumruy Pro', sans-serif; font-size: 13.5px; font-weight: 700; color: #0f172a; line-height: 1.35; margin-bottom: 4px;">
                    <?= htmlspecialchars($settings['shop_name_km'] ?? 'សេវាកម្មលាងរថយន្ត') ?>
                </div>
                <div id="receiptPreviewNameEn" style="font-size: 11px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px;">
                    <?= htmlspecialchars($settings['shop_name_en'] ?? 'MY CAR WASH') ?>
                </div>
                <div id="receiptPreviewAddress" style="font-size: 11px; color: #64748b; line-height: 1.35; margin-bottom: 4px;">
                    <?= htmlspecialchars($settings['shop_address'] ?: 'Your address appears here') ?>
                </div>
                <div id="receiptPreviewPhone" style="font-size: 11px; color: #64748b;">
                    Tel: <?= htmlspecialchars($settings['shop_phone'] ?: 'your phone numbers') ?>
                </div>

                <div style="border-top: 1px dashed #cbd5e1; margin: 16px 0 12px;"></div>

                <!-- Skeleton preview ticket items -->
                <div style="height: 6px; background: #e2e8f0; border-radius: 3px; width: 85%; margin: 6px auto;"></div>
                <div style="height: 6px; background: #e2e8f0; border-radius: 3px; width: 60%; margin: 6px auto;"></div>
                <div style="height: 6px; background: #e2e8f0; border-radius: 3px; width: 75%; margin: 6px auto;"></div>

                <div style="border-top: 1px dashed #e2e8f0; margin-top: 16px;"></div>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Settings Content -->
    <div class="settings-content-col">
        <!-- ======================================================== -->
        <!-- GROUP 1: SHOP INFORMATION -->
        <!-- ======================================================== -->
        <div id="tab_shop" class="tab-pane" style="display: <?= ($activeTab === 'shop') ? 'block' : 'none' ?>;">
            <div class="settings-content-card">
                <div style="margin-bottom: 24px;">
                    <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main, #0f172a); margin: 0 0 4px 0;">Shop information</h2>
                    <p style="font-size: 13px; color: var(--text-muted, #64748b); margin: 0;">
                        Shown on receipt headers, counter screens, customer printouts and reports.
                    </p>
                </div>

                <form action="<?= APP_URL ?>/index.php?view=settings" method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_shop">
                    <input type="hidden" name="tab" value="shop">

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                                Shop name (Khmer) <span style="color: #ef4444;">*</span>
                            </label>
                            <input type="text" name="shop_name_km" id="shopNameKmInput" class="form-input" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 14px; font-family: 'Kantumruy Pro', sans-serif;" value="<?= htmlspecialchars($settings['shop_name_km'] ?? 'សេវាកម្មលាងរថយន្ត') ?>" required placeholder="ឈ្មោះហាងជាភាសាខ្មែរ">
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">ឈ្មោះហាងជាភាសាខ្មែរ</div>
                        </div>

                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                                Shop name (English) <span style="color: #ef4444;">*</span>
                            </label>
                            <input type="text" name="shop_name_en" id="shopNameEnInput" class="form-input" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 14px;" value="<?= htmlspecialchars($settings['shop_name_en'] ?? 'My Car Wash') ?>" required placeholder="My Car Wash">
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">Brand name shown in English</div>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                            Address
                        </label>
                        <input type="text" name="shop_address" id="shopAddressInput" class="form-input" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 14px;" value="<?= htmlspecialchars($settings['shop_address'] ?? '') ?>" placeholder="Street, sangkat, city">
                        <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">Printed at the top of 80mm receipts and contact cards</div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                            Phone numbers
                        </label>
                        <input type="text" name="shop_phone" id="shopPhoneInput" class="form-input" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 14px;" value="<?= htmlspecialchars($settings['shop_phone'] ?? '012 334 556 / 098 776 543') ?>" placeholder="012 334 556 / 098 776 543">
                        <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">Separate several numbers with a slash</div>
                    </div>

                    <div style="margin-bottom: 24px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                            Shop logo
                        </label>
                        <div id="shopLogoDropzone" onclick="document.getElementById('shopLogoFileInput').click()" style="border: 2px dashed #cbd5e1; border-radius: 10px; padding: 22px 24px; display: flex; align-items: center; gap: 20px; background: var(--bg-hover, #fafafa); cursor: pointer; transition: all 0.2s ease;">
                            <div id="shopLogoThumbBox" style="width: 58px; height: 58px; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #ffffff; flex-shrink: 0; overflow: hidden;">
                                <?php if (!empty($settings['shop_logo'])): ?>
                                    <img id="shopLogoThumbImg" src="<?= APP_URL ?>/<?= htmlspecialchars($settings['shop_logo']) ?>" style="max-height: 50px; max-width: 50px; object-fit: contain;">
                                    <i id="shopLogoPlaceholderIcon" class="fa-regular fa-image" style="display: none; font-size: 26px; color: #94a3b8;"></i>
                                <?php else: ?>
                                    <i id="shopLogoPlaceholderIcon" class="fa-regular fa-image" style="font-size: 26px; color: #94a3b8;"></i>
                                    <img id="shopLogoThumbImg" src="" style="display: none; max-height: 50px; max-width: 50px; object-fit: contain;">
                                <?php endif; ?>
                            </div>
                            <div style="flex: 1;">
                                <div style="font-size: 14px; color: #0284c7; font-weight: 600;">
                                    Click to upload or drop a file here
                                </div>
                                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                                    PNG, JPG, WebP or SVG. Best at 50–80px tall. A live preview appears here once chosen.
                                </div>
                            </div>
                        </div>
                        <input type="file" name="shop_logo_file" id="shopLogoFileInput" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="display: none;" onchange="handleLogoSelect(this)">
                        <?php if (!empty($settings['shop_logo'])): ?>
                            <div style="margin-top: 8px;">
                                <label style="font-size: 12px; color: #ef4444; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                    <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 32px; padding-top: 20px; border-top: 1px solid var(--border, #f1f5f9); flex-wrap: wrap; gap: 14px;">
                        <span style="font-size: 13px; color: var(--text-muted, #64748b);">Changes apply to new receipts immediately.</span>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button type="reset" class="btn btn-secondary" style="background: var(--bg-surface, #ffffff); border: 1px solid #cbd5e1; color: var(--text-main, #475569); padding: 8px 18px; border-radius: 8px; font-weight: 500; font-size: 13.5px;">Cancel</button>
                            <button type="submit" class="btn btn-primary" style="background: #0284c7; border: 1px solid #0284c7; color: #ffffff; padding: 8px 20px; border-radius: 8px; font-weight: 600; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 1px 2px rgba(2,132,199,0.2);">
                                <i class="fa-solid fa-floppy-disk"></i> Save changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

"""

# Part 2: Closing tags before script and script update
script_token = "<script>\n// Tab Switching"
idx_script = content.find(script_token)
if idx_script == -1:
    print("ERROR finding script token!")
    exit(1)

# Before the script, we close:
#     </div><!-- /.settings-content-col -->
# </div><!-- /.settings-layout-grid -->

replacement_script = """        </div>
    </div>
</div>

    </div><!-- /.settings-content-col -->
</div><!-- /.settings-layout-grid -->

<script>
// Tab Switching
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
    const target = document.getElementById('tab_' + tabId);
    if (target) {
        target.style.display = 'block';
    }
    // Update sidebar active states
    document.querySelectorAll('.settings-nav-item').forEach(btn => {
        if (btn.getAttribute('data-tab') === tabId) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    // Toggle receipt preview card
    const previewCard = document.getElementById('settingsReceiptPreview');
    if (previewCard) {
        if (tabId === 'shop' || tabId === 'receipt') {
            previewCard.style.display = 'block';
        } else {
            previewCard.style.display = 'none';
        }
    }
    // Update browser URL query param without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url);
}

// Live Receipt Preview Sync
function setupReceiptLiveSync() {
    const kmInput = document.getElementById('shopNameKmInput');
    const enInput = document.getElementById('shopNameEnInput');
    const addrInput = document.getElementById('shopAddressInput');
    const phoneInput = document.getElementById('shopPhoneInput');

    const kmPrev = document.getElementById('receiptPreviewNameKm');
    const enPrev = document.getElementById('receiptPreviewNameEn');
    const addrPrev = document.getElementById('receiptPreviewAddress');
    const phonePrev = document.getElementById('receiptPreviewPhone');

    if (kmInput && kmPrev) {
        kmInput.addEventListener('input', () => {
            kmPrev.textContent = kmInput.value.trim() || 'សេវាកម្មលាងរថយន្ត';
        });
    }
    if (enInput && enPrev) {
        enInput.addEventListener('input', () => {
            enPrev.textContent = (enInput.value.trim() || 'MY CAR WASH').toUpperCase();
        });
    }
    if (addrInput && addrPrev) {
        addrInput.addEventListener('input', () => {
            addrPrev.textContent = addrInput.value.trim() || 'Your address appears here';
        });
    }
    if (phoneInput && phonePrev) {
        phoneInput.addEventListener('input', () => {
            phonePrev.textContent = 'Tel: ' + (phoneInput.value.trim() || 'your phone numbers');
        });
    }
}

// Logo Selection & Drag-and-Drop
function handleLogoSelect(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        const thumbImg = document.getElementById('shopLogoThumbImg');
        const iconPlace = document.getElementById('shopLogoPlaceholderIcon');
        const prevImg = document.getElementById('receiptPreviewLogoImg');
        const prevIcon = document.getElementById('receiptPreviewLogoIcon');

        if (thumbImg) {
            thumbImg.src = e.target.result;
            thumbImg.style.display = 'block';
        }
        if (iconPlace) iconPlace.style.display = 'none';

        if (prevImg) {
            prevImg.src = e.target.result;
            prevImg.style.display = 'block';
        }
        if (prevIcon) prevIcon.style.display = 'none';
    };
    reader.readAsDataURL(file);
}

document.addEventListener('DOMContentLoaded', function() {
    setupReceiptLiveSync();

    const dropzone = document.getElementById('shopLogoDropzone');
    const fileInput = document.getElementById('shopLogoFileInput');
    if (dropzone && fileInput) {
        ['dragenter', 'dragover'].forEach(name => {
            dropzone.addEventListener(name, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.style.borderColor = '#0284c7';
                dropzone.style.background = '#f0f9ff';
            });
        });
        ['dragleave', 'drop'].forEach(name => {
            dropzone.addEventListener(name, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.style.borderColor = '#cbd5e1';
                dropzone.style.background = 'var(--bg-hover, #fafafa)';
            });
        });
        dropzone.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                handleLogoSelect(fileInput);
            }
        });
    }
});
"""

# Let's find end of tab_history (before script)
# Currently before idx_script, we have:
#         </div>
#     </div>
# </div>
#
# <script>
# // Tab Switching
# ... up to rateInput listener

# Let's see what is between idx_script and 'const rateInput'
idx_rate = content.find("// Live Exchange Rate Calculator")
if idx_rate == -1:
    print("ERROR finding rateInput!")
    exit(1)

# Find the end of tab_history
idx_tab_hist_end = content.rfind('</div>\n    </div>\n</div>', 0, idx_script)
if idx_tab_hist_end == -1:
    print("ERROR finding tab_hist_end!")
    exit(1)

# Now construct the final file:
new_content = (
    content[:part1_start]
    + replacement_part1
    + content[part1_end:idx_tab_hist_end]
    + replacement_script
    + "\n"
    + content[idx_rate:]
)

with open('views/owner/settings.php', 'w', encoding='utf-8') as f:
    f.write(new_content)

print("Settings page successfully updated!")
