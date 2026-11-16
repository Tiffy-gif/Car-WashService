const fs = require('fs');
const html = fs.readFileSync('pos/index.html', 'utf8');
const regex = /id="(modal[^"]+)"/g;
let m;
while ((m = regex.exec(html)) !== null) {
  console.log(m[1]);
}
