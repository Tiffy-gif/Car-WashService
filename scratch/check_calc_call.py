with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = [m.start() for m in re.finditer(r'calculateShiftExpectedCash', text)]
print(f"Found {len(matches)} occurrences")
for idx in matches:
    print(text[max(0, idx-50):min(len(text), idx+150)])
