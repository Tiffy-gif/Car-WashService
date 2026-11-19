with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Locate tab_receipt replacement area
start_token = "<!-- GROUP 2: RECEIPT SETTINGS -->"
money_token = "<!-- GROUP 3: MONEY & EXCHANGE RATE -->"

idx_start = content.find(start_token)
idx_money = content.find(money_token)

idx_receipt_div = content.rfind('<!-- ========================================================', 0, idx_start)
idx_money_div = content.rfind('<!-- ========================================================', 0, idx_money)

new_tab_receipt = """<!-- ======================================================== -->
<!-- GROUP 2: RECEIPT SETTINGS -->
<!-- ======================================================== -->
<div id="tab_receipt" class="tab-pane" style="display: <?= ($activeTab === 'receipt') ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px; align-items: start;">
        <!-- Left: Receipt Configuration Form Card -->
        <div class="settings-content-card">
            <div style="border-bottom: 1px solid var(--border); padding-bottom: 14px; margin-bottom: 24px;">
                <h2 style="font-size: 18px; font-weight: 700; color: var(--text-main, #0f172a); margin: 0 0 4px 0;">
                    <i class="fa-solid fa-receipt mr-2" style="color: var(--primary);"></i>Receipt &amp; 80mm Thermal Print
                </h2>
                <p style="font-size: 13px; color: var(--text-muted, #64748b); margin: 0;">
                    Control logo display, bilingual output, and custom thank-you footer on customer receipts.
                </p>
            </div>

            <form action="<?= APP_URL ?>/index.php?view=settings" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_receipt">
                <input type="hidden" name="tab" value="receipt">

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                        Thank-You Footer Message
                    </label>
                    <textarea name="receipt_footer" id="receiptFooterTextarea" class="form-input" rows="3" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 14px; font-family: 'Kantumruy Pro', sans-serif; resize: vertical;"><?= htmlspecialchars(!empty($settings['receipt_footer']) ? $settings['receipt_footer'] : 'Thank you for choosing AquaGleam! / សូមអរគុណ!') ?></textarea>
                    <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">Prints at the very bottom of every 80mm thermal receipt. Updates preview live as you type.</div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                            Show Shop Logo on Receipt
                        </label>
                        <select name="receipt_show_logo" id="receiptShowLogoSelect" class="form-select" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 14px;">
                            <option value="1" <?= (($settings['receipt_show_logo'] ?? '1') === '1') ? 'selected' : '' ?>>Show Logo at Top</option>
                            <option value="0" <?= (($settings['receipt_show_logo'] ?? '1') === '0') ? 'selected' : '' ?>>Hide Logo (Text Only)</option>
                        </select>
                        <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">Hiding the logo saves paper on compact 80mm/58mm printers.</div>
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-main, #1e293b); margin-bottom: 6px;">
                            Receipt Language Mode
                        </label>
                        <select name="receipt_language" id="receiptLanguageSelect" class="form-select" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 14px;">
                            <option value="both" <?= (($settings['receipt_language'] ?? 'both') === 'both') ? 'selected' : '' ?>>Bilingual (Both Khmer &amp; English)</option>
                            <option value="km" <?= (($settings['receipt_language'] ?? '') === 'km') ? 'selected' : '' ?>>Khmer Only (ភាសាខ្មែរ)</option>
                            <option value="en" <?= (($settings['receipt_language'] ?? '') === 'en') ? 'selected' : '' ?>>English Only</option>
                        </select>
                        <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">Controls headings and labels on printed customer receipts.</div>
                    </div>
                </div>

                <div style="background: var(--bg-hover, #f8fafc); border: 1px dashed var(--border, #cbd5e1); border-radius: 10px; padding: 16px 18px; margin: 24px 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <div style="font-weight: 700; font-size: 14px; color: var(--text-main);">
                            <i class="fa-solid fa-print mr-1" style="color: #0284c7;"></i> Test Print Receipt on Thermal Paper
                        </div>
                        <div style="font-size: 12px; color: var(--text-muted, #64748b); margin-top: 2px;">
                            Preview real thermal printer output before handing receipts to customers.
                        </div>
                    </div>
                    <a href="<?= APP_URL ?>/index.php?view=receipt&test=1" target="_blank" class="btn btn-secondary" style="background: var(--bg-surface, #fff); border: 1px solid #cbd5e1; color: var(--text-main, #334155); font-weight: 500; font-size: 13px; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
                        <i class="fa-solid fa-arrow-up-right-from-square text-muted"></i> Open Test Receipt (80mm)
                    </a>
                </div>

                <div style="margin-top: 28px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="submit" class="btn btn-primary" style="background: #0284c7; border: 1px solid #0284c7; color: #ffffff; padding: 9px 20px; border-radius: 8px; font-weight: 600; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 1px 2px rgba(2,132,199,0.2);">
                        <i class="fa-solid fa-floppy-disk"></i> Save Receipt Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Right Edge: Interactive Full Sample Receipt Preview -->
        <div style="position: sticky; top: 20px;">
            <div class="settings-receipt-preview-card" style="background: var(--bg-surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.04);">
                
                <!-- Preview Toolbar: Title + 80mm/58mm switch + Khmer/EN switch -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; gap: 6px; flex-wrap: wrap;">
                    <div style="font-size: 12.5px; font-weight: 700; color: var(--text-main, #0f172a); display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                        <span>Live Preview</span>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <!-- Paper Size Switcher -->
                        <div style="display: inline-flex; background: #e2e8f0; padding: 2px; border-radius: 6px;">
                            <button type="button" id="btnPaper80" onclick="setReceiptPaperSize('80mm')" style="border: none; background: #ffffff; color: #0284c7; font-weight: 700; font-size: 11px; padding: 3px 8px; border-radius: 4px; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.08);">80mm</button>
                            <button type="button" id="btnPaper58" onclick="setReceiptPaperSize('58mm')" style="border: none; background: transparent; color: #64748b; font-weight: 600; font-size: 11px; padding: 3px 8px; border-radius: 4px; cursor: pointer; transition: all 0.15s ease;">58mm</button>
                        </div>
                        <!-- Language Switcher -->
                        <div style="display: inline-flex; background: #e2e8f0; padding: 2px; border-radius: 6px;">
                            <button type="button" id="btnLangKm" onclick="setReceiptLang('km')" style="border: none; background: #ffffff; color: #0284c7; font-weight: 700; font-size: 11px; padding: 3px 8px; border-radius: 4px; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.08);">ខ្មែរ</button>
                            <button type="button" id="btnLangEn" onclick="setReceiptLang('en')" style="border: none; background: transparent; color: #64748b; font-weight: 600; font-size: 11px; padding: 3px 8px; border-radius: 4px; cursor: pointer; transition: all 0.15s ease;">EN</button>
                        </div>
                    </div>
                </div>

                <!-- Simulated Thermal Paper Container -->
                <div id="receiptPaperWrapper" style="display: flex; justify-content: center; align-items: flex-start; background: #f1f5f9; border-radius: 10px; padding: 12px 8px;">
                    <div id="receiptPaperContainer" class="paper-80mm" style="width: 100%; max-width: 310px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 18px 14px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); color: #000000; font-family: 'JetBrains Mono', 'Segoe UI Mono', monospace, 'Kantumruy Pro'; font-size: 11px; line-height: 1.35; transition: all 0.2s ease;">
                        
                        <!-- 1. Header & Real Shop Branding -->
                        <div style="text-align: center; margin-bottom: 8px;">
                            <div id="receiptPreviewLogoWrap" style="margin: 0 auto 10px; width: 44px; height: 44px; border-radius: 10px; background: #e0f2fe; display: <?= (($settings['receipt_show_logo'] ?? '1') === '0') ? 'none' : 'flex' ?>; align-items: center; justify-content: center; overflow: hidden;">
                                <?php if (!empty($settings['shop_logo'])): ?>
                                    <img id="receiptPreviewLogoImg" src="<?= APP_URL ?>/<?= htmlspecialchars($settings['shop_logo']) ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                    <i id="receiptPreviewLogoIcon" class="fa-solid fa-droplet" style="display: none; color: #0284c7; font-size: 20px;"></i>
                                <?php else: ?>
                                    <i id="receiptPreviewLogoIcon" class="fa-solid fa-droplet" style="color: #0284c7; font-size: 20px;"></i>
                                    <img id="receiptPreviewLogoImg" src="" style="display: none; max-width: 100%; max-height: 100%; object-fit: contain;">
                                <?php endif; ?>
                            </div>

                            <div id="receiptPreviewNameKm" style="font-family: 'Kantumruy Pro', sans-serif; font-size: 13.5px; font-weight: 700; color: #000000; line-height: 1.3; margin-bottom: 3px;">
                                <?= htmlspecialchars(!empty($settings['shop_name_km']) ? $settings['shop_name_km'] : 'សេវាកម្មលាងរថយន្ត') ?>
                            </div>
                            <div id="receiptPreviewNameEn" style="font-size: 11.5px; font-weight: 700; color: #222222; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px;">
                                <?= htmlspecialchars(!empty($settings['shop_name_en']) ? $settings['shop_name_en'] : 'AquaGleam Car Wash & Detailing') ?>
                            </div>
                            <div id="receiptPreviewAddress" style="font-size: 10px; color: #444444; line-height: 1.35; margin-bottom: 2px;">
                                <?= htmlspecialchars(!empty($settings['shop_address']) ? $settings['shop_address'] : 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh') ?>
                            </div>
                            <div id="receiptPreviewPhone" style="font-size: 10px; color: #444444;">
                                Tel: <?= htmlspecialchars(!empty($settings['shop_phone']) ? $settings['shop_phone'] : '012 334 556 / 098 776 543') ?>
                            </div>

                            <div style="border-top: 1px dashed #000000; margin: 8px 0 6px;"></div>

                            <!-- Document Title -->
                            <div id="previewDocTitle" style="font-weight: 800; font-size: 11.5px; letter-spacing: 0.5px; color: #000000;">
                                បង្កាន់ដៃទទួលប្រាក់ / សេវាកម្ម
                            </div>
                        </div>

                        <!-- 2. Order Metadata & Vehicle Box -->
                        <div style="font-size: 10px; margin-bottom: 6px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                                <span><span class="lbl-receipt-no">លេខបង្កាន់ដៃ:</span> <strong>TEST-261118</strong></span>
                                <span><?= date('d/m/Y H:i') ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span><span class="lbl-cashier">អ្នកគិតលុយ:</span> <?= htmlspecialchars($userName ?? 'Counter') ?></span>
                                <span><span class="lbl-customer">អតិថិជន:</span> Sokha Seng</span>
                            </div>
                        </div>

                        <!-- Prominent Vehicle License Plate -->
                        <div style="border: 1.5px solid #000000; border-radius: 4px; padding: 4px 6px; text-align: center; margin: 6px 0; background: #fafafa;">
                            <div style="font-size: 14px; font-weight: 800; letter-spacing: 1px; color: #000000;">2BD-9999</div>
                            <div class="lbl-plate-sub" style="font-size: 9.5px; text-transform: uppercase; color: #333333;">SUV PICKUP</div>
                        </div>

                        <!-- 3. Items Table Header -->
                        <div style="border-top: 1px dashed #000000; border-bottom: 1px dashed #000000; padding: 4px 0; margin: 6px 0 5px; display: flex; justify-content: space-between; font-weight: 700; font-size: 10px; text-transform: uppercase;">
                            <span class="lbl-col-desc">មុខទំនិញ / សេវាកម្ម</span>
                            <span class="lbl-col-total">តម្លៃ</span>
                        </div>

                        <!-- Sample Items Rows -->
                        <div style="margin-bottom: 5px;">
                            <div style="font-weight: 700; font-size: 10.5px;" class="item-name-1">លាងរថយន្តបាញ់ក្រមួន (Foam Wash &amp; Wax)</div>
                            <div style="display: flex; justify-content: space-between; font-size: 10px; color: #222222;">
                                <span>1 x $10.00</span>
                                <span style="font-weight: 700;">$10.00</span>
                            </div>
                        </div>

                        <div style="margin-bottom: 5px;">
                            <div style="font-weight: 700; font-size: 10.5px;" class="item-name-2">ត្រួតពិនិត្យ និងសារ៉ាំងហ្វ្រាំង (Brake Check)</div>
                            <div style="display: flex; justify-content: space-between; font-size: 10px; color: #222222;">
                                <span>1 x $5.00</span>
                                <span style="font-weight: 700;">$5.00</span>
                            </div>
                        </div>

                        <div style="margin-bottom: 5px;">
                            <div style="font-weight: 700; font-size: 10.5px;" class="item-name-3">ប្រេងម៉ាស៊ីន 5W-30 (Engine Oil Synthetic)</div>
                            <div style="display: flex; justify-content: space-between; font-size: 10px; color: #222222;">
                                <span>1 x $22.00</span>
                                <span style="font-weight: 700;">$22.00</span>
                            </div>
                        </div>

                        <div style="margin-bottom: 6px;">
                            <div style="font-weight: 700; font-size: 10.5px;" class="item-name-4">តម្រងប្រេងម៉ាស៊ីន (Oil Filter)</div>
                            <div style="display: flex; justify-content: space-between; font-size: 10px; color: #222222;">
                                <span>1 x $3.00</span>
                                <span style="font-weight: 700;">$3.00</span>
                            </div>
                        </div>

                        <!-- 4. Financial Calculations -->
                        <div style="border-top: 1px dashed #000000; padding-top: 6px; margin-top: 6px;">
                            <div style="display: flex; justify-content: space-between; font-size: 10px; margin-bottom: 2px;">
                                <span class="lbl-subtotal">សរុបបឋម:</span>
                                <span>$40.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 10px; margin-bottom: 4px; color: #dc2626;">
                                <span class="lbl-discount">បញ្ចុះតម្លៃ:</span>
                                <span>-$2.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 800; border-top: 1px solid #000000; padding-top: 4px; margin-bottom: 2px;">
                                <span class="lbl-total-usd">សរុបរួម (USD):</span>
                                <span>$38.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 800; margin-bottom: 2px;">
                                <span class="lbl-total-khr">សរុប (KHR):</span>
                                <span>155,800 ៛</span>
                            </div>
                            <div style="text-align: right; font-size: 9px; color: #444444; margin-bottom: 6px;" class="lbl-rate">
                                អត្រាប្តូរប្រាក់: $1.00 = 4,100 ៛
                            </div>

                            <div style="border-top: 1px dashed #000000; padding-top: 4px; margin-top: 4px; font-size: 10px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                                    <span class="lbl-cash-tendered">ប្រាក់ទទួល (CASH):</span>
                                    <span>$40.00</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-weight: 700;">
                                    <span class="lbl-change">ប្រាក់អាប់:</span>
                                    <span>$2.00 (8,200 ៛)</span>
                                </div>
                            </div>
                        </div>

                        <!-- 5. Thank-You Message (Live-Synced) -->
                        <div style="border-top: 1px dashed #000000; margin: 10px 0 8px;"></div>
                        <div id="receiptPreviewFooter" style="text-align: center; font-family: 'Kantumruy Pro', sans-serif; font-size: 10.5px; font-weight: 600; line-height: 1.4; color: #000000;">
                            <?= htmlspecialchars(!empty($settings['receipt_footer']) ? $settings['receipt_footer'] : 'Thank you for choosing AquaGleam! / សូមអរគុណ!') ?>
                        </div>
                    </div>
                </div>

                <!-- Footer note -->
                <div style="text-align: center; font-size: 10.5px; color: var(--text-muted, #94a3b8); margin-top: 10px;">
                    Reflects unsaved edits in real-time.
                </div>
            </div>
        </div>
    </div>
</div>
"""

# Next, update setupReceiptLiveSync and add setReceiptPaperSize and setReceiptLang in the script
script_start_token = "// Live Receipt Preview Sync"
idx_script_start = content.find(script_start_token)

# Find where setupReceiptLiveSync ends (around handleLogoSelect)
idx_handle_logo = content.find("// Logo Selection & Drag-and-Drop")

new_script_part = """// Live Receipt Preview Sync & Interactive Controls
let currentPaperSize = '80mm';
let currentReceiptLang = 'km';

const RECEIPT_TRANSLATIONS = {
    km: {
        docTitle: 'បង្កាន់ដៃទទួលប្រាក់ / សេវាកម្ម',
        receiptNo: 'លេខបង្កាន់ដៃ:',
        cashier: 'អ្នកគិតលុយ:',
        customer: 'អតិថិជន:',
        plateSub: 'រថយន្ត SUV PICKUP',
        colDesc: 'មុខទំនិញ / សេវាកម្ម',
        colTotal: 'តម្លៃ',
        item1: 'លាងរថយន្តបាញ់ក្រមួន (Foam Wash & Wax)',
        item2: 'ត្រួតពិនិត្យ និងសារ៉ាំងហ្វ្រាំង (Brake Check)',
        item3: 'ប្រេងម៉ាស៊ីន 5W-30 (Engine Oil Synthetic)',
        item4: 'តម្រងប្រេងម៉ាស៊ីន (Oil Filter)',
        subtotal: 'សរុបបឋម:',
        discount: 'បញ្ចុះតម្លៃ:',
        totalUsd: 'សរុបរួម (USD):',
        totalKhr: 'សរុប (KHR):',
        rate: 'អត្រាប្តូរប្រាក់: $1.00 = 4,100 ៛',
        cashTendered: 'ប្រាក់ទទួល (CASH):',
        change: 'ប្រាក់អាប់:'
    },
    en: {
        docTitle: 'WASH SERVICE RECEIPT',
        receiptNo: 'Receipt #:',
        cashier: 'Cashier:',
        customer: 'Customer:',
        plateSub: 'SUV PICKUP',
        colDesc: 'Service / Item',
        colTotal: 'Amount',
        item1: 'Premium Foam Wash & Wax',
        item2: 'Brake Check & Adjustment',
        item3: 'Engine Oil 5W-30 Synthetic',
        item4: 'High-Flow Oil Filter',
        subtotal: 'Subtotal:',
        discount: 'Discount:',
        totalUsd: 'TOTAL (USD):',
        totalKhr: 'TOTAL (KHR):',
        rate: 'Exchange Rate: $1.00 = 4,100 KHR',
        cashTendered: 'Cash Tendered (CASH):',
        change: 'Change Due:'
    },
    both: {
        docTitle: 'WASH SERVICE RECEIPT / បង្កាន់ដៃ',
        receiptNo: 'Receipt # / លេខ:',
        cashier: 'Cashier / បេឡា:',
        customer: 'Customer / អតិថិជន:',
        plateSub: 'SUV PICKUP',
        colDesc: 'SERVICE / សេវាកម្ម',
        colTotal: 'AMOUNT / តម្លៃ',
        item1: 'Premium Foam Wash & Wax',
        item2: 'Brake Check & Adjustment',
        item3: 'Engine Oil 5W-30 Synthetic',
        item4: 'High-Flow Oil Filter',
        subtotal: 'Subtotal / បឋម:',
        discount: 'Discount / បញ្ចុះតម្លៃ:',
        totalUsd: 'TOTAL (USD):',
        totalKhr: 'TOTAL (KHR):',
        rate: 'Rate / អត្រាប្តូរប្រាក់: $1.00 = 4,100 ៛',
        cashTendered: 'Cash Tendered / ទទួល:',
        change: 'Change / ប្រាក់អាប់:'
    }
};

function setReceiptPaperSize(size) {
    currentPaperSize = size;
    const container = document.getElementById('receiptPaperContainer');
    const btn80 = document.getElementById('btnPaper80');
    const btn58 = document.getElementById('btnPaper58');
    if (!container) return;

    if (size === '58mm') {
        container.style.maxWidth = '236px';
        container.style.padding = '12px 8px';
        container.style.fontSize = '9.5px';
        if (btn58) {
            btn58.style.background = '#ffffff';
            btn58.style.color = '#0284c7';
            btn58.style.fontWeight = '700';
            btn58.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
        }
        if (btn80) {
            btn80.style.background = 'transparent';
            btn80.style.color = '#64748b';
            btn80.style.fontWeight = '600';
            btn80.style.boxShadow = 'none';
        }
    } else {
        container.style.maxWidth = '310px';
        container.style.padding = '18px 14px';
        container.style.fontSize = '11px';
        if (btn80) {
            btn80.style.background = '#ffffff';
            btn80.style.color = '#0284c7';
            btn80.style.fontWeight = '700';
            btn80.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
        }
        if (btn58) {
            btn58.style.background = 'transparent';
            btn58.style.color = '#64748b';
            btn58.style.fontWeight = '600';
            btn58.style.boxShadow = 'none';
        }
    }
}

function setReceiptLang(lang) {
    currentReceiptLang = lang;
    const data = RECEIPT_TRANSLATIONS[lang] || RECEIPT_TRANSLATIONS.km;
    const btnKm = document.getElementById('btnLangKm');
    const btnEn = document.getElementById('btnLangEn');

    if (lang === 'en') {
        if (btnEn) {
            btnEn.style.background = '#ffffff';
            btnEn.style.color = '#0284c7';
            btnEn.style.fontWeight = '700';
            btnEn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
        }
        if (btnKm) {
            btnKm.style.background = 'transparent';
            btnKm.style.color = '#64748b';
            btnKm.style.fontWeight = '600';
            btnKm.style.boxShadow = 'none';
        }
    } else {
        if (btnKm) {
            btnKm.style.background = '#ffffff';
            btnKm.style.color = '#0284c7';
            btnKm.style.fontWeight = '700';
            btnKm.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
        }
        if (btnEn) {
            btnEn.style.background = 'transparent';
            btnEn.style.color = '#64748b';
            btnEn.style.fontWeight = '600';
            btnEn.style.boxShadow = 'none';
        }
    }

    // Apply translations to DOM elements
    const setElemText = (sel, text) => {
        const el = document.querySelector(sel);
        if (el) el.textContent = text;
    };

    setElemText('#previewDocTitle', data.docTitle);
    setElemText('.lbl-receipt-no', data.receiptNo);
    setElemText('.lbl-cashier', data.cashier);
    setElemText('.lbl-customer', data.customer);
    setElemText('.lbl-plate-sub', data.plateSub);
    setElemText('.lbl-col-desc', data.colDesc);
    setElemText('.lbl-col-total', data.colTotal);
    setElemText('.item-name-1', data.item1);
    setElemText('.item-name-2', data.item2);
    setElemText('.item-name-3', data.item3);
    setElemText('.item-name-4', data.item4);
    setElemText('.lbl-subtotal', data.subtotal);
    setElemText('.lbl-discount', data.discount);
    setElemText('.lbl-total-usd', data.totalUsd);
    setElemText('.lbl-total-khr', data.totalKhr);
    setElemText('.lbl-rate', data.rate);
    setElemText('.lbl-cash-tendered', data.cashTendered);
    setElemText('.lbl-change', data.change);
}

function setupReceiptLiveSync() {
    // Live Thank-You Footer Message
    const footerInput = document.getElementById('receiptFooterTextarea') || document.querySelector('textarea[name="receipt_footer"]');
    const footerPrev = document.getElementById('receiptPreviewFooter');
    if (footerInput && footerPrev) {
        footerInput.addEventListener('input', () => {
            footerPrev.textContent = footerInput.value.trim() || 'Thank you for choosing AquaGleam! / សូមអរគុណ!';
        });
    }

    // Live Show / Hide Logo
    const showLogoSelect = document.getElementById('receiptShowLogoSelect') || document.querySelector('select[name="receipt_show_logo"]');
    const logoWrap = document.getElementById('receiptPreviewLogoWrap');
    if (showLogoSelect && logoWrap) {
        showLogoSelect.addEventListener('change', () => {
            logoWrap.style.display = showLogoSelect.value === '0' ? 'none' : 'flex';
        });
    }

    // Live Receipt Language Mode Dropdown Sync
    const langSelect = document.getElementById('receiptLanguageSelect') || document.querySelector('select[name="receipt_language"]');
    if (langSelect) {
        langSelect.addEventListener('change', () => {
            if (langSelect.value === 'en') {
                setReceiptLang('en');
            } else if (langSelect.value === 'km') {
                setReceiptLang('km');
            } else {
                setReceiptLang('both');
            }
        });
    }

    // Shop info listeners (if changed on Shop Info tab)
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
            addrPrev.textContent = addrInput.value.trim() || 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh';
        });
    }
    if (phoneInput && phonePrev) {
        phoneInput.addEventListener('input', () => {
            phonePrev.textContent = 'Tel: ' + (phoneInput.value.trim() || '012 334 556 / 098 776 543');
        });
    }
}
"""

# Let's perform replacement
new_content = (
    content[:idx_receipt_div]
    + new_tab_receipt
    + content[idx_money_div:idx_script_start]
    + new_script_part
    + "\n"
    + content[idx_handle_logo:]
)

with open('views/owner/settings.php', 'w', encoding='utf-8') as f:
    f.write(new_content)

print("Settings successfully updated with full sample receipt preview!")
