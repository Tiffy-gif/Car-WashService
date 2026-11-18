with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("staffToken === ''")
print(text[idx-300:idx+300])
