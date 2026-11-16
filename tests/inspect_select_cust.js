const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
console.log(lines.slice(5145, 5235).join('\n'));
