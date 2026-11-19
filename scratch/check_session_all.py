with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'checkSession', text)]
for idx in matches:
    print(text[max(0, idx-20):min(len(text), idx+150)])
