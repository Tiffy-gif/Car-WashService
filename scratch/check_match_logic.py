with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("if ($action === 'evaluate_cart_upsell')")
idx2 = text.find("// 2. Fetch active upsell rules", idx)
idx3 = text.find("echo json_encode([", idx2)
print(text[idx2:idx3])
