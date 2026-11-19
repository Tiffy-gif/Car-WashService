with open('pos/js/storage.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('SESSION')
if pos != -1:
    print(text[pos-50:pos+200])
else:
    pos2 = text.find('TOKEN')
    print(text[pos2-50:pos2+200])
