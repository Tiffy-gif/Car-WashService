const fs = require('fs');
const css = fs.readFileSync('pos/css/app.css', 'utf8');
const lines = css.split('\n');
lines.forEach((l, i) => {
    if (l.includes('mechanic-screen-container') || l.includes('lift-hero-card') || l.includes('mechanic-workspace-grid')) {
        console.log(`${i + 1}: ${l.trim().slice(0, 100)}`);
    }
});
