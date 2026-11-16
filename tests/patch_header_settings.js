const fs = require('fs');
let html = fs.readFileSync('pos/index.html', 'utf8');

const regex = /([ \t]*<!-- Fullscreen Button in POS Header -->[\r\n]+[ \t]*<button type="button" class="btn btn-icon btn-topbar-tool" id="btnToggleFullscreen"[^>]*>[\s\S]*?<\/button>)/;

const match = html.match(regex);
if (match) {
    const replacement = `            <!-- Settings Button in POS Header -->\r\n            <button type="button" class="btn btn-icon btn-topbar-tool" id="btnHeaderSettings" title="POS Settings & Device Key" data-i18n-title="settings">\r\n                <i class="fa-solid fa-gear"></i>\r\n            </button>\r\n\r\n` + match[1];
    html = html.replace(regex, replacement);
    fs.writeFileSync('pos/index.html', html, 'utf8');
    console.log('Successfully added btnHeaderSettings to pos/index.html');
} else {
    console.error('Regex target not matched in pos/index.html');
}
