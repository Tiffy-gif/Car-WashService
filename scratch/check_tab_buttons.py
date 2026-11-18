with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('switchTab(')
print(text[idx-200:idx+1200])
