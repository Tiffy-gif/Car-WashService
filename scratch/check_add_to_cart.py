with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('static addToCart(')
print(text[idx:idx+1200])
