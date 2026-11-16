const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
const idx = lines.findIndex(l => l.includes('refreshCatalogSilently'));
console.log(lines.slice(idx, idx + 45).join('\n'));
