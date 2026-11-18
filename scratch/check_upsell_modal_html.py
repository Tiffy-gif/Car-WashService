with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('id="modalUpsellPrompt"')
print(text[idx:idx+1500])
