with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    for i, l in enumerate(f):
        if '$settings' in l and ('=' in l or 'pdo' in l or 'SELECT' in l):
            print(f'{i+1}: {l.strip()[:90]}')
