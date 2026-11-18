with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('.form-input')
print(text[pos:pos+600])
