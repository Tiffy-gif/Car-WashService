const fs = require('fs');
const content = fs.readFileSync('pos/js/storage.js', 'utf8');
const lines = content.split('\n');
lines.forEach((l, i) => {
    if (l.includes('fetch(') || l.includes('http') || l.includes('sync') || l.includes('api')) {
        console.log('Line ' + (i+1) + ': ' + l.trim());
    }
});
