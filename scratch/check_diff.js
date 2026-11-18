const { execSync } = require('child_process');
const fs = require('fs');

const diff = execSync('git diff pos/js/app.js').toString();
const lines = diff.split('\n');
console.log('Total diff lines:', lines.length);

// Let's see file content before changes using git show HEAD:pos/js/app.js
fs.writeFileSync('scratch/app_head.js', execSync('git show HEAD:pos/js/app.js', { maxBuffer: 10 * 1024 * 1024 }));

const res = execSync('node --check scratch/app_head.js', { stdio: 'pipe' });
console.log('HEAD app.js syntax check passed!');
