with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('bindEvents() {')
print(text[pos:pos+500])
