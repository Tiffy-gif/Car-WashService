with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("$validTabs")
print(text[idx:idx+1500])
