with open('pos/js/thermal-printer.js', 'r', encoding='utf-8') as f:
    text = f.read()

print("File length:", len(text))
print("Last 1000 chars:")
print(text[-1000:])
