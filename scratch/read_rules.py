with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

import re
targets = ['stat-card', 'table-clean', 'modal', 'card-header']
for t in targets:
    pos = text.find(f'.{t}')
    if pos != -1:
        print(f"\n=== .{t} ===")
        print(text[pos:pos+600])
