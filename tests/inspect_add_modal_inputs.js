const fs = require('fs');
const code = fs.readFileSync('views/owner/pos_devices.php', 'utf8');
const idx = code.indexOf('id="addDeviceModal"');
console.log(code.substring(idx, idx + 1200));
