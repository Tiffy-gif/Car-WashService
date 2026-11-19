with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('require_once dirname(__DIR__, 2) . \'/views/layouts/header.php\';')
print(text[pos:pos+2500])
