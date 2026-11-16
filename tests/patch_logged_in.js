const fs = require('fs');
let code = fs.readFileSync('pos/js/app.js', 'utf8');

const target = `        this.updatePicturesToggleUI();
        this.renderCatalog();`;

const replacement = `        this.updatePicturesToggleUI();
        this.renderCatalog();
        this.updateGotposCustomerCard();`;

if (code.includes(target)) {
    code = code.replace(target, replacement);
    fs.writeFileSync('pos/js/app.js', code, 'utf8');
    console.log('Added updateGotposCustomerCard() to renderLoggedInState()');
} else {
    console.error('Target not found in renderLoggedInState()');
}
