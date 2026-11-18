with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find('table table-clean')
idx_end = text.find('</table>', idx)
print(text[idx:idx_end+8])
