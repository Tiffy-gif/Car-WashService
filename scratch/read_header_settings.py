with open('views/layouts/header.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('view=settings')
print(text[pos-200:pos+400])
