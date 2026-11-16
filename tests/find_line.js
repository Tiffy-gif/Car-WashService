const fs = require('fs');
const lines = fs.readFileSync('pos/index.html', 'utf8').split('\n');
lines.forEach((l, i) => {
  if (l.includes('id="modalCustomer"')) console.log(i + 1, l);
});
