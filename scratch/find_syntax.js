const fs = require('fs');
const s = fs.readFileSync('pos/js/app.js', 'utf8');

// Parse with Babel/acorn or simple tokenizer
try {
    new Function(s);
    console.log('No syntax error!');
} catch (e) {
    console.log('Error message:', e.message);
    console.log('Stack:', e.stack);
}
