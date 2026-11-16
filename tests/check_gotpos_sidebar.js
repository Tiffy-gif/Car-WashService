const fs = require('fs');
if (fs.existsSync('tests/gotpos_live_data.json')) {
    const data = JSON.parse(fs.readFileSync('tests/gotpos_live_data.json', 'utf8'));
    console.log('Keys in gotpos_live_data.json:', Object.keys(data));
    if (data.leftSidebarHTML) {
        console.log('leftSidebarHTML preview:\n', data.leftSidebarHTML.slice(0, 1500));
    }
}
