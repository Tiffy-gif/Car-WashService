import re

with open('pos/index.html', 'r', encoding='utf-8') as f:
    html = f.read()

modals = re.findall(r'id=["\'](modal\w+)["\']', html)
print("Modals found:")
for m in sorted(set(modals)):
    print("-", m)
