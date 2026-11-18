const fs = require('fs');
const css = fs.readFileSync('pos/css/app.css', 'utf8');
const lines = css.split('\n');
lines.forEach((l, i) => {
    if (l.includes('washer-command-header') || l.includes('washer-header-title-box')) {
        console.log(`${i + 1}: ${l.trim().slice(0, 100)}`);
    }
});
