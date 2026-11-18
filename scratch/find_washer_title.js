const fs = require('fs');
const js = fs.readFileSync('pos/js/i18n.js', 'utf8');
const lines = js.split('\n');
lines.forEach((l, i) => {
    if (l.includes('washerDutyTitle')) {
        console.log(`${i + 1}: ${l.trim()}`);
    }
});
