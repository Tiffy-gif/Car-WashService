import re

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

tabs = re.findall(r'id=["\']tab-([^"\']+)["\']', text)
print('Settings sections/tabs:', tabs)
cards = re.findall(r'<h[234][^>]*>(.*?)</h[234]>', text)
for c in cards[:15]:
    print(' -', c.strip())
