with open('views/receipt/view.php', 'r', encoding='utf-8') as f:
    for i in range(80):
        print(f.readline().rstrip())
