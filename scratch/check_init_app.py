with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('initEventListeners')
if pos != -1:
    print(text[pos:pos+800])
else:
    pos2 = text.find('init()')
    print(text[pos2:pos2+800])
