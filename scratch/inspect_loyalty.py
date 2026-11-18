with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

# Let's see how tab_loyalty has tables or cards
pos = text.find('id="tab_loyalty"')
print(text[pos:pos+3500])
