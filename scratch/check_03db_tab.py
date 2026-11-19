import subprocess

cmd = ['git', 'show', '03dbf13:views/owner/settings.php']
commit_text = subprocess.check_output(cmd, encoding='utf-8')

pos1 = commit_text.find('id="tab_upsell"')
pos2 = commit_text.find('<!-- GROUP 6B: PERFORMANCE')
print("In 03dbf13, tab_upsell length:", pos2 - pos1)
print(commit_text[pos1:pos1+1500])
