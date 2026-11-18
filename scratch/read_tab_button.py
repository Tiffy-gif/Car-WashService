with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("switchTab('upsell')")
if pos != -1:
    print(text[pos-100:pos+200])
