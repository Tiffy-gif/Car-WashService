with open('pos/js/storage.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('class PosStorage')
print(text[pos:pos+700])
