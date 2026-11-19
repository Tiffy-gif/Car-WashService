with open('views/orders/receipt.php', 'r', encoding='utf-8') as f:
    for i in range(120):
        print(f.readline().rstrip())
