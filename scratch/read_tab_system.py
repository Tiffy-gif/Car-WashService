with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('id="tab_system"')
print(text[pos:pos+1500])
