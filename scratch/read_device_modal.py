with open('views/owner/pos_devices.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('class="modal')
print(text[pos-100:pos+2500])
