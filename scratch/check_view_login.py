with open('pos/index.html', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('id="viewLogin"')
print(text[pos:pos+800])
