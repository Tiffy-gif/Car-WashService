const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
console.log(lines.slice(2575, 2650).join('\n'));
