with open('pos/js/api.js', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'openDrawer|open_drawer', text)]
print(f"Found {len(matches)} occurrences in api.js")
for idx in matches:
    print(text[max(0, idx-50):min(len(text), idx+150)])
