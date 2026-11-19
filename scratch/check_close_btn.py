with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('id="btnCloseCashMovementModal"')
print(text[pos-200:pos+300])
