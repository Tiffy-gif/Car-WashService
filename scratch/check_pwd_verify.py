with open('core/CashierControl.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('function verifyManagerPin')
pos2 = text.find('password_verify', pos)
print(text[pos2-100:pos2+300])
