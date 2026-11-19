import os, re

for root, dirs, files in os.walk('views'):
    for f in files:
        if f.endswith('.php'):
            p = os.path.join(root, f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as file:
                lines = file.readlines()
                for num, line in enumerate(lines):
                    if 'window.print' in line or 'printReceipt' in line or 'printTicket' in line or 'btn-print' in line or 'fa-print' in line:
                        print(f"{p}:{num+1} | {line.strip()[:100]}")
