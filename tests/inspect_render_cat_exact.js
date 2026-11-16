const fs = require('fs');
const content = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = content.split('\n');
console.log(lines.slice(3650, 3755).join('\n'));
