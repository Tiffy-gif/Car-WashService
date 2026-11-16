const fs = require('fs');
let code = fs.readFileSync('pos/js/app.js', 'utf8');

// Fix 1: Update document click listener at line 1552
const targetDocClick = `            document.addEventListener('click', (e) => {
                if (!e.target.closest('.search-box-wrap') && !e.target.closest('.intake-plate-field')) {
                    document.getElementById('custDropdown')?.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                    this.closeSavedCustomersPanel();
                }
            });`;

const replacementDocClick = `            document.addEventListener('click', (e) => {
                if (!e.target.closest('.search-box-wrap') && !e.target.closest('.intake-plate-field') && !e.target.closest('#modalSelectCustomer') && !e.target.closest('#gotposCustomerCard')) {
                    document.getElementById('custDropdown')?.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                    this.closeSavedCustomersPanel();
                }
            });`;

if (code.includes(targetDocClick)) {
    code = code.replace(targetDocClick, replacementDocClick);
    console.log('1. Fixed document click listener to ignore modalSelectCustomer and gotposCustomerCard');
} else {
    console.error('Target targetDocClick not found!');
}

// Fix 2: Stop propagation on custCard click
const targetCardClick = `        const custCard = document.getElementById('gotposCustomerCard');
        if (custCard) {
            custCard.addEventListener('click', (e) => {
                if (e.target.closest('#btnGotposResetCustomer')) {
                    e.stopPropagation();
                    this.resetCustomer();
                    return;
                }
                this.openCustomerSelectModal();
            });
        }`;

const replacementCardClick = `        const custCard = document.getElementById('gotposCustomerCard');
        if (custCard) {
            custCard.addEventListener('click', (e) => {
                e.stopPropagation();
                if (e.target.closest('#btnGotposResetCustomer')) {
                    this.resetCustomer();
                    return;
                }
                this.openCustomerSelectModal();
            });
        }`;

if (code.includes(targetCardClick)) {
    code = code.replace(targetCardClick, replacementCardClick);
    console.log('2. Fixed custCard click to stopPropagation()');
} else {
    console.error('Target targetCardClick not found!');
}

fs.writeFileSync('pos/js/app.js', code, 'utf8');
console.log('Saved pos/js/app.js');
