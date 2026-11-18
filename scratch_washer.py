
import re
with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

matches = [m.start() for m in re.finditer(r'washer', text, re.I)]
print('Matches count:', len(matches))
for m in matches[:5]:
    print(text[max(0, m-50):m+250])
    print('='*40)
