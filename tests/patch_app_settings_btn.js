const fs = require('fs');
let code = fs.readFileSync('pos/js/app.js', 'utf8');

const regex = /(if \(btnOpenSettings\) \{[\s\S]*?btnOpenSettings\.addEventListener\('click', openSettings\);[\s\S]*?\})/;
const match = code.match(regex);
if (match) {
    const replacement = match[1] + `\n        const btnHeaderSettings = document.getElementById('btnHeaderSettings');\n        if (btnHeaderSettings) {\n            btnHeaderSettings.addEventListener('click', openSettings);\n        }`;
    code = code.replace(regex, replacement);
    fs.writeFileSync('pos/js/app.js', code, 'utf8');
    console.log('Successfully added btnHeaderSettings listener to pos/js/app.js');
} else {
    console.error('Regex target not matched in pos/js/app.js');
}
