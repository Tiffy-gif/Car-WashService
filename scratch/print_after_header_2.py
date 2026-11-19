with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("require_once dirname(__DIR__) . '/layouts/header.php';")
print(text[pos+2500:pos+4500])
