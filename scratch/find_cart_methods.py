import re
with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

matches = re.findall(r'static\s+([a-zA-Z0-9_]*Cart[a-zA-Z0-9_]*)\(', text)
print("Cart methods:", set(matches))
