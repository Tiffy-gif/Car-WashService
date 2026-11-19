import os, re

files_to_inspect = [
    'views/orders/receipt.php',
    'views/orders/manual_receipt.php',
    'views/owner/reports.php',
    'views/recovery/paper_forms.php',
    'views/owner/settings.php',
    'pos/js/thermal-printer.js',
    'pos/js/app.js',
    'pos/js/config.js',
    'api/printer.php',
    'scripts/counter-printer-service.js'
]

results = {}

for f in files_to_inspect:
    if os.path.exists(f):
        with open(f, 'r', encoding='utf-8', errors='ignore') as file:
            content = file.read()
            results[f] = {
                'len': len(content),
                'window_print': len(re.findall(r'window\.print', content)),
                'media_print': len(re.findall(r'@media\s+print', content)),
                'escpos': len(re.findall(r'escpos|ESC/POS', content, re.I)),
                'size_80mm': '80mm' in content,
                'size_58mm': '58mm' in content,
                'size_a4': 'a4' in content.lower(),
            }

for k, v in results.items():
    print(k, v)
