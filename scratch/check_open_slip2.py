with open('pos/js/thermal-printer.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('renderOpenShiftSlipToCanvas')
print(text[pos+1200:pos+2600])
