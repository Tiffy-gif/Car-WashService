const fs = require('fs');
const js = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = js.split('\n');
lines.forEach((l, i) => {
    if (l.includes('userMenuEndShift') || l.includes('userMenuSpotAudit') || l.includes('userMenuSettings') || l.includes('userMenuBackoffice')) {
        console.log(`${i + 1}: ${l.trim().slice(0, 120)}`);
    }
});
