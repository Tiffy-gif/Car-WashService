with open('pos/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('static async checkSession()')
print(text[pos+900:pos+1800])
