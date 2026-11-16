const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const idx = html.indexOf('id="modalSettings"');
console.log(html.substring(idx + 800, idx + 3500));
