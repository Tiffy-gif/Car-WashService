const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
const idx = lines.findIndex(l => l.includes('static openCustomerSelectModal'));
console.log('Index:', idx);
if (idx >= 0) console.log(lines.slice(idx, idx + 40).join('\n'));
