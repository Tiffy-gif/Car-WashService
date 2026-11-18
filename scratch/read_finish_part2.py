with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('static async handleConfirmFinish')
print(text[idx+1200:idx+3000])
