with open('pos/css/app.css', 'r', encoding='utf-8') as f:
    text = f.read()

print("Length of app.css:", len(text))
print("Last 300 chars of app.css:")
print(repr(text[-300:]))
