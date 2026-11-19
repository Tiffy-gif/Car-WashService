with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('function switchTab')
print(text[pos:pos+1500])
