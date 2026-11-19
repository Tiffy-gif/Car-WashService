with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

start = None
end = None
for i, l in enumerate(lines):
    if 'id="tab_receipt"' in l:
        start = i
    if 'id="tab_money"' in l:
        end = i
        break

print(f"tab_receipt spans lines {start+1} to {end+1}")
