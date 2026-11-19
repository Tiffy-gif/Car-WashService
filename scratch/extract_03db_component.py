import subprocess

cmd = ['git', 'show', '03dbf13:views/owner/settings.php']
text = subprocess.check_output(cmd, encoding='utf-8')

pos1 = text.find('<!-- GROUP 6C: UPSELL & PROMOTION OFFERS STUDIO -->')
pos2 = text.find('<!-- GROUP 6B: PERFORMANCE & LEADERBOARD SETTINGS -->')
component_html = text[pos1:pos2]

with open('scratch/component_from_03db.html', 'w', encoding='utf-8') as f:
    f.write(component_html)

print("Extracted component from 03dbf13! Length:", len(component_html))
