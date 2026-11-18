import re

with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

# find sections matching washer
for m in re.finditer(r'<section[^>]*id=["\']([^"\']*washer[^"\']*)["\'][^>]*>', text, re.I):
    start = m.start()
    print("Found section:", m.group(0))
    print(text[start:start+2000])
    print("="*60)
