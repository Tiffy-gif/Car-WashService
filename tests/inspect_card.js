const fs = require('fs');
const lines = fs.readFileSync('pos/index.html', 'utf8').split('\n');
console.log(lines.slice(510, 535).join('\n'));
