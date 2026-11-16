const fs = require('fs');
let sidebar = '';
try {
  sidebar = fs.readFileSync('views/layouts/sidebar.php', 'utf8');
} catch (e) {
  try {
    sidebar = fs.readFileSync('views/layouts/nav.php', 'utf8');
  } catch(e2) {
    sidebar = 'Not found';
  }
}
const lines = sidebar.split('\n');
lines.forEach((l, i) => {
  if (l.includes('pos_devices') || l.includes('Device') || l.includes('device') || l.includes('Terminal')) {
    console.log(i + 1, l.trim());
  }
});
