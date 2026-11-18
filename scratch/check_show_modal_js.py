with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('static showUpsellOfferModal(offer)')
print(text[idx:idx+800])
