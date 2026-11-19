with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, l in enumerate(lines[:120]):
    if 'save_shop' in l:
        print(''.join(lines[i:i+40]))
