settings_path = 'views/owner/settings.php'
with open(settings_path, 'r', encoding='utf-8') as f:
    text = f.read()

pos1 = text.find('id="tab_upsell"')
pos_start = text.rfind('<!-- ======================================================== -->', 0, pos1)

pos_next = text.find('<!-- GROUP 6B: PERFORMANCE & LEADERBOARD SETTINGS -->')
pos_end = text.rfind('<!-- ======================================================== -->', 0, pos_next)

print(f"Replacing from {pos_start} to {pos_end}")

with open('scratch/component_from_03db.html', 'r', encoding='utf-8') as f:
    replacement = f.read()

before = text[:pos_start]
after = text[pos_end:]

new_text = before + "<!-- ======================================================== -->\n" + replacement.strip() + "\n\n" + after

with open(settings_path, 'w', encoding='utf-8') as f:
    f.write(new_text)

print("Revived component in views/owner/settings.php successfully!")
