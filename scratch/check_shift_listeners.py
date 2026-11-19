with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('posShiftStatusBadge')
print(text[pos-100:pos+800])
