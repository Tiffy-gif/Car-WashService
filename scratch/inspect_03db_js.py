with open('scratch/component_from_03db.html', 'r', encoding='utf-8') as f:
    text = f.read()

import re
print("onclick preview:", re.findall(r'onclick=[\'"][^\'"]*lang[^\'"]*[\'"]', text, re.I))
print("functions:", re.findall(r'function\s+[a-zA-Z0-9_]+\s*\(', text))
