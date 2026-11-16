const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
const idx = lines.findIndex(l => l.includes('static renderLoggedInState()'));
console.log(lines.slice(idx, idx + 35).join('\n'));
