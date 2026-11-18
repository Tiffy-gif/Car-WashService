with open('views/owner/services.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('closeModal(\'serviceModal\')')
print(text[pos-200:pos+500])
