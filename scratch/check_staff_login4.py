with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("action === 'staff_login'")
pos2 = text.find("echo json_encode([", pos + 1000)
print(text[pos2:pos2+600])
