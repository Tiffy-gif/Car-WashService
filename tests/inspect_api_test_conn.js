const fs = require('fs');
const code = fs.readFileSync('api/pos/index.php', 'utf8');
const lines = code.split('\n');
lines.forEach((l, i) => {
  if (l.includes('test_connection')) {
    console.log(i + 1, l);
  }
});
