const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
console.log('--- Line 1552 ---');
console.log(lines.slice(1545, 1570).join('\n'));
console.log('--- Line 1776 ---');
console.log(lines.slice(1770, 1795).join('\n'));
