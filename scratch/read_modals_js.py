with open('public/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

lines = text.splitlines()
for l in lines[25:65]:
    print(l)
