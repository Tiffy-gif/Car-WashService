const fs = require('fs');
const i18n = fs.readFileSync('pos/js/i18n.js', 'utf8');
console.log('Has selectCustomer:', i18n.includes('selectCustomer'));
console.log('Has walkIn:', i18n.includes('walkIn'));
console.log('Sample keys:', i18n.match(/[a-zA-Z0-9_-]+:\s*['"][^'"]+['"]/g)?.slice(0, 20));
