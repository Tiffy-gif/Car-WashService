with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, line in enumerate(lines):
    if 'allowedActions' in line or "'record_cash_movement'" in line:
        print(f"Line {i+1}: {line.strip()}")
