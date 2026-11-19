with open('core/CashierControl.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('function verifyManagerPin')
print(text[pos+1000:pos+2200])
