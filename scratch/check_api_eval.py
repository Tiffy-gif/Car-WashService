with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("if ($action === 'evaluate_cart_upsell')")
idx2 = text.find("// Unknown action fallback", idx)
print(text[idx:idx2])
