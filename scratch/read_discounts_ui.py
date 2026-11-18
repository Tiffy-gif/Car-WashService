with open('views/owner/discounts.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('<div class="card">')
if pos != -1:
    print(text[pos:pos+3000])
else:
    pos2 = text.find('table')
    print(text[pos2:pos2+3000])
