const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const lines = html.split('\n');
lines.forEach((l, i) => {
    if (l.toLowerCase().includes('mechanic')) {
        console.log(`${i + 1}: ${l.trim().slice(0, 120)}`);
    }
});
