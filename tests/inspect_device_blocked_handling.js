const fs = require('fs');
const code = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = code.split('\n');
lines.forEach((l, i) => {
  if (l.includes('DEVICE_BLOCKED') || l.includes('UNAPPROVED_DEVICE') || l.includes('DEVICE_KEY_REQUIRED') || l.includes('device_blocked') || l.includes('deviceBlocked')) {
    console.log(i + 1, l);
  }
});
