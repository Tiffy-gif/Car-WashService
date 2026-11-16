const fs = require('fs');
const code = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = code.split('\n');
lines.forEach((l, i) => {
  if (l.includes('btnOpenSettings') || l.includes('modalSettings') || l.includes('openSettingsModal')) {
    console.log(i + 1, l.trim());
  }
});
