import os, re

matches = []

for root, dirs, files in os.walk('.'):
    # skip git, node_modules, vendor, scratch
    if any(p in root for p in ['.git', 'node_modules', 'vendor', '.gemini']):
        continue
    for f in files:
        if f.endswith(('.php', '.js', '.html')):
            path = os.path.join(root, f)
            try:
                with open(path, 'r', encoding='utf-8', errors='ignore') as file:
                    content = file.read()
                    has_win_print = 'window.print' in content
                    has_media_print = '@media print' in content
                    has_receipt = 'receipt' in f.lower() or 'print' in f.lower()
                    has_escpos = 'escpos' in content.lower() or 'esc/pos' in content.lower()
                    if has_win_print or has_media_print or has_receipt or has_escpos:
                        matches.append({
                            'path': path,
                            'win_print': has_win_print,
                            'media_print': has_media_print,
                            'escpos': has_escpos,
                            'f_match': has_receipt
                        })
            except Exception as e:
                pass

print(f"Total matching files: {len(matches)}")
for m in matches:
    print(f"{m['path']} | win_print={m['win_print']} | media_print={m['media_print']} | escpos={m['escpos']}")
