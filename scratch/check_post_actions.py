with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx = text.find("$action = $_POST['action'] ?? '';")
print(text[idx:idx+1200])
