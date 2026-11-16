const fs = require('fs');
const i18n = fs.readFileSync('pos/js/i18n.js', 'utf8');
const lines = i18n.split('\n');
const idx = lines.findIndex(l => l.includes('applyToDOM()'));
console.log(lines.slice(idx + 35, idx + 65).join('\n'));
