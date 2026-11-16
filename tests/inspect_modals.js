const fs = require('fs');

const html = fs.readFileSync('pos/index.html', 'utf8');
const regex = /<div[^>]*class="[^"]*modal[^"]*"[^>]*id="([^"]+)"/g;
let match;
while ((match = regex.exec(html)) !== null) {
    console.log('Modal ID:', match[1]);
}
