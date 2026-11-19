import os, re

for root, dirs, files in os.walk('pos'):
    for f in files:
        if f.endswith(('.php', '.js', '.html')):
            p = os.path.join(root, f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as file:
                lines = file.readlines()
                for num, line in enumerate(lines):
                    if any(k in line for k in ['print', 'Print', 'printer', 'Receipt', 'ticket', 'receipt']):
                        print(f"{p}:{num+1} | {line.strip()[:100]}")
