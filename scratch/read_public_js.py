with open('public/js/app.js', 'r', encoding='utf-8') as f:
    text = f.read()

print("Length:", len(text))
print(text[:1200])
