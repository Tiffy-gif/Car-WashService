import os

matches = []
for root, dirs, files in os.walk('.'):
    if any(k in root for k in ['node_modules', '.git', 'scratch']):
        continue
    for f in files:
        if f.endswith(('.php', '.js', '.html', '.css', '.md')):
            p = os.path.join(root, f)
            try:
                content = open(p, 'r', encoding='utf-8', errors='ignore').read()
                if 'system settings' in content.lower() or 'system_settings' in content.lower():
                    matches.append(p)
            except:
                pass

print("Files containing system settings:", matches)
