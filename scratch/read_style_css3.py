with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

lines = text.splitlines()
for l in lines[180:350]:
    print(l)
