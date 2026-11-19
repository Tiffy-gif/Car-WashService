with open('pos/css/app.css', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'modal-backdrop|modal-content', text)]
print(f"Found {len(matches)} matches in app.css")
if matches:
    print(text[matches[0]:matches[0]+600])
