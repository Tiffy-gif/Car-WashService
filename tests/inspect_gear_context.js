const fs = require('fs');
const lines = fs.readFileSync('pos/index.html', 'utf8').split('\n');
console.log('--- Lines 150-175 ---');
console.log(lines.slice(150, 175).join('\n'));
console.log('--- Lines 200-220 ---');
console.log(lines.slice(200, 220).join('\n'));
