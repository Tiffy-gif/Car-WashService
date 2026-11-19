with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

import re
matches = re.findall(r'.*header\.php.*', text)
print(matches)
