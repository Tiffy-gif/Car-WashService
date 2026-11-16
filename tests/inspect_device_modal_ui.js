const fs = require('fs');
const code = fs.readFileSync('views/owner/pos_devices.php', 'utf8');
const lines = code.split('\n');
console.log(lines.slice(150, 220).join('\n'));
