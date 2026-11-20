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
    // Update browser URL query param without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url);
}

// Live Receipt Preview Sync & Interactive Controls
let currentPaperSize = '80mm';
let currentReceiptLang = 'km';

const RECEIPT_TRANSLATIONS = {
    km: {
        receiptNo: 'វិក្កយបត្រ៖',
        cashier: 'បេឡា៖',
        customer: 'អតិថិជន៖',
        plateTag: 'ស្លាកលេខ៖',
        colDesc: 'ទំនិញ/សេវា',
        colQty: 'ចំនួន',
        colTotal: 'តម្លៃ',
        item1: 'លាងសម្អាតខាងក្រៅ',
        item2: 'បាញ់ក្រមួនការពារទឹក',
        subtotal: 'សរុបបឋម៖',
        discount: 'បញ្ចុះតម្លៃ៖',
        totalUsd: 'សរុប USD៖',
        totalKhr: 'សរុប រៀល៖',
        rate: 'អត្រាប្តូរប្រាក់: $1.00 = 4,100 ៛',
        paymentMethod: 'វិធីទូទាត់៖',
        cashTendered: 'ប្រាក់ទទួល៖',
        change: 'ប្រាក់អាប់៖',
        valPayment: 'សាច់ប្រាក់ (CASH)',
        valCashier: 'ម្ចាស់ហាង',
        valCustomer: 'ភ្ញៀវទូទៅ'
    },
    en: {
        receiptNo: 'Invoice #:',
        cashier: 'Cashier:',
        customer: 'Customer:',
        plateTag: 'PLATE #:',
        colDesc: 'ITEM',
        colQty: 'QTY',
        colTotal: 'PRICE',
        item1: 'Exterior Wash',
        item2: 'Hydrophobic Wax & Polish',
        subtotal: 'Subtotal:',
        discount: 'Discount:',
        totalUsd: 'TOTAL USD:',
        totalKhr: 'TOTAL KHR:',
        rate: 'Rate: $1.00 = 4,100 KHR',
        paymentMethod: 'Payment:',
        cashTendered: 'Tendered:',
        change: 'Change:',
        valPayment: 'CASH',
        valCashier: 'Shop Owner',
        valCustomer: 'Walk-in Guest'
    },
    both: {
        receiptNo: 'វិក្កយបត្រ / Invoice #:',
        cashier: 'បេឡា / Cashier:',
        customer: 'អតិថិជន / Customer:',
        plateTag: 'ស្លាកលេខ / PLATE #:',
        colDesc: 'ទំនិញ/សេវា (ITEM)',
        colQty: 'ចំនួន',
        colTotal: 'តម្លៃ',
        item1: 'Exterior Wash (លាងខាងក្រៅ)',
        item2: 'Hydrophobic Wax & Polish (បាញ់ក្រមួន)',
        subtotal: 'សរុបបឋម / Subtotal:',
        discount: 'បញ្ចុះតម្លៃ / Discount:',
        totalUsd: 'TOTAL USD / សរុប USD:',
        totalKhr: 'TOTAL KHR / សរុប រៀល:',
        rate: 'អត្រាប្តូរប្រាក់ / Rate: $1.00 = 4,100 ៛',
        paymentMethod: 'វិធីទូទាត់ / Payment:',
        cashTendered: 'ប្រាក់ទទួល / Tendered:',
        change: 'ប្រាក់អាប់ / Change:',
        valPayment: 'សាច់ប្រាក់ (CASH)',
        valCashier: 'Shop Owner',
        valCustomer: 'Walk-in Guest'
    }
};

function setReceiptPaperSize(size) {
    currentPaperSize = size;
    const container = document.getElementById('receiptPaperContainer');
    const btn80 = document.getElementById('btnPaper80');
    const btn58 = document.getElementById('btnPaper58');
    if (!container) return;

    if (size === '58mm') {
        container.classList.add('paper-58mm');
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
        container.classList.remove('paper-58mm');
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
    const data = RECEIPT_TRANSLATIONS[lang] || RECEIPT_TRANSLATIONS.both;
    const btnKm = document.getElementById('btnLangKm');
    const btnBoth = document.getElementById('btnLangBoth');
    const btnEn = document.getElementById('btnLangEn');

    const resetBtn = (b) => {
        if (b) {
            b.style.background = 'transparent';
            b.style.color = '#64748b';
            b.style.fontWeight = '600';
            b.style.boxShadow = 'none';
        }
    };
    const activeBtn = (b) => {
        if (b) {
            b.style.background = '#ffffff';
            b.style.color = '#0284c7';
            b.style.fontWeight = '700';
            b.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
        }
    };

    resetBtn(btnKm);
    resetBtn(btnBoth);
    resetBtn(btnEn);

    if (lang === 'en') {
        activeBtn(btnEn);
    } else if (lang === 'km') {
        activeBtn(btnKm);
    } else {
        activeBtn(btnBoth);
    }

    // Toggle shop names in header based on language & switch
    const showShopName = document.getElementById('switchShowShopName')?.checked ?? true;
    const kmNameEl = document.getElementById('receiptPreviewNameKm');
    const enNameEl = document.getElementById('receiptPreviewNameEn');
    if (kmNameEl) kmNameEl.style.display = (showShopName && lang !== 'en') ? 'block' : 'none';
    if (enNameEl) enNameEl.style.display = (showShopName && lang !== 'km') ? 'block' : 'none';

    // Apply translations to DOM elements (checking for custom overrides first)
    const getLabel = (inputSel, fallback) => {
        const inp = document.querySelector(inputSel);
        return (inp && inp.value.trim()) ? inp.value.trim() : fallback;
    };
    const setElemText = (sel, text) => {
        const el = document.querySelector(sel);
        if (el && text !== undefined) el.textContent = text;
    };

    setElemText('.lbl-receipt-no', getLabel('#lblInputInvoice', data.receiptNo));
    setElemText('.lbl-cashier', getLabel('#lblInputCashier', data.cashier));
    setElemText('.lbl-customer', getLabel('#lblInputCustomer', data.customer));
    setElemText('.val-cashier', data.valCashier);
    setElemText('.val-customer', data.valCustomer);
    setElemText('.lbl-plate-tag', getLabel('#lblInputPlate', data.plateTag));
    setElemText('.lbl-col-desc', getLabel('#lblInputItem', data.colDesc));
    setElemText('.lbl-col-qty', getLabel('#lblInputQty', data.colQty));
    setElemText('.lbl-col-total', getLabel('#lblInputPrice', data.colTotal));
    setElemText('.item-name-1', data.item1);
    setElemText('.item-name-2', data.item2);
    setElemText('.lbl-subtotal', getLabel('#lblInputSubtotal', data.subtotal));
    setElemText('.lbl-discount', getLabel('#lblInputDiscount', data.discount));
    setElemText('.lbl-tax', getLabel('#lblInputTax', lang === 'km' ? 'ពន្ធ / Tax:' : 'Tax / VAT (10%):'));
    setElemText('.lbl-total-usd', getLabel('#lblInputTotalUsd', data.totalUsd));
    setElemText('.lbl-total-khr', getLabel('#lblInputTotalKhr', data.totalKhr));
    setElemText('.lbl-rate', getLabel('#lblInputRate', data.rate));
    setElemText('.lbl-payment-method', getLabel('#lblInputPayment', data.paymentMethod));
    setElemText('.val-payment-method', data.valPayment);
    setElemText('.lbl-cash-tendered', getLabel('#lblInputTendered', data.cashTendered));
    setElemText('.lbl-change', getLabel('#lblInputChange', data.change));

    // Also sync the dropdown if different
    const langSelect = document.getElementById('receiptLanguageSelect');
    if (langSelect && langSelect.value !== lang) {
        langSelect.value = lang;
    }
}

function resetReceiptToDefaults() {
    if (!confirm('Reset all receipt settings back to clean thermal printer defaults?')) return;

    const actionInput = document.getElementById('receiptFormAction');
    const form = document.getElementById('receiptSettingsForm');
    if (actionInput && form) {
        actionInput.value = 'reset_receipt_defaults';
        form.submit();
        return;
    }

    // Fallback client-side reset
    const setCheck = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.checked = val;
    };
    setCheck('switchShowLogo', true);
    setCheck('switchShowShopName', true);
    setCheck('switchShowAddress', true);
    setCheck('switchShowPhone', true);
    setCheck('switchShowInvoiceNo', true);
    setCheck('switchShowDate', true);
    setCheck('switchShowCashier', true);
    setCheck('switchShowCustomer', true);
    setCheck('switchShowPlate', true);
    setCheck('switchShowVehicleType', false);
    setCheck('switchShowWasher', false);
    setCheck('switchShowItemCount', false);
    setCheck('switchShowDiscount', true);
    setCheck('switchShowTax', false);
    setCheck('switchShowExchangeRate', false);
    setCheck('switchShowPayment', true);
    setCheck('switchShowOilReminder', true);
    setCheck('switchShowNotes', true);
    setCheck('switchShowFooter', true);

    // Reset text boxes
    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = val;
    };
    setVal('receiptPaperSizeSelect', '80mm');
    setVal('receiptLanguageSelect', 'en');
    setVal('receiptFontSizeSelect', 'normal');
    setVal('receiptBoldModeSelect', '1');
    setVal('receiptHeaderAlignSelect', 'center');
    setVal('receiptLineSpacingSelect', 'normal');
    setVal('receiptLogoSizeSelect', 'medium');

    setVal('inputWelcomeLine', '');
    setVal('inputBottomLine', 'Powered by Counter POS');
    setVal('receiptFooterTextarea', 'Thank you for your visit!');
    setVal('inputHeaderExtra', '');
    setVal('inputFooterExtra', '');

    setVal('lblInputInvoice', '');
    setVal('lblInputDate', '');
    setVal('lblInputCashier', '');
    setVal('lblInputCustomer', '');
    setVal('lblInputPlate', '');
    setVal('lblInputVehicleType', '');
    setVal('lblInputWasher', '');
    setVal('lblInputItem', '');
    setVal('lblInputQty', '');
    setVal('lblInputPrice', '');
    setVal('lblInputSubtotal', '');
    setVal('lblInputDiscount', '');
    setVal('lblInputTax', '');
    setVal('lblInputTotalUsd', '');
    setVal('lblInputTotalKhr', '');
    setVal('lblInputRate', '');
    setVal('lblInputPayment', '');
    setVal('lblInputTendered', '');
    setVal('lblInputChange', '');

    setReceiptPaperSize('80mm');
    setReceiptLang('en');
    applyReceiptPreviewUpdates();
}

function applyReceiptPreviewUpdates() {
    const container = document.getElementById('receiptPaperContainer');
    if (!container) return;

    // 1. Paper Size
    const paperSelect = document.getElementById('receiptPaperSizeSelect');
    if (paperSelect) {
        setReceiptPaperSize(paperSelect.value);
    }

    // 2. Font Size
    const fontSelect = document.getElementById('receiptFontSizeSelect');
    if (fontSelect) {
        container.classList.remove('font-small', 'font-normal', 'font-large');
        container.classList.add('font-' + fontSelect.value);
    }

    // 3. Bold Mode
    const boldSelect = document.getElementById('receiptBoldModeSelect');
    if (boldSelect) {
        if (boldSelect.value === '0') container.classList.add('bold-off');
        else container.classList.remove('bold-off');
    }

    // 4. Header Alignment
    const alignSelect = document.getElementById('receiptHeaderAlignSelect');
    const headerEl = container.querySelector('.receipt-header');
    if (alignSelect && headerEl) {
        headerEl.classList.remove('align-left', 'align-center', 'align-right');
        headerEl.classList.add('align-' + alignSelect.value);
    }

    // 5. Line Spacing
    const spacingSelect = document.getElementById('receiptLineSpacingSelect');
    if (spacingSelect) {
        container.classList.remove('spacing-compact', 'spacing-normal', 'spacing-relaxed');
        container.classList.add('spacing-' + spacingSelect.value);
    }

    // 6. Logo Size
    const logoSizeSelect = document.getElementById('receiptLogoSizeSelect');
    const logoImg = document.getElementById('receiptPreviewLogoImg');
    if (logoSizeSelect && logoImg) {
        logoImg.classList.remove('logo-small', 'logo-medium', 'logo-large');
        logoImg.classList.add('logo-' + logoSizeSelect.value);
    }

    // 7. Show / Hide Switches
    const setDisplay = (id, show, displayType = 'block') => {
        const el = document.getElementById(id);
        if (el) el.style.display = show ? displayType : 'none';
    };
    const isChecked = (id) => {
        const el = document.getElementById(id);
        return el ? el.checked : true;
    };

    setDisplay('receiptPreviewLogoWrap', isChecked('switchShowLogo'), 'flex');
    const showShop = isChecked('switchShowShopName');
    setDisplay('receiptPreviewNameKm', showShop && currentReceiptLang !== 'en', 'block');
    setDisplay('receiptPreviewNameEn', showShop && currentReceiptLang !== 'km', 'block');
    setDisplay('receiptPreviewAddress', isChecked('switchShowAddress'), 'block');
    setDisplay('receiptPreviewPhone', isChecked('switchShowPhone'), 'block');

    // Metadata
    setDisplay('previewInvoiceNoRow', isChecked('switchShowInvoiceNo'), 'flex');
    setDisplay('previewDateSpan', isChecked('switchShowDate'), 'inline');
    setDisplay('previewCashierSpan', isChecked('switchShowCashier'), 'inline');
    setDisplay('previewCustomerSpan', isChecked('switchShowCustomer'), 'inline');
    setDisplay('previewWasherRow', isChecked('switchShowWasher'), 'flex');

    // Plate & Vehicle Type
    setDisplay('previewPlateBadge', isChecked('switchShowPlate'), 'flex');
    setDisplay('previewPlateSub', isChecked('switchShowVehicleType'), 'inline');

    // Financials
    setDisplay('previewItemCountRow', isChecked('switchShowItemCount'), 'flex');
    const showDisc = isChecked('switchShowDiscount');
    setDisplay('previewDiscountRow', showDisc, 'flex');
    const subtotalValEl = document.querySelector('#previewSubtotalRow span:last-child');
    if (subtotalValEl) {
        subtotalValEl.textContent = showDisc ? '$15.50' : '$13.50';
    }
    setDisplay('previewTaxRow', isChecked('switchShowTax'), 'flex');
    setDisplay('previewExchangeRate', isChecked('switchShowExchangeRate'), 'block');
    setDisplay('previewPaymentBlock', isChecked('switchShowPayment'), 'block');

    // Extra Boxes & Footers
    setDisplay('previewOilReminder', isChecked('switchShowOilReminder'), 'block');
    setDisplay('previewNotes', isChecked('switchShowNotes'), 'block');
    setDisplay('previewFooterWrap', isChecked('switchShowFooter'), 'block');
    setDisplay('previewFooterDivider', isChecked('switchShowFooter'), 'block');

    // Custom Messages & Extra Lines
    const welcomeInp = document.getElementById('inputWelcomeLine');
    const welcomeEl = document.getElementById('previewWelcomeLine');
    if (welcomeInp && welcomeEl) {
        welcomeEl.textContent = welcomeInp.value.trim();
        welcomeEl.style.display = welcomeInp.value.trim() ? 'block' : 'none';
    }

    const headerExtraInp = document.getElementById('inputHeaderExtra');
    const headerExtraEl = document.getElementById('previewHeaderExtra');
    if (headerExtraInp && headerExtraEl) {
        headerExtraEl.innerHTML = headerExtraInp.value.trim().replace(/\n/g, '<br>');
        headerExtraEl.style.display = headerExtraInp.value.trim() ? 'block' : 'none';
    }

    const footerExtraInp = document.getElementById('inputFooterExtra');
    const footerExtraEl = document.getElementById('previewFooterExtra');
    if (footerExtraInp && footerExtraEl) {
        footerExtraEl.innerHTML = footerExtraInp.value.trim().replace(/\n/g, '<br>');
        footerExtraEl.style.display = footerExtraInp.value.trim() ? 'block' : 'none';
    }

    const footerInp = document.getElementById('receiptFooterTextarea');
    const footerEl = document.getElementById('receiptPreviewFooter');
    if (footerInp && footerEl) {
        footerEl.textContent = footerInp.value.trim() || 'Thank you for your visit!';
    }

    const bottomInp = document.getElementById('inputBottomLine');
    const bottomEl = document.getElementById('receiptPreviewBottomLine');
    if (bottomInp && bottomEl) {
        bottomEl.textContent = bottomInp.value.trim() || 'Powered by Counter POS';
    }

    // Refresh translations and custom labels
    setReceiptLang(currentReceiptLang);
}

function setupReceiptLiveSync() {
    const form = document.getElementById('receiptSettingsForm');
    if (!form) return;

    // Listen to all inputs, selects, textareas for instantaneous live updates
    form.addEventListener('input', applyReceiptPreviewUpdates);
    form.addEventListener('change', applyReceiptPreviewUpdates);

    // Initial sync
    applyReceiptPreviewUpdates();

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
            enPrev.textContent = enInput.value.trim() || 'My Car Wash';
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
    setReceiptLang('<?= $settings['receipt_language'] ?? 'both' ?>');

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

// Live Exchange Rate Calculator & Confirmation
const rateInput = document.getElementById('new_exchange_rate_input');
if (rateInput) {
    rateInput.addEventListener('input', function() {
        const val = parseFloat(this.value) || 0;
        if (document.getElementById('prev_2_50')) document.getElementById('prev_2_50').innerText = Math.round(2.50 * val).toLocaleString() + ' ៛';
        if (document.getElementById('prev_5_00')) document.getElementById('prev_5_00').innerText = Math.round(5.00 * val).toLocaleString() + ' ៛';
        if (document.getElementById('prev_10_00')) document.getElementById('prev_10_00').innerText = Math.round(10.00 * val).toLocaleString() + ' ៛';
        if (document.getElementById('prev_25_00')) document.getElementById('prev_25_00').innerText = Math.round(25.00 * val).toLocaleString() + ' ៛';
    });
}

function confirmExchangeRateChange(e) {
    const curVal = document.getElementById('current_exchange_rate_val').value;
    const newVal = document.getElementById('new_exchange_rate_input').value;
    if (curVal !== newVal) {
        const msg = "WARNING: You are changing the exchange rate from " + parseInt(curVal).toLocaleString() + " KHR to " + parseInt(newVal).toLocaleString() + " KHR per USD.\n\n" +
                    "• All NEW orders, POS syncs, and financial reports will calculate with " + parseInt(newVal).toLocaleString() + " KHR.\n" +
                    "• Past completed orders will safely keep their original KHR totals.\n\n" +
                    "Are you sure you want to save this new exchange rate?";
        if (!confirm(msg)) {
            e.preventDefault();
            return false;
        }
    }
    return true;
}

// Modal Helpers
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
function openTelegramInfoModal() {
    openModal('telegramInfoModal');
}
function closeTelegramInfoModal() {
    closeModal('telegramInfoModal');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTelegramInfoModal();
    }
});

// Copy TV URL Helper
function copyTvUrl() {
    const input = document.getElementById('tvWebAddressInput');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(() => {
            alert('TV web address copied to clipboard!');
        }).catch(() => {
            document.execCommand('copy');
            alert('TV web address copied to clipboard!');
        });
    } else {
        document.execCommand('copy');
        alert('TV web address copied to clipboard!');
    }
}
