import sys
sys.path.append('scratch')
from draft_enhanced_tab import enhanced_tab_upsell_html

settings_path = 'views/owner/settings.php'
with open(settings_path, 'r', encoding='utf-8') as f:
    text = f.read()

# Locate the boundaries
target_start_str = '<!-- ======================================================== -->\n<!-- GROUP 6C: UPSELL & PROMOTION OFFERS STUDIO -->'
pos_start = text.find(target_start_str)
if pos_start == -1:
    # fallback to id="tab_upsell"
    pos_tab = text.find('id="tab_upsell"')
    pos_start = text.rfind('<!-- ======================================================== -->', 0, pos_tab)

pos_end = text.find('<!-- ======================================================== -->\n<!-- GROUP 6B: PERFORMANCE & LEADERBOARD SETTINGS -->')
if pos_end == -1:
    pos_end = text.find('<!-- GROUP 6B: PERFORMANCE & LEADERBOARD SETTINGS -->')

print(f"Replacing from index {pos_start} to {pos_end}")
before = text[:pos_start]
after = text[pos_end:]

new_text = before + enhanced_tab_upsell_html.strip() + "\n\n" + after

with open(settings_path, 'w', encoding='utf-8') as f:
    f.write(new_text)

print("Successfully replaced tab_upsell in settings.php!")
