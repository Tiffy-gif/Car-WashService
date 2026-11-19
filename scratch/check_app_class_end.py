with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

print("Length of app.js:", len(text))
pos = text.rfind('window.PosApp = PosApp')
if pos != -1:
    print(text[pos-400:pos+100])
else:
    print(text[-500:])
