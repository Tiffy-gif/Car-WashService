const fs = require('fs');
const lines = fs.readFileSync('pos/index.html', 'utf8').split('\n');
console.log(lines.slice(370, 395).join('\n'));
