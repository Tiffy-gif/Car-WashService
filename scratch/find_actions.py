with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    for i, l in enumerate(f):
        if 'action ===' in l:
            print(f'{i+1}: {l.strip()[:80]}')
