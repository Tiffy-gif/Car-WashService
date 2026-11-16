const fs = require('fs');
const files = fs.readdirSync('pos/js');
files.forEach(f => {
  if (f.endsWith('.js')) {
    const c = fs.readFileSync('pos/js/' + f, 'utf8');
    if (c.includes('checkConnectionHealth')) {
      console.log('Found in', f);
      const lines = c.split('\n');
      lines.forEach((l, i) => {
        if (l.includes('checkConnectionHealth')) console.log(i + 1, l.trim());
      });
    }
  }
});
