with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('id="tab_system"')
pos_end = text.find('id="tab_history"')
print(text[pos:pos_end])
