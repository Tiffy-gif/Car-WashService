const fs = require('fs');
const code = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = code.split('\n');
lines.forEach((l, i) => {
  if (l.includes("document.addEventListener('click'") || l.includes('document.addEventListener("click"')) {
    console.log(i + 1, l);
  }
});
