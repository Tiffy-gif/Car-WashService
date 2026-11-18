with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('static renderWasherBays')
print(text[idx+3500:idx+7000])
