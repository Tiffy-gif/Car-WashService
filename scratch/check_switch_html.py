with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('name="enable_upsell_offers"')
print(text[idx-200:idx+400])
