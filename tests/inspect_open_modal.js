const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
const idx = lines.findIndex(l => l.includes('openCustomerSelectModal()'));
console.log(lines.slice(idx, idx + 40).join('\n'));
