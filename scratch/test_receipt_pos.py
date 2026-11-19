# Let's inspect tab_receipt lines in views/owner/settings.php
with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

start_marker = '<!-- GROUP 2: RECEIPT SETTINGS -->'
end_marker = '<!-- GROUP 3: MONEY & EXCHANGE RATE -->'

idx_start = content.find(start_marker)
idx_end = content.find(end_marker)

print(f"Found markers: {idx_start} to {idx_end}")
