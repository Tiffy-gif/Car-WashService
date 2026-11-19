with open('views/layouts/nav.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('settings')
while pos != -1:
    print(text[pos-100:pos+300])
    print("-" * 50)
    pos = text.find('settings', pos+1)
