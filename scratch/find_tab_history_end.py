with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('id="tab_history"')
pos_script = text.find('<script>', pos)
print(text[pos_script-500:pos_script+300])
