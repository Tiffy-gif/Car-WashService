const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const lines = html.split('\n');
lines.forEach((l, i) => {
  if (l.includes('modalSettings') || l.includes('openSettingsModal') || l.includes('btnSettings') || l.includes('SettingsModal')) {
    console.log(i + 1, l.trim());
  }
});
