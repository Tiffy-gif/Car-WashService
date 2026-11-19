with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos1 = text.find('id="tab_shop"')
pos2 = text.find('id="tab_receipt"')
print(text[pos1:pos2])
