const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const idx = html.indexOf('id="viewLogin"');
console.log(html.substring(idx, idx + 1800));
