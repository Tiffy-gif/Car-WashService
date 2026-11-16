const fs = require('fs');
const specs = JSON.parse(fs.readFileSync('tests/gotpos_detailed_specs.json', 'utf8'));
console.log(specs.catTabs.outerHTML);
