with open('pos/js/thermal-printer.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('isReprint')
pos2 = text.find('isReprint', pos + 20)
if pos2 != -1:
    print(text[pos2-100:pos2+600])
