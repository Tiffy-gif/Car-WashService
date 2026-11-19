import subprocess, re

cmd = ['git', 'show', '49620b7:views/owner/settings.php']
text = subprocess.check_output(cmd, encoding='utf-8')
print("Length before:", len(text))

tabs = re.findall(r'id="(tab_[^"]+)"', text)
print("Tabs before any changes:", tabs)

tab_buttons = re.findall(r"switchTab\('([^']+)'\)", text)
print("Tab buttons before:", tab_buttons)
