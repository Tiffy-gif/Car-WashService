with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = re.findall(r'(\.[a-zA-Z0-9_\-]+)\s*\{', text)
unique_classes = sorted(list(set(matches)))
print("Total classes:", len(unique_classes))
# print classes related to card, table, btn, badge, form, modal, tab
for c in unique_classes:
    if any(k in c for k in ['card', 'table', 'btn', 'badge', 'form', 'modal', 'tab', 'stat']):
        print(c)
