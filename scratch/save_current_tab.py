with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

idx_receipt_div = 52487
idx_money_div = 63151

with open('scratch/current_tab_receipt.txt', 'w', encoding='utf-8') as f:
    f.write(content[idx_receipt_div:idx_money_div])

print("Written scratch/current_tab_receipt.txt")
