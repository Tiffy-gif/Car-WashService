const fs = require('fs');
const code = fs.readFileSync('pos/css/app.css', 'utf8');
const lines = code.split('\n');
lines.forEach((l, i) => {
  if (l.includes('.btn-topbar-tool')) {
    console.log(i + 1, l);
  }
});
