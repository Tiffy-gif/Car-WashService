with open('views/layouts/header.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, line in enumerate(lines):
    if 'topbar' in line.lower() or 'header-title' in line.lower() or 'page-title' in line.lower() or 'navbar' in line.lower():
        print(f'{i+1}: {line.strip()[:80]}')
