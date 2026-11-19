with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("action === 'get_shift_status'")
print(text[pos:pos+700])
