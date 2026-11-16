const fs = require('fs');
const data = JSON.parse(fs.readFileSync('tests/gotpos_live_data.json', 'utf8'));
if (data.bodyHTML) {
    const html = data.bodyHTML;
    const lines = html.split('\n');
    lines.forEach((l, i) => {
        if (l.includes('Clothing') || l.includes('Footwear') || l.includes('Watches') || l.includes('category')) {
            console.log('Line ' + (i+1) + ': ' + l.trim().slice(0, 120));
        }
    });
}
