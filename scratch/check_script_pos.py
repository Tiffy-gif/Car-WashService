with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos_script = text.rfind('<script>')
print(text[pos_script-400:pos_script+200])
