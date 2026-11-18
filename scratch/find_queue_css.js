const fs = require('fs');
const css = fs.readFileSync('pos/css/app.css', 'utf8');
const lines = css.split('\n');
lines.forEach((l, i) => {
    if (l.includes('queue-list-table') || l.includes('bay-card') || l.includes('btn-queue-back-pos') || l.includes('queue-section-header')) {
        console.log(`${i + 1}: ${l.trim().slice(0, 100)}`);
    }
});
