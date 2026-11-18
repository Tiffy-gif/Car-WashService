settings_path = 'views/owner/settings.php'
with open(settings_path, 'r', encoding='utf-8') as f:
    text = f.read()

pos_start = text.find('<!-- GROUP 6C: UPSELL & PROMOTION OFFERS STUDIO -->')
if pos_start == -1:
    pos_tab = text.find('id="tab_upsell"')
    pos_start = text.rfind('<!-- ======================================================== -->', 0, pos_tab)
else:
    pos_start = text.rfind('<!-- ======================================================== -->', 0, pos_start)

pos_end = text.find('<!-- GROUP 6B: PERFORMANCE & LEADERBOARD SETTINGS -->')
pos_end = text.rfind('<!-- ======================================================== -->', 0, pos_end)

print(f"Replacing from index {pos_start} to {pos_end}")

with open('scratch/native_upsell_snippet.html', 'r', encoding='utf-8') as f:
    replacement = f.read()

before = text[:pos_start]
after = text[pos_end:]

new_text = before + replacement.strip() + "\n\n" + after

with open(settings_path, 'w', encoding='utf-8') as f:
    f.write(new_text)

print("Replaced successfully in views/owner/settings.php!")
