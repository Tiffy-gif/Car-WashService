with open('views/owner/services.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find('require_once dirname(__DIR__, 2) . \'/views/layouts/header.php\';')
if pos != -1:
    print(text[pos:pos+3000])
else:
    pos2 = text.find('<div class="')
    print(text[pos2:pos2+3000])
