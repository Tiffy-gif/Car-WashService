const fs = require('fs');
const code = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = code.split('\n');
const idx = lines.findIndex(l => l.includes('static checkConnectionHealth'));
console.log('Index:', idx);
if (idx >= 0) console.log(lines.slice(idx, idx + 45).join('\n'));
