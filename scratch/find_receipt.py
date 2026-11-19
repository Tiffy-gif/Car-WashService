with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, l in enumerate(lines):
    if 'id="tab_receipt"' in l or 'save_receipt' in l:
        print(f'{i+1}: {l.strip()[:80]}')
