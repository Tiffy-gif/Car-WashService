import re

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

matches = re.findall(r'(?:if|elseif)\s*\(\$action\s*===\s*[\'"]([^\'"]+)[\'"]\)', text)
print("Action handlers in settings.php:", matches)
