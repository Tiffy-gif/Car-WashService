with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

import re
modals = re.findall(r'<div[^>]*class="[^"]*modal[^"]*"[^>]*>', text)
for m in modals:
    print(m)
