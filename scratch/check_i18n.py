with open('pos/js/i18n.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('km:')
pos2 = text.find('en:')
print("pos km:", pos, "pos en:", pos2)
if pos != -1:
    print(text[pos:pos+300])
