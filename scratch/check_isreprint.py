with open('pos/js/thermal-printer.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('isReprint')
if pos != -1:
    print(text[pos-100:pos+600])
