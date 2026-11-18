with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('static addServiceToCart')
idx2 = text.find('this.cart.push', idx)
print(text[idx2:idx2+600])
