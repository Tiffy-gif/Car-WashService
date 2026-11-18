with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('id="tab_upsell"')
idx2 = text.find('id="tab_performance"')
print("Length of tab_upsell:", idx2 - idx)
print(text[idx:idx+1200])
