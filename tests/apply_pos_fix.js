const fs = require('fs');
const path = require('path');

const cssPath = path.join(__dirname, '../pos/css/app.css');
let content = fs.readFileSync(cssPath, 'utf8');

let idx = content.indexOf('/* ============================================================\r\n   CLEAN MODERN MINIMAL RETAIL POS THEME');
if (idx === -1) {
    idx = content.indexOf('/* ============================================================\n   CLEAN MODERN MINIMAL RETAIL POS THEME');
}

if (idx === -1) {
    console.error('Marker not found!');
    process.exit(1);
}

const prefix = content.substring(0, idx);

const newBlock = `/* ============================================================
   CLEAN MODERN MINIMAL RETAIL POS THEME (OTPOS Style - Fixed & Enhanced)
   Pure white surfaces, thin light borders (#e2e8f0, #eef2f6),
   very soft shadows, warm energetic orange accent (#ff762d / #ea580c),
   circular orange plus button (+), clean empty cart illustration,
   perfect 3-column architecture (Categories | Products Grid | Cart Panel)
   ============================================================ */
:root {
  --pos-accent: #ff762d;
  --pos-accent-hover: #e8590c;
  --pos-accent-soft: #fff7ed;
  --pos-accent-glow: rgba(255, 118, 45, 0.28);
  --pos-bg: #f8fafc;
  --pos-surface: #ffffff;
  --pos-border: #e2e8f0;
  --pos-border-subtle: #f1f5f9;
  --pos-text: #0f172a;
  --pos-text-muted: #64748b;
  --pos-text-sub: #94a3b8;
  --pos-shadow-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
  --pos-shadow-sm: 0 2px 6px rgba(15, 23, 42, 0.04);
  --pos-shadow-md: 0 8px 24px -4px rgba(15, 23, 42, 0.07);
  --pos-radius: 12px;
}

body {
  background-color: var(--pos-bg) !important;
  color: var(--pos-text) !important;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
}

/* ------------------------------------------------------------
   1. MAIN POS VIEWPORT & 2-CONTAINER WORKSPACE
   ------------------------------------------------------------ */
.view-section#viewPos {
  height: calc(100vh - 60px) !important;
  max-height: calc(100vh - 60px) !important;
  overflow: hidden !important;
  background-color: var(--pos-bg) !important;
}

.view-section#viewPos.active {
  display: flex !important;
  flex-direction: column !important;
}

/* Bay Status Strip: Sleek & Compact */
.pos-bay-status-strip {
  flex-shrink: 0 !important;
  background: var(--pos-surface) !important;
  border-bottom: 1px solid var(--pos-border) !important;
  padding: 6px 16px !important;
  display: flex !important;
  align-items: center !important;
  gap: 12px !important;
  min-height: 42px !important;
}

.bay-strip-queue-summary {
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  font-size: 0.82rem !important;
  font-weight: 700 !important;
  color: var(--pos-text) !important;
  background: var(--pos-border-subtle) !important;
  padding: 4px 10px !important;
  border-radius: 9999px !important;
  border: 1px solid var(--pos-border) !important;
}

.bay-strip-queue-summary i {
  color: var(--pos-accent) !important;
}

.bay-strip-chips {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  flex-wrap: nowrap !important;
  overflow-x: auto !important;
}

.bay-chip {
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  padding: 3px 10px !important;
  border-radius: 9999px !important;
  font-size: 0.78rem !important;
  font-weight: 600 !important;
  border: 1px solid var(--pos-border) !important;
  background: #ffffff !important;
  white-space: nowrap !important;
}

.bay-chip.free {
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
  color: #166534 !important;
}
.bay-chip.free .bay-chip-dot {
  background: #22c55e !important;
  box-shadow: 0 0 0 2px #bbf7d0 !important;
}

.bay-chip.busy {
  border-color: #fed7aa !important;
  background: #fff7ed !important;
  color: #9a3412 !important;
}
.bay-chip.busy .bay-chip-dot {
  background: #f97316 !important;
  box-shadow: 0 0 0 2px #fed7aa !important;
}

.bay-chip.tech {
  border-color: #fde68a !important;
}

.pos-workspace {
  display: flex !important;
  flex-direction: row !important;
  align-items: stretch !important;
  gap: 14px !important;
  padding: 8px 16px 12px 16px !important;
  flex: 1 1 0% !important;
  min-height: 0 !important;
  height: 100% !important;
  background-color: var(--pos-bg) !important;
  box-sizing: border-box !important;
  overflow: hidden !important;
  width: 100% !important;
  max-width: 100vw !important;
}

/* ------------------------------------------------------------
   2. LEFT/CENTER CATALOG AREA (.pos-catalog-column)
   ------------------------------------------------------------ */
.pos-catalog-column {
  flex: 1 1 0% !important;
  min-width: 0 !important;
  display: flex !important;
  flex-direction: column !important;
  height: 100% !important;
  min-height: 0 !important;
  overflow: hidden !important;
  background: transparent !important;
  border: none !important;
  gap: 10px !important;
  box-sizing: border-box !important;
}

/* Customer Intake Card (Mandatory Plate, Name, Phone, Actions) */
.pos-customer-intake-card {
  background: var(--pos-surface) !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: var(--pos-radius) !important;
  box-shadow: var(--pos-shadow-xs) !important;
  padding: 10px 14px !important;
  margin-bottom: 0 !important;
  display: flex !important;
  flex-direction: column !important;
  gap: 8px !important;
  flex-shrink: 0 !important;
  width: 100% !important;
  box-sizing: border-box !important;
  position: relative !important;
}

.intake-inputs-row {
  display: flex !important;
  align-items: flex-end !important;
  gap: 12px !important;
  width: 100% !important;
  flex-wrap: nowrap !important;
}

.intake-field {
  display: flex !important;
  flex-direction: column !important;
  gap: 4px !important;
}

.intake-plate-field {
  flex: 1.1 !important;
  min-width: 160px !important;
}

.intake-name-field {
  flex: 1.4 !important;
  min-width: 170px !important;
}

.intake-phone-field {
  flex: 1 !important;
  min-width: 130px !important;
}

.intake-label {
  font-size: 0.78rem !important;
  font-weight: 700 !important;
  color: var(--pos-text-muted) !important;
  display: flex !important;
  align-items: center !important;
  gap: 5px !important;
}

.intake-label i {
  color: var(--pos-accent) !important;
  font-size: 0.85rem !important;
}

.required-star {
  color: #ef4444 !important;
  font-weight: 800 !important;
}

.intake-input-wrap {
  position: relative !important;
  display: flex !important;
  align-items: center !important;
  width: 100% !important;
}

.intake-input {
  width: 100% !important;
  height: 38px !important;
  background: #f8fafc !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: 8px !important;
  padding: 0 10px !important;
  font-size: 0.88rem !important;
  font-weight: 600 !important;
  color: var(--pos-text) !important;
  outline: none !important;
  box-sizing: border-box !important;
  transition: all 0.15s ease !important;
}

.intake-input:focus {
  background: #ffffff !important;
  border-color: var(--pos-accent) !important;
  box-shadow: 0 0 0 3px var(--pos-accent-soft) !important;
}

.intake-input.plate-input {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
  font-weight: 800 !important;
  letter-spacing: 0.06em !important;
  color: #0f172a !important;
  text-transform: uppercase !important;
  padding-right: 34px !important;
}

.btn-intake-icon {
  position: absolute !important;
  right: 6px !important;
  background: transparent !important;
  border: none !important;
  color: var(--pos-text-sub) !important;
  cursor: pointer !important;
  padding: 4px !important;
  font-size: 14px !important;
}
.btn-intake-icon:hover {
  color: var(--pos-accent) !important;
}

.intake-actions {
  display: flex !important;
  align-items: center !important;
  gap: 6px !important;
  flex-shrink: 0 !important;
}

.btn-intake-action {
  height: 38px !important;
  padding: 0 14px !important;
  border-radius: 8px !important;
  font-size: 0.82rem !important;
  font-weight: 700 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  cursor: pointer !important;
  white-space: nowrap !important;
  transition: all 0.15s ease !important;
}

#btnResetCustomer {
  background: #f8fafc !important;
  border: 1px solid var(--pos-border) !important;
  color: var(--pos-text-muted) !important;
}
#btnResetCustomer:hover {
  background: #f1f5f9 !important;
  color: #0f172a !important;
}

.btn-intake-action#btnOpenNewCustomerModal,
#btnOpenNewCustomerModal {
  background: var(--pos-accent) !important;
  border: 1px solid var(--pos-accent) !important;
  color: #ffffff !important;
  box-shadow: 0 2px 8px var(--pos-accent-glow) !important;
}
.btn-intake-action#btnOpenNewCustomerModal:hover,
#btnOpenNewCustomerModal:hover {
  background: var(--pos-accent-hover) !important;
  border-color: var(--pos-accent-hover) !important;
}

/* Vehicle Switcher Bar (Segmented Control Style) */
.vehicle-type-switcher {
  display: flex !important;
  gap: 6px !important;
  width: 100% !important;
  flex-shrink: 0 !important;
  background: #f1f5f9 !important;
  padding: 4px !important;
  border-radius: 12px !important;
  border: 1px solid var(--pos-border) !important;
  box-sizing: border-box !important;
}

.vehicle-type-switcher .vehicle-btn {
  flex: 1 1 0 !important;
  min-width: 0 !important;
  height: 38px !important;
  border-radius: 8px !important;
  background: transparent !important;
  border: none !important;
  color: var(--pos-text-muted) !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 7px !important;
  font-size: 0.84rem !important;
  font-weight: 700 !important;
  cursor: pointer !important;
  transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1) !important;
  white-space: nowrap !important;
  box-shadow: none !important;
}

.vehicle-type-switcher .vehicle-btn:hover:not(.active) {
  background: rgba(255, 255, 255, 0.6) !important;
  color: var(--pos-text) !important;
}

.vehicle-type-switcher .vehicle-btn.active {
  background: #ffffff !important;
  border: none !important;
  color: var(--pos-accent) !important;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
}

.vehicle-type-switcher .vehicle-btn.active i,
.vehicle-type-switcher .vehicle-btn.active .v-title-km,
.vehicle-type-switcher .vehicle-btn.active .v-title-en {
  color: var(--pos-accent) !important;
}

html[lang="km"] .vehicle-btn .v-title-km {
  display: inline !important;
  font-size: 0.84rem !important;
  font-weight: 700 !important;
}
html[lang="km"] .vehicle-btn .v-title-en {
  display: none !important;
}
html[lang="en"] .vehicle-btn .v-title-en {
  display: inline !important;
  font-size: 0.84rem !important;
  font-weight: 700 !important;
}
html[lang="en"] .vehicle-btn .v-title-km {
  display: none !important;
}

/* ------------------------------------------------------------
   3. CATALOG BODY: CATEGORIES ON LEFT, PRODUCTS GRID ON RIGHT
   ------------------------------------------------------------ */
.pos-catalog-body {
  display: flex !important;
  flex-direction: row !important;
  gap: 12px !important;
  flex: 1 1 0% !important;
  min-height: 0 !important;
  overflow: hidden !important;
  width: 100% !important;
}

/* Category Vertical Rail (Left of Catalog) */
.pos-category-rail,
.category-vertical-sidebar,
.pos-workspace .category-vertical-sidebar,
.pos-workspace.layout-cart-right .category-vertical-sidebar,
.pos-workspace.layout-cart-left .category-vertical-sidebar {
  order: -1 !important;
  width: 96px !important;
  min-width: 96px !important;
  max-width: 96px !important;
  flex-shrink: 0 !important;
  background: var(--pos-surface) !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: var(--pos-radius) !important;
  box-shadow: var(--pos-shadow-xs) !important;
  padding: 8px 6px !important;
  display: flex !important;
  flex-direction: column !important;
  height: 100% !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}

.category-vertical-sidebar .category-list-scroll {
  display: flex !important;
  flex-direction: column !important;
  gap: 6px !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  height: 100% !important;
  padding-right: 2px !important;
}

.category-pill {
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  justify-content: center !important;
  width: 100% !important;
  min-height: 60px !important;
  padding: 6px 4px !important;
  border-radius: 10px !important;
  background: var(--pos-surface) !important;
  border: 1px solid var(--pos-border-subtle) !important;
  box-shadow: none !important;
  gap: 4px !important;
  cursor: pointer !important;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
  text-align: center !important;
  box-sizing: border-box !important;
}

.category-pill i {
  font-size: 16px !important;
  color: var(--pos-text-muted) !important;
  transition: color 0.2s ease, transform 0.2s ease !important;
}

.category-pill span {
  font-size: 10px !important;
  font-weight: 700 !important;
  color: #475569 !important;
  line-height: 1.2 !important;
  display: block !important;
  max-width: 82px !important;
  white-space: normal !important;
  word-break: normal !important;
  overflow-wrap: normal !important;
}

.category-pill:hover:not(.active) {
  background: #f8fafc !important;
  border-color: #cbd5e1 !important;
  transform: translateY(-1px) !important;
}

.category-vertical-sidebar .category-pill.active,
.category-pill.active {
  background: var(--pos-accent) !important;
  border: 1px solid var(--pos-accent) !important;
  box-shadow: 0 4px 12px var(--pos-accent-glow) !important;
}

.category-vertical-sidebar .category-pill.active i,
.category-vertical-sidebar .category-pill.active span,
.category-pill.active i,
.category-pill.active span {
  color: #ffffff !important;
}

.category-pill.dimmed {
  opacity: 0.55 !important;
}

/* Catalog Grid & Wrap (Center Column) */
.catalog-grid-wrap,
.pos-workspace .catalog-grid-wrap,
.pos-workspace.layout-cart-right .catalog-grid-wrap {
  order: 1 !important;
  flex: 1 1 0% !important;
  min-width: 0 !important;
  height: 100% !important;
  overflow-y: auto !important;
  padding: 2px !important;
  box-sizing: border-box !important;
}

.catalog-grid,
.catalog-grid.picture-grid {
  display: grid !important;
  grid-template-columns: repeat(auto-fill, minmax(185px, 1fr)) !important;
  gap: 12px !important;
}

/* Clutter tags on item cards: Clean badge layout */
.card-tag-type {
  position: absolute !important;
  top: 16px !important;
  left: 16px !important;
  padding: 2px 7px !important;
  font-size: 9.5px !important;
  font-weight: 700 !important;
  border-radius: 6px !important;
  background: rgba(255, 255, 255, 0.94) !important;
  backdrop-filter: blur(4px) !important;
  border: 1px solid #e2e8f0 !important;
  color: #334155 !important;
  z-index: 2 !important;
  letter-spacing: 0.02em !important;
}

.card-tag-type.wash {
  color: #0284c7 !important;
  border-color: #bae6fd !important;
}

.card-tag-type.mechanical {
  color: #d97706 !important;
  border-color: #fde68a !important;
}

.card-tag-status,
.card-tag-status.duration {
  position: absolute !important;
  top: 16px !important;
  right: 16px !important;
  padding: 2px 7px !important;
  font-size: 9.5px !important;
  font-weight: 600 !important;
  border-radius: 6px !important;
  background: rgba(255, 255, 255, 0.94) !important;
  border: 1px solid #e2e8f0 !important;
  color: #475569 !important;
  z-index: 2 !important;
}

.service-wait-time-badge {
  display: none !important;
}

/* Product & Service Cards */
.pos-item-card,
.pos-item-card.card-picture-mode {
  background: var(--pos-surface) !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: 14px !important;
  padding: 10px !important;
  display: flex !important;
  flex-direction: column !important;
  position: relative !important;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03) !important;
  cursor: pointer !important;
  transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1) !important;
  min-height: 245px !important;
  height: 100% !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}

.pos-item-card:hover,
.pos-item-card.card-picture-mode:hover {
  transform: translateY(-3px) !important;
  border-color: #cbd5e1 !important;
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.07) !important;
}

.item-card-media-box,
.pos-item-card.card-picture-mode .item-card-media-box {
  background: #f8fafc !important;
  border-radius: 10px !important;
  height: 145px !important;
  width: 100% !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  overflow: hidden !important;
  margin-bottom: 8px !important;
  position: relative !important;
  border: 1px solid #f1f5f9 !important;
}

.item-card-img,
.pos-item-card.card-picture-mode .item-card-img {
  width: 100% !important;
  height: 100% !important;
  object-fit: contain !important;
  padding: 6px !important;
  transition: transform 0.25s ease !important;
}

.pos-item-card:hover .item-card-img,
.pos-item-card.card-picture-mode:hover .item-card-img {
  transform: scale(1.06) !important;
}

.item-card-fallback-icon,
.pos-item-card.card-picture-mode .item-card-fallback-icon {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 56px !important;
  height: 56px !important;
  border-radius: 50% !important;
  background: var(--pos-accent-soft) !important;
  color: var(--pos-accent) !important;
  font-size: 24px !important;
  box-shadow: 0 2px 8px var(--pos-accent-glow) !important;
  transition: transform 0.25s ease !important;
}

.pos-item-card:hover .item-card-fallback-icon,
.pos-item-card.card-picture-mode:hover .item-card-fallback-icon {
  transform: scale(1.1) !important;
}

.item-card-body,
.pos-item-card.card-picture-mode .item-card-body {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 !important;
  justify-content: space-between !important;
  gap: 6px !important;
  padding: 2px 2px !important;
}

.item-titles {
  display: flex !important;
  flex-direction: column !important;
  gap: 2px !important;
}

.item-name-km {
  font-size: 13.5px !important;
  font-weight: 700 !important;
  color: var(--pos-text) !important;
  line-height: 1.3 !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  white-space: nowrap !important;
}

.item-name-en {
  font-size: 11.5px !important;
  font-weight: 500 !important;
  color: var(--pos-text-sub) !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  white-space: nowrap !important;
}

.item-meta-row,
.pos-item-card.card-picture-mode .item-meta-row {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  margin-top: auto !important;
  padding-top: 6px !important;
  border-top: 1px dashed #f1f5f9 !important;
}

.item-price-block {
  display: flex !important;
  flex-direction: column !important;
}

.item-price-usd {
  font-size: 16.5px !important;
  font-weight: 800 !important;
  color: var(--pos-text) !important;
  letter-spacing: -0.01em !important;
}

.item-price-khr {
  font-size: 11px !important;
  color: #94a3b8 !important;
  font-weight: 500 !important;
}

/* Circular Orange Action Plus Button (+) */
.btn-card-add,
.btn-add-circle {
  width: 36px !important;
  height: 36px !important;
  min-width: 36px !important;
  border-radius: 50% !important;
  background: var(--pos-accent) !important;
  color: transparent !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border: none !important;
  box-shadow: 0 4px 10px var(--pos-accent-glow) !important;
  cursor: pointer !important;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
  padding: 0 !important;
  position: relative !important;
  flex-shrink: 0 !important;
  overflow: hidden !important;
}

.btn-card-add::before,
.btn-add-circle::before {
  content: '+' !important;
  color: #ffffff !important;
  font-size: 21px !important;
  font-weight: 700 !important;
  line-height: 1 !important;
  position: absolute !important;
}

.btn-card-add:hover,
.btn-add-circle:hover {
  background: var(--pos-accent-hover) !important;
  transform: scale(1.12) !important;
  box-shadow: 0 6px 14px rgba(255, 90, 31, 0.45) !important;
}

/* Bottom Action Dock Bar in Catalog Column */
.pos-bottom-dock {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  background: var(--pos-surface) !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: var(--pos-radius) !important;
  box-shadow: var(--pos-shadow-xs) !important;
  padding: 8px 14px !important;
  flex-shrink: 0 !important;
  width: 100% !important;
  box-sizing: border-box !important;
}

.dock-hint-badge {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  font-size: 0.82rem !important;
  font-weight: 600 !important;
  color: var(--pos-text-muted) !important;
}

.dock-hint-badge i,
#btnQuickService i {
  color: var(--pos-accent) !important;
}

.dock-actions-wrap {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
}

.btn-dock-action {
  height: 34px !important;
  padding: 0 12px !important;
  border-radius: 8px !important;
  border: 1px solid var(--pos-border) !important;
  background: #f8fafc !important;
  color: var(--pos-text) !important;
  font-size: 0.8rem !important;
  font-weight: 700 !important;
  cursor: pointer !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  transition: all 0.15s ease !important;
}
.btn-dock-action:hover {
  background: #f1f5f9 !important;
  border-color: #cbd5e1 !important;
}

/* ------------------------------------------------------------
   4. RIGHT COLUMN: CART PANEL (.pos-cart-column)
   ------------------------------------------------------------ */
.pos-cart-column {
  width: 410px !important;
  min-width: 410px !important;
  max-width: 430px !important;
  flex-shrink: 0 !important;
  background: var(--pos-surface) !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: 16px !important;
  box-shadow: var(--pos-shadow-sm) !important;
  padding: 14px 16px !important;
  display: flex !important;
  flex-direction: column !important;
  height: 100% !important;
  overflow: hidden !important;
  box-sizing: border-box !important;
}

/* Cart Header */
.cart-header {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  padding-bottom: 10px !important;
  border-bottom: 1px solid var(--pos-border-subtle) !important;
  margin-bottom: 8px !important;
  flex-shrink: 0 !important;
}

.cart-header-title {
  font-size: 1.02rem !important;
  font-weight: 800 !important;
  color: var(--pos-text) !important;
  display: flex !important;
  align-items: center !important;
  gap: 6px !important;
}

.cart-items-count {
  background: var(--pos-accent-soft) !important;
  color: var(--pos-accent) !important;
  font-size: 0.76rem !important;
  font-weight: 800 !important;
  padding: 2px 8px !important;
  border-radius: 9999px !important;
  border: 1px solid rgba(255, 118, 45, 0.2) !important;
}

.btn-clear-cart-text {
  background: transparent !important;
  border: none !important;
  color: #ef4444 !important;
  font-size: 0.82rem !important;
  font-weight: 600 !important;
  cursor: pointer !important;
  padding: 4px 8px !important;
  border-radius: 6px !important;
  transition: all 0.15s ease !important;
}
.btn-clear-cart-text:hover {
  background: #fee2e2 !important;
}

/* Cart Scroll Area */
.cart-items-list {
  flex: 1 1 0% !important;
  min-height: 120px !important;
  overflow-y: auto !important;
  padding-right: 2px !important;
  display: flex !important;
  flex-direction: column !important;
  gap: 8px !important;
}

/* Empty Cart State with SVG Illustration */
.cart-empty-state {
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  justify-content: center !important;
  height: 100% !important;
  min-height: 200px !important;
  text-align: center !important;
  padding: 24px 12px !important;
}

.cart-empty-icon-wrap {
  width: 125px !important;
  height: 125px !important;
  background: url('../icons/no-order.svg') center center no-repeat !important;
  background-size: contain !important;
  border-radius: 0 !important;
  margin-bottom: 12px !important;
  opacity: 0.88 !important;
}

.cart-empty-icon-wrap i {
  display: none !important;
}

.cart-empty-title {
  font-size: 1.05rem !important;
  font-weight: 700 !important;
  color: var(--pos-text) !important;
  margin: 0 0 4px 0 !important;
}

.cart-empty-subtitle {
  font-size: 0.82rem !important;
  color: var(--pos-text-sub) !important;
  margin: 0 !important;
}

/* Cart Item Rows */
.cart-item-row {
  display: flex !important;
  flex-direction: column !important;
  background: #ffffff !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: 12px !important;
  padding: 10px 12px !important;
  gap: 8px !important;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02) !important;
  transition: all 0.15s ease !important;
}

.cart-item-row:hover {
  border-color: #cbd5e1 !important;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.04) !important;
}

.cart-item-main {
  display: flex !important;
  align-items: flex-start !important;
  justify-content: space-between !important;
  gap: 8px !important;
}

.cart-item-info {
  display: flex !important;
  flex-direction: column !important;
  gap: 2px !important;
  flex: 1 !important;
}

.cart-item-title {
  font-size: 0.9rem !important;
  font-weight: 700 !important;
  color: var(--pos-text) !important;
  line-height: 1.25 !important;
}

.cart-item-sub {
  font-size: 0.76rem !important;
  color: var(--pos-text-muted) !important;
}

.cart-item-price {
  font-size: 0.95rem !important;
  font-weight: 800 !important;
  color: var(--pos-text) !important;
  text-align: right !important;
}

.cart-item-khr {
  font-size: 0.72rem !important;
  color: var(--pos-text-sub) !important;
  text-align: right !important;
}

.cart-item-actions {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  padding-top: 6px !important;
  border-top: 1px dashed var(--pos-border) !important;
}

.qty-control {
  display: inline-flex !important;
  align-items: center !important;
  background: #f8fafc !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: 8px !important;
  overflow: hidden !important;
}

.qty-btn {
  width: 28px !important;
  height: 28px !important;
  border: none !important;
  background: transparent !important;
  color: var(--pos-text) !important;
  font-weight: 800 !important;
  font-size: 15px !important;
  cursor: pointer !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  transition: background 0.15s ease !important;
}
.qty-btn:hover {
  background: #e2e8f0 !important;
}

.qty-val {
  min-width: 30px !important;
  text-align: center !important;
  font-size: 0.86rem !important;
  font-weight: 800 !important;
  color: var(--pos-text) !important;
}

.btn-remove-item {
  background: transparent !important;
  border: none !important;
  color: #ef4444 !important;
  font-size: 0.78rem !important;
  font-weight: 600 !important;
  cursor: pointer !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 5px !important;
}
.btn-remove-item:hover {
  text-decoration: underline !important;
}

/* Cart Footer & Summary */
.cart-footer {
  display: flex !important;
  flex-direction: column !important;
  gap: 8px !important;
  padding-top: 8px !important;
  border-top: 1px solid var(--pos-border-subtle) !important;
  flex-shrink: 0 !important;
}

.summary-line {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  font-size: 0.86rem !important;
  color: var(--pos-text-muted) !important;
}

.summary-line.total-line,
.cart-grand-total-card {
  background: #f8fafc !important;
  border: 1px solid var(--pos-border) !important;
  border-radius: 12px !important;
  padding: 12px 14px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
}

.total-title {
  font-size: 0.95rem !important;
  font-weight: 800 !important;
  color: var(--pos-text) !important;
}

.total-subtitle {
  font-size: 0.72rem !important;
  color: var(--pos-text-sub) !important;
  display: block !important;
}

.total-amount-box {
  text-align: right !important;
}

.cart-grand-total-card .total-usd,
.total-usd {
  font-size: 1.6rem !important;
  font-weight: 900 !important;
  color: #0f172a !important;
  letter-spacing: -0.02em !important;
  line-height: 1.1 !important;
}

.total-khr {
  font-size: 0.76rem !important;
  color: var(--pos-text-sub) !important;
  font-weight: 600 !important;
}

/* Primary Checkout Button: High Touch Orange */
.btn-checkout-primary {
  width: 100% !important;
  height: 50px !important;
  border-radius: 12px !important;
  border: none !important;
  font-size: 1.05rem !important;
  font-weight: 800 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 8px !important;
  cursor: pointer !important;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
}

.btn-checkout-primary:not(:disabled),
.btn-checkout-primary.is-active {
  background: var(--pos-accent) !important;
  color: #ffffff !important;
  box-shadow: 0 4px 14px var(--pos-accent-glow) !important;
}

.btn-checkout-primary:not(:disabled):hover {
  background: var(--pos-accent-hover) !important;
  transform: translateY(-1px) !important;
  box-shadow: 0 6px 18px rgba(255, 90, 31, 0.45) !important;
}

.btn-checkout-primary:disabled,
.btn-checkout-primary.is-disabled {
  background: #f1f5f9 !important;
  color: #94a3b8 !important;
  border: 1px solid var(--pos-border) !important;
  cursor: not-allowed !important;
  box-shadow: none !important;
}

.btn-checkout-secondary {
  display: none !important;
}

/* Action Pills Row */
.cart-action-pills-row {
  display: flex !important;
  align-items: center !important;
  gap: 6px !important;
  width: 100% !important;
}

.cart-action-pills-row .btn-cart-pill {
  flex: 1 !important;
  height: 36px !important;
  border-radius: 8px !important;
  font-size: 0.8rem !important;
  font-weight: 700 !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 6px !important;
  cursor: pointer !important;
  border: 1px solid transparent !important;
  transition: all 0.15s ease !important;
}

.btn-cart-pill.pill-clear {
  background: #fef2f2 !important;
  color: #dc2626 !important;
  border-color: #fecaca !important;
}
.btn-cart-pill.pill-clear:hover:not(:disabled) {
  background: #fee2e2 !important;
}

.btn-cart-pill.pill-saved {
  background: #f0fdf4 !important;
  color: #16a34a !important;
  border-color: #bbf7d0 !important;
}
.btn-cart-pill.pill-saved:hover:not(:disabled) {
  background: #dcfce7 !important;
}

.btn-cart-pill.pill-discount {
  background: #faf5ff !important;
  color: #9333ea !important;
  border-color: #e9d5ff !important;
}
.btn-cart-pill.pill-discount:hover:not(:disabled) {
  background: #f3e8ff !important;
}

.checkout-helper-text {
  font-size: 0.78rem !important;
  color: var(--pos-text-sub) !important;
  text-align: center !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 5px !important;
  padding-top: 2px !important;
}

/* ------------------------------------------------------------
   5. DARK THEME OVERRIDES
   ------------------------------------------------------------ */
[data-theme="dark"] {
  --pos-bg: #0b1120;
  --pos-surface: #111c30;
  --pos-border: #1f2c44;
  --pos-border-subtle: #17233a;
  --pos-text: #f8fafc;
  --pos-text-muted: #94a3b8;
  --pos-text-sub: #64748b;
  --pos-accent-soft: #27170e;
  --pos-shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.2);
}

[data-theme="dark"] body {
  background-color: var(--pos-bg) !important;
  color: var(--pos-text) !important;
}

[data-theme="dark"] .pos-bay-status-strip,
[data-theme="dark"] .pos-customer-intake-card,
[data-theme="dark"] .category-vertical-sidebar,
[data-theme="dark"] .pos-bottom-dock,
[data-theme="dark"] .pos-cart-column,
[data-theme="dark"] .pos-item-card {
  background: var(--pos-surface) !important;
  border-color: var(--pos-border) !important;
}

[data-theme="dark"] .vehicle-type-switcher {
  background: #0d1525 !important;
  border-color: var(--pos-border) !important;
}

[data-theme="dark"] .vehicle-type-switcher .vehicle-btn.active {
  background: #17233a !important;
  color: var(--pos-accent) !important;
}

[data-theme="dark"] .intake-input,
[data-theme="dark"] .category-pill:not(.active),
[data-theme="dark"] .item-card-media-box,
[data-theme="dark"] .cart-item-row,
[data-theme="dark"] .summary-line.total-line,
[data-theme="dark"] .cart-grand-total-card,
[data-theme="dark"] #btnResetCustomer,
[data-theme="dark"] .btn-dock-action {
  background: #0d1525 !important;
  border-color: var(--pos-border) !important;
  color: var(--pos-text) !important;
}

[data-theme="dark"] .qty-control {
  background: #0d1525 !important;
  border-color: var(--pos-border) !important;
}

[data-theme="dark"] .qty-btn {
  color: var(--pos-text) !important;
}

[data-theme="dark"] .qty-btn:hover {
  background: #17233a !important;
}

[data-theme="dark"] .qty-val {
  color: var(--pos-text) !important;
}

[data-theme="dark"] .intake-input.plate-input {
  color: #38bdf8 !important;
}

[data-theme="dark"] .category-pill span,
[data-theme="dark"] .vehicle-btn span,
[data-theme="dark"] .item-name-en {
  color: var(--pos-text-muted) !important;
}

[data-theme="dark"] .cart-grand-total-card .total-usd,
[data-theme="dark"] .total-usd {
  color: #ffffff !important;
}

[data-theme="dark"] .btn-checkout-primary:not(:disabled),
[data-theme="dark"] .btn-checkout-primary.is-active {
  background: var(--pos-accent) !important;
  color: #ffffff !important;
  box-shadow: 0 4px 14px var(--pos-accent-glow) !important;
}

[data-theme="dark"] .btn-checkout-primary:disabled,
[data-theme="dark"] .btn-checkout-primary.is-disabled {
  background: #1e293b !important;
  color: #475569 !important;
  border-color: #334155 !important;
}

[data-theme="dark"] .btn-cart-pill.pill-clear {
  background: #450a0a !important;
  color: #f87171 !important;
  border-color: #7f1d1d !important;
}

[data-theme="dark"] .btn-cart-pill.pill-saved {
  background: #052e16 !important;
  color: #4ade80 !important;
  border-color: #14532d !important;
}

[data-theme="dark"] .btn-cart-pill.pill-discount {
  background: #3b0764 !important;
  color: #c084fc !important;
  border-color: #581c87 !important;
}

[data-theme="dark"] .cart-wait-time-row,
[data-theme="dark"] #cartWaitTimeRow {
  background: #17233a !important;
  border-color: #1f2c44 !important;
  color: #fde047 !important;
}

@media (max-width: 1320px) {
  .catalog-grid,
  .catalog-grid.picture-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
  }
}
`;

fs.writeFileSync(cssPath, prefix + newBlock, 'utf8');
console.log('Successfully updated pos/css/app.css!');
