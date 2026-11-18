with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('id="tab_loyalty"')
idx2 = text.find('id="tab_performance"', idx)
if idx2 == -1:
    idx2 = text.find('id="tab_', idx + 50)
print(text[idx2-200:idx2+400])
