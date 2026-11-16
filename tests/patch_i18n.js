const fs = require('fs');
let code = fs.readFileSync('pos/js/i18n.js', 'utf8');

const target = `        const custNameEl = document.getElementById('topbarCustomerName');
        if (custNameEl && (!window.PosApp || !window.PosApp.currentCustomer)) {
            custNameEl.textContent = this.t('generalCustomer');
        }
    }`;

const replacement = `        const custNameEl = document.getElementById('topbarCustomerName');
        if (custNameEl && (!window.PosApp || !window.PosApp.currentCustomer)) {
            custNameEl.textContent = this.t('generalCustomer');
        }

        if (window.PosApp && typeof window.PosApp.updateGotposCustomerCard === 'function') {
            window.PosApp.updateGotposCustomerCard();
        }
    }`;

if (code.includes(target)) {
    code = code.replace(target, replacement);
    fs.writeFileSync('pos/js/i18n.js', code, 'utf8');
    console.log('Successfully added updateGotposCustomerCard hook to I18nManager.applyToDOM()');
} else {
    console.error('Target not found in pos/js/i18n.js');
}
