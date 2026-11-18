with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("'evaluate_cart_upsell'")
print(text[idx-50:idx+300])
