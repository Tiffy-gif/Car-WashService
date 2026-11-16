const fs = require('fs');
const lines = fs.readFileSync('pos/js/app.js', 'utf8').split('\n');
console.log(lines.slice(5265, 5345).join('\n'));
