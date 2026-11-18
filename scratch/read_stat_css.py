with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('.stat-card')
print(text[pos:pos+1000])
