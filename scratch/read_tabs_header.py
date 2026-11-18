with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('tab-btn')
if pos != -1:
    print(text[pos-100:pos+1200])
else:
    pos2 = text.find('tab_shop')
    print(text[pos2-1000:pos2])
