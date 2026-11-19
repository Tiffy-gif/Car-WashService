with open('views/orders/receipt.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

print(''.join(lines[120:180]))
