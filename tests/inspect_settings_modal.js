const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const idx = html.indexOf('id="modalSettings"');
console.log('modalSettings found at idx:', idx);
if (idx >= 0) console.log(html.substring(idx, idx + 1500));
