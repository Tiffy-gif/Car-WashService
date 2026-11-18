with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("action === 'save_loyalty'")
print(text[idx-20:idx+1500])
