const fs = require('fs');
const code = fs.readFileSync('index.php', 'utf8');
const lines = code.split('\n');
lines.forEach((l, i) => {
  if (l.includes('pos_devices') || l.includes('pos/index.html')) {
    console.log(i + 1, l);
  }
});
