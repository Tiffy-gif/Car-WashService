with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("$settings = [];")
if idx == -1:
    idx = text.find("$settings")
print(text[idx-50:idx+800])
