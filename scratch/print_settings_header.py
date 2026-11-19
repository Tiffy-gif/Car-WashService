with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos_topbar = text.find('System Settings')
pos_first_tab = text.find('id="tab_shop"')

print(text[pos_topbar:pos_topbar+1500])
print("\n" + "="*50 + "\n")
print(text[pos_first_tab-1500:pos_first_tab])
