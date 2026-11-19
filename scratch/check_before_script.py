with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

idx = content.find("<script>\n// Tab Switching")
print("Before script anchor:")
print(repr(content[idx-100:idx]))
