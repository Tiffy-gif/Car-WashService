with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

idx = 0
while True:
    idx = text.find('checkCartUpsellRules', idx)
    if idx == -1: break
    print(text[idx-50:idx+150])
    print('='*40)
    idx += len('checkCartUpsellRules')
