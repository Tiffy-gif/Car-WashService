const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const lines = html.split('\n');
lines.forEach((l, i) => {
    if (l.includes('userDropdown') || l.includes('user-menu') || l.includes('endShiftMenu') || l.includes('spotAuditMenu') || l.includes('btnSettings')) {
        console.log(`${i + 1}: ${l.trim().slice(0, 120)}`);
    }
});
