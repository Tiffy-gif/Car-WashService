import re

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    c = f.read()

tabs = re.findall(r'id=["\'](tab_[^"\']+)["\']', c)
print('Found tabs:', tabs)
