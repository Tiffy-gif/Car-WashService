with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'modalCancelOrder', text)]
for idx in matches:
    start = max(0, idx - 100)
    end = min(len(text), idx + 400)
    print("--- MATCH ---")
    print(text[start:end])
