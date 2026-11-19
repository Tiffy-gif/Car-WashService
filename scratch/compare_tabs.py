import subprocess, re

cmd = ['git', 'show', '49620b7:views/owner/settings.php']
old_text = subprocess.check_output(cmd, encoding='utf-8')

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    new_text = f.read()

# Find all comments with "GROUP" in old and new
old_groups = re.findall(r'<!-- (?:GROUP|=+)[^>]+-->', old_text)
new_groups = re.findall(r'<!-- (?:GROUP|=+)[^>]+-->', new_text)

print("Old groups count:", len(old_groups))
print("New groups count:", len(new_groups))

old_tabs = re.findall(r'id="(tab_[^"]+)"', old_text)
new_tabs = re.findall(r'id="(tab_[^"]+)"', new_text)

print("Old tabs:", old_tabs)
print("New tabs:", new_tabs)
