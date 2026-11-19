with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("action === 'staff_login'")
print(text[pos:pos+400])
