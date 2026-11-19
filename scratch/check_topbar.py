with open('pos/index.html', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, line in enumerate(lines):
    if 'posShiftStatusBadge' in line:
        print("".join(lines[max(0, i-15):min(len(lines), i+25)]))
        break
