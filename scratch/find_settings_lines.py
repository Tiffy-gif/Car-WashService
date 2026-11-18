with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, line in enumerate(lines):
    if '$validTabs =' in line:
        print(f"validTabs at line {i+1}: {line.strip()}")
    if "elseif ($action === 'save_loyalty')" in line:
        print(f"save_loyalty at line {i+1}")
    if "elseif ($action === 'save_telegram')" in line:
        print(f"save_telegram at line {i+1}")
    if "$settings = [];" in line:
        print(f"settings fetch at line {i+1}")
    if "switchTab('loyalty')" in line:
        print(f"switchTab loyalty at line {i+1}")
    if 'id="tab_performance"' in line:
        print(f"tab_performance at line {i+1}")
