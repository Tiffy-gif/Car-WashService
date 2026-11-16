const fs = require('fs');
const code = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = code.split('\n');
const idx = lines.findIndex(l => l.includes('checkConnectionHealth()'));
console.log(lines.slice(idx, idx + 45).join('\n'));
