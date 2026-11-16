const fs = require('fs');
let html = fs.readFileSync('pos/index.html', 'utf8');

// 1. Replace brandArea
const brandTarget = `<div class="brand-area" id="brandArea">\r
            <div class="brand-logo-icon">\r
                <img id="brandLogoImg" src="" alt="Shop Logo" style="display: none; max-width: 100%; max-height: 100%; object-fit: contain;">\r
                <i class="fa-solid fa-car-side" id="brandDefaultIcon" style="color: #2563eb; font-size: 20px;"></i>\r
            </div>\r
            <div class="brand-title">\r
                <h1 id="brandShopNamePrimary">My Car Wash</h1>\r
                <span id="brandShopNameSecondary" style="display: none;"></span>\r
            </div>\r
        </div>`;

const brandReplacement = `<div class="brand-area" id="brandArea">\r
            <div class="brand-logo-icon">\r
                <img id="brandLogoImg" src="" alt="Shop Logo" style="display: none; max-width: 100%; max-height: 100%; object-fit: contain;">\r
                <i class="fa-solid fa-car-side" id="brandDefaultIcon" style="color: #ff762d; font-size: 20px;"></i>\r
            </div>\r
            <div class="brand-title">\r
                <h1 id="brandShopNamePrimary"><span class="gotpos-brand-orange">G</span>OTPOS <span class="gotpos-brand-sub">Car Wash</span></h1>\r
                <span id="brandShopNameSecondary" style="display: none;"></span>\r
            </div>\r
            <!-- GOTPOS Top Date & Time Pills -->\r
            <div class="gotpos-top-pills">\r
                <div class="gotpos-pill-box">\r
                    <i class="fa-regular fa-calendar"></i>\r
                    <span id="gotposLiveDate">Sat, 14 Nov 2026</span>\r
                </div>\r
                <div class="gotpos-pill-box">\r
                    <i class="fa-regular fa-clock"></i>\r
                    <span id="gotposLiveTime">09:59 AM</span>\r
                </div>\r
            </div>\r
        </div>`;

if (html.includes(brandTarget)) {
    html = html.replace(brandTarget, brandReplacement);
    console.log('1. Successfully updated Brand Area with Date & Time Pills.');
} else {
    console.warn('Could not find brandTarget');
}

// 2. Replace Header Actions to include Open Order & Dashboard
const headerTarget = `class="header-actions">\r
            <!-- Network Status Pill (Preserved for compatibility, hidden in favor of single unified sync wrapper) -->`;

const headerReplacement = `class="header-actions">\r
            <!-- GOTPOS Live Open Order & Dashboard -->\r
            <div class="gotpos-open-order-chip">\r
                <span class="dot-live"></span>\r
                <span>Open Order</span>\r
                <i class="fa-solid fa-power-off"></i>\r
            </div>\r
            <button type="button" class="btn-gotpos-dash" onclick="PosApp.switchView('queue')" title="Dashboard">\r
                <i class="fa-solid fa-chart-pie"></i>\r
                <span>Dashboard</span>\r
            </button>\r
            <!-- Network Status Pill (Preserved for compatibility, hidden in favor of single unified sync wrapper) -->`;

if (html.includes(headerTarget)) {
    html = html.replace(headerTarget, headerReplacement);
    console.log('2. Successfully updated Header Actions with Open Order chip and Dashboard button.');
} else {
    console.warn('Could not find headerTarget');
}

// 3. Replace Vehicle Switcher with GOTPOS Toolbar (Filter + Vehicle Switcher + Search Input)
const vTarget = `                    <div class="vehicle-type-switcher">\r
                        <button type="button" class="vehicle-btn" data-vtype="motorbike">\r
                            <i class="fa-solid fa-motorcycle"></i>\r
                            <span class="v-title-km">ម៉ូតូ</span>\r
                            <span class="v-title-en">Motorbike</span>\r
                        </button>\r
                        <button type="button" class="vehicle-btn active" data-vtype="small_car">\r
                            <i class="fa-solid fa-car-side"></i>\r
                            <span class="v-title-km">ឡានតូច</span>\r
                            <span class="v-title-en">Small Car</span>\r
                        </button>\r
                        <button type="button" class="vehicle-btn" data-vtype="suv_pickup">\r
                            <i class="fa-solid fa-truck-pickup"></i>\r
                            <span class="v-title-km">ឡានធំ / SUV</span>\r
                            <span class="v-title-en">SUV / Pickup</span>\r
                        </button>\r
                        <button type="button" class="vehicle-btn" data-vtype="van_truck">\r
                            <i class="fa-solid fa-van-shuttle"></i>\r
                            <span class="v-title-km">ឡានដឹក / វ៉ាន់</span>\r
                            <span class="v-title-en">Van / Truck</span>\r
                        </button>\r
                    </div>`;

const vReplacement = `                    <!-- GOTPOS Catalog Toolbar: Filter + Vehicle Pills + Real-Time Search -->\r
                    <div class="gotpos-catalog-toolbar">\r
                        <div class="gotpos-toolbar-left">\r
                            <button type="button" class="btn-gotpos-filter" title="Filter"><i class="fa-solid fa-sliders"></i> <span>Filter</span></button>\r
                            <div class="vehicle-type-switcher gotpos-vehicle-switcher">\r
                                <button type="button" class="vehicle-btn" data-vtype="motorbike">\r
                                    <i class="fa-solid fa-motorcycle"></i>\r
                                    <span class="v-title-km">ម៉ូតូ</span>\r
                                    <span class="v-title-en">Motorbike</span>\r
                                </button>\r
                                <button type="button" class="vehicle-btn active" data-vtype="small_car">\r
                                    <i class="fa-solid fa-car-side"></i>\r
                                    <span class="v-title-km">ឡានតូច</span>\r
                                    <span class="v-title-en">Small Car</span>\r
                                </button>\r
                                <button type="button" class="vehicle-btn" data-vtype="suv_pickup">\r
                                    <i class="fa-solid fa-truck-pickup"></i>\r
                                    <span class="v-title-km">ឡានធំ / SUV</span>\r
                                    <span class="v-title-en">SUV / Pickup</span>\r
                                </button>\r
                                <button type="button" class="vehicle-btn" data-vtype="van_truck">\r
                                    <i class="fa-solid fa-van-shuttle"></i>\r
                                    <span class="v-title-km">ឡានដឹក / វ៉ាន់</span>\r
                                    <span class="v-title-en">Van / Truck</span>\r
                                </button>\r
                            </div>\r
                        </div>\r
                        <div class="gotpos-search-wrapper">\r
                            <i class="fa-solid fa-magnifying-glass gotpos-search-icon"></i>\r
                            <input type="text" id="posGlobalSearchInput" class="gotpos-search-input" placeholder="Search product..." autocomplete="off">\r
                            <button type="button" class="gotpos-barcode-btn" title="Barcode Scan"><i class="fa-solid fa-barcode"></i></button>\r
                        </div>\r
                    </div>`;

if (html.includes(vTarget)) {
    html = html.replace(vTarget, vReplacement);
    console.log('3. Successfully updated Vehicle Switcher & added GOTPOS Search Toolbar.');
} else {
    console.warn('Could not find vTarget');
}

fs.writeFileSync('pos/index.html', html);
console.log('Done pos/index.html updates!');

