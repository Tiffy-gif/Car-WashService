with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('tab_performance')
print(text[pos:pos+2500])
