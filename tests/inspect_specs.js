const fs = require('fs');
if (fs.existsSync('tests/gotpos_detailed_specs.json')) {
    const text = fs.readFileSync('tests/gotpos_detailed_specs.json', 'utf8');
    console.log(text.slice(0, 1500));
}
