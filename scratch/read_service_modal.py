with open('views/owner/services.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('class="modal')
print(text[pos-100:pos+1500])
