with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, line in enumerate(lines):
    if "document.getElementById('posShiftStatusBadge')?.addEventListener('click'" in line:
        print(f"Line {i+1}: {line}")
        print("".join(lines[i:i+30]))
        break
