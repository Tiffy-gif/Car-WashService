with open('views/layouts/header.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('System Settings')
print(text[pos-200:pos+400])
