const fs = require('fs');

// 1. Refine pos/js/app.js
let js = fs.readFileSync('pos/js/app.js', 'utf8');

// Update allBtn in renderCategories to include the icon
const oldAllBtn = `allBtn.innerHTML = \`<span>\${allText}</span>\`;`;
const newAllBtn = `allBtn.innerHTML = \`<i class="fa-solid fa-shapes"></i><span class="pill-text">\${allText}</span>\`;`;
if (js.includes(oldAllBtn)) {
    js = js.replace(oldAllBtn, newAllBtn);
    console.log('Updated allBtn in pos/js/app.js to include icon.');
}

fs.writeFileSync('pos/js/app.js', js);

// 2. Refine pos/css/app.css
let css = fs.readFileSync('pos/css/app.css', 'utf8');

const refinementCss = `
/* ==========================================================================
   GOTPOS PIXEL-PERFECT REFINEMENTS
   ========================================================================== */

/* Active Vehicle Switcher - Crisp White Text & Icons */
.vehicle-type-switcher .vehicle-btn.active,
.gotpos-vehicle-switcher .vehicle-btn.active {
    background: #ff762d !important;
    border-color: #ff762d !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 2px 8px rgba(255, 118, 45, 0.3) !important;
}
.vehicle-type-switcher .vehicle-btn.active span,
.vehicle-type-switcher .vehicle-btn.active i,
.gotpos-vehicle-switcher .vehicle-btn.active span,
.gotpos-vehicle-switcher .vehicle-btn.active i {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Category Vertical Sidebar & "All" Pill */
.category-vertical-sidebar .category-pill {
    padding: 10px 4px !important;
    min-height: 58px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
}
.category-vertical-sidebar .category-pill.active {
    background: #ffffff !important;
    border: 1.5px solid #ff762d !important;
    color: #ff762d !important;
    box-shadow: 0 2px 8px rgba(255, 118, 45, 0.15) !important;
}
.category-vertical-sidebar .category-pill i {
    font-size: 18px !important;
    margin-bottom: 4px !important;
    display: block !important;
}
.category-vertical-sidebar .category-pill.active i {
    color: #ff762d !important;
}

/* Circular Plus / Checkmark Button on Product Cards */
.btn-card-add {
    width: 28px !important;
    height: 28px !important;
    min-width: 28px !important;
    max-width: 28px !important;
    border-radius: 50% !important;
    background: #ff762d !important;
    color: transparent !important;
    font-size: 0 !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: none !important;
    cursor: pointer !important;
    position: relative !important;
    transition: transform 0.15s, background 0.15s !important;
    line-height: 1 !important;
}
.btn-card-add::before {
    content: "+" !important;
    font-size: 20px !important;
    font-weight: 700 !important;
    line-height: 1 !important;
    color: #ffffff !important;
    display: block !important;
    margin-top: -2px !important;
}
.pos-item-card.in-cart .btn-card-add {
    background: #10b981 !important;
}
.pos-item-card.in-cart .btn-card-add::before {
    content: "✓" !important;
    font-size: 15px !important;
    margin-top: 0 !important;
}

/* Bottom Pastel Action Icons Bar */
.gotpos-advance-features {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 10px 4px 4px 4px !important;
    border-top: 1px solid var(--gotpos-border) !important;
    margin-top: auto !important;
}
.btn-pastel-feature {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    background: transparent !important;
    border: none !important;
    cursor: pointer !important;
    padding: 0 !important;
    flex: 1 !important;
}
.pastel-circle {
    width: 38px !important;
    height: 38px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin-bottom: 4px !important;
    transition: transform 0.15s ease !important;
}
.pastel-circle i {
    font-size: 15px !important;
    display: block !important;
}
.pastel-label {
    font-size: 10.5px !important;
    color: var(--gotpos-text-muted) !important;
    font-weight: 600 !important;
    display: block !important;
}

/* Specific Pastel Color Palettes */
.bg-purple-subtle { background: #f3e8ff !important; }
.text-purple, .text-purple i { color: #9333ea !important; }

.bg-success-subtle { background: #dcfce7 !important; }
.text-success, .text-success i { color: #16a34a !important; }

.bg-pink-subtle { background: #fce7f3 !important; }
.text-pink, .text-pink i { color: #db2777 !important; }

.bg-secondary-subtle { background: #e0f2fe !important; }
.text-secondary, .text-secondary i { color: #0284c7 !important; }

.bg-danger-subtle { background: #ffe4e6 !important; }
.text-danger, .text-danger i { color: #e11d48 !important; }

[data-theme="dark"] .bg-purple-subtle { background: rgba(147, 51, 234, 0.22) !important; }
[data-theme="dark"] .bg-success-subtle { background: rgba(22, 163, 74, 0.22) !important; }
[data-theme="dark"] .bg-pink-subtle { background: rgba(219, 39, 119, 0.22) !important; }
[data-theme="dark"] .bg-secondary-subtle { background: rgba(2, 132, 199, 0.22) !important; }
[data-theme="dark"] .bg-danger-subtle { background: rgba(225, 29, 72, 0.22) !important; }

/* Top Navbar Spacing */
.header-actions {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.btn-gotpos-dash {
    white-space: nowrap !important;
}
`;

fs.appendFileSync('pos/css/app.css', '\n' + refinementCss);
console.log('Successfully refined pos/css/app.css!');

