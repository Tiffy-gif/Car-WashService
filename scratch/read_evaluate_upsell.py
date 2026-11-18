with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("if ($action === 'evaluate_cart_upsell')")
print(text[idx:idx+2500])
