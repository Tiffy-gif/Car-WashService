with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos = text.find("'record_cash_movement' => ['pos.access']")
if pos != -1:
    print(text[pos-100:pos+300])
