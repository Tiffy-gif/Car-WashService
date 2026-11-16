const fs = require('fs');
const lines = fs.readFileSync('pos/index.html', 'utf8').split('\n');
console.log(lines.slice(175, 205).join('\n'));
