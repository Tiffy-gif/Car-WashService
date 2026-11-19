with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('id="formLogin"')
if pos != -1:
    print(text[pos:pos+600])
else:
    pos2 = text.find('loginUsername')
    print(text[pos2-100:pos2+300])
