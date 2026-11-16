const fs = require('fs');
const specs = JSON.parse(fs.readFileSync('tests/gotpos_detailed_specs.json', 'utf8'));
console.log(Object.keys(specs));
for (const k of Object.keys(specs)) {
    if (k !== 'card') console.log(k, JSON.stringify(specs[k]).slice(0, 300));
}
