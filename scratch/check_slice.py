import re

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Let's inspect the exact lines of tab_receipt
receipt_start_token = "<!-- GROUP 2: RECEIPT SETTINGS -->"
money_start_token = "<!-- GROUP 3: MONEY & EXCHANGE RATE -->"

idx_start = content.find(receipt_start_token)
idx_end = content.find(money_start_token)

if idx_start == -1 or idx_end == -1:
    print("Tokens not found!")
    exit(1)

# Back up to comment divider before GROUP 2
idx_receipt_div = content.rfind('<!-- ========================================================', 0, idx_start)
idx_money_div = content.rfind('<!-- ========================================================', 0, idx_end)

print(f"Slice from {idx_receipt_div} to {idx_money_div}")
