const { execSync } = require('child_process');
const fs = require('fs');

// Read the diff
const patch = execSync('git diff pos/js/app.js', { maxBuffer: 10 * 1024 * 1024 }).toString();

// Parse diff into hunks
const lines = patch.split('\n');
const hunks = [];
let currentHunk = [];
let inHeader = true;
let headerLines = [];

for (const line of lines) {
    if (line.startsWith('@@ ')) {
        if (currentHunk.length > 0) {
            hunks.push(currentHunk.join('\n'));
            currentHunk = [];
        }
        inHeader = false;
        currentHunk.push(line);
    } else if (inHeader) {
        headerLines.push(line);
    } else {
        currentHunk.push(line);
    }
}
if (currentHunk.length > 0) {
    hunks.push(currentHunk.join('\n'));
}

console.log('Total hunks:', hunks.length);

// Bisect hunks: test applying 1..k hunks
for (let k = 1; k <= hunks.length; k++) {
    const testPatch = headerLines.join('\n') + '\n' + hunks.slice(0, k).join('\n') + '\n';
    fs.writeFileSync('scratch/test.patch', testPatch);
    try {
        execSync('git checkout pos/js/app.js');
        execSync('git apply scratch/test.patch');
        execSync('node --check pos/js/app.js');
    } catch (e) {
        console.log(`Failed at hunk ${k} / ${hunks.length}:`);
        console.log(hunks[k - 1].slice(0, 300));
        break;
    }
}
