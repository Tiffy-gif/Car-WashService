with open('pos/js/storage.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('static setCurrentUser')
if pos != -1:
    print(text[pos:pos+400])
pos2 = text.find('static setCurrentShift')
if pos2 != -1:
    print(text[pos2:pos2+400])
