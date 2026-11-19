with open('index.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("login")
print(text[pos:pos+400])
