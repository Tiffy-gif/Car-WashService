with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'data-view=["\']([^"\']+)["\']', text)]
views = [text[m:m+30] for m in matches]
print("data-views in index.html:")
for v in sorted(set(views)):
    print(v)
