const fs = require('fs');
const lines = fs.readFileSync('views/layouts/sidebar.php', 'utf8').split('\n');
console.log(lines.slice(155, 205).join('\n'));
