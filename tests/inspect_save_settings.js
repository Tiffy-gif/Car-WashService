const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
console.log(lines.slice(11160, 11230).join('\n'));
