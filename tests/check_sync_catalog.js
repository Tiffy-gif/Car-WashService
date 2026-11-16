const fs = require('fs');
// Let's check api/sync_catalog.php or what PosStorage returns
const phpCode = fs.readFileSync('api/sync_catalog.php', 'utf8');
console.log('sync_catalog.php length:', phpCode.length);
