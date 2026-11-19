with open('pos/js/thermal-printer.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('renderOpenShiftSlipToCanvas')
print(text[pos:pos+1200])
