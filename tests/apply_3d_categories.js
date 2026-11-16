const fs = require('fs');

// 1. Update pos/js/app.js in renderCategories
let appJs = fs.readFileSync('pos/js/app.js', 'utf8');

const targetAllBtn = `        // 2. Build "All" Button (No icon)
        const allBtn = document.createElement('button');
        allBtn.type = 'button';
        allBtn.className = 'category-pill' + (this.selectedCategory === 'all' ? ' active' : '');
        allBtn.setAttribute('data-cat', 'all');
        const allText = (lang === 'km') ? (I18nManager.t('allCategories') || 'ទាំងអស់') : (I18nManager.t('allCategories') || 'All');
        allBtn.innerHTML = \`<i class="fa-solid fa-shapes"></i><span class="pill-text">\${allText}</span>\`;`;

const replacementAllBtn = `        // 2. Build "All" Button (With GOTPOS 3D asset)
        const allBtn = document.createElement('button');
        allBtn.type = 'button';
        allBtn.className = 'category-pill' + (this.selectedCategory === 'all' ? ' active' : '');
        allBtn.setAttribute('data-cat', 'all');
        const allText = (lang === 'km') ? (I18nManager.t('allCategories') || 'ទាំងអស់') : (I18nManager.t('allCategories') || 'All');
        allBtn.innerHTML = \`<img src="icons/cat-all.png" alt="All" class="category-icon-img" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='block';"><i class="fa-solid fa-shapes" style="display:none;"></i><span class="pill-text">\${allText}</span>\`;`;

if (appJs.includes(targetAllBtn)) {
    appJs = appJs.replace(targetAllBtn, replacementAllBtn);
    console.log('Updated allBtn in renderCategories');
} else {
    console.log('targetAllBtn not matched directly, checking variations...');
}

// Update category item icon HTML in categories.forEach
const targetCatBtn = `            const displayName = this.getShortCategoryName(rawName, lang);
            const fullName = (lang === 'km') ? (cat.name_km || cat.name_en) : (cat.name_en || cat.name_km);
            const catIcon = cat.icon || PosApp.getCategoryIcon(cat.code, cat.name_en);

            btn.innerHTML = \`<i class="fa-solid \${catIcon}"></i><span>\${this.escapeHtml(displayName)}</span>\`;`;

const replacementCatBtn = `            const displayName = this.getShortCategoryName(rawName, lang);
            const fullName = (lang === 'km') ? (cat.name_km || cat.name_en) : (cat.name_en || cat.name_km);
            const catIcon = cat.icon || PosApp.getCategoryIcon(cat.code, cat.name_en);

            const catImageMap = {
                'all': 'cat-all.png',
                'wash': 'cat-clothing.png',
                'oil': 'cat-food.png',
                'engine_oil': 'cat-food.png',
                'oil_part': 'cat-accessories.png',
                'repair': 'cat-watches.png',
                'maint': 'cat-watches.png',
                'filter': 'cat-accessories.png',
                'fluid': 'cat-beauty.png',
                'coolant': 'cat-beauty.png',
                'care': 'cat-electronics.png',
                'products': 'cat-electronics.png',
                'spare_parts': 'cat-bags.png',
                'spare': 'cat-bags.png',
                'clothing': 'cat-clothing.png',
                'footwear': 'cat-footwear.png',
                'watches': 'cat-watches.png',
                'food': 'cat-food.png',
                'accessories': 'cat-accessories.png',
                'electronics': 'cat-electronics.png',
                'bags': 'cat-bags.png',
                'beauty': 'cat-beauty.png',
                'fitness': 'cat-fitness.png'
            };

            const codeKey = (cat.code || '').toLowerCase();
            const idKey = String(cat.id || '').toLowerCase();
            const imgFile = catImageMap[codeKey] || catImageMap[idKey];
            const iconHtml = imgFile 
                ? \`<img src="icons/\${imgFile}" alt="\${this.escapeHtml(displayName)}" class="category-icon-img" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='block';"><i class="fa-solid \${catIcon}" style="display: none;"></i>\`
                : \`<i class="fa-solid \${catIcon}"></i>\`;

            btn.innerHTML = \`\${iconHtml}<span>\${this.escapeHtml(displayName)}</span>\`;`;

if (appJs.includes(targetCatBtn)) {
    appJs = appJs.replace(targetCatBtn, replacementCatBtn);
    console.log('Updated category item buttons in renderCategories');
} else {
    console.log('targetCatBtn not matched directly, checking variations...');
}

fs.writeFileSync('pos/js/app.js', appJs, 'utf8');

// 2. Append CSS updates to pos/css/app.css
let css = fs.readFileSync('pos/css/app.css', 'utf8');

const fullCategoryStyles = `
/* ==========================================================================
   GOTPOS FULL 3D CATEGORY RAIL (Matching media_1794674529666_cafa9ac5.png)
   ========================================================================== */

/* Full Height Left Category Sidebar Rail */
.category-vertical-sidebar,
#categorySidebar {
    flex: 0 0 102px !important;
    width: 102px !important;
    min-width: 102px !important;
    max-width: 102px !important;
    height: 100% !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 0 6px 12px 0 !important;
    display: flex !important;
    flex-direction: column !important;
    scrollbar-width: none !important;
    border-right: 1px solid var(--gotpos-border) !important;
    box-sizing: border-box !important;
}

.category-vertical-sidebar::-webkit-scrollbar,
#categorySidebar::-webkit-scrollbar {
    display: none !important;
}

.category-list-scroll,
#categoryListWrap {
    display: flex !important;
    flex-direction: column !important;
    width: 100% !important;
    gap: 10px !important;
    align-items: center !important;
}

/* Category Cards */
.category-vertical-sidebar .category-pill,
#categorySidebar .category-pill {
    width: 90px !important;
    height: 84px !important;
    min-width: 90px !important;
    min-height: 84px !important;
    max-width: 90px !important;
    border-radius: 10px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 8px 4px 6px 4px !important;
    border: 1px solid #e9ecef !important;
    background: #ffffff !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02) !important;
    position: relative !important;
}

[data-theme="dark"] .category-vertical-sidebar .category-pill,
[data-theme="dark"] #categorySidebar .category-pill {
    background: #1e293b !important;
    border-color: #334155 !important;
}

.category-vertical-sidebar .category-pill:hover,
#categorySidebar .category-pill:hover {
    border-color: #ff762d !important;
    transform: translateY(-1px) !important;
}

/* Active Category Card: Solid orange border, white bg */
.category-vertical-sidebar .category-pill.active,
#categorySidebar .category-pill.active {
    border: 1.5px solid #ff762d !important;
    background: #ffffff !important;
    box-shadow: 0 2px 8px rgba(255, 118, 45, 0.15) !important;
}

[data-theme="dark"] .category-vertical-sidebar .category-pill.active,
[data-theme="dark"] #categorySidebar .category-pill.active {
    background: #1e293b !important;
    border-color: #ff762d !important;
}

/* 3D Category Image Icon */
.category-icon-img {
    width: 32px !important;
    height: 32px !important;
    object-fit: contain !important;
    margin-bottom: 5px !important;
    display: block !important;
    transition: transform 0.2s ease !important;
}

.category-pill:hover .category-icon-img {
    transform: scale(1.08) !important;
}

/* Fallback FontAwesome icon if image fails */
.category-vertical-sidebar .category-pill i,
#categorySidebar .category-pill i {
    font-size: 26px !important;
    margin-bottom: 5px !important;
    line-height: 1 !important;
    display: block !important;
}

/* Category Label Text */
.category-vertical-sidebar .category-pill span,
.category-vertical-sidebar .category-pill .pill-text,
#categorySidebar .category-pill span,
#categorySidebar .category-pill .pill-text {
    font-size: 12px !important;
    font-weight: 600 !important;
    line-height: 1.2 !important;
    text-align: center !important;
    color: #1e293b !important;
    display: block !important;
    word-break: break-word !important;
    max-width: 100% !important;
}

[data-theme="dark"] .category-vertical-sidebar .category-pill span,
[data-theme="dark"] .category-vertical-sidebar .category-pill .pill-text,
[data-theme="dark"] #categorySidebar .category-pill span,
[data-theme="dark"] #categorySidebar .category-pill .pill-text {
    color: #cbd5e1 !important;
}

.category-vertical-sidebar .category-pill.active span,
.category-vertical-sidebar .category-pill.active .pill-text,
#categorySidebar .category-pill.active span,
#categorySidebar .category-pill.active .pill-text {
    color: #1e293b !important;
    font-weight: 700 !important;
}

[data-theme="dark"] .category-vertical-sidebar .category-pill.active span,
[data-theme="dark"] .category-vertical-sidebar .category-pill.active .pill-text,
[data-theme="dark"] #categorySidebar .category-pill.active span,
[data-theme="dark"] #categorySidebar .category-pill.active .pill-text {
    color: #ffffff !important;
    font-weight: 700 !important;
}
`;

css += '\n' + fullCategoryStyles;
fs.writeFileSync('pos/css/app.css', css, 'utf8');

console.log('Successfully updated app.js and app.css for Full 3D Category Rail!');
