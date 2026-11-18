with open('views/owner/pos_devices.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('<div class="card">')
if pos != -1:
    print(text[pos:pos+3500])
else:
    pos2 = text.find('table')
    print(text[pos2:pos2+3500])
