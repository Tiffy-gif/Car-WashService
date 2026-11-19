with open('pos/js/api.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('recordCashMovement')
if pos != -1:
    print(text[pos-200:pos+800])
