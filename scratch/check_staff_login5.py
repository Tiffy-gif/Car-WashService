with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("action === 'staff_login'")
pos2 = text.find("'session_token'", pos)
print(text[pos2-100:pos2+300])
