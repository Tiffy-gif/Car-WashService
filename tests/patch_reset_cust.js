const fs = require('fs');
let code = fs.readFileSync('pos/js/app.js', 'utf8');

const target = `        document.getElementById('cartLoyaltyRewardBanner')?.setAttribute('style', 'display: none;');
        document.getElementById('cartLoyaltyRewardStatusBadge')?.setAttribute('style', 'display: none;');
        if (plateInp) plateInp.focus();
        else document.getElementById('custSearchInput')?.focus();
    }`;

const replacement = `        document.getElementById('cartLoyaltyRewardBanner')?.setAttribute('style', 'display: none;');
        document.getElementById('cartLoyaltyRewardStatusBadge')?.setAttribute('style', 'display: none;');
        if (plateInp) plateInp.focus();
        else document.getElementById('custSearchInput')?.focus();

        // Reset GOTPOS Customer Card Header
        this.updateGotposCustomerCard();
    }`;

if (code.includes(target)) {
    code = code.replace(target, replacement);
    fs.writeFileSync('pos/js/app.js', code, 'utf8');
    console.log('Successfully updated resetCustomer() in pos/js/app.js');
} else {
    console.error('Target not found in pos/js/app.js');
}
