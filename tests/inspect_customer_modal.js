const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const idx = html.indexOf('id="modalCustomer"');
console.log(html.substring(idx, idx + 1500));
