with open('pos/js/thermal-printer.js', 'r', encoding='utf-8') as f:
    text = f.read()

import re
funcs = re.findall(r'(?:static\s+async\s+|static\s+|function\s+)(\w+)\s*\(', text)
print("Functions in thermal-printer.js:")
for f in sorted(set(funcs)):
    print("-", f)
