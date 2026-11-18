import re

with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

matches = [m.start() for m in re.finditer(r'http_response_code\(401\)', text)]
print("Count of 401:", len(matches))
for m in matches[:5]:
    print(text[m-50:m+250])
    print("="*50)
