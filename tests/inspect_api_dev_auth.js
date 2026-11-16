const fs = require('fs');
const code = fs.readFileSync('api/pos/index.php', 'utf8');
const lines = code.split('\n');
console.log(lines.slice(170, 240).join('\n'));
