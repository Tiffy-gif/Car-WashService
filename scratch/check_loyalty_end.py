with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

idx1 = text.find("elseif ($action === 'save_loyalty')")
idx2 = text.find("elseif ($action === 'save_telegram')")
print(text[idx1:idx2])
