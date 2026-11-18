with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    text = f.read()

text = text.replace('<table class="table table-clean">', '<table class="table table-clean" style="table-layout: fixed; width: 100%;">')

with open('views/owner/settings.php', 'w', encoding='utf-8') as f:
    f.write(text)

print("Updated table to table-layout: fixed!")
