const fs = require('fs');
const css = fs.readFileSync('pos/css/app.css', 'utf8');
const lines = css.split('\n');
lines.forEach((l, i) => {
    if (l.includes('.user-dropdown-menu .header-menu-item') || l.includes('user-dropdown-menu')) {
        console.log(`${i + 1}: ${l.trim().slice(0, 100)}`);
    }
});
