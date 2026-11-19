with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.rfind('require_once')
print(text[pos-300:pos+200])
