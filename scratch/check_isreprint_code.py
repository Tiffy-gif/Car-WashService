with open('pos/js/thermal-printer.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('if (isReprint)')
if pos != -1:
    print(text[pos:pos+400])
