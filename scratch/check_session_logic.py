with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('checkSession()')
print(text[pos:pos+700])
