with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('function switchTab')
print(text[idx:idx+800])
