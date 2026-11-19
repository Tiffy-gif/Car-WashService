with open('pos/js/i18n.js', 'r', encoding='utf-8') as f:
    text = f.read()

km_target = "settings: 'ការកំណត់',"
en_target = "settings: 'Settings',"

print("km_target in text:", km_target in text)
print("en_target in text:", en_target in text)
