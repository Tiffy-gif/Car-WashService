with open('pos/js/api.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('sessionToken')
if pos != -1:
    print(text[pos-50:pos+300])
else:
    pos2 = text.find('token')
    print(text[pos2-50:pos2+300])
