with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos_req = text.find("require_once dirname(__DIR__) . '/layouts/header.php';")
pos_tab_shop = text.find('id="tab_shop"')
print(text[pos_req:pos_tab_shop])
