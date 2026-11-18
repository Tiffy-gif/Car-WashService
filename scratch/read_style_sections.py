with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

import re
components = re.findall(r'(\/\* ===+ [^*]+ ===+\*\/)', text)
for c in components:
    print(c)
