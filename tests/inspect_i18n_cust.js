const fs = require('fs');
const i18n = fs.readFileSync('pos/js/i18n.js', 'utf8');
const lines = i18n.split('\n');
lines.forEach(l => {
  if (l.includes('customer') || l.includes('Customer') || l.includes('savedCust')) {
    console.log(l.trim());
  }
});
