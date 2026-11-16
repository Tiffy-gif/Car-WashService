const fs = require('fs');
let css = fs.readFileSync('pos/css/app.css', 'utf8');

const newStyles = `
/* ========================================================
   GOTPOS Customer Selector Modal & Card Actions
   ======================================================== */
.gotpos-customer-card {
  cursor: pointer !important;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
  position: relative !important;
}

.gotpos-customer-card:hover {
  border-color: #ff5b1a !important;
  box-shadow: 0 4px 14px rgba(255, 91, 26, 0.12) !important;
}

.gotpos-cust-card-actions {
  display: flex !important;
  align-items: center !important;
  gap: 4px !important;
}

.btn-gotpos-cust-edit,
.btn-gotpos-cust-reset {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 28px !important;
  height: 28px !important;
  border-radius: 6px !important;
  border: none !important;
  background: transparent !important;
  cursor: pointer !important;
  transition: all 0.15s ease !important;
}

.btn-gotpos-cust-edit {
  color: var(--gotpos-text-subtle, #64748b) !important;
}

.btn-gotpos-cust-edit:hover {
  background: rgba(255, 91, 26, 0.1) !important;
  color: #ff5b1a !important;
}

.btn-gotpos-cust-reset {
  color: #94a3b8 !important;
  font-size: 13px !important;
}

.btn-gotpos-cust-reset:hover {
  background: rgba(239, 68, 68, 0.12) !important;
  color: #ef4444 !important;
}

/* Modal Content */
.modal-customer-select-content {
  max-width: 660px !important;
  width: 95% !important;
  border-radius: 16px !important;
  overflow: hidden !important;
  display: flex !important;
  flex-direction: column !important;
  max-height: 85vh !important;
}

.select-cust-top-bar {
  padding: 1rem 1.25rem 0.65rem 1.25rem !important;
  display: flex !important;
  gap: 0.75rem !important;
  align-items: center !important;
  justify-content: space-between !important;
  background: var(--bg-surface, #ffffff) !important;
  border-bottom: 1px solid var(--border, #e2e8f0) !important;
}

.select-cust-search-wrap {
  flex: 1 !important;
  position: relative !important;
  display: flex !important;
  align-items: center !important;
  background: #f1f5f9 !important;
  border-radius: 10px !important;
  padding: 0 0.85rem !important;
  border: 1.5px solid transparent !important;
  transition: all 0.15s ease !important;
}

.select-cust-search-wrap:focus-within {
  border-color: #ff5b1a !important;
  background: #ffffff !important;
  box-shadow: 0 0 0 3px rgba(255, 91, 26, 0.1) !important;
}

.select-cust-search-icon {
  color: #94a3b8 !important;
  font-size: 14px !important;
  margin-right: 8px !important;
}

.select-cust-search-input {
  width: 100% !important;
  border: none !important;
  outline: none !important;
  background: transparent !important;
  padding: 0.65rem 0 !important;
  font-size: 0.95rem !important;
  color: var(--text-main, #0f172a) !important;
}

.btn-clear-search {
  border: none !important;
  background: transparent !important;
  color: #94a3b8 !important;
  cursor: pointer !important;
  padding: 4px !important;
}

.btn-clear-search:hover {
  color: #ef4444 !important;
}

.select-cust-actions-row {
  display: flex !important;
  align-items: center !important;
  gap: 0.5rem !important;
}

.btn-walkin-quick,
.btn-add-cust-quick {
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  padding: 0.55rem 0.9rem !important;
  border-radius: 8px !important;
  font-size: 0.88rem !important;
  font-weight: 600 !important;
  white-space: nowrap !important;
  cursor: pointer !important;
  transition: all 0.15s ease !important;
}

.btn-walkin-quick {
  border: 1.5px solid #cbd5e1 !important;
  background: #ffffff !important;
  color: #475569 !important;
}

.btn-walkin-quick:hover {
  border-color: #ff5b1a !important;
  color: #ff5b1a !important;
  background: rgba(255, 91, 26, 0.05) !important;
}

.btn-add-cust-quick {
  background: #ff5b1a !important;
  color: #ffffff !important;
  border: 1.5px solid #ff5b1a !important;
}

.btn-add-cust-quick:hover {
  background: #e04b10 !important;
  border-color: #e04b10 !important;
}

/* Tabs */
.select-cust-tabs-row {
  display: flex !important;
  align-items: center !important;
  gap: 0.4rem !important;
  padding: 0.6rem 1.25rem !important;
  background: #f8fafc !important;
  border-bottom: 1px solid var(--border, #e2e8f0) !important;
  overflow-x: auto !important;
}

.cust-tab-btn {
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  padding: 0.35rem 0.75rem !important;
  border-radius: 20px !important;
  border: 1px solid transparent !important;
  background: transparent !important;
  color: #64748b !important;
  font-size: 0.82rem !important;
  font-weight: 600 !important;
  cursor: pointer !important;
  transition: all 0.15s ease !important;
  white-space: nowrap !important;
}

.cust-tab-btn:hover {
  background: #e2e8f0 !important;
  color: #1e293b !important;
}

.cust-tab-btn.active {
  background: #ff5b1a !important;
  color: #ffffff !important;
}

.cust-tab-btn.active .text-amber,
.cust-tab-btn.active .text-slate,
.cust-tab-btn.active .fa-solid {
  color: #ffffff !important;
}

.cust-tab-count {
  font-size: 0.75rem !important;
  padding: 1px 6px !important;
  border-radius: 10px !important;
  background: rgba(0, 0, 0, 0.08) !important;
}

.cust-tab-btn.active .cust-tab-count {
  background: rgba(255, 255, 255, 0.25) !important;
  color: #ffffff !important;
}

/* List Wrap inside Modal */
.select-cust-list-wrap {
  flex: 1 !important;
  max-height: 400px !important;
  min-height: 240px !important;
  overflow-y: auto !important;
  padding: 0.5rem 1.25rem 1rem 1.25rem !important;
  background: var(--bg-surface, #ffffff) !important;
}

.select-cust-list-wrap .saved-cust-card {
  height: auto !important;
  min-height: 56px !important;
  padding: 0.65rem 0.75rem !important;
  margin-bottom: 6px !important;
  border: 1px solid #e2e8f0 !important;
  border-radius: 10px !important;
  background: #ffffff !important;
  transition: all 0.15s ease !important;
}

.select-cust-list-wrap .saved-cust-card:hover {
  background: #f8fafc !important;
  border-color: #ff5b1a !important;
  transform: translateY(-1px) !important;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.05) !important;
}

.select-cust-list-wrap .saved-cust-avatar {
  width: 38px !important;
  height: 38px !important;
  min-width: 38px !important;
  min-height: 38px !important;
  font-size: 0.88rem !important;
}

/* Dark Theme Overrides for Customer Modal */
[data-theme="dark"] .select-cust-top-bar {
  background: #141d33 !important;
  border-color: #334155 !important;
}

[data-theme="dark"] .select-cust-search-wrap {
  background: #1e293b !important;
}

[data-theme="dark"] .select-cust-search-wrap:focus-within {
  background: #0f172a !important;
}

[data-theme="dark"] .select-cust-search-input {
  color: #f8fafc !important;
}

[data-theme="dark"] .btn-walkin-quick {
  background: #1e293b !important;
  border-color: #334155 !important;
  color: #cbd5e1 !important;
}

[data-theme="dark"] .select-cust-tabs-row {
  background: #0f172a !important;
  border-color: #334155 !important;
}

[data-theme="dark"] .cust-tab-btn {
  color: #94a3b8 !important;
}

[data-theme="dark"] .cust-tab-btn:hover {
  background: #1e293b !important;
  color: #ffffff !important;
}

[data-theme="dark"] .select-cust-list-wrap {
  background: #141d33 !important;
}

[data-theme="dark"] .select-cust-list-wrap .saved-cust-card {
  background: #1c2541 !important;
  border-color: #334155 !important;
}

[data-theme="dark"] .select-cust-list-wrap .saved-cust-card:hover {
  background: #243052 !important;
  border-color: #ff5b1a !important;
}
`;

css += '\n' + newStyles;
fs.writeFileSync('pos/css/app.css', css, 'utf8');
console.log('Appended customer modal styles to pos/css/app.css');
