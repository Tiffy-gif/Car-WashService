with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

print("File length:", len(text))

# Find the header, where tab buttons start, and where the first tab starts
pos_topbar = text.find('System Settings')
pos_tabs_nav = text.find('<div class="tabs"')
pos_first_tab = text.find('id="tab_shop"')

print("pos_topbar:", pos_topbar)
print("pos_tabs_nav:", pos_tabs_nav)
print("pos_first_tab:", pos_first_tab)

if pos_tabs_nav != -1:
    print("\n--- Current Top Navigation Block ---")
    print(text[pos_tabs_nav:pos_first_tab])
