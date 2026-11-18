with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("$activeTab === 'loyalty'")
if idx == -1:
    idx = text.find("activeTab === 'loyalty'")
if idx != -1:
    print(text[idx:idx+2500])
else:
    print("Not found")
