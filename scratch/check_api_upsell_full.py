with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("if ($action === 'evaluate_cart_upsell')")
idx2 = text.find("if ($action ===", idx + 10)
if idx2 == -1:
    idx2 = text.find("// Unknown action", idx)
print(text[idx:idx2])
