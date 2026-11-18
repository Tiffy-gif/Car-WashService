const fs = require('fs');
const vm = require('vm');

const code = fs.readFileSync('pos/js/app.js', 'utf8');
const lines = code.split('\n');

// Find which method in PosApp fails to parse
// Let's split by static methods
let currentMethod = '';
let methodStart = 0;

for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const m = line.match(/^\s*(async\s+)?(static\s+)?([a-zA-Z0-9_$]+)\s*\(/);
    if (m) {
        // test previous chunk
        if (currentMethod) {
            const chunk = 'class Test {\n' + lines.slice(methodStart, i).join('\n') + '\n}';
            try {
                new vm.Script(chunk);
            } catch (err) {
                console.log(`Error in method ${currentMethod} (lines ${methodStart + 1}-${i}):`, err.message);
            }
        }
        currentMethod = m[3];
        methodStart = i;
    }
}
